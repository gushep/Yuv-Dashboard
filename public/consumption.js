/* Consumo executado: somente a base sincronizada, sem rateio de horas compartilhadas. */
window.Consumption = (() => {
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const normalize = value => String(value ?? '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('pt-BR');
  const state = {active:false, rows:null, dates:{}, sprint:null, developer:'', updated:null, reserves:{}, defaultMinutes:null, loaded:false, saving:false, loading:false, error:'', configError:'', expanded:new Set()};
  const duration = value => window.Forecast.duration(value);
  function format(minutes) {
    if (minutes === null) return 'Sem registro';
    const value = Math.round(Math.abs(minutes));
    return `${Math.floor(value/60)}h${value%60 ? String(value%60).padStart(2,'0') : ''}`;
  }
  const percent = value => value === null ? '—' : `${value.toLocaleString('pt-BR',{maximumFractionDigits:1})}%`;
  const emptyTotals = () => ({sprint:0, support:0, total:0, tasks:[]});
  function add(target, task) { target[task.group] += task.minutes ?? 0; target.total += task.minutes ?? 0; target.tasks.push(task); }
  function shares(totals) { return {sprint:totals.total ? totals.sprint/totals.total*100 : null, support:totals.total ? totals.support/totals.total*100 : null}; }
  /**
   * Horas executadas da sprint. O macro soma tudo, como sempre; a composição separa Desenvolvimento (Full stack),
   * Projetos, cards só de pessoas sem time (fora das métricas por pessoa) e os sem atribuição individual.
   */
  function model(rows, sprint) {
    const unique = new Map(), seenSprints = new Map(), groups = new Map();
    const macro = emptyTotals(), unassigned = emptyTotals(), outside = emptyTotals();
    let missingType = 0, missingReal = 0, missingId = 0, multiSprint = 0;
    for (const row of rows) {
      const id = String(row.Id ?? '').trim();
      if (id && !row.AsOf) { const values = seenSprints.get(id) || new Set(); values.add(Number(row.Sprint)); seenSprints.set(id,values); }
      if (Number(row.Sprint) !== Number(sprint)) continue;
      if (!id) { missingId++; continue; }
      if (!unique.has(id)) unique.set(id,row);
    }
    for (const row of unique.values()) {
      const group = normalize(row.Tipo) === 'suporte' ? 'support' : 'sprint';
      const minutes = duration(row.RealH);
      const people = [...new Set(String(row.Responsavel ?? '').split(',').map(name => name.trim()).filter(Boolean))];
      const task = {row, group, minutes};
      if (!normalize(row.Tipo)) missingType++;
      if (minutes === null) missingReal++;
      if (seenSprints.get(String(row.Id).trim())?.size > 1) multiSprint++;
      add(macro,task);
      const counted = people.filter(name => window.SprintRules.teamOf(name) !== null);
      if (people.length && !counted.length) { task.reason = 'Responsável sem time nas Configurações'; add(outside,task); }
      else if (counted.length === 1) {
        if (!groups.has(counted[0])) groups.set(counted[0],{name:counted[0],team:window.SprintRules.teamOf(counted[0]),...emptyTotals()});
        add(groups.get(counted[0]),task);
      } else { task.reason = counted.length ? 'Tempo compartilhado entre vários responsáveis' : 'Sem responsável'; add(unassigned,task); }
    }
    const developers = [...groups.values()].sort((a,b) => b.total-a.total || a.name.localeCompare(b.name,'pt-BR'));
    const sum = list => list.reduce((total,dev) => ({sprint:total.sprint+dev.sprint, support:total.support+dev.support, total:total.total+dev.total, tasks:[...total.tasks,...dev.tasks]}),emptyTotals());
    const attributed = sum(developers);
    const teams = {fullstack:sum(developers.filter(dev => dev.team === 'fullstack')), projects:sum(developers.filter(dev => dev.team === 'projects'))};
    return {macro, developers, attributed, teams, outside, unassigned, missingType, missingReal, missingId, multiSprint};
  }
  /** Composição de um indicador do topo em fonte menor: Desenvolvimento, Projetos e, quando houver, o resto. */
  function compositionText(result, key) {
    const parts = [`Desenvolvimento ${format(result.teams.fullstack[key])}`, `Projetos ${format(result.teams.projects[key])}`];
    if (result.outside[key]) parts.push(`Sem time ${format(result.outside[key])}`);
    if (result.unassigned[key]) parts.push(`Sem atribuição ${format(result.unassigned[key])}`);
    return parts.join(' · ');
  }
  function reserveModel(support, reserve) {
    if (!reserve) return {consumed:null, available:null, excess:null, balance:-support, scale:Math.max(support,1), marker:null};
    return {consumed:support/reserve*100, available:Math.max(0,(reserve-support)/reserve*100), excess:support > reserve ? (support-reserve)/reserve*100 : 0, balance:reserve-support, scale:Math.max(reserve,support)*1.15, marker:reserve/(Math.max(reserve,support)*1.15)*100};
  }
  /** Ritmo da reserva: projeta o consumo de suporte até o fim da sprint e a data em que a reserva acaba. */
  function paceText(support, dates, reserve, today = window.SprintRules.today()) {
    const pace = window.SprintRules.pace(support, dates, today, reserve);
    const day = value => value.split('-').reverse().slice(0, 2).join('/');
    if (pace.status === 'unavailable') return {text:'Ritmo indisponível: datas da sprint ausentes no Monday.', risk:false};
    if (pace.status === 'not-started') return {text:'Sprint ainda não começou: sem ritmo de consumo.', risk:false};
    if (pace.status === 'finished') return {text:`Sprint encerrada: média de ${format(pace.daily)} de suporte por dia útil.`, risk:false};
    if (!reserve) return {text:`Ritmo atual: ${format(pace.daily)} por dia útil · ${format(pace.projected)} previstas até o fim da sprint.`, risk:false};
    const risk = pace.exhaustionDate !== null && pace.exhaustionDate < pace.sprintEnd;
    const ending = pace.exhaustionDate === null ? 'a reserva é suficiente até o fim da sprint'
      : risk ? `a reserva acaba em ${day(pace.exhaustionDate)}, antes do fim da sprint (${day(pace.sprintEnd)})` : 'a reserva acaba no último dia da sprint';
    return {text:`No ritmo atual (${format(pace.daily)} por dia útil): ${format(pace.projected)} até o fim da sprint, ${percent(pace.projectedPercent)} da reserva; ${ending}.`, risk};
  }
  function bar(totals) {
    const parts = shares(totals);
    return `<div class="cons-stack" role="img" aria-label="Sprint ${format(totals.sprint)}, ${percent(parts.sprint)}; Suporte ${format(totals.support)}, ${percent(parts.support)}">${totals.total ? `<span class="cons-green" style="width:${parts.sprint}%">${parts.sprint >= 12 ? percent(parts.sprint) : ''}</span><span class="cons-red" style="width:${parts.support}%">${parts.support >= 12 ? percent(parts.support) : ''}</span>` : ''}</div>`;
  }
  function taskLink(row) {
    const url = window.SprintPanel.safeMondayUrl(row.Url);
    return url ? `<a href="${escape(url)}" target="_blank" rel="noopener noreferrer">${escape(row.Nome || 'Sem título')} ↗</a>` : escape(row.Nome || 'Sem título');
  }
  /**
   * Tasks de um desenvolvedor (ou sem atribuição) como datatable: busca, filtro por Tipo, Grupo, Status e Atribuição
   * e ordenação pelo cabeçalho (mesmo componente dos modais de indicadores), com os subtotais embaixo.
   */
  function renderDetails(container, totals, unassigned = false) {
    const columns = [
      {label:'Tarefa', primary:true, html:task => taskLink(task.row), value:task => task.row.Nome || 'Sem título'},
      {label:'Tipo', html:task => escape(task.row.Tipo || 'Sem Tipo'), value:task => task.row.Tipo || 'Sem Tipo', filter:true},
      {label:'Grupo calculado', html:task => task.group === 'support' ? 'Suporte' : 'Sprint', value:task => task.group === 'support' ? 'Suporte' : 'Sprint', filter:true},
      {label:'Status original', html:task => escape(task.row.Status || 'Não informado'), value:task => task.row.Status || 'Não informado', filter:true},
      {label:'Tempo Real', html:task => format(task.minutes), value:task => format(task.minutes), sort:task => task.minutes ?? ''},
      ...(unassigned ? [{label:'Atribuição', html:task => escape(task.reason), value:task => task.reason, filter:true}] : [])
    ];
    const table = document.createElement('div');
    container.replaceChildren(table);
    window.KpiTable.render(table, {columns, rows:totals.tasks, empty:'Nenhuma task.', tableClass:'cons-detail-table'});
    const subtotals = document.createElement('p');
    subtotals.className = 'cons-subtotals';
    subtotals.textContent = `Subtotais: Sprint ${format(totals.sprint)} · Suporte ${format(totals.support)} · Total ${format(totals.total)}${totals.tasks.some(task => task.minutes === null) ? ' · Há itens sem registro de Tempo Real.' : ''}`;
    container.append(subtotals);
  }
  function cells(totals) {
    const parts = shares(totals);
    const missing = totals.tasks?.filter(task => task.minutes === null).length || 0;
    return `<td><strong>${format(totals.total)}</strong>${missing ? `<small>${missing} item(ns) sem registro · soma conhecida</small>` : ''}</td><td class="cons-sprint">${format(totals.sprint)} · ${percent(parts.sprint)}</td><td class="cons-support">${format(totals.support)} · ${percent(parts.support)}</td><td>${bar(totals)}</td>`;
  }
  function render() {
    if (!state.active) return;
    const sprints = [...new Set([...(state.rows || []).map(row => Number(row.Sprint)), ...Object.keys(state.dates).map(Number)])].filter(value => Number.isInteger(value) && value > 0).sort((a,b) => b-a);
    if (!sprints.includes(state.sprint)) state.sprint = sprints[0] ?? null;
    el('cons-sprint').replaceChildren(...sprints.map(value => new Option(`Sprint ${value}`,String(value))));
    if (state.sprint !== null) { el('cons-sprint').value = state.sprint; window.rememberScreenSprint?.('consumption', state.sprint); }
    const dates = state.dates[state.sprint];
    const date = value => new Date(`${value}T12:00:00`).toLocaleDateString('pt-BR');
    el('cons-dates').textContent = dates ? `${date(dates.start)} – ${date(dates.end)}` : 'Datas da sprint indisponíveis no Monday';
    el('cons-updated').textContent = `${state.loading ? 'Atualizando… ' : ''}${state.updated ? `Última atualização: ${new Date(state.updated).toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'})}` : 'Aguardando sincronização do Monday'}`;
    el('cons-refresh').disabled = state.loading;
    el('cons-error').textContent = [state.error,state.configError].filter(Boolean).join(' ');
    el('cons-error').hidden = !(state.error || state.configError);
    el('cons-content').hidden = state.rows === null || state.sprint === null;
    el('cons-empty').hidden = state.rows !== null && state.sprint !== null;
    el('cons-empty').textContent = state.rows === null ? 'Configure a integração com Monday e atualize os dados para visualizar horas reais.' : 'Nenhum item ou cronograma de sprint disponível na consulta atual.';
    if (state.rows === null || state.sprint === null) return;
    const result = model(state.rows,state.sprint), totals = result.macro, parts = shares(totals);
    const reserve = state.reserves[state.sprint] ?? state.defaultMinutes ?? 0, comparison = reserveModel(totals.support,reserve);
    // Os mesmos cards do Capacity (previsto e registrado contra a capacidade, com semáforo), pela mesma regra.
    el('cons-teams').innerHTML = window.Capacity.summaryFor(state.rows, state.sprint, dates || null, window.SprintRules.settings.capacityOverrides || {}, reserve).html;
    el('cons-total').textContent = format(totals.total);
    el('cons-total-parts').textContent = compositionText(result,'total');
    el('cons-support-projects').hidden = !result.teams.projects.support;
    el('cons-support-projects').textContent = result.teams.projects.support ? `Atenção: Projetos registrou ${format(result.teams.projects.support)} de suporte; a sustentação é do time Full stack.` : '';
    el('cons-composition').innerHTML = bar(totals);
    el('cons-legend').innerHTML = `<span class="cons-sprint">● Sprint · ${format(totals.sprint)} · ${percent(parts.sprint)}</span><span class="cons-support">● Suporte · ${format(totals.support)} · ${percent(parts.support)}</span>`;
    const canEdit = Boolean(window.CAN_MANAGE_CAPACITY);
    el('cons-reserve-input').value = reserve/60;
    el('cons-reserve-input').disabled = !canEdit || !state.loaded || state.saving;
    el('cons-reserve-save').disabled = !canEdit || !state.loaded || state.saving;
    el('cons-reserve').classList.toggle('cons-over',comparison.balance < 0);
    el('cons-reserve-hours').textContent = state.loaded ? `${format(totals.support)} consumidas / ${format(reserve)} reservadas` : 'Carregando reserva salva…';
    el('cons-reserve-percent').textContent = !state.loaded ? 'Reserva ainda indisponível' : reserve ? `${percent(comparison.consumed)} da reserva consumida` : 'Sem reserva configurada';
    el('cons-reserve-bar').innerHTML = !state.loaded ? '' : `<div class="cons-reserve-track" role="img" aria-label="${escape(el('cons-reserve-percent').textContent)}"><span class="cons-red" style="width:${totals.support/comparison.scale*100}%"></span>${comparison.marker === null ? '' : `<span class="cons-marker" style="left:${comparison.marker}%"><small>100% · ${format(reserve)}</small></span>`}</div>`;
    el('cons-reserve-balance').textContent = !state.loaded ? '' : !reserve ? (totals.support ? `${format(totals.support)} consumidas sem reserva` : 'Sem reserva configurada') : comparison.balance > 0 ? `${format(comparison.balance)} restantes · ${percent(comparison.available)} da reserva disponível` : comparison.balance === 0 ? 'Reserva totalmente consumida' : `${format(-comparison.balance)} acima da reserva · Excesso de ${percent(comparison.excess)}`;
    const reservePace = paceText(totals.support, dates, reserve);
    el('cons-reserve-pace').textContent = state.loaded ? reservePace.text : '';
    el('cons-reserve-pace').classList.toggle('cons-pace-risk', state.loaded && reservePace.risk);
    const names = result.developers.map(dev => dev.name);
    if (!names.includes(state.developer)) state.developer = '';
    el('cons-developer').replaceChildren(new Option('Todos os desenvolvedores',''), ...names.map(name => new Option(name,name)));
    el('cons-developer').value = state.developer;
    // Duas tabelas: Desenvolvimento (Full stack) e Projetos; o filtro de desenvolvedor vale para as duas.
    // Antes de classificar os times não há Projetos: a tabela dele fica escondida.
    const projectsOn = window.SprintRules.teamsConfigured();
    el('cons-projects-title').hidden = !projectsOn;
    el('cons-projects-body').closest('.cons-developer-scroll').hidden = !projectsOn;
    for (const [team, body, foot, label] of [['fullstack','cons-body','cons-foot','Desenvolvimento'],['projects','cons-projects-body','cons-projects-foot','Projetos']]) {
      const displayed = result.developers.filter(dev => dev.team === team && (!state.developer || dev.name === state.developer));
      el(body).replaceChildren();
      for (const [index,dev] of displayed.entries()) {
        const tr = document.createElement('tr'), expanded = state.expanded.has(dev.name);
        const initials = dev.name.split(/\s+/).map(part => part[0]).slice(0,2).join('').toLocaleUpperCase('pt-BR');
        tr.innerHTML = `<td><button type="button" class="cap-dev-button cons-dev" aria-expanded="${expanded}"><span class="cap-avatar cap-avatar-${index%4}">${escape(initials)}</span><span class="cap-dev-name" title="${escape(dev.name)}">${escape(dev.name)}</span><span class="cap-chevron" aria-hidden="true">${expanded ? '⌄' : '›'}</span></button></td>${cells(dev)}`;
        tr.querySelector('button').onclick = () => { expanded ? state.expanded.delete(dev.name) : state.expanded.add(dev.name); render(); };
        el(body).append(tr);
        if (expanded) { const detail = document.createElement('tr'); detail.className = 'cons-detail'; detail.innerHTML = '<td colspan="5"></td>'; renderDetails(detail.firstElementChild, dev); el(body).append(detail); }
      }
      if (!displayed.length) el(body).innerHTML = `<tr><td colspan="5">${state.developer ? 'O desenvolvedor filtrado não é deste time.' : `Nenhum item atribuível individualmente a ${label} nesta sprint.`}</td></tr>`;
      const shown = displayed.reduce((sum,dev) => ({sprint:sum.sprint+dev.sprint,support:sum.support+dev.support,total:sum.total+dev.total,tasks:[...sum.tasks,...dev.tasks]}),emptyTotals());
      el(foot).innerHTML = `<tr><th>${state.developer ? 'Total exibido' : `Total ${label}`}</th>${cells(shown)}</tr>`;
    }
    const others = {sprint:result.unassigned.sprint+result.outside.sprint, support:result.unassigned.support+result.outside.support, total:result.unassigned.total+result.outside.total, tasks:[...result.unassigned.tasks,...result.outside.tasks]};
    el('cons-unassigned-summary').textContent = `Sprint ${format(others.sprint)} · Suporte ${format(others.support)} · Total ${format(others.total)} · ${others.tasks.length} item(ns)`;
    if (others.tasks.length) renderDetails(el('cons-unassigned'), others, true);
    else el('cons-unassigned').textContent = 'Nenhum item sem atribuição individual.';
    el('cons-reconciliation').textContent = `Reconciliação do time: ${format(totals.total)} executadas = ${format(result.teams.fullstack.total)} Desenvolvimento + ${format(result.teams.projects.total)} Projetos + ${format(result.outside.total)} de pessoas sem time + ${format(result.unassigned.total)} sem atribuição individual.`;
    el('cons-quality').textContent = `${totals.tasks.length} itens únicos · ${result.missingType} sem Tipo (incluídos em Sprint) · ${result.missingReal} sem registro válido de Tempo Real (não são zeros confirmados) · ${result.multiSprint} cards presentes em várias sprints, com tempo acumulado · ${result.missingId} registros sem ID excluídos por falta de identificação auditável.`;
    el('cons-quality-summary').textContent = `Qualidade dos dados: Tempo Real por card · ${result.missingType} sem Tipo · ${result.missingReal} sem registro · ver detalhes`;
  }
  async function loadReserves() {
    try { const result = await mondayRequest(el('cons-reserve-form').dataset.url); state.reserves = result.reserves || {}; state.defaultMinutes = result.defaultMinutes; state.loaded = true; state.configError = ''; }
    catch (error) { state.loaded = false; state.configError = `Não foi possível carregar a reserva salva: ${error.message}`; }
    render();
  }
  async function saveReserve(event) {
    event.preventDefault();
    if (!state.loaded || state.saving || state.sprint === null) return;
    if (!window.CAN_MANAGE_CAPACITY) { el('cons-save-status').textContent = 'Somente o Maestro Scrum altera a reserva.'; return; }
    const hours = Number(el('cons-reserve-input').value.replace(',','.'));
    if (!Number.isFinite(hours) || hours < 0 || hours > 10000) { el('cons-save-status').textContent = 'Informe uma reserva entre 0h e 10000h.'; return; }
    const sprint = state.sprint;
    state.saving = true; render();
    try { const result = await mondayRequest(el('cons-reserve-form').dataset.url,{sprint:String(sprint), minutes:Math.round(hours*60)}); state.reserves = result.reserves; el('cons-save-status').textContent = `Reserva da Sprint ${sprint} salva.`; }
    catch (error) { el('cons-save-status').textContent = `Falha ao salvar: ${error.message}`; }
    finally { state.saving = false; render(); }
  }
  function onDataUpdated(result) { state.rows = result.rows; state.updated = result.syncedAt; state.dates = Object.fromEntries((result.sprints || []).map(item => [item.number,item])); state.error = ''; render(); }
  function onError(message) { state.loading = false; state.error = `Falha ao atualizar: ${message}${state.rows ? ' · Exibindo a última consulta disponível.' : ''}`; render(); }
  function onLoading() { state.loading = true; render(); }
  function onSyncComplete() { state.loading = false; render(); }
  function enter(hint = null) {
    state.active = true;
    document.body.classList.add('consumption-view');
    if (Number.isInteger(hint)) state.sprint = hint;
    render(); loadReserves();
    if (typeof mondayReady !== 'undefined' && mondayReady) syncMonday(false);
  }
  function leave() {
    state.active = false;
    document.body.classList.remove('consumption-view');
    document.querySelectorAll('#page-consumption details[open]').forEach(node => { node.open = false; });
  }
  document.addEventListener('DOMContentLoaded', () => {
    el('cons-sprint').onchange = event => { state.sprint = Number(event.target.value); state.expanded.clear(); el('cons-save-status').textContent = ''; render(); };
    el('cons-developer').onchange = event => { state.developer = event.target.value; render(); };
    el('cons-refresh').onclick = () => syncMonday(true);
    el('cons-reserve-form').onsubmit = saveReserve;
  });
  return {enter,leave,onDataUpdated,onError,onLoading,onSyncComplete,model,compositionText,reserveModel,shares,format,bar};
})();
