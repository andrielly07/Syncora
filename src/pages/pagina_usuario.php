<?php
require_once '../config/conection.php';
session_start();

$sessao = $_SESSION['user'] ?? null;
$usuarioId = filter_var($sessao['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || $usuarioId < 1) {
  header('Location: login.html');
  exit;
}

try {
  $stmt = conect()->prepare('SELECT username, email FROM tb_usuario WHERE user_id = :user_id');
  $stmt->execute([':user_id' => $usuarioId]);
  $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$usuario) {
    header('Location: login.html');
    exit;
  }
} catch (Throwable $erro) {
  header('Location: principal.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dunwich – Página do usuário</title>
  <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/dunwich.css">
  <script src="../assets/js/dunwich.js" defer></script>
</head>

<body class="pagina-usuario">
  <div class="palco">
    <img class="p" src="../assets/img/livro.png" alt="" style="--x:80;--y:195;--w:1760">
    <img class="p" src="../assets/img/logo.png" alt="Dunwich" style="--x:795;--y:38;--w:305">

    <a class="p usuario-voltar" href="principal.php" style="--x:1255;--y:320;--f:18">Voltar para os tomos</a>
    <p class="p usuario-titulo" style="--x:350;--y:290;--w:500;--f:24">Página do usuário</p>
    <img class="p" src="../assets/img/ornamento.png" alt="" style="--x:315;--y:355;--w:560">

    <img class="p usuario-avatar" src="../assets/img/avatar.png" alt="Avatar do usuário"
      style="--x:495;--y:480;--w:180">
    <p id="usuario-nome-atual" class="p usuario-nome" style="--x:290;--y:700;--w:600;--f:26;--lh:1.2">
      <?php echo htmlspecialchars($usuario['username'], ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <p id="usuario-email-atual" class="p usuario-email" style="--x:240;--y:770;--w:700;--f:14;--lh:1.4">
      <?php echo htmlspecialchars($usuario['email'], ENT_QUOTES, 'UTF-8'); ?>
    </p>

    <p class="p usuario-instrução" style="--x:300;--y:875;--w:600;--f:14;--lh:1.4">
      Gerencie o acesso à sua conta ou encerre sua jornada em Dunwich.
    </p>
    <div class="p usuario-acoes" style="--x:295;--y:1010;--w:600">
      <button id="logout" class="botao conta-acao usuario-acao-sair" type="button">Sair da conta</button>
      <button id="excluir-conta" class="botao conta-acao usuario-acao-excluir" type="button">Excluir conta</button>
    </div>

    <section class="p usuario-edicao" style="--x:1022;--y:390;--w:600">
      <h1 id="titulo-edicao-usuario">Alterar meus dados</h1>
      <p class="usuario-edicao-intro">Atualize seu nome e seu e-mail para manter seu perfil sempre correto.</p>
      <form id="form-usuario" method="post">
        <label for="novo-nome">Nome</label>
        <input id="novo-nome" name="nome" type="text" maxlength="100" autocomplete="name" required
          value="<?php echo htmlspecialchars($usuario['username'], ENT_QUOTES, 'UTF-8'); ?>">
        <label for="novo-email">E-mail</label>
        <input id="novo-email" name="email" type="email" maxlength="150" autocomplete="email" required
          value="<?php echo htmlspecialchars($usuario['email'], ENT_QUOTES, 'UTF-8'); ?>">
        <button class="botao-secundario usuario-salvar" type="submit">Salvar alterações</button>
        <p id="usuario-feedback" class="usuario-feedback" role="status" aria-live="polite"></p>
      </form>
    </section>

    <div class="p r ext" style="--x:1912;--w:648"></div>
    <img class="p r" src="../assets/img/painel.png" alt="" style="--x:1912;--y:0;--w:648">
    <p class="p r" style="--x:2058;--y:18;--f:24">Desenvolvido por</p>
    <nav aria-label="Syncora">
      <a class="p r" href="#" style="--x:1993;--y:505;--f:24">Serviços</a>
      <a class="p r" href="#" style="--x:1993;--y:553;--f:24">Contatos</a>
      <a class="p r" href="#" style="--x:1993;--y:601;--f:24">Sobre</a>
      <img class="p r" src="../assets/img/ig.png" alt="" style="--x:1952;--y:650;--w:36">
      <a class="p r" href="#" style="--x:1996;--y:653;--f:24">Instagram</a>
      <img class="p r" src="../assets/img/x.png" alt="" style="--x:1952;--y:698;--w:36">
      <a class="p r" href="#" style="--x:1996;--y:701;--f:24">X (Twitter)</a>
      <img class="p r" src="../assets/img/fb.png" alt="" style="--x:1952;--y:746;--w:36">
      <a class="p r" href="#" style="--x:1996;--y:749;--f:24">Facebook</a>
    </nav>
    <p class="p r" style="--x:2205;--y:962;--f:24">2026</p>
  </div>
</body>

</html>