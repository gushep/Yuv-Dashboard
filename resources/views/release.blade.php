<div class="page" id="page-release" data-url="{{ route('release.data') }}">
  {{-- A moldura em tabela repete o respiro de topo e de rodapé em cada página do PDF; na tela ela se comporta como blocos comuns. --}}
  <table class="release-print-frame" role="presentation"><thead><tr><td class="release-print-space"></td></tr></thead><tbody><tr><td>
  <header class="release-header">
    <div class="cap-heading"><img src="{{ asset('yuv-logo.png') }}" alt="YUV"><div><h1>Release da sprint</h1><p><span class="release-screen-only">Itens implementados, organizados automaticamente por Tipo</span><span class="release-print-only">Entregas implementadas na sprint</span></p></div></div>
    <div class="release-actions"><label><span class="release-sprint-label">Sprint</span><select id="release-sprint" aria-label="Selecionar sprint da release"></select></label><span id="release-dates"></span><button id="release-export" type="button" disabled title="Abrir impressão para salvar a release como PDF"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 16h6"/></svg>Exportar PDF</button></div>
  </header>
  <div class="release-info"><strong id="release-count">Aguardando dados</strong><span>Descrições: objetivo da ficha do card ou resumo revisado do que foi implementado.</span></div>
  <div class="release-metadata"><span id="release-updated"></span><span id="release-summary-status" class="release-summary-status"></span></div>
  <div id="release-selection" class="release-selection" hidden><strong id="release-selection-count"></strong><span>Desmarque o que não deve ir para o PDF de CS e clientes; na tela todos os itens continuam visíveis.</span><button type="button" id="release-select-all">Marcar todos</button><button type="button" id="release-select-none">Desmarcar todos</button></div>
  <p id="release-loading" class="release-message" role="status" hidden>Carregando itens implementados e comentários dos cards…</p>
  <p id="release-error" class="release-message release-error" role="alert" hidden></p>
  <p id="release-warning" class="release-message release-warning" role="status" hidden></p>
  <p id="release-empty" class="release-message" hidden></p>
  <div id="release-document" class="release-document" aria-label="Entregas implementadas da sprint"></div>
  <footer class="release-footer"><span>Agrupamento automático pela coluna Tipo · Status = Implementado.</span><span>Descrição do objetivo da ficha ou de resumo revisado. Sem informação confiável, apenas o título.</span></footer>
  </td></tr></tbody><tfoot><tr><td class="release-print-space"></td></tr></tfoot></table>
</div>
