/* Definições únicas dos indicadores. Todas as telas usam estas regras; o back-end espelha em App\Support\SprintRules. */
window.SprintRules = (() => {
  const normalize = value => String(value ?? '').trim().toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ');
  const DONE = ['implementado'];
  const DONE_NOT_DELIVERED = ['aguardando deploy', 'qa - aprovado'];
  const REMOVED = ['cancelado', 'descontinuado', 'despriorizado'];
  const BLOCKED = ['parado', 'bloqueado', 'impedido', 'limitacao externa'];
  const NOT_STARTED = ['', 'backlog', 'pronto pra iniciar', 'pronto para iniciar', 'pronto pra comecar', 'pronto para comecar'];
  const settings = { capacityHours: 80, excludedPeople: [], team: [], capacityOverrides: {} };
  const TEAM_LABELS = { fullstack: 'Full stack', projects: 'Projetos (IA)' };

  const isDone = status => DONE.includes(normalize(status));
  const isDoneNotDelivered = status => DONE_NOT_DELIVERED.includes(normalize(status));
  const isRemoved = status => REMOVED.includes(normalize(status));
  /** Em aberto: ainda exige trabalho do time nesta sprint. */
  const isOpen = status => !isDone(status) && !isDoneNotDelivered(status) && !isRemoved(status);
  const isBlocked = status => BLOCKED.includes(normalize(status));
  const isNotStarted = status => NOT_STARTED.includes(normalize(status));
  /** Encerrado nos kanbans: entregue ou retirado do escopo. */
  const isClosed = status => isDone(status) || isRemoved(status);
  /** Em teste nos kanbans: qualquer status de QA (Testes, Reprovado, Aprovado). */
  const isInTest = status => /^qa\b/.test(normalize(status));
  /**
   * Times da capacidade (espelho de App\Support\TeamRules). Full stack atende a sprint e a sustentação; Projetos (IA)
   * atende demandas próprias; quem não está em nenhum fica fora das métricas por pessoa. Enquanto ninguém foi
   * classificado, vale a regra anterior: todos contam como full stack, menos as pessoas fora das métricas.
   */
  const teamsConfigured = () => settings.team.length > 0;
  function teamOf(name) {
    const key = normalize(name);
    if (teamsConfigured()) return settings.team.find(member => normalize(member.name) === key)?.group ?? null;
    return settings.excludedPeople.some(person => normalize(person) === key) ? null : 'fullstack';
  }
  const teamLabel = group => TEAM_LABELS[group] || 'Sem time';
  const isExcludedPerson = name => teamOf(name) === null;
  /** Pessoas classificadas: contam na capacidade mesmo sem card na sprint. */
  const teamMembers = () => settings.team.map(member => ({ name: member.name, group: member.group }));
  const capacityMinutes = () => Math.round(settings.capacityHours * 60);
  /**
   * Capacidade da pessoa na sprint, em minutos: a salva nesta sprint; sem ela, a última salva numa sprint anterior
   * (herança); sem nenhuma, o padrão das Configurações. `overrides` usa a chave "sprint|nome".
   */
  function capacityFor(overrides = settings.capacityOverrides, sprint, name) {
    const target = normalize(name);
    let bestSprint = null, value = null;
    for (const [key, minutes] of Object.entries(overrides || {})) {
      const split = key.indexOf('|');
      const keySprint = Number(key.slice(0, split)), keyName = key.slice(split + 1);
      if (split < 0 || keySprint > Number(sprint) || normalize(keyName) !== target || !Number.isInteger(minutes)) continue;
      if (bestSprint === null || keySprint > bestSprint) { bestSprint = keySprint; value = minutes; }
    }
    return { minutes: value ?? capacityMinutes(), inherited: bestSprint !== null && bestSprint !== Number(sprint), fromSprint: bestSprint };
  }
  function applySettings(values = {}) {
    if (Number.isFinite(Number(values.capacityHours)) && Number(values.capacityHours) >= 0) settings.capacityHours = Number(values.capacityHours);
    if (Array.isArray(values.excludedPeople)) settings.excludedPeople = values.excludedPeople.map(String).filter(Boolean);
    if (values.capacityOverrides && typeof values.capacityOverrides === 'object') settings.capacityOverrides = { ...values.capacityOverrides };
    if (Array.isArray(values.team)) settings.team = values.team.filter(member => member && TEAM_LABELS[member.group]).map(member => ({ id: String(member.id), name: String(member.name), group: member.group }));
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

  /** Semáforo de uso contra a capacidade: amarelo a partir de 90% (o mesmo limite da Torre de Controle), vermelho a partir de 100%. */
  const CAPACITY_YELLOW = 90;
  const LEVEL_TEXT = { green: 'Verde: bastante saldo (abaixo de 90% da capacidade)', yellow: 'Amarelo: perto do limite (de 90% a menos de 100%)', red: 'Vermelho: chegou ou passou da capacidade (100% ou mais)' };
  function capacityLevel(used, capacity) {
    if (!(capacity > 0)) return used > 0 ? 'red' : 'green';
    const percent = used / capacity * 100;
    return percent >= 100 ? 'red' : percent >= CAPACITY_YELLOW ? 'yellow' : 'green';
  }

  return { normalize, isDone, capacityLevel, LEVEL_TEXT, isDoneNotDelivered, isRemoved, isOpen, isBlocked, isNotStarted, isClosed, isInTest, isExcludedPerson, capacityMinutes, capacityFor, teamOf, teamLabel, teamMembers, teamsConfigured, TEAM_LABELS, applySettings, settings, today, workingDays, pace };
})();
