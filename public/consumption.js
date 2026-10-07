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
  function model(rows, sprint) {
    const unique = new Map(), seenSprints = new Map(), groups = new Map();
    const macro = emptyTotals(), unassigned = emptyTotals();
    let missingType = 0, missingReal = 0, missingId = 0, multiSprint = 0;
    for (const row of rows) {
      const id = String(row.Id ?? '').trim();
      if (id) { const values = seenSprints.get(id) || new Set(); values.add(Number(row.Sprint)); seenSprints.set(id,values); }
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
      if (seenSprints.get(String(row.Id).trim()).size > 1) multiSprint++;
      add(macro,task);
      if (people.length === 1) {
        if (!groups.has(people[0])) groups.set(people[0],{name:people[0],...emptyTotals()});
        add(groups.get(people[0]),task);
      } else { task.reason = people.length ? 'Tempo compartilhado entre vários responsáveis' : 'Sem responsável'; add(unassigned,task); }
    }
    const developers = [...groups.values()].sort((a,b) => b.total-a.total || a.name.localeCompare(b.name,'pt-BR'));
    const attributed = developers.reduce((sum,dev) => ({sprint:sum.sprint+dev.sprint, support:sum.support+dev.support, total:sum.total+dev.total}),emptyTotals());
    return {macro, developers, attributed, unassigned, missingType, missingReal, missingId, multiSprint};
  }
  function reserveModel(support, reserve) {
    if (!reserve) return {consumed:null, available:null, excess:null, balance:-support, scale:Math.max(support,1), marker:null};
    return {consumed:support/reserve*100, available:Math.max(0,(reserve-support)/reserve*100), excess:support > reserve ? (support-reserve)/reserve*100 : 0, balance:reserve-support, scale:Math.max(reserve,support)*1.15, marker:reserve/(Math.max(reserve,support)*1.15)*100};
  }
  function bar(totals) {
    const parts = shares(totals);
    return `<div class="cons-stack" role="img" aria-label="Sprint ${format(totals.sprint)}, ${percent(parts.sprint)}; Suporte ${format(totals.support)}, ${percent(parts.support)}">${totals.total ? `<span class="cons-green" style="width:${parts.sprint}%">${parts.sprint >= 12 ? percent(parts.sprint) : ''}</span><span class="cons-red" style="width:${parts.support}%">${parts.support >= 12 ? percent(parts.support) : ''}</span>` : ''}</div>`;
  }
  function taskLink(row) {
    const url = window.SprintPanel.safeMondayUrl(row.Url);
    return url ? `<a href="${escape(url)}" target="_blank" rel="noopener noreferrer">${escape(row.Nome || 'Sem título')} ↗</a>` : escape(row.Nome || 'Sem título');
  }
  function details(totals, unassigned = false) {
    return `<div class="cap-table-scroll"><table class="cons-detail-table"><thead><tr><th>Tarefa</th><th>Tipo</th><th>Grupo calculado</th><th>Status original</th><th>Tempo Real</th>${unassigned ? '<th>Atribuição</th>' : ''}</tr></thead><tbody>${totals.tasks.map(task => `<tr><td>${taskLink(task.row)}</td><td>${escape(task.row.Tipo || 'Sem Tipo')}</td><td>${task.group === 'support' ? 'Suporte' : 'Sprint'}</td><td>${escape(task.row.Status || 'Não informado')}</td><td>${format(task.minutes)}</td>${unassigned ? `<td>${escape(task.reason)}</td>` : ''}</tr>`).join('')}</tbody></table></div><p class="cons-subtotals">Subtotais: Sprint ${format(totals.sprint)} · Suporte ${format(totals.support)} · Total ${format(totals.total)}${totals.tasks.some(task => task.minutes === null) ? ' · Há itens sem registro de Tempo Real.' : ''}</p>`;
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
    if (state.sprint !== null) el('cons-sprint').value = state.sprint;
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
    el('cons-total').textContent = format(totals.total);
    el('cons-sprint-hours').textContent = `${format(totals.sprint)} · ${percent(parts.sprint)}`;
    el('cons-support-hours').textContent = `${format(totals.support)} · ${percent(parts.support)}`;
    el('cons-composition').innerHTML = bar(totals);
    el('cons-legend').innerHTML = `<span class="cons-sprint">● Sprint · ${format(totals.sprint)} · ${percent(parts.sprint)}</span><span class="cons-support">● Suporte · ${format(totals.support)} · ${percent(parts.support)}</span>`;
    const reserve = state.reserves[state.sprint] ?? state.defaultMinutes ?? 0, comparison = reserveModel(totals.support,reserve);
    el('cons-reserve-input').value = reserve/60;
    el('cons-reserve-input').disabled = !state.loaded || state.saving;
    el('cons-reserve-save').disabled = !state.loaded || state.saving;
    el('cons-reserve').classList.toggle('cons-over',comparison.balance < 0);
    el('cons-reserve-hours').textContent = state.loaded ? `${format(totals.support)} consumidas / ${format(reserve)} reservadas` : 'Carregando reserva salva…';
    el('cons-reserve-percent').textContent = !state.loaded ? 'Reserva ainda indisponível' : reserve ? `${percent(comparison.consumed)} da reserva consumida` : 'Sem reserva configurada';
    el('cons-reserve-bar').innerHTML = !state.loaded ? '' : `<div class="cons-reserve-track" role="img" aria-label="${escape(el('cons-reserve-percent').textContent)}"><span class="cons-red" style="width:${totals.support/comparison.scale*100}%"></span>${comparison.marker === null ? '' : `<span class="cons-marker" style="left:${comparison.marker}%"><small>100% · ${format(reserve)}</small></span>`}</div>`;
    el('cons-reserve-balance').textContent = !state.loaded ? '' : !reserve ? (totals.support ? `${format(totals.support)} consumidas sem reserva` : 'Sem reserva configurada') : comparison.balance > 0 ? `${format(comparison.balance)} restantes · ${percent(comparison.available)} da reserva disponível` : comparison.balance === 0 ? 'Reserva totalmente consumida' : `${format(-comparison.balance)} acima da reserva · Excesso de ${percent(comparison.excess)}`;
    const names = result.developers.map(dev => dev.name);
    if (!names.includes(state.developer)) state.developer = '';
    el('cons-developer').replaceChildren(new Option('Todos os desenvolvedores',''), ...names.map(name => new Option(name,name)));
    el('cons-developer').value = state.developer;
    const displayed = result.developers.filter(dev => !state.developer || dev.name === state.developer);
    el('cons-body').replaceChildren();
    for (const [index,dev] of displayed.entries()) {
      const tr = document.createElement('tr'), expanded = state.expanded.has(dev.name);
      const initials = dev.name.split(/\s+/).map(part => part[0]).slice(0,2).join('').toLocaleUpperCase('pt-BR');
      tr.innerHTML = `<td><button type="button" class="cap-dev-button cons-dev" aria-expanded="${expanded}"><span class="cap-avatar cap-avatar-${index%4}">${escape(initials)}</span><span class="cap-dev-name" title="${escape(dev.name)}">${escape(dev.name)}</span><span class="cap-chevron" aria-hidden="true">${expanded ? '⌄' : '›'}</span></button></td>${cells(dev)}`;
      tr.querySelector('button').onclick = () => { expanded ? state.expanded.delete(dev.name) : state.expanded.add(dev.name); render(); };
      el('cons-body').append(tr);
      if (expanded) { const detail = document.createElement('tr'); detail.className = 'cons-detail'; detail.innerHTML = `<td colspan="5">${details(dev)}</td>`; el('cons-body').append(detail); }
    }
    if (!displayed.length) el('cons-body').innerHTML = '<tr><td colspan="5">Nenhum item atribuível individualmente nesta sprint.</td></tr>';
    const shown = displayed.reduce((sum,dev) => ({sprint:sum.sprint+dev.sprint,support:sum.support+dev.support,total:sum.total+dev.total,tasks:[...sum.tasks,...dev.tasks]}),emptyTotals());
    el('cons-foot').innerHTML = `<tr><th>${state.developer ? 'Total dos desenvolvedores exibidos' : 'Total atribuível aos desenvolvedores'}</th>${cells(shown)}</tr>`;
    el('cons-unassigned-summary').textContent = `Sprint ${format(result.unassigned.sprint)} · Suporte ${format(result.unassigned.support)} · Total ${format(result.unassigned.total)} · ${result.unassigned.tasks.length} item(ns)`;
    el('cons-unassigned').innerHTML = result.unassigned.tasks.length ? details(result.unassigned,true) : 'Nenhum item sem atribuição individual.';
    el('cons-reconciliation').textContent = `Reconciliação do time: ${format(totals.total)} executadas = ${format(result.attributed.total)} atribuídas aos devs + ${format(result.unassigned.total)} sem atribuição individual.`;
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
    if (location.hash === '#consumption') navigate('consumption');
  });
  return {enter,leave,onDataUpdated,onError,onLoading,onSyncComplete,model,reserveModel,shares,format,bar};
})();
