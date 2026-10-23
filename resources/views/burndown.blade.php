<div class="page" id="page-burndown" data-url="{{ route('burndown.show') }}">
  <header class="cap-hero">
    <div class="cap-heading"><img src="yuv-logo.png" alt="YUV"><span class="cap-divider"></span><div><h1>Burndown</h1><p>Quanto falta implementar na sprint, dia a dia, contra a linha ideal</p></div></div>
    <div class="cap-filters"><label>Sprint <select id="burndown-sprint" aria-label="Selecionar sprint do burndown"></select></label><span id="burndown-dates"></span></div>
  </header>
  <div class="burndown-toolbar">
    <div class="burndown-units" role="group" aria-label="Unidade dos gráficos">
      <button type="button" data-unit="cards" aria-pressed="true">Cards</button>
      <button type="button" data-unit="hours" aria-pressed="false">Horas estimadas</button>
    </div>
    <button type="button" id="burndown-refresh" class="burndown-refresh">Atualizar dados</button>
    <span id="burndown-updated" role="status"></span>
  </div>
  <p id="burndown-error" class="cap-message cap-error" role="alert" hidden></p>
  <p id="burndown-empty" class="cap-message" hidden></p>
  <div id="burndown-content" hidden>
    <div class="burndown-panels" id="burndown-panels"></div>
    <section class="burndown-card burndown-sla">
      <h2>SLA do suporte</h2>
      <p class="burndown-note">Tempo de atendimento dos cards de Tipo Suporte desta sprint: da abertura do card no Monday até o status Implementado, em tempo corrido (inclui noites e fins de semana).</p>
      <div class="burndown-facts" id="burndown-sla-facts"></div>
      <div id="burndown-sla-table"></div>
    </section>
    <section class="burndown-card burndown-method">
      <h2>Como os gráficos são calculados</h2>
      <dl>
        <div><dt><span class="burndown-key burndown-key-remaining"></span>Restante</dt><dd>Cards do painel que ainda não estão com status <b>Implementado</b> no fim de cada dia útil. Aguardando Deploy e QA - Aprovado ainda contam como restantes. Em horas, soma o Tempo estimado desses cards.</dd></div>
        <div><dt><span class="burndown-key burndown-key-done"></span>Concluído</dt><dd>Cards com status Implementado no fim do dia, acumulados desde o início da sprint.</dd></div>
        <div><dt><span class="burndown-key burndown-key-scope"></span>Escopo</dt><dd>Total de cards do painel no dia (Restante + Concluído). Quando entra card na sprint, o escopo e o restante sobem juntos; o tooltip mostra quantos entraram ou saíram no dia.</dd></div>
        <div><dt><span class="burndown-key burndown-key-ideal"></span>Ideal</dt><dd>Linha reta que parte do escopo do primeiro dia útil e chega a zero no último dia útil da sprint. Restante acima do ideal indica atraso; abaixo, folga.</dd></div>
      </dl>
      <ul>
        <li><b>Geral:</b> todos os cards da sprint. <b>Suporte:</b> cards com Tipo Suporte, independente do campo Planejada. <b>Planejado e não planejado:</b> todos os demais. Suporte + Planejado e não planejado = Geral.</li>
        <li>Só dias úteis (segunda a sexta), sem feriados configurados. Cada ponto é o estado no fim do dia, reconstituído pelo log de atividades do Monday.</li>
        <li>Cancelado, Descontinuado e Despriorizado ficam fora de todas as linhas, inclusive do escopo.</li>
        <li>O dia de hoje é parcial: vale o estado da última sincronização com o Monday.</li>
        <li>Ritmo médio = concluídos ÷ dias úteis decorridos. Ritmo necessário = restante ÷ dias úteis que faltam, contando hoje.</li>
        <li>SLA: a abertura é a criação do card no log de atividades copiado; cards criados antes do início do log ficam com tempo desconhecido e fora da média. Cards em aberto mostram há quanto tempo estão abertos e também ficam fora da média.</li>
      </ul>
      <p class="burndown-note" id="burndown-history"></p>
    </section>
  </div>
</div>
