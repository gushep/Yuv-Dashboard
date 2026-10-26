/* Botão Novo Ticket (Tarefas e Painel do Bombeiro): abre ticket de suporte no Monday, sempre em Backlog e na sprint atual.
 * Imagens coladas ou arrastadas na descrição vão como anexos do comentário inicial do ticket, como o Monday faz no corpo do texto. */
window.NewTicket = (() => {
  const state = {options:null, loading:false, saving:false, preparing:false, created:null, error:'', files:[], failed:[]};
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const PRIORITY_CLASS = {'Crítica':'critical', 'Alta':'high', 'Média':'medium', 'Baixa':'low'};
  const MAX_FILES = 10;
  const TEXT_FIELDS = ['title', 'client', 'description'];
  /** Modelo de abertura de chamado do time: a descrição já vem com ele, só para ajustar. */
  const DESCRIPTION_TEMPLATE = `🐞 PROBLEMA
[Descreva a falha de forma objetiva.]

📌 CONTEXTO E IMPACTO
[Cliente/unidade afetada, funcionalidade e impacto na operação.]
[Ocorre sempre ou de forma intermitente? Afeta um ou vários usuários/equipamentos?]

🖥️ AMBIENTE
[Produção/homologação, web/app/API e versão, quando relevante.]
[Perfil de acesso, equipamento/IMEI e data/hora da ocorrência, quando necessário.]

🔄 PASSOS PARA REPRODUZIR
[Acessar...]
[Selecionar/informar...]
[Executar...]

❌ RESULTADO ATUAL
[O que acontece hoje, incluindo mensagem de erro.]

✅ RESULTADO ESPERADO
[O comportamento correto e a regra que o sustenta.]

📎 EVIDÊNCIAS
[Prints, vídeos, logs e exemplos.]
[Se não foi possível reproduzir, registrar isso e incluir as evidências disponíveis.]

🔗 DEPENDÊNCIAS / INFORMAÇÕES PENDENTES
[O que falta para avançar, quem fornecerá e outros cards relacionados.]

🧪 CRITÉRIOS DE ACEITE
☐ O cenário reportado apresenta o resultado esperado.
☐ Os cenários relacionados continuam funcionando.
☐ A correção foi validada no ambiente e perfil afetados.
☐ [Validação específica da demanda.]

📝 ANÁLISE TÉCNICA — PREENCHER DURANTE A INVESTIGAÇÃO
[Causa identificada, correção aplicada e possíveis impactos.]

🚀 VALIDAÇÃO E ENTREGA
[Evidência do teste, responsável pela validação e situação do deploy, quando aplicável.]`;
  /** A descrição só conta como preenchida quando o modelo foi ajustado. */
  function descriptionChanged(text) { return text.trim() !== '' && text.trim() !== DESCRIPTION_TEMPLATE; }
  let dialog = null;

  function form() { return dialog.querySelector('form'); }
  /** Campo do formulário pelo nome; form.title devolveria o atributo title do próprio formulário. */
  function field(name) { return form()?.elements.namedItem(name); }
  function uploadLimit() { return state.options?.maxUploadBytes || Attachments.DEFAULT_LIMIT; }
  function hasDraft() {
    return Boolean(dialog && form() && !state.created && (state.files.length || field('title').value.trim() || field('client').value.trim()
      || descriptionChanged(field('description').value)));
  }

  function renderFiles() {
    return state.files.map((entry, index) => `<div class="nt-file ${entry.file.size > uploadLimit() ? 'too-big' : ''}" title="${escape(entry.file.name)} · ${Attachments.fileSize(entry.file.size)}">
        ${entry.preview ? `<img src="${entry.preview}" alt="${escape(entry.file.name)}">` : `<span class="nt-file-icon">📎</span><span class="nt-file-name">${escape(entry.file.name)}</span>`}
        <button type="button" data-remove-file="${index}" aria-label="Remover ${escape(entry.file.name)}">✕</button>
      </div>`).join('');
  }

  function renderSuccess() {
    const created = state.created;
    const sent = created.attached;
    return `<div class="nt-head"><div class="nt-title">Ticket criado</div><button type="button" class="kpi-modal-close" aria-label="Fechar" data-close>✕</button></div>
      <div class="nt-success">
        <div class="nt-success-icon" aria-hidden="true">✓</div>
        <p><strong>#${escape(created.id)}</strong> foi aberto no Monday na <strong>Sprint ${escape(created.sprint.number)}</strong>, com tipo Suporte e status Backlog${sent ? ` e ${sent} imagem(ns)/anexo(s) na descrição` : ''}.</p>
        ${state.failed.length ? `<p class="nt-warning">Não foi possível anexar: ${state.failed.map(entry => `${escape(entry.file.name)} (${escape(entry.error)})`).join('; ')}.</p>` : ''}
        <div class="nt-actions">
          ${state.failed.length && created.commentId ? `<button type="button" class="nt-secondary" data-retry ${state.saving ? 'disabled' : ''}>${state.saving ? 'Enviando...' : 'Tentar anexar de novo'}</button>` : ''}
          ${created.url ? `<a class="nt-secondary" href="${escape(created.url)}" target="_blank" rel="noopener">Abrir no Monday ↗</a>` : ''}
          ${window.ItemDetail && canAccessScreen('raw') ? '<button type="button" class="nt-secondary" data-details>Ver detalhes</button>' : ''}
          <button type="button" class="nt-primary" data-another>Abrir outro ticket</button>
        </div>
      </div>`;
  }

  function render() {
    if (state.created) { dialog.innerHTML = renderSuccess(); return; }
    const options = state.options;
    const sprint = options ? `Sprint ${options.sprint.number}` : state.loading ? 'Sprint atual…' : 'Sprint atual';
    dialog.innerHTML = `<div class="nt-head"><div class="nt-title">Novo Ticket</div><button type="button" class="kpi-modal-close" aria-label="Fechar" data-close>✕</button></div>
      <form class="nt-form" novalidate>
        <div class="nt-fixed" aria-label="Valores definidos automaticamente">
          <span class="nt-chip">Tipo: <b>Suporte</b></span><span class="nt-chip">Status: <b>Backlog</b></span><span class="nt-chip">${escape(sprint)}${options ? ' <b>(atual)</b>' : ''}</span>
        </div>
        <label class="nt-field">Título <span class="nt-req">*</span>
          <input name="title" maxlength="255" required autocomplete="off" placeholder="Resumo do problema ou da solicitação">
        </label>
        <label class="nt-field">Cliente <span class="nt-req">*</span>
          <input name="client" maxlength="255" required autocomplete="off" placeholder="Nome do cliente afetado">
        </label>
        <div class="nt-field">
          <div class="nt-label-row"><label for="nt-description">Descrição <span class="nt-req">*</span></label>
            <span class="nt-hint">Ajuste o modelo: troque os textos entre [colchetes] e apague o que não se aplica.</span>
            <button type="button" class="nt-link" data-template>Restaurar modelo</button></div>
          <div class="nt-editor" data-drop>
            <textarea id="nt-description" name="description" rows="14" maxlength="10000" required placeholder="Descreva o problema. Cole prints aqui com Ctrl+V.">${escape(DESCRIPTION_TEMPLATE)}</textarea>
            <div class="nt-files" data-files>${renderFiles()}</div>
            <label class="nt-attach">
              <input type="file" multiple hidden data-file-input>
              <span>🖼️ Cole (Ctrl+V), arraste ou <u>clique para adicionar imagens e arquivos</u></span>
              <small>Até ${MAX_FILES} · ${Attachments.fileSize(uploadLimit())} cada · imagens grandes são reduzidas</small>
            </label>
          </div>
        </div>
        <fieldset class="nt-field nt-priorities"><legend>Prioridade <span class="nt-req">*</span></legend>
          <div class="nt-priority-list">${options ? options.priorities.map(priority => `<label class="nt-priority ${PRIORITY_CLASS[priority] || 'neutral'}">
              <input type="radio" name="priority" value="${escape(priority)}" required><span>${escape(priority)}</span></label>`).join('')
            : `<span class="nt-muted">${state.loading ? 'Carregando prioridades do Monday...' : 'Prioridades indisponíveis.'}</span>`}</div>
        </fieldset>
        <div class="nt-foot">
          <span class="nt-message ${state.error ? 'error' : ''}" aria-live="polite">${escape(state.error)}</span>
          <button type="button" class="nt-secondary" data-close>Cancelar</button>
          <button type="submit" class="nt-primary" ${!options || state.saving ? 'disabled' : ''}>${state.saving ? 'Criando...' : 'Criar ticket'}</button>
        </div>
      </form>`;
  }

  /** Redesenha o formulário mantendo o que já foi digitado e a prioridade escolhida. */
  function rerender() {
    const current = form();
    const draft = current ? Object.fromEntries(TEXT_FIELDS.map(name => [name, field(name).value])) : null;
    const priority = current?.querySelector('[name=priority]:checked')?.value;
    render();
    if (!draft || state.created) return;
    Object.entries(draft).forEach(([name, value]) => { field(name).value = value; });
    form().querySelectorAll('[name=priority]').forEach(input => { input.checked = input.value === priority; });
  }

  function message(text, kind = 'error') {
    state.error = kind === 'error' ? text : '';
    const target = dialog.querySelector('.nt-message');
    if (target) { target.textContent = text; target.className = `nt-message ${kind}`; }
  }

  function updateFiles() {
    const list = dialog.querySelector('[data-files]');
    if (list) list.innerHTML = renderFiles();
    const submitButton = dialog.querySelector('form .nt-primary');
    if (submitButton) submitButton.disabled = !state.options || state.saving || state.preparing;
  }

  async function addFiles(list) {
    if (!list.length || state.saving) return;
    state.preparing = true;
    message('Preparando imagens...', 'info');
    updateFiles();
    const prepared = await Attachments.prepare(list, uploadLimit());
    state.preparing = false;
    const added = prepared.map(file => ({file, preview:file.type.startsWith('image/') ? URL.createObjectURL(file) : null}));
    state.files = [...state.files, ...added];
    state.files.splice(MAX_FILES).forEach(entry => entry.preview && URL.revokeObjectURL(entry.preview));
    const tooBig = state.files.map(entry => entry.file).filter(file => file.size > uploadLimit());
    message(tooBig.length ? Attachments.tooBigMessage(tooBig, uploadLimit()) : '', tooBig.length ? 'error' : 'info');
    updateFiles();
  }

  function clearFiles() {
    state.files.forEach(entry => entry.preview && URL.revokeObjectURL(entry.preview));
    state.files = [];
  }

  /** Envia os anexos ao comentário inicial do ticket, um por requisição; os que falharem ficam para nova tentativa. */
  async function uploadFiles(entries, onProgress) {
    const failed = [];
    for (const [index, entry] of entries.entries()) {
      onProgress?.(index + 1, entries.length);
      try { await Attachments.upload(`/api/tickets/${encodeURIComponent(state.created.id)}/comments/${encodeURIComponent(state.created.commentId)}/files`, entry.file); state.created.attached++; }
      catch (error) { failed.push({...entry, error:error.message}); }
    }
    return failed;
  }

  async function submit() {
    if (state.saving || state.preparing) return;
    const current = form();
    const ticket = {title:field('title').value.trim(), client:field('client').value.trim(), description:field('description').value.trim(),
      priority:current.querySelector('[name=priority]:checked')?.value || ''};
    const missing = [['title', 'título'], ['client', 'cliente'], ['description', 'descrição (ajuste o modelo)'], ['priority', 'prioridade']]
      .filter(([name]) => name === 'description' ? !descriptionChanged(ticket.description) : !ticket[name]);
    if (missing.length) {
      message(`Preencha: ${missing.map(([, label]) => label).join(', ')}.`);
      (missing[0][0] === 'priority' ? current.querySelector('[name=priority]') : field(missing[0][0]))?.focus();
      return;
    }
    const tooBig = state.files.map(entry => entry.file).filter(file => file.size > uploadLimit());
    if (tooBig.length) { message(Attachments.tooBigMessage(tooBig, uploadLimit())); return; }
    state.saving = true;
    current.querySelector('.nt-primary').disabled = true;
    current.querySelector('.nt-primary').textContent = 'Criando...';
    message('Criando o ticket no Monday...', 'info');
    try {
      const created = await mondayRequest('/api/tickets', ticket);
      state.created = {...created, attached:0};
      const files = state.files;
      state.failed = [];
      if (files.length && created.commentId) {
        state.failed = await uploadFiles(files, (index, total) => message(`Ticket criado. Enviando anexo ${index} de ${total}...`, 'info'));
      } else if (files.length) {
        state.failed = files.map(entry => ({...entry, error:'o comentário inicial não foi criado'}));
      }
      state.saving = false;
      clearFiles();
      render();
      // O cache foi limpo no servidor; as telas abertas buscam o card novo.
      if (currentPage === 'bombeiro') window.Bombeiro?.refresh(true);
      else if (typeof syncMonday === 'function') syncMonday(true);
    } catch (error) {
      message(error.message);
      current.querySelector('.nt-primary').disabled = false;
      current.querySelector('.nt-primary').textContent = 'Criar ticket';
    } finally {
      state.saving = false;
    }
  }

  async function retryFailed() {
    if (state.saving) return;
    state.saving = true;
    render();
    state.failed = await uploadFiles(state.failed);
    state.saving = false;
    render();
  }

  function close() {
    if (state.saving) return;
    if (hasDraft() && !confirm('Descartar o ticket que ainda não foi criado?')) return;
    clearFiles();
    dialog.close();
  }

  function open() {
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.className = 'kpi-modal nt-modal';
      dialog.setAttribute('aria-label', 'Novo Ticket');
      document.body.appendChild(dialog);
      dialog.addEventListener('click', event => {
        if (event.target === dialog || event.target.closest('[data-close]')) { close(); return; }
        const remove = event.target.closest('[data-remove-file]');
        if (remove && !state.saving) {
          const [entry] = state.files.splice(Number(remove.dataset.removeFile), 1);
          if (entry?.preview) URL.revokeObjectURL(entry.preview);
          message('', 'info');
          updateFiles();
          return;
        }
        if (event.target.closest('[data-retry]')) { retryFailed(); return; }
        if (event.target.closest('[data-template]')) {
          const description = field('description');
          if (!descriptionChanged(description.value) || confirm('Substituir a descrição atual pelo modelo em branco?')) { description.value = DESCRIPTION_TEMPLATE; description.focus(); }
          return;
        }
        if (event.target.closest('[data-another]')) { Object.assign(state, {created:null, error:'', failed:[]}); render(); field('title').focus(); return; }
        if (event.target.closest('[data-details]')) { const id = state.created.id; dialog.close(); window.ItemDetail.open(id); }
      });
      dialog.addEventListener('submit', event => { event.preventDefault(); submit(); });
      dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
      dialog.addEventListener('change', event => { if (event.target.matches('[data-file-input]')) { addFiles([...event.target.files]); event.target.value = ''; } });
      // Prints colados na descrição viram anexos; texto colado segue normal.
      dialog.addEventListener('paste', event => {
        if (!event.target.closest('form')) return;
        const files = [...(event.clipboardData?.files || [])];
        if (files.length) { event.preventDefault(); addFiles(files); }
      });
      dialog.addEventListener('dragover', event => {
        const drop = event.target.closest('[data-drop]');
        if (!drop) return;
        event.preventDefault();
        drop.classList.add('over');
      });
      dialog.addEventListener('dragleave', event => {
        const drop = event.target.closest('[data-drop]');
        if (drop && !drop.contains(event.relatedTarget)) drop.classList.remove('over');
      });
      dialog.addEventListener('drop', event => {
        const drop = event.target.closest('[data-drop]');
        if (!drop) return;
        event.preventDefault();
        drop.classList.remove('over');
        addFiles([...event.dataTransfer.files]);
      });
    }
    clearFiles();
    Object.assign(state, {created:null, error:'', saving:false, preparing:false, failed:[]});
    render();
    dialog.showModal();
    field('title').focus();
    if (!state.options) loadOptions();
  }

  async function loadOptions() {
    state.loading = true;
    state.error = '';
    try {
      state.options = await mondayRequest('/api/tickets/options');
    } catch (error) {
      state.error = error.message;
    } finally {
      state.loading = false;
    }
    if (dialog.open && !state.created) rerender();
  }

  return {open};
})();
