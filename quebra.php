<?php
header('Content-Type: application/json; charset=utf-8');
require 'vendor/autoload.php';

// ─── LIMITE MÍNIMO DE TEXTO ÚTIL POR PÁGINA (em caracteres) ──────────────────
const MIN_CHARS_PAGINA = 80;

// ─── PADRÕES QUE INDICAM PÁGINAS CLARAMENTE INÚTEIS ──────────────────────────
// Usados apenas quando toda a página corresponde a esses padrões.
const PADROES_PAGINA_INUTIL = [
    // Sumário / Índice: linha com pontilhado e número de página
    '/(\.\s*){5,}\s*\d+/',
    // Página exclusivamente de números e espaços
    '/^\s*[\d\s]+\s*$/',
];

// Palavras que, sozinhas em uma página curta, indicam capa/folha de rosto
const PALAVRAS_CAPA = [
    'todos os direitos reservados',
    'all rights reserved',
    'direitos reservados',
    'copyright',
    'impresso no brasil',
    'printed in brazil',
    'isbn',
    'cnpj',
    'depósito legal',
    'ficha catalográfica',
    'dedicatória',
    'dedicado a',
    'aos meus',
    'à minha',
];

/**
 * Decide se uma página deve ser descartada.
 * Retorna true apenas quando há certeza de que a página não tem conteúdo útil.
 */
function deveDescartarPagina(string $texto, int $numeroPagina): bool
{
    $textoLimpo = trim($texto);
    $tamanho    = mb_strlen($textoLimpo, 'UTF-8');

    // Página completamente vazia
    if ($tamanho === 0) {
        return true;
    }

    // Muito curta: pode ser capa, número de página solto, decoração
    if ($tamanho < MIN_CHARS_PAGINA) {
        $lower = mb_strtolower($textoLimpo, 'UTF-8');

        // Somente dígitos e espaços (ex.: número de página, código de barra)
        if (preg_match('/^[\d\s\-–—]+$/', $textoLimpo)) {
            return true;
        }

        // Palavras administrativas/decorativas típicas de capa
        foreach (PALAVRAS_CAPA as $palavra) {
            if (mb_strpos($lower, $palavra) !== false) {
                return true;
            }
        }

        // Primeira página curta: provavelmente capa ou folha de rosto
        if ($numeroPagina === 1 && $tamanho < 200) {
            return true;
        }
    }

    // Página que parece exclusivamente um sumário (maioria das linhas com pontilhado + número)
    $linhas = explode("\n", $textoLimpo);
    $linhasComPontilhado = 0;
    $linhasComTexto = 0;
    foreach ($linhas as $linha) {
        $l = trim($linha);
        if ($l === '') continue;
        $linhasComTexto++;
        if (preg_match('/(\.\s*){4,}\s*\d+\s*$/', $l)) {
            $linhasComPontilhado++;
        }
    }
    if ($linhasComTexto > 0 && ($linhasComPontilhado / $linhasComTexto) >= 0.6) {
        return true;
    }

    return false;
}

/**
 * Normaliza o texto extraído de uma página:
 * - Remove hifenização no final de linha
 * - Colapsa espaços múltiplos
 * - Normaliza quebras de linha
 */
function normalizarTextoPagina(string $texto): string
{
    // Normaliza quebras de linha
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);

    // Remove hifenização ao fim de linha (ex: "pala-\nvra" → "palavra")
    $texto = preg_replace('/-\n([a-záàâãéêíóôõúüç])/ui', '$1', $texto);

    // Colapsa espaços múltiplos em um único espaço
    $texto = preg_replace('/[ \t]{2,}/', ' ', $texto);

    // Remove linhas que são somente números (rodapés de página)
    $texto = preg_replace('/^\s*\d{1,3}\s*$/m', '', $texto);

    // Colapsa mais de duas linhas em branco consecutivas
    $texto = preg_replace('/\n{3,}/', "\n\n", $texto);

    return trim($texto);
}

function quebrarPdfPorPaginas(): string
{
    if (!isset($_FILES['rpg_pdf'])) {
        return json_encode([
            'sucesso'   => false,
            'mensagem'  => 'Arquivo não indexado corretamente no FormData.',
        ], JSON_UNESCAPED_UNICODE);
    }

    $file = $_FILES['rpg_pdf'];

    // Valida tipo MIME básico
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return json_encode([
            'sucesso'  => false,
            'mensagem' => 'Erro no upload do arquivo (código ' . $file['error'] . ').',
        ], JSON_UNESCAPED_UNICODE);
    }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($file['tmp_name']);
    } catch (\Exception $e) {
        return json_encode([
            'sucesso'  => false,
            'mensagem' => 'Não foi possível abrir o PDF: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

    $paginas = $pdf->getPages();

    if (empty($paginas)) {
        return json_encode([
            'sucesso'  => false,
            'mensagem' => 'O PDF não contém páginas legíveis.',
        ], JSON_UNESCAPED_UNICODE);
    }

    $paginasUteis   = [];
    $totalPaginas   = count($paginas);
    $descartadas    = 0;

    foreach ($paginas as $numeroPagina => $pagina) {
        $numReal = $numeroPagina + 1; // índice 0-based → número 1-based

        try {
            $textoBruto = $pagina->getText();
        } catch (\Exception $e) {
            // Página ilegível: registra como descartada, continua
            $descartadas++;
            continue;
        }

        $textoNormalizado = normalizarTextoPagina($textoBruto);

        if (deveDescartarPagina($textoNormalizado, $numReal)) {
            $descartadas++;
            continue;
        }

        $paginasUteis[] = [
            'numero' => $numReal,
            'texto'  => $textoNormalizado,
        ];
    }

    if (empty($paginasUteis)) {
        return json_encode([
            'sucesso'  => false,
            'mensagem' => 'Nenhuma página com conteúdo útil foi encontrada no PDF.',
        ], JSON_UNESCAPED_UNICODE);
    }

    return json_encode([
        'sucesso'         => true,
        'total_paginas'   => $totalPaginas,
        'total_uteis'     => count($paginasUteis),
        'total_descartadas' => $descartadas,
        'paginas'         => $paginasUteis,
    ], JSON_UNESCAPED_UNICODE);
}

echo quebrarPdfPorPaginas();
