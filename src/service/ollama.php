<?php
set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');

session_start();

function erroIa(int $status, string $mensagem): void
{
    http_response_code($status);
    echo '[Erro IA]: ' . $mensagem;
    exit;
}

function usuarioLogado(): int
{
    $sessao = $_SESSION['user'] ?? null;
    if (!is_string($sessao) || $sessao === '') {
        erroIa(401, 'Faça login para salvar e consultar suas conversas.');
    }

    $usuario = json_decode($sessao, true);
    $id = filter_var($usuario['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        erroIa(401, 'Sessão de usuário inválida. Faça login novamente.');
    }

    return $id;
}

function validarConversa(string $valor): string
{
    $valor = trim($valor);
    if (!preg_match('/^[a-f0-9-]{16,64}$/i', $valor)) {
        erroIa(400, 'Identificador de conversa inválido.');
    }
    return $valor;
}

function salvarResposta(int $usuarioId, int $historicoId, string $conversaId, string $documento, int $pagina, string $resposta): void
{
    require_once dirname(__DIR__, 2) . '/conection.php';

    try {
        $pdo = conect();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('A conexão com o banco não foi criada.');
        }

        $verificar = $pdo->prepare('SELECT historico_id FROM tb_historico WHERE historico_id = :historico_id AND user_id = :user_id');
        $verificar->execute([':historico_id' => $historicoId, ':user_id' => $usuarioId]);
        if (!$verificar->fetchColumn()) {
            throw new RuntimeException('Histórico não pertence ao usuário autenticado.');
        }

        $sql = 'INSERT INTO tb_chat_mensagem
                    (historico_id, conversation_id, document_name, page_number, role_dif, content)
                VALUES
                    (:historico_id, :conversation_id, :document_name, :page_number, :role_dif, :content)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':historico_id' => $historicoId,
            ':conversation_id' => $conversaId,
            ':document_name' => $documento,
            ':page_number' => max(0, $pagina),
            ':role_dif' => 'assistant',
            ':content' => $resposta,
        ]);
    } catch (Throwable $erro) {
        erroIa(500, 'A resposta foi gerada, mas não pôde ser salva no banco. Verifique se executou database.sql.');
    }
}

$usuarioId = usuarioLogado();

$raizProjeto = dirname(__DIR__, 2);
$autoload = $raizProjeto . '/vendor/autoload.php';
if (!is_file($autoload)) {
    erroIa(500, 'Dependências não instaladas. Execute "composer install" na raiz do projeto.');
}
require_once $autoload;

try {
    Dotenv\Dotenv::createImmutable($raizProjeto)->safeLoad();
} catch (Throwable $erro) {
    erroIa(500, 'Não foi possível carregar a configuração da IA.');
}

$textoPagina = isset($_POST['texto_pagina']) ? trim((string) $_POST['texto_pagina']) : '';
$numeroPagina = isset($_POST['numero_pagina']) ? (int) $_POST['numero_pagina'] : 0;
$modoTeste = isset($_POST['modo_teste']) && $_POST['modo_teste'] === '1';
$historicoId = filter_var($_POST['historico_id'] ?? null, FILTER_VALIDATE_INT);
if (!$historicoId || $historicoId < 1) {
    erroIa(400, 'Histórico inválido. Inicie o envio do arquivo novamente.');
}
$conversaId = validarConversa((string) ($_POST['conversa_id'] ?? ''));
$documento = trim((string) ($_POST['documento'] ?? 'documento.pdf'));
$documento = basename(str_replace('\\', '/', $documento));
$documento = preg_replace('/[\x00-\x1F\x7F]/', '', $documento);
$documento = mb_substr($documento ?: 'documento.pdf', 0, 255, 'UTF-8');

if ($textoPagina === '') {
    erroIa(400, 'Nenhum texto recebido.');
}

$instrucaoResumo = $modoTeste
    ? 'Faça um resumo curto, com no máximo 3 tópicos e cerca de 60 palavras.'
    : 'Faça um resumo claro e objetivo, preservando nomes, lugares, regras, valores numéricos e informações importantes.';
$limiteTokens = $modoTeste ? 330 : 600;

$prompt = <<<PROMPT
Você é um extrator de informações de livros de RPG.
$instrucaoResumo
Não invente informações nem misture fatos que não estejam no trecho.
Retorne somente o resumo em Markdown simples, sem introdução, conclusão ou comentários sobre o processo. Use títulos curtos em negrito e listas quando ajudarem na leitura. Destaque personagens, locais, enredo, regras, criaturas, itens e outros tópicos identificados no trecho.
A página de origem é $numeroPagina.

TEXTO:
$textoPagina
PROMPT;

$url = trim((string) ($_ENV['URL_IA'] ?? getenv('URL_IA') ?? ''));
$modelo = trim((string) ($_ENV['MODELO_IA'] ?? getenv('MODELO_IA') ?? 'gemma3'));
$chave = trim((string) ($_ENV['KEY_IA'] ?? getenv('KEY_IA') ?? ''));
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    erroIa(500, 'URL_IA não contém uma URL válida.');
}
if ($modelo === '') {
    erroIa(500, 'MODELO_IA não foi definido.');
}

$payload = json_encode([
    'model' => $modelo,
    'prompt' => $prompt,
    'stream' => true,
    'options' => [
        'temperature' => 0.3,
        'top_p' => 0.9,
        'repeat_penalty' => 1.1,
        'num_predict' => $limiteTokens,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payload === false) {
    erroIa(500, 'Não foi possível preparar a requisição para a IA.');
}

$ch = curl_init($url);
if (!$ch) {
    erroIa(502, 'Não foi possível inicializar a conexão com a IA.');
}

$buffer = '';
$respostaIa = '';
$erroIaRemoto = null;
$emitirLinha = function (string $linha) use (&$respostaIa, &$erroIaRemoto): void {
    $linha = trim($linha);
    if ($linha === '')
        return;
    $json = json_decode($linha, true);
    if (!is_array($json))
        return;
    if (isset($json['response']) && is_string($json['response']))
        $respostaIa .= $json['response'];
    if (isset($json['error']) && (string) $json['error'] !== '')
        $erroIaRemoto = (string) $json['error'];
};

$cabecalhos = ['Content-Type: application/json', 'Accept: application/x-ndjson, application/json'];
if ($chave !== '')
    $cabecalhos[] = 'Authorization: Bearer ' . $chave;

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => $cabecalhos,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, $emitirLinha): int {
        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $emitirLinha(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
        }
        return strlen($chunk);
    },
]);

$resultado = curl_exec($ch);
$erroCurl = curl_error($ch);
$codigoHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($buffer !== '')
    $emitirLinha($buffer);
curl_close($ch);

if ($erroIaRemoto !== null)
    erroIa(502, $erroIaRemoto);
if ($resultado === false || $erroCurl !== '')
    erroIa(502, $erroCurl !== '' ? $erroCurl : 'tempo limite excedido');
if ($codigoHttp >= 400)
    erroIa(502, 'A IA respondeu HTTP ' . $codigoHttp . '. Verifique a URL e o modelo ' . $modelo . '.');
if (trim($respostaIa) === '')
    erroIa(502, 'A IA não retornou conteúdo.');

$respostaIa = trim($respostaIa);
salvarResposta($usuarioId, $historicoId, $conversaId, $documento, $numeroPagina, $respostaIa);
echo $respostaIa;
