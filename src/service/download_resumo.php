<?php
session_start();

function erroDownloadResumo(int $status, string $mensagem): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => $mensagem], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$sessao = $_SESSION['user'] ?? null;
$sessao = is_string($sessao) ? json_decode($sessao, true) : $sessao;
$usuarioId = filter_var($sessao['user_id'] ?? null, FILTER_VALIDATE_INT);
$historicoId = filter_var($_GET['historico_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || !$historicoId) {
    erroDownloadResumo(401, 'Faça login para baixar este resumo.');
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
        erroDownloadResumo(404, 'Histórico não encontrado.');
    }

    $raizProjeto = realpath(dirname(__DIR__, 2));
    $pastaPermitida = realpath($raizProjeto . '/resumos/usuario_' . $usuarioId);
    $caminho = realpath($raizProjeto . '/resumos/usuario_' . $usuarioId . '/resumo_historico_' . $historicoId . '.pdf');
    if (
        !$raizProjeto || !$pastaPermitida || !$caminho || !is_file($caminho)
        || strpos($caminho, $pastaPermitida . DIRECTORY_SEPARATOR) !== 0
    ) {
        erroDownloadResumo(404, 'Resumo ainda não está disponível para download. Gere o resumo novamente para criar o arquivo.');
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
    erroDownloadResumo(500, 'Não foi possível baixar o resumo. Tente novamente em instantes.');
}
