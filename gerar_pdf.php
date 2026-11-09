<?php
/**
 * Recebe os resumos produzidos para cada página do PDF e os reúne em um
 * documento PDF para download. Não exige biblioteca externa de geração.
 */

function responderErroPdf($status, $mensagem)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => $mensagem], JSON_UNESCAPED_UNICODE);
    exit;
}

function textoParaWinAnsi($texto)
{
    $texto = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], (string) $texto);

    if (function_exists('iconv')) {
        $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $texto);
        if ($convertido !== false) {
            $texto = $convertido;
        }
    } else {
        // Fallback sem iconv: conserva ASCII e evita bytes UTF-8 inválidos no PDF.
        $texto = preg_replace('/[^\x20-\x7E\x0A]/', '?', $texto);
    }

    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $texto);
}

function limparMarkdownPdf($texto)
{
    $texto = (string) $texto;
    $texto = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $texto);
    $texto = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $texto);
    $texto = preg_replace('/`{1,3}([^`]+)`{1,3}/u', '$1', $texto);
    $texto = preg_replace('/\*\*(.*?)\*\*/u', '$1', $texto);
    $texto = preg_replace('/__(.*?)__/u', '$1', $texto);
    $texto = preg_replace('/\*(.*?)\*/u', '$1', $texto);
    $texto = preg_replace('/_(.*?)_/u', '$1', $texto);
    $texto = preg_replace('/^\s{0,3}#{1,6}\s+/u', '', $texto);
    $texto = preg_replace('/\s+#+\s*$/u', '', $texto);
    $texto = str_replace(
        ['\\\\*', '\\\\_', '\\\\`', '\\\\[', '\\\\]', '\\\\#', '\\\\-', '\\\\.', '\\\\!'],
        ['*', '_', '`', '[', ']', '#', '-', '.', '!'],
        $texto
    );
    return trim($texto);
}

function escaparStringPdf($textoWinAnsi)
{
    return str_replace(["\\", '(', ')'], ["\\\\", '\\(', '\\)'], $textoWinAnsi);
}

function numeroPdf($numero)
{
    return rtrim(rtrim(number_format((float) $numero, 2, '.', ''), '0'), '.');
}

function comandoTextoPdf($textoWinAnsi, $x, $y, $tamanho, $fonte, $cor)
{
    return 'q ' . $cor . ' rg BT /F' . (int) $fonte . ' ' . numeroPdf($tamanho)
        . ' Tf 1 0 0 1 ' . numeroPdf($x) . ' ' . numeroPdf($y)
        . ' Tm (' . escaparStringPdf($textoWinAnsi) . ') Tj ET Q';
}

class DocumentoResumoPdf
{
    private $paginas = [];
    private $comandos = null;
    private $y = 790;

    private function iniciarPagina()
    {
        if ($this->comandos !== null) {
            $this->paginas[] = $this->comandos;
        }

        $this->comandos = [];
        $this->y = 790;

        if (!empty($this->paginas)) {
            $this->comandos[] = comandoTextoPdf(
                textoParaWinAnsi('SYNCORA  |  RESUMO DO PDF'), 52, 808, 8, 1, '0.42 0.45 0.52'
            );
            $this->comandos[] = 'q 0.84 0.85 0.89 RG 0.7 w 52 798 m 543 798 l S Q';
            $this->y = 778;
        }
    }

    private function garantirEspaco($altura)
    {
        if ($this->y - $altura < 58) {
            $this->iniciarPagina();
        }
    }

    private function escreverLinhaBytes($bytes, $x, $tamanho, $fonte, $cor, $altura)
    {
        $this->garantirEspaco($altura);
        $this->comandos[] = comandoTextoPdf($bytes, $x, $this->y, $tamanho, $fonte, $cor);
        $this->y -= $altura;
    }

    private function escreverLinha($texto, $x, $tamanho, $fonte, $cor, $altura)
    {
        $this->escreverLinhaBytes(textoParaWinAnsi($texto), $x, $tamanho, $fonte, $cor, $altura);
    }

