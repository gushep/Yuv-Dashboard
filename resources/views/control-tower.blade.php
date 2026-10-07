<div class="page" id="page-control-tower" data-url="{{ route('control-tower.show') }}" data-commit-url="{{ route('commitments.store') }}">
  <header class="cap-hero">
    <div class="cap-heading"><img src="yuv-logo.png" alt="YUV"><span class="cap-divider"></span><div><h1>Torre de controle</h1><p>Para onde a sprint está indo e quanto tempo resta para manobrar</p></div></div>
    <div class="cap-filters"><label>Sprint <select id="tower-sprint" aria-label="Selecionar sprint da torre de controle"></select></label><span id="tower-dates"></span></div>
  </header>
  <div class="tower-toolbar"><button type="button" id="tower-refresh">Atualizar dados</button><span id="tower-updated" role="status"></span></div>
  <p id="tower-error" class="cap-message cap-error" role="alert" hidden></p>
  <p id="tower-empty" class="cap-message" hidden></p>
  <div id="tower-content" hidden>
    <section class="tower-status" id="tower-status" aria-live="polite">
      <div class="tower-light"><span class="tower-light-dot" aria-hidden="true"></span><div><strong id="tower-status-label"></strong><p id="tower-status-detail"></p></div></div>
      <div class="tower-facts">
        <div><span>Dias úteis restantes</span><strong id="tower-days"></strong></div>
        <div><span>Compromisso concluído</span><strong id="tower-commitment"></strong></div>
        <div><span>Escopo concluído</span><strong id="tower-scope"></strong></div>
      </div>
      <p class="tower-decision" id="tower-decision"></p>
    </section>
    <section class="tower-signals" id="tower-signals" aria-label="Sinais da sprint"></section>
    <div class="tower-grid">
      <section class="tower-card tower-burnup">
        <h2>Trajetória da sprint</h2>
        <p class="tower-note">Escopo e cards concluídos no fim de cada dia útil, reconstituídos pelo histórico do Monday. As linhas tracejadas mostram a projeção até o fim da sprint.</p>
        <div class="tower-chart"><canvas id="tower-burnup-chart" aria-label="Burnup da sprint"></canvas></div>
        <p class="tower-note" id="tower-forecast"></p>
      </section>
      <section class="tower-card">
        <h2>Manobras sugeridas</h2>
        <p id="tower-maneuvers-text"></p>
        <ul class="tower-list" id="tower-maneuvers"></ul>
      </section>
      <section class="tower-card">
        <h2>Alertas</h2>
        <ul class="tower-list" id="tower-alerts"></ul>
      </section>
      <section class="tower-card">
        <h2>Mudanças de escopo</h2>
        <p id="tower-scope-text"></p>
        <details><summary id="tower-added-summary"></summary><ul class="tower-list" id="tower-added"></ul></details>
        <details><summary id="tower-removed-summary"></summary><ul class="tower-list" id="tower-removed"></ul></details>
      </section>
    </div>
    <section class="tower-card tower-commitment">
      <h2>Compromisso da sprint</h2>
      <p class="tower-note">Marque o que é compromisso; o restante é desejável. Sem marcação, os cards planejados contam como compromisso. A marcação fica salva no dashboard e não altera o Monday.</p>
      <div class="tower-filters" role="search" aria-label="Filtrar cards da sprint">
        <label class="tower-search">Buscar<input type="search" id="tower-filter-q" placeholder="Nome do card"></label>
        <fieldset class="tower-situations"><legend>Situação <button type="button" id="tower-filter-open" class="tower-link-button">Em aberto</button></legend>
          <button type="button" class="tower-chip" data-situation="notStarted" aria-pressed="false">Não iniciados</button>
          <button type="button" class="tower-chip" data-situation="progress" aria-pressed="false">Em andamento</button>
          <button type="button" class="tower-chip" data-situation="blocked" aria-pressed="false">Impedidos</button>
          <button type="button" class="tower-chip" data-situation="waiting" aria-pressed="false">Feitos, não entregues</button>
          <button type="button" class="tower-chip" data-situation="done" aria-pressed="false">Concluídos</button>
        </fieldset>
        <label>Responsável<select id="tower-filter-owner"></select></label>
        <label>Prioridade<select id="tower-filter-priority"></select></label>
        <label>Compromisso<select id="tower-filter-commitment"><option value="">Todos</option><option value="yes">Compromisso</option><option value="no">Desejável</option></select></label>
        <button type="button" id="tower-filter-clear" class="tower-link-button">Limpar filtros</button>
        <span id="tower-filter-count" role="status"></span>
      </div>
      <div class="cap-table-scroll"><table class="tower-table"><thead><tr>
        <th aria-sort="none"><button type="button" data-sort="committed">Compromisso</button></th>
        <th aria-sort="none"><button type="button" data-sort="name">Card</button></th>
        <th aria-sort="none"><button type="button" data-sort="status">Status</button></th>
        <th aria-sort="none"><button type="button" data-sort="owner">Responsável</button></th>
        <th aria-sort="none"><button type="button" data-sort="priority">Prioridade</button></th>
        <th aria-sort="none"><button type="button" data-sort="estimate">Estimativa</button></th>
      </tr></thead><tbody id="tower-items"></tbody></table></div>
      <span id="tower-commit-status" role="status"></span>
    </section>
    <footer class="tower-note" id="tower-footer"></footer>
  </div>
</div>
