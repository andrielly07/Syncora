<?php
set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');

$textoPagina = isset($_POST['texto_pagina']) ? trim((string) $_POST['texto_pagina']) : '';
$numeroPagina = isset($_POST['numero_pagina']) ? (int) $_POST['numero_pagina'] : 0;
$modoTeste = isset($_POST['modo_teste']) && $_POST['modo_teste'] === '1';

if ($textoPagina === '') {
    http_response_code(400);
    echo '[Erro]: Nenhum texto recebido.';
    exit;
}

if ($modoTeste) {
    $instrucaoResumo = 'Faça um resumo curto, com no máximo 3 tópicos e cerca de 60 palavras.';
    $limiteTokens = 220;
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

$url = 'http://localhost:11434/api/generate';
$data = [
    'model' => 'gemma3',
    'prompt' => $prompt,
    'stream' => true,
    'options' => [
        'temperature' => 0.3,
        'top_p' => 0.9,
        'repeat_penalty' => 1.1,
        'num_predict' => $limiteTokens,
    ],
];

$ch = curl_init($url);
if (!$ch) {
    http_response_code(502);
    echo '[Erro]: Não foi possível inicializar a conexão com o Ollama.';
    exit;
}

$buffer = '';
$respostaIa = '';
$erroOllama = null;
$emitirLinha = function ($linha) use (&$respostaIa, &$erroOllama) {
    $linha = trim($linha);
    if ($linha === '')
        return;
    $json = json_decode($linha, true);
    if (!is_array($json))
        return;
    if (isset($json['response']) && is_string($json['response'])) {
        $respostaIa .= $json['response'];
    }
    if (isset($json['error'])) {
        $erroOllama = (string) $json['error'];
    }
};

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
// O Ollama pode demorar entre dois fragmentos enquanto ainda está gerando.
// Por isso não usamos LOW_SPEED_TIME: ele encerraria respostas válidas.
curl_setopt($ch, CURLOPT_TIMEOUT, 180);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$buffer, $emitirLinha) {
    $buffer .= $chunk;
    while (($pos = strpos($buffer, "\n")) !== false) {
        $emitirLinha(substr($buffer, 0, $pos));
        $buffer = substr($buffer, $pos + 1);
    }
    return strlen($chunk);
});

$resultado = curl_exec($ch);
$erroCurl = curl_error($ch);
$codigoHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($buffer !== '')
    $emitirLinha($buffer);
curl_close($ch);

if ($erroOllama !== null) {
    http_response_code(502);
    echo '[Erro Ollama]: ' . $erroOllama;
    exit;
}
if ($resultado === false || $erroCurl !== '') {
    http_response_code(502);
    echo '[Erro de conexão com Ollama]: ' . ($erroCurl !== '' ? $erroCurl : 'tempo limite excedido');
    exit;
}
if ($codigoHttp >= 400) {
    http_response_code(502);
    echo '[Erro Ollama HTTP ' . $codigoHttp . ']. Verifique o modelo gemma3.';
    exit;
}
if (trim($respostaIa) === '') {
    http_response_code(502);
    echo '[Sem resposta do modelo. Verifique se o Ollama está em execução.]';
    exit;
}

echo trim($respostaIa);