    private function adicionarParagrafo($texto, $recuo)
    {
        $texto = limparMarkdownPdf($texto);
        $bytes = textoParaWinAnsi($texto);
        if ($bytes === '') {
            return;
        }

        $largura = 491 - $recuo;
        $maxCaracteres = max(24, (int) floor($largura / (10.2 * 0.51)));
        $linhas = explode("\n", wordwrap($bytes, $maxCaracteres, "\n", true));

        foreach ($linhas as $indice => $linha) {
            $prefixo = ($recuo > 0 && $indice === 0) ? "\x95  " : '';
            $this->escreverLinhaBytes(trim($prefixo . $linha), 52 + $recuo, 10.2, 1, '0.16 0.18 0.23', 14);
        }
    }

    private function adicionarTituloSecao($titulo, $reservaDepois = 14)
    {
        $titulo = trim($titulo);
        if ($titulo === '') {
            return;
        }

        $bytes = textoParaWinAnsi(limparMarkdownPdf($titulo));
        $maxCaracteres = 72;
        $linhas = explode("\n", wordwrap($bytes, $maxCaracteres, "\n", true));
        $this->garantirEspaco((count($linhas) * 16) + $reservaDepois);
        foreach ($linhas as $linha) {
            $this->escreverLinhaBytes(trim($linha), 52, 11, 2, '0.28 0.22 0.48', 16);
        }
        $this->y -= 2;
    }

    private function processarResumo($resumo)
    {
        if (is_array($resumo)) {
            $sintese = isset($resumo['resumo']) && is_string($resumo['resumo'])
                ? trim($resumo['resumo']) : '';
            if ($sintese !== '') {
                $this->adicionarParagrafo($sintese, 0);
            }

            $personagens = isset($resumo['personagens']) && is_array($resumo['personagens'])
                ? $resumo['personagens'] : [];
            if (!empty($personagens)) {
                $this->adicionarTituloSecao('Personagens');
                foreach ($personagens as $personagem) {
                    if (is_string($personagem)) {
                        $this->adicionarParagrafo($personagem, 14);
                    } elseif (is_array($personagem)) {
                        $nome = isset($personagem['nome']) ? trim((string) $personagem['nome']) : '';
                        $descricao = isset($personagem['descricao']) ? trim((string) $personagem['descricao']) : '';
                        $texto = trim($nome . ($descricao !== '' ? ': ' . $descricao : ''));
                        if ($texto !== '') $this->adicionarParagrafo($texto, 14);
                    }
                }
            }

            $topicos = isset($resumo['topicos']) && is_array($resumo['topicos'])
                ? $resumo['topicos'] : [];
            $temPersonagens = !empty($personagens);
            foreach ($topicos as $topico) {
                if (!is_array($topico)) continue;
                $tituloTopico = isset($topico['titulo']) ? trim((string) $topico['titulo']) : '';
                $itens = isset($topico['itens']) && is_array($topico['itens']) ? $topico['itens'] : [];
                if ($tituloTopico === '' || empty($itens)) continue;
                if ($temPersonagens && preg_match('/^personagens?$/iu', $tituloTopico)) continue;
                $this->adicionarTituloSecao($tituloTopico);
                foreach ($itens as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $this->adicionarParagrafo($item, 14);
                    }
                }
            }
            return;
        }

        $linhas = preg_split('/\R/u', str_replace("\0", '', (string) $resumo));
        $categorias = 'personagens?|protagonistas?|antagonistas?|locais|lugares|cenários?|regras(?: e mecânicas)?|mecânicas|criaturas|monstros|itens|equipamentos|recursos|organizações|facções|enredo|história|acontecimentos|contexto|resumo|outros tópicos';

