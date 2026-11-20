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
$historicoId = filter_var($_GET['historico_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || !$historicoId) {
    responder(401, ['erro' => 'Sessão ou conversa inválida.']);
}

require_once dirname(__DIR__, 2) . '/conection.php';

try {
    $pdo = conect();
    if (!$pdo instanceof PDO) throw new RuntimeException('Conexão inválida.');

    $stmt = $pdo->prepare(
        'SELECT historico_id, name_file, numbers_page, stored_file_name, created_at
         FROM tb_historico
         WHERE historico_id = :historico_id AND user_id = :user_id'
    );
    $stmt->execute([':historico_id' => $historicoId, ':user_id' => $usuarioId]);
    $historico = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$historico) responder(404, ['erro' => 'Conversa não encontrada.']);

    $stmt = $pdo->prepare(
        'SELECT message_id, conversation_id, document_name, page_number, role_dif, content, created_at
         FROM tb_chat_mensagem
         WHERE historico_id = :historico_id
         ORDER BY page_number ASC, message_id ASC'
    );
    $stmt->execute([':historico_id' => $historicoId]);

    responder(200, [
        'sucesso' => true,
        'historico' => $historico,
        'mensagens' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $erro) {
    responder(500, ['erro' => 'Não foi possível carregar esta conversa.']);
}
