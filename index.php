<?php
session_start();

if (!isset($_SESSION['user']['login'])) {
    header('Location: src/pages/login.html');
    exit;
}

header('Location: src/pages/principal.php');
exit;
