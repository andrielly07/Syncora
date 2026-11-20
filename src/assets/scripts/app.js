const LIMITE_PAGINAS_TESTE = 5;
const TEMPO_LIMITE_BLOCO_MS = 180000;
let controleCancelamento = null;
let urlPdfAtual = null;
let conversaAtual = null;
let historicoAtual = null;

function escaparTexto(valor) {
    return String(valor ?? '').replace(/[&<>"]/g, caractere => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'
    })[caractere]);
}

function formataData(data) {
    const horaFormatada = new Date(data).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
    const dataFormatada = new Date(data).toLocaleDateString('pt-BR')

    return `${horaFormatada} - ${dataFormatada}`
}

function renderizarHistorico(mensagens) {
    const container = document.getElementById('historico-chat');
    if (!container) return;
    container.innerHTML = '';

    if (!mensagens.length) {
        container.innerHTML = '<p class="historico-vazio">Nenhum arquivo foi processado ainda.</p>';
        return;
    }

    for (const historico of mensagens) {
        const item = document.createElement('a');
        item.className = 'card-historico';
        item.href = `chat.html?historico_id=${encodeURIComponent(historico.historico_id)}`;
        item.innerHTML = `
            <strong>${escaparTexto(historico.name_file)}</strong>
            <span>${escaparTexto(historico.numbers_page)} páginas · ${escaparTexto(historico.total_mensagens)} respostas</span>
            <small>${formataData(historico.created_at)}</small>`;
        container.appendChild(item);
    }
}

async function carregarHistorico() {
    const container = document.getElementById('historico-chat');
    if (!container) return;
    try {
        const resposta = await fetch('src/service/historico.php', { credentials: 'same-origin' });
        const dados = await resposta.json();

        if (!resposta.ok) throw new Error(dados.erro || 'Não foi possível carregar o histórico.');
        renderizarHistorico(dados.historicos || []);
    } catch (erro) {
        container.innerHTML = `<p class="erro">Erro ao carregar histórico</p>`;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    carregarHistorico();
    document.getElementById('btn-atualizar-historico')?.addEventListener('click', carregarHistorico);
});

