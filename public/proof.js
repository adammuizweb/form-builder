(function () {
  'use strict';

  const WIDTH = 1200;
  const PAD = 96;
  const CONTENT_WIDTH = WIDTH - (PAD * 2);
  const encoder = new TextEncoder();
  const proofBreakpoints = new WeakMap();

  const text = (value, max) => String(value == null ? '' : value).replace(/[\u0000-\u001f\u007f]+/gu, ' ').replace(/\s+/gu, ' ').trim().slice(0, max);

  function roundedRect(ctx, x, y, width, height, radius) {
    const r = Math.min(radius, width / 2, height / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + width, y, x + width, y + height, r);
    ctx.arcTo(x + width, y + height, x, y + height, r);
    ctx.arcTo(x, y + height, x, y, r);
    ctx.arcTo(x, y, x + width, y, r);
    ctx.closePath();
  }

  function splitLongWord(ctx, word, maxWidth) {
    const chunks = [];
    let chunk = '';
    for (const character of Array.from(word)) {
      const candidate = chunk + character;
      if (chunk && ctx.measureText(candidate).width > maxWidth) {
        chunks.push(chunk);
        chunk = character;
      } else {
        chunk = candidate;
      }
    }
    if (chunk) chunks.push(chunk);
    return chunks;
  }

  function wrappedLines(ctx, value, maxWidth) {
    const source = text(value, 4000);
    if (!source) return [];
    const words = source.split(/\s+/u).flatMap((word) => ctx.measureText(word).width > maxWidth ? splitLongWord(ctx, word, maxWidth) : [word]);
    const lines = [];
    let line = '';
    for (const word of words) {
      const candidate = line ? line + ' ' + word : word;
      if (line && ctx.measureText(candidate).width > maxWidth) {
        lines.push(line);
        line = word;
      } else {
        line = candidate;
      }
    }
    if (line) lines.push(line);
    return lines;
  }

  function drawWrapped(ctx, value, x, y, maxWidth, lineHeight, breakpoints) {
    for (const line of wrappedLines(ctx, value, maxWidth)) {
      ctx.fillText(line, x, y);
      y += lineHeight;
      if (Array.isArray(breakpoints)) breakpoints.push(Math.ceil(y));
    }
    return y;
  }

  function proofCardHeight(ctx, model) {
    let y = 54;
    ctx.font = '700 21px system-ui, sans-serif';
    y += (wrappedLines(ctx, text(model.reference_label, 120).toUpperCase(), CONTENT_WIDTH - 76).length * 30) + 24;
    y += 60;
    ctx.font = '600 23px system-ui, sans-serif';
    y += wrappedLines(ctx, text(model.submitted_label, 120) + ': ' + text(model.submitted_at, 200), CONTENT_WIDTH - 76).length * 32;
    return Math.max(250, Math.ceil(y + 50));
  }

  function proofHeight(ctx, model) {
    let y = 142;
    ctx.font = '800 25px system-ui, sans-serif';
    ctx.letterSpacing = '3px';
    y += (wrappedLines(ctx, text(model.proof_title, 120).toUpperCase(), CONTENT_WIDTH).length * 34) + 40;
    ctx.letterSpacing = '0px';
    ctx.font = '800 50px system-ui, sans-serif';
    y += (wrappedLines(ctx, model.form_title, CONTENT_WIDTH).length * 62) + 28;
    ctx.font = '750 36px system-ui, sans-serif';
    y += (wrappedLines(ctx, model.success_heading, CONTENT_WIDTH).length * 46) + 18;
    ctx.font = '400 27px system-ui, sans-serif';
    y += (wrappedLines(ctx, model.success_message, CONTENT_WIDTH).length * 39) + 34;
    y += proofCardHeight(ctx, model) + 58;
    ctx.font = '400 22px system-ui, sans-serif';
    y += (wrappedLines(ctx, model.note, CONTENT_WIDTH).length * 33) + 55;
    return Math.max(1050, Math.ceil(y + 45 + 75));
  }

  function proofCanvas(model) {
    const sizing = document.createElement('canvas');
    sizing.width = WIDTH;
    sizing.height = 1;
    const sizingContext = sizing.getContext('2d');
    if (!sizingContext) throw new Error('Canvas is unavailable.');
    const height = proofHeight(sizingContext, model);
    const work = document.createElement('canvas');
    work.width = WIDTH;
    work.height = height;
    const ctx = work.getContext('2d', { alpha: false });
    if (!ctx) throw new Error('Canvas is unavailable.');
    const breakpoints = [];

    ctx.fillStyle = '#f4f7f2';
    ctx.fillRect(0, 0, WIDTH, height);
    ctx.fillStyle = '#ffffff';
    roundedRect(ctx, 40, 40, WIDTH - 80, height - 80, 30);
    ctx.fill();
    ctx.fillStyle = '#2b7a4a';
    roundedRect(ctx, 40, 40, WIDTH - 80, 22, 11);
    ctx.fill();

    let y = 142;
    ctx.fillStyle = '#2b7a4a';
    ctx.font = '800 25px system-ui, sans-serif';
    ctx.letterSpacing = '3px';
    y = drawWrapped(ctx, text(model.proof_title, 120).toUpperCase(), PAD, y, CONTENT_WIDTH, 34, breakpoints) + 40;
    ctx.letterSpacing = '0px';

    ctx.fillStyle = '#1d241d';
    ctx.font = '800 50px system-ui, sans-serif';
    y = drawWrapped(ctx, model.form_title, PAD, y, CONTENT_WIDTH, 62, breakpoints) + 28;

    ctx.fillStyle = '#2b7a4a';
    ctx.font = '750 36px system-ui, sans-serif';
    y = drawWrapped(ctx, model.success_heading, PAD, y, CONTENT_WIDTH, 46, breakpoints) + 18;

    ctx.fillStyle = '#596659';
    ctx.font = '400 27px system-ui, sans-serif';
    y = drawWrapped(ctx, model.success_message, PAD, y, CONTENT_WIDTH, 39, breakpoints) + 34;

    const cardY = y;
    const cardHeight = proofCardHeight(ctx, model);
    breakpoints.push(Math.max(1, Math.floor(cardY - 21)));
    ctx.fillStyle = '#edf5ef';
    roundedRect(ctx, PAD, cardY, CONTENT_WIDTH, cardHeight, 22);
    ctx.fill();
    ctx.strokeStyle = '#b8d5c0';
    ctx.lineWidth = 2;
    ctx.stroke();

    y = cardY + 54;
    ctx.fillStyle = '#596659';
    ctx.font = '700 21px system-ui, sans-serif';
    y = drawWrapped(ctx, text(model.reference_label, 120).toUpperCase(), PAD + 38, y, CONTENT_WIDTH - 76, 30) + 24;
    ctx.fillStyle = '#1c5633';
    ctx.font = '800 38px ui-monospace, SFMono-Regular, Consolas, monospace';
    ctx.fillText(text(model.reference, 80), PAD + 38, y);
    y += 60;
    ctx.fillStyle = '#596659';
    ctx.font = '600 23px system-ui, sans-serif';
    drawWrapped(ctx, text(model.submitted_label, 120) + ': ' + text(model.submitted_at, 200), PAD + 38, y, CONTENT_WIDTH - 76, 32);
    y = cardY + cardHeight + 58;
    breakpoints.push(Math.ceil(cardY + cardHeight + 29));

    ctx.fillStyle = '#6b776b';
    ctx.font = '400 22px system-ui, sans-serif';
    y = drawWrapped(ctx, model.note, PAD, y, CONTENT_WIDTH, 33, breakpoints) + 55;

    ctx.strokeStyle = '#d9e2d6';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(PAD, y);
    ctx.lineTo(WIDTH - PAD, y);
    ctx.stroke();
    y += 45;
    ctx.fillStyle = '#879187';
    ctx.font = '500 19px system-ui, sans-serif';
    ctx.fillText(text(model.reference, 80), PAD, y);
    proofBreakpoints.set(work, breakpoints.filter((point) => point > 0 && point < height).sort((a, b) => a - b));
    return work;
  }

  function canvasBlob(canvas, type, quality) {
    return new Promise((resolve, reject) => canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('Image encoding failed.')), type, quality));
  }

  function concatBytes(parts) {
    const length = parts.reduce((total, part) => total + part.length, 0);
    const output = new Uint8Array(length);
    let offset = 0;
    for (const part of parts) {
      output.set(part, offset);
      offset += part.length;
    }
    return output;
  }

  function ascii(value) {
    return encoder.encode(value);
  }

  async function pdfBlob(canvas) {
    const pageWidth = 595.28;
    const pageHeight = 841.89;
    const margin = 24;
    const scale = (pageWidth - margin * 2) / canvas.width;
    const sliceHeight = Math.floor((pageHeight - margin * 2) / scale);
    const safeBreakpoints = proofBreakpoints.get(canvas) || [];
    const pages = [];
    let top = 0;
    while (top < canvas.height) {
      const limit = Math.min(top + sliceHeight, canvas.height);
      const candidates = safeBreakpoints.filter((point) => point > top && point <= limit);
      const preferred = candidates.length ? candidates[candidates.length - 1] : limit;
      const bottom = limit === canvas.height || preferred - top >= sliceHeight * 0.55 ? preferred : limit;
      const height = bottom - top;
      const pageCanvas = document.createElement('canvas');
      pageCanvas.width = canvas.width;
      pageCanvas.height = height;
      const pageContext = pageCanvas.getContext('2d', { alpha: false });
      if (!pageContext) throw new Error('Canvas is unavailable.');
      pageContext.fillStyle = '#ffffff';
      pageContext.fillRect(0, 0, pageCanvas.width, pageCanvas.height);
      pageContext.drawImage(canvas, 0, top, canvas.width, height, 0, 0, canvas.width, height);
      pages.push({ height, jpeg: new Uint8Array(await (await canvasBlob(pageCanvas, 'image/jpeg', 0.94)).arrayBuffer()) });
      top = bottom;
    }
    const parts = [ascii('%PDF-1.4\n')];
    const offsets = [0];
    let byteLength = parts[0].length;
    const addObject = (number, bodyParts) => {
      offsets[number] = byteLength;
      const objectParts = [ascii(`${number} 0 obj\n`), ...bodyParts, ascii('\nendobj\n')];
      parts.push(...objectParts);
      byteLength += objectParts.reduce((total, part) => total + part.length, 0);
    };
    addObject(1, [ascii('<< /Type /Catalog /Pages 2 0 R >>')]);
    const pageNumbers = pages.map((unused, index) => 3 + (index * 3));
    addObject(2, [ascii(`<< /Type /Pages /Kids [${pageNumbers.map((number) => `${number} 0 R`).join(' ')}] /Count ${pages.length} >>`)]);
    pages.forEach((page, index) => {
      const pageObject = pageNumbers[index];
      const imageObject = pageObject + 1;
      const contentObject = pageObject + 2;
      const drawWidth = canvas.width * scale;
      const drawHeight = page.height * scale;
      const drawX = (pageWidth - drawWidth) / 2;
      const drawY = pageHeight - margin - drawHeight;
      const content = `q\n${drawWidth.toFixed(2)} 0 0 ${drawHeight.toFixed(2)} ${drawX.toFixed(2)} ${drawY.toFixed(2)} cm\n/Im0 Do\nQ\n`;
      addObject(pageObject, [ascii(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /XObject << /Im0 ${imageObject} 0 R >> >> /Contents ${contentObject} 0 R >>`)]);
      addObject(imageObject, [ascii(`<< /Type /XObject /Subtype /Image /Width ${canvas.width} /Height ${page.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${page.jpeg.length} >>\nstream\n`), page.jpeg, ascii('\nendstream')]);
      addObject(contentObject, [ascii(`<< /Length ${ascii(content).length} >>\nstream\n${content}endstream`)]);
    });
    const xrefOffset = byteLength;
    const objectCount = 2 + (pages.length * 3);
    let xref = `xref\n0 ${objectCount + 1}\n0000000000 65535 f \n`;
    for (let number = 1; number <= objectCount; number++) xref += String(offsets[number]).padStart(10, '0') + ' 00000 n \n';
    xref += `trailer\n<< /Size ${objectCount + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF\n`;
    parts.push(ascii(xref));
    return new Blob([concatBytes(parts)], { type: 'application/pdf' });
  }

  function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 30000);
  }

  function initialize(root) {
    if (root.dataset.fbProofReady === '1') return;
    const modelElement = root.querySelector('[data-fb-proof-model]');
    const button = root.querySelector('[data-fb-proof-download]');
    const status = root.querySelector('[data-fb-proof-status]');
    if (!(modelElement instanceof HTMLScriptElement) || !(button instanceof HTMLButtonElement) || !(status instanceof HTMLElement)) return;
    let model;
    try {
      model = JSON.parse(modelElement.textContent || '');
    } catch (error) {
      return;
    }
    if (!model || !['png', 'pdf'].includes(model.format)) return;
    root.dataset.fbProofReady = '1';
    button.addEventListener('click', async () => {
      if (button.disabled) return;
      button.disabled = true;
      status.textContent = text(model.preparing_label, 200);
      try {
        const canvas = proofCanvas(model);
        const extension = model.format === 'pdf' ? 'pdf' : 'png';
        const blob = model.format === 'pdf' ? await pdfBlob(canvas) : await canvasBlob(canvas, 'image/png');
        const base = text(model.filename, 120).replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '') || 'submission-proof';
        saveBlob(blob, `${base}.${extension}`);
        status.textContent = '';
      } catch (error) {
        console.error('[form-builder] submission proof generation failed', error);
        status.textContent = text(model.failed_label, 300);
      } finally {
        button.disabled = false;
      }
    });
  }

  document.querySelectorAll('[data-fb-proof]').forEach(initialize);
}());
