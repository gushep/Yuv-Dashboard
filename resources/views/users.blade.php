<div class="page" id="page-users" data-url="{{ route('users.index') }}" data-current-user="{{ auth()->id() }}">
  <div class="sec"><span class="sec-label">cadastro</span><div class="sec-line"></div></div>
  <form class="settings-card" id="users-form" novalidate>
    <h2 id="users-form-title">Novo usuário</h2>
    <p>A pessoa só verá no menu e só conseguirá abrir as telas selecionadas. A tela Usuários dá acesso a este cadastro.</p>
    <div class="settings-grid">
      <label class="settings-field">Nome<input id="users-name" name="name" autocomplete="off" maxlength="255" required></label>
      <label class="settings-field">E-mail<input id="users-email" name="email" type="email" autocomplete="off" maxlength="255" required></label>
      <label class="settings-field"><span id="users-password-label">Senha (mínimo 8 caracteres)</span><input id="users-password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="255"></label>
    </div>
    <label class="settings-field users-screens-field">Telas com acesso
      <select id="users-screens" name="screens" multiple size="8" aria-describedby="users-screens-hint"></select>
    </label>
    <div class="users-screens-tools">
      <span id="users-screens-hint">Segure Ctrl (ou ⌘ no Mac) para marcar mais de uma tela · <strong id="users-screens-count">0 selecionadas</strong></span>
      <button type="button" id="users-screens-all">Selecionar todas</button>
      <button type="button" id="users-screens-none">Limpar</button>
    </div>
    <fieldset class="users-permissions">
      <legend>Alterações na Monday</legend>
      <label class="users-switch">
        <input type="checkbox" role="switch" id="users-update-items">
        <span class="users-switch-track" aria-hidden="true"></span>
        <span class="users-switch-text"><strong>Atualizar cards</strong><small>Na tela Tarefas: alterar status, comentar e anexar evidências.</small></span>
      </label>
      <label class="users-switch">
        <input type="checkbox" role="switch" id="users-create-tickets">
        <span class="users-switch-track" aria-hidden="true"></span>
        <span class="users-switch-text"><strong>Abrir tickets</strong><small>Botão Novo Ticket nas telas Tarefas e Painel do Bombeiro.</small></span>
      </label>
    </fieldset>
    <div class="settings-actions">
      <button type="submit" id="users-save">Cadastrar usuário</button>
      <button type="button" class="secondary" id="users-cancel" hidden>Cancelar edição</button>
    </div>
    <div class="settings-message" id="users-status" aria-live="polite"></div>
  </form>
  <div class="sec"><span class="sec-label">usuários cadastrados</span><div class="sec-line"></div></div>
  <div class="tcard">
    <div class="tscroll"><table class="users-table"><thead><tr>
      <th>Nome</th><th>E-mail</th><th>Telas com acesso</th><th><span class="sr-only">Ações</span></th>
    </tr></thead><tbody id="users-body"></tbody></table></div>
  </div>
</div>
