<?php /* draw.php — served as PHP so it won't be FTP-uploaded to the public host */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>SVG Draw</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg: #e8e6e1;
    --toolbar: #1c1c1c;
    --toolbar-border: #333;
    --btn-bg: #2a2a2a;
    --btn-hover: #3a3a3a;
    --btn-active: #111;
    --accent: #f0c040;
    --text: #f0ede8;
    --text-dim: #888;
    --stroke: #1c1c1c;
    --disabled: #444;
    --disabled-text: #555;
    --modal-bg: #1c1c1c;
    --input-bg: #111;
  }

  html, body {
    width: 100%; height: 100%;
    overflow: hidden;
    background: var(--bg);
    font-family: 'JetBrains Mono', monospace;
    touch-action: none;
  }

  /* ── Toolbar ── */
  #toolbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 52px;
    background: var(--toolbar);
    border-bottom: 1px solid var(--toolbar-border);
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 14px;
    z-index: 100;
    user-select: none;
  }

  #toolbar .title {
    font-size: 12px;
    font-weight: 600;
    color: var(--accent);
    letter-spacing: 0.12em;
    text-transform: uppercase;
    flex: 1;
  }

  .btn {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 14px;
    border-radius: 5px;
    border: 1px solid #3a3a3a;
    background: var(--btn-bg);
    color: var(--text);
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s, opacity 0.12s;
    white-space: nowrap;
  }
  .btn:hover:not(:disabled) { background: var(--btn-hover); border-color: #555; }
  .btn:active:not(:disabled) { background: var(--btn-active); }
  .btn.hidden {
    display: none;
  }

  .btn-clear {
    border-color: #5a2a2a;
    color: #cc7070;
  }
  .btn-clear:hover:not(:disabled) { background: #3a1a1a; border-color: #cc7070; }

  /* stroke size picker */
  .size-wrap {
    display: flex; align-items: center; gap: 6px;
  }
  .size-label { font-size: 11px; color: var(--text-dim); }
  .size-dots {
    display: flex; gap: 5px; align-items: center;
  }
  .size-dot {
    border-radius: 50%;
    background: var(--text);
    cursor: pointer;
    transition: transform 0.1s, box-shadow 0.1s;
    border: 2px solid transparent;
  }
  .size-dot:hover { transform: scale(1.2); }
  .size-dot.active { border-color: var(--accent); }

  /* ── Canvas area ── */
  #canvas-wrap {
    position: fixed;
    top: 52px; left: 0; right: 0; bottom: 0;
    background: var(--bg);
    cursor: crosshair;
    /* subtle paper texture via repeating gradient */
    background-image:
      repeating-linear-gradient(0deg, rgba(0,0,0,0.025) 0px, rgba(0,0,0,0.025) 1px, transparent 1px, transparent 40px),
      repeating-linear-gradient(90deg, rgba(0,0,0,0.025) 0px, rgba(0,0,0,0.025) 1px, transparent 1px, transparent 40px);
  }

  #drawing-svg {
    width: 100%; height: 100%;
    display: block;
    /* background: transparent — default for SVG */
  }

  /* ── Modal overlay ── */
  #modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.65);
    z-index: 200;
    align-items: center;
    justify-content: center;
  }
  #modal-overlay.open { display: flex; }

  #modal {
    background: var(--modal-bg);
    border: 1px solid #3a3a3a;
    border-radius: 10px;
    padding: 28px 28px 22px;
    width: min(380px, 90vw);
    box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    animation: popIn 0.15s ease;
  }
  @keyframes popIn {
    from { transform: scale(0.93); opacity: 0; }
    to   { transform: scale(1);    opacity: 1; }
  }

  #modal h2 {
    font-size: 13px;
    font-weight: 600;
    color: var(--accent);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    margin-bottom: 18px;
  }

  .input-row {
    display: flex;
    align-items: center;
    border: 1px solid #3a3a3a;
    border-radius: 5px;
    overflow: hidden;
    background: var(--input-bg);
    margin-bottom: 18px;
  }
  #filename-input {
    flex: 1;
    background: transparent;
    border: none;
    outline: none;
    color: var(--text);
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px;
    padding: 10px 12px;
  }
  .ext-tag {
    padding: 10px 12px 10px 0;
    font-size: 13px;
    color: var(--text-dim);
    pointer-events: none;
  }

  .modal-btns {
    display: flex; gap: 8px; justify-content: flex-end;
  }
  .btn-cancel { border-color: #3a3a3a; color: var(--text-dim); }
  .btn-confirm { border-color: var(--accent); color: var(--accent); }
  .btn-confirm:hover:not(:disabled) { background: rgba(240,192,64,0.1); }

  /* ── Stroke counter ── */
  #counter {
    font-size: 11px;
    color: var(--text-dim);
    letter-spacing: 0.05em;
    margin-left: 4px;
  }
