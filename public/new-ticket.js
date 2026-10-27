/* Botão Novo Ticket (Tarefas e Painel do Bombeiro): primeiro a pessoa escolhe o tipo de demanda, com critérios e exemplos.
 * Sustentação abre ticket de suporte no Monday, sempre em Backlog e na sprint atual; imagens coladas ou arrastadas na descrição
 * vão como anexos do comentário inicial do ticket, como o Monday faz no corpo do texto.
 * Melhoria / Feature abre demanda no Funil de Demandas com os campos do formulário de demandas; os arquivos vão para a coluna Evidências. */
window.NewTicket = (() => {
  const state = {kind:null, options:null, loading:false, featureOptions:null, featureLoading:false, saving:false, preparing:false, created:null, error:'', files:[], failed:[]};
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const PRIORITY_CLASS = {'Crítica':'critical', 'Crítico':'critical', 'Alta':'high', 'Média':'medium', 'Baixa':'low'};
  const MAX_FILES = 10;
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
  /** Critérios de escolha do tipo de demanda, mostrados na primeira etapa do modal. */
  const KINDS = [
    {kind:'support', icon:'🧯', name:'Sustentação', sub:'Suporte · Bombeiro',
      text:'Algo que já existe falhou ou o cliente precisa de ajuda. Entra no Backlog da sprint atual com tipo Suporte e aparece no Painel do Bombeiro.',
      yes:['Erro, falha ou lentidão em produção', 'Relatório, tela ou integração que parou ou mostra dado errado', 'Equipamento sem comunicar ou com leitura incorreta', 'Dúvida de uso, configuração, cadastro ou ajuste de dados do cliente'],
      no:['Tela, relatório, filtro ou campo que ainda não existe', 'Mudar uma regra que hoje funciona como foi combinado']},
    {kind:'feature', icon:'✨', name:'Melhoria / Feature', sub:'Funil de Demandas',
      text:'Algo novo ou diferente do que existe hoje. Vai para o Funil de Demandas e é avaliado e priorizado pelo time de produto antes de entrar em uma sprint.',
      yes:['Nova tela, relatório, filtro ou campo', 'Homologação de equipamento ou integração nova', 'Mudar um comportamento que funciona como foi especificado', 'Ganho de usabilidade ou desempenho pedido pelo cliente'],
      no:['Algo que deveria funcionar e não funciona: é Sustentação']},
  ];
  /** Orientação de prioridade do ticket de sustentação. */
  const PRIORITY_GUIDE = {
    'Crítica':'operação do cliente parada ou vários clientes afetados, sem alternativa',
    'Alta':'funcionalidade importante falhando, com contorno difícil',
    'Média':'falha com contorno possível ou impacto localizado',
    'Baixa':'ajuste visual, dúvida ou baixo impacto',
  };
  /** Orientação de severidade da demanda de melhoria. */
  const SEVERITY_GUIDE = {
    'Crítico':'bloqueia venda, operação de cliente ou obrigação legal/contratual',
    'Alta':'impacto relevante em clientes ou receita, sem alternativa razoável',
    'Média':'ganho importante, mas existe alternativa hoje',
    'Baixa':'conveniência ou ajuste de baixo impacto',
  };
  /** Campos de escolha da demanda de melhoria: nome do campo, rótulo e orientação, na ordem do formulário de demandas. */
  const FEATURE_SELECTS = [
    ['origin', 'Origem', 'De onde vem essa demanda?'],
    ['type', 'Tipo', 'Qual o tipo da demanda? Bug é Sustentação.'],
    ['module', 'Módulo', 'Qual módulo da plataforma?'],
    ['motivation', 'Motivação da demanda para o negócio', 'Qual a motivação da demanda para a Yuv?'],
    ['mrr', 'Possível MRR', 'Existe receita dependendo desta demanda?'],
  ];
  const FEATURE_TEXTS = {
    description:['Descrição da demanda', 'Qual é o problema ou a necessidade? Quanto mais claro o problema, melhor a priorização e a solução.'],
    solution:['Solução proposta', 'Você já imagina uma solução? Como resolver?'],
    requirements:['Requisitos', 'Quais são os requisitos solicitados? Se não houver, escreva “Sem requisitos específicos”.'],
  };
  /** A descrição só conta como preenchida quando o modelo foi ajustado. */
  function descriptionChanged(text) { return text.trim() !== '' && text.trim() !== DESCRIPTION_TEMPLATE; }
  let dialog = null;

  function form() { return dialog.querySelector('form'); }
  /** Campo do formulário pelo nome; form.title devolveria o atributo title do próprio formulário. */
  function field(name) { return form()?.elements.namedItem(name); }
  function kindOptions() { return state.kind === 'feature' ? state.featureOptions : state.options; }
  function uploadLimit() { return kindOptions()?.maxUploadBytes || Attachments.DEFAULT_LIMIT; }
  /** Há rascunho quando algum campo mudou em relação ao que o formulário abriu, ou há arquivos. */
  function hasDraft() {
    if (!dialog || !form() || state.created) return false;
    return Boolean(state.files.length) || [...form().elements].some(element => element.name && (['checkbox', 'radio'].includes(element.type)
      ? element.checked !== element.defaultChecked
      : element.tagName === 'SELECT' ? element.selectedIndex > 0 : element.value.trim() !== element.defaultValue.trim()));
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
    const feature = created.kind === 'feature';
    const summary = feature
      ? `<strong>#${escape(created.id)}</strong> foi aberta no <strong>${escape(created.boardName || 'Funil de Demandas')}</strong> com status Aguardando revisão${sent ? ` e ${sent} evidência(s)` : ''}. O time de produto avalia e prioriza junto às demais demandas.`
      : `<strong>#${escape(created.id)}</strong> foi aberto no Monday na <strong>Sprint ${escape(created.sprint.number)}</strong>, com tipo Suporte e status Backlog${sent ? ` e ${sent} imagem(ns)/anexo(s) na descrição` : ''}.`;
    const canRetry = state.failed.length && (feature || created.commentId);
    return `<div class="nt-head"><div class="nt-title">${feature ? 'Demanda criada' : 'Ticket criado'}</div><button type="button" class="kpi-modal-close" aria-label="Fechar" data-close>✕</button></div>
      <div class="nt-success">
        <div class="nt-success-icon" aria-hidden="true">✓</div>
        <p>${summary}</p>
        ${state.failed.length ? `<p class="nt-warning">Não foi possível anexar: ${state.failed.map(entry => `${escape(entry.file.name)} (${escape(entry.error)})`).join('; ')}.</p>` : ''}
        <div class="nt-actions">
          ${canRetry ? `<button type="button" class="nt-secondary" data-retry ${state.saving ? 'disabled' : ''}>${state.saving ? 'Enviando...' : 'Tentar anexar de novo'}</button>` : ''}
          ${created.url ? `<a class="nt-secondary" href="${escape(created.url)}" target="_blank" rel="noopener">Abrir no Monday ↗</a>` : ''}
          ${window.ItemDetail && canAccessScreen('raw') ? '<button type="button" class="nt-secondary" data-details>Ver detalhes</button>' : ''}
          <button type="button" class="nt-primary" data-another>${feature ? 'Abrir outra demanda' : 'Abrir outro ticket'}</button>
        </div>
      </div>`;
  }

  function renderList(items) { return `<ul>${items.map(item => `<li>${escape(item)}</li>`).join('')}</ul>`; }

  /** Primeira etapa: escolha do tipo de demanda, com quando usar e quando não usar cada um. */
  function renderChooser() {
    return `<div class="nt-head"><div class="nt-title">Novo Ticket</div><button type="button" class="kpi-modal-close" aria-label="Fechar" data-close>✕</button></div>
      <div class="nt-choose">
        <p class="nt-choose-intro"><b>Que tipo de demanda você vai abrir?</b> Na dúvida, pergunte: isso já funcionava, ou deveria funcionar, e não funciona? Então é <b>Sustentação</b>. Ainda não existe ou precisa funcionar diferente do combinado? Então é <b>Melhoria / Feature</b>.</p>
        <div class="nt-kinds">${KINDS.map(kind => `<div class="nt-kind ${kind.kind}" data-kind="${kind.kind}">
            <div class="nt-kind-head"><span class="nt-kind-icon" aria-hidden="true">${kind.icon}</span><div><div class="nt-kind-name">${escape(kind.name)}</div><div class="nt-kind-sub">${escape(kind.sub)}</div></div></div>
            <p class="nt-kind-text">${escape(kind.text)}</p>
            <div class="nt-kind-list yes"><b>Use para</b>${renderList(kind.yes)}</div>
            <div class="nt-kind-list no"><b>Não use para</b>${renderList(kind.no)}</div>
            <button type="button" class="nt-primary" data-kind="${kind.kind}">Abrir ${escape(kind.name.toLowerCase())}</button>
          </div>`).join('')}</div>
      </div>`;
  }

  function renderFoot(label) {
    return `<div class="nt-foot">
          <span class="nt-message ${state.error ? 'error' : ''}" aria-live="polite">${escape(state.error)}</span>
          <button type="button" class="nt-secondary" data-close>Cancelar</button>
          <button type="submit" class="nt-primary" ${!kindOptions() || state.saving ? 'disabled' : ''}>${state.saving ? 'Criando...' : label}</button>
        </div>`;
  }

  /** Opções em botões (prioridade e severidade), com a orientação de cada uma abaixo. */
  function renderLevels(name, levels, guide, loading) {
    if (!levels) return `<div class="nt-priority-list"><span class="nt-muted">${loading ? 'Carregando opções do Monday...' : 'Opções indisponíveis.'}</span></div>`;
    const rank = level => Object.keys(guide).includes(level) ? Object.keys(guide).indexOf(level) : Infinity;
    levels = [...levels].sort((a, b) => rank(a) - rank(b));
    return `<div class="nt-priority-list">${levels.map(level => `<label class="nt-priority ${PRIORITY_CLASS[level] || 'neutral'}">
        <input type="radio" name="${name}" value="${escape(level)}" required><span>${escape(level)}</span></label>`).join('')}</div>
      <dl class="nt-prio-guide">${levels.filter(level => guide[level]).map(level => `<dt>${escape(level)}</dt><dd>${escape(guide[level])}</dd>`).join('')}</dl>`;
  }

  function renderAttach(text) {
    return `<div class="nt-files" data-files>${renderFiles()}</div>
            <label class="nt-attach">
              <input type="file" multiple hidden data-file-input>
              <span>${text}</span>
              <small>Até ${MAX_FILES} · ${Attachments.fileSize(uploadLimit())} cada · imagens grandes são reduzidas</small>
            </label>`;
  }

  function renderSupportForm() {
    const options = state.options;
    const sprint = options ? `Sprint ${options.sprint.number}` : state.loading ? 'Sprint atual…' : 'Sprint atual';
    return `<div class="nt-head"><div class="nt-title">Novo Ticket · Sustentação</div><button type="button" class="kpi-modal-close" aria-label="Fechar" data-close>✕</button></div>
      <form class="nt-form" novalidate>
        <div class="nt-fixed" aria-label="Valores definidos automaticamente">
          <span class="nt-chip">Tipo: <b>Suporte</b></span><span class="nt-chip">Status: <b>Backlog</b></span><span class="nt-chip">${escape(sprint)}${options ? ' <b>(atual)</b>' : ''}</span>
          <button type="button" class="nt-link nt-change-kind" data-change-kind>← Trocar tipo</button>
        </div>
        <label class="nt-field"><span>Título <span class="nt-req">*</span></span>
          <span class="nt-hint">O que falha e onde, em poucas palavras. Ex.: “Relatório de jornada não exporta PDF”.</span>
          <input name="title" maxlength="255" required autocomplete="off" placeholder="Resumo do problema ou da solicitação">
        </label>
        <label class="nt-field"><span>Cliente <span class="nt-req">*</span></span>
          <span class="nt-hint">Nome comercial oficial, sempre escrito do mesmo jeito. Se afeta vários clientes, informe o principal ou “Base toda”.</span>
          <input name="client" maxlength="255" required autocomplete="off" placeholder="Nome do cliente afetado">
        </label>
        <div class="nt-field">
          <div class="nt-label-row"><label for="nt-description">Descrição <span class="nt-req">*</span></label>
            <span class="nt-hint">Ajuste o modelo: troque os textos entre [colchetes] e apague o que não se aplica.</span>
            <button type="button" class="nt-link" data-template>Restaurar modelo</button></div>
          <div class="nt-editor" data-drop>
            <textarea id="nt-description" name="description" rows="14" maxlength="10000" required placeholder="Descreva o problema. Cole prints aqui com Ctrl+V.">${escape(DESCRIPTION_TEMPLATE)}</textarea>
            ${renderAttach('🖼️ Cole (Ctrl+V), arraste ou <u>clique para adicionar imagens e arquivos</u>')}
          </div>
        </div>
        <fieldset class="nt-field nt-priorities"><legend>Prioridade <span class="nt-req">*</span></legend>
          ${renderLevels('priority', options?.priorities, PRIORITY_GUIDE, state.loading)}
        </fieldset>
        ${renderFoot('Criar ticket')}
      </form>`;
  }

  function renderFeatureForm() {
    const options = state.featureOptions;
    const required = '<span class="nt-req">*</span>';
    const select = ([name, label, hint]) => `<label class="nt-field"><span>${escape(label)} ${required}</span><span class="nt-hint">${escape(hint)}</span>
        <select name="${name}" required ${options ? '' : 'disabled'}><option value="">${options ? 'Selecione' : state.featureLoading ? 'Carregando...' : 'Indisponível'}</option>
          ${(options?.[name] || []).map(option => `<option>${escape(option)}</option>`).join('')}</select></label>`;
    const text = name => `<label class="nt-field"><span>${escape(FEATURE_TEXTS[name][0])} ${required}</span><span class="nt-hint">${escape(FEATURE_TEXTS[name][1])}</span>
        <textarea name="${name}" rows="4" maxlength="2000" required></textarea></label>`;
    return `<div class="nt-head"><div class="nt-title">Novo Ticket · Melhoria / Feature</div><button type="button" class="kpi-modal-close" aria-label="Fechar" data-close>✕</button></div>
      <form class="nt-form" novalidate>
        <div class="nt-fixed" aria-label="Valores definidos automaticamente">
          <span class="nt-chip">Quadro: <b>${escape(options?.boardName || 'Funil de Demandas')}</b></span><span class="nt-chip">Status: <b>Aguardando revisão</b></span>
          <button type="button" class="nt-link nt-change-kind" data-change-kind>← Trocar tipo</button>
        </div>
        <p class="nt-feature-intro">Canal oficial para demandas, ideias e melhorias de produto. Descreva o problema ou a necessidade com detalhes: quanto mais claro o problema, melhor conseguimos priorizar e melhor será a solução. Todos os campos são obrigatórios.</p>
        <label class="nt-field"><span>Título ${required}</span><span class="nt-hint">Resuma em uma linha a solicitação.</span>
          <input name="title" maxlength="255" required autocomplete="off" placeholder="Ex.: Filtro por modelo de equipamento na tela de alarmes">
        </label>
        <label class="nt-field"><span>Nome solicitante ${required}</span><span class="nt-hint">Quem pediu a demanda.</span>
          <input name="requester" maxlength="255" required autocomplete="off" value="${escape(options?.requester || '')}">
        </label>
        <div class="nt-grid">${FEATURE_SELECTS.slice(0, 3).map(select).join('')}</div>
        ${text('description')}
        <fieldset class="nt-field"><legend>Quem é afetado? ${required}</legend><span class="nt-hint">Quem a ideia ou o problema afeta? Marque todos que se aplicam.</span>
          <div class="nt-checks">${options ? options.affected.map(option => `<label class="nt-check"><input type="checkbox" name="affected" value="${escape(option)}"><span>${escape(option)}</span></label>`).join('')
            : `<span class="nt-muted">${state.featureLoading ? 'Carregando opções do Monday...' : 'Opções indisponíveis.'}</span>`}</div>
        </fieldset>
        ${text('solution')}
        <div class="nt-field"><span>Evidências ${required}</span><span class="nt-hint">Prints, vídeos, documentos ou e-mails que sustentam a solicitação. Pelo menos um arquivo.</span>
          <div class="nt-editor nt-evidence" data-drop>${renderAttach('📎 Cole (Ctrl+V), arraste ou <u>clique para adicionar evidências</u>')}</div>
        </div>
        ${text('requirements')}
        <div class="nt-grid">${FEATURE_SELECTS.slice(3).map(select).join('')}</div>
        <fieldset class="nt-field nt-priorities"><legend>Severidade ${required}</legend><span class="nt-hint">Qual a urgência na sua percepção?</span>
          ${renderLevels('severity', options?.severity, SEVERITY_GUIDE, state.featureLoading)}
        </fieldset>
        ${renderFoot('Criar demanda')}
      </form>`;
  }

  function render() {
    if (state.created) { dialog.innerHTML = renderSuccess(); return; }
    if (!state.kind) { dialog.innerHTML = renderChooser(); return; }
    dialog.innerHTML = state.kind === 'feature' ? renderFeatureForm() : renderSupportForm();
  }

  /** Redesenha o formulário mantendo o que já foi digitado e as opções marcadas; campos não alterados recebem o valor novo (ex.: solicitante). */
  function rerender() {
    const current = form();
    const changed = current ? [...current.elements].filter(element => element.name && (['checkbox', 'radio'].includes(element.type)
      ? element.checked !== element.defaultChecked : element.tagName === 'SELECT' ? element.value !== '' : element.value !== element.defaultValue))
      .map(element => ({name:element.name, value:element.value, checked:element.checked, type:element.type})) : null;
    render();
    if (!changed || state.created || !form()) return;
    for (const saved of changed) {
      const targets = [...form().elements].filter(element => element.name === saved.name);
      if (['checkbox', 'radio'].includes(saved.type)) targets.filter(element => element.value === saved.value).forEach(element => { element.checked = saved.checked; });
      else targets.forEach(element => { if (element.tagName !== 'SELECT' || [...element.options].some(option => option.value === saved.value)) element.value = saved.value; });
    }
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
    if (submitButton) submitButton.disabled = !kindOptions() || state.saving || state.preparing;
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

  /** Envia os anexos um por requisição: no ticket, ao comentário inicial; na demanda, à coluna Evidências. Os que falharem ficam para nova tentativa. */
  async function uploadFiles(entries, onProgress) {
    const created = state.created;
    const url = created.kind === 'feature'
      ? `/api/feature-requests/${encodeURIComponent(created.id)}/files`
      : `/api/tickets/${encodeURIComponent(created.id)}/comments/${encodeURIComponent(created.commentId)}/files`;
    const failed = [];
    for (const [index, entry] of entries.entries()) {
      onProgress?.(index + 1, entries.length);
      try { await Attachments.upload(url, entry.file); created.attached++; }
      catch (error) { failed.push({...entry, error:error.message}); }
    }
    return failed;
  }

  /** Dados do formulário aberto e o que falta preencher, na ordem dos campos. */
  function collect() {
    const current = form();
    const checked = name => current.querySelector(`[name=${name}]:checked`)?.value || '';
    if (state.kind === 'support') {
      const ticket = {title:field('title').value.trim(), client:field('client').value.trim(), description:field('description').value.trim(), priority:checked('priority')};
      const missing = [['title', 'título'], ['client', 'cliente'], ['description', 'descrição (ajuste o modelo)'], ['priority', 'prioridade']]
        .filter(([name]) => name === 'description' ? !descriptionChanged(ticket.description) : !ticket[name]);
      return {url:'/api/tickets', payload:ticket, missing};
    }
    const value = name => field(name).value.trim();
    const request = {
      title:value('title'), requester:value('requester'), origin:value('origin'), type:value('type'), module:value('module'),
      description:value('description'), affected:[...current.querySelectorAll('[name=affected]:checked')].map(input => input.value),
      solution:value('solution'), requirements:value('requirements'), motivation:value('motivation'), mrr:value('mrr'), severity:checked('severity'),
    };
    const labels = [['title', 'título'], ['requester', 'nome solicitante'], ['origin', 'origem'], ['type', 'tipo'], ['module', 'módulo'],
      ['description', 'descrição'], ['affected', 'quem é afetado'], ['solution', 'solução proposta'], ['evidence', 'evidências (anexe pelo menos um arquivo)'],
      ['requirements', 'requisitos'], ['motivation', 'motivação'], ['mrr', 'possível MRR'], ['severity', 'severidade']];
    const missing = labels.filter(([name]) => name === 'evidence' ? !state.files.length : name === 'affected' ? !request.affected.length : !request[name]);
    return {url:'/api/feature-requests', payload:request, missing};
  }

  async function submit() {
    if (state.saving || state.preparing) return;
    const current = form();
    const {url, payload, missing} = collect();
    if (missing.length) {
      message(`Preencha: ${missing.map(([, label]) => label).join(', ')}.`);
      const name = missing[0][0];
      (name === 'evidence' ? current.querySelector('[data-file-input]')?.closest('label') : current.querySelector(`[name=${name}]`))?.focus();
      return;
    }
    const tooBig = state.files.map(entry => entry.file).filter(file => file.size > uploadLimit());
    if (tooBig.length) { message(Attachments.tooBigMessage(tooBig, uploadLimit())); return; }
    const feature = state.kind === 'feature';
    const label = feature ? 'Criar demanda' : 'Criar ticket';
    state.saving = true;
    current.querySelector('.nt-primary').disabled = true;
    current.querySelector('.nt-primary').textContent = 'Criando...';
    message(feature ? 'Criando a demanda no Funil de Demandas...' : 'Criando o ticket no Monday...', 'info');
    try {
      const created = await mondayRequest(url, payload);
      state.created = {...created, kind:state.kind, attached:0};
      const files = state.files;
      state.failed = [];
      if (files.length && (feature || created.commentId)) {
        state.failed = await uploadFiles(files, (index, total) => message(`${feature ? 'Demanda criada' : 'Ticket criado'}. Enviando anexo ${index} de ${total}...`, 'info'));
      } else if (files.length) {
        state.failed = files.map(entry => ({...entry, error:'o comentário inicial não foi criado'}));
      }
      state.saving = false;
      clearFiles();
      render();
      // O cache foi limpo no servidor; as telas abertas buscam o card novo.
      if (feature) return;
      if (currentPage === 'bombeiro') window.Bombeiro?.refresh(true);
      else if (typeof syncMonday === 'function') syncMonday(true);
    } catch (error) {
      message(error.message);
      current.querySelector('.nt-primary').disabled = false;
      current.querySelector('.nt-primary').textContent = label;
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
    if (hasDraft() && !confirm('Descartar o que ainda não foi enviado?')) return;
    clearFiles();
    dialog.close();
  }

  function chooseKind(kind) {
    Object.assign(state, {kind, error:''});
    render();
    field('title')?.focus();
    if (kind === 'feature' && !state.featureOptions && !state.featureLoading) loadFeatureOptions();
    if (kind === 'support' && !state.options && !state.loading) loadOptions();
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
        const kind = event.target.closest('[data-kind]');
        if (kind) { chooseKind(kind.dataset.kind); return; }
        if (event.target.closest('[data-change-kind]')) {
          if (hasDraft() && !confirm('Trocar o tipo descarta o que já foi preenchido. Continuar?')) return;
          clearFiles();
          Object.assign(state, {kind:null, error:''});
          render();
          return;
        }
        if (event.target.closest('[data-retry]')) { retryFailed(); return; }
        if (event.target.closest('[data-template]')) {
          const description = field('description');
          if (!descriptionChanged(description.value) || confirm('Substituir a descrição atual pelo modelo em branco?')) { description.value = DESCRIPTION_TEMPLATE; description.focus(); }
          return;
        }
        if (event.target.closest('[data-another]')) { Object.assign(state, {created:null, error:'', failed:[]}); render(); field('title')?.focus(); return; }
        if (event.target.closest('[data-details]')) { const id = state.created.id; dialog.close(); window.ItemDetail.open(id); }
      });
      dialog.addEventListener('submit', event => { event.preventDefault(); submit(); });
      dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
      dialog.addEventListener('change', event => { if (event.target.matches('[data-file-input]')) { addFiles([...event.target.files]); event.target.value = ''; } });
      // Prints colados no formulário viram anexos; texto colado segue normal.
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
    Object.assign(state, {kind:null, created:null, error:'', saving:false, preparing:false, failed:[]});
    render();
    dialog.showModal();
    dialog.querySelector('button[data-kind]')?.focus();
    if (!state.options) loadOptions();
  }

  async function loadOptions() {
    state.loading = true;
    try {
      state.options = await mondayRequest('/api/tickets/options');
    } catch (error) {
      if (state.kind === 'support') state.error = error.message;
    } finally {
      state.loading = false;
    }
    if (dialog.open && state.kind === 'support' && !state.created) rerender();
  }

  async function loadFeatureOptions() {
    state.featureLoading = true;
    try {
      state.featureOptions = await mondayRequest('/api/feature-requests/options');
    } catch (error) {
      if (state.kind === 'feature') state.error = error.message;
    } finally {
      state.featureLoading = false;
    }
    if (dialog.open && state.kind === 'feature' && !state.created) rerender();
  }

  return {open};
})();
