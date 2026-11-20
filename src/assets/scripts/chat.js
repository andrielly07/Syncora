function escaparHtml(valor) {
    return String(valor ?? '').replace(/[&<>"']/g, caractere => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[caractere]);
}

function formataData(data) {
    const horaFormatada = new Date(data).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
    const dataFormatada = new Date(data).toLocaleDateString('pt-BR')

    return `${horaFormatada} - ${dataFormatada}`
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
        .trim();
}

function renderizarMarkdown(texto) {
    const container = document.createElement('div');
    container.className = 'resumo-markdown';
    let lista = null;
    const fecharLista = () => {
        if (lista) {
            container.appendChild(lista);
            lista = null;
        }
    };

    for (const bruta of String(texto || '').split(/\r?\n/)) {
        const linha = bruta.trim();
        if (!linha) {
            fecharLista();
            continue;
        }
        const titulo = linha.match(/^#{1,3}\s+(.+)$/) || linha.match(/^\*\*(.+?)\*\*:?$/);
        if (titulo) {
            fecharLista();
            const h = document.createElement('h4');
            h.textContent = limparMarkdown(titulo[1]);
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

async function carregarConversa() {
    const params = new URLSearchParams(window.location.search);
    const historicoId = params.get('historico_id');
    const container = document.getElementById('conversa-chat');
    if (!historicoId) {
        container.innerHTML = '<p class="erro">Conversa não informada.</p>';
        return;
    }

    try {
        const resposta = await fetch(`src/service/conversa.php?historico_id=${encodeURIComponent(historicoId)}`, {
            credentials: 'same-origin'
        });
        const dados = await resposta.json();
        if (!resposta.ok) throw new Error(dados.erro || 'Não foi possível carregar a conversa.');

        document.getElementById('titulo-conversa').textContent = dados.historico.name_file;
        document.getElementById('resumo-conversa').textContent = `${dados.historico.numbers_page} páginas · criado ${new Date(dados.historico.created_at).toLocaleDateString('pt-BR')}`;
        container.innerHTML = '';

        if (!dados.mensagens.length) {
            container.innerHTML = '<p class="historico-vazio">Nenhuma resposta foi salva nesta conversa.</p>';
            return;
        }

        for (const mensagem of dados.mensagens) {
            const item = document.createElement('article');
            item.className = 'mensagem-chat mensagem-ia';
            item.innerHTML = `<div class="mensagem-meta">Página ${escaparHtml(mensagem.page_number)} · ${formataData(mensagem.created_at)}</div><div class="mensagem-conteudo"></div>`;
            item.querySelector('.mensagem-conteudo').appendChild(renderizarMarkdown(mensagem.content));
            container.appendChild(item);
        }
    } catch (erro) {
        container.innerHTML = `<p class="erro">${escaparHtml(erro.message)}</p>`;
    }
}

document.addEventListener('DOMContentLoaded', carregarConversa);
