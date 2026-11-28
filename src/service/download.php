<?php
session_start();

$sessao = $_SESSION['user'] ?? null;
$usuarioId = filter_var($sessao['user_id'] ?? null, FILTER_VALIDATE_INT);
$historicoId = filter_var($_GET['historico_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || !$historicoId) {
    http_response_code(401);
    exit('Faça login para baixar este arquivo.');
}

require_once '../../conection.php';

try {
    $pdo = conect();
    if (!$pdo instanceof PDO)
        throw new RuntimeException('Conexão inválida.');
    $stmt = $pdo->prepare(
        'SELECT stored_file_name, stored_file_path
         FROM tb_historico
         WHERE historico_id = :historico_id AND user_id = :user_id'
    );
    $stmt->execute([':historico_id' => $historicoId, ':user_id' => $usuarioId]);
    $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$arquivo) {
        http_response_code(404);
        exit('Arquivo não encontrado.');
    }

    $raizProjeto = realpath(dirname(__DIR__, 2));
    $pastaPermitida = realpath($raizProjeto . '/resumos/usuario_' . $usuarioId);
    $caminho = realpath($raizProjeto . '/' . ltrim(str_replace('\\', '/', $arquivo['stored_file_path']), '/'));
    if (
        !$raizProjeto || !$pastaPermitida || !$caminho || !is_file($caminho)
        || strpos($caminho, $pastaPermitida . DIRECTORY_SEPARATOR) !== 0
    ) {
        http_response_code(404);
        exit('Arquivo não encontrado.');
    }

    $nomeDownload = basename($arquivo['stored_file_name']);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . addcslashes($nomeDownload, '"\\') . '"');
    header('Content-Length: ' . filesize($caminho));
    header('Cache-Control: private, no-store');
    readfile($caminho);
} catch (Throwable $erro) {
    http_response_code(500);
    exit('Não foi possível baixar o arquivo.');
}
