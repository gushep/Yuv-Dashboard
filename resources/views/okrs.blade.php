{{-- OKRs do time de desenvolvimento (public/okrs.js): se estamos atingindo os resultados-chave, na sprint, num intervalo e mês a mês. --}}
<div class="page" id="page-okrs">
  <header class="cap-hero">
    <div class="cap-heading"><img src="{{ asset('yuv-logo.png') }}" alt="YUV"><span class="cap-divider"></span><div><h1>OKRs do time</h1><p>Desenvolvimento · estamos atingindo os resultados-chave?</p></div></div>
    <div class="cap-filters okr-filters">
      <label>De <select id="okr-from" aria-label="Primeira sprint do período"></select></label>
      <label>até <select id="okr-to" aria-label="Última sprint do período"></select></label>
      <button type="button" id="okr-current" class="pp-ghost">Sprint atual</button>
    </div>
  </header>
  <p id="okr-period" class="okr-period" role="status"></p>
  {{-- Infográfico do período (V1): o resumo visual para apresentar aos gestores; imprime sozinho (Salvar como PDF) --}}
  <section class="okr-card okr-info" id="okr-infographic" aria-label="Infográfico do período">
    <div class="okr-card-head"><h2>Infográfico do período</h2><small>V1 · resumo visual para apresentar · muda com o período escolhido no topo</small>
      <button type="button" id="okr-info-print" class="pp-ghost okr-info-print">🖨️ Imprimir / PDF</button></div>
    <div id="okr-info"></div>
  </section>
  {{-- Objetivo e resultados-chave do período escolhido --}}
  <section class="okr-card" id="okr-objective" aria-label="Objetivo e resultados-chave"></section>
  {{-- Cada sprint do período, com os três resultados-chave --}}
  <section class="okr-card">
    <div class="okr-card-head"><h2>Sprints do período</h2><small>da mais recente para a mais antiga</small></div>
    <div id="okr-sprints"></div>
  </section>
  {{-- Comparativo com os meses anteriores --}}
  <section class="okr-card">
    <div class="okr-card-head"><h2>Histórico por mês</h2><small>cada sprint conta no mês em que começa · últimos 6 meses</small></div>
    <div id="okr-months"></div>
  </section>
  <section class="okr-card okr-method">
    <h2>Como cada resultado-chave é calculado</h2>
    <dl id="okr-method"></dl>
  </section>
  {{-- Crescimento: cards registrados por sprint (planejado, não planejado e suporte) e cards entregues --}}
  <section class="okr-card">
    <div class="okr-card-head"><h2>Crescimento das entregas</h2><small>cards registrados por sprint, por origem, e cards entregues · últimas 12 sprints</small></div>
    <p id="okr-growth-summary" class="okr-growth-summary"></p>
    <div class="okr-chart"><canvas id="okr-growth-chart" role="img" aria-label="Cards registrados por sprint (planejado, não planejado e suporte) e cards entregues; os números estão na tabela abaixo do gráfico"></canvas></div>
    <div id="okr-growth-table"></div>
  </section>
  {{-- Os mesmos resultados-chave por pessoa, uma de cada vez (como na Daily), no período escolhido acima --}}
  <section class="okr-card" id="okr-people">
    <div class="okr-card-head"><h2>OKRs por pessoa</h2><small>os mesmos resultados-chave, só com os cards e as horas de cada pessoa · período escolhido acima · clique no rosto ou use ← e →</small></div>
    <div id="okr-faces" class="daily-faces okr-faces"></div>
    <div id="okr-person"></div>
  </section>
</div>