function escaparHtml(valor) {
    return String(valor).replace(/[&<>"']/g, caractere => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[caractere]);
}

function atualizarStatus(caixaResposta, html) {
    caixaResposta.innerHTML = html;
}

function criarLista(itens) {
    const lista = document.createElement('ul');
    for (const item of itens || []) {
        const li = document.createElement('li');
        li.textContent = item;
        lista.appendChild(li);
    }
    return lista;
}

function adicionarPrevia(container, numeroPagina, progresso, dados) {
    const bloco = document.createElement('section');
    bloco.className = 'bloco-resumo';

    const cabecalho = document.createElement('div');
    cabecalho.className = 'bloco-header';
    cabecalho.innerHTML = `<span class="bloco-titulo">Página ${escaparHtml(numeroPagina)}</span><span class="bloco-progresso">${escaparHtml(progresso)} · resumo recebido</span>`;
    bloco.appendChild(cabecalho);

    const texto = typeof dados === 'string' ? dados : (dados.resumo || '');
    bloco.appendChild(renderizarMarkdown(texto));

    container.appendChild(bloco);
}

function adicionarFalha(container, numeroPagina, progresso, mensagem) {
    const bloco = document.createElement('section');
    bloco.className = 'bloco-resumo bloco-ignorado';

    const cabecalho = document.createElement('div');
    cabecalho.className = 'bloco-header';
    cabecalho.innerHTML = `<span class="bloco-titulo">Página ${escaparHtml(numeroPagina)}</span><span class="bloco-progresso">${escaparHtml(progresso)} · ignorada</span>`;

    const aviso = document.createElement('p');
    aviso.className = 'aviso-ignorado';
    aviso.textContent = ` Bloco ignorado: ${mensagem}`;

    bloco.append(cabecalho, aviso);
    container.appendChild(bloco);
}

async function lerJsonResposta(response, mensagemPadrao) {
    let dados;
    try {
        dados = await response.json();
    } catch (erro) {
        throw new Error(mensagemPadrao);
    }
    if (!response.ok || dados.erro) {
        throw new Error(dados.erro || mensagemPadrao);
    }
    return dados;
}

function renderizarMarkdown(texto) {
    const container = document.createElement('div');
    container.className = 'resumo-markdown';
    const linhas = String(texto || '').split(/\r?\n/);
    let lista = null;

    const fecharLista = () => {
        if (lista) {
            container.appendChild(lista);
            lista = null;
        }
    };

    for (const linhaBruta of linhas) {
        const linha = linhaBruta.trim();
        if (!linha) {
            fecharLista();
            continue;
        }

        const titulo = linha.match(/^#{1,3}\s+(.+)$/);
        const tituloNegrito = linha.match(/^\*\*(.+?)\*\*:?$/);
        if (titulo || tituloNegrito) {
            fecharLista();
            const h = document.createElement('h4');
            h.textContent = limparMarkdown(titulo ? titulo[1] : tituloNegrito[1]);
            container.appendChild(h);
            continue;
        }

        const item = linha.match(/^(?:[-*•]|\d+[.)])\s+(.+)$/);
        if (item) {
            if (!lista) lista = document.createElement('ul');
            const li = document.createElement('li');
            li.textContent = limparMarkdown(item[1]);
            lista.appendChild(li);
            continue;
        }

        fecharLista();
        const p = document.createElement('p');
        p.textContent = limparMarkdown(linha);
        container.appendChild(p);
    }
    fecharLista();
    return container;
}

function limparMarkdown(texto) {
    return String(texto || '')
        .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/`{1,3}([^`]+)`{1,3}/g, '$1')
        .replace(/\*\*(.*?)\*\*/g, '$1')
        .replace(/__(.*?)__/g, '$1')
        .replace(/\*(.*?)\*/g, '$1')
        .replace(/_(.*?)_/g, '$1')
        .replace(/^\s{0,3}#{1,6}\s+/, '')
        .replace(/\s+#+\s*$/, '')
        .replace(/\\([\\`*_[\]{}()#+.!\->])/g, '$1')
        .trim();
}

async function fetchComTimeout(url, opcoes, tempoLimite, mensagemTimeout) {
    const controladorBloco = new AbortController();
    const sinalPai = opcoes.signal;
    let abortadoPeloPai = false;

    const abortarBloco = () => {
        abortadoPeloPai = true;
        controladorBloco.abort();
    };
    if (sinalPai) {
        if (sinalPai.aborted) abortarBloco();
        else sinalPai.addEventListener('abort', abortarBloco, { once: true });
    }

    const temporizador = setTimeout(() => controladorBloco.abort(), tempoLimite);
    try {
        return await fetch(url, { ...opcoes, signal: controladorBloco.signal });
    } catch (erro) {
        if (erro.name === 'AbortError' && !abortadoPeloPai) {
            throw new Error(mensagemTimeout);
        }
        throw erro;
    } finally {
        clearTimeout(temporizador);
        if (sinalPai) sinalPai.removeEventListener('abort', abortarBloco);
    }
}

