<div class="page" id="page-consumption">
  <header class="cap-hero">
    <div class="cap-heading"><img src="yuv-logo.png" alt="YUV"><span class="cap-divider"></span><div><h1>Consumo de horas | Sprint e Suporte</h1><p>Onde o esforço do time está sendo consumido</p></div></div>
    <div class="cap-filters"><label>Sprint <select id="cons-sprint" aria-label="Selecionar sprint do consumo"></select></label><span id="cons-dates"></span></div>
  </header>
  <div class="cons-toolbar cap-info"><span class="cap-info-icon" aria-hidden="true">i</span><strong>Horas executadas · time completo</strong><button type="button" id="cons-refresh">Atualizar dados</button><span id="cons-updated" role="status"></span></div>
  <p id="cons-error" class="cap-message cap-error" role="status" hidden></p>
  <p id="cons-empty" class="cap-message" hidden></p>
  <div id="cons-content" hidden>
    <div class="cons-macro">
      <article class="cons-card"><h2>Total executado</h2><strong id="cons-total"></strong><p>Sprint + Suporte</p></article>
      <article class="cons-card cons-sprint"><h2>Sprint</h2><strong id="cons-sprint-hours"></strong><p>Tipo diferente de Suporte</p></article>
      <article class="cons-card cons-support"><h2>Suporte</h2><strong id="cons-support-hours"></strong><p>Tipo = Suporte</p></article>
    </div>
    <section class="cons-card cons-composition"><h2>Composição do tempo executado (100%)</h2><div id="cons-composition"></div><div id="cons-legend" class="cons-legend"></div></section>
    <section id="cons-reserve" class="cons-card cons-reserve">
      <div><h2>Reserva de suporte do time</h2><strong id="cons-reserve-hours"></strong><p>Reserva coletiva por sprint · time completo</p></div>
      <div><p id="cons-reserve-percent" class="cons-reserve-percent"></p><div id="cons-reserve-bar"></div><p id="cons-reserve-balance"></p></div>
      <details class="cons-reserve-settings cons-popover"><summary>Ajustar reserva</summary>
      <form id="cons-reserve-form" class="cons-reserve-form cons-popover-content" data-url="{{ route('support-reserves.store') }}">
        <label>Reserva desta sprint (horas decimais) <input id="cons-reserve-input" type="number" min="0" max="10000" step="any" required aria-describedby="cons-reserve-help"></label>
        <button id="cons-reserve-save" type="submit">Salvar reserva</button><span id="cons-reserve-help">1,5h = 1h30. Padrão: 40h para todo o time.</span><span id="cons-save-status" role="status"></span>
      </form>
      </details>
    </section>
    <section class="cons-individual"><div class="cons-individual-heading"><h2>Consumo por desenvolvedor</h2><label>Desenvolvedor <select id="cons-developer" aria-label="Filtrar apenas a tabela individual"></select></label></div><p>O filtro afeta apenas a tabela individual. Indicadores macro e reserva consideram sempre o time completo.</p>
      <div class="cap-table-scroll cons-developer-scroll"><table class="cap-table cons-table"><thead><tr><th>Desenvolvedor</th><th>Total executado</th><th>Sprint · horas e %</th><th>Suporte · horas e %</th><th>Distribuição · Sprint / Suporte</th></tr></thead><tbody id="cons-body"></tbody><tfoot id="cons-foot"></tfoot></table></div>
    </section>
    <div class="cons-audit">
    <details class="cons-unassigned cons-popover"><summary><strong>Horas sem atribuição individual</strong><span id="cons-unassigned-summary"></span></summary><div class="cons-popover-content"><p>Cards com vários responsáveis e Tempo Real compartilhado, ou sem responsável, permanecem no macro uma única vez.</p><div id="cons-unassigned"></div></div></details>
    <details class="cons-quality cons-popover"><summary id="cons-quality-summary">Qualidade dos dados · Tempo Real acumulado por card</summary><div class="cons-popover-content"><p id="cons-quality"></p><p id="cons-reconciliation" class="cons-rule"></p><p>Tempo Real é acumulado por card. A integração atual não fornece histórico de apontamentos por sprint ou por pessoa; horas de outras sprints podem estar incluídas. Todos os status entram no consumo, inclusive concluídos.</p></div></details>
    </div>
  </div>
  <footer class="cons-rule">Divisão pela coluna Tipo: Suporte → Suporte • Qualquer outro tipo → Sprint</footer>
</div>
