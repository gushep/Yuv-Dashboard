/* Exporta a esteira da Visão 360° de um cliente como imagem JPEG, para o CS enviar o quadro atualizado ao cliente.
 * Desenhada direto no canvas (sem captura da tela): o layout é sempre o mesmo, independente do tamanho da janela, e só
 * leva o que interessa ao cliente: título, descrição, prioridade e a previsão de entrega pela sprint. */
window.PipelineExport = (() => {
  const SCALE = 2;
  const PAD = 32;
  const COLUMN = 264;
  const GAP = 16;
  const CARD_PAD = 12;
  const FONT = "'DM Sans', sans-serif";
  const MONO = "'DM Mono', monospace";
  const COLORS = {page:'#f1f4f9', column:'#e6ebf2', card:'#ffffff', text:'#0f172a', muted:'#64748b', soft:'#94a3b8', brand:'#1d6ef5'};
  const PRIORITY = {
    'critica':{bg:'#fee2e2', fg:'#b91c1c'}, 'critico':{bg:'#fee2e2', fg:'#b91c1c'},
    'alta':{bg:'#fde8e8', fg:'#dc2626'}, 'media':{bg:'#fef3c7', fg:'#b45309'}, 'baixa':{bg:'#e2e8f0', fg:'#475569'},
  };

  const formatDate = date => date ? date.split('-').reverse().join('/') : '';
  const font = (weight, size, family = FONT) => `${weight} ${size}px ${family}`;

  /** Quebra o texto em linhas que cabem na largura; com limite, a última linha termina em reticências. */
  function wrap(ctx, text, width, maxLines = Infinity) {
    const lines = [];
    for (const paragraph of String(text || '').split(/\n+/)) {
      let line = '';
      for (const word of paragraph.split(/\s+/).filter(Boolean)) {
        const attempt = line ? `${line} ${word}` : word;
        if (ctx.measureText(attempt).width <= width || !line) { line = attempt; continue; }
        lines.push(line);
        line = word;
      }
      if (line) lines.push(line);
    }
    if (lines.length <= maxLines) return lines;
    const kept = lines.slice(0, maxLines);
    let last = kept[maxLines - 1];
    while (last && ctx.measureText(`${last}…`).width > width) last = last.slice(0, -1);
    kept[maxLines - 1] = `${last.trimEnd()}…`;
    return kept;
  }

  function roundRect(ctx, x, y, w, h, r, fill) {
    ctx.beginPath();
    ctx.roundRect(x, y, w, h, r);
    ctx.fillStyle = fill;
    ctx.fill();
  }

  /** Faixa de entrega do card, com a mesma regra da tela: entregue na sprint N, ou previsão pelo fim da sprint (atrasado em vermelho). */
  function banner(row, stage) {
    if (!row.Sprint) return null;
    if (stage === 'implementado') return {label:'Entregue na', value:`Sprint ${row.Sprint}`, bg:'#dcfce7', fg:'#15803d'};
    const end = window.MONDAY_SPRINT_DATES?.[row.Sprint]?.end;
    if (!end) return {label:'Sprint', value:String(row.Sprint), bg:'#e8f0fe', fg:COLORS.brand};
    const late = end < SprintRules.today();
    return {label:`Previsão de entrega${late ? ' · atrasado' : ''}`, value:formatDate(end), bg:late ? '#dc2626' : '#e8f0fe', fg:late ? '#ffffff' : COLORS.brand};
  }

  /** Mede o card (para calcular a altura das colunas) e, com draw, desenha na posição informada. */
  function card(ctx, row, stage, x, y, draw) {
    const inner = COLUMN - 2 * CARD_PAD - 16;
    let top = y + CARD_PAD;
    const strip = banner(row, stage);
    if (strip) {
      ctx.font = font(700, 9.5);
      const labelLines = wrap(ctx, strip.label.toUpperCase(), inner - 100, 3);
      const height = Math.max(34, labelLines.length * 12 + 14);
      if (draw) {
        roundRect(ctx, x + CARD_PAD, top, inner, height, 7, strip.bg);
        ctx.fillStyle = strip.fg;
        ctx.textBaseline = 'middle';
        labelLines.forEach((line, index) => ctx.fillText(line, x + CARD_PAD + 10, top + height / 2 + (index - (labelLines.length - 1) / 2) * 12));
        ctx.font = font(500, 12, MONO);
        ctx.textAlign = 'right';
        ctx.fillText(strip.value, x + CARD_PAD + inner - 10, top + height / 2);
        ctx.textAlign = 'left';
      }
      top += height + 10;
    }
    ctx.font = font(700, 14);
    const title = wrap(ctx, row.Nome, inner, 4);
    if (draw) { ctx.fillStyle = COLORS.text; ctx.textBaseline = 'top'; title.forEach((line, index) => ctx.fillText(line, x + CARD_PAD, top + index * 19)); }
    top += title.length * 19 + 4;
    const description = String(row.Descricao || '').trim();
    if (description) {
      ctx.font = font(400, 12);
      const lines = wrap(ctx, description, inner, 4);
      if (draw) { ctx.fillStyle = COLORS.muted; ctx.textBaseline = 'top'; lines.forEach((line, index) => ctx.fillText(line, x + CARD_PAD, top + index * 16)); }
      top += lines.length * 16 + 6;
    }
    if (row.Prioridade) {
      const colors = PRIORITY[SprintRules.normalize(row.Prioridade)] || PRIORITY.baixa;
      ctx.font = font(700, 10);
      const label = String(row.Prioridade).toUpperCase();
      const width = ctx.measureText(label).width + 14;
      if (draw) {
        roundRect(ctx, x + CARD_PAD, top + 2, width, 18, 4, colors.bg);
        ctx.fillStyle = colors.fg;
        ctx.textBaseline = 'middle';
        ctx.fillText(label, x + CARD_PAD + 7, top + 11);
      }
      top += 24;
    }
    return top - y + CARD_PAD - 4;
  }

  /** Mesma ordem da tela: Implementados das entregas mais recentes para as antigas; as demais etapas por prioridade. */
  function columns(stages, rows) {
    return stages.map(stage => {
      const stageRows = rows.filter(row => row.Etapa === stage.key);
      return {stage, rows:stage.key === 'implementado'
        ? stageRows.sort((a, b) => (Number(b.Sprint) || 0) - (Number(a.Sprint) || 0))
        : Bombeiro.sortByPriority(stageRows)};
    });
  }

  /**
   * Gera e baixa o JPEG da esteira do cliente.
   * @param {{client: string, rows: Array<object>, stages: Array<{key: string, label: string, color: string}>, syncedAt: ?string}} data
   */
  async function download({client, rows, stages, syncedAt}) {
    await Promise.all([document.fonts.load(font(700, 14)), document.fonts.load(font(400, 12)), document.fonts.load(font(500, 12, MONO))]).catch(() => {});
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const lanes = columns(stages, rows);
    // Primeiro mede todos os cards: a imagem tem a altura da coluna mais longa.
    const heights = lanes.map(lane => lane.rows.map(row => card(ctx, row, lane.stage.key, 0, 0, false)));
    const laneHeight = heights.map(list => 52 + list.reduce((sum, height) => sum + height + 10, 0) + (list.length ? 4 : 70));
    const header = 92;
    const width = PAD * 2 + stages.length * COLUMN + (stages.length - 1) * GAP;
    const height = PAD + header + Math.max(...laneHeight) + PAD;
    canvas.width = width * SCALE;
    canvas.height = height * SCALE;
    ctx.scale(SCALE, SCALE);
    ctx.fillStyle = COLORS.page;
    ctx.fillRect(0, 0, width, height);

    ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = COLORS.brand;
    ctx.font = font(700, 11);
    ctx.fillText(`CLIENTE ${client.toUpperCase()} · YUV`, PAD, PAD + 14);
    ctx.fillStyle = COLORS.text;
    ctx.font = font(700, 26);
    ctx.fillText(`Demandas ${client}`, PAD, PAD + 48);
    const updated = syncedAt ? new Date(syncedAt) : new Date();
    ctx.font = font(400, 12);
    ctx.fillStyle = COLORS.muted;
    ctx.textAlign = 'right';
    ctx.fillText(`Atualizado em ${updated.toLocaleString('pt-BR', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'})}`, width - PAD, PAD + 48);
    ctx.textAlign = 'left';

    lanes.forEach((lane, index) => {
      const x = PAD + index * (COLUMN + GAP);
      const y = PAD + header;
      roundRect(ctx, x, y, COLUMN, laneHeight[index], 12, COLORS.column);
      ctx.textBaseline = 'middle';
      ctx.font = font(700, 13);
      ctx.fillStyle = COLORS.text;
      ctx.fillText(lane.stage.label, x + 14, y + 22);
      const count = String(lane.rows.length);
      ctx.font = font(700, 11);
      const pill = ctx.measureText(count).width + 14;
      roundRect(ctx, x + COLUMN - 14 - pill, y + 13, pill, 18, 9, COLORS.card);
      ctx.fillStyle = COLORS.muted;
      ctx.fillText(count, x + COLUMN - 14 - pill + 7, y + 22);
      roundRect(ctx, x + 12, y + 38, COLUMN - 24, 2.5, 1, lane.stage.color);
      let top = y + 50;
      if (!lane.rows.length) {
        ctx.font = font(400, 12);
        ctx.fillStyle = COLORS.soft;
        ctx.textAlign = 'center';
        ctx.fillText('Nenhuma demanda', x + COLUMN / 2, top + 30);
        ctx.textAlign = 'left';
      }
      lane.rows.forEach((row, cardIndex) => {
        const cardHeight = heights[index][cardIndex];
        ctx.save();
        ctx.shadowColor = 'rgba(15,23,42,.08)';
        ctx.shadowBlur = 4;
        ctx.shadowOffsetY = 1;
        roundRect(ctx, x + 8, top, COLUMN - 16, cardHeight, 10, COLORS.card);
        ctx.restore();
        card(ctx, row, lane.stage.key, x + 8, top, true);
        top += cardHeight + 10;
      });
    });

    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.92));
    const slug = SprintRules.normalize(client).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'cliente';
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `demandas-${slug}-${SprintRules.today()}.jpg`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  }

  return {download};
})();
