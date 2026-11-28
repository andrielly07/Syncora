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
    if (!isset($sessao) || $sessao === '') {
        erroIa(401, 'Faça login para salvar e consultar suas conversas.');
    }

    $id = filter_var($sessao['user_id'] ?? null, FILTER_VALIDATE_INT);
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
    require_once dirname(__DIR__) . '/config/conection.php';

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

function configuracaoInteira(string $nome, int $padrao, int $minimo, int $maximo): int
{
    $valor = $_ENV[$nome] ?? $_SERVER[$nome] ?? getenv($nome);
    if ($valor === false || $valor === null || $valor === '' || !is_numeric($valor)) {
        return $padrao;
    }
    return max($minimo, min($maximo, (int) $valor));
}

/**
 * Reserva atomicamente o próximo horário permitido para iniciar uma chamada.
 * O lock é compartilhado por requisições concorrentes e por abas diferentes.
 */
function aguardarJanelaGemini(string $raizProjeto, string $apiKey, int $intervaloMs): void
{
    $identificador = hash('sha256', $raizProjeto . '|' . $apiKey);
    $arquivoLock = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'syncora-gemini-rate-limit-' . $identificador . '.lock';
    $handle = @fopen($arquivoLock, 'c+');
    if (!$handle) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        rewind($handle);
        $conteudo = stream_get_contents($handle);
        $proximoInicio = is_string($conteudo) ? (float) trim($conteudo) : 0.0;
        $espera = max(0.0, $proximoInicio - microtime(true));
        if ($espera > 0) {
            usleep((int) ceil($espera * 1000000));
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, sprintf('%.6f', microtime(true) + ($intervaloMs / 1000)));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function respostaPodeSerTentadaNovamente(int $codigoHttp, bool $falhaCurl): bool
{
    return $falhaCurl || in_array($codigoHttp, [408, 425, 429, 500, 502, 503, 504], true);
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

$apiKey = trim((string) ($_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? ''));
$modelo = trim((string) ($_ENV['MODELO_IA'] ?? getenv('MODELO_IA') ?? 'gemini-3.1-flash-lite'));
if ($modelo === '') {
    $modelo = 'gemini-3.1-flash-lite';
}

if ($apiKey === '') {
    erroIa(500, 'GEMINI_API_KEY não configurada no arquivo .env.');
}

$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelo) . ':generateContent';
$payload = json_encode([
    'contents' => [
        [
            'parts' => [['text' => $prompt]],
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.3,
        'topP' => 0.9,
        'maxOutputTokens' => $limiteTokens,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($payload === false) {
    erroIa(500, 'Não foi possível preparar a requisição para o Gemini.');
}

// Controle autoritativo no servidor; o frontend continua sequencial, mas não é a única defesa.
$intervaloMs = configuracaoInteira('GEMINI_INTERVALO_MS', 2500, 250, 60000);
$maxTentativas = configuracaoInteira('GEMINI_MAX_TENTATIVAS', 4, 1, 6);
$backoffBaseMs = configuracaoInteira('GEMINI_BACKOFF_BASE_MS', 1500, 250, 30000);
$respostaBruta = false;
$codigoHttp = 0;
$erroCurl = '';
$mensagemErroGemini = '';

for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
    aguardarJanelaGemini($raizProjeto, $apiKey, $intervaloMs);

    $respostaBruta = false;
    $codigoHttp = 0;
    $erroCurl = '';
    $retryAfterSegundos = null;
    $ch = curl_init($endpoint);
    if (!$ch) {
        $erroCurl = 'Não foi possível inicializar a conexão com o Gemini.';
        $codigoHttp = 0;
    } else {
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $cabecalho) use (&$retryAfterSegundos): int {
                if (stripos($cabecalho, 'Retry-After:') === 0) {
                    $valor = trim(substr($cabecalho, strlen('Retry-After:')));
                    if (is_numeric($valor)) {
                        $retryAfterSegundos = max(0, (int) $valor);
                    }
                }
                return strlen($cabecalho);
            },
        ]);

        $respostaBruta = curl_exec($ch);
        $erroCurl = curl_error($ch);
        $codigoHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    $falhaCurl = $respostaBruta === false || $erroCurl !== '';
    $dadosTentativa = is_string($respostaBruta) ? json_decode($respostaBruta, true) : null;
    if (is_array($dadosTentativa)) {
        $mensagemErroGemini = (string) ($dadosTentativa['error']['message'] ?? '');
    }

    if (!$falhaCurl && $codigoHttp < 400 && is_array($dadosTentativa)) {
        $respostaIa = trim((string) ($dadosTentativa['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($respostaIa !== '') {
            salvarResposta($usuarioId, $historicoId, $conversaId, $documento, $numeroPagina, $respostaIa);
            echo $respostaIa;
            exit;
        }
        $mensagemErroGemini = 'O Gemini não retornou conteúdo.';
        $codigoHttp = 502;
    }

    $deveTentar = respostaPodeSerTentadaNovamente($codigoHttp, $falhaCurl)
        || ($codigoHttp < 400 && !is_array($dadosTentativa));
    if (!$deveTentar || $tentativa >= $maxTentativas) {
        break;
    }

    $backoffMs = $backoffBaseMs * (2 ** ($tentativa - 1));
    if ($retryAfterSegundos !== null) {
        $backoffMs = max($backoffMs, $retryAfterSegundos * 1000);
    }
    $jitterMs = random_int(0, max(1, (int) floor($backoffMs * 0.25)));
    usleep(($backoffMs + $jitterMs) * 1000);
}

if ($erroCurl !== '') {
    erroIa(502, $erroCurl);
}
if ($codigoHttp >= 400) {
    $detalhe = $mensagemErroGemini !== '' ? $mensagemErroGemini : ('HTTP ' . $codigoHttp);
    erroIa(502, 'Erro no Gemini (' . $codigoHttp . ') após ' . $maxTentativas . ' tentativa(s): ' . $detalhe);
}
erroIa(502, $mensagemErroGemini !== '' ? $mensagemErroGemini : 'Resposta inválida recebida do Gemini após as tentativas configuradas.');

?>