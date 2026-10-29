/* Burndown da sprint: painéis geral, suporte e demais cards contra a linha ideal, e SLA do suporte, calculados no back-end. */
window.Burndown = (() => {
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const COLORS = {remaining:'#1d6ef5', done:'#e1306c', scope:'#94a3b8', ideal:'#16a34a'};
  const state = {active:false, sprint:null, data:null, loading:false, error:'', request:0, charts:[], unit:'cards'};

  const day = value => value ? value.split('-').reverse().slice(0, 2).join('/') : '—';
  const number = value => value === null || value === undefined ? '—' : Number(value).toLocaleString('pt-BR', {maximumFractionDigits:1});
  /** Valor e sufixo na unidade escolhida: cards ou horas estimadas. */
  const pick = (source, key) => state.unit === 'hours' ? source[`${key}Hours`] : source[key];
  const unitLabel = value => state.unit === 'hours' ? `${number(value)}h` : `${number(value)} card(s)`;
  /** Tempo corrido em horas como "5h" ou "2d 4h". */
  const duration = hours => {
    if (hours === null || hours === undefined) return 'Desconhecido';
    const total = Math.round(hours);
    return total < 24 ? `${number(hours)}h` : `${Math.floor(total / 24)}d ${total % 24}h`;
  };
  const dateTime = value => value ? new Date(value).toLocaleString('pt-BR', {timeZone:'America/Sao_Paulo', day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'}) : '—';
  const link = item => window.ItemDetail.link(item);
  const fact = (label, value, detail) => `<div><span>${label}</span><strong>${value}</strong><small>${detail}</small></div>`;

  async function load() {
    const request = ++state.request;
    state.loading = true; render();
    try {
      const url = new URL(el('page-burndown').dataset.url, location.href);
      if (state.sprint) url.searchParams.set('sprint', state.sprint);
      const data = await mondayRequest(url.pathname + url.search);
      if (request !== state.request) return;
      state.data = data; state.sprint = data.sprint; state.error = '';
    } catch (error) {
      if (request === state.request) state.error = `Não foi possível montar o burndown: ${error.message}`;
    } finally {
      if (request === state.request) { state.loading = false; render(); }
    }
  }

  function render() {
    if (!state.active) return;
    const data = state.data;
    el('burndown-refresh').disabled = state.loading;
    el('burndown-updated').textContent = state.loading ? 'Atualizando…' : data?.syncedAt ? `Dados do Monday de ${new Date(data.syncedAt).toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'})}` : '';
    el('burndown-error').textContent = state.error; el('burndown-error').hidden = !state.error;
    el('burndown-sprint').replaceChildren(...(data?.sprints || []).map(value => new Option(`Sprint ${value}`, String(value))));
    if (data?.sprint) { el('burndown-sprint').value = String(data.sprint); window.rememberScreenSprint?.('burndown', data.sprint); }
    document.querySelectorAll('#page-burndown .burndown-units button').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.unit === state.unit)));
    const ready = Boolean(data?.available);
    el('burndown-content').hidden = !ready;
    el('burndown-empty').hidden = ready || !data;
    el('burndown-empty').textContent = data?.reason || '';
    el('burndown-dates').textContent = ready ? `${data.dates.name} · ${day(data.dates.start)} a ${day(data.dates.end)}` : '';
    if (!ready) return;

    renderPanels(data);
    renderSla(data.sla);
    el('burndown-history').textContent = data.partialHistory
      ? `Atenção: o log de atividades copiado começa em ${data.historyStart ? new Date(data.historyStart).toLocaleDateString('pt-BR') : '—'}, depois do início desta sprint. Os dias anteriores usam o status atual dos cards e podem não refletir o que aconteceu.`
      : `Log de atividades do Monday disponível desde ${new Date(data.historyStart).toLocaleDateString('pt-BR')}.`;
  }

  /** Um painel por recorte (geral, suporte, demais): resumo do último dia, gráfico e distância da linha ideal. */
  function renderPanels(data) {
    state.charts.forEach(chart => chart.destroy());
    state.charts = [];
    el('burndown-panels').innerHTML = data.panels.map(panel => `<section class="burndown-card burndown-panel burndown-panel-${panel.key}">
        <h2>${escape(panel.label)}</h2>
        <div class="burndown-facts">${facts(data, panel)}</div>
        ${panel.summary ? `<div class="burndown-chart"><canvas id="burndown-chart-${panel.key}" aria-label="Burndown: ${escape(panel.label)}"></canvas></div><p class="burndown-note">${gapText(data, panel)}</p>` : ''}
      </section>`).join('');
    data.panels.filter(panel => panel.summary).forEach(panel => state.charts.push(chart(data, panel)));
  }

  const idealToday = (data, panel) => {
    const index = data.days.indexOf(panel.summary.date);
    return index >= 0 ? panel.ideal[state.unit][index] : null;
  };

  function facts(data, panel) {
    const s = panel.summary;
    if (!s) return '<p class="burndown-note">A sprint ainda não começou: o burndown aparece a partir do primeiro dia útil.</p>';
    const initial = pick(s, 'initialScope'), scope = pick(s, 'scope');
    const scopeDelta = Math.round((scope - initial) * 10) / 10;
    return [
      fact('Restante', unitLabel(pick(s, 'remaining')), `ideal hoje: ${unitLabel(idealToday(data, panel))}`),
      fact('Concluído', unitLabel(pick(s, 'done')), `de ${unitLabel(scope)} no escopo`),
      fact('Escopo', unitLabel(scope), scopeDelta ? `${scopeDelta > 0 ? '+' : ''}${number(scopeDelta)} desde o 1º dia (${unitLabel(initial)})` : 'sem mudança desde o 1º dia'),
      fact('Ritmo médio', `${number(pick(s, 'averagePace'))}/dia`, 'concluídos por dia útil decorrido'),
      fact('Ritmo necessário', data.remainingDays ? `${number(pick(s, 'requiredPace'))}/dia` : '—', data.remainingDays ? `para zerar em ${data.remainingDays} dia(s) útil(eis)` : 'sprint encerrada'),
    ].join('');
  }

  function gapText(data, panel) {
    const s = panel.summary, ideal = idealToday(data, panel);
    if (ideal === null) return '';
    const gap = Math.round((pick(s, 'remaining') - ideal) * 10) / 10;
    if (gap > 0) return `No fim de ${day(s.date)}, o restante está ${unitLabel(gap)} acima da linha ideal.`;
    if (gap < 0) return `No fim de ${day(s.date)}, o restante está ${unitLabel(-gap)} abaixo da linha ideal.`;
    return `No fim de ${day(s.date)}, o restante está exatamente na linha ideal.`;
  }

  function chart(data, panel) {
    const byDay = Object.fromEntries(panel.points.map(point => [point.date, point]));
    const series = key => data.days.map(date => byDay[date] ? pick(byDay[date], key) : null);
    const hours = state.unit === 'hours';
    const suffix = hours ? ' (h)' : '';
    const datasets = [
      {label:'Ideal', data:panel.ideal[state.unit], borderColor:COLORS.ideal, backgroundColor:COLORS.ideal, borderDash:[6,4], pointRadius:0, borderWidth:1.5},
      {label:`Restante${suffix}`, data:series('remaining'), borderColor:COLORS.remaining, backgroundColor:COLORS.remaining, tension:.15, borderWidth:2.5},
      {label:`Concluído${suffix}`, data:series('done'), borderColor:COLORS.done, backgroundColor:COLORS.done, tension:.15, borderWidth:2},
      {label:`Escopo${suffix}`, data:series('scope'), borderColor:COLORS.scope, backgroundColor:COLORS.scope, borderWidth:1, pointRadius:2},
    ];
    const today = data.today;
    return new Chart(el(`burndown-chart-${panel.key}`).getContext('2d'), {type:'line', data:{labels:data.days.map(date => `${day(date)}${date === today ? ' (hoje)' : ''}`), datasets},
      options:{responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false},
        plugins:{legend:{labels:{boxWidth:12, font:{size:11}}},
          tooltip:{callbacks:{
            label:context => `${context.dataset.label}: ${number(context.parsed.y)}${hours ? 'h' : ''}`,
            afterBody:items => {
              const point = byDay[data.days[items[0]?.dataIndex]];
              if (!point) return '';
              const lines = [];
              if (point.scopeChange > 0) lines.push(`${point.scopeChange} card(s) entraram neste dia`);
              if (point.scopeChange < 0) lines.push(`${-point.scopeChange} card(s) saíram neste dia`);
              if (point.date === today) lines.push('Dia em andamento: estado da última sincronização');
              return lines;
            },
          }}},
        scales:{y:{beginAtZero:true, ticks:{precision:hours ? undefined : 0}, title:{display:true, text:hours ? 'Horas estimadas' : 'Cards'}}}}});
  }

  /** SLA do suporte: média, mediana e maior tempo dos atendidos, cards em aberto e a lista com o tempo de cada um. */
  function renderSla(sla) {
    el('burndown-sla-facts').innerHTML = [
      fact('Tempo médio de atendimento', duration(sla.averageHours), `${sla.done} card(s) implementado(s) com abertura conhecida`),
      fact('Mediana', duration(sla.medianHours), 'metade dos atendimentos levou até este tempo'),
      fact('Maior tempo', duration(sla.maxHours), 'atendimento mais longo da sprint'),
      fact('Em aberto', String(sla.open), sla.openAverageHours === null ? 'nenhum com abertura conhecida' : `abertos há ${duration(sla.openAverageHours)} em média`),
      fact('Sem abertura no log', String(sla.unknown), 'criados antes do início do log; fora da média'),
    ].join('');
    window.KpiTable.render(el('burndown-sla-table'), {rows:sla.items, empty:'Nenhum card de suporte nesta sprint.', tableClass:'burndown-sla-grid', columns:[
      {label:'Card', html:link, value:item => item.name},
      {label:'Status', html:item => `<span class="b ${item.done ? 'bd' : 'bn'}">${escape(item.status || 'Sem status')}</span>`, value:item => item.status, filter:true, empty:'Sem status'},
      {label:'Responsável', html:item => escape(item.owner) || '—', value:item => item.owner, filter:item => String(item.owner || '').split(',').map(name => name.trim()), empty:'Sem responsável'},
      {label:'Aberto em', html:item => dateTime(item.createdAt), value:item => item.createdAt ? dateTime(item.createdAt) : '', sort:item => item.createdAt},
      {label:'Implementado em', html:item => dateTime(item.doneAt), value:item => item.doneAt ? dateTime(item.doneAt) : '', sort:item => item.doneAt},
      {label:'Tempo de atendimento', html:item => `${duration(item.hours)}${item.done || item.hours === null ? '' : ' <small class="burndown-muted">(em aberto)</small>'}`, value:item => duration(item.hours), sort:item => item.hours},
    ]});
  }

  /** Chamado após cada sincronização com o Monday. */
  function refresh() { if (state.active) load(); }

  function enter(hint = null) {
    state.active = true;
    if (Number.isInteger(hint)) state.sprint = hint;
    render(); load();
  }
  function leave() { state.active = false; state.charts.forEach(chart => chart.destroy()); state.charts = []; }

  document.addEventListener('DOMContentLoaded', () => {
    if (!el('page-burndown')) return;
    el('burndown-sprint').onchange = event => { state.sprint = Number(event.target.value); load(); };
    el('burndown-refresh').onclick = () => syncMonday(true);
    document.querySelectorAll('#page-burndown .burndown-units button').forEach(button => {
      button.onclick = () => { state.unit = button.dataset.unit; render(); };
    });
  });

  return {enter, leave, load, refresh};
})();
