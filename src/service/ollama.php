<?php
set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');

function erroIa(int $status, string $mensagem): void
{
    http_response_code($status);
    echo "[Erro IA]:  $mensagem";
    exit;
}

// O .env e o vendor ficam na raiz do projeto, não dentro de src/service.
$raizProjeto = dirname(__DIR__, 2);
$autoload = "$raizProjeto/vendor/autoload.php";

if (!is_file($autoload)) {
    erroIa(500, 'Dependências não instaladas. Execute "composer install" na raiz do projeto.');
}

require_once $autoload;

try {
    Dotenv\Dotenv::createImmutable($raizProjeto)->safeLoad();
} catch (Throwable $erro) {
    erroIa(500, 'Não foi possível carregar a configuração da IA: ' . $erro->getMessage());
}

$textoPagina = isset($_POST['texto_pagina']) ? trim((string) $_POST['texto_pagina']) : '';
$numeroPagina = isset($_POST['numero_pagina']) ? (int) $_POST['numero_pagina'] : 0;
$modoTeste = isset($_POST['modo_teste']) && $_POST['modo_teste'] === '1';

if ($textoPagina === '') {
    erroIa(400, 'Nenhum texto recebido.');
}

if ($modoTeste) {
    $instrucaoResumo = 'Faça um resumo curto, com no máximo 3 tópicos e cerca de 60 palavras.';
    $limiteTokens = 330;
} else {
    $instrucaoResumo = 'Faça um resumo claro e objetivo, preservando nomes, lugares, regras, valores numéricos e informações importantes.';
    $limiteTokens = 600;
}

$prompt = <<<PROMPT
Você é um extrator de informações de livros de RPG.
$instrucaoResumo
Não invente informações nem misture fatos que não estejam no trecho.
Retorne somente o resumo em Markdown simples, sem introdução, conclusão ou comentários sobre o processo. Use títulos curtos em negrito e listas quando ajudarem na leitura. Destaque personagens, locais, enredo, regras, criaturas, itens e outros tópicos identificados no trecho.
A página de origem é $numeroPagina.

TEXTO:
$textoPagina
PROMPT;

$url = trim($_ENV['URL_IA'] ?? getenv('URL_IA') ?? '');
$modelo = trim($_ENV['MODELO_IA'] ?? getenv('MODELO_IA') ?? 'gemma3');
$chave = trim($_ENV['KEY_IA'] ?? getenv('KEY_IA') ?? '');

if ($url === '') {
    erroIa(500, 'URL_IA não foi definida no arquivo .env.');
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    erroIa(500, 'URL_IA não contém uma URL válida.');
}
if ($modelo === '') {
    erroIa(500, 'MODELO_IA não foi definido.');
}

$data = [
    'model' => $modelo,
    'prompt' => $prompt,
    'stream' => true,
    'options' => [
        'temperature' => 0.3,
        'top_p' => 0.9,
        'repeat_penalty' => 1.1,
        'num_predict' => $limiteTokens,
    ],
];

$payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    if ($linha === '') {
        return;
    }

    $json = json_decode($linha, true);
    if (!is_array($json)) {
        return;
    }

    if (isset($json['response']) && is_string($json['response'])) {
        $respostaIa .= $json['response'];
    }
    if (isset($json['error']) && (string) $json['error'] !== '') {
        $erroIaRemoto = (string) $json['error'];
    }
};

$cabecalhos = [
    'Content-Type: application/json',
    'Accept: application/x-ndjson, application/json',
];
if ($chave !== '') {
    $cabecalhos[] = 'Authorization: Bearer ' . $chave;
}

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
if ($buffer !== '') {
    $emitirLinha($buffer);
}

if ($erroIaRemoto !== null) {
    erroIa(502, $erroIaRemoto);
}
if ($resultado === false || $erroCurl !== '') {
    erroIa(502, $erroCurl !== '' ? $erroCurl : 'tempo limite excedido');
}
if ($codigoHttp >= 400) {
    erroIa(502, 'A IA respondeu HTTP ' . $codigoHttp . '. Verifique a URL e o modelo ' . $modelo . '.');
}
if (trim($respostaIa) === '') {
    erroIa(502, 'A IA não retornou conteúdo. Verifique se o serviço está ativo e se o modelo ' . $modelo . ' está instalado.');
}

echo trim($respostaIa);
