/* Tabela dos modais de indicadores: busca, filtro por coluna e ordenação pelo cabeçalho, usada em todas as telas. */
window.KpiTable = (() => {
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const normalize = value => window.SprintRules.normalize(value);
  const EMPTY = '__empty__';
  const PRIORITY_RANK = {critica:0, alta:1, media:2, baixa:3};

  /** Prioridade da mais crítica para a mais baixa; sem prioridade fica por último. */
  const priorityRank = value => PRIORITY_RANK[normalize(value)] ?? 4;

  /** Valores de filtro do card na coluna; "Sem valor" quando vazio. */
  const filterValues = (column, row) => {
    const values = [column.filter === true ? column.value(row) : column.filter(row)].flat().map(value => String(value ?? '').trim()).filter(Boolean);
    return values.length ? values : [EMPTY];
  };
  /** Compara na direção pedida; células vazias ficam sempre no fim. */
  const compare = (a, b, dir) => {
    const emptyA = a === null || a === undefined || a === '', emptyB = b === null || b === undefined || b === '';
    if (emptyA || emptyB) return emptyA - emptyB;
    return (typeof a === 'number' && typeof b === 'number' ? a - b : String(a).localeCompare(String(b), 'pt-BR', {numeric:true})) * dir;
  };

  /**
   * Monta a tabela no container. Cada coluna: label, html(row) para a célula, value(row) para busca e ordenação,
   * sort(row) opcional quando a ordem difere do texto, filter (true ou função com os valores) para ter filtro,
   * className opcional para a célula e primary na coluna que vira o título do cartão no celular.
   * @param {HTMLElement} container
   * @param {{columns: Array<{label: string, html: Function, value: Function, sort?: Function, filter?: boolean|Function, empty?: string, className?: string, primary?: boolean}>,rows: Array<object>, empty?: string, tableClass?: string}} options
   */
  function render(container, {columns, rows, empty = 'Nenhuma task neste indicador.', tableClass = ''}) {
    const state = {q:'', filters:columns.map(() => ''), sort:{index:null, dir:1}};
    const filterable = columns.map((column, index) => ({column, index})).filter(({column}) => column.filter);
    const select = ({column, index}) => {
      const values = [...new Set(rows.flatMap(row => filterValues(column, row)))].filter(value => value !== EMPTY).sort((a, b) => a.localeCompare(b, 'pt-BR'));
      const hasEmpty = rows.some(row => filterValues(column, row).includes(EMPTY));
      return `<label>${escape(column.label)}<select data-filter="${index}"><option value="">Todos</option>${values.map(value => `<option value="${escape(value)}">${escape(value)}</option>`).join('')}${hasEmpty ? `<option value="${EMPTY}">${escape(column.empty || 'Sem valor')}</option>` : ''}</select></label>`;
    };
    container.innerHTML = `<div class="kpi-table-tools">
        <label class="kpi-table-search">Buscar<input type="search" placeholder="Nome, responsável, status…" data-search></label>
        ${filterable.map(select).join('')}
        <button type="button" class="kpi-table-clear" data-clear>Limpar</button>
        <span class="kpi-table-count" aria-live="polite"></span>
      </div>
      <div class="kpi-table-scroll"><table class="kpi-table stack-mobile ${tableClass}"><thead><tr>${columns.map((column, index) => `<th aria-sort="none"${column.primary ? ' data-primary' : ''}><button type="button" data-sort="${index}">${escape(column.label)}</button></th>`).join('')}</tr></thead><tbody></tbody></table></div>`;

    const body = container.querySelector('tbody');
    const update = () => {
      const terms = normalize(state.q).split(' ').filter(Boolean);
      const shown = rows.filter(row => {
        const text = normalize(columns.map(column => column.value(row)).join(' '));
        return terms.every(term => text.includes(term))
          && state.filters.every((value, index) => !value || filterValues(columns[index], row).includes(value));
      });
      const {index, dir} = state.sort;
      if (index !== null) {
        const key = columns[index].sort || columns[index].value;
        shown.sort((a, b) => compare(key(a), key(b), dir));
      }
      body.innerHTML = shown.map(row => `<tr>${columns.map(column => `<td${column.className ? ` class="${column.className}"` : ''}>${column.html(row)}</td>`).join('')}</tr>`).join('')
        || `<tr><td colspan="${columns.length}" class="kpi-table-empty">${rows.length ? 'Nenhuma task com os filtros selecionados.' : escape(empty)}</td></tr>`;
      container.querySelector('.kpi-table-count').textContent = `${shown.length} de ${rows.length} task(s)`;
      container.querySelectorAll('th').forEach((th, position) => th.setAttribute('aria-sort', position === index ? (dir === 1 ? 'ascending' : 'descending') : 'none'));
    };

    container.querySelector('[data-search]').oninput = event => { state.q = event.target.value; update(); };
    container.querySelectorAll('[data-filter]').forEach(element => {
      element.onchange = () => { state.filters[Number(element.dataset.filter)] = element.value; update(); };
    });
    /** Clique alterna crescente e decrescente; o terceiro clique volta à ordem original do indicador. */
    container.querySelectorAll('[data-sort]').forEach(button => {
      button.onclick = () => {
        const index = Number(button.dataset.sort), {sort} = state;
        state.sort = sort.index !== index ? {index, dir:1} : sort.dir === 1 ? {index, dir:-1} : {index:null, dir:1};
        update();
      };
    });
    container.querySelector('[data-clear]').onclick = () => {
      state.q = ''; state.filters = columns.map(() => ''); state.sort = {index:null, dir:1};
      container.querySelectorAll('[data-search], [data-filter]').forEach(element => { element.value = ''; });
      update();
    };
    update();
  }

  return {render, priorityRank};
})();
