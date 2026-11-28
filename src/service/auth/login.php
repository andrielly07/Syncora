<?php
header('Content-Type: application/json; charset=utf-8');
require '../../config/conection.php';
session_start();

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

try {
    $sql = "SELECT user_id, username, email, user_password FROM tb_usuario WHERE email = :email";
    $stm = conect()->prepare($sql);
    $stm->bindValue(":email", $email);
    $stm->execute();

    $usuario = $stm->fetch(PDO::FETCH_OBJ);

    if (!$usuario || $password != $usuario->user_password) {
        echo responseMessage(401, "usuário ou senha incorretos");
        exit;
    }

    unset($usuario->user_password);

    $_SESSION['user'] = [
        "user_id" => $usuario->user_id,
        "login" => true
    ];

    echo responseMessage(200, "login feito com sucesso");
} catch (\PDOException $th) {
    echo responseMessage($th->getCode(), $th->getMessage());
}