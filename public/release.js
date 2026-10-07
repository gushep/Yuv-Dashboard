/* O backend prepara o documento; a interface apenas apresenta os dados da release. */
window.SprintRelease = (() => {
  const el = id => document.getElementById(id);
  const state = {active:false, sprint:null, data:null, loading:false, error:'', request:0, expanded:new Set(), printing:false, printGroups:[], excluded:new Set()};
  /* Seleção para exportação: guardada só neste navegador, por sprint. Na tela todos os itens continuam visíveis. */
  const selectionKey = sprint => `yuv-release-excluded-${sprint}`;
  function loadSelection(sprint) {
    try { state.excluded = new Set(JSON.parse(localStorage.getItem(selectionKey(sprint)) || '[]').map(String)); } catch { state.excluded = new Set(); }
  }
  function saveSelection() {
    try { localStorage.setItem(selectionKey(state.sprint), JSON.stringify([...state.excluded])); } catch { /* A seleção vale para esta sessão mesmo sem armazenamento local. */ }
  }
  function setSelected(ids, selected) {
    for (const id of ids) selected ? state.excluded.delete(String(id)) : state.excluded.add(String(id));
    saveSelection();render();
  }
  const selectedItems = group => group.items.filter(item => !state.excluded.has(String(item.id)));
  const normalize = value => String(value ?? '').trim().replace(/\s+/g,' ').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  function typeVisual(type) {
    const name = normalize(type);
    if (name === 'melhoria') return {color:'green', icon:'↗'};
    if (name === 'suporte') return {color:'red', icon:'◎'};
    if (name === 'homologacao') return {color:'purple', icon:'✓'};
    const colors = ['blue','teal','orange','purple'];
    const hash = [...name].reduce((value,char) => (value*31+char.charCodeAt(0))>>>0,0);
    return {color:colors[hash%colors.length], icon:'◇'};
  }
  function groupNode(group) {
    const visual = typeVisual(group.type), section = document.createElement('details');
    const selected = selectedItems(group).length;
    section.className = `release-group release-${visual.color}${selected ? '' : ' release-group-excluded'}`;
    section.open = state.expanded.has(group.type);
    section.addEventListener('toggle',()=>{
      if (!section.isConnected || state.printing) return;
      if (section.open) state.expanded.add(group.type);
      else state.expanded.delete(group.type);
    });
    const summary = document.createElement('summary');
    const heading = document.createElement('h2');
    const icon = document.createElement('span');icon.className='release-icon';icon.append(svgIcon(visual.color==='green'?'trend':visual.color==='red'?'support':visual.color==='purple'?'check':'layers'));icon.setAttribute('aria-hidden','true');
    const label = document.createElement('span');label.textContent=group.type;
    const counter = document.createElement('span');counter.className='release-counter';counter.dataset.total=group.count;counter.dataset.selected=selected;
    counter.textContent=state.printing ? selected : group.count;counter.setAttribute('aria-label',`${group.count} itens`);
    const chevron = document.createElement('span');chevron.className='release-chevron';chevron.append(svgIcon('chevron'));chevron.setAttribute('aria-hidden','true');
    heading.append(icon,label,counter,chevron);summary.append(heading);section.append(summary);
    const items = document.createElement('div');items.className='release-group-items';section.append(items);
    const groupActions = document.createElement('div');groupActions.className='release-group-selection';
    for (const [label, value] of [['Marcar todos deste tipo', true], ['Desmarcar todos deste tipo', false]]) {
      const button = document.createElement('button');button.type='button';button.textContent=label;
      button.onclick=()=>setSelected(group.items.map(item => item.id), value);groupActions.append(button);
    }
    items.append(groupActions);
    for (const item of group.items) {
      const isSelected = !state.excluded.has(String(item.id));
      const article = document.createElement('article');article.className=`release-delivery${isSelected ? '' : ' release-excluded'}`;article.dataset.itemId=item.id;
      const pick = document.createElement('input');pick.type='checkbox';pick.className='release-pick';pick.checked=isSelected;
      pick.setAttribute('aria-label',`Incluir na exportação: ${item.title}`);pick.onchange=()=>setSelected([item.id], pick.checked);
      article.append(pick);
      const text = document.createElement('div'), title = document.createElement('h3');text.className='release-copy';title.textContent=item.title;text.append(title);
      if (item.description) { const description = document.createElement('p');description.textContent=item.description;text.append(description); }
      if (item.descriptionStale) { const stale = document.createElement('small');stale.className='release-stale';stale.textContent='Resumo desatualizado: o card mudou depois que o resumo foi escrito.';text.append(stale); }
      article.append(text);
      const module = document.createElement('span');module.className='release-module';module.append(svgIcon('layers'));
      if (item.module) { module.append(document.createTextNode(item.module));module.title=`Módulo: ${item.module}`; }
      else module.setAttribute('aria-label','Entrega sem módulo informado');
      article.append(module);
      const url = window.SprintPanel.safeMondayUrl(item.url);
      if (url) { const link = document.createElement('a');link.href=url;link.className='release-card-link';link.append(document.createTextNode('Ver card'),svgIcon('external'));link.target='_blank';link.rel='noopener noreferrer';article.append(link); }
      items.append(article);
    }
    return section;
  }
  function svgIcon(name) {
    const paths = {
      trend:['M3 17l6-6 4 4 8-10','M15 5h6v6'],
      support:['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18','M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10','M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8'],
      check:['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18','M8 12l3 3 5-6'],
      layers:['M12 3L3 8l9 5 9-5-9-5z','M3 12l9 5 9-5M3 16l9 5 9-5'],
      external:['M14 4h6v6M20 4l-9 9','M10 5H4v15h15v-6'],
      chevron:['M6 9l6 6 6-6']
    };
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');svg.setAttribute('stroke-width','1.8');svg.setAttribute('stroke-linecap','round');svg.setAttribute('stroke-linejoin','round');svg.setAttribute('aria-hidden','true');
    for(const value of paths[name]){const path=document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d',value);svg.append(path);}
    return svg;
  }
  /** Situação das descrições: de onde vieram e o que falta pedir no Claude Code. */
  function summaryStatus(data) {
    const items = data.groups.flatMap(group => group.items);
    const count = source => items.filter(item => item.descriptionSource === source).length;
    const pending = (data.withoutDescription || 0) + (data.staleSummaries || 0);
    return `Descrições: ${count('ficha')} da ficha · ${count('resumo')} resumo(s) · ${data.withoutDescription || 0} sem descrição${data.staleSummaries ? ` · ${data.staleSummaries} desatualizado(s)` : ''}${pending ? ` — peça no Claude Code: "gera os resumos da release da sprint ${data.sprint}"` : ''}`;
  }
  function render() {
    if (!state.active) return;
    const data = state.data;
    const options = [...new Set([...(data?.sprints || []), ...(state.sprint ? [state.sprint] : [])])].sort((a,b) => b-a);
    el('release-sprint').replaceChildren(...options.map(value => new Option(`Sprint ${value}`,String(value))));
    if (state.sprint) el('release-sprint').value = String(state.sprint);
    el('release-sprint').disabled = !options.length;
    el('release-loading').hidden = !state.loading;
    el('release-error').hidden = !state.error;el('release-error').textContent=state.error;
    const visible = data && data.sprint===state.sprint;
    const selectedCount = visible ? data.groups.reduce((sum, group) => sum + selectedItems(group).length, 0) : 0;
    el('release-export').disabled = state.loading || !!state.error || !visible || !Number.isInteger(data.sprint) || !selectedCount;
    el('release-selection').hidden = !visible || !data.total;
    el('release-selection-count').textContent = visible ? `Exportar ${selectedCount} de ${data.total} ${data.total===1?'item':'itens'} no PDF` : '';
    const dates = visible ? data.dates : null;
    const date = value => new Date(`${value}T12:00:00`).toLocaleDateString('pt-BR');
    el('release-dates').textContent=dates ? `${date(dates.start)} – ${date(dates.end)}` : '';
    const shownTotal = state.printing ? selectedCount : visible ? data.total : 0;
    el('release-count').textContent = visible ? `Sprint ${data.sprint ?? '—'} · Status: Implementado · ${shownTotal} ${shownTotal===1?'item':'itens'}` : 'Aguardando dados da sprint';
    el('release-updated').textContent=visible ? `Última atualização: ${new Date(data.syncedAt).toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'})}` : '';
    el('release-summary-status').textContent = visible ? summaryStatus(data) : '';
    el('release-warning').hidden = !visible || !data.commentsUnavailable;
    el('release-warning').textContent = visible ? `Comentários indisponíveis em ${data.commentsUnavailable} item(ns). Os títulos das entregas foram preservados.` : '';
    el('release-empty').hidden = state.loading || !visible || data.total!==0;
    el('release-empty').textContent = state.sprint ? 'Esta sprint não possui itens com Status = Implementado.' : 'Nenhuma sprint disponível nos dados do Monday.';
    el('release-document').hidden = state.loading || !visible || !data.total;
    const groupOrder = type => { const index = ['melhoria','suporte','homologacao'].indexOf(normalize(type));return index < 0 ? 3 : index; };
    const groups = visible ? [...data.groups].sort((first,second) => groupOrder(first.type)-groupOrder(second.type)) : [];
    el('release-document').replaceChildren(...groups.map(groupNode));
  }
  async function load() {
    if (!state.active) return;
    const request = ++state.request, sprint = state.sprint;
    state.loading = true;state.error = '';render();
    try {
      const data = await mondayRequest(el('page-release').dataset.url,sprint ? {sprint} : {});
      if (!state.active || request !== state.request) return;
      if (data.sprint !== state.data?.sprint) loadSelection(data.sprint);
      state.data = data;state.sprint = data.sprint;
    } catch(error) {
      if (!state.active || request !== state.request) return;
      state.error = `Não foi possível obter a release: ${error.message}${state.data?.sprint===sprint ? ' Exibindo a última consulta disponível.' : ''}`;
    } finally {
      if (state.active && request === state.request) { state.loading = false;render(); }
    }
  }
  function enter(hint = null) {
    state.expanded.clear();
    state.active = true;document.body.classList.add('release-view');
    if (Number.isInteger(hint)) state.sprint=hint;
    load();
  }
  function leave() { state.active=false;state.request++;state.loading=false;document.body.classList.remove('release-view'); }
  function onDataUpdated() { if (state.active && !state.loading && !state.printing) load(); }
  function preparePrint() {
    if (!state.active || state.printing) return;
    state.printing = true;
    render();
    // Somente os tipos com itens marcados são abertos e impressos; os contadores passam a mostrar os itens marcados.
    state.printGroups = [...el('release-document').querySelectorAll('details')].map(group=>({group,open:group.open}));
    for (const {group} of state.printGroups) group.open = !group.classList.contains('release-group-excluded');
  }
  function restorePrint() {
    state.printGroups = [];state.printing = false;
    render();
  }
  function exportPdf() {
    if (!state.active || state.loading || state.error || !state.data || state.data.sprint!==state.sprint || !Number.isInteger(state.sprint)) return;
    const previousTitle = document.title;
    const restoreTitle = () => { document.title = previousTitle; };
    document.title = `Release da sprint - Sprint ${state.sprint}`;
    window.addEventListener('afterprint',restoreTitle,{once:true});
    try { preparePrint();window.print(); }
    catch(error) {
      window.removeEventListener('afterprint',restoreTitle);restoreTitle();restorePrint();
      state.error = `Não foi possível abrir a exportação: ${error.message}`;render();
    }
  }
  document.addEventListener('DOMContentLoaded',()=>{
    el('release-sprint').onchange=event=>{state.expanded.clear();state.sprint=Number(event.target.value);load();};
    el('release-export').onclick=exportPdf;
    el('release-select-all').onclick=()=>setSelected(state.data?.groups.flatMap(group => group.items.map(item => item.id)) || [], true);
    el('release-select-none').onclick=()=>setSelected(state.data?.groups.flatMap(group => group.items.map(item => item.id)) || [], false);
    if (location.hash==='#release') navigate('release');
  });
  window.addEventListener('beforeprint',preparePrint);
  window.addEventListener('afterprint',restorePrint);
  return {enter,leave,onDataUpdated,typeVisual};
})();
