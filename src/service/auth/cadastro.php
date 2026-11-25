<?php
header('Content-Type: application/json; charset=utf-8');
require '../../../conection.php';
session_start();

$name = trim((string) ($_POST['name'] ?? $_POST['usuario'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? $_POST['senha'] ?? '');
$rpt_password = (string) ($_POST['rpt_password'] ?? $_POST['senha'] ?? '');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    echo responseMessage(400, "preencha nome, email e senha corretamente");
    exit;
}
if ($password !== $rpt_password) {
    echo responseMessage(422, "as senhas não conferem");
    exit;
}

$sql = "SELECT email FROM tb_usuario WHERE email = :email";
$pdo = conect();
$stm = $pdo->prepare($sql);
$stm->bindValue(":email", $email);
$stm->execute();
$userExist = $stm->fetch(PDO::FETCH_ASSOC);

if ($userExist) {
    echo responseMessage(409, "usuário já cadastrado");
    exit;
}

try {
    $sql = "INSERT INTO tb_usuario(username,email,user_password) VALUE(:username,:email,:user_password)";

    $stm = $pdo->prepare($sql);
    $stm->bindValue(":username", $name);
    $stm->bindValue(":email", $email);
    $stm->bindValue(":user_password", $password);
    $stm->execute();

    $_SESSION['user'] = json_encode([
        "user_id" => (int) $pdo->lastInsertId(),
        "login" => true,
        "username" => $name,
        "email" => $email
    ], JSON_UNESCAPED_UNICODE);
    echo responseMessage(201, "usuário cadastrado com sucesso");
} catch (PDOException $th) {
    echo responseMessage(500, "erro ao cadastrar usuário");
}
