<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../config/conection.php';

function responderExclusaoHistorico(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderExclusaoHistorico(405, ['erro' => 'Método não permitido.']);
}

$sessao = $_SESSION['user'] ?? null;
$usuarioId = filter_var($sessao['user_id'] ?? null, FILTER_VALIDATE_INT);
$historicoId = filter_var($_POST['historico_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || $usuarioId < 1) {
    responderExclusaoHistorico(401, ['erro' => 'Faça login antes de apagar um histórico.']);
}
if (!$historicoId || $historicoId < 1) {
    responderExclusaoHistorico(400, ['erro' => 'Histórico inválido.']);
}

try {
    $pdo = conect();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Conexão inválida.');
    }

    $pdo->beginTransaction();
    $buscar = $pdo->prepare(
        'SELECT stored_file_path FROM tb_historico
         WHERE historico_id = :historico_id AND user_id = :user_id'
    );
    $buscar->execute([
        ':historico_id' => $historicoId,
        ':user_id' => $usuarioId
    ]);
    $historico = $buscar->fetch(PDO::FETCH_ASSOC);
    if (!$historico) {
        $pdo->rollBack();
        responderExclusaoHistorico(404, ['erro' => 'Histórico não encontrado.']);
    }

    $mensagens = $pdo->prepare('DELETE FROM tb_chat_mensagem WHERE historico_id = :historico_id');
    $mensagens->execute([':historico_id' => $historicoId]);
    $apagar = $pdo->prepare(
        'DELETE FROM tb_historico WHERE historico_id = :historico_id AND user_id = :user_id'
    );
    $apagar->execute([
        ':historico_id' => $historicoId,
        ':user_id' => $usuarioId
    ]);
    if ($apagar->rowCount() !== 1) {
        throw new RuntimeException('Histórico não foi removido.');
    }
    $pdo->commit();

    $raizProjeto = dirname(__DIR__, 2);
    $pastaUsuario = $raizProjeto . '/resumos/usuario_' . $usuarioId;
    $caminhoOriginal = $raizProjeto . '/' . ltrim(str_replace('\\', '/', (string) $historico['stored_file_path']), '/');
    $pastaPermitida = realpath($pastaUsuario);
    if ($pastaPermitida && is_dir($pastaPermitida)) {
        $arquivoOriginal = realpath($caminhoOriginal);
        if ($arquivoOriginal && dirname($arquivoOriginal) === $pastaPermitida) {
            @unlink($arquivoOriginal);
        }
        $arquivoResumo = $pastaPermitida . DIRECTORY_SEPARATOR . 'resumo_historico_' . $historicoId . '.pdf';
        if (is_file($arquivoResumo)) {
            @unlink($arquivoResumo);
        }
        @rmdir($pastaPermitida);
    }

    responderExclusaoHistorico(200, [
        'sucesso' => true,
        'mensagem' => 'Histórico apagado com sucesso.'
    ]);
} catch (Throwable $erro) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderExclusaoHistorico(500, ['erro' => 'Não foi possível apagar este histórico.']);
}
