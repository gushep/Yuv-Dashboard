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
   */
  function barHtml({present = [], seconds = 0, label = 'Tempo', targetMinutes = null, live = true}) {
    const over = targetMinutes !== null && seconds > targetMinutes * 60;
    return `<div class="cer-bar ${over ? 'over' : ''}" role="status">
      ${present.length ? avatarsHtml(present, 8) : ''}
      <span class="cer-count">${present.length ? `${present.length} ${live ? 'na sala' : 'presente(s)'}` : 'ninguém na sala agora'}</span>
      <span class="cer-timer">⏱ ${escape(label)} <b data-cer-clock>${clock(seconds)}</b>${targetMinutes !== null ? ` de ${targetMinutes}:00` : ''}</span>
      ${over ? `<span class="cer-alert">⚠ passou de ${targetMinutes} min</span>` : ''}
    </div>`;
  }

  /** Para o histórico: quem esteve (avatares, nomes no título) e quanto durou. */
  function attendanceHtml(attendees = [], seconds = null, {live = false} = {}) {
    if (!attendees.length && !seconds) return '<span class="cer-attendance empty">sem registro de presença</span>';
    return `<span class="cer-attendance" title="${escape(attendees.length ? `Presentes: ${attendees.join(', ')}` : '')}">${avatarsHtml(attendees, 5)}
      <span>👥 ${attendees.length} · ⏱ ${live ? `${duration(seconds)} até agora` : duration(seconds)}</span></span>`;
  }

  return {clock, duration, avatarsHtml, barHtml, attendanceHtml};
})();
