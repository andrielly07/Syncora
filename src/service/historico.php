<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

function responder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$sessao = $_SESSION['user'] ?? null;
$usuario = is_string($sessao) ? json_decode($sessao, true) : null;
$usuarioId = filter_var($usuario['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || $usuarioId < 1) {
    responder(401, ['erro' => 'Faça login para consultar seu histórico.']);
}

require_once '../../conection.php';

try {
    $pdo = conect();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Conexão inválida.');
    }

    $limite = min(max((int) ($_GET['limite'] ?? 100), 1), 500);
    $stmt = $pdo->prepare(
        'SELECT message_id, conversation_id, document_name, page_number, role_dif, content, created_at
         FROM tb_chat_mensagem
         WHERE user_id = :user_id
         ORDER BY created_at DESC, message_id DESC
         LIMIT ' . $limite
    );
    $stmt->execute([':user_id' => $usuarioId]);
    $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    responder(200, ['sucesso' => true, 'mensagens' => array_reverse($mensagens)]);
} catch (Throwable $erro) {
    responder(500, ['erro' => 'Não foi possível carregar o histórico. Execute database.sql e tente novamente.']);
}
