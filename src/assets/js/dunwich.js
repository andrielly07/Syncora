const API_BASE = '../service/';
const LIMITE_PAGINAS_TESTE = 5;

function lerJson(response, mensagem) {
  return response.json().catch(() => ({})).then((dados) => {
    if (!response.ok || dados.erro || dados.status >= 400) {
      throw new Error(dados.erro || dados.message || mensagem);
    }
    return dados;
  });
}

async function baixarResumo(evento) {
  evento.preventDefault();
  const link = evento.currentTarget;
  try {
    const resposta = await fetch(link.href, { credentials: 'same-origin' });
    const tipo = resposta.headers.get('content-type') || '';
    if (!resposta.ok || !tipo.includes('application/pdf')) {
      const corpo = await resposta.text();
      let mensagem = 'O resumo não está disponível para download.';
      try {
        const dados = JSON.parse(corpo);
        mensagem = dados.erro || dados.message || mensagem;
      } catch (erroLeitura) {
        if (corpo.trim()) mensagem = corpo.trim();
      }
      throw new Error(mensagem);
    }
    const arquivo = await resposta.blob();
    const urlTemporaria = URL.createObjectURL(arquivo);
    const a = document.createElement('a');
    a.href = urlTemporaria;
    a.download = link.dataset.nomeDownload || 'resumo-rpg.pdf';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(urlTemporaria), 1000);
  } catch (erro) {
    const detalhe = erro.message && erro.message !== 'O resumo não está disponível para download.'
      ? `\n${erro.message}`
      : '';
    alert(`O resumo não está disponível para download.${detalhe}`);
  }
}

async function fazerLogout(evento) {
  evento.preventDefault();
  try {
    await lerJson(await fetch(`${API_BASE}auth/logout.php`, {
      method: 'POST', credentials: 'same-origin'
    }), 'Não foi possível sair da conta.');
    window.location.href = 'login.html';
  } catch (erro) {
    alert(erro.message || 'Não foi possível sair da conta.');
  }
}

async function excluirConta(evento) {
  evento.preventDefault();
  if (!window.confirm('Excluir a conta apagará seus históricos, conversas e arquivos. Essa ação não pode ser desfeita. Deseja continuar?')) return;
  const botao = evento.currentTarget;
  botao.disabled = true;
  try {
    await lerJson(await fetch(`${API_BASE}auth/excluir_conta.php`, {
      method: 'POST', credentials: 'same-origin'
    }), 'Não foi possível excluir a conta.');
    alert('Conta excluída com sucesso.');
    window.location.href = 'login.html';
  } catch (erro) {
    alert(erro.message || 'Não foi possível excluir a conta.');
    botao.disabled = false;
  }
}

async function atualizarUsuario(evento) {
  evento.preventDefault();
  const formulario = evento.currentTarget;
  const botao = formulario.querySelector('button[type="submit"]');
  const feedback = document.getElementById('usuario-feedback');
  if (botao) {
    botao.disabled = true;
    botao.textContent = 'Salvando…';
  }
  try {
    const resposta = await lerJson(await fetch(`${API_BASE}auth/atualizar_usuario.php`, {
      method: 'POST', body: new FormData(formulario), credentials: 'same-origin'
    }), 'Não foi possível atualizar seus dados.');
    const usuario = resposta.usuario || {};
    const nome = usuario.username || formulario.elements.nome.value;
    const email = usuario.email || formulario.elements.email.value;
    const nomeAtual = document.getElementById('usuario-nome-atual');
    const emailAtual = document.getElementById('usuario-email-atual');
    if (nomeAtual) nomeAtual.textContent = nome;
    if (emailAtual) emailAtual.textContent = email;
    if (feedback) {
      feedback.textContent = 'Dados atualizados com sucesso.';
      feedback.className = 'usuario-feedback sucesso';
    }
  } catch (erro) {
    if (feedback) {
      feedback.textContent = erro.message || 'Não foi possível atualizar seus dados.';
      feedback.className = 'usuario-feedback erro';
    }
    alert(erro.message || 'Não foi possível atualizar seus dados.');
  } finally {
    if (botao) {
      botao.disabled = false;
      botao.textContent = 'Salvar alterações';
    }
  }
}

