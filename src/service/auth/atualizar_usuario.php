<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/conection.php';

function responderAtualizacao(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderAtualizacao(405, ['erro' => 'Método não permitido.']);
}

$sessao = $_SESSION['user'] ?? null;
$usuarioId = filter_var($sessao['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || $usuarioId < 1) {
    responderAtualizacao(401, ['erro' => 'Faça login antes de atualizar seus dados.']);
}

$nome = trim((string) ($_POST['nome'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
if ($nome === '' || mb_strlen($nome, 'UTF-8') > 100) {
    responderAtualizacao(400, ['erro' => 'Informe um nome válido com até 100 caracteres.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email, 'UTF-8') > 150) {
    responderAtualizacao(400, ['erro' => 'Informe um e-mail válido com até 150 caracteres.']);
}

try {
    $pdo = conect();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Conexão inválida.');
    }

    $verificar = $pdo->prepare(
        'SELECT user_id FROM tb_usuario WHERE email = :email AND user_id <> :user_id'
    );
    $verificar->execute([':email' => $email, ':user_id' => $usuarioId]);
    if ($verificar->fetchColumn()) {
        responderAtualizacao(409, ['erro' => 'Este e-mail já está sendo usado por outra conta.']);
    }

    $atualizar = $pdo->prepare(
        'UPDATE tb_usuario SET username = :username, email = :email WHERE user_id = :user_id'
    );
    $atualizar->execute([
        ':username' => $nome,
        ':email' => $email,
        ':user_id' => $usuarioId
    ]);

    $_SESSION['user']['username'] = $nome;
    $_SESSION['user']['email'] = $email;
    responderAtualizacao(200, [
        'sucesso' => true,
        'mensagem' => 'Dados atualizados com sucesso.',
        'usuario' => ['username' => $nome, 'email' => $email]
    ]);
} catch (Throwable $erro) {
    responderAtualizacao(500, ['erro' => 'Não foi possível atualizar seus dados.']);
}
