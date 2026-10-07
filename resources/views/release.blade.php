<div class="page" id="page-release" data-url="{{ route('release.data') }}">
  <header class="release-header">
    <div class="cap-heading"><img src="yuv-logo.png" alt="YUV"><div><h1>Release da sprint</h1><p>Itens implementados, organizados automaticamente por Tipo</p></div></div>
    <div class="release-actions"><label><span class="release-sprint-label">Sprint</span><select id="release-sprint" aria-label="Selecionar sprint da release"></select></label><span id="release-dates"></span><button id="release-export" type="button" disabled title="Abrir impressão para salvar a release como PDF"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 16h6"/></svg>Exportar PDF</button></div>
  </header>
  <div class="release-info"><strong id="release-count">Aguardando dados</strong><span>Descrições extraídas dos comentários dos cards.</span></div>
  <div class="release-metadata"><span id="release-updated"></span></div>
  <p id="release-loading" class="release-message" role="status" hidden>Carregando itens implementados e comentários dos cards…</p>
  <p id="release-error" class="release-message release-error" role="alert" hidden></p>
  <p id="release-warning" class="release-message release-warning" role="status" hidden></p>
  <p id="release-empty" class="release-message" hidden></p>
  <div id="release-document" class="release-document" aria-label="Entregas implementadas da sprint"></div>
  <footer class="release-footer"><span>Agrupamento automático pela coluna Tipo · Status = Implementado.</span><span>Descrições extraídas dos comentários. Sem informação confiável, apenas o título.</span></footer>
</div>
