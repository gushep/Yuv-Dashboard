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

  return { normalize, isDone, isDoneNotDelivered, isRemoved, isOpen, isBlocked, isNotStarted, isClosed, isExcludedPerson, capacityMinutes, applySettings, settings };
})();
