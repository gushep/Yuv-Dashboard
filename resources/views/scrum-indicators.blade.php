{{-- Indicadores Scrum (public/scrum-indicators.js): infográfico de antes e depois das mudanças, nos períodos escolhidos. Só Maestro Scrum. --}}
<div class="page" id="page-scrum-indicators">
  <header class="cap-hero">
    <div class="cap-heading"><img src="{{ asset('yuv-logo.png') }}" alt="YUV"><span class="cap-divider"></span><div><h1>Indicadores Scrum</h1><p>Antes e depois das mudanças · para apresentar</p></div></div>
    <div class="cap-filters si-filters">
      <fieldset class="si-range"><legend>Antes das mudanças</legend>
        <label>De <select id="si-before-from" aria-label="Primeira sprint antes das mudanças"></select></label>
        <label>até <select id="si-before-to" aria-label="Última sprint antes das mudanças"></select></label>
      </fieldset>
      <fieldset class="si-range"><legend>Após as mudanças</legend>
        <label>De <select id="si-after-from" aria-label="Primeira sprint após as mudanças"></select></label>
        <label>até <select id="si-after-to" aria-label="Última sprint após as mudanças"></select></label>
      </fieldset>
      <div class="si-actions">
        <button type="button" id="si-default" class="pp-ghost">Padrão</button>
        <button type="button" id="si-fullscreen" class="pp-ghost">⛶ Tela cheia</button>
        <button type="button" id="si-print" class="pp-ghost">🖨️ Imprimir / PDF</button>
      </div>
    </div>
  </header>
  <p id="si-period" class="okr-period" role="status"></p>
  {{-- O infográfico é um slide 16:9 (1600 × 900) ajustado à largura da tela; em tela cheia, ao tamanho do monitor. --}}
  <div class="si-frame" id="si-frame"><div class="si-stage" id="si-stage"></div></div>
</div>
