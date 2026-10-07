/* Painel de consulta do Monday. A credencial permanece no servidor local. */
window.Bombeiro = (() => {
  const CYCLE_MS = 60_000;
  const ALARM_SECONDS = 8.13;
  const ALARM_URL = new URL('den-den-mushi-short.m4a', document.currentScript.src).href;
  const state = {
    active: false, timer: null, inFlight: false, rows: [], allRows: [],
    sprint: null, pendingSprint: null, boardName: '', lastSuccess: null,
    stale: false, newIds: new Set(), audioEnabled: true, audioContext: null,
    activeSound: null, alertPulseTimer: null, audioBuffer: null, audioLoading: null
  };

  function normalized(value) { return String(value ?? '').trim().toLocaleLowerCase('pt-BR'); }
  function filterSupport(rows) {
    return rows.filter(row => String(row.Id || '').trim() && normalized(row.Tipo) === 'suporte');
  }
  function statusBucket(status) {
    const value = normalized(status);
    return value === 'backlog' ? 'backlog' : window.SprintRules.isClosed(status) ? 'done' : 'progress';
  }
  function priorityMeta(priority) {
    const label = String(priority ?? '').trim() || 'Não informada';
    const key = normalized(label).normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const classes = { critica: ['critical', 0], alta: ['high', 1], media: ['medium', 2], baixa: ['low', 3] };
    const [css, rank] = classes[key] || ['neutral', 4];
    return { label, css, rank };
  }
  function createTracker() {
    const seen = new Set();
    let initialized = false;
    return {
      accept(rows) {
        const support = filterSupport(rows);
        const fresh = initialized ? support.filter(row => !seen.has(String(row.Id))) : [];
        for (const row of support) seen.add(String(row.Id));
        initialized = true;
        return { support, fresh };
      }
    };
  }
  const tracker = createTracker();
  const el = id => document.getElementById(id);

  function selectedRows(rows, sprint) {
    const filtered = sprint === 'all' ? rows : sprint === 'unassigned'
      ? rows.filter(row => row.Sprint == null)
      : rows.filter(row => Number(row.Sprint) === sprint);
    return [...new Map(filtered.map(row => [String(row.Id), row])).values()];
  }
  function sortByPriority(rows) {
    return [...rows].sort((a, b) => priorityMeta(a.Prioridade).rank - priorityMeta(b.Prioridade).rank ||
      String(a.Nome).localeCompare(String(b.Nome), 'pt-BR'));
  }
  function safeMondayUrl(value) {
    try {
      const url = new URL(value);
      return url.protocol === 'https:' && (url.hostname === 'monday.com' || url.hostname.endsWith('.monday.com')) ? url.href : '';
    } catch { return ''; }
  }
  function badge(priority) {
    const info = priorityMeta(priority);
    const tag = document.createElement('span');
    tag.className = `fire-priority ${info.css}`;
    tag.textContent = info.label;
    return tag;
  }
  function card(row, bucket) {
    const cancelled = normalized(row.Status) === 'cancelado';
    const node = document.createElement('article');
    node.className = 'fire-task' + (cancelled ? ' cancelled' : '') + (state.newIds.has(String(row.Id)) ? ' new' : '');
    const title = document.createElement('div');
    title.className = 'fire-task-title';
    title.append(document.createTextNode(String(row.Nome || 'Sem título')));
    if (bucket === 'progress' || cancelled) {
      const suffix = document.createElement('span');
      suffix.className = 'fire-original-status';
      suffix.textContent = ` (${String(row.Status || '').trim() || 'Status não informado'})`;
      title.append(suffix);
    }
    node.append(title);
    const meta = document.createElement('div');
    meta.className = 'fire-task-meta';
    meta.append(badge(row.Prioridade));
    if (String(row.Responsavel || '').trim()) {
      const owner = document.createElement('span');
      owner.className = 'fire-owner';
      owner.textContent = String(row.Responsavel).trim();
      meta.append(owner);
    }
    const url = safeMondayUrl(row.Url);
    if (url) {
      const link = document.createElement('a');
      link.className = 'fire-task-link';
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = 'Abrir no Monday ↗';
      meta.append(link);
    }
    node.append(meta);
    return node;
  }
  function updateSprintOptions() {
    const select = el('fire-sprint');
    const sprints = [...new Set(state.allRows.filter(row => row.Sprint != null).map(row => Number(row.Sprint)).filter(n => Number.isInteger(n) && n > 0))].sort((a, b) => b - a);
    const hasUnassigned = state.rows.some(row => row.Sprint == null);
    const desired = state.pendingSprint && sprints.includes(state.pendingSprint) ? state.pendingSprint : state.sprint;
    const chosen = desired === 'all' || (desired === 'unassigned' && hasUnassigned) || sprints.includes(desired) ? desired : (sprints[0] ?? (hasUnassigned ? 'unassigned' : 'all'));
    state.pendingSprint = null;
    state.sprint = chosen;
    select.replaceChildren(new Option('Todas as sprints', 'all'), ...sprints.map(s => new Option(`Sprint ${s}`, String(s))));
    if (hasUnassigned) select.append(new Option('Sem sprint', 'unassigned'));
    select.value = String(chosen);
  }
  function fitLanes() {
    if (!state.active || !el('page-bombeiro').classList.contains('active')) return;
    const board = el('fire-board');
    if (window.innerWidth > 950) {
      const available = Math.max(240, window.innerHeight - board.getBoundingClientRect().top - 12);
      board.style.setProperty('--fire-board-height', `${available}px`);
    } else {
      board.style.removeProperty('--fire-board-height');
    }
    for (const id of ['fire-backlog', 'fire-progress', 'fire-done']) {
      const list = el(id);
      list.style.setProperty('--fire-fit', '1');
      if (list.scrollHeight <= list.clientHeight + 1) continue;
      let low = 0.25, high = 1;
      for (let i = 0; i < 10; i++) {
        const middle = (low + high) / 2;
        list.style.setProperty('--fire-fit', String(middle));
        if (list.scrollHeight <= list.clientHeight + 1) low = middle;
        else high = middle;
      }
      list.style.setProperty('--fire-fit', String(low));
    }
  }
  function render() {
    if (!el('page-bombeiro')) return;
    const rows = selectedRows(state.rows, state.sprint);
    const counts = ['backlog', 'progress', 'done'].map(bucket => rows.filter(row => statusBucket(row.Status) === bucket).length);
    const doneCount = counts[2];
    const printZoom = Math.max(0.35, Math.min(0.65, 8.5 / Math.max(1, ...counts)));
    el('page-bombeiro').style.setProperty('--fire-print-zoom', String(printZoom));
    el('fire-board').classList.toggle('done-wide', doneCount >= 7);
    for (const [bucket, id] of [['backlog', 'fire-backlog'], ['progress', 'fire-progress'], ['done', 'fire-done']]) {
      const laneRows = sortByPriority(rows.filter(row => statusBucket(row.Status) === bucket));
      el(`${id}-count`).textContent = String(laneRows.length);
      const list = el(id);
      list.classList.toggle('two-up', bucket === 'done' && laneRows.length >= 7);
      list.replaceChildren();
      if (!laneRows.length) {
        const empty = document.createElement('div');
        empty.className = 'fire-empty';
        empty.textContent = state.lastSuccess ? 'Nenhuma demanda nesta coluna.' : 'Aguardando dados do Monday...';
        list.append(empty);
      } else {
        list.append(...laneRows.map(row => card(row, bucket)));
      }
    }
    const sprintLabel = state.sprint === 'all' ? 'Todas as sprints' : state.sprint === 'unassigned' ? 'Sem sprint' : `Sprint ${state.sprint || '—'}`;
    el('fire-context').textContent = `${state.boardName || 'Quadro de tarefas'} · ${sprintLabel} · demandas de Suporte`;
    if (state.active) el('tb-right').textContent = state.sprint === 'all' ? 'Todas' : state.sprint === 'unassigned' ? 'Sem sprint' : `S${state.sprint || '—'}`;
    el('fire-last-sync').textContent = state.lastSuccess
      ? `Última atualização: ${state.lastSuccess.toLocaleString('pt-BR')}${state.stale ? ' · dados desatualizados' : ''}`
      : 'Aguardando primeira consulta';
    fitLanes();
  }
  function showAlert(rows) {
    if (!rows.length || !state.active) return;
    const banner = el('fire-alert');
    el('fire-alert-title').textContent = rows.length === 1 ? 'Nova demanda de suporte' : `${rows.length} novas demandas de suporte`;
    const items = el('fire-alert-items');
    items.replaceChildren();
    for (const row of sortByPriority(rows)) {
      const item = document.createElement('span');
      item.className = 'fire-alert-item';
      const name = document.createElement('span');
      name.textContent = String(row.Nome || 'Sem título');
      item.append(name, badge(row.Prioridade));
      items.append(item);
    }
    banner.hidden = false;
    startAlertPulse();
    fitLanes();
    if (state.audioEnabled) {
      try {
        playSound();
      } catch {
        setAudioUi();
        setAudioMessage('Áudio ainda não liberado pelo navegador. Clique ou toque na página para ativá-lo.', true);
      }
    }
  }
  function stopAlertPulse() {
    clearTimeout(state.alertPulseTimer);
    state.alertPulseTimer = null;
    el('fire-alert').classList.remove('is-ringing');
  }
  function startAlertPulse() {
    stopAlertPulse();
    const banner = el('fire-alert');
    void banner.offsetWidth;
    banner.classList.add('is-ringing');
    state.alertPulseTimer = setTimeout(stopAlertPulse, (state.audioBuffer?.duration || ALARM_SECONDS) * 1_000);
  }
  async function ensureAudioContext() {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) throw new Error('Áudio indisponível neste navegador.');
    if (!state.audioContext || state.audioContext.state === 'closed') state.audioContext = new AudioContextClass();
    if (state.audioContext.state !== 'running') await state.audioContext.resume();
    if (state.audioContext.state !== 'running') throw new Error('O navegador bloqueou o áudio. Tente clicar novamente.');
    if (!state.audioBuffer) {
      if (!state.audioLoading) {
        state.audioLoading = (async () => {
          const response = await fetch(ALARM_URL);
          if (!response.ok) throw new Error('Não foi possível carregar o toque Den Den Mushi. Tente novamente.');
          state.audioBuffer = await state.audioContext.decodeAudioData(await response.arrayBuffer());
        })().finally(() => { state.audioLoading = null; });
      }
      await state.audioLoading;
    }
    return state.audioContext;
  }
  function stopSound() {
    if (!state.activeSound) return;
    const { context, gain, source } = state.activeSound;
    const now = context.currentTime;
    gain.gain.cancelScheduledValues(now);
    gain.gain.setValueAtTime(0, now);
    source.stop(now);
    state.activeSound = null;
  }
  function playSound(durationSeconds = null) {
    const context = state.audioContext;
    if (!context || context.state !== 'running' || !state.audioBuffer) throw new Error('O áudio não está ativo neste navegador.');
    durationSeconds = durationSeconds ?? state.audioBuffer.duration;
    stopSound();
    const start = context.currentTime + 0.02;
    const source = context.createBufferSource();
    const gain = context.createGain();
    source.buffer = state.audioBuffer;
    source.loop = false;
    gain.gain.setValueAtTime(0.001, start);
    gain.gain.linearRampToValueAtTime(0.22, start + 0.08);
    gain.gain.setValueAtTime(0.22, start + durationSeconds - 0.3);
    gain.gain.linearRampToValueAtTime(0.001, start + durationSeconds);
    source.connect(gain);
    gain.connect(context.destination);
    state.activeSound = { context, gain, source };
    source.onended = () => {
      source.disconnect();gain.disconnect();
      if (state.activeSound?.gain === gain) state.activeSound = null;
    };
    source.start(start);
    source.stop(start + durationSeconds);
  }
  function setAudioMessage(message, warning = false) {
    const status = el('fire-audio-state');
    status.textContent = message;
    status.classList.toggle('warning', warning);
  }
  function setAudioUi() {
    el('fire-audio-toggle').textContent = state.audioEnabled ? 'Silenciar alertas' : 'Ativar alertas sonoros';
    el('fire-audio-toggle').setAttribute('aria-pressed', String(state.audioEnabled));
    const ready = state.audioEnabled && state.audioContext?.state === 'running' && !!state.audioBuffer;
    setAudioMessage(!state.audioEnabled ? 'Som silenciado · alertas visuais ativos'
      : ready ? 'Den Den Mushi ativo · alertas visuais ativos'
        : 'Alertas sonoros ativados · clique ou toque na página para liberar o áudio');
    el('fire-audio-state').classList.toggle('on', ready);
  }
  async function unlockAudioOnInteraction(event) {
    if (!state.active || !state.audioEnabled || (state.audioContext?.state === 'running' && state.audioBuffer) ||
        event.target.closest?.('#fire-audio-toggle, #fire-audio-test')) return;
    try {
      await ensureAudioContext();
      if (state.audioEnabled) setAudioUi();
    } catch (error) {
      if (state.audioEnabled) setAudioMessage(error.message, true);
    }
  }
  async function toggleAudio() {
    if (state.audioEnabled) {
      state.audioEnabled = false;
      stopSound();
      stopAlertPulse();
      setAudioUi();
      return;
    }
    try {
      await ensureAudioContext();
      playSound(0.8);
      state.audioEnabled = true;
      setAudioUi();
    } catch (error) {
      state.audioEnabled = false;
      setAudioUi();
      setAudioMessage(error.message, true);
    }
  }
  async function testAudio() {
    try {
      await ensureAudioContext();
      playSound();
      setAudioMessage(state.audioEnabled
        ? 'Teste do toque completo iniciado · alertas sonoros ativos'
        : 'Teste do toque completo iniciado · alertas continuam silenciados');
    } catch (error) {
      setAudioMessage(error.message, true);
    }
  }
  async function refresh(manual = false) {
    if (!state.active || state.inFlight) return;
    const startedAt = Date.now();
    clearTimeout(state.timer);
    state.timer = null;
    state.inFlight = true;
    el('fire-refresh').disabled = true;
    try {
      const result = await mondayRequest('/api/sync', { bombeiro: true, force: manual === true });
      if (!Array.isArray(result.rows)) throw new Error('Resposta sem lista de tarefas.');
      const allRows = [...result.rows, ...(Array.isArray(result.unassignedSupport) ? result.unassignedSupport : [])];
      const accepted = tracker.accept(allRows);
      state.rows = accepted.support;
      state.allRows = allRows;
      state.boardName = String(result.boardName || 'Quadro de tarefas');
      state.lastSuccess = new Date(result.syncedAt);
      state.stale = false;
      state.newIds = new Set(accepted.fresh.map(row => String(row.Id)));
      el('fire-error').hidden = true;
      updateSprintOptions();
      render();
      const shown = selectedRows(accepted.fresh, state.sprint);
      showAlert(shown);
    } catch (error) {
      state.stale = true;
      el('fire-error').textContent = `${state.lastSuccess ? 'Dados desatualizados. ' : 'Não foi possível carregar os dados. '}Falha na consulta ao Monday: ${error.message}. Nova tentativa no próximo ciclo de 60 segundos.`;
      el('fire-error').hidden = false;
      render();
    } finally {
      state.inFlight = false;
      el('fire-refresh').disabled = false;
      if (state.active) state.timer = setTimeout(() => refresh(false), Math.max(1_000, CYCLE_MS - (Date.now() - startedAt)));
    }
  }
  function enter(sprintHint = null) {
    state.active = true;
    document.body.classList.add('fire-view');
    if (Number.isInteger(sprintHint)) state.pendingSprint = sprintHint;
    if (state.allRows.length) updateSprintOptions();
    render();
    refresh(false);
  }
  function leave() {
    state.active = false;
    stopSound();
    stopAlertPulse();
    document.body.classList.remove('fire-view');
    clearTimeout(state.timer);
    state.timer = null;
  }
  document.addEventListener('DOMContentLoaded', () => {
    if (!el('page-bombeiro')) return;
    el('fire-sprint').addEventListener('change', event => {
      state.sprint = event.target.value === 'all' || event.target.value === 'unassigned' ? event.target.value : Number(event.target.value);
      state.newIds.clear();
      render();
    });
    el('fire-refresh').addEventListener('click', () => refresh(true));
    el('fire-audio-toggle').addEventListener('click', toggleAudio);
    el('fire-audio-test').addEventListener('click', testAudio);
    document.addEventListener('pointerdown', unlockAudioOnInteraction, true);
    document.addEventListener('keydown', unlockAudioOnInteraction, true);
    el('fire-alert-close').addEventListener('click', () => { el('fire-alert').hidden = true; stopSound(); stopAlertPulse(); fitLanes(); });
    window.addEventListener('resize', () => requestAnimationFrame(fitLanes));
    setAudioUi();
    render();
  });
  return { enter, leave, refresh, filterSupport, statusBucket, priorityMeta, createTracker, selectedRows, sortByPriority };
})();