async function excluirHistorico(evento) {
  evento.preventDefault();
  const botao = evento.currentTarget;
  if (!window.confirm('Apagar este histórico, conversa e arquivos associados? Essa ação não pode ser desfeita.')) return;
  botao.disabled = true;
  const dados = new FormData();
  dados.append('historico_id', botao.dataset.historicoId || '');
  try {
    await lerJson(await fetch(`${API_BASE}excluir_historico.php`, {
      method: 'POST', body: dados, credentials: 'same-origin'
    }), 'Não foi possível apagar este histórico.');
    alert('Histórico apagado com sucesso.');
    window.location.href = 'principal.php';
  } catch (erro) {
    alert(erro.message || 'Não foi possível apagar este histórico.');
    botao.disabled = false;
  }
}

function escapar(texto) {
  return String(texto ?? '').replace(/[&<>"']/g, (caractere) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[caractere]);
}

function textoResumo(texto) {
  return String(texto || '')
    .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/`{1,3}([^`]+)`{1,3}/g, '$1')
    .replace(/\*\*(.*?)\*\*/g, '$1')
    .replace(/__(.*?)__/g, '$1')
    .replace(/^\s{0,3}#{1,6}\s+/gm, '')
    .trim();
}

function nomeSeguro(nome) {
  return String(nome || 'resumo-rpg.pdf').replace(/[\\/:*?"<>|]/g, '-');
}

function dataCurta(valor) {
  const data = new Date(valor);
  if (Number.isNaN(data.getTime())) return 'Data não disponível';
  return data.toLocaleDateString('pt-BR');
}

function atualizarProcessamento(mensagem, estado = '') {
  const painel = document.getElementById('processamento');
  const status = document.getElementById('processamento-status');
  if (!status) return;
  if (painel) painel.className = `p processamento${estado ? ` ${estado}` : ''}`;
  status.textContent = mensagem;
}

function limparPreviasIA() {
  const area = document.getElementById('previas-ia');
  if (area) area.innerHTML = '';
}

function adicionarPreviaIA(numeroPagina, progresso, resumo, erro = false) {
  const area = document.getElementById('previas-ia');
  if (!area) return;
  const bloco = document.createElement('article');
  bloco.className = `previa-ia${erro ? ' erro' : ''}`;
  const titulo = document.createElement('strong');
  titulo.textContent = `Página ${numeroPagina} · ${progresso}${erro ? ' · ignorada' : ' · resposta recebida'}`;
  const conteudo = document.createElement('div');
  conteudo.innerHTML = escapar(erro ? resumo : textoResumo(resumo)).replace(/\r?\n/g, '<br>');
  bloco.append(titulo, conteudo);
  area.appendChild(bloco);
  area.scrollTop = area.scrollHeight;
}

function aguardarRenderizacao() {
  return new Promise((resolver) => {
    requestAnimationFrame(() => requestAnimationFrame(resolver));
  });
}

function renderizarHistoricoCards(historicos) {
  const area = document.getElementById('historico-lista');
  if (!area) return;
  if (!historicos.length) {
    area.innerHTML = '<p>Nenhum tomo processado ainda...</p>';
    return;
  }
  area.innerHTML = historicos.map((item) => `
    <a class="historico-card" href="historico.html?historico_id=${encodeURIComponent(item.historico_id)}">
      <strong title="${escapar(item.name_file)}">${escapar(item.name_file)}</strong>
      <span>${escapar(item.numbers_page)} páginas · ${escapar(item.total_mensagens)} resumos</span>
      <small>${dataCurta(item.created_at)}</small>
    </a>
  `).join('');
}

async function carregarHistoricoCards() {
  const area = document.getElementById('historico-lista');
  if (!area) return;
  try {
    const dados = await lerJson(await fetch(`${API_BASE}historico.php`, {
      credentials: 'same-origin'
    }), 'Não foi possível carregar o histórico.');
    renderizarHistoricoCards(dados.historicos || []);
  } catch (erro) {
    area.innerHTML = `<p>${escapar(erro.message)}</p>`;
  }
}

function renderizarMensagens(mensagens) {
  if (!mensagens.length) return '<p>Nenhum resumo foi salvo para este tomo.</p>';
  return mensagens.map((mensagem) => `
    <article class="historico-mensagem">
      <div class="meta">Página ${escapar(mensagem.page_number)} · ${dataCurta(mensagem.created_at)}</div>
      <div class="conteudo">${escapar(textoResumo(mensagem.content)).replace(/\r?\n/g, '<br>')}</div>
    </article>
  `).join('');
}

async function carregarHistoricoCompleto() {
  const area = document.getElementById('historico-conteudo');
  if (!area) return;
  const historicoId = new URLSearchParams(window.location.search).get('historico_id');
  if (!historicoId || !/^\d+$/.test(historicoId)) {
    area.innerHTML = '<p>Histórico não informado.</p>';
    return;
  }
  try {
    const dados = await lerJson(await fetch(`${API_BASE}conversa.php?historico_id=${encodeURIComponent(historicoId)}`, {
      credentials: 'same-origin'
    }), 'Não foi possível carregar este histórico.');
    const historico = dados.historico || {};
    document.title = `Dunwich – ${historico.name_file || 'Histórico'}`;
    area.innerHTML = `
      <h2>${escapar(historico.name_file || 'Histórico do tomo')}</h2>
      <p>${escapar(historico.numbers_page || 0)} páginas · criado em ${dataCurta(historico.created_at)}</p>
      <div class="historico-acoes">
        <a class="historico-acao" data-download-resumo="true" data-nome-download="resumo-rpg.pdf" href="${API_BASE}download_resumo.php?historico_id=${encodeURIComponent(historicoId)}">Baixar resumo em PDF</a>
        <button class="historico-acao historico-acao-perigo" type="button" data-excluir-historico="true" data-historico-id="${encodeURIComponent(historicoId)}">Apagar histórico</button>
        <a class="historico-acao" href="${API_BASE}download.php?historico_id=${encodeURIComponent(historicoId)}">Baixar tomo original</a>
      </div>
      <h3>Resumos por página</h3>
      ${renderizarMensagens(dados.mensagens || [])}
    `;
    const linkResumo = area.querySelector('[data-download-resumo="true"]');
    if (linkResumo) linkResumo.addEventListener('click', baixarResumo);
    const botaoExcluir = area.querySelector('[data-excluir-historico="true"]');
    if (botaoExcluir) botaoExcluir.addEventListener('click', excluirHistorico);
  } catch (erro) {
    area.innerHTML = `<p>${escapar(erro.message)}</p>`;
  }
}

async function enviarCadastro(evento) {
  evento.preventDefault();
  const formulario = evento.currentTarget;
  const dados = new FormData(formulario);
  dados.append('type', 'create');
  dados.append('name', dados.get('usuario') || '');
  dados.append('email', dados.get('email') || '');
  dados.append('password', dados.get('senha') || '');
  dados.append('rpt_password', dados.get('senha') || '');
  const botao = formulario.querySelector('button[type="submit"]');
  if (botao) { botao.disabled = true; botao.textContent = 'Abrindo o tomo…'; }
  try {
    const resposta = await fetch(`${API_BASE}auth/cadastro.php`, { method: 'POST', body: dados, credentials: 'same-origin' });
    await lerJson(resposta, 'Não foi possível concluir o cadastro.');
    window.location.href = 'principal.php';
  } catch (erro) {
    alert(erro.message);
    if (botao) { botao.disabled = false; botao.textContent = 'Cadastrar'; }
  }
}

async function processarTomo(evento) {
  evento.preventDefault();
  const formulario = evento.currentTarget;
  const arquivo = formulario.querySelector('#arquivo')?.files?.[0];
  const botao = formulario.querySelector('button[type="submit"]');
  const modoTeste = formulario.querySelector('#modo-teste')?.checked ?? true;
  const limitePaginas = modoTeste ? LIMITE_PAGINAS_TESTE : Infinity;
  limparPreviasIA();
  if (!arquivo) { alert('Adicione um arquivo PDF antes de enviar o tomo.'); return; }
  if (!/\.pdf$/i.test(arquivo.name)) { alert('O arquivo selecionado precisa estar no formato PDF.'); return; }
  if (botao) { botao.disabled = true; botao.textContent = 'Processando…'; }
  atualizarProcessamento(`Preparando ${arquivo.name} para análise${modoTeste ? ` · modo de teste: até ${LIMITE_PAGINAS_TESTE} páginas` : ''}…`);
  try {
    const pdfForm = new FormData();
    pdfForm.append('rpg_pdf', arquivo);
    atualizarProcessamento('Lendo o PDF e identificando páginas úteis…');
    const dadosPdf = await lerJson(await fetch(`${API_BASE}quebra.php`, {
      method: 'POST', body: pdfForm, credentials: 'same-origin'
    }), 'Não foi possível ler o tomo enviado.');
    if (!dadosPdf.sucesso || !dadosPdf.paginas?.length) throw new Error(dadosPdf.mensagem || 'Nenhuma página útil foi encontrada.');

    const paginas = dadosPdf.paginas.slice(0, limitePaginas);
    atualizarProcessamento(`PDF lido: ${dadosPdf.paginas.length} páginas úteis encontradas; ${paginas.length} serão resumidas${modoTeste ? ' no modo de teste' : ''}.`);
    const historicoForm = new FormData();
    historicoForm.append('name_file', arquivo.name);
    historicoForm.append('numbers_page', dadosPdf.total_paginas || dadosPdf.paginas.length);
    historicoForm.append('stored_file_name', dadosPdf.arquivo_salvo?.nome || '');
    historicoForm.append('stored_file_path', dadosPdf.arquivo_salvo?.caminho || '');
    const historico = await lerJson(await fetch(`${API_BASE}criar_historico.php`, {
      method: 'POST', body: historicoForm, credentials: 'same-origin'
    }), 'Não foi possível registrar o tomo.');

    const conversaId = window.crypto && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const blocos = [];
    let blocosIgnorados = 0;
    for (let indice = 0; indice < paginas.length; indice += 1) {
      const pagina = paginas[indice];
      const progresso = `${indice + 1}/${paginas.length}`;
      atualizarProcessamento(`Resumindo página ${pagina.numero} (${progresso})…`);
      await aguardarRenderizacao();
      try {
        const iaForm = new FormData();
        iaForm.append('texto_pagina', pagina.texto);
        iaForm.append('numero_pagina', pagina.numero);
        iaForm.append('modo_teste', modoTeste ? '1' : '0');
        iaForm.append('conversa_id', conversaId);
        iaForm.append('historico_id', historico.historico_id);
        iaForm.append('documento', arquivo.name);
        const respostaIa = await fetch(`${API_BASE}cenection_ia.php`, {
          method: 'POST', body: iaForm, credentials: 'same-origin'
        });
        const texto = (await respostaIa.text()).trim();
        if (!respostaIa.ok || !texto || /^\[Erro/i.test(texto)) {
          throw new Error(texto || 'A IA não retornou uma resposta válida.');
        }
        blocos.push({ numero: pagina.numero, resumo: texto });
        adicionarPreviaIA(pagina.numero, progresso, texto);
        atualizarProcessamento(`Resposta da página ${pagina.numero} recebida. ${indice < paginas.length - 1 ? 'Continuando…' : 'Preparando o PDF final…'}`);
        await aguardarRenderizacao();
      } catch (erroPagina) {
        blocosIgnorados += 1;
        adicionarPreviaIA(pagina.numero, progresso, erroPagina.message || 'Falha ao resumir esta página.', true);
        atualizarProcessamento(`Página ${pagina.numero} ignorada. ${indice < paginas.length - 1 ? 'Continuando…' : 'Preparando com as respostas válidas…'}`);
        await aguardarRenderizacao();
      }
    }
    if (!blocos.length) throw new Error('A IA não retornou nenhum resumo válido.');

    atualizarProcessamento(`Compilando ${blocos.length} resposta(s) em um único PDF${blocosIgnorados ? ` · ${blocosIgnorados} página(s) ignorada(s)` : ''}…`);
    const respostaPdf = await fetch(`${API_BASE}gerar_pdf.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ titulo: arquivo.name, paginas: blocos, historico_id: historico.historico_id }), credentials: 'same-origin'
    });
    if (!respostaPdf.ok) {
      const corpoErro = await respostaPdf.text();
      let mensagemErro = 'Não foi possível gerar o PDF de resumo.';
      try {
        const dadosErro = JSON.parse(corpoErro);
        mensagemErro = dadosErro.erro || dadosErro.message || mensagemErro;
      } catch (erroLeitura) {
        if (corpoErro) mensagemErro = corpoErro.replace(/<[^>]*>/g, ' ').trim() || mensagemErro;
      }
      throw new Error(mensagemErro);
    }
    const tipoPdf = respostaPdf.headers.get('content-type') || '';
    if (!tipoPdf.includes('application/pdf')) {
      throw new Error('O servidor não retornou um PDF válido para o resumo.');
    }
    const pdfBlob = await respostaPdf.blob();
    let pdfDataUrl = '';
    if (pdfBlob.size <= 1500000) {
      pdfDataUrl = await new Promise((resolve) => {
        const leitor = new FileReader();
        leitor.onload = () => resolve(leitor.result);
        leitor.onerror = () => resolve('');
        leitor.readAsDataURL(pdfBlob);
      });
    }
    sessionStorage.setItem('dunwichResumo', JSON.stringify({
      nome: arquivo.name, blocos, pdfDataUrl,
      historicoId: Number(historico.historico_id) || 0,
      pdfNome: `${nomeSeguro(arquivo.name.replace(/\.pdf$/i, ''))}-resumo.pdf`,
      modoTeste, paginasProcessadas: paginas.length, paginasIgnoradas: blocosIgnorados
    }));
    atualizarProcessamento(`Resumo concluído: ${blocos.length} página(s) processada(s). Abrindo o resultado…`, 'concluido');
    window.location.href = 'resumo.html';
  } catch (erro) {
    atualizarProcessamento(erro.message || 'Não foi possível concluir o processamento.', 'erro');
    alert(erro.message);
    if (botao) { botao.disabled = false; botao.textContent = 'Enviar tomo'; }
  }
}

