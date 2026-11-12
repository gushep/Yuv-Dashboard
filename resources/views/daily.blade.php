{{-- Daily Scrum (Daily Hub): a Daily pessoa por pessoa. Cards, status e horas vêm do Monday (base sincronizada);
     as responsabilidades que saem da Daily ficam no app (public/daily.js). --}}
<div class="page" id="page-daily-scrum" data-url="{{ route('daily.show') }}">
  <header class="cap-hero">
    <div class="cap-heading"><img src="{{ asset('yuv-logo.png') }}" alt="YUV"><span class="cap-divider"></span><div><h1>Daily Scrum</h1><p id="daily-subtitle">O alinhamento diário do time</p></div></div>
    <div class="cap-filters daily-filters">
      <label>Sprint <select id="daily-sprint" aria-label="Sprint da Daily"></select></label>
      <label>Daily <select id="daily-date" aria-label="Data da Daily"></select></label>
    </div>
  </header>
  <p id="daily-error" class="cap-message cap-error" role="alert" hidden></p>
  {{-- Quem está na Daily agora e o tempo (de hoje); em Dailies anteriores, quem esteve e quanto durou --}}
  <div id="daily-presence"></div>

  {{-- Indicador macro da sprint: objetivo, progresso, horas, tendência e XP do time --}}
  <section class="daily-macro" id="daily-macro" aria-label="Sprint em resumo"></section>

  {{-- Rostos do time: quem tem impedimento aparece marcado; ← e → passam de pessoa --}}
  <nav class="daily-faces" id="daily-faces" aria-label="Pessoas do time"></nav>

  {{-- A pessoa escolhida: horas, tendência e conquistas na largura toda; abaixo, o Kanban dela --}}
  <section class="daily-person" id="daily-person" aria-live="polite"></section>
  <section class="daily-board-card" id="daily-board-card">
    <p class="daily-note">Kanban só leitura: o status muda no detalhe do card ou no Monday. Concluir uma responsabilidade não conclui o card.</p>
    <div id="daily-board"></div>
  </section>

  {{-- Responsabilidades que saem da Daily (To-Do cumulativo) --}}
  <section class="daily-todo" id="daily-todo">
    <div class="daily-board-head">
      <h2>✅ Responsabilidades da Daily <small id="daily-todo-count"></small></h2>
      <div class="raw-views" id="daily-filter" role="group" aria-label="Filtrar responsabilidades">
        <button type="button" data-daily-filter="pending" aria-pressed="true">Pendentes</button>
        <button type="button" data-daily-filter="done" aria-pressed="false">Concluídas</button>
        <button type="button" data-daily-filter="all" aria-pressed="false">Todas</button>
      </div>
    </div>
    <form id="daily-new" class="daily-new" novalidate>
      <label class="daily-new-text">O que precisa ser feito para destravar <input type="text" id="daily-new-description" maxlength="2000" placeholder="Ex.: liberar acesso ao ambiente de QA" autocomplete="off"></label>
      <label>Responsável <select id="daily-new-responsible"></select></label>
      <label>Card (opcional) <select id="daily-new-item"></select></label>
      <button type="submit" id="daily-new-submit">Adicionar</button>
      <p class="daily-new-hint" id="daily-new-hint" aria-live="polite"></p>
    </form>
    <div class="daily-actions" id="daily-actions"></div>
  </section>

  {{-- Ações das retrospectivas ainda abertas, de qualquer sprint: lembradas em todas as Dailies até serem concluídas --}}
  <section class="daily-todo daily-retro" id="daily-retro">
    <div class="daily-board-head"><h2>🔁 Ações das retrospectivas <small id="daily-retro-count"></small></h2></div>
    <p class="daily-note">Lembradas todos os dias até serem concluídas. Só o responsável e o facilitador da retro concluem.</p>
    <div class="daily-actions" id="daily-retro-actions"></div>
  </section>

  {{-- Histórico das Dailies da sprint: presentes, duração e responsabilidades de cada dia --}}
  <section class="daily-todo daily-history" id="daily-history">
    <div class="daily-board-head"><h2>📅 Dailies da sprint <small id="daily-history-count"></small></h2></div>
    <div id="daily-history-list"></div>
  </section>

  {{-- Conclusão: resolução e, com card, publicação opcional no Monday --}}
  <dialog id="daily-done-dialog" class="kpi-modal pp-dialog daily-done-dialog" aria-labelledby="daily-done-title">
    <form method="dialog" id="daily-done-form" novalidate>
      <div class="kpi-modal-head"><div><div class="kpi-modal-title" id="daily-done-title">Concluir responsabilidade</div><div class="kpi-modal-sub" id="daily-done-sub"></div></div>
        <button type="button" class="kpi-modal-close" data-close aria-label="Fechar">✕</button></div>
      <div class="pp-dialog-body">
        <dl class="daily-done-facts" id="daily-done-facts"></dl>
        <label class="settings-field">Resolução <span class="nt-req">*</span>
          <textarea id="daily-resolution" rows="4" maxlength="4000" required placeholder="O que foi feito para resolver"></textarea></label>
        <div id="daily-publish-box" class="daily-publish-box" hidden>
          <label class="daily-publish-check"><input type="checkbox" id="daily-publish" checked> Publicar a resolução como comentário no card do Monday</label>
          <div class="daily-preview" id="daily-preview" aria-live="polite"></div>
        </div>
        <p class="settings-message error" id="daily-done-message" aria-live="polite"></p>
        <div class="settings-actions"><button type="submit" id="daily-done-submit">Concluir</button><button type="button" class="secondary" data-close>Cancelar</button></div>
      </div>
    </form>
  </dialog>
</div>
