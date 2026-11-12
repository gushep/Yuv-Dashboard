/* Integração Monday via backend Laravel; credenciais nunca ficam no navegador. */
const SNAPSHOT_DATA = DATA.slice();
let mondayColumns = [];
let mondaySavedMap = {};
let mondayReady = false;
let mondayRefreshMinutes = 5;
let mondayRefreshTimer = null;
let mondaySyncInFlight = false;
/** Consultas seguidas enquanto o servidor ainda atualiza um snapshot vencido (limitadas, caso o Monday esteja fora). */
let mondayFollowUps = 0;
const MONDAY_FOLLOW_UP_MS = 15000;
const MONDAY_MAX_FOLLOW_UPS = 4;
const mondayFields = [
  ['RemainingH', 'Esforço restante revisado (h)', ['Esforço restante', 'Horas restantes', 'Tempo restante', 'RemainingH']],
  ['Impedimento', 'Impedimento', ['Impedimento', 'Bloqueio']],
  ['Responsavel', 'Responsável', ['Resp.', 'Responsável', 'Responsavel', 'Pessoa', 'Pessoas']],
  ['Status', 'Status', ['Status']],
  ['Prioridade', 'Prioridade', ['Prioridade']],
  ['Tipo', 'Tipo', ['Tipo']],
  ['EstH', 'Tempo estimado (h)', ['Tempo Estimado', 'Tempo estimado', 'EstH']],
  ['RealH', 'Tempo real (h)', ['Tempo Real', 'Tempo real', 'RealH']],
  ['Epico', 'Épico', ['Épico', 'Epico']],
  ['Sprint', 'Sprint *', ['Sprint']],
  ['Planejada', 'Planejada', ['Planejada']],
  ['Retrabalho', 'Retrabalho', ['Retrabalho']],
  ['BugProducao', 'Bug Produção', ['Bug Produção', 'Bug Producao']],
  ['ValorAgregado', 'Melhorias', ['Melhorias', 'Melhoria', 'Novas Funcionalidades', 'Nova Funcionalidade', 'Valor Agregado']]
];

function mondayMessage(id, message, kind = '') {
  const el = document.getElementById(id);
  el.textContent = message;
  el.className = `settings-message ${kind}`;
}

async function mondayRequest(path, body) {
  const options = body === undefined ? {} : {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
    body: JSON.stringify(body)
  };
  const response = await fetch(path, options);
  if (response.redirected && new URL(response.url).pathname === '/login') { location.assign('/login'); throw new Error('Sessão encerrada. Faça login novamente.'); }
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || result.message || 'Falha na comunicação com o servidor.');
  return result;
}

function mondayBoardId() {
  const value = document.getElementById('monday-board').value.trim();
  const match = value.match(/(?:\/boards\/)?(\d+)(?:\/|$)/);
  if (!match) throw new Error('Informe o ID numérico do quadro ou o link do quadro.');
  return match[1];
}

function normalizedTitle(value) {
  return String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
}

function renderMondayMapping() {
  const grid = document.getElementById('monday-mapping');
  grid.replaceChildren();
  for (const [key, label, aliases] of mondayFields) {
    const wrapper = document.createElement('label');
    wrapper.className = 'settings-field';
    wrapper.textContent = label;
    const select = document.createElement('select');
    select.dataset.field = key;
    select.appendChild(new Option('— Não usar —', ''));
    for (const column of mondayColumns) {
      select.appendChild(new Option(`${column.title} (${column.type})`, column.id));
    }
    const guessed = mondayColumns.find(c => aliases.some(a => normalizedTitle(a) === normalizedTitle(c.title)));
    select.value = mondaySavedMap[key] && mondayColumns.some(c => c.id === mondaySavedMap[key])
      ? mondaySavedMap[key] : guessed?.id || '';
    wrapper.appendChild(select);
    grid.appendChild(wrapper);
  }
  document.getElementById('monday-mapping-card').style.display = '';
}

function chooseMondayBoard() {
  const value = document.getElementById('monday-board-picker').value;
  if (value) document.getElementById('monday-board').value = value;
}