        foreach ($linhas as $linhaOriginal) {
            $linha = trim($linhaOriginal);
            if ($linha === '') {
                $this->y -= 5;
                continue;
            }

            $linhaSemMarkdown = preg_replace('/^#{1,3}\s*/u', '', $linha);
            $linhaSemMarkdown = limparMarkdownPdf($linhaSemMarkdown);
            $rotulo = null;
            $conteudoDepoisRotulo = '';

            if (preg_match('/^\*\*(.+?)\*\*:?\s*$/u', $linha, $encontrado)) {
                $rotulo = trim($encontrado[1]);
            } elseif (preg_match('/^(' . $categorias . ')\s*:?\s*(.*)$/iu', $linhaSemMarkdown, $encontrado)) {
                $rotulo = trim($encontrado[1]);
                $conteudoDepoisRotulo = trim($encontrado[2]);
            } elseif (preg_match('/^#{1,3}\s+(.+)$/u', $linha, $encontrado)) {
                $rotulo = trim($encontrado[1]);
            }

            if ($rotulo !== null) {
                $this->adicionarTituloSecao($rotulo);
                if ($conteudoDepoisRotulo !== '') {
                    $this->adicionarParagrafo($conteudoDepoisRotulo, 0);
                }
                continue;
            }

            if (preg_match('/^[•\-*]\s+(.+)$/u', $linha, $encontrado)) {
                $this->adicionarParagrafo($encontrado[1], 14);
                continue;
            }

            $this->adicionarParagrafo($linha, 0);
        }
    }

    public function gerar($titulo, $nomeArquivo, $blocos)
    {
        $this->iniciarPagina();

        $tituloWinAnsi = textoParaWinAnsi($titulo);
        $larguraTitulo = max(28, (int) floor(491 / (21 * 0.55)));
        $linhasTitulo = explode("\n", wordwrap($tituloWinAnsi, $larguraTitulo, "\n", true));
        foreach ($linhasTitulo as $linha) {
            $this->escreverLinhaBytes(trim($linha), 52, 21, 2, '0.73 0.58 0.22', 27);
        }

        $this->y -= 3;
        $this->escreverLinha('RESUMO DO PDF ENVIADO', 52, 9, 2, '0.34 0.36 0.43', 14);
        $this->escreverLinha('Arquivo original: ' . $nomeArquivo, 52, 9, 1, '0.42 0.45 0.52', 13);
        $this->escreverLinha(count($blocos) . ' blocos analisados', 52, 9, 1, '0.42 0.45 0.52', 13);
        $this->comandos[] = 'q 0.84 0.85 0.89 RG 0.8 w 52 ' . numeroPdf($this->y - 2) . ' m 543 ' . numeroPdf($this->y - 2) . ' l S Q';
        $this->y -= 17;

        foreach ($blocos as $indice => $bloco) {
            $numero = (int) $bloco['numero'];
            $this->adicionarTituloSecao('Página ' . $numero, 42);
            $this->processarResumo($bloco['resumo']);
            $this->y -= 9;
        }

        if ($this->comandos !== null) {
            $this->paginas[] = $this->comandos;
            $this->comandos = null;
        }

        return $this->montarArquivo();
    }

    private function montarArquivo()
    {
        $totalPaginas = count($this->paginas);
        $objetos = [];
        $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objetos[2] = '';
        $objetos[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objetos[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $referenciasPaginas = [];
        $proximoId = 5;

        foreach ($this->paginas as $indice => $comandos) {
            $numeroPagina = $indice + 1;
            $comandos[] = 'q 0.84 0.85 0.89 RG 0.6 w 52 47 m 543 47 l S Q';
            $rodape = 'Página ' . $numeroPagina . ' de ' . $totalPaginas;
            $bytesRodape = textoParaWinAnsi($rodape);
            $xRodape = 543 - (strlen($bytesRodape) * 4.1);
            $comandos[] = comandoTextoPdf($bytesRodape, $xRodape, 32, 8, 1, '0.48 0.50 0.56');
            $conteudo = implode("\n", $comandos);

            $idPagina = $proximoId++;
            $idConteudo = $proximoId++;
            $referenciasPaginas[] = $idPagina . ' 0 R';
            $objetos[$idPagina] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]'
                . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $idConteudo . ' 0 R >>';
            $objetos[$idConteudo] = '<< /Length ' . strlen($conteudo) . " >>\nstream\n" . $conteudo . "\nendstream";
        }

        $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $referenciasPaginas) . '] /Count ' . $totalPaginas . ' >>';
        $idInfo = $proximoId++;
        $objetos[$idInfo] = '<< /Title (' . escaparStringPdf(textoParaWinAnsi('Resumo - ' . $this->textoTitulo)) . ') /Author (Syncora) /Producer (Syncora PDF) >>';

        ksort($objetos);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objetos as $id => $objeto) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $objeto . "\nendobj\n";
        }

        $inicioXref = strlen($pdf);
        $maiorId = max(array_keys($objetos));
        $pdf .= "xref\n0 " . ($maiorId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maiorId; $id++) {
            $pdf .= sprintf('%010d 00000 n ', isset($offsets[$id]) ? $offsets[$id] : 0) . "\n";
        }
        $pdf .= 'trailer << /Size ' . ($maiorId + 1) . ' /Root 1 0 R /Info ' . $idInfo . " 0 R >>\n";
        $pdf .= "startxref\n" . $inicioXref . "\n%%EOF\n";

        return $pdf;
    }

    private $textoTitulo = 'Resumo de RPG';

    public function definirTitulo($titulo)
    {
        $this->textoTitulo = $titulo;
    }
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderErroPdf(405, 'Este endpoint aceita somente solicitações POST.');
}

