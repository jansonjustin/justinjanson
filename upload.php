<?php
/**
 * upload.php — Asset Upload
 * =========================
 * Two modes:
 *
 * 1. JSON POST (from drawing tool):
 *    Content-Type: application/json
 *    { "filename": "sketch.svg", "svgData": "<svg>...</svg>" }
 *
 * 2. Web UI (browser / Android):
 *    Drag & drop or file picker → uploads to /var/www/html/assets/img/
 *
 * 3. Multipart POST (from web UI):
 *    Content-Type: multipart/form-data, field: "file"
 *
 * Optional token auth: set DRAWING_TOKEN in justinjanson.env eventually
 * Access at: https://justinjanson.cluster.home/upload.php
 */

define('IMG_DIR',    '/var/www/html/assets/img');
define('SITE_TITLE', getenv('SITE_TITLE') ?: 'Justin Janson');

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson      = str_contains($contentType, 'application/json');
$isMultipart = str_contains($contentType, 'multipart/form-data');
$isPost      = $_SERVER['REQUEST_METHOD'] === 'POST';
$isOptions   = $_SERVER['REQUEST_METHOD'] === 'OPTIONS';

if ($isOptions) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    http_response_code(204);
    exit;
}

function check_token(): void {
    $token = getenv('DRAWING_TOKEN');
    if (!$token) return;
    $auth   = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $bearer = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
    if (!hash_equals($token, $bearer)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function ensure_img_dir(): void {
    if (!is_dir(IMG_DIR)) mkdir(IMG_DIR, 0755, true);
}

function safe_filename(string $name): ?string {
    $name = basename($name);
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.[a-zA-Z0-9]+$/', $name)) return null;
    return $name;
}

// ── JSON SVG endpoint ─────────────────────────────────────────
if ($isPost && $isJson) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    check_token();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['filename'], $data['svgData'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing filename or svgData']);
        exit;
    }

    $filename = safe_filename($data['filename']);
    if (!$filename || !str_ends_with(strtolower($filename), '.svg')) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid filename — must be alphanumeric with .svg extension']);
        exit;
    }

    $svg = $data['svgData'];
    if (!str_contains($svg, '<svg')) {
        http_response_code(400);
        echo json_encode(['error' => 'svgData does not appear to be valid SVG']);
        exit;
    }

    ensure_img_dir();
    $dest  = IMG_DIR . '/' . $filename;
    $bytes = file_put_contents($dest, $svg);

    if ($bytes === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Write failed — check permissions on ' . IMG_DIR]);
        exit;
    }

    echo json_encode(['ok' => true, 'filename' => $filename, 'path' => '/assets/img/' . $filename, 'bytes' => $bytes]);
    exit;
}

// ── Multipart file upload from web UI ─────────────────────────
if ($isPost && $isMultipart) {
    header('Content-Type: application/json');
    check_token();

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Upload error code: ' . ($_FILES['file']['error'] ?? -1)]);
        exit;
    }

    $filename = safe_filename($_FILES['file']['name']);
    if (!$filename) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid filename']);
        exit;
    }

    ensure_img_dir();
    $dest = IMG_DIR . '/' . $filename;

    if (file_exists($dest)) {
        $ext      = pathinfo($filename, PATHINFO_EXTENSION);
        $base     = pathinfo($filename, PATHINFO_FILENAME);
        $filename = $base . '-' . date('YmdHis') . '.' . $ext;
        $dest     = IMG_DIR . '/' . $filename;
    }

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to move uploaded file']);
        exit;
    }

    echo json_encode(['ok' => true, 'filename' => $filename, 'path' => '/assets/img/' . $filename, 'size' => filesize($dest)]);
    exit;
}

// ── Web UI ────────────────────────────────────────────────────
$existingFiles = [];
if (is_dir(IMG_DIR)) {
    foreach (glob(IMG_DIR . '/*') as $f) {
        if (is_file($f)) {
            $existingFiles[] = ['name' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f)];
        }
    }
    usort($existingFiles, fn($a, $b) => $b['mtime'] - $a['mtime']);
}

