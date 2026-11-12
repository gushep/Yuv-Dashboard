<div class="page" id="page-planning" data-url="{{ route('planning.show') }}" data-history-url="{{ route('planning.history') }}">
  <header class="cap-hero">
    <div class="cap-heading"><img src="{{ asset('yuv-logo.png') }}" alt="YUV"><span class="cap-divider"></span><div><h1>Sprint Planning</h1><p id="pp-subtitle">Planejamento das sprints com o time na mesa</p></div></div>
    <div class="pp-hero-actions" id="pp-hero-actions"></div>
  </header>
  <p id="planning-error" class="cap-message cap-error" role="alert" hidden></p>

  {{-- Entrada: últimas sprints com as horas planejadas e executadas --}}
  <div id="pp-lobby">
    <div id="pp-open-banner" class="pp-open-banner" hidden></div>
    <div class="sec"><span class="sec-label">últimas sprints</span><div class="sec-line"></div></div>
    <div id="pp-history" class="pp-history"><p class="pp-muted">Carregando...</p></div>
  </div>

  {{-- Mesa da planning --}}
  <div id="pp-table-view" class="pp-game" hidden>
    {{-- Presentes na mesa e o tempo da planning (no modo leitura: quem esteve e quanto durou) --}}
    <div id="pp-room-bar" hidden></div>
    <div class="pp-arena">
      <div class="pp-felt">
        <div id="pp-seats"></div>
        <div id="pp-center" class="pp-center"></div>
      </div>
    </div>
    <div id="pp-controls" class="pp-controls"></div>
    <section id="pp-load" class="pp-load" hidden></section>
    <section id="pp-hand-section" class="pp-hand-section" hidden>
      <div class="pp-hand-head"><h2>Sua mão · Backlog do quadro Tarefas</h2><input type="search" id="pp-hand-search" placeholder="Buscar carta..." aria-label="Buscar carta da mão"></div>
      <div id="pp-hand" class="pp-hand"></div>
    </section>
    <section id="pp-estimated" class="pp-estimated" hidden></section>
  </div>

  <dialog id="pp-sprint-dialog" class="kpi-modal pp-dialog" aria-labelledby="pp-sprint-title">
    <form method="dialog" id="pp-sprint-form" novalidate>
      <div class="kpi-modal-head"><div><div class="kpi-modal-title" id="pp-sprint-title">Planejar nova sprint</div><div class="kpi-modal-sub">Escolha a sprint que vai ser planejada. O time entra na mesa pela tela Planning poker.</div></div>
        <button type="button" class="kpi-modal-close" data-close aria-label="Fechar">✕</button></div>
      <div class="pp-dialog-body">
        <label class="settings-field">Sprint <span class="nt-req">*</span><select id="pp-sprint-select" required></select></label>
        <p class="settings-message error" id="pp-sprint-message" aria-live="polite"></p>
        <div class="settings-actions"><button type="submit" id="pp-sprint-submit">Abrir a mesa</button><button type="button" class="secondary" data-close>Cancelar</button></div>
      </div>
    </form>
  </dialog>

  {{-- Encerrar a planning: o objetivo da sprint é obrigatório e fica registrado para o histórico e o Daily Hub. --}}
  <dialog id="pp-close-dialog" class="kpi-modal pp-dialog" aria-labelledby="pp-close-title">
    <form method="dialog" id="pp-close-form" novalidate>
      <div class="kpi-modal-head"><div><div class="kpi-modal-title" id="pp-close-title">Encerrar a planning</div><div class="kpi-modal-sub" id="pp-close-sub"></div></div>
        <button type="button" class="kpi-modal-close" data-close aria-label="Fechar">✕</button></div>
      <div class="pp-dialog-body">
        <label class="settings-field">Objetivo da Sprint <span class="nt-req">*</span>
          <textarea id="pp-goal" rows="4" maxlength="2000" required placeholder="Ex.: entregar o relatório de jornada para a Wisevia e estabilizar o login por FaceID."></textarea></label>
        <p class="pp-muted" id="pp-close-notes"></p>
        <p class="settings-message error" id="pp-close-message" aria-live="polite"></p>
        <div class="settings-actions"><button type="submit" id="pp-close-submit">Encerrar planning</button><button type="button" class="secondary" data-close>Cancelar</button></div>
      </div>
    </form>
  </dialog>

  <dialog id="pp-scope-dialog" class="kpi-modal pp-dialog pp-scope-dialog" aria-labelledby="pp-scope-name">
    <div class="kpi-modal-head"><div><div class="kpi-modal-title" id="pp-scope-name"></div><div class="kpi-modal-sub" id="pp-scope-title"></div></div>
      <button type="button" class="kpi-modal-close" data-close aria-label="Fechar">✕</button></div>
    <div class="kpi-modal-body"><div id="pp-scope-body" class="pp-scope-body"></div></div>
  </dialog>
</div>
