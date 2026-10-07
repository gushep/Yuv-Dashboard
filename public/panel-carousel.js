/* Modo de apresentação: alterna os dois painéis sem duplicar suas consultas ao Monday. */
window.PanelCarousel = (() => {
  const panels = [
    {page:'page-bombeiro',module:() => window.Bombeiro,label:'Sustentação'},
    {page:'page-sprint-panel',module:() => window.SprintPanel,label:'Sprint'}
  ];
  const state = {active:false,index:0,paused:false,seconds:30,timer:null};
  const el = id => document.getElementById(id);

  function clearCycle() { clearTimeout(state.timer); state.timer = null; }
  function updateControls() {
    el('carousel-current').textContent = `${panels[state.index].label} · ${state.index + 1}/2`;
    el('carousel-pause').textContent = state.paused ? '▶ Continuar' : '⏸ Pausar';
    el('carousel-pause').setAttribute('aria-pressed',String(state.paused));
    el('carousel-interval').value = String(state.seconds);
    el('carousel-fullscreen').hidden = Boolean(document.fullscreenElement);
    el('carousel-status').textContent = state.paused ? 'Troca pausada' : `Troca automática a cada ${state.seconds} segundos`;
  }
  function scheduleCycle() {
    clearCycle();
    if (state.active && !state.paused) state.timer = setTimeout(() => next(),state.seconds * 1000);
  }
  function show(index, sprintHint = null) {
    if (!state.active) return;
    if (state.index !== null) {
      const previous = panels[state.index];
      previous.module()?.leave();
      el(previous.page).classList.remove('active');
    }
    state.index = (index + panels.length) % panels.length;
    const selected = panels[state.index];
    el(selected.page).classList.add('active');
    selected.module()?.enter(sprintHint);
    updateControls();
    scheduleCycle();
  }
  function next() { show(state.index + 1); }
  function previous() { show(state.index - 1); }
  function togglePause() { state.paused = !state.paused; updateControls(); scheduleCycle(); }
  function setIntervalSeconds(value) {
    const seconds = Number(value);
    if (![15,30,45,60,90].includes(seconds)) return;
    state.seconds = seconds;
    try { localStorage.setItem('yuv-carousel-seconds',String(seconds)); } catch { }
    updateControls();
    scheduleCycle();
  }
  async function requestFullscreen() {
    if (document.fullscreenElement || !document.documentElement.requestFullscreen) return;
    try { await document.documentElement.requestFullscreen(); }
    catch { /* O layout em tela cheia por CSS continua disponível. */ }
    updateControls();
  }
  function enter(startIndex = 0, sprintHint = null) {
    if (state.active) return;
    state.active = true;
    state.paused = false;
    try {
      const saved = Number(localStorage.getItem('yuv-carousel-seconds'));
      if ([15,30,45,60,90].includes(saved)) state.seconds = saved;
    } catch { }
    document.body.classList.add('carousel-view');
    el('carousel-controls').hidden = false;
    state.index = null;
    show(startIndex,sprintHint);
    requestFullscreen();
  }
  function leave() {
    if (!state.active) return;
    clearCycle();
    panels[state.index].module()?.leave();
    el(panels[state.index].page).classList.remove('active');
    state.active = false;
    el('carousel-controls').hidden = true;
    document.body.classList.remove('carousel-view');
    if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
  }
  document.addEventListener('DOMContentLoaded',() => {
    if (!el('carousel-controls')) return;
    el('carousel-next').addEventListener('click',next);
    el('carousel-prev').addEventListener('click',previous);
    el('carousel-pause').addEventListener('click',togglePause);
    el('carousel-interval').addEventListener('change',event => setIntervalSeconds(event.target.value));
    el('carousel-fullscreen').addEventListener('click',requestFullscreen);
    el('carousel-exit').addEventListener('click',() => navigate('dashboard'));
    document.addEventListener('fullscreenchange',() => {
      if (!state.active) return;
      updateControls();
      requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
    });
    document.addEventListener('keydown',event => {
      if (!state.active) return;
      if (event.key === 'Escape') { event.preventDefault(); navigate('dashboard'); }
      if (event.key === 'ArrowRight') { event.preventDefault(); next(); }
      if (event.key === 'ArrowLeft') { event.preventDefault(); previous(); }
      if (event.code === 'Space' && !['BUTTON','SELECT','INPUT','TEXTAREA'].includes(document.activeElement?.tagName)) {
        event.preventDefault(); togglePause();
      }
    });
    updateControls();
  });
  return {enter,leave,next,previous,togglePause,setIntervalSeconds};
})();
