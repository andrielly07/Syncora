<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dunwich</title>
</head>

<body>
  <?php
  session_start();
  if (!isset($_SESSION['user']['login'])) {
    header("Location: login.html");
  } else {
    header("Location: principal.php");
  }
  ?>
</body>

</html>
