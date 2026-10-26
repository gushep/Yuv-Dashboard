/* Anexos enviados ao Monday (comentários de tarefas e abertura de tickets): nome de prints colados, redução de imagens e upload. */
window.Attachments = (() => {
  /** Imagens acima disso são reduzidas antes do envio. */
  const SHRINK_ABOVE_BYTES = 1.5 * 1024 * 1024;
  const DEFAULT_LIMIT = 50 * 1024 * 1024;

  function fileSize(bytes) { return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`; }

  /** Prints colados chegam como "image.png"; ganham nome com data e hora para ficarem distinguíveis no Monday. */
  function named(file) {
    return file.name && file.name !== 'image.png' ? file
      : new File([file], `print-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.${(file.type.split('/')[1] || 'png')}`, {type:file.type});
  }

  /**
   * Prints e fotos grandes viram JPEG reduzido (até 2560 px) antes do envio, para caber no limite de upload.
   * Imagens pequenas seguem como estão, sem perda de qualidade.
   */
  async function shrinkImage(file, limit = DEFAULT_LIMIT) {
    if (!/^image\/(png|jpeg|webp|bmp)$/.test(file.type) || file.size <= Math.min(limit, SHRINK_ABOVE_BYTES)) return file;
    const bitmap = await createImageBitmap(file).catch(() => null);
    if (!bitmap) return file;
    let smallest = file;
    for (const [maxSide, quality] of [[2560, .9], [1920, .85], [1440, .8]]) {
      const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(bitmap.width * scale);
      canvas.height = Math.round(bitmap.height * scale);
      const context = canvas.getContext('2d');
      context.fillStyle = '#fff';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
      if (blob && blob.size < smallest.size) smallest = new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', {type:'image/jpeg'});
      if (smallest.size <= limit) break;
    }
    bitmap.close();
    return smallest;
  }

  /** Nomeia e reduz os arquivos recebidos (seleção, arrastar ou colar). */
  async function prepare(list, limit = DEFAULT_LIMIT) {
    const prepared = [];
    for (const file of [...list]) prepared.push(await shrinkImage(named(file), limit));
    return prepared;
  }

  function tooBigMessage(files, limit) {
    return `${files.map(file => `${file.name} (${fileSize(file.size)})`).join(', ')} passa(m) do limite de ${fileSize(limit)} por arquivo aceito pelo servidor.`;
  }

  /** Um anexo por requisição: cada envio precisa caber sozinho no limite de upload do PHP. */
  async function upload(url, file) {
    const form = new FormData();
    form.append('file', file, file.name);
    const response = await fetch(url, {
      method:'POST', body:form,
      headers:{'Accept':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''}
    });
    const result = await response.json().catch(() => null);
    if (response.status === 413 || (!response.ok && result === null)) throw new Error('o servidor recusou o tamanho do arquivo (limite de upload do PHP)');
    if (response.status === 403) throw new Error('sem permissão para anexar');
    if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.error || result.message || 'falha no envio');
  }

  return {fileSize, prepare, tooBigMessage, upload, DEFAULT_LIMIT};
})();
