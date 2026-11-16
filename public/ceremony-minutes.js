/*
 * Resumo em formato de ata das cerimônias (Sprint Planning, Daily Scrum, Sprint Review e Sprint Retrospective). Cada
 * tela busca os dados que já mostra no modo leitura e chama `CeremonyMinutes.print(CeremonyMinutes.<cerimônia>(...))`:
 * a ata é montada num documento à parte e vai para a impressão do navegador, onde "Salvar como PDF" gera o arquivo.
 * Só leitura: nada é gravado no app nem no Monday.
 */
window.CeremonyMinutes = (() => {
  const TIMEZONE = 'America/Sao_Paulo';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const day = value => value ? new Date(String(value).length === 10 ? `${value}T12:00:00` : value).toLocaleDateString('pt-BR', {timeZone: TIMEZONE}) : '—';
  const dateTime = value => value ? new Date(value).toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: TIMEZONE}) : '—';
  /** Dia (AAAA-MM-DD) de um instante no fuso de São Paulo. */
  const dateOf = value => value ? new Date(value).toLocaleDateString('en-CA', {timeZone: TIMEZONE}) : null;
  const hours = value => value === null || value === undefined || value === '' || Number.isNaN(Number(value)) ? '—' : `${String(Math.round(Number(value) * 10) / 10).replace('.', ',')}h`;
  const minutes = value => window.Capacity?.formatMinutes ? window.Capacity.formatMinutes(Math.max(0, Math.round(value || 0))) : hours((value || 0) / 60);
  const duration = seconds => window.Ceremony ? window.Ceremony.duration(seconds) : `${Math.round((seconds || 0) / 60)} min`;
  const names = list => list?.length ? list.join(', ') : '—';
  const text = value => value ? escape(value).replace(/\n/g, '<br>') : '—';
  const paragraph = (value, empty) => value ? `<p>${text(value)}</p>` : `<p class="empty">${escape(empty)}</p>`;
  const bullets = (items, empty) => items.length ? `<ul>${items.map(item => `<li>${item}</li>`).join('')}</ul>` : `<p class="empty">${escape(empty)}</p>`;
  /** Tabela da ata: as células já chegam em HTML escapado. */
  const table = (heads, rows, empty) => rows.length
    ? `<table><thead><tr>${heads.map(head => `<th>${escape(head)}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${row.map(cell => `<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table>`
    : `<p class="empty">${escape(empty)}</p>`;
  const stepsTable = steps => table(['Etapa', 'Previsto', 'Realizado'], (steps || []).map(step => [escape(step.name), `${step.minutes} min`, step.seconds ? duration(step.seconds) : '—']), 'Sem tempo registrado.');
  const presence = (attendees = []) => `${attendees.length} · ${names(attendees)}`;

  const GOAL = {yes: 'Objetivo atingido', partial: 'Objetivo parcialmente atingido', no: 'Objetivo não atingido'};
  const VERDICT = {accepted: 'Aceito', adjust: 'Aceito com ajustes', rejected: 'Não aceito', skipped: 'Não apresentado'};
  const REASON = {capacity: 'Capacidade', blocked: 'Impedimento', reprioritized: 'Repriorizado', scope: 'Escopo'};
  const DESTINATION = {next: 'Próxima sprint', backlog: 'Volta ao Backlog'};
  const COLUMN = {keep: 'Manter', improve: 'Melhorar', try: 'Experimentar'};
  const ACTION_STATUS = {open: 'Em andamento', done: 'Concluída', dropped: 'Descartada'};
  const PREVIOUS_DECISION = {done: 'Concluída', continued: 'Continua', dropped: 'Descartada', reopened: 'Reaberta'};

  /** Indicadores da sprint (os mesmos da Review e da etapa Dados da Retro), em tópicos. */
  function metricsHtml(metrics) {
    if (!metrics) return '<p class="empty">Sem indicadores da sprint (dados do Monday não sincronizados).</p>';
    const items = [
      `<b>Entregas:</b> ${metrics.delivered} card(s) Implementado`,
      `<b>Do planejado:</b> ${metrics.plannedPct === null || metrics.plannedPct === undefined ? '—' : `${metrics.plannedPct}%`} (${metrics.plannedDone} de ${metrics.planned} planejados entregues)`,
      `<b>Fora do planejado:</b> ${metrics.unplanned} não planejado(s) e ${metrics.support} de suporte`,
      `<b>Horas:</b> ${minutes(metrics.registeredMinutes)} registradas de ${minutes(metrics.plannedMinutes)} planejadas`,
    ];
    if (metrics.scope !== undefined) items.splice(1, 0, `<b>Escopo:</b> ${metrics.scope} card(s)`);
    if (metrics.onTargetPct !== undefined) items.push(`<b>Estimativas:</b> ${metrics.onTargetPct === null ? '—' : `${metrics.onTargetPct}% no alvo`} (${metrics.onTarget} de ${metrics.onTargetBase} entregas até 100% do estimado)`);
    if (metrics.reworkPct !== undefined) items.push(`<b>Qualidade:</b> ${metrics.reworkPct}% de retrabalho · ${metrics.bugs} bug(s) em produção · ${metrics.dragPct}% arrastado`);
    if (metrics.daily) items.push(`<b>Daily:</b> ${metrics.daily.done} de ${metrics.daily.total} responsabilidade(s) resolvidas`);
    return bullets(items, '');
  }

  /** Resultado da Sprint Review da mesma sprint, numa linha (para a ata da Retro). */
  function reviewLine(summary) {
    if (!summary) return '';
    const v = summary.verdicts || {}, presented = (v.accepted || 0) + (v.adjust || 0) + (v.rejected || 0);
    const parts = [presented ? `${v.accepted} de ${presented} entregas aceitas${v.adjust ? `, ${v.adjust} com ajustes` : ''}${v.rejected ? `, ${v.rejected} não aceita(s)` : ''}` : 'nenhuma entrega avaliada',
      summary.goalResult ? GOAL[summary.goalResult].toLowerCase() : null, `${summary.feedbacks} feedback(s)`].filter(Boolean);
    return `<p><b>Sprint Review:</b> ${escape(parts.join(' · '))}</p>`;
  }

  // ── Sprint Planning ──────────────────────────────────────────────────────────
  /**
   * @param data resposta do modo leitura da planning (`/api/planning/sprints/{sprint}`)
   * @param sprint linha da sprint no histórico (nome e período), quando houver
   */
  const splitNames = value => String(value || '').split(',').map(name => name.trim()).filter(Boolean);
  const sameOwners = (a, b) => splitNames(a).map(name => name.toLowerCase()).sort().join('|') === splitNames(b).map(name => name.toLowerCase()).sort().join('|');
  const differs = (a, b) => a !== null && a !== undefined && b !== null && b !== undefined && Math.abs(Number(a) - Number(b)) >= 0.01;
  const sum = (list, value) => list.reduce((total, item) => total + (Number(value(item)) || 0), 0);
  const deltaText = (value, base) => `${signedHours(value - base)}${base ? ` (${value - base > 0 ? '+' : ''}${Math.round((value - base) / base * 100)}%)` : ''}`;

  /** Responsável na planning × hoje no Monday, e as trocas registradas no log do Monday depois da estimativa. */
  const ownerChanged = round => round.current?.found && (!sameOwners(round.owner, round.current.owner) || round.current.ownerChanges > 0);
  const estimateChanged = round => round.current?.found && differs(round.current.estimate, round.finalEstimate);

  /** Resumo da planning: estimativas (IA × planning), decisões, escopo da sprint hoje, trocas e carga do time. */
  function planningSummary(data, rounds, load) {
    const final = sum(rounds, round => round.finalEstimate), ai = sum(rounds.filter(round => round.aiEstimate !== null && round.aiEstimate !== undefined), round => round.aiEstimate);
    const withAi = rounds.filter(round => round.aiEstimate !== null && round.aiEstimate !== undefined);
    const above = withAi.filter(round => Number(round.finalEstimate) > Number(round.aiEstimate) + 0.01).length;
    const below = withAi.filter(round => Number(round.finalEstimate) < Number(round.aiEstimate) - 0.01).length;
    const cards = data.sprintCards || [];
    const estimatedIds = new Set(rounds.map(round => String(round.itemId)));
    const outside = rounds.filter(round => round.current && !round.current.inSprint);
    const capacity = sum(load, person => person.capacity), planned = sum(load, person => person.total);
    return bullets([
      `<b>${rounds.length}</b> card(s) estimado(s) na mesa · <b>${hours(final)}</b> na planning${withAi.length ? ` contra ${hours(ai)} da IA, ${deltaText(sum(withAi, round => round.finalEstimate), ai)}` : ''}`,
      `Decisão: <b>${rounds.filter(round => round.finalSource !== 'ai').length}</b> pela média do time · <b>${rounds.filter(round => round.finalSource === 'ai').length}</b> pela IA`,
      ...(withAi.length ? [`Planning acima da IA em <b>${above}</b> card(s), abaixo em <b>${below}</b>, igual em <b>${withAi.length - above - below}</b>`] : []),
      `Sprint hoje no Monday: <b>${cards.length}</b> card(s), <b>${hours(sum(cards, card => card.estimate))}</b> estimadas (${cards.filter(card => estimatedIds.has(String(card.id))).length} estimado(s) na mesa, ${cards.filter(card => !estimatedIds.has(String(card.id))).length} de fora da mesa)`,
      ...(outside.length ? [`<b>${outside.length}</b> card(s) estimado(s) na mesa não estão na sprint hoje`] : []),
      `<b>${rounds.filter(ownerChanged).length}</b> card(s) com troca de responsável e <b>${rounds.filter(estimateChanged).length}</b> com estimativa alterada no Monday depois da planning`,
      ...(load.length ? [`Carga do time: <b>${hours(planned)}</b> de ${hours(capacity)} de capacidade${capacity ? ` (${Math.round(planned / capacity * 100)}%)` : ''}`] : []),
    ], '');
  }

  /**
   * Por pessoa: os cards estimados na mesa (pelo responsável na planning) e os demais cards da sprint (pelo responsável
   * no Monday), com a estimativa da IA, a média do time, a da planning e como o card está hoje.
   */
  function planningPeople(data, rounds, load) {
    const estimatedIds = new Set(rounds.map(round => String(round.itemId)));
    const people = new Map();
    const add = (owner, entry) => { for (const name of splitNames(owner).length ? splitNames(owner) : ['Sem responsável']) { if (!people.has(name)) people.set(name, []); people.get(name).push(entry); } };
    for (const round of rounds) add(round.owner, {round});
    for (const card of data.sprintCards || []) if (!estimatedIds.has(String(card.id))) add(card.owner, {card});
    const loadOf = name => load.find(person => person.name.toLowerCase() === name.toLowerCase());
    const rank = name => { const index = load.findIndex(person => person.name.toLowerCase() === name.toLowerCase()); return index < 0 ? load.length : index; };
    return [...people.keys()].sort((a, b) => (a === 'Sem responsável') - (b === 'Sem responsável') || rank(a) - rank(b) || a.localeCompare(b, 'pt-BR')).map(name => {
      const entries = people.get(name);
      const person = loadOf(name);
      const plannedHours = sum(entries, entry => entry.round ? entry.round.finalEstimate : entry.card.estimate);
      const rows = entries.map(({round, card}) => {
        if (card) return [escape(card.name), '<small>fora da mesa</small>', '—', '—', '—', `${hours(card.estimate)}<br><small>${escape(card.status || '—')}</small>`, '—'];
        const now = round.current;
        const notes = [
          now && !now.found ? 'Card não está mais na base do Monday' : null,
          now?.found && !now.inSprint ? 'Não está na sprint hoje' : null,
          ownerChanged(round) ? `Responsável hoje: ${escape(now.owner || 'ninguém')}` : null,
          round.note ? text(round.note) : null,
        ].filter(Boolean);
        return [escape(round.name), `<small>estimado na mesa${round.decidedBy ? ` · ${escape(round.decidedBy)}` : ''}</small>`, hours(round.aiEstimate), hours(round.average),
          `<b>${hours(round.finalEstimate)}</b><br><small>${round.finalSource === 'ai' ? 'pela IA' : 'pela média'}</small>`,
          now?.found ? `${estimateChanged(round) ? `<b>${hours(now.estimate)}</b>` : hours(now.estimate)}<br><small>${escape(now.status || '—')}</small>` : '—', notes.join('<br>') || '—'];
      });
      return {person: true, title: `${name} · ${entries.length} card(s) · ${hours(plannedHours)}${person ? ` · carga ${hours(person.total)} de ${hours(person.capacity)}${person.percent === null ? '' : ` (${person.percent}%)`}` : ''}`,
        html: table(['Card', 'Origem', 'IA', 'Média do time', 'Planning', 'Hoje no Monday', 'Observações'], rows, '')};
    });
  }

  function planning(data, sprint = null) {
    const name = data.name || sprint?.name || `Sprint ${data.sprint}`;
    const rounds = data.rounds || [];
    const total = rounds.reduce((sum, round) => sum + (Number(round.finalEstimate) || 0), 0);
    const load = data.load || [];
    const owners = rounds.filter(ownerChanged);
    const estimates = rounds.filter(estimateChanged);
    return {
      ceremony: 'Sprint Planning', title: `Ata da Sprint Planning · ${name}`, file: `Ata Sprint Planning - ${name}`,
      meta: [
        ['Sprint', escape(name)],
        ['Período', sprint ? `${day(sprint.start)} a ${day(sprint.end)}` : '—'],
        ['Situação', data.open ? 'Mesa aberta (em andamento)' : data.closedAt ? `Encerrada em ${dateTime(data.closedAt)}` : 'Encerrada'],
        ['Conduzida por', escape(data.startedBy || '—')],
        ['Duração', data.durationSeconds ? duration(data.durationSeconds) : '—'],
        ['Presentes', escape(presence(data.attendees))],
      ],
      sections: [
        {title: 'Objetivo da Sprint', html: paragraph(data.goal, 'Sem objetivo registrado na planning.')},
        {title: 'Resumo da planning', html: planningSummary(data, rounds, load)},
        ...planningPeople(data, rounds, load),
        {title: `Troca de responsável depois da planning · ${owners.length}`, html: table(['Card', 'Na planning', 'Hoje no Monday', 'Trocas no Monday'],
          owners.map(round => [escape(round.name), escape(round.owner || '—'), `<b>${escape(round.current.owner || '—')}</b>`,
            round.current.ownerChanges ? `${round.current.ownerChanges} troca(s) · última em ${dateTime(round.current.ownerChangedAt)}` : '—']),
          'Nenhum card estimado mudou de responsável depois da planning.')},
        {title: `Estimativa alterada no Monday depois da planning · ${estimates.length}`, html: table(['Card', 'Responsável', 'Planning', 'Hoje no Monday', 'Diferença'],
          estimates.map(round => [escape(round.name), escape(round.current.owner || round.owner || '—'), hours(round.finalEstimate), `<b>${hours(round.current.estimate)}</b>`, deltaText(Number(round.current.estimate), Number(round.finalEstimate))]),
          'Nenhuma estimativa da planning foi alterada no Monday.')},
        {title: `Cartas do time por card · ${rounds.length} card(s) · ${hours(total)}`, html: table(
          ['Card', 'Responsável', 'Cartas do time', 'Média', 'IA', 'Estimativa final', 'Decisão'],
          rounds.map(round => [
            escape(round.name), escape(round.owner || '—'),
            escape((round.votes || []).map(vote => `${vote.name} ${vote.hours === null || vote.hours === undefined ? '?' : hours(vote.hours)}`).join(', ') || '—'),
            hours(round.average), hours(round.aiEstimate),
            `<b>${hours(round.finalEstimate)}</b> (${round.finalSource === 'ai' ? 'IA' : 'média do time'})`,
            `${escape(round.decidedBy || '—')}${round.note ? `<br><small>${text(round.note)}</small>` : ''}`,
          ]),
          data.recorded ? 'Nenhum card estimado nesta planning.' : 'Planning feita fora do app: sem cartas registradas.')},
        ...(!data.recorded && data.cards?.length ? [{title: `Cards da sprint (Monday) · ${data.cards.length}`, html: table(['Card', 'Responsável', 'Tipo', 'Estimado'],
          data.cards.map(card => [escape(card.name), escape(card.owner || '—'), escape(card.type || '—'), hours(card.estimate)]), '')}] : []),
        {title: 'Carga do time na sprint', html: table(
          ['Pessoa', 'Time', 'Já na sprint', 'Estimado na planning', 'Total', 'Capacidade', 'Ocupação'],
          load.map(person => [escape(person.name), escape(person.team || '—'), hours(person.inSprint), hours(person.planned), `<b>${hours(person.total)}</b>`, hours(person.capacity), person.percent === null ? '—' : `${person.percent}%`]),
          'Sem carga calculada.') + (data.reserveHours ? `<p class="note">Reserva do Bombeiro descontada da capacidade Full stack: ${hours(data.reserveHours)}.</p>` : '')},
      ],
    };
  }

  // ── Daily Scrum ──────────────────────────────────────────────────────────────
  const BOARD = [['done', '✅ Entregues'], ['qa', '🧪 Em QA'], ['progress', '🔵 Em andamento'], ['blocked', '🔴 Impedidos'], ['pending', '⏭️ Próximos']];
  const signedHours = value => `${value > 0 ? '+' : value < 0 ? '−' : ''}${hours(Math.abs(value))}`;

  /** O que mudou num card no intervalo: entrada na sprint, status, horas apontadas, estimativa e comentários. */
  function cardChange(card, columnOf) {
    const before = card.before;
    const logged = before ? (card.real ?? 0) - (before.real ?? 0) : 0;
    const statusChanged = Boolean(before) && (before.status || '') !== (card.status || '');
    const estimateChanged = Boolean(before) && before.estimate !== card.estimate && !(before.estimate === null && card.estimate === null);
    const delivered = statusChanged && columnOf(card.status) === 'done';
    // Entra na ata o card que mudou de status, recebeu comentário ou teve horas apontadas; a estimativa aparece junto.
    const changed = statusChanged || card.comments.length > 0 || Math.abs(logged) >= 0.01;
    return {card, entered: !before, statusChanged, estimateChanged, delivered, logged: Math.round(logged * 100) / 100, changed};
  }

  const owners = card => String(card.owner || '').split(',').map(name => name.trim()).filter(Boolean);

  /** Cards da sprint por pessoa (card com dois responsáveis entra nos dois; sem responsável, em "Sem responsável"). */
  function cardsByPerson(changes) {
    const byPerson = new Map();
    for (const card of changes.cards || []) {
      for (const name of owners(card).length ? owners(card) : ['Sem responsável']) {
        if (!byPerson.has(name)) byPerson.set(name, []);
        byPerson.get(name).push(card);
      }
    }
    return byPerson;
  }

  /**
   * Por pessoa, só quem teve card alterado: os cards dela por situação (entregues, em QA, em andamento, impedidos e
   * próximos), as horas apontadas no intervalo e os cards que mudaram de status, receberam comentário ou tiveram horas apontadas (status
   * antes → agora, horas, estimativa e comentários do Monday).
   */
  function peopleSections(changes, columnOf, order) {
    const byPerson = cardsByPerson(changes);
    const rank = name => { const index = order.findIndex(item => item.toLowerCase() === name.toLowerCase()); return index < 0 ? order.length : index; };
    const names = [...byPerson.keys()].sort((a, b) => (a === 'Sem responsável') - (b === 'Sem responsável') || rank(a) - rank(b) || a.localeCompare(b, 'pt-BR'));
    return names.flatMap(name => {
      const cards = byPerson.get(name);
      const items = cards.map(card => cardChange(card, columnOf));
      const moved = items.filter(item => item.changed);
      if (!moved.length) return [];
      const logged = items.reduce((sum, item) => sum + item.logged, 0);
      const count = key => cards.filter(card => columnOf(card.status) === key).length;
      const statusCell = item => item.entered ? `<b>${escape(item.card.status || '—')}</b> <small>(entrou na sprint)</small>`
        : item.statusChanged ? `${escape(item.card.before.status || '—')} → <b>${escape(item.card.status || '—')}</b>${item.delivered ? ' ✅' : ''}` : `${escape(item.card.status || '—')} <small>(sem mudança)</small>`;
      const hoursCell = item => `${Math.abs(item.logged) >= 0.01 ? `<b>${signedHours(item.logged)}</b>` : '—'}<br><small>${hours(item.card.real ?? 0)} de ${hours(item.card.estimate)}${item.estimateChanged ? ` (estimado era ${hours(item.card.before.estimate)})` : ''}</small>`;
      const commentsCell = item => item.card.comments.length ? item.card.comments.map(comment => `<div class="comment"><small>${escape(comment.author)} · ${dateTime(comment.at)}</small><br>${text(comment.text)}</div>`).join('') : '—';
      return [{title: `${name} · ${moved.length} card(s) alterado(s) · ${signedHours(logged)} apontadas`, html: `<p class="counts">${BOARD.map(([key, label]) => `${label} <b>${count(key)}</b>`).join(' · ')}</p>
        ${table(['Card', 'Status', 'Horas apontadas', 'Comentários no card'], moved.map(item => [escape(item.card.name), statusCell(item), hoursCell(item), commentsCell(item)]), '')}`}];
    });
  }

  /** Quem não teve card com mudança de status, comentário nem horas apontadas no intervalo. */
  function quietPeople(changes, columnOf, order) {
    const rank = name => { const index = order.findIndex(item => item.toLowerCase() === name.toLowerCase()); return index < 0 ? order.length : index; };
    return [...cardsByPerson(changes).entries()].filter(([, cards]) => !cards.some(card => cardChange(card, columnOf).changed))
      .map(([name]) => name).sort((a, b) => (a === 'Sem responsável') - (b === 'Sem responsável') || rank(a) - rank(b) || a.localeCompare(b, 'pt-BR'));
  }

  /** Resumo do time no intervalo e avisos sobre o histórico e os comentários. */
  function teamSummary(changes, columnOf, order) {
    const items = (changes.cards || []).map(card => cardChange(card, columnOf));
    const quiet = quietPeople(changes, columnOf, order);
    const logged = items.reduce((sum, item) => sum + item.logged, 0);
    const notes = [];
    if (!changes.historyStart || changes.historyStart > changes.from) notes.push('O histórico do Monday copiado não cobre todo o período: mudanças de status e horas apontadas podem não aparecer.');
    if (!changes.commentsAvailable) notes.push('Não foi possível ler os comentários de alguns cards no Monday.');
    return `<p>Desde <b>${escape(changes.fromLabel)}</b> (${dateTime(changes.from)}) até ${dateTime(changes.to)}:</p>
      ${bullets([
        `<b>${items.filter(item => item.delivered).length}</b> card(s) entregue(s) (passaram a Implementado)`,
        `<b>${items.filter(item => item.statusChanged).length}</b> card(s) mudaram de status`,
        `<b>${items.reduce((sum, item) => sum + item.card.comments.length, 0)}</b> comentário(s) nos cards`,
        `<b>${signedHours(logged)}</b> apontadas nos cards da sprint`,
        ...(quiet.length ? [`Sem mudança de status, comentário nem horas apontadas: ${escape(quiet.join(', '))}`] : []),
      ], '')}
      ${items.some(item => item.changed) ? '<p class="note">Abaixo, por pessoa, só os cards que mudaram de status, receberam comentário ou tiveram horas apontadas.</p>' : `<p class="empty">Nenhum card mudou de status, recebeu comentário ou teve horas apontadas desde ${escape(changes.fromLabel)}.</p>`}
      ${notes.map(note => `<p class="note">${escape(note)}</p>`).join('')}`;
  }

  /**
   * @param data Daily do dia (`/api/daily?sprint=&date=`)
   * @param options.impediments cards impedidos agora (só na Daily de hoje): [{name, owner, status, age}]
   * @param options.changes o que mudou nos cards desde a Daily anterior (`/api/daily/minutes`)
   * @param options.columnOf status do Monday → coluna do Kanban da Daily (done, qa, progress, blocked, pending)
   * @param options.people ordem das pessoas (a mesma da tela)
   */
  function daily(data, {impediments = null, changes = null, columnOf = window.Daily?.column, people = []} = {}) {
    const date = data.date;
    const info = data.presence || {};
    const sprintInfo = (data.sprints || []).find(item => Number(item.number) === Number(data.sprint));
    const sprintName = sprintInfo?.name || `Sprint ${data.sprint}`;
    const actions = data.actions || [];
    const opened = actions.filter(action => action.origin === date);
    const resolved = actions.filter(action => action.status === 'done' && dateOf(action.closedAt) === date);
    const carried = actions.filter(action => action.origin < date && (action.status === 'pending' || dateOf(action.closedAt) > date));
    const situation = action => action.status === 'done' ? `Resolvida em ${day(action.closedAt)}${action.elapsed ? ` (${escape(action.elapsed)})` : ''}` : 'Pendente';
    const status = {running: 'Em andamento', ended: 'Encerrada', idle: 'Não iniciada'}[info.status] || '—';
    const board = changes && columnOf ? [
      {title: 'Resumo do time', html: teamSummary(changes, columnOf, people)},
      ...peopleSections(changes, columnOf, people).map(section => ({...section, person: true})),
    ] : [];
    const sections = [
      {title: 'Objetivo da Sprint', html: paragraph(data.goal, 'Sem objetivo registrado na planning.')},
      ...board,
      {title: `Responsabilidades abertas nesta Daily · ${opened.length}`, html: table(['Questão', 'Responsável', 'Card', 'Aberta por', 'Situação hoje'],
        opened.map(action => [text(action.description), escape(action.responsible?.name || '—'), escape(action.item?.name || '—'), escape(action.openedBy || '—'), situation(action)]),
        'Nenhuma responsabilidade aberta nesta Daily.')},
      {title: `Resolvidas neste dia · ${resolved.length}`, html: table(['Questão', 'Responsável', 'Resolução', 'Tempo de resolução'],
        resolved.map(action => [text(action.description), escape(action.responsible?.name || '—'), text(action.resolution), escape(action.elapsed || '—')]),
        'Nenhuma responsabilidade resolvida neste dia.')},
      {title: `Pendências de Dailies anteriores · ${carried.length}`, html: table(['Questão', 'Responsável', 'Aberta em', 'Situação hoje'],
        carried.map(action => [text(action.description), escape(action.responsible?.name || '—'), day(action.origin), situation(action)]),
        'Nenhuma pendência de Dailies anteriores.')},
    ];
    if (impediments) {
      sections.push({title: `Impedimentos (cards impedidos no Monday) · ${impediments.length}`, html: table(['Card', 'Responsável', 'Status', 'Impedido há'],
        impediments.map(card => [escape(card.name), escape(card.owner || '—'), escape(card.status || '—'), escape(card.age || '—')]), 'Nenhum card impedido.')});
    }
    if (date === data.today && data.retroActions?.length) {
      sections.push({title: `Ações das retrospectivas em aberto · ${data.retroActions.length}`, html: table(['Ação', 'Retro', 'Responsável', 'Prazo'],
        data.retroActions.map(action => [text(action.description), escape(action.retro?.sprintName || '—'), escape(action.responsible?.name || '—'), `${action.dueDate ? day(action.dueDate) : 'sem prazo'}${action.late ? ` · ${action.lateDays} dia(s) atrasada` : ''}`]), '')});
    }
    return {
      ceremony: 'Daily Scrum', title: `Ata da Daily Scrum · ${day(date)}`, file: `Ata Daily Scrum - ${sprintName} - ${day(date).replace(/\//g, '-')}`,
      meta: [
        ['Sprint', escape(sprintName)],
        ['Data', day(date)],
        ['Situação', status],
        ['Início', info.startedAt ? dateTime(info.startedAt) : '—'],
        ['Encerramento', info.closedAt ? dateTime(info.closedAt) : '—'],
        ['Iniciada por', escape(info.startedBy || '—')],
        ['Encerrada por', escape(info.closedBy || '—')],
        ['Duração', info.seconds ? `${duration(info.seconds)} (previsto 15 min)` : '—'],
        ['Presentes', escape(presence(info.attendees))],
      ],
      sections,
    };
  }

  // ── Sprint Review ────────────────────────────────────────────────────────────
  /**
   * @param data resposta do modo leitura da review (`/api/review/sprints/{sprint}`)
   * @param metrics indicadores da sprint (congelados no encerramento ou calculados agora)
   */
  function review(data, metrics = null) {
    const item = data.review;
    const sprint = data.sprint || {};
    const name = item?.sprintName || sprint.name || `Sprint ${sprint.number}`;
    const items = item ? item.items : (data.items || []);
    const delivered = items.filter(entry => entry.kind === 'delivered');
    const pending = items.filter(entry => entry.kind === 'pending');
    const feedbacks = item?.feedbacks || [];
    const count = key => delivered.filter(entry => entry.verdict === key).length;
    const next = pending.filter(entry => entry.destination === 'next');
    const demands = feedbacks.filter(feedback => feedback.demand?.status === 'created');
    return {
      ceremony: 'Sprint Review', title: `Ata da Sprint Review · ${name}`, file: `Ata Sprint Review - ${name}`,
      meta: [
        ['Sprint', escape(name)],
        ['Período', sprint.start ? `${day(sprint.start)} a ${day(sprint.end)}` : '—'],
        ['Data da review', item ? day(item.startedAt) : 'Feita fora do app'],
        ['Situação', !item ? '—' : item.status === 'open' ? 'Em andamento' : `Encerrada em ${dateTime(item.closedAt)}`],
        ['Facilitador', escape(item?.facilitator?.name || '—')],
        ['Duração', item?.elapsedSeconds ? `${duration(item.elapsedSeconds)} (previsto 60 min)` : '—'],
        ['Presentes', escape(presence(item?.attendees))],
      ],
      sections: [
        {title: 'Objetivo da Sprint', html: `${paragraph(item?.sprintGoal ?? data.sprintGoal, 'Sem objetivo registrado na planning.')}
          <p><b>Resultado:</b> ${item?.goalResult ? GOAL[item.goalResult] : 'não avaliado'}${item?.goalNote ? `. ${text(item.goalNote)}` : ''}</p>`},
        {title: 'Resultado da sprint', html: metricsHtml(metrics)},
        {title: `Entregas apresentadas · ${delivered.length}`, html: `${item ? `<p>${count('accepted')} aceita(s) · ${count('adjust')} com ajustes · ${count('rejected')} não aceita(s) · ${count('skipped')} não apresentada(s)</p>` : ''}
          ${table(['Entrega', 'Responsável', 'Tipo / épico', 'Horas (real de estimado)', 'Aceite', 'Observações'],
            delivered.map(entry => [escape(entry.name), escape(entry.owner || '—'), escape([entry.type, entry.epic].filter(Boolean).join(' · ') || '—'), `${hours(entry.real)} de ${hours(entry.estimate)}`,
              `<b>${escape(VERDICT[entry.verdict] || 'Sem avaliação')}</b>`, text(entry.notes)]), 'Nenhuma entrega (card Implementado) nesta sprint.')}`},
        {title: `Planejados não entregues · ${pending.length}`, html: table(['Card', 'Responsável', 'Status no Monday', 'Motivo', 'Destino'],
          pending.map(entry => [escape(entry.name), escape(entry.owner || '—'), escape(entry.status || '—'), escape(REASON[entry.reason] || '—'), escape(DESTINATION[entry.destination] || '—')]),
          'Todos os planejados foram entregues.')},
        {title: `Feedbacks · ${feedbacks.length}`, html: table(['Feedback', 'Quem deu', 'Tipo', 'Entrega', 'Funil de Demandas'],
          feedbacks.map(feedback => [text(feedback.text), escape(feedback.givenBy || feedback.author || '—'), escape(feedback.typeLabel || '—'), escape(feedback.item?.name || '—'),
            feedback.demand?.status === 'created' ? `Virou demanda${feedback.demand.id ? ` #${escape(feedback.demand.id)}` : ''}` : 'Só registrado']),
          'Nenhum feedback registrado.')},
        {title: 'Próximos passos', html: `<p><b>Voltam na próxima sprint:</b></p>${bullets(next.map(entry => `${escape(entry.name)}${entry.owner ? ` · ${escape(entry.owner)}` : ''}`), 'Nenhum planejado marcado para a próxima sprint.')}
          <p><b>Demandas criadas no Funil:</b></p>${bullets(demands.map(feedback => `${escape(feedback.text)}${feedback.demand.id ? ` · #${escape(feedback.demand.id)}` : ''}`), 'Nenhum feedback virou demanda.')}`},
        ...(item ? [{title: 'Tempo por etapa', html: stepsTable(item.steps)}] : []),
      ],
    };
  }

  // ── Sprint Retrospective ─────────────────────────────────────────────────────
  /**
   * @param data resposta do modo leitura da retro (`/api/retro/sprints/{sprint}`)
   * @param metrics indicadores da sprint (congelados no encerramento ou calculados agora)
   */
  function retro(data, metrics = null) {
    const item = data.retro;
    const sprint = data.sprint || {};
    const name = item?.sprintName || sprint.name || `Sprint ${sprint.number}`;
    if (!item) {
      return {ceremony: 'Sprint Retrospective', title: `Ata da Sprint Retrospective · ${name}`, file: `Ata Sprint Retrospective - ${name}`,
        meta: [['Sprint', escape(name)], ['Período', sprint.start ? `${day(sprint.start)} a ${day(sprint.end)}` : '—'], ['Situação', 'Retro feita fora do app']],
        sections: [{title: 'Objetivo da Sprint', html: paragraph(data.sprintGoal, 'Sem objetivo registrado na planning.')}, {title: 'Dados da sprint', html: metricsHtml(metrics)}]};
    }
    const votes = item.goalVotes || {};
    const cards = item.cards || [];
    const cardLine = card => `${card.hidden || card.text === null ? '<em>carta não revelada</em>' : text(card.text)}${item.revealed && card.author ? ` <small>· ${escape(card.author)}</small>` : ''}${item.revealed ? ` <small>· ▲ ${card.votes}</small>` : ''}`
      + (card.merged || []).map(merged => `<br><small>↳ ${merged.hidden || merged.text === null ? 'carta não revelada' : text(merged.text)}</small>`).join('');
    const focus = slot => item.topics.find(topic => topic.focus === slot);
    return {
      ceremony: 'Sprint Retrospective', title: `Ata da Sprint Retrospective · ${name}`, file: `Ata Sprint Retrospective - ${name}`,
      meta: [
        ['Sprint', escape(name)],
        ['Período', sprint.start ? `${day(sprint.start)} a ${day(sprint.end)}` : '—'],
        ['Data da retro', day(item.startedAt)],
        ['Situação', item.status === 'open' ? 'Em andamento' : `Encerrada em ${dateTime(item.closedAt)}`],
        ['Facilitador', escape(item.facilitator?.name || '—')],
        ['Duração', item.elapsedSeconds ? `${duration(item.elapsedSeconds)} (previsto 45 min)` : '—'],
        ['Presentes', escape(presence(item.attendees))],
      ],
      sections: [
        {title: 'Objetivo da retro', html: paragraph(item.goal, 'Sem objetivo.')},
        {title: `Ações das retros anteriores · ${item.previousActions.length}`, html: table(['Ação', 'Retro de origem', 'Responsável', 'Decisão nesta retro', 'Situação'],
          item.previousActions.map(action => [text(action.description), escape(action.retro?.sprintName || '—'), escape(action.responsible?.name || '—'),
            action.review ? `${escape(PREVIOUS_DECISION[action.review.type] || action.review.type)}${action.review.note ? `: ${text(action.review.note)}` : ''}` : '—', escape(ACTION_STATUS[action.status] || action.status)]),
          'Nenhuma ação aberta das retros anteriores.')},
        {title: 'O que os dados mostram', html: `${metricsHtml(metrics)}
          <p><b>Objetivo da sprint:</b> ${item.sprintGoal ? text(item.sprintGoal) : '<em>sem objetivo registrado na planning</em>'}</p>
          <p><b>O time atingiu o objetivo?</b> Sim ${votes.yes || 0} · Parcial ${votes.partial || 0} · Não ${votes.no || 0}</p>
          ${reviewLine(data.review)}
          ${item.context ? `<p><b>Contexto observado:</b> ${text(item.context)}</p>` : ''}`},
        {title: 'A visão do time', html: Object.entries(COLUMN).map(([key, label]) => {
          const list = cards.filter(card => card.column === key).sort((a, b) => (b.votes || 0) - (a.votes || 0));
          return `<p><b>${label} · ${list.length}</b></p>${bullets(list.map(cardLine), 'Nenhuma carta.')}`;
        }).join('') + (item.revealed ? '' : '<p class="note">As cartas não foram reveladas.</p>')},
        {title: `Discussão · ${item.topics.length} tema(s)`, html: table(['Tema', 'Origem', 'Votos', 'Anotações'],
          item.topics.map(topic => [`${escape(topic.title)}${topic.question ? `<br><small>${text(topic.question)}</small>` : ''}`, escape(topic.column ? COLUMN[topic.column] : 'Tema do facilitador'), topic.votes ?? '—', text(topic.notes)]),
          'Nenhum tema discutido.')},
        {title: `Focos para a Sprint ${item.sprint + 1}`, html: bullets([1, 2].map(slot => `<b>Foco ${slot}:</b> ${focus(slot) ? escape(focus(slot).title) : 'não escolhido'}`), '')},
        {title: `Plano de ação · ${item.actions.length}`, html: table(['Ação', 'Responsável', 'Prazo', 'Sinal de resultado', 'Foco', 'Card', 'Situação'],
          item.actions.map(action => [text(action.description), escape(action.responsible?.name || '—'), action.dueDate ? day(action.dueDate) : '—', text(action.signal),
            action.focus ? `Foco ${action.focus}` : '—', escape(action.item?.name || '—'), `${escape(ACTION_STATUS[action.status] || action.status)}${action.late ? ' (atrasada)' : ''}`]),
          'Nenhuma ação no plano.') + (item.dependencies ? `<p><b>Dependências a mapear:</b> ${text(item.dependencies)}</p>` : '')},
        {title: 'Tempo por etapa', html: stepsTable(item.steps)},
      ],
    };
  }

  // ── Documento e impressão ────────────────────────────────────────────────────
  const STYLE = `@page{size:A4;margin:16mm 14mm}
    *{box-sizing:border-box}
    body{margin:0;color:#1f2937;font:11.5px/1.5 "Segoe UI",Roboto,Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    header{border-bottom:3px solid #1b6cdd;padding-bottom:10px;margin-bottom:14px}
    header small{color:#1b6cdd;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
    h1{margin:4px 0 0;font-size:20px;color:#0f172a}
    .meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 18px;margin:0 0 16px;padding:10px 12px;border:1px solid #d6dde8;border-radius:6px;background:#f5f8fc}
    .meta div{display:flex;gap:6px}.meta dt{min-width:104px;color:#64748b;font-weight:600}.meta dd{margin:0}
    .meta .wide{grid-column:1/-1}
    section{margin:0 0 14px;break-inside:auto}
    h2{margin:0 0 6px;padding:4px 8px;border-left:4px solid #1b6cdd;background:#eef4fd;font-size:13px;color:#0f172a;break-after:avoid}
    p{margin:4px 0}ul{margin:4px 0;padding-left:18px}li{margin:2px 0}
    table{width:100%;border-collapse:collapse;margin:4px 0;font-size:10.5px}
    th,td{border:1px solid #d6dde8;padding:4px 6px;text-align:left;vertical-align:top}
    th{background:#f1f5f9;color:#334155;font-weight:700}
    tr{break-inside:avoid}
    small{color:#64748b}.empty,.note{color:#64748b;font-style:italic}
    .person h2{border-left-color:#7c3aed;background:#f4f0fe}
    .counts{margin:2px 0 6px;color:#334155}
    .comment{margin:0 0 4px}.comment:last-child{margin:0}
    footer{margin-top:18px;padding-top:8px;border-top:1px solid #d6dde8;color:#64748b;font-size:10px}`;

  /** Documento HTML completo da ata (cabeçalho, quadro de dados da reunião, seções e rodapé). */
  function documentHtml(minutes) {
    return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>${escape(minutes.file)}</title><style>${STYLE}</style></head><body>
      <header><small>YUV Sprint Metrics · Ata de reunião · ${escape(minutes.ceremony)}</small><h1>${escape(minutes.title)}</h1></header>
      <dl class="meta">${minutes.meta.map(([label, value]) => `<div class="${label === 'Presentes' ? 'wide' : ''}"><dt>${escape(label)}</dt> <dd>${value}</dd></div>`).join('')}</dl>
      ${minutes.sections.map(section => `<section class="${section.person ? 'person' : ''}"><h2>${escape(section.title)}</h2>${section.html}</section>`).join('')}
      <footer>Ata gerada em ${dateTime(new Date().toISOString())} pelo YUV Sprint Metrics, com os dados registrados na cerimônia e a base sincronizada do Monday.</footer>
    </body></html>`;
  }

  /**
   * Abre a impressão da ata num documento à parte (a tela atual não muda). No diálogo do navegador, "Salvar como PDF"
   * gera o arquivo, já com o nome da ata.
   */
  function print(minutes) {
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:794px;height:1123px;border:0';
    document.body.append(frame);
    const doc = frame.contentDocument;
    doc.open(); doc.write(documentHtml(minutes)); doc.close();
    const previous = document.title;
    document.title = minutes.file;
    let done = false;
    const cleanup = () => { if (done) return; done = true; document.title = previous; frame.remove(); };
    frame.contentWindow.addEventListener('afterprint', () => setTimeout(cleanup, 300), {once: true});
    setTimeout(() => {
      try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (error) { cleanup(); alert(`Não foi possível abrir o resumo: ${error.message}`); }
    }, 60);
  }

  /** Ícone e rótulo do botão Resumo, igual em todas as telas. */
  const buttonLabel = '📄 Resumo';

  return {planning, daily, review, retro, documentHtml, print, buttonLabel, dateOf};
})();
