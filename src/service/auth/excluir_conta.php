<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/conection.php';

function responderExclusaoConta(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderExclusaoConta(405, ['erro' => 'Método não permitido.']);
}

$sessao = $_SESSION['user'] ?? null;
$usuarioId = filter_var($sessao['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || $usuarioId < 1) {
    responderExclusaoConta(401, ['erro' => 'Faça login antes de excluir sua conta.']);
}

try {
    $pdo = conect();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Conexão inválida.');
    }

    $pdo->beginTransaction();
    $historicos = $pdo->prepare(
        'SELECT stored_file_path FROM tb_historico WHERE user_id = :user_id'
    );
    $historicos->execute([':user_id' => $usuarioId]);
    $caminhos = $historicos->fetchAll(PDO::FETCH_COLUMN);

    $apagarMensagens = $pdo->prepare(
        'DELETE m FROM tb_chat_mensagem m
         INNER JOIN tb_historico h ON h.historico_id = m.historico_id
         WHERE h.user_id = :user_id'
    );
    $apagarMensagens->execute([':user_id' => $usuarioId]);

    $apagarHistoricos = $pdo->prepare('DELETE FROM tb_historico WHERE user_id = :user_id');
    $apagarHistoricos->execute([':user_id' => $usuarioId]);

    $apagarUsuario = $pdo->prepare('DELETE FROM tb_usuario WHERE user_id = :user_id');
    $apagarUsuario->execute([':user_id' => $usuarioId]);
    if ($apagarUsuario->rowCount() !== 1) {
        throw new RuntimeException('A conta não foi encontrada.');
    }
    $pdo->commit();

    $raizProjeto = dirname(__DIR__, 3);
    $pastaUsuario = $raizProjeto . '/resumos/usuario_' . $usuarioId;
    $pastaPermitida = realpath($pastaUsuario);
    if ($pastaPermitida && is_dir($pastaPermitida)) {
        foreach (new FilesystemIterator($pastaPermitida, FilesystemIterator::SKIP_DOTS) as $arquivo) {
            if ($arquivo->isFile() || $arquivo->isLink()) {
                @unlink($arquivo->getPathname());
            }
        }
        @rmdir($pastaPermitida);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parametros = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $parametros['path'], $parametros['domain'],
            (bool) $parametros['secure'], (bool) $parametros['httponly']
        );
    }
    session_destroy();

    responderExclusaoConta(200, [
        'sucesso' => true,
        'mensagem' => 'Conta excluída com sucesso.'
    ]);
} catch (Throwable $erro) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderExclusaoConta(500, ['erro' => 'Não foi possível excluir a conta. Nenhum dado foi removido.']);
}
