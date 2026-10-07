/* Definições únicas dos indicadores. Todas as telas usam estas regras; o back-end espelha em App\Support\SprintRules. */
window.SprintRules = (() => {
  const normalize = value => String(value ?? '').trim().toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ');
  const DONE = ['implementado'];
  const DONE_NOT_DELIVERED = ['aguardando deploy', 'qa - aprovado'];
  const REMOVED = ['cancelado', 'descontinuado', 'despriorizado'];
  const BLOCKED = ['parado', 'bloqueado', 'impedido', 'limitacao externa'];
  const NOT_STARTED = ['backlog', 'pronto pra iniciar', 'pronto para iniciar', 'pronto pra comecar', 'pronto para comecar'];
  const settings = { capacityHours: 80, excludedPeople: [] };

  const isDone = status => DONE.includes(normalize(status));
  const isDoneNotDelivered = status => DONE_NOT_DELIVERED.includes(normalize(status));
  const isRemoved = status => REMOVED.includes(normalize(status));
  /** Em aberto: ainda exige trabalho do time nesta sprint. */
  const isOpen = status => !isDone(status) && !isDoneNotDelivered(status) && !isRemoved(status);
  const isBlocked = status => BLOCKED.includes(normalize(status));
  const isNotStarted = status => NOT_STARTED.includes(normalize(status));
  /** Encerrado nos kanbans: entregue ou retirado do escopo. */
  const isClosed = status => isDone(status) || isRemoved(status);
  const isExcludedPerson = name => settings.excludedPeople.some(person => normalize(person) === normalize(name));
  const capacityMinutes = () => Math.round(settings.capacityHours * 60);
  function applySettings(values = {}) {
    if (Number.isFinite(Number(values.capacityHours)) && Number(values.capacityHours) >= 0) settings.capacityHours = Number(values.capacityHours);
    if (Array.isArray(values.excludedPeople)) settings.excludedPeople = values.excludedPeople.map(String).filter(Boolean);
  }

  /** Data de hoje (AAAA-MM-DD) no fuso America/Sao_Paulo. */
  function today(now = new Date()) {
    const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
    const part = type => parts.find(item => item.type === type)?.value;
    return `${part('year')}-${part('month')}-${part('day')}`;
  }

  /** Dias úteis (segunda a sexta) entre duas datas AAAA-MM-DD, inclusive. */
  function workingDays(start, end) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(start || '') || !/^\d{4}-\d{2}-\d{2}$/.test(end || '') || start > end) return [];
    const days = [];
    for (let day = Date.parse(`${start}T00:00:00Z`), last = Date.parse(`${end}T00:00:00Z`); day <= last; day += 86400000) {
      const date = new Date(day);
      if (date.getUTCDay() >= 1 && date.getUTCDay() <= 5) days.push(date.toISOString().slice(0, 10));
    }
    return days;
  }

  /**
   * Projeta o consumo de um orçamento (reserva ou capacidade) mantendo o ritmo médio por dia útil já transcorrido.
   * Hoje conta como dia útil completo, como no Capacity.
   */
  function pace(consumedMinutes, dates, today, budgetMinutes) {
    const all = workingDays(dates?.start, dates?.end);
    if (!all.length || !today) return { status: 'unavailable' };
    const elapsed = all.filter(day => day <= today).length;
    if (!elapsed) return { status: 'not-started', totalDays: all.length };
    const daily = consumedMinutes / elapsed;
    const projected = Math.round(daily * all.length);
    const exhaustionIndex = budgetMinutes > 0 && daily > 0 ? Math.ceil(budgetMinutes / daily) : null;
    return {
      status: elapsed >= all.length ? 'finished' : 'ongoing',
      elapsedDays: elapsed, totalDays: all.length, daily, projected,
      projectedPercent: budgetMinutes > 0 ? projected / budgetMinutes * 100 : null,
      exhaustionDate: exhaustionIndex !== null && exhaustionIndex <= all.length ? all[exhaustionIndex - 1] : null,
      sprintEnd: all.at(-1)
    };
  }

  return { normalize, isDone, isDoneNotDelivered, isRemoved, isOpen, isBlocked, isNotStarted, isClosed, isExcludedPerson, capacityMinutes, applySettings, settings, today, workingDays, pace };
})();