$corpo = file_get_contents('php://input');
if ($corpo === false || strlen($corpo) > 25000000) {
    responderErroPdf(413, 'Os dados enviados excedem o limite permitido para gerar o PDF.');
}

$dados = json_decode($corpo, true);
if (!is_array($dados) || json_last_error() !== JSON_ERROR_NONE) {
    responderErroPdf(400, 'Não foi possível interpretar os dados dos resumos.');
}

$tituloOriginal = isset($dados['titulo']) && is_string($dados['titulo']) ? trim($dados['titulo']) : '';
$tituloOriginal = str_replace('\\', '/', $tituloOriginal);
$nomeArquivo = basename($tituloOriginal);
$titulo = preg_replace('/\.pdf$/i', '', $nomeArquivo);
$titulo = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $titulo));
if ($titulo === '') {
    $titulo = 'Resumo do RPG';
}
if (strlen($titulo) > 300) {
    $titulo = substr($titulo, 0, 300);
}
if ($nomeArquivo === '') {
    $nomeArquivo = $titulo . '.pdf';
}

$blocosRecebidos = isset($dados['paginas']) ? $dados['paginas'] : null;
if (!is_array($blocosRecebidos) || count($blocosRecebidos) === 0 || count($blocosRecebidos) > 2000) {
    responderErroPdf(400, 'Nenhum bloco válido foi recebido para compor o PDF.');
}

$blocos = [];
foreach ($blocosRecebidos as $bloco) {
    if (!is_array($bloco) || !isset($bloco['numero'], $bloco['resumo'])
        || !is_numeric($bloco['numero'])
        || (!is_array($bloco['resumo']) && !is_string($bloco['resumo']))) {
        responderErroPdf(400, 'Um dos blocos recebidos está em formato inválido.');
    }

    $resumo = $bloco['resumo'];
    $resumoValido = is_string($resumo)
        ? trim($resumo) !== '' && strlen($resumo) <= 250000
        : json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== false;
    if (!$resumoValido) {
        responderErroPdf(400, 'Um dos blocos não contém um resumo válido.');
    }

    $blocos[] = [
        'numero' => max(1, (int) $bloco['numero']),
        'resumo' => $resumo,
    ];
}

$documento = new DocumentoResumoPdf();
$documento->definirTitulo($titulo);
$pdf = $documento->gerar($titulo, $nomeArquivo, $blocos);

$nomeAscii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titulo) : $titulo;
if ($nomeAscii === false || $nomeAscii === '') {
    $nomeAscii = 'resumo-rpg';
}
$nomeAscii = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $nomeAscii), '-_');
if ($nomeAscii === '') {
    $nomeAscii = 'resumo-rpg';
}
$nomeDownload = $nomeAscii . '-resumo.pdf';
$nomeUnicode = $titulo . '-resumo.pdf';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nomeDownload . '"; filename*=UTF-8\'\'' . rawurlencode($nomeUnicode));
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store, max-age=0');
echo $pdf;
