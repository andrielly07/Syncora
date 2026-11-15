<?php
header('Content-Type: application/json; charset=utf-8');
require '../../../conection.php';

$name = $_POST['name'];
$email = $_POST['email'];
$password = $_POST['password'];
$rpt_password = $_POST['rpt_password'];

$sql = "SELECT email FROM tb_usuario WHERE email = :email";
$stm = conect()->prepare($sql);
$stm->bindValue(":email", $email);
$stm->execute();
$userExist = $stm->fetch(PDO::FETCH_ASSOC);

if ($userExist) {
    echo responseMessage(409, "usuário já cadastrado");
    exit;
}

try {
    $stm = conect()->prepare($sql);

    $sql = "INSERT INTO tb_usuario(username,email,user_password) VALUE(:username,:email,:user_password)";

    $stm = conect()->prepare($sql);
    $stm->bindValue(":username", $name);
    $stm->bindValue(":email", $email);
    $stm->bindValue(":user_password", $password);
    $stm->execute();

    echo responseMessage(201, "usuário cadastrado com sucesso");
} catch (PDOException $th) {
    echo responseMessage(500, "erro ao cadastrar usuário");
}
