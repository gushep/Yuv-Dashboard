/* Capacity usa os dados já carregados pelo dashboard; cálculos internos em minutos. */
window.Capacity = (() => {
  const DEFAULT_MINUTES = 80 * 60;
  const state = { sprint: null, userSelectedSprint: false, developer: 'all', expanded: new Set(), overrides: {}, loadedOverrides: false, error: '', active: false, loading: false, wasCollapsed: false };
  const el = id => document.getElementById(id);
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const normalized = value => String(value ?? '').trim().toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const people = value => String(value ?? '').split(',').map(name => name.trim()).filter(Boolean);
  const planning = value => {
    const text = normalized(value);
    return text === 'planejada' || text === 'planejado' ? 'planned'
      : text === 'nao planejado' || text === 'nao planejada' ? 'unplanned' : 'unknown';
  };
  function minutes(value) {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'number') return Number.isFinite(value) && value >= 0 ? Math.round(value * 60) : null;
    const text = String(value).trim().toLocaleLowerCase('pt-BR');
    let match = text.match(/^(\d+)\s*h\s*(\d{1,2})?\s*m?$/);
    if (match) return Number(match[1]) * 60 + Number(match[2] || 0);
    match = text.match(/^(\d{1,2})\s*m$/);
    if (match) return Number(match[1]);
    match = text.match(/^(\d+):(\d{2})$/);
    if (match && Number(match[2]) < 60) return Number(match[1]) * 60 + Number(match[2]);
    const decimal = Number(text.replace(',', '.'));
    return Number.isFinite(decimal) && decimal >= 0 ? Math.round(decimal * 60) : null;
  }
  function formatMinutes(value) {
    if (value === null) return '—';
    const sign = value < 0 ? '-' : '';
    const abs = Math.abs(Math.round(value));
    const hours = Math.floor(abs / 60), mins = abs % 60;
    return `${sign}${hours}h${mins ? String(mins).padStart(2, '0') : ''}`;
  }
  const formatPct = value => value === null ? '—' : `${Math.round(value)}%`;
  const safeUrl = value => {
    try { const url = new URL(value); return url.protocol === 'https:' && (url.hostname === 'monday.com' || url.hostname.endsWith('.monday.com')) ? url.href : ''; }
    catch { return ''; }
  };
  function todaySaoPaulo(now = new Date()) {
    const parts = new Intl.DateTimeFormat('en-US', {timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(now);
    const part = type => parts.find(item => item.type === type)?.value;
    return `${part('year')}-${part('month')}-${part('day')}`;
  }
  function weekdays(from, to) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(from || '') || !/^\d{4}-\d{2}-\d{2}$/.test(to || '') || from > to) return null;
    let count = 0;
    for (let day = Date.parse(`${from}T00:00:00Z`), end = Date.parse(`${to}T00:00:00Z`); day <= end; day += 86400000) {
      const weekday = new Date(day).getUTCDay();
      if (weekday >= 1 && weekday <= 5) count++;
    }
    return count;
  }
  function elapsedMinutes(totalMinutes, dates, today) {
    if (!dates?.start || !dates?.end) return null;
    const all = weekdays(dates.start, dates.end);
    if (!all) return null;
    if (today < dates.start) return 0;
    if (today >= dates.end) return totalMinutes;
    return Math.round(totalMinutes * weekdays(dates.start, today) / all);
  }
  const isDone = status => window.Bombeiro?.statusBucket(status) === 'done';
  function model(allRows, sprint, dates, overrides = {}, today = todaySaoPaulo()) {
    const sprintById = new Map();
    for (const row of allRows) if (row.Id) {
      const key = String(row.Id);
      if (!sprintById.has(key)) sprintById.set(key, new Set());
      sprintById.get(key).add(Number(row.Sprint));
    }
    const unique = new Map();
    allRows.forEach((row, index) => {
      if (Number(row.Sprint) === Number(sprint)) unique.set(row.Id ? `${row.Id}|${sprint}` : `snapshot-${index}`, row);
    });
    const groups = new Map(), pending = [], unassigned = [];
    for (const row of unique.values()) {
      const owners = people(row.Responsavel);
      if (!owners.length) { unassigned.push(row); continue; }
      const reasons = [];
      if (owners.length > 1) reasons.push('vários responsáveis; sem apontamentos individuais');
      if (row.Id && sprintById.get(String(row.Id))?.size > 1) reasons.push('card em várias sprints; tempo total não separado por sprint');
      if (planning(row.Planejada) === 'unknown') reasons.push('planejamento não informado');
      if (reasons.length) { pending.push({row, reasons}); continue; }
      const owner = owners[0];
      if (!groups.has(owner)) groups.set(owner, []);
      groups.get(owner).push(row);
    }
    const developers = [...groups.entries()].map(([name, rows]) => {
      const key = `${sprint}|${name}`;
      const capacity = Number.isInteger(overrides[key]) ? overrides[key] : DEFAULT_MINUTES;
      const elapsed = elapsedMinutes(capacity, dates, today);
      const result = {name, rows, capacity, elapsed, plannedEstimate:0, completedEstimate:0, missingEstimate:0,
        realPlanned:0, realUnplanned:0, realSupport:0, otherUnplanned:0, registered:0, knownReal:0, missingReal:0,
        knownPlanned:0, knownSupport:0, knownOther:0};
      for (const row of rows) {
        const category = planning(row.Planejada);
        const estimate = minutes(row.EstH), real = minutes(row.RealH);
        if (category === 'planned') {
          if (estimate === null) result.missingEstimate++;
          else { result.plannedEstimate += estimate; if (isDone(row.Status)) result.completedEstimate += estimate; }
        }
        if (real === null) { result.missingReal++; continue; }
        result.knownReal++;
        result.registered += real;
        if (category === 'planned') { result.realPlanned += real; result.knownPlanned++; }
        else if (normalized(row.Tipo) === 'suporte') { result.realSupport += real; result.knownSupport++; }
        else { result.otherUnplanned += real; result.knownOther++; }
      }
      result.realUnplanned = result.realSupport + result.otherUnplanned;
      result.utilization = result.knownReal && elapsed > 0 ? result.registered / elapsed * 100 : null;
      result.delivery = result.plannedEstimate > 0 ? result.completedEstimate / result.plannedEstimate * 100 : null;
      result.supportImpact = result.registered > 0 ? result.realSupport / result.registered * 100 : null;
      return result;
    }).sort((a,b) => a.name.localeCompare(b.name, 'pt-BR'));
    return {developers, pending, unassigned, selectedCount:unique.size};
  }
  function dateLabel(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('pt-BR',{day:'2-digit',month:'short',year:'numeric',timeZone:'UTC'}).format(new Date(`${value}T12:00:00Z`));
  }
  function badge(text, kind) { return `<span class="cap-badge ${kind}">${escapeHtml(text)}</span>`; }
  function detail(dev) {
    const tr = document.createElement('tr');
    tr.className = 'cap-detail-row';
    const td = document.createElement('td');
    td.className = 'cap-detail-cell';
    td.colSpan = 11;
    const lines = dev.rows.map(task => {
      const category = planning(task.Planejada);
      const name = escapeHtml(task.Nome || 'Sem título');
      const url = safeUrl(task.Url);
      const taskName = url ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${name} ↗</a>` : name;
      const real = minutes(task.RealH), estimate = minutes(task.EstH);
      return `<tr><td>${taskName}</td><td>${badge(category === 'planned' ? 'Planejado' : 'Não planejado',category === 'planned' ? 'plan':'unplan')}</td><td>${badge(task.Tipo || 'Não informado',normalized(task.Tipo)==='suporte'?'support':'other')}</td><td>${badge(task.Status || 'Não informado',isDone(task.Status)?'done':'unknown')}</td><td>${estimate === null ? 'Sem estimativa' : formatMinutes(estimate)}</td><td>${real === null ? 'Sem apontamento' : formatMinutes(real)}</td></tr>`;
    }).join('');
    td.innerHTML = `<div class="cap-detail"><h3>${escapeHtml(dev.name)} · itens da sprint</h3><div class="cap-detail-scroll"><table class="cap-detail-table"><thead><tr><th>Tarefa</th><th>Planejamento</th><th>Tipo</th><th>Status</th><th>Horas previstas</th><th>Tempo real</th></tr></thead><tbody>${lines}</tbody><tfoot><tr><td colspan="4">Total dos itens atribuídos</td><td>${formatMinutes(dev.plannedEstimate)}${dev.missingEstimate ? ` · ${dev.missingEstimate} sem estimativa` : ''}</td><td>${dev.knownReal ? formatMinutes(dev.registered) : 'Sem apontamento'}</td></tr></tfoot></table></div><p class="cap-detail-summary">Real planejado: ${formatMinutes(dev.realPlanned)} · Real não planejado: ${formatMinutes(dev.realUnplanned)} (suporte: ${formatMinutes(dev.realSupport)}; outros: ${formatMinutes(dev.otherUnplanned)}) · Registradas: ${formatMinutes(dev.registered)}${dev.missingReal ? ` · ${dev.missingReal} item(ns) sem apontamento` : ''}</p></div>`;
    tr.append(td);
    return tr;
  }
  function percentBar(value, type) {
    if (value === null) return '<span title="Sem denominador ou sem apontamentos suficientes">—</span>';
    const fill = type === 'delivery' ? 'cap-delivery' : value > 100 ? 'cap-util-high' : value === 100 ? 'cap-util-full' : 'cap-util-low';
    const label = type === 'delivery' ? 'cap-blue' : value > 100 ? 'cap-over' : '';
    return `<div class="cap-meter"><span class="cap-track"><span class="cap-fill ${fill}" style="width:${Math.max(0,Math.min(100,value))}%"></span></span><span class="cap-percent ${label}">${formatPct(value)}</span></div>`;
  }
  function renderExtra(id, title, items, withReason = false) {
    const node = el(id);
    node.hidden = !items.length;
    if (!items.length) return;
    const list = items.map(item => {
      const row = withReason ? item.row : item;
      const why = withReason ? ` — ${item.reasons.join('; ')}` : '';
      const estimate = minutes(row.EstH), real = minutes(row.RealH);
      return `<li>${escapeHtml(row.Nome || 'Sem título')} (${escapeHtml(row.Responsavel || 'sem responsável')})${escapeHtml(why)} · Estimativa: ${estimate === null ? 'sem estimativa' : formatMinutes(estimate)} · Real: ${real === null ? 'sem apontamento' : formatMinutes(real)}</li>`;
    }).join('');
    node.innerHTML = `<details><summary>${title} · ${items.length} item(ns)</summary><ul>${list}</ul></details>`;
  }
  function render() {
    if (!el('page-capacity')) return;
    const sprints = [...new Set(DATA.map(row => Number(row.Sprint)).filter(number => Number.isInteger(number) && number > 0))].sort((a,b)=>b-a);
    const sprintSelect = el('cap-sprint');
    if (!sprints.includes(state.sprint)) state.sprint = sprints[0] ?? null;
    sprintSelect.replaceChildren(...sprints.map(number => new Option(`Sprint ${number}`,String(number))));
    if (state.sprint !== null) sprintSelect.value = String(state.sprint);
    const dates = window.MONDAY_SPRINT_DATES?.[state.sprint] || null;
    el('cap-dates').textContent = dates ? `${dateLabel(dates.start)} – ${dateLabel(dates.end)}` : 'Datas da sprint não disponíveis';
    const result = state.sprint === null ? {developers:[],pending:[],unassigned:[]} : model(DATA,state.sprint,dates,state.overrides);
    const select = el('cap-developer');
    const names = result.developers.map(dev => dev.name);
    if (state.developer !== 'all' && !names.includes(state.developer)) state.developer = 'all';
    select.replaceChildren(new Option('Todos os desenvolvedores','all'),...names.map(name => new Option(name,name)));
    select.value = state.developer;
    const shown = state.developer === 'all' ? result.developers : result.developers.filter(dev => dev.name === state.developer);
    const tbody = el('cap-body');
    tbody.replaceChildren();
    for (const [index, dev] of shown.entries()) {
      const tr = document.createElement('tr');
      const initials = dev.name.split(/\s+/).map(part => part[0]).slice(0,2).join('').toUpperCase();
      const balancePlan = dev.capacity-dev.plannedEstimate, balanceNow = dev.capacity-dev.registered;
      const realTip = `Real planejado: ${formatMinutes(dev.realPlanned)} · Real não planejado: ${formatMinutes(dev.realUnplanned)} (suporte: ${formatMinutes(dev.realSupport)}; outros: ${formatMinutes(dev.otherUnplanned)})${dev.missingReal ? ` · ${dev.missingReal} sem apontamento` : ''}`;
      const unplannedParts = [dev.knownSupport ? `Suporte ${formatMinutes(dev.realSupport)}` : '',dev.knownOther ? `Outros ${formatMinutes(dev.otherUnplanned)}` : ''].filter(Boolean).join(' · ');
      const unplannedCell = dev.knownSupport || dev.knownOther ? `${formatMinutes(dev.realUnplanned)}<br><small>${unplannedParts}</small>` : '—';
      const utilTip = `Registradas: ${formatMinutes(dev.registered)} ÷ capacidade até hoje: ${formatMinutes(dev.elapsed)}. ${realTip}. Segunda a sexta; hoje conta como dia útil completo; sem feriados configurados.`;
      const deliveryTip = `Estimativas concluídas: ${formatMinutes(dev.completedEstimate)} ÷ estimativas planejadas: ${formatMinutes(dev.plannedEstimate)}. ${dev.missingEstimate} item(ns) planejado(s) sem estimativa.${dev.plannedEstimate ? '' : ' Denominador zero: indicador indisponível.'}`;
      tr.innerHTML = `<td><button class="cap-dev-button" type="button" aria-expanded="${state.expanded.has(dev.name)}"><span class="cap-avatar cap-avatar-${index%4}">${escapeHtml(initials)}</span><span class="cap-dev-name" title="${escapeHtml(dev.name)}">${escapeHtml(dev.name)}</span><span class="cap-chevron">${state.expanded.has(dev.name)?'⌄':'›'}</span></button></td><td><input class="cap-hours-input" type="number" min="0" max="200" step="0.25" value="${dev.capacity/60}" aria-label="Capacidade em horas de ${escapeHtml(dev.name)} na sprint ${state.sprint}" title="Horas decimais: 1,5 equivale a 1h30">h</td><td>${formatMinutes(dev.plannedEstimate)}${dev.missingEstimate ? `<br><small title="Itens planejados sem estimativa">${dev.missingEstimate} sem estimativa</small>` : ''}</td><td class="${balancePlan<0?'cap-negative':''}">${formatMinutes(balancePlan)}</td><td>${dev.knownPlanned?formatMinutes(dev.realPlanned):'—'}</td><td>${unplannedCell}</td><td title="${escapeHtml(realTip)}">${dev.knownReal?formatMinutes(dev.registered):'—'}</td><td class="${balanceNow<0?'cap-negative':''}">${dev.knownReal?formatMinutes(balanceNow):'—'}</td><td title="${escapeHtml(utilTip)}">${percentBar(dev.utilization,'util')}</td><td title="${escapeHtml(deliveryTip)}">${percentBar(dev.delivery,'delivery')}${dev.missingEstimate ? `<small>${dev.missingEstimate} sem estimativa</small>` : ''}</td><td title="${escapeHtml(dev.registered ? `${formatMinutes(dev.realSupport)} ÷ ${formatMinutes(dev.registered)}` : 'Sem horas registradas: denominador zero')}"><span class="cap-impact">${formatPct(dev.supportImpact)}</span></td>`;
      tr.querySelector('.cap-dev-button').addEventListener('click', () => {
        state.expanded.has(dev.name) ? state.expanded.delete(dev.name) : state.expanded.add(dev.name);
        render();
      });
      tr.querySelector('.cap-hours-input').addEventListener('change', event => saveCapacity(dev.name,event.target.value));
      tbody.append(tr);
      if (state.expanded.has(dev.name)) tbody.append(detail(dev));
    }
    if (!shown.length) {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td colspan="11" class="cap-empty">${state.sprint === null ? 'Nenhuma sprint disponível.' : 'Nenhum desenvolvedor com itens atribuídos nesta sprint.'}</td>`;
      tbody.append(tr);
    }
    renderExtra('cap-pending','Pendências de atribuição',result.pending,true);
    renderExtra('cap-unassigned','Sem responsável',result.unassigned);
    const standardElapsed = elapsedMinutes(DEFAULT_MINUTES,dates,todaySaoPaulo());
    el('cap-reference').textContent = `Referência: ${formatMinutes(DEFAULT_MINUTES)} por dev · até hoje: ${formatMinutes(standardElapsed)}`;
    el('cap-calendar').textContent = 'Dias úteis: segunda a sexta; hoje completo';
    el('cap-calendar').title = 'Capacidade até hoje = capacidade total × dias úteis transcorridos ÷ dias úteis totais. Inclui o dia atual completo no fuso America/Sao_Paulo; não há feriados configurados.';
    const updated = window.MONDAY_LAST_SYNC ? `Última atualização: ${new Date(window.MONDAY_LAST_SYNC).toLocaleString('pt-BR')}` : 'Base incorporada disponível';
    el('cap-last-sync').textContent = state.loading ? `Buscando dados atuais do Monday... ${updated}` : updated;
    const warnings = [];
    if (!dates) warnings.push('Datas da sprint indisponíveis: utilização até hoje não pode ser calculada.');
    if (!window.MONDAY_LIVE_SPRINTS?.has(state.sprint)) warnings.push('Esta sprint usa a base incorporada; ela não distingue ausência de tempo de zero nem contém IDs para auditar duplicações.');
    else warnings.push('Tempo Real é uma coluna numérica de total do card, sem histórico de apontamentos por pessoa ou sprint. As horas exibidas são provisórias; cards compartilhados ou em várias sprints estão fora dos totais individuais.');
    if (result.pending.length) warnings.push(`${result.pending.length} card(s) em Pendências de atribuição, fora dos totais individuais.`);
    if (result.unassigned.length) warnings.push(`${result.unassigned.length} card(s) sem responsável, fora dos totais individuais.`);
    const missing = result.developers.reduce((sum,dev)=>sum+dev.missingEstimate,0);
    if (missing) warnings.push(`${missing} item(ns) planejado(s) sem estimativa: entrega do planejado considera apenas estimativas informadas.`);
    el('cap-warning').textContent = warnings.join(' ');
    el('cap-quality').hidden = !warnings.length;
    el('cap-warning-summary').textContent = `Qualidade dos dados: ${window.MONDAY_LIVE_SPRINTS?.has(state.sprint) ? 'Tempo Real por card' : 'base incorporada'}${result.pending.length ? ` · ${result.pending.length} pendência(s)` : ''}${result.unassigned.length ? ` · ${result.unassigned.length} sem responsável` : ''}${missing ? ` · ${missing} sem estimativa` : ''} · ver detalhes`;
    el('cap-error').textContent = state.error;
    el('cap-error').hidden = !state.error;
  }
  async function loadOverrides() {
    if (state.loadedOverrides || location.protocol === 'file:') return;
    try {
      const result = await mondayRequest('/api/capacity');
      state.overrides = result.overrides || {};
      state.loadedOverrides = true;
      render();
    } catch (error) { state.error = `Não foi possível carregar as capacidades salvas: ${error.message}`; render(); }
  }
  async function saveCapacity(developer, hoursText) {
    const hours = Number(String(hoursText).replace(',','.'));
    if (!Number.isFinite(hours) || hours < 0 || hours > 200) { state.error = 'Informe uma capacidade entre 0h e 200h.'; render(); return; }
    const value = Math.round(hours * 60);
    try {
      const result = await mondayRequest('/api/capacity',{sprint:state.sprint,developer,minutes:value});
      state.overrides = result.overrides || {};
      state.error = '';
      render();
    } catch (error) { state.error = `Não foi possível salvar a capacidade: ${error.message}`; render(); }
  }
  function enter(sprintHint = null) {
    if (!state.active) state.wasCollapsed = document.body.classList.contains('sidebar-collapsed');
    state.active = true;
    document.body.classList.add('capacity-view','sidebar-collapsed');
    if (Number.isInteger(sprintHint)) { state.sprint = sprintHint; state.userSelectedSprint = true; }
    if (!window.MONDAY_LAST_SYNC && location.protocol !== 'file:') state.loading = true;
    render();
    loadOverrides();
    if (typeof mondayReady !== 'undefined' && mondayReady && !window.MONDAY_LAST_SYNC) syncMonday(false);
  }
  function leave() {
    state.active = false;
    document.body.classList.remove('capacity-view');
    if (!state.wasCollapsed) document.body.classList.remove('sidebar-collapsed');
  }
  function onDataUpdated(result) {
    window.MONDAY_SPRINT_DATES = Object.fromEntries((result.sprints || []).map(item => [Number(item.number),item]));
    window.MONDAY_LAST_SYNC = result.syncedAt;
    window.MONDAY_LIVE_SPRINTS = new Set(result.rows.map(row => Number(row.Sprint)));
    state.loading = false;
    if (!state.userSelectedSprint) state.sprint = null;
    state.error = '';
    if (state.active) render();
  }
  function onLoading() { state.loading = true; if (state.active) render(); }
  function onError(message) { state.loading = false; state.error = `Falha ao atualizar os dados: ${message}. Exibindo os últimos dados disponíveis.`; if (state.active) render(); }
  document.addEventListener('DOMContentLoaded', () => {
    el('cap-sprint').addEventListener('change', event => { state.sprint = Number(event.target.value); state.userSelectedSprint = true; state.expanded.clear(); render(); });
    el('cap-developer').addEventListener('change', event => { state.developer = event.target.value; render(); });
  });
  return {enter,leave,render,onLoading,onDataUpdated,onError,model,minutes,formatMinutes,percentBar,weekdays,elapsedMinutes,todaySaoPaulo};
})();