</style>
</head>
<body>

<!-- ─────────────── CONFIGURATION ─────────────── -->
<script>
const CONFIG = {
  // URL of server endpoint that accepts POST with { filename, svgData }.
  // Set to "" (empty string) to disable the server save button entirely.
  serverSaveURL: "/upload.php",
  enableClientDownload: false,
  exportPadding: 24,
};
</script>
<!-- ──────────────────────────────────────────── -->

<div id="toolbar">
  <span class="title">✏ SVG Draw</span>

  <div class="size-wrap">
    <span class="size-label">size</span>
    <div class="size-dots">
      <div class="size-dot active" data-size="2"  style="width:8px;height:8px"  title="Fine"></div>
      <div class="size-dot"        data-size="5"  style="width:12px;height:12px" title="Medium"></div>
      <div class="size-dot"        data-size="10" style="width:17px;height:17px" title="Thick"></div>
      <div class="size-dot"        data-size="20" style="width:23px;height:23px" title="Bold"></div>
    </div>
  </div>

  <span id="counter">0 strokes</span>

  <button class="btn btn-clear" id="btn-clear">✕ Clear</button>
  <button class="btn" id="btn-download" title="Download SVG to your device">⬇ Download</button>
  <button class="btn" id="btn-save"     title="Save SVG to server">💾 Save</button>
</div>

<div id="canvas-wrap">
  <svg id="drawing-svg" xmlns="http://www.w3.org/2000/svg"></svg>
</div>

<!-- Save-to-server modal -->
<div id="modal-overlay">
  <div id="modal">
    <h2>💾 Save to Server</h2>
    <div class="input-row">
      <input id="filename-input" type="text" placeholder="my-drawing" spellcheck="false" autocomplete="off" />
      <span class="ext-tag">.svg</span>
    </div>
    <div class="modal-btns">
      <button class="btn btn-cancel" id="btn-modal-cancel">Cancel</button>
      <button class="btn btn-confirm" id="btn-modal-confirm">Save</button>
    </div>
  </div>
</div>

