<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

function responderLogout(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderLogout(405, ['erro' => 'Método não permitido.']);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $parametros = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $parametros['path'],
        $parametros['domain'],
        (bool) $parametros['secure'],
        (bool) $parametros['httponly']
    );
}

session_destroy();
responderLogout(200, ['sucesso' => true, 'mensagem' => 'Logout realizado com sucesso.']);
