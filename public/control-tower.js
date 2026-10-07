/* Torre de controle: trajetória, sinais, previsão e manobras da sprint calculados no back-end. */
window.ControlTower = (() => {
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const SEEN_KEY = 'yuv-seen-alerts';
  const STATUS = {
    green: ['Rota sob controle', 'Nenhum sinal pede manobra agora.'],
    yellow: ['Atenção: ajuste de rota recomendado', 'Há sinais amarelos; acompanhe antes que virem risco.'],
    red: ['Risco alto: manobra necessária', 'Há sinais vermelhos; decida as manobras abaixo.']
  };
  const LEVEL = {green:'Verde', yellow:'Amarelo', red:'Vermelho', unknown:'Sem dados'};
  const state = {active:false, sprint:null, data:null, loading:false, error:'', request:0, chart:null};

  const day = value => value ? value.split('-').reverse().slice(0, 2).join('/') : '—';
  const link = item => {
    const url = window.SprintPanel?.safeMondayUrl(item.url) || '';
    return url ? `<a href="${escape(url)}" target="_blank" rel="noopener noreferrer">${escape(item.name || 'Sem título')} ↗</a>` : escape(item.name || 'Sem título');
  };
  const age = item => item.knownSince ? `há ${item.workingDays} dia(s) útil(eis)` : 'desde antes do início do histórico';

  async function fetchTower(sprint) {
    const url = new URL(el('page-control-tower').dataset.url, location.href);
    if (sprint) url.searchParams.set('sprint', sprint);
    return mondayRequest(url.pathname + url.search);
  }

  async function load() {
    const request = ++state.request;
    state.loading = true; render();
    try {
      const data = await fetchTower(state.sprint);
      if (request !== state.request) return;
      state.data = data; state.sprint = data.sprint; state.error = '';
      updateBadge(data);
    } catch (error) {
      if (request === state.request) state.error = `Não foi possível montar a torre de controle: ${error.message}`;
    } finally {
      if (request === state.request) { state.loading = false; render(); }
    }
  }

  function render() {
    if (!state.active) return;
    const data = state.data;
    el('tower-refresh').disabled = state.loading;
    el('tower-updated').textContent = state.loading ? 'Atualizando…' : data?.syncedAt ? `Dados do Monday de ${new Date(data.syncedAt).toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'})}` : '';
    el('tower-error').textContent = state.error; el('tower-error').hidden = !state.error;
    const sprints = data?.sprints || [];
    el('tower-sprint').replaceChildren(...sprints.map(value => new Option(`Sprint ${value}`, String(value))));
    if (data?.sprint) el('tower-sprint').value = String(data.sprint);
    const ready = Boolean(data?.available);
    el('tower-content').hidden = !ready;
    el('tower-empty').hidden = ready || !data;
    el('tower-empty').textContent = data?.reason || '';
    el('tower-dates').textContent = ready ? `${data.dates.name} · ${day(data.dates.start)} a ${day(data.dates.end)}` : '';
    if (!ready) return;

    const [label, detail] = STATUS[data.status];
    el('tower-status').className = `tower-status tower-${data.status}`;
    el('tower-status-label').textContent = label;
    el('tower-status-detail').textContent = detail;
    el('tower-days').textContent = `${data.remainingDays} de ${data.workingDays}`;
    el('tower-commitment').textContent = `${data.totals.committedDone} de ${data.totals.committed}`;
    el('tower-scope').textContent = `${data.totals.done} de ${data.totals.scope}${data.totals.doneNotDelivered ? ` · ${data.totals.doneNotDelivered} aguardando entrega` : ''}`;
    el('tower-decision').textContent = data.decision.text;
    el('tower-decision').classList.toggle('tower-late', data.decision.late);

    el('tower-signals').innerHTML = data.signals.map(signal => `<article class="tower-signal tower-${signal.level}"><span class="tower-dot" aria-hidden="true"></span><div><h3>${escape(signal.label)} <small>${LEVEL[signal.level]}</small></h3><p>${escape(signal.detail)}</p></div></article>`).join('');

    const forecast = data.forecast;
    el('tower-forecast').textContent = forecast.available
      ? `Previsão (Monte Carlo, ${forecast.runs.toLocaleString('pt-BR')} simulações sobre ${forecast.samples} dias úteis de histórico): com 85% de confiança, pelo menos ${forecast.p85} card(s) concluídos até o fim; resultado mais provável, ${forecast.p50}. Chance de concluir todo o escopo restante (${data.totals.remaining}): ${forecast.probabilityAll.toLocaleString('pt-BR')}%.`
      : 'Previsão indisponível: o histórico de entregas ainda é curto (mínimo de 10 dias úteis com dados).';
    renderChart(data);

    el('tower-maneuvers-text').textContent = data.maneuvers.text;
    el('tower-maneuvers').innerHTML = data.maneuvers.suggestions.map(item => `<li>${link(item)} <small>${escape(item.status || 'Sem status')} · ${escape(item.priority || 'sem prioridade')} · ${item.committed ? 'compromisso' : 'desejável'}</small></li>`).join('');

    el('tower-alerts').innerHTML = data.alerts.length ? data.alerts.map(alert => `<li class="tower-alert tower-${alert.level}">${escape(alert.text)}</li>`).join('') : '<li>Nenhum alerta no momento.</li>';

    const scope = data.scopeChanges;
    el('tower-scope-text').textContent = scope.baselineDate ? `Linha de base: ${scope.baseline} card(s) no fim do planejamento (${day(scope.baselineDate)}).` : 'A sprint ainda não começou.';
    el('tower-added-summary').textContent = `${scope.added.length} card(s) adicionados depois do planejamento`;
    el('tower-added').innerHTML = scope.added.map(item => `<li>${link(item)} <small>${escape(item.type || 'Sem tipo')}${item.changedAt ? ` · entrou em ${day(item.changedAt.slice(0,10))}` : ''}</small></li>`).join('') || '<li>Nenhum.</li>';
    el('tower-removed-summary').textContent = `${scope.removed.length} card(s) retirados`;
    el('tower-removed').innerHTML = scope.removed.map(item => `<li>${link(item)} <small>${escape(item.reason)}</small></li>`).join('') || '<li>Nenhum.</li>';

    const items = [...data.items].sort((a, b) => Number(a.done) - Number(b.done) || Number(b.committed) - Number(a.committed) || a.name.localeCompare(b.name, 'pt-BR'));
    el('tower-items').innerHTML = items.map(item => `<tr class="${item.done ? 'tower-done' : ''}"><td><input type="checkbox" data-item="${escape(item.id)}" ${item.committed ? 'checked' : ''} aria-label="Compromisso: ${escape(item.name)}"></td><td>${link(item)}</td><td>${escape(item.status || '—')}</td><td>${escape(item.owner || '—')}</td><td>${escape(item.priority || '—')}</td></tr>`).join('');
    el('tower-items').querySelectorAll('input[type=checkbox]').forEach(input => { input.onchange = () => saveCommitment(input.dataset.item, input.checked); });

    const t = data.thresholds;
    el('tower-footer').textContent = `Regras: card parado = ${t.stalledDays}+ dias úteis no mesmo status; impedimento vermelho a partir de ${t.blockedRedDays} dias úteis; escopo adicionado amarelo acima de ${t.scopeYellow}% e vermelho acima de ${t.scopeRed}% da linha de base (suporte fora da conta); entrega verde a partir de ${t.probabilityGreen}% de chance e vermelha abaixo de ${t.probabilityRed}%. Histórico do Monday disponível desde ${data.historyStart ? new Date(data.historyStart).toLocaleDateString('pt-BR') : '—'}.`;
  }

  function renderChart(data) {
    const labels = window.SprintRules.workingDays(data.dates.start, data.dates.end);
    const byDay = Object.fromEntries(data.burnup.map(point => [point.date, point]));
    const last = data.burnup.at(-1);
    const projection = (extra) => labels.map(date => !last || date < last.date ? null : date === last.date ? last.done : last.done + extra);
    const datasets = [
      {label:'Escopo', data:labels.map(date => byDay[date]?.scope ?? null), borderColor:'#7c3aed', backgroundColor:'#7c3aed', tension:.2, spanGaps:false},
      {label:'Concluídos', data:labels.map(date => byDay[date]?.done ?? null), borderColor:'#16a34a', backgroundColor:'#16a34a', tension:.2},
    ];
    if (data.forecast.available && last && data.remainingDays > 0) {
      datasets.push({label:'Projeção provável (50%)', data:projection(data.forecast.p50), borderColor:'#16a34a', borderDash:[6,4], pointRadius:0});
      datasets.push({label:'Projeção com 85% de confiança', data:projection(data.forecast.p85), borderColor:'#d97706', borderDash:[3,4], pointRadius:0});
    }
    state.chart?.destroy();
    state.chart = new Chart(el('tower-burnup-chart').getContext('2d'), {type:'line', data:{labels:labels.map(day), datasets},
      options:{responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false}, plugins:{legend:{labels:{boxWidth:12, font:{size:11}}}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}}}});
  }

  async function saveCommitment(itemId, committed) {
    try {
      await mondayRequest(el('page-control-tower').dataset.commitUrl, {sprint:state.sprint, itemId, committed});
      el('tower-commit-status').textContent = 'Compromisso atualizado.';
      load();
    } catch (error) { el('tower-commit-status').textContent = `Falha ao salvar: ${error.message}`; }
  }

  function seenAlerts() { try { return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')); } catch { return new Set(); } }
  function rememberAlerts(keys) { try { localStorage.setItem(SEEN_KEY, JSON.stringify([...keys].slice(-300))); } catch { } }

  /** Atualiza o contador no menu e avisa somente os alertas que ainda não foram vistos. */
  function updateBadge(data) {
    const badge = el('tower-badge');
    const alerts = data?.available ? data.alerts : [];
    badge.hidden = !alerts.length;
    badge.textContent = String(alerts.length);
    badge.className = `nav-badge ${alerts.some(alert => alert.level === 'red') ? 'nav-badge-red' : 'nav-badge-yellow'}`;
    const seen = seenAlerts();
    const fresh = alerts.filter(alert => !seen.has(alert.key));
    if (!fresh.length) return;
    alerts.forEach(alert => seen.add(alert.key));
    rememberAlerts(seen);
    const toast = el('tower-toast');
    toast.innerHTML = `<strong>${fresh.length} alerta(s) novo(s) na sprint ${escape(data.sprint)}</strong><ul>${fresh.slice(0, 3).map(alert => `<li>${escape(alert.text)}</li>`).join('')}</ul><button type="button" id="tower-toast-open">Abrir torre de controle</button><button type="button" id="tower-toast-close" aria-label="Fechar aviso">×</button>`;
    toast.hidden = false;
    el('tower-toast-open').onclick = () => { toast.hidden = true; navigate('control-tower'); };
    el('tower-toast-close').onclick = () => { toast.hidden = true; };
  }

  /** Chamado após cada sincronização com o Monday. */
  async function refreshAlerts() {
    if (state.active) { load(); return; }
    try { updateBadge(await fetchTower(null)); } catch { /* O aviso é complementar; falhas aparecem ao abrir a torre. */ }
  }

  function enter(hint = null) {
    state.active = true;
    if (Number.isInteger(hint)) state.sprint = hint;
    render(); load();
  }
  function leave() { state.active = false; state.chart?.destroy(); state.chart = null; }

  document.addEventListener('DOMContentLoaded', () => {
    if (!el('page-control-tower')) return;
    el('tower-sprint').onchange = event => { state.sprint = Number(event.target.value); load(); };
    el('tower-refresh').onclick = () => syncMonday(true);
  });

  return {enter, leave, load, refreshAlerts, updateBadge};
})();
