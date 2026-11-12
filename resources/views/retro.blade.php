{{-- Sprint Retrospective: tela inicial (ações das retros e últimas sprints), sala ao vivo em sete etapas e modo leitura
     (olhinho). Regras no servidor (RetrospectiveService); a tela fica em public/retro.js. --}}
<div class="page" id="page-sprint-retrospective" data-url="{{ route('retro.show') }}" data-room-url="{{ route('retro.room') }}">
  <header class="cap-hero">
    <div class="cap-heading"><img src="{{ asset('yuv-logo.png') }}" alt="YUV"><span class="cap-divider"></span><div><h1>Sprint Retrospective</h1><p id="rt-subtitle">Aprender com cada sprint e acompanhar as ações até o fim</p></div></div>
    <div class="pp-hero-actions" id="rt-hero-actions"></div>
  </header>
  <p id="retro-error" class="cap-message cap-error" role="alert" hidden></p>

  {{-- Tela inicial --}}
  <div id="rt-lobby">
    <div id="rt-open-banner" class="pp-open-banner rt-live" hidden></div>
    <div class="sec"><span class="sec-label">ações das retros</span><div class="sec-line"></div></div>
    <div id="rt-kpis" class="rt-kpis" role="group" aria-label="Filtrar ações das retros"></div>
    <section id="rt-actions" class="rt-panel" aria-live="polite"></section>
    <div class="sec"><span class="sec-label">últimas sprints</span><div class="sec-line"></div></div>
    <div id="rt-history" class="rt-history"><p class="pp-muted">Carregando...</p></div>
  </div>

  {{-- Sala da retro e modo leitura --}}
  <div id="rt-room" hidden>
    <div id="rt-room-top" class="rt-room-top"></div>
    <ol id="rt-stepper" class="rt-stepper" aria-label="Etapas da retro"></ol>
    <section id="rt-step" class="rt-panel rt-step" aria-live="polite"></section>
  </div>

  {{-- Concluir, descartar ou manter uma ação --}}
  <dialog id="rt-action-dialog" class="kpi-modal pp-dialog" aria-labelledby="rt-action-title">
    <form method="dialog" id="rt-action-form" novalidate>
      <div class="kpi-modal-head"><div><div class="kpi-modal-title" id="rt-action-title">Concluir ação</div><div class="kpi-modal-sub" id="rt-action-sub"></div></div>
        <button type="button" class="kpi-modal-close" data-close aria-label="Fechar">✕</button></div>
      <div class="pp-dialog-body">
        <p class="rt-dialog-signal" id="rt-action-signal"></p>
        <label class="settings-field" id="rt-action-text-label"><span id="rt-action-text-name">Resultado</span> <span class="nt-req">*</span>
          <textarea id="rt-action-text" rows="3" maxlength="4000"></textarea></label>
        <label class="rt-check" id="rt-action-signal-box"><input type="checkbox" id="rt-action-signal-reached"> O sinal de resultado foi atingido</label>
        <label class="settings-field" id="rt-action-due-box">Novo prazo (opcional) <input type="date" id="rt-action-due"></label>
        <p class="settings-message error" id="rt-action-message" aria-live="polite"></p>
        <div class="settings-actions"><button type="submit" id="rt-action-submit">Concluir</button><button type="button" class="secondary" data-close>Cancelar</button></div>
      </div>
    </form>
  </dialog>
</div>
