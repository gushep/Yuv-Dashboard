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
  const state = {active:false, sprint:null, data:null, loading:false, error:'', request:0, chart:null, origin:'',
    filters:{q:'', situations:new Set(), owner:'', priority:'', commitment:''}, sort:{key:null, dir:1}};
  const OPEN_SITUATIONS = ['notStarted', 'progress', 'blocked'];
  /** Origem do card na sprint; a cor liga a barra de progresso à etiqueta da tabela. */
  const ORIGIN = {
    planned: {label:'Planejado', color:'#1d6ef5', rank:0},
    unplanned: {label:'Não planejado', color:'#e8890c', rank:1},
    support: {label:'Suporte', color:'#0d9488', rank:2},
  };
  const ORIGINS = Object.keys(ORIGIN);

  /* Tabela de compromisso: situação, etiquetas e ordenação. */
  const AVATAR_COLORS = ['#ff7a21','#0871e9','#9d75df','#16a96b','#e1306c','#0d9488','#d97706','#475569'];
  const SITUATION = {blocked:['Impedido',0], progress:['Em andamento',1], notStarted:['Não iniciado',2], waiting:['Feito, não entregue',3], done:['Concluído',4]};
  const PRIORITY = {critica:['bs',0], alta:['bn',1], media:['bw',2], baixa:['bo',3]};
  const situation = item => item.done ? 'done' : item.doneNotDelivered ? 'waiting' : window.SprintRules.isBlocked(item.status) ? 'blocked' : item.notStarted ? 'notStarted' : 'progress';
  const owners = item => String(item.owner || '').split(',').map(name => name.trim()).filter(Boolean);
  const priorityKey = item => window.SprintRules.normalize(item.priority);
  const priorityRank = item => PRIORITY[priorityKey(item)]?.[1] ?? 4;
  function statusBadge(item) {
    const kind = situation(item);
    const css = {done:'bd', waiting:'bw', blocked:'bs', notStarted:'bo'}[kind] || (/^(qa|cr)\b/.test(window.SprintRules.normalize(item.status)) ? 'bq' : 'bn');
    return `<span class="b ${css}" title="${escape(SITUATION[kind][0])}">${escape(item.status || 'Sem status')}</span>`;
  }
  function priorityBadge(item) {
    return item.priority ? `<span class="b ${PRIORITY[priorityKey(item)]?.[0] || 'bo'}">${escape(item.priority)}</span>` : '<span class="tower-muted">Sem prioridade</span>';
  }
  function originBadge(item) {
    const title = item.category === 'planned' ? 'Compromisso da sprint' : item.category === 'support' ? `Tipo Suporte · Planejada: ${item.planned || 'vazio'}` : `Planejada: ${item.planned || 'vazio'}`;
    return `<span class="tower-origin tower-origin-${item.category}" title="${escape(title)}">${ORIGIN[item.category].label}</span>`;
  }
  function avatar(name) {
    const initials = name.split(/\s+/).filter(Boolean).map(part => part[0]).filter((_, index, all) => index === 0 || index === all.length - 1).join('').toLocaleUpperCase('pt-BR');
    const color = AVATAR_COLORS[[...name].reduce((sum, char) => sum + char.charCodeAt(0), 0) % AVATAR_COLORS.length];
    return `<span class="tower-avatar" style="background:${color}" aria-hidden="true">${escape(initials)}</span>`;
  }
  function ownerCell(item) {
    const names = owners(item);
    if (!names.length) return '<span class="tower-muted">Sem responsável</span>';
    return `<span class="tower-owner">${names.map(avatar).join('')}<span>${escape(names.join(', '))}</span></span>`;
  }
  const SORTS = {
    committed: (a, b) => ORIGIN[a.category].rank - ORIGIN[b.category].rank,
    name: (a, b) => a.name.localeCompare(b.name, 'pt-BR'),
    status: (a, b) => SITUATION[situation(a)][1] - SITUATION[situation(b)][1] || a.status.localeCompare(b.status, 'pt-BR'),
    owner: (a, b) => (owners(a)[0] ? 0 : 1) - (owners(b)[0] ? 0 : 1) || (owners(a)[0] || '').localeCompare(owners(b)[0] || '', 'pt-BR'),
    priority: (a, b) => priorityRank(a) - priorityRank(b),
    estimate: (a, b) => (a.estimate === null) - (b.estimate === null) || (a.estimate ?? 0) - (b.estimate ?? 0),
  };
  /** Ordem padrão: em aberto antes dos concluídos; planejado, não planejado e suporte; maior prioridade primeiro. */
  const defaultOrder = (a, b) => Number(a.done) - Number(b.done) || SORTS.committed(a, b) || SORTS.priority(a, b) || SORTS.name(a, b);
  function matches(item) {
    const f = state.filters;
    const kind = situation(item);
    return (!f.q || window.SprintRules.normalize(item.name).includes(window.SprintRules.normalize(f.q)))
      && (!f.situations.size || f.situations.has(kind))
      && (!f.owner || (f.owner === '-' ? !owners(item).length : owners(item).includes(f.owner)))
      && (!f.priority || (f.priority === '-' ? !item.priority : item.priority === f.priority))
      && (!f.commitment || item.category === f.commitment);
  }
  function fillSelect(id, values, emptyLabel, selected) {
    const select = el(id);
    select.replaceChildren(new Option('Todos', ''), ...values.map(value => new Option(value, value)), new Option(emptyLabel, '-'));
    select.value = [...select.options].some(option => option.value === selected) ? selected : '';
    return select.value;
  }
  function renderItems() {
    const items = state.data.items;
    state.filters.owner = fillSelect('tower-filter-owner', [...new Set(items.flatMap(owners))].sort((a, b) => a.localeCompare(b, 'pt-BR')), 'Sem responsável', state.filters.owner);
    state.filters.priority = fillSelect('tower-filter-priority', [...new Set(items.map(item => item.priority).filter(Boolean))].sort((a, b) => priorityRank({priority:a}) - priorityRank({priority:b})), 'Sem prioridade', state.filters.priority);
    const {key, dir} = state.sort;
    const shown = items.filter(matches).sort(key ? (a, b) => SORTS[key](a, b) * dir || defaultOrder(a, b) : defaultOrder);
    el('tower-filter-count').textContent = `${shown.length} de ${items.length} card(s)`;
    document.querySelectorAll('#page-control-tower .tower-situations .tower-chip').forEach(chip => {
      const kind = chip.dataset.situation;
      chip.dataset.label ||= chip.textContent;
      chip.textContent = `${chip.dataset.label} · ${items.filter(item => situation(item) === kind).length}`;
      chip.setAttribute('aria-pressed', String(state.filters.situations.has(kind)));
    });
    document.querySelectorAll('#page-control-tower .tower-table th').forEach(th => {
      const sortKey = th.querySelector('button').dataset.sort;
      th.setAttribute('aria-sort', sortKey === key ? (dir === 1 ? 'ascending' : 'descending') : 'none');
    });
    el('tower-items').innerHTML = shown.length ? shown.map(item => `<tr class="${item.done ? 'tower-done' : ''}">
      <td>${originBadge(item)}</td>
      <td>${link(item)}${item.type ? `<small>${escape(item.type)}</small>` : ''}</td>
      <td>${statusBadge(item)}</td><td>${ownerCell(item)}</td><td>${priorityBadge(item)}</td>
      <td>${item.estimate === null ? '<span class="tower-muted">Sem estimativa</span>' : `${String(item.estimate).replace('.', ',')}h`}</td></tr>`).join('')
      : '<tr><td colspan="6" class="tower-muted">Nenhum card com os filtros selecionados.</td></tr>';  }

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
    renderOrigins(data);

    el('tower-maneuvers-text').textContent = data.maneuvers.text;
    el('tower-maneuvers').innerHTML = data.maneuvers.suggestions.map(item => `<li>${link(item)} <small>${escape(item.status || 'Sem status')} · ${escape(item.priority || 'sem prioridade')} · ${ORIGIN[item.category].label.toLocaleLowerCase('pt-BR')}</small></li>`).join('');

    el('tower-alerts').innerHTML = data.alerts.length ? data.alerts.map(alert => `<li class="tower-alert tower-${alert.level}">${escape(alert.text)}</li>`).join('') : '<li>Nenhum alerta no momento.</li>';

    const scope = data.scopeChanges;
    el('tower-scope-text').textContent = scope.baselineDate ? `Linha de base: ${scope.baseline} card(s) no fim do planejamento (${day(scope.baselineDate)}).` : 'A sprint ainda não começou.';
    el('tower-added-summary').textContent = `${scope.added.length} card(s) adicionados depois do planejamento`;
    el('tower-added').innerHTML = scope.added.map(item => `<li>${link(item)} <small>${escape(item.type || 'Sem tipo')}${item.changedAt ? ` · entrou em ${day(item.changedAt.slice(0,10))}` : ''}</small></li>`).join('') || '<li>Nenhum.</li>';
    el('tower-removed-summary').textContent = `${scope.removed.length} card(s) retirados`;
    el('tower-removed').innerHTML = scope.removed.map(item => `<li>${link(item)} <small>${escape(item.reason)}</small></li>`).join('') || '<li>Nenhum.</li>';

    renderItems();

    const t = data.thresholds;
    el('tower-footer').textContent = `Regras: card parado = ${t.stalledDays}+ dias úteis no mesmo status; impedimento vermelho a partir de ${t.blockedRedDays} dias úteis; escopo adicionado amarelo acima de ${t.scopeYellow}% e vermelho acima de ${t.scopeRed}% da linha de base (suporte fora da conta); entrega verde a partir de ${t.probabilityGreen}% de chance e vermelha abaixo de ${t.probabilityRed}%. Histórico do Monday disponível desde ${data.historyStart ? new Date(data.historyStart).toLocaleDateString('pt-BR') : '—'}.`;
  }

  /**
   * Burnup de escopo e concluídos. Com uma origem selecionada, mostra só os cards dela; a projeção
   * aparece apenas em "Todos", porque a previsão considera o escopo inteiro da sprint.
   */
  function renderChart(data) {
    const labels = window.SprintRules.workingDays(data.dates.start, data.dates.end);
    const origin = state.origin;
    const byDay = Object.fromEntries(data.burnup.map(point => [point.date, origin ? point.categories?.[origin] : point]));
    const last = data.burnup.at(-1);
    const projection = (extra) => labels.map(date => !last || date < last.date ? null : date === last.date ? last.done : last.done + extra);
    const suffix = origin ? ` · ${ORIGIN[origin].label}` : '';
    const datasets = [
      {label:`Escopo${suffix}`, data:labels.map(date => byDay[date]?.scope ?? null), borderColor:'#7c3aed', backgroundColor:'#7c3aed', tension:.2, spanGaps:false},
      {label:`Concluídos${suffix}`, data:labels.map(date => byDay[date]?.done ?? null), borderColor:'#16a34a', backgroundColor:'#16a34a', tension:.2},
    ];
    if (!origin && data.forecast.available && last && data.remainingDays > 0) {
      datasets.push({label:'Projeção provável (50%)', data:projection(data.forecast.p50), borderColor:'#16a34a', borderDash:[6,4], pointRadius:0});
      datasets.push({label:'Projeção com 85% de confiança', data:projection(data.forecast.p85), borderColor:'#d97706', borderDash:[3,4], pointRadius:0});
    }
    state.chart?.destroy();
    state.chart = new Chart(el('tower-burnup-chart').getContext('2d'), {type:'line', data:{labels:labels.map(day), datasets},
      options:{responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false},
        plugins:{legend:{labels:{boxWidth:12, font:{size:11}}},
          subtitle:{display:Boolean(origin), text:'Projeção disponível apenas em "Todos": a previsão considera o escopo inteiro da sprint.', font:{size:11}, color:'#4b5568', padding:{bottom:8}}},
        scales:{y:{beginAtZero:true, ticks:{precision:0}}}}});
    document.querySelectorAll('#page-control-tower .tower-origin-filter .tower-chip').forEach(chip => chip.setAttribute('aria-pressed', String(chip.dataset.origin === origin)));
  }

  /** Uma barra por origem: quanto do escopo atual já foi entregue e quanto falta. */
  function renderOrigins(data) {
    el('tower-origins').innerHTML = ORIGINS.map(origin => {
      const t = data.totals.categories[origin];
      const percent = t.scope ? Math.round(t.done / t.scope * 100) : 0;
      const waiting = t.doneNotDelivered ? ` (${t.doneNotDelivered} aguardando entrega)` : '';
      return `<div class="tower-origin-row">
        <strong>${ORIGIN[origin].label}</strong>
        <div class="tower-origin-track" role="progressbar" aria-label="${ORIGIN[origin].label}: entregues" aria-valuemin="0" aria-valuemax="${t.scope}" aria-valuenow="${t.done}"><div class="tower-origin-fill" style="width:${percent}%;background:${ORIGIN[origin].color}"></div></div>
        <span class="tower-origin-count">${t.scope ? `<b>${t.done} de ${t.scope}</b> entregues · ${t.remaining} pendente(s)${waiting}` : 'Nenhum card'}</span>
      </div>`;
    }).join('');
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
    document.querySelectorAll('#page-control-tower .tower-origin-filter .tower-chip').forEach(chip => {
      chip.onclick = () => { state.origin = chip.dataset.origin; if (state.data?.available) renderChart(state.data); };
    });
    const filters = {q:'tower-filter-q', owner:'tower-filter-owner', priority:'tower-filter-priority', commitment:'tower-filter-commitment'};
    const refreshItems = () => { if (state.data?.available) renderItems(); };
    document.querySelectorAll('#page-control-tower .tower-situations .tower-chip').forEach(chip => {
      chip.onclick = () => {
        const kind = chip.dataset.situation, selected = state.filters.situations;
        selected.has(kind) ? selected.delete(kind) : selected.add(kind);
        refreshItems();
      };
    });
    el('tower-filter-open').onclick = () => {
      const selected = state.filters.situations;
      const onlyOpen = selected.size === OPEN_SITUATIONS.length && OPEN_SITUATIONS.every(kind => selected.has(kind));
      state.filters.situations = new Set(onlyOpen ? [] : OPEN_SITUATIONS);
      refreshItems();
    };
    for (const [key, id] of Object.entries(filters)) {
      el(id).addEventListener(key === 'q' ? 'input' : 'change', event => { state.filters[key] = event.target.value; if (state.data?.available) renderItems(); });
    }
    el('tower-filter-clear').onclick = () => {
      state.filters = {q:'', situations:new Set(), owner:'', priority:'', commitment:''};
      for (const id of Object.values(filters)) el(id).value = '';
      if (state.data?.available) renderItems();
    };
    document.querySelectorAll('#page-control-tower .tower-table th button').forEach(button => {
      button.onclick = () => {
        const key = button.dataset.sort;
        state.sort = state.sort.key === key ? {key, dir:-state.sort.dir} : {key, dir:1};
        if (state.data?.available) renderItems();
      };
    });
  });

  return {enter, leave, load, refreshAlerts, updateBadge};
})();
