<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$apiKey = $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '';

if ($apiKey === '') {
    die("ERRO: GEMINI_API_KEY nao encontrada.\n");
}

// Teste rápido (remova este bloco depois de validar)
$preview = substr($apiKey, 0, 4) . '...' . substr($apiKey, -4);
echo "OK: Chave carregada ({$preview}) | Tamanho: " . strlen($apiKey) . "\n";

// ... seu código que carrega o .env, pega $apiKey e define chamarGemini() ...

// Bloco de teste temporário (remova depois de testar):
if (php_sapi_name() === 'cli' || isset($_GET['testar'])) {
    try {
        $resposta = chamarGemini('Responda apenas: OK funcionando', $apiKey);
        echo "Sucesso: " . $resposta . "\n";
    } catch (Throwable $e) {
        echo "Erro: " . $e->getMessage() . "\n";
    }
}

function chamarGemini(string $prompt, string $apiKey, string $model = 'gemini-3.8-flash'): string
{
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Erro de cURL ao chamar o Gemini: {$curlError}");
    }

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        $message = $data['error']['message'] ?? 'Erro desconhecido na API do Gemini';
        throw new RuntimeException("Erro HTTP {$httpCode} do Gemini: {$message}");
    }

    return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    $resposta = chamarGemini('Responda apenas: OK funcionando', $apiKey);
    echo "Resposta do Gemini: {$resposta}\n";

    function chamarGemini(string $prompt, string $apiKey, string $model = 'gemini-3.8-flash', int $maxTentativas = 3): string
{
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];

    for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Erro de cURL ao chamar o Gemini: {$curlError}");
        }

        $data = json_decode($response, true);

        if ($httpCode === 503 && $tentativa < $maxTentativas) {
            sleep(2 * $tentativa);
            continue;
        }

        if ($httpCode >= 400) {
            $message = $data['error']['message'] ?? 'Erro desconhecido na API do Gemini';
            throw new RuntimeException("Erro HTTP {$httpCode} do Gemini: {$message}");
        }

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    return '';
}
}

?>
