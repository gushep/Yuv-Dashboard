{{-- Sprint Review: tela inicial (resultado das reviews e últimas sprints), sala ao vivo em seis etapas e modo leitura
     (olhinho). Regras no servidor (SprintReviewService); a tela fica em public/review.js e reaproveita o visual da retro. --}}
<div class="page" id="page-sprint-review" data-url="{{ route('review.show') }}" data-room-url="{{ route('review.room') }}">
  <header class="cap-hero">
    <div class="cap-heading"><img src="{{ asset('yuv-logo.png') }}" alt="YUV"><span class="cap-divider"></span><div><h1>Sprint Review</h1><p id="rv-subtitle">O que o time entregou, o que acharam e o que vem a seguir</p></div></div>
    <div class="pp-hero-actions" id="rv-hero-actions"></div>
  </header>
  <p id="review-error" class="cap-message cap-error" role="alert" hidden></p>

  {{-- Tela inicial --}}
  <div id="rv-lobby">
    <div id="rv-open-banner" class="pp-open-banner rt-live" hidden></div>
    <div class="sec"><span class="sec-label">resultado das reviews</span><div class="sec-line"></div></div>
    <div id="rv-kpis" class="rt-kpis"></div>
    <div class="sec"><span class="sec-label">últimas sprints</span><div class="sec-line"></div></div>
    <div id="rv-history" class="rt-history"><p class="pp-muted">Carregando...</p></div>
  </div>

  {{-- Sala e modo leitura --}}
  <div id="rv-room" hidden>
    <div id="rv-room-top"></div>
    <ol id="rv-stepper" class="rt-stepper rv-stepper" aria-label="Etapas da review"></ol>
    <section id="rv-step" class="rt-panel rt-step" aria-live="polite"></section>
  </div>
</div>
