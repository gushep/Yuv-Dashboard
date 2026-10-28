/* Integração Monday via backend Laravel; credenciais nunca ficam no navegador. */
const SNAPSHOT_DATA = DATA.slice();
let mondayColumns = [];
let mondaySavedMap = {};
let mondayReady = false;
let mondayRefreshMinutes = 5;
let mondayRefreshTimer = null;
let mondaySyncInFlight = false;
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

function applyMetricSettings(metrics = {}) {
  SprintRules.applySettings(metrics);
  document.getElementById('metric-capacity').value = String(SprintRules.settings.capacityHours);
  document.getElementById('metric-excluded').value = SprintRules.settings.excludedPeople.join('\n');
}

async function saveMetricSettings() {
  const button = document.getElementById('metric-save');
  button.disabled = true;
  try {
    const capacityHours = Number(String(document.getElementById('metric-capacity').value).replace(',', '.'));
    const excludedPeople = document.getElementById('metric-excluded').value.split('\n').map(name => name.trim()).filter(Boolean);
    const result = await mondayRequest('/api/metric-settings', { capacityHours, excludedPeople });
    applyMetricSettings(result.metrics);
    if (DATA.length) useMondayRows(DATA.slice());
    mondayMessage('metric-status', 'Regras salvas. Os indicadores já usam os novos valores.', 'success');
  } catch (error) {
    mondayMessage('metric-status', error.message, 'error');
  } finally {
    button.disabled = false;
  }
}

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
    if (result.rows.length || !['consumption','release'].includes(currentPage)) useMondayRows(result.rows);
    window.Capacity?.onDataUpdated(result);
    // As datas das sprints chegam com o Capacity; com elas a tela Tarefas descobre a sprint ativa.
    if (currentPage === 'raw') renderRaw();
    // As abas Roadmap e Backlog acompanham o mesmo ciclo de atualização (o servidor decide se busca de novo no Monday).
    if (currentPage === 'raw' && isBoardTab()) loadBoardTab(rawTab, false);
    window.Forecast?.onDataUpdated(result);
    window.Consumption?.onDataUpdated(result);
    window.SprintRelease?.onDataUpdated(result);
    if (canAccessScreen('control-tower')) window.ControlTower?.refreshAlerts();
    window.Burndown?.refresh();
    const time = new Date(result.syncedAt).toLocaleString('pt-BR');
    mondayMessage('monday-sync-status', `${result.rows.length} registros carregados de ${result.fetched} tarefas · ${result.skipped} sem Sprint reconhecida · ${time}.`, 'success');
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
    applyMetricSettings(config.metrics);
    document.getElementById('monday-board').value = config.boardId || '';
    document.getElementById('roadmap-board').value = config.roadmapBoardId || '';
    document.getElementById('backlog-board').value = config.backlogBoardId || '';
    mondayRefreshMinutes = config.refreshMinutes ?? 5;
    document.getElementById('monday-refresh').value = String(mondayRefreshMinutes);
    mondaySavedMap = config.map || {};
    mondayReady = Boolean(config.boardId && config.hasToken);
    if (mondayReady) {
      mondayMessage('monday-connection', 'Credencial salva. Carregando colunas e dados do quadro...');
      if (canAccessScreen('settings')) await inspectMonday();
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
