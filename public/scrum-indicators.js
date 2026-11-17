/*
 * Indicadores Scrum (só Maestro Scrum): infográfico de antes e depois das mudanças, para apresentar à diretoria. Os dois
 * períodos são escolhidos na tela (de / até); cada indicador é a média por sprint do período, com os cards do Monday
 * (DATA, sprints encerradas congeladas no último dia), sem os retirados (cancelado, descontinuado, despriorizado).
 * O infográfico é um slide 16:9 (1600 × 900), ajustado à largura da tela, à tela cheia e à impressão (uma página).
 */
window.ScrumIndicators = (() => {
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const rules = () => window.SprintRules;
  const normalize = value => rules().normalize(value);
  const filled = value => String(value ?? '').trim() !== '';
  const number = value => { const parsed = Number(String(value ?? '').replace(',', '.')); return Number.isFinite(parsed) ? parsed : 0; };
  const STAGE_W = 1600, STAGE_H = 900;
  /** Padrão: as 3 últimas sprints encerradas são o "após"; todas as anteriores, o "antes". */
  const DEFAULT_AFTER = 3;

  const state = {active: false, beforeFrom: null, beforeTo: null, afterFrom: null, afterTo: null};

  // ── Sprints ──────────────────────────────────────────────────────────────────
  const dates = sprint => window.MONDAY_SPRINT_DATES?.[sprint] || null;
  const today = () => rules().today();
  /** Sprints com datas que já começaram, em ordem crescente. */
  function startedSprints() {
    const now = today();
    return (typeof SPRINTS === 'undefined' ? [] : SPRINTS).filter(sprint => dates(sprint)?.start && dates(sprint).start <= now).map(Number).sort((a, b) => a - b);
  }
  const closed = sprint => (dates(sprint)?.end ?? '9999') < today();
  const between = (list, from, to) => list.filter(sprint => sprint >= Math.min(from, to) && sprint <= Math.max(from, to));

  function applyDefaults(list) {
    const done = list.filter(closed);
    const after = done.slice(-DEFAULT_AFTER);
    const before = done.slice(0, -DEFAULT_AFTER);
    if (!after.length) return;
    state.afterFrom = after[0]; state.afterTo = after.at(-1);
    state.beforeFrom = (before[0] ?? after[0]); state.beforeTo = (before.at(-1) ?? after[0]);
  }

  // ── Contas ───────────────────────────────────────────────────────────────────
  const isDragged = row => typeof isArrastado === 'function' ? isArrastado(row) : (filled(row.Arrastado) && !['0', 'nao', 'false'].includes(normalize(row.Arrastado)));
  const pct = (part, whole) => whole > 0 ? part / whole * 100 : null;

  /**
   * Indicadores de um período: médias por sprint e percentuais sobre os cards do período.
   * @return {Record<string, ?number>}
   */
  function measure(sprints) {
    const set = new Set(sprints.map(Number));
    const rows = (typeof DATA === 'undefined' ? [] : DATA).filter(row => set.has(Number(row.Sprint)) && !rules().isRemoved(row.Status));
    const count = Math.max(1, sprints.length);
    const isDone = row => rules().isDone(row.Status);
    const isSupport = row => normalize(row.Tipo) === 'suporte';
    const done = rows.filter(isDone);
    const support = rows.filter(isSupport);
    const planned = rows.filter(row => !isSupport(row) && normalize(row.Planejada) === 'planejada');
    const pairs = done.filter(row => number(row.EstH) > 0 && number(row.RealH) > 0);
    const sum = (list, read) => list.reduce((total, row) => total + read(row), 0);
    const estimate = sum(pairs, row => number(row.EstH)), real = sum(pairs, row => number(row.RealH));
    return {
      sprints: sprints.length, cards: rows.length,
      donePerSprint: done.length / count, cardsPerSprint: rows.length / count, nonSupportPerSprint: (rows.length - support.length) / count,
      productivity: pct(done.length, rows.length),
      plannedDelivered: pct(planned.filter(isDone).length, planned.length), plannedPerSprint: planned.length / count,
      deviation: estimate > 0 ? (real / estimate - 1) * 100 : null,
      withEstimate: pct(rows.filter(row => number(row.EstH) > 0).length, rows.length),
      ready: pct(rows.filter(row => number(row.EstH) > 0 && filled(row.Tipo) && filled(row.Planejada) && filled(row.Prioridade) && filled(row.Responsavel)).length, rows.length),
      origin: pct(rows.filter(row => filled(row.Planejada)).length, rows.length),
      supportPerSprint: support.length / count, supportWithHours: pct(support.filter(row => number(row.RealH) > 0).length, support.length),
      doneWithHours: pct(done.filter(row => number(row.RealH) > 0).length, done.length),
      hoursPerSprint: sum(rows, row => number(row.RealH)) / count, doneHoursPerSprint: sum(done, row => number(row.RealH)) / count,
      rework: pct(rows.filter(row => row.Retrabalho && String(row.Retrabalho) !== '0').length, rows.length),
      dragged: pct(rows.filter(isDragged).length, rows.length),
      client: pct(rows.filter(row => filled(row.Cliente)).length, rows.length),
    };
  }

  // ── Formatos e comparação ────────────────────────────────────────────────────
  const decimal = (value, digits = 1) => value === null || value === undefined ? '—' : String(Math.round(value * 10 ** digits) / 10 ** digits).replace('.', ',');
  const FORMAT = {
    count: value => decimal(value, value >= 10 ? 0 : 1),
    percent: value => value === null ? '—' : `${decimal(value, value >= 10 ? 0 : 1)}%`,
    percentFine: value => value === null ? '—' : `${decimal(value, 1)}%`,
    hours: value => `${decimal(value, 0)}h`,
    signed: value => value === null ? '—' : `${value >= 0 ? '+' : '−'}${decimal(Math.abs(value), 1)}%`,
  };
  /**
   * Selo da variação, verde quando melhorou: ×N para volumes, p.p. para percentuais, % para taxas que devem cair e
   * N× menor para o desvio da estimativa (comparado em valor absoluto).
   */
  function change(kind, before, after, better) {
    if (before === null || after === null || before === undefined || after === undefined) return {text: 'sem base', good: null};
    const improved = better === 'higher' ? after > before : (kind === 'deviation' ? Math.abs(after) < Math.abs(before) : after < before);
    const same = Math.abs(after - before) < 0.05;
    let text;
    if (kind === 'ratio') text = before > 0 && after >= before ? `×${decimal(after / before, 1)}` : before > 0 ? `−${Math.round((1 - after / before) * 100)}%` : 'novo';
    else if (kind === 'pp') text = `${after >= before ? '+' : '−'}${decimal(Math.abs(after - before), Math.abs(after - before) >= 10 ? 0 : 1)} p.p.`;
    else if (kind === 'relative') text = before > 0 ? `${after <= before ? '−' : '+'}${Math.round(Math.abs(after - before) / before * 100)}%` : 'novo';
    else text = Math.abs(after) > 0 && Math.abs(after) < Math.abs(before) ? `${decimal(Math.abs(before) / Math.abs(after), 0)}× menor` : `${Math.abs(after) >= Math.abs(before) ? '+' : '−'}${decimal(Math.abs(Math.abs(after) - Math.abs(before)), 1)} p.p.`;
    return {text: same ? 'estável' : text, good: same ? null : improved};
  }

  /**
   * As 8 frentes: indicador principal (com barras de antes e agora) e um segundo indicador numa linha.
   * key: campo de measure(); kind: tipo do selo; better: higher ou lower; format: FORMAT.
   */
  const FRONTS = [
    {icon: '🚀', title: 'Volume de entrega', unit: 'cards / sprint', main: {key: 'donePerSprint', label: 'cards entregues por sprint', kind: 'ratio', better: 'higher', format: 'count'}, sub: {key: 'doneHoursPerSprint', label: 'Horas entregues por sprint', format: 'hours'}},
    {icon: '✅', title: 'Compromisso cumprido', unit: 'do planejado', main: {key: 'plannedDelivered', label: 'planejados entregues', kind: 'pp', better: 'higher', format: 'percent'}, sub: {key: 'plannedPerSprint', label: 'Cards planejados por sprint', format: 'count'}},
    {icon: '🎯', title: 'Estimativa precisa', unit: 'de desvio', main: {key: 'deviation', label: 'desvio real × estimado', kind: 'deviation', better: 'lower', format: 'signed'}, sub: {key: 'withEstimate', label: 'Cards com estimativa', format: 'percentFine'}},
    {icon: '🧩', title: 'Backlog refinado', unit: 'cards prontos', main: {key: 'ready', label: 'cards prontos', kind: 'pp', better: 'higher', format: 'percent'}, sub: {key: 'origin', label: 'Com origem definida', format: 'percentFine'}},
    {icon: '🛟', title: 'Sustentação sob controle', unit: 'tickets / sprint', main: {key: 'supportPerSprint', label: 'tickets registrados por sprint', kind: 'ratio', better: 'higher', format: 'count'}, sub: {key: 'supportWithHours', label: 'Tickets com horas', format: 'percentFine'}},
    {icon: '⏱️', title: 'Capacity medida', unit: 'entregas c/ horas', main: {key: 'doneWithHours', label: 'entregas com horas apontadas', kind: 'pp', better: 'higher', format: 'percent'}, sub: {key: 'hoursPerSprint', label: 'Horas por sprint', format: 'hours'}},
    {icon: '🔁', title: 'Qualidade', unit: 'de retrabalho', main: {key: 'rework', label: 'cards com retrabalho', kind: 'relative', better: 'lower', format: 'percentFine'}, sub: {key: 'dragged', label: 'Cards arrastados', format: 'percentFine'}},
    {icon: '📊', title: 'Gestão metrificada', unit: 'cards com cliente', main: {key: 'client', label: 'cards com cliente identificado', kind: 'pp', better: 'higher', format: 'percent'}, sub: {key: 'withEstimate', label: 'Cards com estimativa', format: 'percentFine'}},
  ];
  const HERO = [
    {key: 'productivity', title: 'Produtividade', kind: 'pp', better: 'higher', format: 'percent', target: 90},
    {key: 'plannedDelivered', title: 'Planejado cumprido', kind: 'pp', better: 'higher', format: 'percent'},
    {key: 'rework', title: 'Retrabalho', kind: 'relative', better: 'lower', format: 'percentFine'},
    {key: 'deviation', title: 'Desvio da estimativa', kind: 'deviation', better: 'lower', format: 'signed'},
  ];

  // ── Tela ─────────────────────────────────────────────────────────────────────
  function render() {
    if (!state.active) return;
    const list = startedSprints();
    if (!list.length) { el('si-period').textContent = 'Aguardando os dados do Monday...'; el('si-stage').innerHTML = ''; return; }
    if (![state.beforeFrom, state.beforeTo, state.afterFrom, state.afterTo].every(sprint => list.includes(sprint))) applyDefaults(list);
    renderFilters(list);
    const before = between(list, state.beforeFrom, state.beforeTo), after = between(list, state.afterFrom, state.afterTo);
    const overlap = before.some(sprint => after.includes(sprint));
    const partial = after.some(sprint => !closed(sprint)) || before.some(sprint => !closed(sprint));
    el('si-period').innerHTML = `Antes das mudanças: ${label(before)} · Após as mudanças: ${label(after)}`
      + (overlap ? ' · <b>atenção: os dois períodos têm sprints em comum</b>' : '')
      + (partial ? ' · <b>inclui sprint em andamento</b> (números parciais)' : '');
    renderStage(measure(before), measure(after), before, after, list);
    fit();
  }
  const label = sprints => sprints.length === 1 ? `Sprint ${sprints[0]}` : `Sprints ${sprints[0]} a ${sprints.at(-1)} (${sprints.length})`;

  function renderFilters(list) {
    const options = list.slice().reverse().map(sprint => `<option value="${sprint}">Sprint ${sprint}${closed(sprint) ? '' : ' (atual)'}</option>`).join('');
    for (const [id, value] of [['si-before-from', state.beforeFrom], ['si-before-to', state.beforeTo], ['si-after-from', state.afterFrom], ['si-after-to', state.afterTo]]) {
      const select = el(id);
      if (select.dataset.options !== options) { select.innerHTML = options; select.dataset.options = options; }
      select.value = String(value);
    }
  }

  function tag(spec, before, after) {
    const result = change(spec.kind, before[spec.key], after[spec.key], spec.better);
    const met = spec.target && after[spec.key] !== null && after[spec.key] >= spec.target;
    return `<span class="si-tag ${result.good === false ? 'bad' : ''}">${met ? `meta ${spec.target}% ✓` : escape(result.text)}</span>`;
  }

  function renderStage(before, after, beforeSprints, afterSprints, list) {
    const delivery = change('ratio', before.donePerSprint, after.donePerSprint, 'higher');
    const growth = before.donePerSprint > 0 ? (after.donePerSprint / before.donePerSprint - 1) * 100 : null;
    const headline = growth === null ? '—' : `${growth >= 0 ? '+' : '−'}${Math.round(Math.abs(growth))}%`;
    const verb = growth === null ? 'O time em números' : growth >= 5 ? `O time entrega <em>${escape(delivery.text.replace('×', ''))}× mais</em> por sprint` : growth <= -5 ? `O time entrega <em>${Math.round(Math.abs(growth))}% menos</em> por sprint` : 'O time mantém o volume de entregas por sprint';
    const control = after.rework !== null && before.rework !== null && after.rework < before.rework ? ', com mais controle e menos retrabalho' : '';
    const note = after.supportPerSprint > before.supportPerSprint && before.nonSupportPerSprint > 0
      ? `<b>Nota:</b> parte da variação de volume vem da sustentação registrada na sprint. Sem os tickets de suporte, a média vai de ${decimal(before.nonSupportPerSprint, 0)} para ${decimal(after.nonSupportPerSprint, 0)} cards por sprint (${change('relative', before.nonSupportPerSprint, after.nonSupportPerSprint, 'higher').text}).` : '';
    el('si-stage').innerHTML = `<section class="si-hero">
        <div class="si-hero-top">
          <div>
            <span class="si-eyebrow">Produto e Projetos · Time Scrum · YUV</span>
            <h2>${verb}${control}</h2>
            <p>O que mudou em gestão e produtividade a partir das alterações na área de Produto e Projetos.</p>
            <div class="si-chips"><span class="si-chip"><i class="before"></i>Antes das mudanças</span><span class="si-chip"><i class="after"></i>Após as mudanças</span></div>
          </div>
          <div class="si-headline"><strong>${headline}</strong><span>cards entregues por sprint</span><small>de ${decimal(before.donePerSprint, 0)} para ${decimal(after.donePerSprint, 0)} em média</small></div>
        </div>
        <div class="si-kpis">${HERO.map(spec => `<div class="si-kpi"><b>${FORMAT[spec.format](after[spec.key])}</b><div><h3>${spec.title}</h3><small>antes <s>${FORMAT[spec.format](before[spec.key])}</s></small>${tag(spec, before, after)}</div></div>`).join('')}</div>
      </section>
      <div class="si-body">
        <section>
          <div class="si-section-title"><h3>8 frentes que mudaram</h3><span>médias por sprint · antes × agora</span></div>
          <div class="si-fronts">${FRONTS.map(front => frontHtml(front, before, after)).join('')}</div>
        </section>
        <section class="si-charts">
          <div class="si-box"><h3>Cards por sprint <small>registrados e entregues</small></h3><div class="si-plot">${volumeChart(beforeSprints, afterSprints, list)}</div>
            <div class="si-legend"><span><i style="background:#dbe7fe;border:1px solid #b9cffb"></i>Registrados</span><span><i style="background:#a7b3c4"></i>Entregues antes</span><span><i style="background:#1d6ef5"></i>Entregues após</span></div></div>
          <div class="si-box"><h3>Produtividade por sprint <small>% dos cards entregues · meta 90%</small></h3><div class="si-plot">${productivityChart(beforeSprints, afterSprints, list)}</div>
            <div class="si-legend"><span><i class="line" style="background:#0f172a"></i>Produtividade</span><span><i class="line" style="background:#e8890c"></i>Meta 90%</span></div></div>
        </section>
      </div>
      <footer class="si-foot"><span><b>Fonte:</b> quadro de Tarefas do Monday, pelo YUV Sprint Metrics.</span><span>${note}</span></footer>`;
  }

  function frontHtml(front, before, after) {
    const main = front.main, previous = before[main.key], current = after[main.key];
    const result = change(main.kind, previous, current, main.better);
    const max = Math.max(Math.abs(previous ?? 0), Math.abs(current ?? 0)) || 1;
    const width = value => `${Math.max(2, Math.abs(value ?? 0) / max * 100)}%`;
    const format = FORMAT[main.format];
    const sub = front.sub;
    return `<article class="si-front">
        <div class="si-front-head"><span class="si-icon">${front.icon}</span><h4>${front.title}</h4></div>
        <div class="si-big"><b>${format(current)}</b><span>${front.unit}</span></div>
        <span class="si-badge ${result.good === false ? 'bad' : result.good === null ? 'flat' : ''}">${escape(result.text)}</span>
        <div class="si-bars">
          <div class="si-bar-caption">${main.label}</div>
          <div class="si-bar"><span>antes</span><span class="si-track"><span style="width:${width(previous)}"></span></span><em>${format(previous)}</em></div>
          <div class="si-bar after"><span>agora</span><span class="si-track"><span style="width:${width(current)}"></span></span><em>${format(current)}</em></div>
          <p class="si-sub">${sub.label}: <s>${FORMAT[sub.format](before[sub.key])}</s> → <b>${FORMAT[sub.format](after[sub.key])}</b></p>
        </div>
      </article>`;
  }

  // ── Gráficos (SVG, para a mesma imagem na tela, na tela cheia e na impressão) ─
  const W = 500, H = 200, PAD = {l: 32, r: 8, t: 20, b: 20};
  /** Sprints do gráfico: do início do "antes" ao fim do "após" (as do meio aparecem mais claras). */
  function chartSprints(beforeSprints, afterSprints, list) {
    const first = Math.min(beforeSprints[0] ?? Infinity, afterSprints[0] ?? Infinity), last = Math.max(beforeSprints.at(-1) ?? -Infinity, afterSprints.at(-1) ?? -Infinity);
    return list.filter(sprint => sprint >= first && sprint <= last).map(sprint => {
      const metrics = measure([sprint]);
      return {sprint, total: metrics.cards, done: Math.round(metrics.donePerSprint), productivity: metrics.productivity,
        group: afterSprints.includes(sprint) ? 'after' : beforeSprints.includes(sprint) ? 'before' : 'gap'};
    });
  }
  function frame(points) {
    const plotW = W - PAD.l - PAD.r, plotH = H - PAD.t - PAD.b, slot = plotW / Math.max(1, points.length);
    const x = index => PAD.l + slot * index + slot / 2;
    const firstAfter = points.findIndex(point => point.group === 'after');
    const marker = firstAfter < 0 ? '' : `<rect x="${PAD.l + slot * firstAfter}" y="${PAD.t}" width="${plotW - slot * firstAfter}" height="${plotH}" fill="#dbe7fe" opacity=".45"/>
      <line x1="${PAD.l + slot * firstAfter}" x2="${PAD.l + slot * firstAfter}" y1="${PAD.t - 10}" y2="${PAD.t + plotH}" stroke="#1d6ef5" stroke-width="1.5" stroke-dasharray="4 3"/>
      <text x="${PAD.l + slot * firstAfter - 4}" y="${PAD.t - 2}" text-anchor="end" font-size="10" font-weight="800" fill="#1d6ef5">mudanças →</text>`;
    const labels = points.map((point, index) => `<text x="${x(index)}" y="${H - 5}" text-anchor="middle" font-size="9.5" fill="#94a3b8">S${point.sprint}</text>`).join('');
    const grid = (max, step, suffix = '') => {
      let out = '';
      for (let value = 0; value <= max; value += step) {
        const y = PAD.t + plotH - value / max * plotH;
        out += `<line x1="${PAD.l}" x2="${PAD.l + plotW}" y1="${y}" y2="${y}" stroke="#e2e8f0"/><text x="${PAD.l - 5}" y="${y + 3.5}" text-anchor="end" font-size="9.5" fill="#475569">${value}${suffix}</text>`;
      }
      return out;
    };
    return {plotW, plotH, slot, x, marker, labels, grid};
  }
  const COLORS = {before: '#a7b3c4', after: '#1d6ef5', gap: '#d5dbe3'};

  function volumeChart(beforeSprints, afterSprints, list) {
    const points = chartSprints(beforeSprints, afterSprints, list);
    const {plotH, slot, x, marker, labels, grid} = frame(points);
    const highest = Math.max(...points.map(point => point.total), 1);
    const step = highest > 200 ? 100 : highest > 100 ? 50 : highest > 40 ? 25 : 10;
    const top = Math.ceil(highest / step) * step;
    const base = PAD.t + plotH, h = value => value / top * plotH, barW = Math.min(22, slot * .64);
    const bars = points.map((point, index) => `<g><title>Sprint ${point.sprint}: ${point.done} entregues de ${point.total} registrados</title>
        <rect x="${x(index) - barW / 2}" y="${base - h(point.total)}" width="${barW}" height="${h(point.total)}" rx="3" fill="#dbe7fe"/>
        <rect x="${x(index) - barW / 2 + 3}" y="${base - h(point.done)}" width="${Math.max(1, barW - 6)}" height="${h(point.done)}" rx="2" fill="${COLORS[point.group]}"/>
        ${point.group === 'after' ? `<text x="${x(index)}" y="${base - h(point.total) - 4}" text-anchor="middle" font-size="11" font-weight="900" fill="#0f172a">${point.done}</text>` : ''}</g>`).join('');
    return `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Cards registrados e entregues por sprint">${marker}${grid(top, step)}${bars}${labels}</svg>`;
  }

  function productivityChart(beforeSprints, afterSprints, list) {
    const points = chartSprints(beforeSprints, afterSprints, list).filter(point => point.productivity !== null);
    const {plotW, plotH, x, marker, labels, grid} = frame(points);
    const y = value => PAD.t + plotH - value / 100 * plotH;
    const path = points.map((point, index) => `${index ? 'L' : 'M'}${x(index)} ${y(point.productivity)}`).join(' ');
    const dots = points.map((point, index) => `<g><title>Sprint ${point.sprint}: ${decimal(point.productivity)}%</title><circle cx="${x(index)}" cy="${y(point.productivity)}" r="${point.group === 'after' ? 5 : 3.2}" fill="${COLORS[point.group]}" stroke="#fff" stroke-width="2"/>
      ${point.group === 'after' ? `<text x="${x(index)}" y="${y(point.productivity) - 9}" text-anchor="middle" font-size="11" font-weight="900" fill="#0f172a">${Math.round(point.productivity)}%</text>` : ''}</g>`).join('');
    return `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Produtividade por sprint contra a meta de 90%">${marker}${grid(100, 25, '%')}
      <line x1="${PAD.l}" x2="${PAD.l + plotW}" y1="${y(90)}" y2="${y(90)}" stroke="#e8890c" stroke-width="2" stroke-dasharray="6 4"/>
      ${points.length ? `<path d="${path}" fill="none" stroke="#0f172a" stroke-width="2"/>` : ''}${dots}${labels}</svg>`;
  }

  // ── Escala: largura da tela, tela cheia e impressão ──────────────────────────
  function fit() {
    const frameBox = el('si-frame'), stage = el('si-stage');
    if (!frameBox || !stage) return;
    const fullscreen = document.fullscreenElement === frameBox;
    const width = fullscreen ? innerWidth : frameBox.clientWidth;
    const scale = fullscreen ? Math.min(innerWidth / STAGE_W, innerHeight / STAGE_H) : width / STAGE_W;
    stage.style.transform = `scale(${scale})`;
    stage.style.left = fullscreen ? `${(innerWidth - STAGE_W * scale) / 2}px` : '0px';
    stage.style.top = fullscreen ? `${(innerHeight - STAGE_H * scale) / 2}px` : '0px';
    frameBox.style.height = fullscreen ? '' : `${STAGE_H * scale}px`;
  }

  function fullscreen() {
    const frameBox = el('si-frame');
    if (document.fullscreenElement) document.exitFullscreen?.();
    else frameBox.requestFullscreen?.().catch(() => {});
  }

  /** Imprime só o infográfico, numa página 16:9 (no diálogo, "Salvar como PDF" gera o arquivo). */
  function print() {
    const previous = document.title;
    document.title = 'Indicadores Scrum - antes e depois das mudanças';
    document.body.classList.add('si-printing');
    const done = () => { document.body.classList.remove('si-printing'); document.title = previous; fit(); };
    window.addEventListener('afterprint', done, {once: true});
    window.print();
  }

  function enter() { state.active = true; render(); }
  function leave() { state.active = false; if (document.fullscreenElement === el('si-frame')) document.exitFullscreen?.(); }
  function onDataUpdated() { if (state.active) render(); }

  document.addEventListener('DOMContentLoaded', () => {
    if (!el('page-scrum-indicators')) return;
    const bind = (id, field) => el(id).addEventListener('change', event => { state[field] = Number(event.target.value); render(); });
    bind('si-before-from', 'beforeFrom'); bind('si-before-to', 'beforeTo'); bind('si-after-from', 'afterFrom'); bind('si-after-to', 'afterTo');
    el('si-default').addEventListener('click', () => { applyDefaults(startedSprints()); render(); });
    el('si-fullscreen').addEventListener('click', fullscreen);
    el('si-print').addEventListener('click', print);
    document.addEventListener('fullscreenchange', fit);
    addEventListener('resize', () => { if (state.active) fit(); });
  });

  return {enter, leave, onDataUpdated, measure, change};
})();
