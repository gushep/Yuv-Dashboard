/* Tema claro ou escuro do usuário. O servidor já entrega o <html> com data-theme salvo; o botão do menu lateral troca na hora
 * e grava a escolha. As cores das telas vêm do dark-theme.css; aqui ficam só as dos gráficos, que são desenhados em canvas. */
window.Theme = (() => {
  /** Cores neutras dos gráficos (eixos, grade, legenda, textos e borda das fatias) e a versão de cada uma no tema escuro. */
  const DARK = {
    '#94a3b8':'#7d8898', '#64748b':'#9aa5b5', '#4b5568':'#aab4c3', '#334155':'#c8d0dc', '#0d1117':'#e6ebf2',
    'rgba(0,0,0,.05)':'rgba(255,255,255,.07)', '#fff':'#161c26',
    // Padrões do Chart.js, para os gráficos que não definem cor de eixo ou de grade.
    '#666':'#9ea8b7', 'rgba(0,0,0,0.1)':'rgba(255,255,255,.1)',
  };
  const LIGHT = Object.fromEntries(Object.entries(DARK).map(([light, dark]) => [dark, light]));

  function current() { return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'; }
  function isDark() { return current() === 'dark'; }

  /** Cor neutra no tema atual; cores de série (azul, verde...) não estão no mapa e voltam iguais. */
  function color(value) {
    if (typeof value !== 'string') return value;
    const key = value.replace(/\s+/g, '').toLowerCase();
    return (isDark() ? DARK[key] : LIGHT[key]) || value;
  }

  /** Ajusta as cores neutras das opções de um gráfico ao tema atual, antes de cada desenho. */
  function adaptChart(chart) {
    const options = chart.options || {};
    for (const scale of Object.values(options.scales || {})) {
      if (scale.ticks) scale.ticks.color = color(scale.ticks.color);
      if (scale.grid) scale.grid.color = color(scale.grid.color);
      if (scale.title) scale.title.color = color(scale.title.color);
    }
    const plugins = options.plugins || {};
    if (plugins.legend?.labels) plugins.legend.labels.color = color(plugins.legend.labels.color);
    for (const name of ['title', 'subtitle']) {
      if (plugins[name]) plugins[name].color = color(plugins[name].color);
    }
    // A borda branca separa as fatias das roscas: no escuro ela acompanha o fundo do card.
    for (const dataset of chart.data?.datasets || []) {
      if (typeof dataset.borderColor === 'string' && ['#fff', '#ffffff', '#161c26'].includes(dataset.borderColor.toLowerCase())) {
        dataset.borderColor = color(dataset.borderColor);
      }
    }
  }

  if (window.Chart) {
    Chart.register({id:'yuvTheme', beforeUpdate:adaptChart});
  }

  function apply(theme) {
    document.documentElement.dataset.theme = theme;
    document.querySelectorAll('.theme-toggle').forEach(button => button.setAttribute('aria-pressed', String(theme === 'dark')));
    if (window.Chart) Object.values(Chart.instances).forEach(chart => chart.update('none'));
  }

  /** Troca na hora e grava; se o servidor recusar, volta ao tema anterior. */
  async function toggle() {
    const previous = current();
    const next = previous === 'dark' ? 'light' : 'dark';
    apply(next);
    try {
      const response = await fetch('/api/theme', {
        method:'POST',
        headers:{'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''},
        body:JSON.stringify({theme:next}),
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
    } catch (error) {
      apply(previous);
      alert('Não foi possível salvar o tema. Tente de novo.');
    }
  }

  return {toggle, color, isDark};
})();
