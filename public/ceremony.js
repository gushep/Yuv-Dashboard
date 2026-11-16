/*
 * Presentes e tempo das cerimônias (Sprint Planning, Daily Scrum e Sprint Retrospective), no mesmo formato em todas:
 * na sala, a barra com quem está agora e o tempo corrido (com o tempo previsto e alerta quando estoura); no histórico,
 * quem esteve e quanto durou.
 */
window.Ceremony = (() => {
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const initials = name => String(name || '?').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();

  /** Cronômetro: 7:05 ou 1:02:30. */
  function clock(seconds) {
    const total = Math.max(0, Math.floor(seconds || 0));
    const hours = Math.floor(total / 3600), minutes = Math.floor(total % 3600 / 60), rest = String(total % 60).padStart(2, '0');
    return hours ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}` : `${minutes}:${rest}`;
  }

  /** Duração no histórico: "menos de 1 min", "14 min" ou "1h 05min". */
  function duration(seconds) {
    const minutes = Math.round(Math.max(0, seconds || 0) / 60);
    if (minutes < 1) return 'menos de 1 min';
    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}min`;
  }

  function avatarsHtml(names, max = 6) {
    const shown = names.slice(0, max), extra = names.length - shown.length;
    return `<span class="cer-avatars">${shown.map((name, index) => `<span class="cer-av cer-av-${index % 4}" title="${escape(name)}">${escape(initials(name))}</span>`).join('')}${extra > 0 ? `<span class="cer-av cer-av-more" title="${escape(names.slice(max).join(', '))}">+${extra}</span>` : ''}</span>`;
  }

  /**
   * Barra da sala: presentes agora e o tempo corrido. Com `targetMinutes`, mostra o previsto e alerta quando passa.
   * O tempo fica num elemento com `data-cer-clock` para a tela atualizar a cada segundo sem redesenhar a barra.
   * `actions` é o HTML dos botões da cerimônia (ex.: Iniciar e Encerrar), no fim da barra.
   */
  function barHtml({present = [], seconds = 0, label = 'Tempo', targetMinutes = null, live = true, actions = ''}) {
    const over = targetMinutes !== null && seconds > targetMinutes * 60;
    return `<div class="cer-bar ${over ? 'over' : ''}" role="status">
      ${present.length ? avatarsHtml(present, 8) : ''}
      <span class="cer-count">${present.length ? `${present.length} ${live ? 'na sala' : 'presente(s)'}` : 'ninguém na sala agora'}</span>
      <span class="cer-timer">⏱ ${escape(label)} <b data-cer-clock>${clock(seconds)}</b>${targetMinutes !== null ? ` de ${targetMinutes}:00` : ''}</span>
      ${over ? `<span class="cer-alert">⚠ passou de ${targetMinutes} min</span>` : ''}
      ${actions ? `<span class="cer-actions">${actions}</span>` : ''}
    </div>`;
  }

  /** Para o histórico: quem esteve (avatares, nomes no título) e quanto durou. */
  function attendanceHtml(attendees = [], seconds = null, {live = false} = {}) {
    if (!attendees.length && !seconds) return '<span class="cer-attendance empty">sem registro de presença</span>';
    return `<span class="cer-attendance" title="${escape(attendees.length ? `Presentes: ${attendees.join(', ')}` : '')}">${avatarsHtml(attendees, 5)}
      <span>👥 ${attendees.length} · ⏱ ${live ? `${duration(seconds)} até agora` : duration(seconds)}</span></span>`;
  }

  // ── Caixas de texto e redesenho das salas ────────────────────────────────────
  /** Telas do Gerenciamento Ágil: nelas, as caixas de texto crescem com o texto, sem rolagem interna. */
  const PAGES = ['page-planning', 'page-daily-scrum', 'page-sprint-review', 'page-sprint-retrospective'];
  const inPages = node => PAGES.some(id => document.getElementById(id)?.contains(node));

  /** Ajusta a altura da caixa de texto ao conteúdo (a altura mínima é a das linhas do `rows`). */
  function autosize(field) {
    // Escondida (diálogo fechado, tela oculta) não dá para medir: ajusta quando aparecer.
    if (!field || field.tagName !== 'TEXTAREA' || !field.isConnected || !field.getClientRects().length) return;
    field.style.height = 'auto';
    const border = field.offsetHeight - field.clientHeight;
    field.style.height = `${field.scrollHeight + border}px`;
  }
  /** Ajusta todas as caixas de texto dentro de um elemento (depois de cada redesenho). */
  const autosizeAll = root => root?.querySelectorAll?.('textarea').forEach(autosize);

  /**
   * Redesenha mantendo o campo em que a pessoa está (mesmo id), com o cursor no lugar: as salas atualizam o que os
   * outros fazem sem tirar o foco de quem está com o cursor numa caixa.
   */
  function keepFocus(root, redraw) {
    const active = document.activeElement;
    const kept = active && root?.contains(active) && active.id && active.matches('input, textarea, select')
      ? {id: active.id, start: active.selectionStart ?? null, end: active.selectionEnd ?? null, scroll: window.scrollY} : null;
    redraw();
    if (!kept) return;
    const field = document.getElementById(kept.id);
    if (!field || document.activeElement === field) return;
    field.focus({preventScroll: true});
    if (kept.start !== null && typeof field.setSelectionRange === 'function') { try { field.setSelectionRange(kept.start, kept.end); } catch { /* campo sem seleção */ } }
    window.scrollTo({top: kept.scroll});
  }

  document.addEventListener('input', event => { if (event.target.tagName === 'TEXTAREA' && inPages(event.target)) autosize(event.target); });
  document.addEventListener('DOMContentLoaded', () => {
    // Cada redesenho das telas recria as caixas de texto: ajusta as novas (e as que aparecem ao trocar de tela).
    const observer = new MutationObserver(records => {
      for (const record of records) {
        for (const node of record.addedNodes) {
          if (node.nodeType !== 1) continue;
          if (node.tagName === 'TEXTAREA') autosize(node); else autosizeAll(node);
        }
        if (record.type === 'attributes' && record.target.nodeType === 1) autosizeAll(record.target);
      }
    });
    PAGES.forEach(id => { const page = document.getElementById(id); if (page) observer.observe(page, {childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'class', 'open']}); });
  });

  return {clock, duration, avatarsHtml, barHtml, attendanceHtml, autosize, autosizeAll, keepFocus};
})();
