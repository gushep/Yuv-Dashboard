/*
 * Tabelas de lista viram cartões no celular (ver responsive.css): cada célula recebe o nome da coluna em data-label
 * e a coluna principal (th[data-primary], ou a primeira) vira o título do cartão. Funciona em qualquer tabela
 * redesenhada depois, porque observa as mudanças da página.
 */
(() => {
  const SELECTOR = 'table.stack-mobile, .tscroll > table';

  function label(table) {
    const headers = [...table.querySelectorAll('thead th')];
    const names = headers.map(th => th.textContent.trim());
    const primary = Math.max(0, headers.findIndex(th => th.hasAttribute('data-primary')));
    table.querySelectorAll('tbody tr').forEach(row => [...row.children].forEach((cell, index) => {
      if (cell.colSpan > 1) return;
      if (cell.dataset.label !== names[index]) cell.dataset.label = names[index] || '';
      cell.classList.toggle('cell-primary', index === primary);
      // Campo sem valor ("—") não ocupa linha no cartão.
      cell.classList.toggle('cell-empty', index !== primary && !cell.querySelector('input, select, button, img') && ['', '—', '-'].includes(cell.textContent.trim()));
    }));
  }

  let scheduled = false;
  const labelAll = () => { scheduled = false; document.querySelectorAll(SELECTOR).forEach(label); };
  const schedule = () => { if (!scheduled) { scheduled = true; requestAnimationFrame(labelAll); } };

  document.addEventListener('DOMContentLoaded', () => {
    labelAll();
    new MutationObserver(records => {
      if (records.some(record => record.target.closest?.('table, .tscroll, dialog, .page'))) schedule();
    }).observe(document.body, {childList:true, subtree:true});
  });
})();
