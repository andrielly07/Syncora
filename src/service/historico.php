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
$usuarioId = filter_var($sessao['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || $usuarioId < 1) {
    responder(401, ['erro' => 'Faça login para consultar seu histórico.']);
}

require_once dirname(__DIR__, 2) . '/conection.php';

try {
    $pdo = conect();
    if (!$pdo instanceof PDO)
        throw new RuntimeException('Conexão inválida.');

    $stmt = $pdo->prepare(
        'SELECT h.historico_id, h.name_file, h.numbers_page, h.created_at,
                COUNT(m.message_id) AS total_mensagens
         FROM tb_historico h
         LEFT JOIN tb_chat_mensagem m ON m.historico_id = h.historico_id
         WHERE h.user_id = :user_id
         GROUP BY h.historico_id, h.name_file, h.numbers_page, h.created_at
         ORDER BY h.created_at DESC, h.historico_id DESC'
    );
    $stmt->execute([':user_id' => $usuarioId]);
    responder(200, ['sucesso' => true, 'historicos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $erro) {
    responder(500, ['erro' => 'Não foi possível carregar os históricos. Execute database.sql e tente novamente.']);
}
