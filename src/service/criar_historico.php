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
    responder(401, ['erro' => 'Faça login antes de enviar um arquivo.']);
}

$nomeArquivo = basename(str_replace('\\', '/', trim((string) ($_POST['name_file'] ?? ''))));
$nomeArquivo = preg_replace('/[\x00-\x1F\x7F]/', '', $nomeArquivo);
$nomeArquivo = mb_substr($nomeArquivo ?: 'documento.pdf', 0, 300, 'UTF-8');
$numeroPaginas = max(0, (int) ($_POST['numbers_page'] ?? 0));
$nomeSalvo = basename(str_replace('\\', '/', trim((string) ($_POST['stored_file_name'] ?? ''))));
$caminhoSalvo = trim(str_replace('\\', '/', (string) ($_POST['stored_file_path'] ?? '')));
$prefixoPermitido = 'resumos/usuario_' . $usuarioId . '/';
if (
    $nomeSalvo === '' || !preg_match('/^usuario_' . preg_quote((string) $usuarioId, '/') . '_[0-9_-]+\.pdf$/i', $nomeSalvo)
    || strpos($caminhoSalvo, $prefixoPermitido) !== 0
    || basename($caminhoSalvo) !== $nomeSalvo
) {
    responder(400, ['erro' => 'Os dados do arquivo salvo são inválidos.']);
}

require_once dirname(__DIR__, 2) . '/conection.php';
try {
    $pdo = conect();
    if (!$pdo instanceof PDO)
        throw new RuntimeException('Conexão inválida.');
    $stmt = $pdo->prepare(
        'INSERT INTO tb_historico (name_file, user_id, numbers_page, stored_file_name, stored_file_path)
         VALUES (:name_file, :user_id, :numbers_page, :stored_file_name, :stored_file_path)'
    );
    $stmt->execute([
        ':name_file' => $nomeArquivo,
        ':user_id' => $usuarioId,
        ':numbers_page' => $numeroPaginas,
        ':stored_file_name' => $nomeSalvo,
        ':stored_file_path' => $caminhoSalvo,
    ]);
    responder(201, ['sucesso' => true, 'historico_id' => (int) $pdo->lastInsertId()]);
} catch (Throwable $erro) {
    responder(500, ['erro' => 'Não foi possível criar o histórico deste arquivo.']);
}
