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
if (!$usuarioId || $usuarioId < 1) responder(401, ['erro' => 'Faça login antes de enviar um arquivo.']);

$nomeArquivo = basename(str_replace('\\', '/', trim((string) ($_POST['name_file'] ?? ''))));
$nomeArquivo = preg_replace('/[\x00-\x1F\x7F]/', '', $nomeArquivo);
$nomeArquivo = mb_substr($nomeArquivo ?: 'documento.pdf', 0, 300, 'UTF-8');
$numeroPaginas = max(0, (int) ($_POST['numbers_page'] ?? 0));

require_once dirname(__DIR__, 2) . '/conection.php';
try {
    $pdo = conect();
    if (!$pdo instanceof PDO) throw new RuntimeException('Conexão inválida.');
    $stmt = $pdo->prepare(
        'INSERT INTO tb_historico (name_file, user_id, numbers_page)
         VALUES (:name_file, :user_id, :numbers_page)'
    );
    $stmt->execute([
        ':name_file' => $nomeArquivo,
        ':user_id' => $usuarioId,
        ':numbers_page' => $numeroPaginas,
    ]);
    responder(201, ['sucesso' => true, 'historico_id' => (int) $pdo->lastInsertId()]);
} catch (Throwable $erro) {
    responder(500, ['erro' => 'Não foi possível criar o histórico deste arquivo.']);
}