function renderizarResumo() {
  const area = document.querySelector('.resumo');
  if (!area) return;
  const bruto = sessionStorage.getItem('dunwichResumo');
  if (!bruto) { area.innerHTML = '<p>Envie um tomo pela página principal para gerar o resumo.</p>'; return; }
  try {
    const dados = JSON.parse(bruto);
    const fragmento = dados.blocos.map((bloco) => `<p><strong>Página ${escapar(bloco.numero)}</strong></p><p>${escapar(textoResumo(bloco.resumo)).replace(/\n/g, '<br>')}</p>`).join('');
    area.innerHTML = fragmento || '<p>O resumo está vazio.</p>';
    const seta = document.querySelector('#baixar-resumo, a[aria-label="Baixar resumo em PDF"]');
    if (seta && dados.historicoId) {
      seta.href = `${API_BASE}download_resumo.php?historico_id=${encodeURIComponent(dados.historicoId)}`;
      seta.removeAttribute('download');
      seta.dataset.nomeDownload = dados.pdfNome || 'resumo-rpg.pdf';
      seta.title = 'Baixar o resumo em PDF';
      seta.onclick = baixarResumo;
    } else if (seta && dados.pdfDataUrl) {
      seta.href = dados.pdfDataUrl;
      seta.download = dados.pdfNome || 'resumo-rpg.pdf';
      seta.title = 'Baixar o resumo em PDF';
    } else if (seta) {
      seta.removeAttribute('href');
      seta.title = 'Resumo ainda não disponível para download';
    }
    const introducao = document.querySelector('.palco > p');
    if (introducao && dados.nome) introducao.textContent = `Aqui está um resumo completo do tomo ${dados.nome} que você me enviou, folheie as páginas e descubra o essencial!`;
  } catch (erro) {
    area.innerHTML = '<p>Não foi possível carregar o resumo deste tomo.</p>';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const cadastro = document.querySelector('form[action="principal.html"]');
  const arquivo = document.getElementById('arquivo');
  const nomeTomo = document.getElementById('tomo-name');
  const envio = arquivo?.closest('form') || document.querySelector('form[action="resumo.html"]');
  if (cadastro) { cadastro.addEventListener('submit', enviarCadastro); cadastro.action = '#'; cadastro.method = 'post'; }
  if (envio) { envio.addEventListener('submit', processarTomo); envio.action = '#'; envio.method = 'post'; envio.enctype = 'multipart/form-data'; }
  const botaoLogout = document.getElementById('logout');
  if (botaoLogout) botaoLogout.addEventListener('click', fazerLogout);
  const botaoExcluirConta = document.getElementById('excluir-conta');
  if (botaoExcluirConta) botaoExcluirConta.addEventListener('click', excluirConta);
  const formularioUsuario = document.getElementById('form-usuario');
  if (formularioUsuario) formularioUsuario.addEventListener('submit', atualizarUsuario);
  if (arquivo && nomeTomo) {
    arquivo.addEventListener('change', () => {
      nomeTomo.textContent = arquivo.files?.[0]?.name || 'Adicionar um novo tomo';
    });
  }
  carregarHistoricoCards();
  carregarHistoricoCompleto();
  renderizarResumo();
});
