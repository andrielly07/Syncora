<?php
session_start();

$sessao = $_SESSION['user'] ?? null;
$usuarioId = filter_var($sessao['user_id'] ?? null, FILTER_VALIDATE_INT);
$historicoId = filter_var($_GET['historico_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || !$historicoId) {
    http_response_code(401);
    exit('Faça login para baixar este resumo.');
}

require_once '../config/conection.php';
try {
    $pdo = conect();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Conexão inválida.');
    }
    $stmt = $pdo->prepare(
        'SELECT name_file
         FROM tb_historico
         WHERE historico_id = :historico_id AND user_id = :user_id'
    );
    $stmt->execute([':historico_id' => $historicoId, ':user_id' => $usuarioId]);
    $historico = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$historico) {
        http_response_code(404);
        exit('Histórico não encontrado.');
    }

    $raizProjeto = realpath(dirname(__DIR__, 2));
    $pastaPermitida = realpath($raizProjeto . '/resumos/usuario_' . $usuarioId);
    $caminho = realpath($raizProjeto . '/resumos/usuario_' . $usuarioId . '/resumo_historico_' . $historicoId . '.pdf');
    if (
        !$raizProjeto || !$pastaPermitida || !$caminho || !is_file($caminho)
        || strpos($caminho, $pastaPermitida . DIRECTORY_SEPARATOR) !== 0
    ) {
        http_response_code(404);
        exit('Resumo ainda não está disponível para download.');
    }

    $base = pathinfo((string) $historico['name_file'], PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?: 'resumo-rpg';
    $nomeDownload = $base . '-resumo.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . addcslashes($nomeDownload, '"\\') . '"');
    header('Content-Length: ' . filesize($caminho));
    header('Cache-Control: private, no-store');
    readfile($caminho);
} catch (Throwable $erro) {
    http_response_code(500);
    exit('Não foi possível baixar o resumo.');
}
