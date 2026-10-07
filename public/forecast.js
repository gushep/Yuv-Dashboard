/* Previsibilidade usa o mesmo snapshot e a mesma sincronização do Capacity. */
window.Forecast = (() => {
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const normalize = value => String(value ?? '').trim().toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const owners = row => [...new Set(String(row.Responsavel || '').split(',').map(name => name.trim()).filter(Boolean))];
  function blocker(row) {
    const text = String(row.Impedimento || '').trim();
    if (text && !['nao','nenhum','sem impedimento','sem bloqueio','false','0','desbloqueado'].includes(normalize(text))) return text;
    return ['parado','bloqueado','impedido','limitacao externa'].includes(normalize(row.Status)) ? `Status: ${row.Status}` : '';
  }
  const state = {active:false, sprint:null, developer:'all', reference:null, expanded:new Set(), overrides:{}, loaded:false, error:'', syncError:'', loading:false};
  const labels = {over:'Acima da capacidade', limit:'No limite', unknown:'Sem previsão', within:'Dentro da capacidade', low:'Baixa carga prevista', empty:'Sem demandas abertas'};
  function duration(value) {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    const text = String(value).trim().toLowerCase();
    let match = text.match(/^(\d+)h(?:\s*(\d{1,2})m?)?$/);
    if (match) return Number(match[2] || 0) < 60 ? Number(match[1])*60 + Number(match[2] || 0) : null;
    match = text.match(/^(\d+)m$/);
    if (match) return Number(match[1]);
    match = text.match(/^(\d+):(\d{2})$/);
    if (match) return Number(match[2]) < 60 ? Number(match[1])*60 + Number(match[2]) : null;
    const hours = Number(text.replace(',', '.'));
    return Number.isFinite(hours) && hours >= 0 ? Math.round(hours*60) : null;
  }
  function format(value) {
    if (value === null || value === undefined) return 'Não definido';
    const minutes = Math.abs(Math.round(value));
    return `${Math.floor(minutes/60)}h${minutes%60 ? ` ${String(minutes%60).padStart(2,'0')}min` : ''}`;
  }
  const dateValid = value => /^\d{4}-\d{2}-\d{2}$/.test(value || '') && !Number.isNaN(Date.parse(value)) && new Date(`${value}T00:00:00Z`).toISOString().slice(0,10) === value;
  function workingDays(start, end, holidays = []) {
    if (!dateValid(start) || !dateValid(end) || start > end) return null;
    const result = [], excluded = new Set(holidays);
    for (let day = Date.parse(`${start}T00:00:00Z`), last = Date.parse(`${end}T00:00:00Z`); day <= last; day += 86400000) {
      const date = new Date(day), key = date.toISOString().slice(0,10);
      if (date.getUTCDay() >= 1 && date.getUTCDay() <= 5 && !excluded.has(key)) result.push(key);
    }
    return result;
  }
  function capacity(total, dates, reference, holidays = []) {
    const all = workingDays(dates?.start, dates?.end, holidays);
    if (!all || !dateValid(reference) || total === null) return {remaining:null, days:null, totalDays:null};
    const future = all.filter(day => day > reference);
    return {remaining:Math.max(0, Math.round(all.length ? total*future.length/all.length : 0)), days:future.length, totalDays:all.length};
  }
  function classify(known, available, missing, count) {
    if (!count) return 'empty';
    if (available === null) return 'unknown';
    if (known > available) return 'over';
    if (missing) return 'unknown';
    if (available === 0) return 'unknown';
    const percent = known/available*100;
    return percent < 50 ? 'low' : percent < 90 ? 'within' : 'limit';
  }
  function balance(dev) {
    if (dev.status === 'empty') return 'Sem demandas abertas';
    if (dev.available !== null && dev.known > dev.available) return `${dev.missing ? 'pelo menos ' : ''}${format(dev.known-dev.available)} a mais`;
    if (dev.missing || dev.available === null) return 'Revisar estimativas';
    return dev.available === dev.known ? 'Sem folga' : `${format(dev.available-dev.known)} de folga`;
  }
  function model(rows, sprint, dates, overrides = {}, reference = window.Capacity.todaySaoPaulo(), holidays = []) {
    const seenSprints = new Map(), unique = new Map(), groups = new Map(), unassigned = [];
    rows.forEach((row,index) => {
      if (row.Id && !row.AsOf) { const set = seenSprints.get(String(row.Id)) || new Set(); set.add(Number(row.Sprint)); seenSprints.set(String(row.Id),set); }
      if (Number(row.Sprint) === Number(sprint)) unique.set(row.Id ? String(row.Id) : `snapshot-${index}`, row);
    });
    for (const row of unique.values()) {
      const people = owners(row), done = !window.SprintRules.isOpen(row.Status);
      if (!people.length && !done) unassigned.push(row);
      for (const name of people) {
        if (!groups.has(name)) groups.set(name,[]);
        if (done) continue;

        const estimate = duration(row.EstH), real = duration(row.RealH), revised = duration(row.RemainingH);
        const multipleSprints = row.Id && seenSprints.get(String(row.Id))?.size > 1;
        const reasons = [];
        if (people.length > 1) reasons.push('Atribuição compartilhada: o Monday não fornece a parcela por pessoa; esforço fora do total até corrigir a atribuição na origem.');
        if (multipleSprints) reasons.push('Card em várias sprints: tempo acumulado não separado por sprint. Corrija o escopo e o esforço restante no Monday.');
        let remainingEffort = null, source = '', issue = '';
        if (people.length > 1 || multipleSprints) issue = 'Revisar esforço restante e atribuição no Monday';
        else if (revised !== null) { remainingEffort = revised; source = 'Esforço restante revisado no Monday'; }
        else if (estimate === null || estimate <= 0) issue = 'Sem estimativa válida';
        else if (!String(row.Status || '').trim()) issue = 'Status ausente: revisar esforço restante';
        else if (real !== null && real >= estimate) issue = 'Revisar esforço restante: estimativa consumida';
        else if (window.SprintRules.isNotStarted(row.Status)) { remainingEffort = estimate; source = 'Estimativa total · tarefa não iniciada'; }
        else if (real === null || !row.Id) issue = 'Tempo real ausente ou não auditável em tarefa em andamento';
        else { remainingEffort = estimate-real; source = 'Calculada provisoriamente: estimativa − tempo real; não mede progresso exato'; }
        groups.get(name).push({row, estimate, real, remainingEffort, source, issue, reasons});
      }
    }
    const developers = [...groups].map(([name,tasks]) => {
      const key = `${sprint}|${name}`, total = Number.isInteger(overrides[key]) ? overrides[key] : window.SprintRules.capacityMinutes();
      const calendar = capacity(total, dates, reference, holidays);
      const known = tasks.reduce((sum,task) => sum + (task.remainingEffort ?? 0),0), missing = tasks.filter(task => task.remainingEffort === null).length;
      const available = calendar.remaining, status = classify(known,available,missing,tasks.length);
      return {name,tasks,total,calendar,known,missing,available,status};
    });
    const rank = {over:0,limit:1,unknown:2,within:3,low:4,empty:5};
    developers.sort((a,b) => rank[a.status]-rank[b.status] || (b.known-(b.available ?? b.known))-(a.known-(a.available ?? a.known)) || a.name.localeCompare(b.name,'pt-BR'));
    return {developers,unassigned};
  }
  const badge = status => `<span class="forecast-badge ${status}"><span aria-hidden="true">●</span> ${labels[status]}</span>`;
  function taskLink(row) {
    let url = '';
    try { const parsed = new URL(row.Url); if (parsed.protocol === 'https:' && (parsed.hostname === 'monday.com' || parsed.hostname.endsWith('.monday.com'))) url = parsed.href; } catch {}
    return url ? `<a href="${escape(url)}" target="_blank" rel="noopener noreferrer">${escape(row.Nome)} ↗</a>` : escape(row.Nome || 'Sem título');
  }
  function summary(dev) {
    if (dev.available === null) return 'Datas da sprint indisponíveis: configure o cronograma no Monday para calcular a capacidade futura.';
    return `${dev.missing ? 'Pelo menos ' : ''}${format(dev.known)} de demanda para ${format(dev.available)} disponíveis: ${balance(dev)}.${dev.missing ? ` Previsão incompleta: ${dev.missing} item(ns) pendente(s) de revisão.` : ''}`;
  }
  function detail(dev) {
    const tr = document.createElement('tr'); tr.className = 'forecast-detail';
    const td = document.createElement('td'); td.colSpan = 6;
    td.innerHTML = `<div class="forecast-detail-box"><h3>${escape(dev.name)} · Tarefas que faltam concluir</h3><p class="forecast-summary ${dev.status}">${escape(summary(dev))}</p><div class="cap-detail-scroll"><table class="cap-detail-table"><thead><tr><th>Tarefa</th><th>Status</th><th>Planejamento / Tipo</th><th>Estimativa total</th><th>Tempo real registrado</th><th>Horas para concluir / Origem</th></tr></thead><tbody>${dev.tasks.map(task => `<tr class="${task.issue ? 'forecast-task-warning' : ''}"><td>${taskLink(task.row)}${blocker(task.row) ? `<p class="forecast-blocker">Impedimento: ${escape(blocker(task.row))}</p>` : ''}${task.reasons.length ? `<small>${escape(task.reasons.join(' '))}</small>` : ''}</td><td>${escape(task.row.Status || 'Não informado')}</td><td>${escape(task.row.Planejada || 'Não informado')} / ${escape(task.row.Tipo || 'Não informado')}</td><td>${format(task.estimate)}</td><td>${format(task.real)}</td><td><strong>${task.remainingEffort === null ? escape(task.issue) : format(task.remainingEffort)}</strong><small>${escape(task.source)}</small>${task.issue ? '<small>Corrigir no Monday e atualizar os dados.</small>' : ''}</td></tr>`).join('')}</tbody><tfoot><tr><td colspan="5">Total ${dev.missing ? 'conhecido' : ''} · ${dev.tasks.length} tarefas abertas</td><td>${format(dev.known)}${dev.missing ? ` · ${dev.missing} pendente(s)` : ''}</td></tr></tfoot></table></div><p class="cap-note">Estimativas vêm do Monday. Nas tarefas em andamento, estimativa menos tempo real é uma aproximação. O apontamento exibido é do card e pode ser histórico ou compartilhado.</p></div>`;
    tr.append(td); return tr;
  }
  function render() {
    if (!el('page-forecast')) return;
    const sprints = [...new Set(DATA.map(row => Number(row.Sprint)).filter(value => Number.isInteger(value) && value>0))].sort((a,b)=>b-a);
    if (!sprints.includes(state.sprint)) state.sprint = sprints[0] ?? null;
    el('forecast-sprint').replaceChildren(...sprints.map(value => new Option(`Sprint ${value}`,value)));
    el('forecast-sprint').value = state.sprint;
    const dates = window.MONDAY_SPRINT_DATES?.[state.sprint];
    el('forecast-dates').textContent = dates ? `${dates.start.split('-').reverse().join('/')} – ${dates.end.split('-').reverse().join('/')}` : 'Datas da sprint não disponíveis';
    state.reference ||= window.Capacity.todaySaoPaulo(); el('forecast-reference').value = state.reference;
    const result = model(DATA,state.sprint,dates,state.overrides,state.reference);
    const names = result.developers.map(dev=>dev.name);
    if (!names.includes(state.developer)) state.developer = 'all';
    el('forecast-developer').replaceChildren(new Option('Todos os desenvolvedores','all'),...names.map(name=>new Option(name,name)));
    el('forecast-developer').value = state.developer;
    const shown = result.developers.filter(dev=>state.developer==='all' || dev.name===state.developer);
    const tbody = el('forecast-body'); tbody.replaceChildren();
    if (state.loaded) for (const [index,dev] of shown.entries()) {
      const tr = document.createElement('tr'); tr.className = 'forecast-dev-row';
      const expanded = state.expanded.has(dev.name);
      const ratio = dev.available>0 ? `${(dev.known/dev.available*100).toFixed(1)}%${dev.missing ? ' (mínimo conhecido)' : ''}` : 'Sem divisão: capacidade zero ou indisponível';
      const blockers = dev.tasks.filter(task=>blocker(task.row)).length;
      const initials = dev.name.split(/\s+/).map(part=>part[0]).slice(0,2).join('');
      tr.innerHTML = `<td><button class="cap-dev-button" type="button" aria-expanded="${expanded}" aria-label="${expanded?'Recolher':'Expandir'} tarefas de ${escape(dev.name)}"><span class="cap-chevron">${expanded?'⌄':'›'}</span><span class="cap-avatar cap-avatar-${index%4}">${escape(initials)}</span><span>${escape(dev.name)}</span></button>${blockers ? `<small class="forecast-blocker">${blockers} impedimento(s) conhecido(s)</small>` : ''}</td><td title="Carga futura = horas para concluir ÷ capacidade restante: ${escape(ratio)}. Ocupação futura, não produtividade ou probabilidade de entrega.">${badge(dev.status)}</td><td>${dev.tasks.length}${dev.missing ? `<small>${dev.missing} pendente(s) de revisão</small>` : ''}</td><td>${dev.missing ? `Pelo menos ${format(dev.known)}` : format(dev.known)}</td><td title="${escape(`Capacidade total ${format(dev.total)} ÷ ${dev.calendar.totalDays ?? '?'} dias úteis × ${dev.calendar.days ?? '?'} dias futuros`)}">${format(dev.available)}</td><td class="forecast-balance ${dev.status}">${escape(balance(dev))}</td>`;
      const toggle = () => { expanded ? state.expanded.delete(dev.name) : state.expanded.add(dev.name); render(); };
      tr.addEventListener('click', toggle);
      tbody.append(tr); if (expanded) tbody.append(detail(dev));
    }
    if (!state.loaded || !shown.length) tbody.innerHTML = `<tr><td colspan="6" class="cap-empty">${!state.loaded ? 'Aguardando configurações salvas para calcular a previsão.' : 'Nenhum desenvolvedor nesta sprint.'}</td></tr>`;
    const calendar = capacity(window.SprintRules.capacityMinutes(),dates,state.reference);
    el('forecast-cutoff').textContent = `Corte: fim de ${state.reference.split('-').reverse().join('/')} · ${calendar.days ?? '?'} dias úteis posteriores · America/Sao_Paulo`;
    el('forecast-last-sync').textContent = state.loading ? 'Atualizando dados do Monday…' : window.MONDAY_LAST_SYNC ? `Última atualização: ${new Date(window.MONDAY_LAST_SYNC).toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'})}` : 'Base incorporada · sem atualização do Monday';
    const warnings = ['Segunda a sexta; não há calendário de feriados ou calendário individual configurado. Corte exclui o dia de referência inteiro.','Nas tarefas em andamento, o cálculo usa estimativa menos tempo real compatível como aproximação. Esforço restante revisado no Monday tem prioridade. Tempo real ausente ou estimativa consumida em tarefa aberta exigem revisão. Cards compartilhados ou em várias sprints ficam pendentes para evitar atribuição incorreta.','Capacidade herdada do painel Capacity; reservas e ausências não estão disponíveis na integração atual e não são descontadas. Ajustes de esforço devem ser feitos no Monday.', 'A data de referência altera a capacidade futura; tarefas refletem a base atual, sem reconstruir histórico.'];
    if (!dates) warnings.push('Cronograma da sprint ausente: não é possível calcular a capacidade futura.');
    if (!window.MONDAY_LIVE_SPRINTS?.has(state.sprint)) warnings.push('Base incorporada sem IDs e sem distinção entre tempo ausente e zero: tarefas em andamento exigem atualização dos dados.');
    el('forecast-warning').textContent = warnings.join(' ');
    el('forecast-unassigned').hidden = !result.unassigned.length;
    el('forecast-unassigned').innerHTML = `<details><summary>Sem responsável · ${result.unassigned.length} tarefas abertas fora dos totais individuais</summary><ul>${result.unassigned.map(row=>`<li>${taskLink(row)} · ${escape(row.Tipo)} · ${escape(row.Status)}</li>`).join('')}</ul></details>`;
    const thresholds = {low:50,limit:90};
    el('forecast-legend').innerHTML = `${badge('low')} &lt;${thresholds.low}% ${badge('within')} ${thresholds.low}% a &lt;${thresholds.limit}% ${badge('limit')} ${thresholds.limit}% a 100% ${badge('over')} &gt;100% ${badge('unknown')} Dados insuficientes`;
    el('forecast-error').textContent = [state.error,state.syncError].filter(Boolean).join(' '); el('forecast-error').hidden = !(state.error || state.syncError);

    el('forecast-refresh').disabled = state.loading;
  }
  function fail(message) { state.error = message; render(); }
  async function load() {
    try { const result = await mondayRequest('/api/capacity'); state.overrides = result.overrides || {}; state.loaded = true; state.error = ''; render(); }
    catch (error) { state.loaded = false; fail(`Não foi possível carregar a configuração: ${error.message}`); }
  }
  function enter(hint = null) {
    state.active = true; if (Number.isInteger(hint)) state.sprint=hint;
    render(); load();
    if (typeof mondayReady !== 'undefined' && mondayReady) syncMonday(false);
  }
  function leave() { state.active = false; }
  function onDataUpdated() { state.syncError = ''; if (state.active) { render(); load(); } }
  function onLoading() { state.loading = true; if (state.active) render(); }
  function onError(message) { state.loading = false; state.syncError = `Falha ao atualizar: ${message}. Exibindo a última base disponível.`; if (state.active) render(); }
  function onSyncComplete() { state.loading = false; if (state.active) render(); }
  document.addEventListener('DOMContentLoaded', () => {
    el('forecast-sprint').onchange = event => { state.sprint = Number(event.target.value); state.expanded.clear(); render(); };
    el('forecast-developer').onchange = event => { state.developer = event.target.value; render(); };
    el('forecast-reference').onchange = event => { if (dateValid(event.target.value)) { state.reference = event.target.value; render(); } };
    el('forecast-refresh').onclick = () => syncMonday(true);
  });
  return {enter,leave,render,onDataUpdated,onLoading,onError,onSyncComplete,model,duration,format,capacity,workingDays,classify,balance};
})();
