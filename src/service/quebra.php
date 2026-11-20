<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require dirname(__DIR__, 2) . '/vendor/autoload.php';

function usuarioAtual(): int
{
    $sessao = $_SESSION['user'] ?? null;
    $usuario = is_string($sessao) ? json_decode($sessao, true) : null;
    $id = filter_var($usuario['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        http_response_code(401);
        echo json_encode(['erro' => 'Faça login antes de enviar um arquivo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $id;
}

function salvarArquivoPdf(array $file, int $usuarioId): array
{
    $raizProjeto = dirname(__DIR__, 2);
    $nomePasta = 'usuario_' . $usuarioId;
    $pasta = $raizProjeto . '/resumos/' . $nomePasta;
    if (!is_dir($pasta) && !mkdir($pasta, 0755, true) && !is_dir($pasta)) {
        throw new RuntimeException('Não foi possível criar a pasta do usuário.');
    }

    $data = date('Y-m-d_H-i-s');
    $nomeArquivo = $nomePasta . '_' . $data . '.pdf';
    $destino = $pasta . '/' . $nomeArquivo;
    $contador = 1;
    while (file_exists($destino)) {
        $nomeArquivo = $nomePasta . '_' . $data . '_' . $contador++ . '.pdf';
        $destino = $pasta . '/' . $nomeArquivo;
    }

    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        throw new RuntimeException('Não foi possível salvar o arquivo enviado.');
    }

    return [
        'nome' => $nomeArquivo,
        'caminho' => 'resumos/' . $nomePasta . '/' . $nomeArquivo,
    ];
}

const MIN_CHARS_PAGINA = 80;
const PALAVRAS_CAPA = [
    'todos os direitos reservados', 'all rights reserved', 'direitos reservados',
    'copyright', 'impresso no brasil', 'printed in brazil', 'isbn', 'cnpj',
    'depósito legal', 'ficha catalográfica', 'dedicatória', 'dedicado a',
    'aos meus', 'à minha',
];

function deveDescartarPagina(string $texto, int $numeroPagina): bool
{
    $textoLimpo = trim($texto);
    $tamanho = mb_strlen($textoLimpo, 'UTF-8');
    if ($tamanho === 0) return true;

    if ($tamanho < MIN_CHARS_PAGINA) {
        $lower = mb_strtolower($textoLimpo, 'UTF-8');
        if (preg_match('/^[\d\s\-–—]+$/', $textoLimpo)) return true;
        foreach (PALAVRAS_CAPA as $palavra) {
            if (mb_strpos($lower, $palavra) !== false) return true;
        }
        if ($numeroPagina === 1 && $tamanho < 200) return true;
    }

    $linhas = explode("\n", $textoLimpo);
    $linhasComPontilhado = 0;
    $linhasComTexto = 0;
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '') continue;
        $linhasComTexto++;
        if (preg_match('/(\.\s*){4,}\s*\d+\s*$/', $linha)) $linhasComPontilhado++;
    }
    return $linhasComTexto > 0 && ($linhasComPontilhado / $linhasComTexto) >= 0.6;
}

function normalizarTextoPagina(string $texto): string
{
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    $texto = preg_replace('/-\n([a-záàâãéêíóôõúüç])/ui', '$1', $texto);
    $texto = preg_replace('/[ \t]{2,}/', ' ', $texto);
    $texto = preg_replace('/^\s*\d{1,3}\s*$/m', '', $texto);
    $texto = preg_replace('/\n{3,}/', "\n\n", $texto);
    return trim($texto);
}

function quebrarPdfPorPaginas(): string
{
    $usuarioId = usuarioAtual();
    if (!isset($_FILES['rpg_pdf'])) {
        return json_encode(['sucesso' => false, 'mensagem' => 'Arquivo não indexado corretamente no FormData.'], JSON_UNESCAPED_UNICODE);
    }

    $file = $_FILES['rpg_pdf'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return json_encode(['sucesso' => false, 'mensagem' => 'Erro no upload do arquivo (código ' . $file['error'] . ').'], JSON_UNESCAPED_UNICODE);
    }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        return json_encode(['sucesso' => false, 'mensagem' => 'Apenas arquivos PDF podem ser salvos.'], JSON_UNESCAPED_UNICODE);
    }

    try {
        $arquivoSalvo = salvarArquivoPdf($file, $usuarioId);
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile(dirname(__DIR__, 2) . '/' . $arquivoSalvo['caminho']);
    } catch (Throwable $e) {
        if (isset($arquivoSalvo['caminho'])) {
            @unlink(dirname(__DIR__, 2) . '/' . $arquivoSalvo['caminho']);
        }
        return json_encode(['sucesso' => false, 'mensagem' => 'Não foi possível abrir ou salvar o PDF: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    $paginas = $pdf->getPages();
    if (empty($paginas)) {
        @unlink(dirname(__DIR__, 2) . '/' . $arquivoSalvo['caminho']);
        return json_encode(['sucesso' => false, 'mensagem' => 'O PDF não contém páginas legíveis.'], JSON_UNESCAPED_UNICODE);
    }

    $paginasUteis = [];
    $totalPaginas = count($paginas);
    $descartadas = 0;
    foreach ($paginas as $numeroPagina => $pagina) {
        $numReal = $numeroPagina + 1;
        try {
            $textoBruto = $pagina->getText();
        } catch (Throwable $e) {
            $descartadas++;
            continue;
        }
        $textoNormalizado = normalizarTextoPagina($textoBruto);
        if (deveDescartarPagina($textoNormalizado, $numReal)) {
            $descartadas++;
            continue;
        }
        $paginasUteis[] = ['numero' => $numReal, 'texto' => $textoNormalizado];
    }

    if (empty($paginasUteis)) {
        @unlink(dirname(__DIR__, 2) . '/' . $arquivoSalvo['caminho']);
        return json_encode(['sucesso' => false, 'mensagem' => 'Nenhuma página com conteúdo útil foi encontrada no PDF.'], JSON_UNESCAPED_UNICODE);
    }

    return json_encode([
        'sucesso' => true,
        'total_paginas' => $totalPaginas,
        'total_uteis' => count($paginasUteis),
        'total_descartadas' => $descartadas,
        'arquivo_salvo' => $arquivoSalvo,
        'paginas' => $paginasUteis,
    ], JSON_UNESCAPED_UNICODE);
}

echo quebrarPdfPorPaginas();
