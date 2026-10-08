/* Cadastro de usuários: dados e telas liberadas para cada pessoa. */
window.Users = (() => {
  const state = {active:false, users:[], screens:[], editing:null, saving:false};
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const baseUrl = () => el('page-users').dataset.url;
  const currentUserId = () => Number(el('page-users').dataset.currentUser);

  async function request(url, method = 'GET', body) {
    const response = await fetch(url, {
      method,
      headers: {'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''},
      body: body === undefined ? undefined : JSON.stringify(body)
    });
    if (response.redirected && new URL(response.url).pathname === '/login') { location.assign('/login'); throw new Error('Sessão encerrada. Faça login novamente.'); }
    const result = await response.json().catch(() => ({}));
    if (response.status === 403) throw new Error('Você não tem permissão para gerenciar usuários.');
    if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Falha na comunicação com o servidor.');
    return result;
  }

  function message(text, kind = '') {
    el('users-status').textContent = text;
    el('users-status').className = `settings-message ${kind}`;
  }
  function screenLabel(value) { return state.screens.find(screen => screen.value === value)?.label || value; }
  function selectedScreens() { return [...el('users-screens').selectedOptions].map(option => option.value); }
  function updateCount() {
    const count = selectedScreens().length;
    el('users-screens-count').textContent = `${count} ${count === 1 ? 'selecionada' : 'selecionadas'}`;
  }
  function setSelected(values) {
    for (const option of el('users-screens').options) option.selected = values.includes(option.value);
    updateCount();
  }

  function renderScreenOptions() {
    const keep = selectedScreens();
    el('users-screens').innerHTML = state.screens.map(screen => `<option value="${escape(screen.value)}">${escape(screen.label)}</option>`).join('');
    setSelected(keep);
  }

  function renderTable() {
    if (!state.users.length) { el('users-body').innerHTML = '<tr><td colspan="4" class="users-none">Nenhum usuário cadastrado.</td></tr>'; return; }
    el('users-body').innerHTML = state.users.map(user => {
      const screens = user.screens.length
        ? `<div class="users-screen-list">${user.screens.map(value => `<span class="b">${escape(screenLabel(value))}</span>`).join('')}</div>`
        : '<span class="users-none">Sem acesso a nenhuma tela</span>';
      const isMe = user.id === currentUserId();
      return `<tr>
        <td>${escape(user.name)}${isMe ? ' <span class="users-none">(você)</span>' : ''}</td>
        <td class="cm">${escape(user.email)}</td>
        <td>${screens}</td>
        <td><div class="users-actions">
          <button type="button" data-edit="${user.id}">Editar</button>
          ${isMe ? '' : `<button type="button" class="danger" data-delete="${user.id}">Excluir</button>`}
        </div></td>
      </tr>`;
    }).join('');
  }

  function resetForm() {
    state.editing = null;
    el('users-form').reset();
    setSelected([]);
    el('users-form-title').textContent = 'Novo usuário';
    el('users-password-label').textContent = 'Senha (mínimo 8 caracteres)';
    el('users-password').placeholder = '';
    el('users-save').textContent = 'Cadastrar usuário';
    el('users-cancel').hidden = true;
  }

  function startEdit(id) {
    const user = state.users.find(item => item.id === id);
    if (!user) return;
    state.editing = user.id;
    el('users-name').value = user.name;
    el('users-email').value = user.email;
    el('users-password').value = '';
    el('users-password-label').textContent = 'Nova senha';
    el('users-password').placeholder = 'Deixe em branco para manter a atual';
    setSelected(user.screens);
    el('users-form-title').textContent = `Editar ${user.name}`;
    el('users-save').textContent = 'Salvar alterações';
    el('users-cancel').hidden = false;
    message('');
    el('users-form').scrollIntoView({behavior:'smooth', block:'start'});
    el('users-name').focus({preventScroll:true});
  }

  async function load() {
    try {
      const result = await request(baseUrl());
      state.users = result.users;
      state.screens = result.screens;
      renderScreenOptions();
      renderTable();
    } catch (error) {
      message(error.message, 'error');
    }
  }

  async function save(event) {
    event.preventDefault();
    if (state.saving) return;
    state.saving = true;
    el('users-save').disabled = true;
    const editing = state.editing;
    const body = {name:el('users-name').value.trim(), email:el('users-email').value.trim(), password:el('users-password').value, screens:selectedScreens()};
    try {
      await request(editing ? `${baseUrl()}/${editing}` : baseUrl(), editing ? 'PUT' : 'POST', body);
      resetForm();
      await load();
      message(editing ? 'Usuário atualizado. O menu dessa pessoa muda quando ela recarregar o dashboard.' : 'Usuário cadastrado.', 'success');
    } catch (error) {
      message(error.message, 'error');
    } finally {
      state.saving = false;
      el('users-save').disabled = false;
    }
  }

  async function remove(id) {
    const user = state.users.find(item => item.id === id);
    if (!user || !confirm(`Excluir o usuário ${user.name} (${user.email})? Essa ação não pode ser desfeita.`)) return;
    try {
      await request(`${baseUrl()}/${id}`, 'DELETE');
      if (state.editing === id) resetForm();
      await load();
      message('Usuário excluído.', 'success');
    } catch (error) {
      message(error.message, 'error');
    }
  }

  function enter() { state.active = true; load(); }
  function leave() { state.active = false; }

  document.addEventListener('DOMContentLoaded', () => {
    if (!el('page-users')) return;
    el('users-form').addEventListener('submit', save);
    el('users-cancel').onclick = () => { resetForm(); message(''); };
    el('users-screens').addEventListener('change', updateCount);
    el('users-screens-all').onclick = () => setSelected(state.screens.map(screen => screen.value));
    el('users-screens-none').onclick = () => setSelected([]);
    el('users-body').addEventListener('click', event => {
      const button = event.target.closest('button');
      if (button?.dataset.edit) startEdit(Number(button.dataset.edit));
      if (button?.dataset.delete) remove(Number(button.dataset.delete));
    });
  });

  return {enter, leave};
})();
