<?php
require "../config/conection.php";
session_start();
$user = $_SESSION['user'];

try {
  $sql = "SELECT * FROM tb_usuario WHERE user_id = :user_id";

  $stm = conect()->prepare($sql);
  $stm->bindValue(":user_id", $user['user_id']);
  $stm->execute();

  $usuario = $stm->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  echo $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dunwich</title>
  <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/dunwich.css">
  <script src="../assets/js/dunwich.js" defer></script>
</head>

<body>
  <div class="palco">
    <img class="p" src="../assets/img/livro.png" alt="" style="--x:80;--y:195;--w:1760">
    <img class="p" src="../assets/img/logo.png" alt="Dunwich" style="--x:795;--y:38;--w:305">

    <!-- Página esquerda: usuário e índice de tomos -->
    <p class="p" style="--x:300;--y:303;--w:380;--f:17;--lh:.95">Aqui uma nova<br>história é contada...</p>
    <img class="p" src="../assets/img/avatar.png" alt="" style="--x:310;--y:388;--w:112">
    <p class="p" style="--x:440;--y:404;--f:24">
      <?php echo $usuario['username']; ?>
    </p>
    <img class="p" src="../assets/img/ornamento.png" alt="" style="--x:330;--y:518;--w:520">
    <p class="p" style="--x:330;--y:612;--f:16">Índice de tomos:</p>
    <div id="historico-lista" class="p historico-lista" aria-live="polite"
      style="--x:328;--y:648;--w:520;--f:12;--lh:1.25">
      <p>Nenhum tomo processado ainda...</p>
    </div>
    <a class="p" href="#" style="--x:322;--y:1100;--f:15">Configurações</a>
    <p class="p" style="--x:625;--y:1100;--f:16">Idioma: Port(BRA)</p>

    <!-- Página direita: enviar o tomo -->
    <p class="p" style="--x:1042;--y:312;--w:590;--f:18;--lh:.95">Me envie um livro de regras para que eu possa
      resumi-lo, assim te ajudarei a entender melhor o universo que ali está!</p>
    <img class="p" src="../assets/img/ornamento.png" alt="" style="--x:1075;--y:385;--w:520">
    <section id="processamento" class="p processamento" aria-live="polite"
      style="--x:1042;--y:850;--w:580;--f:13;--lh:1.25">
      <p id="processamento-status">Escolha um tomo para iniciar a análise.</p>
      <div id="previas-ia" class="previas-ia"></div>
    </section>

    <!-- Para o back-end -->
    <form>
      <input class="oculto" type="file" id="arquivo" name="tomo" accept=".pdf" required>
      <label class="p tomo centro" for="arquivo" style="--x:1125;--y:470;--w:420;--f:18"><img src="../assets/img/selo.png"
          alt=""><span id="tomo-name">Adicionar um novo tomo</span></label>
      <label class="p modo-teste" for="modo-teste" style="--x:1042;--y:1200;--w:520;--f:12">
        <input type="checkbox" id="modo-teste" checked>
        Modo de teste: resumir somente as primeiras 5 páginas úteis
      </label>
      <button class="p botao enviar" type="submit" style="--x:1090;--y:1230;--w:500;--f:18">Enviar
        tomo</button>
    </form>

    <!-- Marcador com a lixeira -->
    <a class="p" href="#" aria-label="Limpar" style="--x:900;--y:1250;--w:120;--h:190;height:calc(190*var(--px))"></a>

    <!-- Painel de madeira clara com a Syncora -->
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