<script>
(function () {
  /* ── refs ── */
  const svg       = document.getElementById('drawing-svg');
  const btnDL     = document.getElementById('btn-download');
  const btnSave   = document.getElementById('btn-save');
  const btnClear  = document.getElementById('btn-clear');
  const overlay   = document.getElementById('modal-overlay');
  const filenameInput = document.getElementById('filename-input');
  const btnConfirm= document.getElementById('btn-modal-confirm');
  const btnCancel = document.getElementById('btn-modal-cancel');
  const counter   = document.getElementById('counter');

  /* ── state ── */
  let strokeWidth = 2;
  let drawing = false;
  let currentPath = null;
  let points = [];
  let strokeCount = 0;

  /* ── apply config ── */
  if (!CONFIG.enableClientDownload) btnDL.classList.add('hidden');
  if (!CONFIG.serverSaveURL)        btnSave.classList.add('hidden');

  /* ── stroke size picker ── */
  document.querySelectorAll('.size-dot').forEach(dot => {
    dot.addEventListener('click', () => {
      document.querySelectorAll('.size-dot').forEach(d => d.classList.remove('active'));
      dot.classList.add('active');
      strokeWidth = parseInt(dot.dataset.size);
    });
  });

  /* ── helpers ── */
  function getPos(e) {
    const rect = svg.getBoundingClientRect();
    const src = e.touches ? e.touches[0] : e;
    return { x: src.clientX - rect.left, y: src.clientY - rect.top };
  }

  function pointsToD(pts) {
    if (pts.length < 2) return `M${pts[0].x} ${pts[0].y}`;
    let d = `M${pts[0].x} ${pts[0].y}`;
    for (let i = 1; i < pts.length; i++) {
      const prev = pts[i - 1], curr = pts[i];
      const mx = (prev.x + curr.x) / 2, my = (prev.y + curr.y) / 2;
      d += ` Q${prev.x} ${prev.y} ${mx} ${my}`;
    }
    const last = pts[pts.length - 1];
    d += ` L${last.x} ${last.y}`;
    return d;
  }

  function updateCounter() {
    counter.textContent = `${strokeCount} stroke${strokeCount !== 1 ? 's' : ''}`;
  }

  /* ── drawing events ── */
  function startDraw(e) {
    e.preventDefault();
    drawing = true;
    points = [getPos(e)];
    currentPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    currentPath.setAttribute('fill', 'none');
    currentPath.setAttribute('stroke', '#1c1c1c');
    currentPath.setAttribute('stroke-width', strokeWidth);
    currentPath.setAttribute('stroke-linecap', 'round');
    currentPath.setAttribute('stroke-linejoin', 'round');
    currentPath.setAttribute('d', `M${points[0].x} ${points[0].y}`);
    svg.appendChild(currentPath);
  }

  function moveDraw(e) {
    if (!drawing) return;
    e.preventDefault();
    points.push(getPos(e));
    currentPath.setAttribute('d', pointsToD(points));
  }

  function endDraw(e) {
    if (!drawing) return;
    drawing = false;
    if (points.length === 1) {
      // just a tap — draw a small circle dot
      const { x, y } = points[0];
      currentPath.setAttribute('d', `M${x-0.01} ${y} L${x+0.01} ${y}`);
    }
    strokeCount++;
    updateCounter();
    currentPath = null;
    points = [];
  }

  svg.addEventListener('mousedown',  startDraw);
  svg.addEventListener('mousemove',  moveDraw);
  svg.addEventListener('mouseup',    endDraw);
  svg.addEventListener('mouseleave', endDraw);
  svg.addEventListener('touchstart', startDraw, { passive: false });
  svg.addEventListener('touchmove',  moveDraw,  { passive: false });
  svg.addEventListener('touchend',   endDraw);

  /* ── SVG export builder (crops to content + padding) ── */
  function buildSVG() {
    const children = Array.from(svg.children);

    if (children.length === 0) {
      return '<' + '?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>';
    }

    const pad = CONFIG.exportPadding;

    // union bounding box across every path
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    children.forEach(el => {
      try {
        const b = el.getBBox();
        if (b.width === 0 && b.height === 0) return;
        minX = Math.min(minX, b.x);
        minY = Math.min(minY, b.y);
        maxX = Math.max(maxX, b.x + b.width);
        maxY = Math.max(maxY, b.y + b.height);
      } catch (_) {}
    });

    // expand by half stroke-width so thick lines aren't clipped at edges
    const maxSW = Math.max(...children.map(el => parseFloat(el.getAttribute('stroke-width') || 0)));
    const halfSW = maxSW / 2;
    minX -= halfSW; minY -= halfSW;
    maxX += halfSW; maxY += halfSW;

    const vx = minX - pad;
    const vy = minY - pad;
    const vw = (maxX - minX) + pad * 2;
    const vh = (maxY - minY) + pad * 2;

    return '<' + `?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${Math.round(vw)}" height="${Math.round(vh)}" viewBox="${vx.toFixed(2)} ${vy.toFixed(2)} ${vw.toFixed(2)} ${vh.toFixed(2)}">
${svg.innerHTML}
</svg>`;
  }

  /* ── clear ── */
  btnClear.addEventListener('click', () => {
    if (!strokeCount && svg.children.length === 0) return;
    if (!confirm('Clear the canvas?')) return;
    svg.innerHTML = '';
    strokeCount = 0;
    updateCounter();
  });

  /* ── client download ── */
  btnDL.addEventListener('click', () => {
    const blob = new Blob([buildSVG()], { type: 'image/svg+xml' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'drawing.svg';
    a.click();
    URL.revokeObjectURL(url);
  });

  /* ── server save modal ── */
  btnSave.addEventListener('click', () => {
    filenameInput.value = '';
    overlay.classList.add('open');
    setTimeout(() => filenameInput.focus(), 120);
  });

  function closeModal() { overlay.classList.remove('open'); }

  btnCancel.addEventListener('click', closeModal);
  overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });

  filenameInput.addEventListener('keydown', e => {
    if (e.key === 'Enter')  btnConfirm.click();
    if (e.key === 'Escape') closeModal();
  });

  btnConfirm.addEventListener('click', async () => {
    let name = filenameInput.value.trim() || 'drawing';
    // strip .svg if user typed it; we'll add it
    name = name.replace(/\.svg$/i, '');
    const filename = name + '.svg';
    const svgData = buildSVG();

    btnConfirm.disabled = true;
    btnConfirm.textContent = 'Saving…';

    try {
      const res = await fetch(CONFIG.serverSaveURL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ filename, svgData }),
      });
      if (!res.ok) throw new Error(`Server responded ${res.status}`);
      closeModal();
      // brief success flash
      btnSave.textContent = '✓ Saved!';
      setTimeout(() => { btnSave.innerHTML = '💾 Save'; }, 2200);
    } catch (err) {
      alert(`Save failed: ${err.message}`);
    } finally {
      btnConfirm.disabled = false;
      btnConfirm.textContent = 'Save';
    }
  });

  /* ── init ── */
  updateCounter();
})();
</script>
</body>
</html>