function fmt_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>Upload — <?= htmlspecialchars(SITE_TITLE) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,600;1,300&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg:         #f7f6f4;
      --bg-subtle:  #efede9;
      --bg-hover:   #e8e6e2;
      --text:       #1a1917;
      --text-muted: #7d7b74;
      --border:     #d4d1cb;
      --accent:     #3b4f63;
      --accent-h:   #2c3d4f;
      --green:      #2d6a4f;
      --green-bg:   #d8f3dc;
      --red:        #9b2335;
      --red-bg:     #fde8eb;
      --font-d:     'Cormorant Garamond', Georgia, serif;
      --font-b:     'Jost', sans-serif;
      --radius:     3px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: var(--font-b);
      font-size: 15px;
      line-height: 1.6;
      min-height: 100vh;
      padding-bottom: 80px;
    }

    .nav {
      background: var(--bg);
      border-bottom: 1px solid var(--border);
      padding: 0 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 56px;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .nav__brand { font-family: var(--font-d); font-size: 18px; font-weight: 600; color: var(--text); text-decoration: none; }
    .nav__label { font-size: 11px; font-weight: 500; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-muted); }

    .page { max-width: 680px; margin: 0 auto; padding: 48px 24px 0; }

    .page-header { margin-bottom: 40px; }
    .eyebrow { display: block; font-size: 11px; font-weight: 500; letter-spacing: 0.14em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; }
    .page-header h1 { font-family: var(--font-d); font-size: clamp(2rem, 6vw, 3rem); font-weight: 300; line-height: 1.1; }
    .page-header p { margin-top: 12px; color: var(--text-muted); font-size: 14px; }
    .page-header code { font-size: 13px; background: var(--bg-subtle); padding: 1px 5px; border-radius: 2px; }

    /* Toast */
    .toast { padding: 12px 16px; border-radius: var(--radius); font-size: 13px; margin-bottom: 24px; display: none; align-items: flex-start; gap: 10px; line-height: 1.5; }
    .toast--ok  { background: var(--green-bg); color: var(--green); }
    .toast--err { background: var(--red-bg);   color: var(--red); }
    .toast.visible { display: flex; }
    .toast code { font-family: monospace; background: rgba(0,0,0,0.08); padding: 1px 5px; border-radius: 2px; font-size: 12px; word-break: break-all; }

    /* Progress */
    .progress-wrap { margin-bottom: 24px; display: none; }
    .progress-wrap.visible { display: block; }
    .progress-label { font-size: 12px; color: var(--text-muted); margin-bottom: 6px; display: flex; justify-content: space-between; }
    .progress-track { height: 3px; background: var(--border); border-radius: 2px; overflow: hidden; }
    .progress-fill  { height: 100%; background: var(--accent); width: 0%; transition: width 150ms ease; }

    /* Drop zone */
    .dropzone {
      border: 2px dashed var(--border);
      border-radius: var(--radius);
      background: var(--bg-subtle);
      padding: 48px 24px;
      text-align: center;
      cursor: pointer;
      transition: border-color 200ms, background 200ms;
      position: relative;
      margin-bottom: 16px;
      -webkit-tap-highlight-color: transparent;
      user-select: none;
    }
    .dropzone:hover, .dropzone.over { border-color: var(--accent); background: var(--bg-hover); }
    .dropzone.over { border-style: solid; }
    .dropzone__icon { font-size: 40px; display: block; margin-bottom: 12px; opacity: 0.45; }
    .dropzone__title { font-family: var(--font-d); font-size: 1.5rem; font-weight: 300; margin-bottom: 6px; }
    .dropzone__sub { font-size: 13px; color: var(--text-muted); }

    /* Hidden input covers entire dropzone for click-to-browse */
    #fileInput { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }

    /* Browse button — separate, large touch target for Android */
    .btn {
      display: flex; align-items: center; justify-content: center; gap: 8px;
      width: 100%; min-height: 52px;
      background: var(--accent); color: #fff;
      font-family: var(--font-b); font-size: 14px; font-weight: 500; letter-spacing: 0.04em;
      border: none; border-radius: var(--radius); cursor: pointer;
      margin-bottom: 40px;
      transition: background 160ms;
      -webkit-tap-highlight-color: transparent;
    }
    .btn:hover:not(:disabled) { background: var(--accent-h); }
    .btn:active { transform: translateY(1px); }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

    /* Divider */
    .divider { display: flex; align-items: center; gap: 12px; color: var(--text-muted); font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 24px; }
    .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

    /* File list */
    .files-header { font-size: 11px; font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 16px; }
    .file-list { list-style: none; }
    .file-item { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); }
    .file-item:last-child { border-bottom: none; }
    .file-thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 2px; border: 1px solid var(--border); background: var(--bg-subtle); flex-shrink: 0; }
    .file-thumb-icon { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 22px; border: 1px solid var(--border); border-radius: 2px; background: var(--bg-subtle); flex-shrink: 0; }
    .file-info { flex: 1; min-width: 0; }
    .file-name { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .file-meta { font-size: 11px; color: var(--text-muted); }
    .copy-btn {
      font-size: 11px; font-weight: 500; color: var(--accent);
      background: none; border: 1px solid var(--border); border-radius: var(--radius);
      padding: 5px 12px; min-height: 38px; cursor: pointer; white-space: nowrap; flex-shrink: 0;
      font-family: var(--font-b); transition: background 120ms;
      -webkit-tap-highlight-color: transparent;
    }
    .copy-btn:hover { background: var(--bg-hover); }
    .copy-btn.copied { color: var(--green); border-color: var(--green); }

    .empty { color: var(--text-muted); font-size: 13px; font-style: italic; padding: 16px 0; }

    @media (max-width: 480px) {
      .dropzone { padding: 36px 16px; }
      .page { padding: 28px 16px 0; }
    }
  </style>
</head>
<body>

<nav class="nav">
  <a href="/index.html" class="nav__brand"><?= htmlspecialchars(SITE_TITLE) ?></a>
  <span class="nav__label">Upload</span>
</nav>

<div class="page">

  <header class="page-header">
    <span class="eyebrow">Assets</span>
    <h1>Upload images</h1>
    <p>Files are saved to <code>/assets/img/</code> and usable in markdown immediately.</p>
  </header>

  <div class="toast toast--ok" id="toastOk"><span>✓</span><span id="toastOkMsg"></span></div>
  <div class="toast toast--err" id="toastErr"><span>✕</span><span id="toastErrMsg"></span></div>

  <div class="progress-wrap" id="progressWrap">
    <div class="progress-label"><span id="progressFile">Uploading…</span><span id="progressPct">0%</span></div>
    <div class="progress-track"><div class="progress-fill" id="progressFill"></div></div>
  </div>

  <!-- Drop zone — click anywhere on it to browse on desktop -->
  <div class="dropzone" id="dropzone">
    <input type="file" id="fileInput" multiple accept="image/*,.svg">
    <span class="dropzone__icon">⬆</span>
    <div class="dropzone__title">Drop files here</div>
    <div class="dropzone__sub">SVG · PNG · JPG · GIF · WEBP</div>
  </div>

  <!-- Explicit browse button — clearer tap target on Android -->
  <button class="btn" id="browseBtn">Choose files from device</button>

  <div class="divider">uploaded files</div>

  <div class="files-header" id="filesHeader">
    <?= count($existingFiles) ?> file<?= count($existingFiles) !== 1 ? 's' : '' ?> in /assets/img/
  </div>

  <ul class="file-list" id="fileList">
    <?php if (empty($existingFiles)): ?>
    <li class="empty" id="emptyState">No files yet.</li>
    <?php else: ?>
    <?php foreach ($existingFiles as $f):
      $isImg = preg_match('/\.(svg|png|jpg|jpeg|gif|webp)$/i', $f['name']);
      $path  = '/assets/img/' . htmlspecialchars($f['name']);
      $md    = '![' . htmlspecialchars(pathinfo($f['name'], PATHINFO_FILENAME)) . '](' . $path . ')';
    ?>
    <li class="file-item">
      <?php if ($isImg): ?>
        <img src="<?= $path ?>" class="file-thumb" alt="" loading="lazy">
      <?php else: ?>
        <div class="file-thumb-icon">📄</div>
      <?php endif; ?>
      <div class="file-info">
        <div class="file-name"><?= htmlspecialchars($f['name']) ?></div>
        <div class="file-meta"><?= fmt_size($f['size']) ?> &middot; <?= date('M j, Y', $f['mtime']) ?></div>
      </div>
      <button class="copy-btn" onclick="copyMd(this, <?= json_encode($md) ?>)">Copy md</button>
    </li>
    <?php endforeach; ?>
    <?php endif; ?>
  </ul>

</div>

<script>
  const dropzone     = document.getElementById('dropzone');
  const fileInput    = document.getElementById('fileInput');
  const browseBtn    = document.getElementById('browseBtn');
  const progressWrap = document.getElementById('progressWrap');
  const progressFill = document.getElementById('progressFill');
  const progressPct  = document.getElementById('progressPct');
  const progressFile = document.getElementById('progressFile');
  const toastOk      = document.getElementById('toastOk');
  const toastErr     = document.getElementById('toastErr');
  const fileList     = document.getElementById('fileList');
  const filesHeader  = document.getElementById('filesHeader');

  let fileCount = <?= count($existingFiles) ?>;

  // Browse button triggers its own separate file input to avoid double-trigger
  const browseInput = document.createElement('input');
  browseInput.type     = 'file';
  browseInput.multiple = true;
  browseInput.accept   = 'image/*,.svg';
  browseInput.style.display = 'none';
  document.body.appendChild(browseInput);

  browseBtn.addEventListener('click', () => browseInput.click());
  browseInput.addEventListener('change', () => {
    if (browseInput.files.length) uploadFiles(browseInput.files);
    browseInput.value = '';
  });

  // Dropzone drag events
  ['dragenter','dragover'].forEach(ev => dropzone.addEventListener(ev, e => {
    e.preventDefault(); dropzone.classList.add('over');
  }));
  ['dragleave','drop'].forEach(ev => dropzone.addEventListener(ev, e => {
    e.preventDefault(); dropzone.classList.remove('over');
  }));
  dropzone.addEventListener('drop', e => {
    if (e.dataTransfer?.files?.length) uploadFiles(e.dataTransfer.files);
  });

  // Dropzone file input (click-to-browse on desktop)
  fileInput.addEventListener('change', () => {
    if (fileInput.files.length) uploadFiles(fileInput.files);
    fileInput.value = '';
  });

  function toast(type, html) {
    toastOk.classList.remove('visible');
    toastErr.classList.remove('visible');
    if (type === 'ok') { document.getElementById('toastOkMsg').innerHTML = html; toastOk.classList.add('visible'); }
    else               { document.getElementById('toastErrMsg').innerHTML = html; toastErr.classList.add('visible'); }
    setTimeout(() => { toastOk.classList.remove('visible'); toastErr.classList.remove('visible'); }, 7000);
  }

  async function uploadFiles(files) {
    browseBtn.disabled = true;
    for (const file of files) await uploadOne(file);
    browseBtn.disabled = false;
  }

  function uploadOne(file) {
    return new Promise(resolve => {
      const fd = new FormData();
      fd.append('file', file);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'upload.php');

      progressWrap.classList.add('visible');
      progressFile.textContent = file.name;
      progressFill.style.width = '0%';
      progressPct.textContent  = '0%';

      xhr.upload.addEventListener('progress', e => {
        if (e.lengthComputable) {
          const p = Math.round(e.loaded / e.total * 100);
          progressFill.style.width = p + '%';
          progressPct.textContent  = p + '%';
        }
      });

      xhr.addEventListener('load', () => {
        progressWrap.classList.remove('visible');
        try {
          const res = JSON.parse(xhr.responseText);
          if (res.ok) {
            const md = `![${res.filename.replace(/\.[^.]+$/, '')}](${res.path})`;
            toast('ok', `Saved <code>${res.path}</code> — <code>${md}</code>`);
            prependFile(res.filename, res.size || res.bytes || 0, res.path);
          } else {
            toast('err', res.error || 'Upload failed');
          }
        } catch { toast('err', 'Unexpected server response'); }
        resolve();
      });

      xhr.addEventListener('error', () => {
        progressWrap.classList.remove('visible');
        toast('err', 'Network error');
        resolve();
      });

      xhr.send(fd);
    });
  }

  function prependFile(filename, size, path) {
    document.getElementById('emptyState')?.remove();
    fileCount++;
    filesHeader.textContent = fileCount + ' file' + (fileCount !== 1 ? 's' : '') + ' in /assets/img/';

    const isImg = /\.(svg|png|jpg|jpeg|gif|webp)$/i.test(filename);
    const md    = `![${filename.replace(/\.[^.]+$/, '')}](${path})`;
    const sz    = size < 1024 ? size + ' B' : size < 1048576 ? (size/1024).toFixed(1) + ' KB' : (size/1048576).toFixed(1) + ' MB';
    const now   = new Date().toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});

    const li = document.createElement('li');
    li.className = 'file-item';
    li.innerHTML = (isImg ? `<img src="${path}" class="file-thumb" alt="" loading="lazy">` : `<div class="file-thumb-icon">📄</div>`)
      + `<div class="file-info"><div class="file-name">${filename}</div><div class="file-meta">${sz} &middot; ${now}</div></div>`
      + `<button class="copy-btn" onclick="copyMd(this, ${JSON.stringify(md)})">Copy md</button>`;
    fileList.prepend(li);
  }

  function copyMd(btn, md) {
    navigator.clipboard.writeText(md).then(() => {
      btn.textContent = 'Copied!';
      btn.classList.add('copied');
      setTimeout(() => { btn.textContent = 'Copy md'; btn.classList.remove('copied'); }, 1500);
    });
  }
</script>

</body>
</html>