async function enviar() {
    const fileInput = document.getElementById('arquivo');
    const caixaResposta = document.querySelector('#resp');
    const botaoEnviar = document.getElementById('btn-enviar');
    const botaoParar = document.getElementById('btn-parar');
    const modoTeste = document.getElementById('modo-teste').checked;

    if (controleCancelamento) return;
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Selecione um arquivo PDF!');
        return;
    }
    const arquivo = fileInput.files[0];
    if (!/\.pdf$/i.test(arquivo.name)) {
        alert('O arquivo selecionado precisa estar no formato PDF.');
        return;
    }
    if (urlPdfAtual) {
        URL.revokeObjectURL(urlPdfAtual);
        urlPdfAtual = null;
    }

    const nomeArquivo = arquivo.name;
    const nomeSeguro = escaparHtml(nomeArquivo);
    conversaAtual = (window.crypto && crypto.randomUUID)
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    controleCancelamento = new AbortController();
    botaoEnviar.disabled = true;
    botaoParar.disabled = false;
    atualizarStatus(caixaResposta, `<p> Preparando <b>${nomeSeguro}</b> para análise…</p>`);

    try {
        const formPdf = new FormData();
        formPdf.append('rpg_pdf', arquivo);
        const respQuebra = await fetch('src/service/quebra.php', {
            method: 'POST', body: formPdf, signal: controleCancelamento.signal
        });

        const dadosPdf = await lerJsonResposta(respQuebra, 'O servidor retornou uma resposta inválida ao processar o PDF.');
        if (!dadosPdf.sucesso) throw new Error(dadosPdf.mensagem || 'Não foi possível ler o PDF enviado.');

        const paginasEncontradas = dadosPdf.paginas || [];
        if (!paginasEncontradas.length) throw new Error('Nenhuma página útil foi encontrada no PDF.');
        const paginas = modoTeste ? paginasEncontradas.slice(0, LIMITE_PAGINAS_TESTE) : paginasEncontradas;

        const formHistorico = new FormData();
        formHistorico.append('name_file', nomeArquivo);
        formHistorico.append('numbers_page', dadosPdf.total_paginas || paginasEncontradas.length);
        formHistorico.append('stored_file_name', dadosPdf.arquivo_salvo?.nome || '');
        formHistorico.append('stored_file_path', dadosPdf.arquivo_salvo?.caminho || '');
        const respHistorico = await fetch('src/service/criar_historico.php', {
            method: 'POST',
            body: formHistorico,
            credentials: 'same-origin',
            signal: controleCancelamento.signal,
        });
        const dadosHistorico = await lerJsonResposta(respHistorico, 'Não foi possível criar o histórico deste arquivo.');
        historicoAtual = Number(dadosHistorico.historico_id);
        if (!historicoAtual) throw new Error('O servidor não retornou o identificador do histórico.');
        carregarHistorico();

        atualizarStatus(caixaResposta, `
            <div class="resumo-stats">
                <span> <b>${Number(dadosPdf.total_paginas) || 0} páginas</b> no PDF</span>
                <span class="badge badge-uteis"> ${paginasEncontradas.length} úteis</span>
                ${modoTeste ? `<span class="badge badge-teste"> teste: ${paginas.length} processadas</span>` : ''}
                ${(Number(dadosPdf.total_descartadas) || 0) > 0 ? `<span class="badge badge-desc"> ${Number(dadosPdf.total_descartadas)} descartadas</span>` : ''}
            </div>
            <p id="status-processamento" aria-live="polite"> Iniciando a análise dos blocos…</p>
            <div id="previas-ia" aria-live="polite"></div>
        `);

        const status = document.getElementById('status-processamento');
        const previas = document.getElementById('previas-ia');
        const blocos = [];
        let blocosIgnorados = 0;

        for (let i = 0; i < paginas.length; i++) {
            const pagina = paginas[i];
            const progresso = `${i + 1}/${paginas.length}`;
            status.textContent = ` Resumindo página ${pagina.numero} (${progresso})…`;

            try {
                const formOllama = new FormData();
                formOllama.append('texto_pagina', pagina.texto);
                formOllama.append('numero_pagina', pagina.numero);
                formOllama.append('modo_teste', modoTeste ? '1' : '0');
                formOllama.append('conversa_id', conversaAtual);
                formOllama.append('historico_id', historicoAtual);
                formOllama.append('documento', nomeArquivo);

                const respOllama = await fetchComTimeout('src/service/ollama.php', {
                    method: 'POST', body: formOllama, signal: controleCancelamento.signal
                }, TEMPO_LIMITE_BLOCO_MS, `Tempo limite excedido na página ${pagina.numero}. Bloco ignorado.`);
                const textoResumo = (await respOllama.text()).trim();
                if (!respOllama.ok || !textoResumo || /^\[(?:Erro|Sem resposta)/i.test(textoResumo)) {
                    throw new Error(textoResumo || `A IA não retornou um resumo para a página ${pagina.numero}.`);
                }
                blocos.push({ numero: Number(pagina.numero) || (i + 1), resumo: textoResumo });
                adicionarPrevia(previas, pagina.numero, progresso, textoResumo);
                carregarHistorico();
                status.textContent = `Resumo da página ${progresso} recebido. ${i < paginas.length - 1 ? 'Continuando…' : 'Preparando o PDF…'}`;
            } catch (erroBloco) {
                // Um bloco com erro ou resposta inválida não interrompe o livro inteiro.
                if (erroBloco.name === 'AbortError') throw erroBloco;
                blocosIgnorados++;
                const mensagem = erroBloco.message || 'Resposta vazia ou inválida.';
                adicionarFalha(previas, pagina.numero, progresso, mensagem);
                status.textContent = ` Página ${pagina.numero} ignorada. ${i < paginas.length - 1 ? 'Continuando para a próxima…' : 'Preparando o PDF com os blocos válidos…'}`;
            }
            caixaResposta.scrollTop = caixaResposta.scrollHeight;
        }

        if (!blocos.length) {
            throw new Error('Nenhum bloco retornou conteúdo válido; não foi possível gerar o PDF.');
        }

        status.textContent = ' Compilando os resumos em um único arquivo PDF…';
        const respPdf = await fetch('src/service/gerar_pdf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify({ titulo: nomeArquivo, paginas: blocos }),
            signal: controleCancelamento.signal,
        });
        const conteudoPdf = await respPdf.blob();
        if (!respPdf.ok || conteudoPdf.type !== 'application/pdf' || !conteudoPdf.size) {
            const mensagem = await conteudoPdf.text();
            let detalhe = 'O servidor não conseguiu gerar o PDF final.';
            try { detalhe = JSON.parse(mensagem).erro || detalhe; } catch (ignorado) { if (mensagem) detalhe = mensagem; }
            throw new Error(detalhe);
        }

        urlPdfAtual = URL.createObjectURL(conteudoPdf);
        const nomeBase = nomeArquivo.replace(/\.pdf$/i, '').replace(/[\\/:*?"<>|]/g, '-');
        const nomeDownload = `${nomeBase || 'resumo-rpg'}-resumo.pdf`;
        const link = document.createElement('a');
        link.href = urlPdfAtual;
        link.download = nomeDownload;
        link.className = 'link-download';
        link.textContent = 'Baixar o resumo em PDF';
        status.className = 'concluido';
        status.textContent = modoTeste
            ? ` PDF de teste gerado com ${blocos.length} página(s) válida(s). ${blocosIgnorados ? `${blocosIgnorados} ignorada(s).` : ''}`
            : ` Resumo completo gerado com ${blocos.length} página(s) válida(s). ${blocosIgnorados ? `${blocosIgnorados} ignorada(s).` : ''}`;
        caixaResposta.appendChild(link);

    } catch (erro) {
        const mensagem = erro.name === 'AbortError' ? 'Processamento interrompido.' : `Erro: ${erro.message || 'Falha inesperada.'}`;
        const status = document.getElementById('status-processamento');
        if (status) { status.className = 'erro'; status.textContent = mensagem; }
        else atualizarStatus(caixaResposta, `<p class="erro">${escaparHtml(mensagem)}</p>`);
    } finally {
        controleCancelamento = null;
        botaoEnviar.disabled = false;
        botaoParar.disabled = true;
    }
}

function parar() {
    if (controleCancelamento) controleCancelamento.abort();
}
