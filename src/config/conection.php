<?php
function conect()
{
    $servename = 'localhost';
    $dbname = 'syncora';
    $username = 'root';
    $password = 'senha';

    try {
        $dsn = "mysql:host=$servename;dbname=$dbname;charset=utf8mb4";
        $conn = new PDO($dsn, $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $conn;
    } catch (PDOException $th) {
        echo json_encode([
            "status" => $th->getCode(),
            "message" => $th->getMessage()
        ]);
    }
}

function responseMessage($status, $message)
{
    return json_encode([
        "status" => $status,
        "message" => $message
    ]);
}