async function findMondayBoards() {
  const button = document.getElementById('monday-boards');
  button.disabled = true;
  mondayMessage('monday-connection', 'Localizando quadros acessíveis...');
  try {
    const token = document.getElementById('monday-token').value.trim();
    const result = await mondayRequest('/api/boards', { token });
    const picker = document.getElementById('monday-board-picker');
    picker.replaceChildren();
    picker.appendChild(new Option('Selecione um quadro', ''));
    for (const board of result.boards) picker.appendChild(new Option(`${board.name} (${board.id})`, board.id));
    document.getElementById('monday-board-picker-wrap').style.display = '';
    if (result.boards.length === 1) {
      picker.value = result.boards[0].id;
      chooseMondayBoard();
    }
    mondayMessage('monday-connection', `${result.boards.length} quadro(s) disponível(is). Selecione o de tarefas e clique em Buscar colunas.`, 'success');
  } catch (error) {
    mondayMessage('monday-connection', error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

/** As colunas do quadro só são consultadas no Monday quando a tela Configurações é aberta, não a cada carregamento. */
function enterMondaySettings() {
  if (mondayReady && !mondayColumns.length) inspectMonday();
  if (mondayReady) loadTeamUsers();
  else renderTeamSettings();
}

async function inspectMonday() {
  const button = document.getElementById('monday-inspect');
  button.disabled = true;
  mondayMessage('monday-connection', 'Consultando colunas do quadro...');
  try {
    const boardId = mondayBoardId();
    const token = document.getElementById('monday-token').value.trim();
    const result = await mondayRequest('/api/inspect', { boardId, token });
    mondayColumns = result.columns;
    renderMondayMapping();
    mondayMessage('monday-connection', `Quadro “${result.boardName}” encontrado. Confira as colunas antes de salvar.`, 'success');
  } catch (error) {
    mondayMessage('monday-connection', error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

async function saveMonday() {
  const button = document.getElementById('monday-save');
  button.disabled = true;
  mondayMessage('monday-sync-status', 'Salvando configuração...');
  try {
    const boardId = mondayBoardId();
    const token = document.getElementById('monday-token').value.trim();
    const map = Object.fromEntries([...document.querySelectorAll('#monday-mapping select')]
      .map(select => [select.dataset.field, select.value]));
    if (!map.Sprint) throw new Error('Selecione a coluna Sprint.');
    const refreshMinutes = Number(document.getElementById('monday-refresh').value);
    const result = await mondayRequest('/api/config', { boardId, token, map, refreshMinutes });
    mondaySavedMap = map;
    mondayReady = true;
    mondayRefreshMinutes = result.refreshMinutes;
    document.getElementById('monday-token').value = '';
    mondayMessage('monday-connection', `Configuração salva para “${result.boardName}”. Token criptografado no servidor.`, 'success');
    await syncMonday(true);
  } catch (error) {
    mondayMessage('monday-sync-status', error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

/** Quadros das abas Roadmap e Backlog da tela Tarefas; o servidor confere que o token consegue lê-los antes de gravar. */
async function saveBoardTabs() {
  const button = document.getElementById('board-tabs-save');
  button.disabled = true;
  mondayMessage('board-tabs-status', 'Conferindo os quadros no Monday...');
  // Aceita o ID ou o link do quadro.
  const boardId = id => { const value = document.getElementById(id).value.trim(); return value.match(/(?:\/boards\/)?(\d+)(?:\/|$)/)?.[1] || value; };
  try {
    const result = await mondayRequest('/api/board-tabs', { roadmapBoardId: boardId('roadmap-board'), backlogBoardId: boardId('backlog-board') });
    document.getElementById('roadmap-board').value = result.roadmapBoardId || '';
    document.getElementById('backlog-board').value = result.backlogBoardId || '';
    const describe = (label, name) => name ? `${label}: “${name}”` : `${label}: desligado`;
    mondayMessage('board-tabs-status', `Salvo. ${describe('Roadmap', result.boardNames.roadmap)} · ${describe('Backlog', result.boardNames.backlog)}.`, 'success');
    Object.values(window.RAW_BOARDS || {}).forEach(board => Object.assign(board, { loaded: false, rows: [], error: '' }));
  } catch (error) {
    mondayMessage('board-tabs-status', error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

/**
 * Times da capacidade nas Configurações: usuários ativos do Monday, cada um em Full stack, Projetos ou sem time.
 * `teamDraft` guarda a escolha da tela até salvar (nome normalizado => pessoa).
 */
const teamState = { users: null, loading: false, error: '', draft: new Map() };

function teamDraftFromSettings() {
  teamState.draft = new Map(SprintRules.settings.team.map(member => [SprintRules.normalize(member.name), { ...member }]));
}

function applyMetricSettings(metrics = {}) {
  SprintRules.applySettings(metrics);
  document.getElementById('metric-capacity').value = String(SprintRules.settings.capacityHours);
  teamDraftFromSettings();
  renderTeamSettings();
}

async function loadTeamUsers() {
  if (teamState.loading || teamState.users) return;
  teamState.loading = true; teamState.error = ''; renderTeamSettings();
  try { teamState.users = (await mondayRequest('/api/monday-users')).users || []; }
  catch (error) { teamState.error = `Não foi possível buscar os usuários do Monday: ${error.message}`; }
  finally { teamState.loading = false; renderTeamSettings(); }
}

function renderTeamSettings() {
  const list = document.getElementById('team-list');
  if (!list) return;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const active = new Set((teamState.users || []).map(user => SprintRules.normalize(user.name)));
  // Ativos do Monday + classificados que não estão mais ativos (para poder tirá-los do time).
  const people = [...(teamState.users || [])];
  for (const member of teamState.draft.values()) if (!active.has(SprintRules.normalize(member.name))) people.push({ id: member.id, name: member.name, inactive: true });
  people.sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'));
  const search = SprintRules.normalize(document.getElementById('team-search').value);
  const onlyClassified = document.getElementById('team-only-classified').checked;
  const groupOf = name => teamState.draft.get(SprintRules.normalize(name))?.group || '';
  const shown = people.filter(person => (!search || SprintRules.normalize(person.name).includes(search)) && (!onlyClassified || groupOf(person.name)));
  const counts = { fullstack: 0, projects: 0 };
  for (const member of teamState.draft.values()) counts[member.group]++;
  document.getElementById('team-counts').textContent = `Full stack: ${counts.fullstack} · Projetos: ${counts.projects}${teamState.users ? ` · Sem time: ${Math.max(0, teamState.users.length - counts.fullstack - counts.projects)}` : ''}`;
  const legacy = document.getElementById('team-legacy');
  legacy.hidden = teamState.draft.size > 0;
  legacy.textContent = SprintRules.settings.excludedPeople.length
    ? `Ninguém classificado ainda: até salvar os times, todos contam como Full stack, exceto ${SprintRules.settings.excludedPeople.join(', ')}.`
    : 'Ninguém classificado ainda: até salvar os times, todos contam como Full stack.';
  if (teamState.loading && !teamState.users) { list.innerHTML = '<p class="team-empty">Buscando usuários ativos do Monday…</p>'; return; }
  const notice = teamState.error ? `<p class="team-empty team-error">${escape(teamState.error)} Exibindo só as pessoas já classificadas.</p>` : '';
  if (!shown.length) { list.innerHTML = `${notice}<p class="team-empty">${people.length ? 'Ninguém encontrado com esse filtro.' : 'Nenhum usuário ativo encontrado. Confira a conexão com o Monday.'}</p>`; return; }
  list.innerHTML = notice + shown.map(person => {
    const group = groupOf(person.name);
    const option = (value, label) => `<label class="team-choice ${value || 'none'}${group === value ? ' selected' : ''}"><input type="radio" name="team-${escape(person.id)}" value="${value}"${group === value ? ' checked' : ''}>${label}</label>`;
    return `<div class="team-row" role="listitem" data-id="${escape(person.id)}" data-name="${escape(person.name)}"><span class="team-name">${escape(person.name)}${person.inactive ? '<small>inativo no Monday</small>' : ''}</span><span class="team-choices" role="radiogroup" aria-label="Time de ${escape(person.name)}">${option('fullstack', 'Full stack')}${option('projects', 'Projetos (IA)')}${option('', 'Sem time')}</span></div>`;
  }).join('');
}

function chooseTeam(event) {
  const input = event.target.closest('input[type=radio]');
  if (!input) return;
  const row = input.closest('.team-row');
  const key = SprintRules.normalize(row.dataset.name);
  if (input.value) teamState.draft.set(key, { id: row.dataset.id, name: row.dataset.name, group: input.value });
  else teamState.draft.delete(key);
  renderTeamSettings();
}

async function saveMetricSettings() {
  const button = document.getElementById('metric-save');
  button.disabled = true;
  try {
    const capacityHours = Number(String(document.getElementById('metric-capacity').value).replace(',', '.'));
    const team = [...teamState.draft.values()].map(({ id, name, group }) => ({ id, name, group }));
    const result = await mondayRequest('/api/metric-settings', { capacityHours, team });
    applyMetricSettings(result.metrics);
    if (DATA.length) useMondayRows(DATA.slice());
    mondayMessage('metric-status', 'Regras e times salvos. Os indicadores já usam os novos valores.', 'success');
  } catch (error) {
    mondayMessage('metric-status', error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('team-list')?.addEventListener('change', chooseTeam);
  document.getElementById('team-search')?.addEventListener('input', renderTeamSettings);
  document.getElementById('team-only-classified')?.addEventListener('change', renderTeamSettings);
});

function scheduleMondayRefresh() {
  clearTimeout(mondayRefreshTimer);
  mondayRefreshTimer = null;
  if (currentPage === 'bombeiro' || currentPage === 'sprint-panel' || currentPage === 'carousel') return;
  if (!mondayReady) {
    mondayMessage('monday-refresh-status', 'O intervalo será aplicado quando a conexão com o Monday estiver configurada.');
    return;
  }
  if (!mondayRefreshMinutes) {
    mondayMessage('monday-refresh-status', 'Atualização automática desligada. Use “Atualizar dados agora” para consultar o Monday.');
    return;
  }
  const delay = mondayRefreshMinutes * 60 * 1000;
  mondayRefreshTimer = setTimeout(() => syncMonday(false), delay);
  const next = new Date(Date.now() + delay).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  mondayMessage('monday-refresh-status', `Atualização a cada ${mondayRefreshMinutes} min enquanto o dashboard estiver aberto. Próxima consulta por volta das ${next}.`, 'success');
}

async function saveMondayRefresh() {
  const button = document.getElementById('monday-save-refresh');
  button.disabled = true;
  try {
    const refreshMinutes = Number(document.getElementById('monday-refresh').value);
    const result = await mondayRequest('/api/refresh', { refreshMinutes });
    mondayRefreshMinutes = result.refreshMinutes;
    scheduleMondayRefresh();
  } catch (error) {
    mondayMessage('monday-refresh-status', error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

function useMondayRows(rows) {
  const valid = rows.filter(row => Number.isInteger(row.Sprint) && row.Sprint > 0 && typeof row.Nome === 'string');
  if (!valid.length) throw new Error('Nenhum item com Sprint reconhecida. Confira o mapeamento da coluna Sprint.');
  const liveSprints = new Set(valid.map(row => row.Sprint));
  DATA.splice(0, DATA.length, ...SNAPSHOT_DATA.filter(row => !liveSprints.has(row.Sprint)), ...valid);
  SPRINTS.splice(0, SPRINTS.length, ...[...new Set(DATA.map(row => row.Sprint).filter(Number.isInteger))].sort((a, b) => a - b));
  EPICOS_LIST.splice(0, EPICOS_LIST.length, ...[...new Set(DATA.map(row => row.Epico).filter(Boolean))].sort((a, b) => a.localeCompare(b)));
  DEVS.splice(0, DEVS.length, ...[...new Set(DATA.flatMap(row => devPeople(row)).filter(name => !SprintRules.isExcludedPerson(name)))].sort((a, b) => a.localeCompare(b)));
  initSprintNav();
  const devSelect = document.getElementById('timeDevSel');
  const sprintSelect = document.getElementById('timeSpSel');
  devSelect.length = 1;
  sprintSelect.length = 1;
  timeInited = false;
  // Telas montadas com estes dados são redesenhadas onde estão; as demais buscam os próprios dados.
  if (['dashboard', 'sprint', 'epicos', 'time', 'raw'].includes(currentPage)) navigate(currentPage, currentSprint, { history: 'replace' });
  else document.getElementById('tb-right').textContent = sprintRangeLabel();
}

async function syncMonday(force = true) {
  if (mondaySyncInFlight) return;
  mondaySyncInFlight = true;
  let followUp = false;
  window.Capacity?.onLoading();
  window.Consumption?.onLoading();
  window.Forecast?.onLoading();
  const button = document.getElementById('monday-sync');
  button.disabled = true;
  mondayMessage('monday-sync-status', 'Buscando tarefas no Monday...');
  try {
    const result = await mondayRequest('/api/sync', { force });
    // Ordem e cores dos status do quadro: colunas do Kanban da tela Tarefas.
    if (result.statusOptions?.length) window.MONDAY_STATUS_OPTIONS = result.statusOptions;
    // Rótulos da Prioridade: opções da alteração em massa da tela Tarefas.
    if (result.priorityOptions?.length) window.MONDAY_PRIORITY_OPTIONS = result.priorityOptions;
    // Status de hoje de cada card (as sprints encerradas chegam congeladas no último dia): usado pela Visão 360°.
    window.MONDAY_LIVE_STATUS = result.liveStatus || {};
    if (result.rows.length || !['consumption','release'].includes(currentPage)) useMondayRows(result.rows);
    window.Capacity?.onDataUpdated(result);
    window.HoursStrip?.onDataUpdated();
    // As datas das sprints chegam com o Capacity; com elas a tela Tarefas descobre a sprint ativa.
    if (currentPage === 'raw') renderRaw();
    // As abas Roadmap, Backlog e Visão 360° acompanham o mesmo ciclo de atualização (o servidor decide se busca de novo no Monday).
    if (currentPage === 'raw') (isOverviewTab() ? BOARD_TABS : isBoardTab() ? [rawTab] : []).forEach(tab => loadBoardTab(tab, false));
    window.Forecast?.onDataUpdated(result);
    window.Consumption?.onDataUpdated(result);
    window.Daily?.onDataUpdated(result);
    window.Retro?.onDataUpdated(result);
    window.Review?.onDataUpdated(result);
    window.SprintRelease?.onDataUpdated(result);
    if (canAccessScreen('control-tower')) window.ControlTower?.refreshAlerts();
    window.Burndown?.refresh();
    const time = new Date(result.syncedAt).toLocaleString('pt-BR');
    mondayMessage('monday-sync-status', `${result.rows.length} registros carregados de ${result.fetched} tarefas · ${result.skipped} sem Sprint reconhecida · ${time}${result.refreshing ? ' · atualizando com o Monday em segundo plano' : ''}.`, 'success');
    // A tela abriu com o último snapshot salvo; o servidor está buscando o novo e ele chega na próxima consulta.
    followUp = Boolean(result.refreshing) && mondayFollowUps < MONDAY_MAX_FOLLOW_UPS;
    mondayFollowUps = followUp ? mondayFollowUps + 1 : 0;
  } catch (error) {
    mondayMessage('monday-sync-status', error.message, 'error');
    window.Capacity?.onError(error.message);
    window.Forecast?.onError(error.message);
    window.Consumption?.onError(error.message);
  } finally {
    button.disabled = false;
    mondaySyncInFlight = false;
    window.Forecast?.onSyncComplete();
    window.Consumption?.onSyncComplete();
    scheduleMondayRefresh();
    // Os painéis do Bombeiro e da Sprint consultam sozinhos a cada minuto; nas demais telas, a consulta seguinte vem logo.
    if (followUp && !['bombeiro', 'sprint-panel', 'carousel'].includes(currentPage)) {
      clearTimeout(mondayRefreshTimer);
      mondayRefreshTimer = setTimeout(() => syncMonday(false), MONDAY_FOLLOW_UP_MS);
    }
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  if (location.protocol === 'file:') {
    mondayMessage('monday-connection', 'Abra o dashboard pelo servidor Laravel para consultar o Monday.', 'error');
    window.Capacity?.onError('Abra pelo servidor Laravel para consultar o Monday');
    return;
  }
  try {
    const config = await mondayRequest('/api/config');
    applyMetricSettings({ ...config.metrics, capacityOverrides: config.capacityOverrides });
    document.getElementById('monday-board').value = config.boardId || '';
    document.getElementById('roadmap-board').value = config.roadmapBoardId || '';
    document.getElementById('backlog-board').value = config.backlogBoardId || '';
    mondayRefreshMinutes = config.refreshMinutes ?? 5;
    document.getElementById('monday-refresh').value = String(mondayRefreshMinutes);
    mondaySavedMap = config.map || {};
    mondayReady = Boolean(config.boardId && config.hasToken);
    if (mondayReady) {
      mondayMessage('monday-connection', 'Credencial salva. Carregando colunas e dados do quadro...');
      if (currentPage === 'settings') enterMondaySettings();
      await syncMonday(false);
    } else {
      mondayMessage('monday-connection', 'Informe o quadro e o token; depois clique em Buscar colunas.');
      window.Capacity?.onError('Integração com Monday não configurada');
      scheduleMondayRefresh();
    }
  } catch (error) {
    mondayMessage('monday-connection', `Servidor indisponível: ${error.message}`, 'error');
    window.Capacity?.onError(error.message);
  }
});
