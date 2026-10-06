<div class="page" id="page-forecast">
  <header class="cap-hero">
    <div class="cap-heading"><img src="yuv-logo.png" alt="YUV"><span class="cap-divider"></span><div><h1>Previsibilidade da sprint</h1><p>Demanda restante versus capacidade disponível</p></div></div>
    <div class="cap-filters"><label>Sprint <select id="forecast-sprint" aria-label="Selecionar sprint da previsão"></select></label><span id="forecast-dates"></span></div>
  </header>
  <div class="forecast-toolbar">
    <label>Desenvolvedor <select id="forecast-developer"></select></label>
    <label>Data de referência <input type="date" id="forecast-reference"></label>
    <button type="button" id="forecast-refresh">Atualizar dados</button>
    <span id="forecast-last-sync" role="status"></span>
  </div>
  <div class="forecast-cutoff"><strong id="forecast-cutoff"></strong></div>
  <p class="forecast-note">Somente visualização: corrija as estimativas e os responsáveis no Monday e depois atualize os dados.</p>
  <p class="forecast-note">Em andamento: estimativa − tempo real registrado. Exemplo: 10h estimadas e 4h apontadas = 6h para concluir. Esse cálculo é uma aproximação; tarefas não iniciadas usam a estimativa total.</p>
  <div id="forecast-error" class="cap-message cap-error" role="status" hidden></div>
  <div class="cap-table-scroll"><table class="forecast-table"><thead><tr>
    <th>Desenvolvedor</th><th>Situação</th><th>Tarefas abertas</th><th title="Esforço restante revisado no Monday; na ausência, estimativa total para tarefas não iniciadas ou estimativa menos tempo real em andamento">Horas para concluir</th><th title="Capacidade total ÷ dias úteis totais × dias úteis posteriores ao corte; mínimo zero">Capacidade restante</th><th title="Capacidade restante − horas para concluir. Com dados incompletos, somente o excesso conhecido é afirmado.">Folga / excesso</th>
  </tr></thead><tbody id="forecast-body"></tbody></table></div>
  <div class="forecast-legend" id="forecast-legend" aria-label="Legenda das situações"></div>
  <p class="forecast-note">Faixas indicam ocupação da capacidade futura, não produtividade, ociosidade ou probabilidade de entrega. </p>
  <details class="cap-quality"><summary>Fórmulas e qualidade dos dados</summary><p id="forecast-warning" class="cap-message cap-warning"></p><p class="forecast-note">Carga futura = horas para concluir ÷ capacidade restante. Folga / excesso = capacidade restante − horas para concluir. A soma não determina quais tarefas ficarão fora da sprint: isso depende de prioridade, sequência e dependências.</p></details>
  <section id="forecast-unassigned" class="cap-extra" hidden></section>
  <footer class="forecast-note">A previsão compara esforço restante e capacidade futura. Impedimentos, dependências e novas demandas também podem afetar a entrega.</footer>
</div>
