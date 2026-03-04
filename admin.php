<?php
/**
 * admin.php — justinjanson.com Build & Publish Panel
 * =====================================================
 * Access at: http://justinjanson.cluster.home/admin.php
 *
 * When an action button is clicked, JS fetches ?stream=<action>
 * which pipes script output to the browser line by line via
 * PHP's flush(). No page reload, no waiting for the whole
 * process to finish before seeing anything.
 *
 * NOT uploaded to FTP host — publish.php excludes *.php.
 */

define('DB_PATH',      '/var/lib/sitedb/site.db');
define('SCRIPT_BUILD', '/scripts/build.php');
define('SCRIPT_PUB',   '/scripts/publish.php');

// ── Streaming mode ────────────────────────────────────────────
// ?stream=build|publish|deploy
// Outputs plain text, flushed line by line.
// JS on the main page reads this as a ReadableStream.
if (isset($_GET['stream'])) {
    $action = $_GET['stream'];

    // Kill all output buffering so flush() actually works
    while (ob_get_level()) ob_end_clean();
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', false);

    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no'); // prevent nginx buffering
    header('Cache-Control: no-cache');

    switch ($action) {
        case 'build':
            $cmd = 'php ' . escapeshellarg(SCRIPT_BUILD) . ' --verbose 2>&1';
            break;
        case 'publish':
            $cmd = 'php ' . escapeshellarg(SCRIPT_PUB) . ' --dry-run 2>&1';
            break;
        case 'deploy':
            $cmd = 'php ' . escapeshellarg(SCRIPT_PUB) . ' --build 2>&1';
            break;
        case 'wipe':
            // Wipe the SQLite database, then rebuild from scratch
            echo "=== Wipe DB + Rebuild ===\n";
            if (file_exists(DB_PATH)) {
                if (unlink(DB_PATH)) {
                    echo "✓ Database deleted: " . DB_PATH . "\n";
                } else {
                    echo "✗ Failed to delete database — check permissions.\n";
                    echo "\n__EXIT__:1\n";
                    exit(1);
                }
            } else {
                echo "  Database did not exist — nothing to delete.\n";
            }
            echo "\nStarting fresh build...\n\n";
            flush();

            // Now stream build.php output line by line
            $proc = popen('php ' . escapeshellarg(SCRIPT_BUILD) . ' --verbose 2>&1', 'r');
            if (!$proc) {
                echo "✗ Error: could not start build script.\n";
                echo "\n__EXIT__:1\n";
                exit(1);
            }
            while (!feof($proc)) {
                $line = fgets($proc);
                if ($line !== false) { echo $line; flush(); }
            }
            $exitCode = pclose($proc);
            echo "\n__EXIT__:" . $exitCode . "\n";
            exit(0);
        default:
            echo "Unknown action.\n";
            exit(1);
    }

    $proc = popen($cmd, 'r');
    if (!$proc) {
        echo "Error: could not start script.\n";
        exit(1);
    }

    while (!feof($proc)) {
        $line = fgets($proc);
        if ($line !== false) {
            echo $line;
            flush(); // push each line to browser immediately
        }
    }

    $exitCode = pclose($proc);

    // Sentinel — JS reads this to know the process finished and get exit code
    echo "\n__EXIT__:" . $exitCode . "\n";
    exit(0);
}

// ── Normal page render ────────────────────────────────────────

function get_stats(): array {
    if (!file_exists(DB_PATH)) {
        return ['posts' => 0, 'projects' => 0, 'last_post' => null, 'db_exists' => false];
    }
    try {
        $db    = new PDO('sqlite:' . DB_PATH);
        $posts = (int) $db->query("SELECT COUNT(*) FROM posts")->fetchColumn();
        $projs = (int) $db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
        $last  = $db->query("SELECT date FROM posts ORDER BY date DESC LIMIT 1")->fetchColumn();
        return ['posts' => $posts, 'projects' => $projs, 'last_post' => $last ?: null, 'db_exists' => true];
    } catch (Exception $e) {
        return ['posts' => 0, 'projects' => 0, 'last_post' => null, 'db_exists' => false];
    }
}

function count_md(string $dir): int {
    return count(glob($dir . '/*.md') ?: []);
}

$stats     = get_stats();
$blogMd    = count_md('/blog');
$projMd    = count_md('/projects');
$now       = date('D, M j Y  H:i T');
$outOfSync = ($blogMd > $stats['posts'] || $projMd > $stats['projects']);

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin — justinjanson.com</title>
  <style>
    /* ============================================================
       Admin Panel — dark terminal aesthetic
       Intentionally different from the public site's warm tones.
       ============================================================ */
    :root {
      --bg:         #0f1117;
      --bg-panel:   #161b27;
      --bg-card:    #1c2333;
      --bg-input:   #111722;
      --border:     #2a3347;
      --border-hi:  #334060;
      --text:       #c9d1e0;
      --text-muted: #5a6680;
      --text-dim:   #2d3a52;
      --accent:     #4a9eff;
      --accent-dim: #1d3a6b;
      --green:      #3ddc84;
      --green-dim:  #1a3d2b;
      --red:        #ff5a5a;
      --red-dim:    #3d1a1a;
      --amber:      #f5a623;
      --amber-dim:  #3d2a0a;
      --font-mono:  'JetBrains Mono', 'Fira Code', 'Cascadia Code', 'Consolas', monospace;
      --font-ui:    'SF Pro Display', 'Segoe UI', system-ui, sans-serif;
      --radius:     4px;
      --ease:       cubic-bezier(0.25, 0.1, 0.25, 1);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: var(--font-ui);
      font-size: 14px;
      line-height: 1.6;
      min-height: 100%;
      background-image: radial-gradient(circle, var(--text-dim) 1px, transparent 1px);
      background-size: 28px 28px;
      background-attachment: fixed;
    }
    .page { max-width: 920px; margin: 0 auto; padding: 40px 24px 80px; }

    /* Header */
    .header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 40px; padding-bottom: 24px; border-bottom: 1px solid var(--border); }
    .header__brand { display: flex; align-items: center; gap: 12px; }
    .header__cursor { width: 10px; height: 22px; background: var(--accent); border-radius: 1px; animation: blink 1.1s step-end infinite; flex-shrink: 0; }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }
    .header__title { font-family: var(--font-mono); font-size: 13px; font-weight: 400; color: var(--text-muted); letter-spacing: 0.05em; line-height: 1.4; }
    .header__title strong { display: block; font-size: 18px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; }
    .header__time { font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); text-align: right; line-height: 1.8; flex-shrink: 0; }

    /* Stats row */
    .stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 32px; }
    .stat { background: var(--bg-card); padding: 16px 20px; }
    .stat__label { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
    .stat__value { font-family: var(--font-mono); font-size: 22px; font-weight: 600; color: var(--accent); line-height: 1; }

    /* Action cards */
    .actions { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 32px; }
    .action-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; display: flex; flex-direction: column; gap: 12px; transition: border-color 160ms var(--ease); }
    .action-card:hover { border-color: var(--border-hi); }
    .action-card__title { font-size: 12px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text); }
    .action-card__desc { font-size: 12px; color: var(--text-muted); line-height: 1.6; flex: 1; }

    /* Buttons */
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 9px 16px; border: none; border-radius: var(--radius); font-family: var(--font-mono); font-size: 12px; font-weight: 600; letter-spacing: 0.04em; cursor: pointer; width: 100%; transition: background 160ms var(--ease), transform 80ms var(--ease); }
    .btn:active { transform: translateY(1px); }
    .btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
    .btn--blue  { background: var(--accent-dim); color: var(--accent);  border: 1px solid var(--accent-dim); }
    .btn--blue:hover:not(:disabled)  { background: #1f3f6b; border-color: var(--accent); }
    .btn--amber { background: var(--amber-dim); color: var(--amber); border: 1px solid var(--amber-dim); }
    .btn--amber:hover:not(:disabled) { background: #4d3510; border-color: var(--amber); }
    .btn--green { background: var(--green-dim); color: var(--green); border: 1px solid var(--green-dim); }
    .btn--green:hover:not(:disabled) { background: #234d34; border-color: var(--green); }
    .btn--red   { background: var(--red-dim);   color: var(--red);   border: 1px solid var(--red-dim); }
    .btn--red:hover:not(:disabled)   { background: #5a1a1a; border-color: var(--red); }

    /* Warning note */
    .note { display: flex; align-items: flex-start; gap: 10px; background: var(--amber-dim); border: 1px solid #6b4010; border-radius: var(--radius); padding: 12px 16px; margin-bottom: 32px; font-size: 12px; color: #c8882a; line-height: 1.5; }
    .note strong { color: var(--amber); }

    /* Config panels */
    .config-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 32px; }
    .config-panel { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .config-panel__header { padding: 10px 16px; background: var(--bg-panel); border-bottom: 1px solid var(--border); font-size: 10px; font-family: var(--font-mono); letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); }
    .config-panel__body { padding: 14px 16px; }
    .config-row { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 4px 0; border-bottom: 1px solid var(--border); font-size: 12px; }
    .config-row:last-child { border-bottom: none; }
    .config-row__key { font-family: var(--font-mono); color: var(--text-muted); flex-shrink: 0; }
    .config-row__val { font-family: var(--font-mono); color: var(--text); text-align: right; word-break: break-all; }
    .config-row__val--masked  { color: var(--text-dim); letter-spacing: 0.2em; }
    .config-row__val--missing { color: var(--red); }

    /* Console output */
    .console { background: var(--bg-input); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .console__header { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; background: var(--bg-card); border-bottom: 1px solid var(--border); }
    .console__title { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); display: flex; align-items: center; gap: 8px; }
    .console__dot { width: 7px; height: 7px; border-radius: 50%; background: var(--text-dim); transition: background 300ms; }
    .console__dot--running { background: var(--amber); animation: pulse 1s ease-in-out infinite; }
    .console__dot--success { background: var(--green); }
    .console__dot--error   { background: var(--red); }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
    .console__badge { font-family: var(--font-mono); font-size: 10px; padding: 2px 8px; border-radius: 2px; }
    .console__badge--running { background: var(--amber-dim); color: var(--amber); }
    .console__badge--ok      { background: var(--green-dim); color: var(--green); }
    .console__badge--err     { background: var(--red-dim);   color: var(--red); }
    .console__body { padding: 20px; min-height: 180px; max-height: 500px; overflow-y: auto; }

    /* Output pre — lines appended by JS */
    #output { font-family: var(--font-mono); font-size: 12px; line-height: 1.75; color: var(--text); white-space: pre-wrap; word-break: break-word; margin: 0; }
    .console__idle { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); }
    .console__idle::before { content: '$ _'; }

    /* Line colorization classes added by JS */
    .line-ok      { color: var(--green); }
    .line-err     { color: var(--red); }
    .line-section { color: var(--accent); }
    .line-muted   { color: var(--text-muted); }

    /* Responsive */
    @media (max-width: 960px) {
      .actions { grid-template-columns: repeat(2,1fr); }
    }
    @media (max-width: 720px) {
      .stats { grid-template-columns: repeat(2,1fr); }
      .actions { grid-template-columns: 1fr; }
      .config-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 480px) {
      .header { flex-direction: column; }
    }
  </style>
</head>
<body>
<div class="page">

  <header class="header">
    <div class="header__brand">
      <div class="header__cursor"></div>
      <div class="header__title">
        <strong>justinjanson.com</strong>
        admin / build &amp; publish panel
      </div>
    </div>
    <div class="header__time"><?= htmlspecialchars($now) ?><br>local access only</div>
  </header>

  <div class="stats">
    <div class="stat"><div class="stat__label">Blog posts (DB)</div><div class="stat__value"><?= $stats['posts'] ?></div></div>
    <div class="stat"><div class="stat__label">Projects (DB)</div><div class="stat__value"><?= $stats['projects'] ?></div></div>
    <div class="stat"><div class="stat__label">Blog .md files</div><div class="stat__value"><?= $blogMd ?></div></div>
    <div class="stat"><div class="stat__label">Project .md files</div><div class="stat__value"><?= $projMd ?></div></div>
  </div>

  <?php if ($outOfSync): ?>
  <div class="note">
    <strong>⚠</strong>
    <span>
      Source files and database are out of sync
      (<?= $blogMd ?> blog .md / <?= $stats['posts'] ?> in DB &nbsp;·&nbsp;
       <?= $projMd ?> project .md / <?= $stats['projects'] ?> in DB).
      <strong>Run Build</strong> to update.
    </span>
  </div>
  <?php endif; ?>

  <div class="actions">

    <div class="action-card">
      <div class="action-card__title">Build</div>
      <div class="action-card__desc">Scans /blog and /projects for .md files, updates SQLite, regenerates all HTML pages.</div>
      <button class="btn btn--blue" onclick="runAction('build', this)">⚙ Run Build</button>
    </div>

    <div class="action-card">
      <div class="action-card__title">Publish (dry run)</div>
      <div class="action-card__desc">Shows what lftp would upload without transferring any files. Safe to run any time.</div>
      <button class="btn btn--amber" onclick="runAction('publish', this)">◎ Dry Run</button>
    </div>

    <div class="action-card">
      <div class="action-card__title">Build + Deploy</div>
      <div class="action-card__desc">Builds the site then uploads everything to your FTP host. Writes to the live site.</div>
      <button class="btn btn--green" onclick="runAction('deploy', this)">↑ Deploy Live</button>
    </div>

    <div class="action-card">
      <div class="action-card__title">Wipe DB + Rebuild</div>
      <div class="action-card__desc">Deletes the SQLite database entirely, then runs a full build to recreate it from your .md files. Useful when the DB is corrupted or out of sync.</div>
      <button class="btn btn--red" onclick="confirmWipe(this)">⚠ Wipe &amp; Rebuild</button>
    </div>

  </div>

  <div class="config-grid">

    <div class="config-panel">
      <div class="config-panel__header">FTP configuration</div>
      <div class="config-panel__body">
        <?php
        foreach ([
          'FTP_HOST'   => getenv('FTP_HOST'),
          'FTP_USER'   => getenv('FTP_USER'),
          'FTP_PASS'   => getenv('FTP_PASS'),
          'FTP_PORT'   => getenv('FTP_PORT') ?: '21',
          'FTP_REMOTE' => getenv('FTP_REMOTE') ?: '/',
        ] as $key => $val):
          $masked  = ($key === 'FTP_PASS');
          $missing = !$val;
          $display = $missing ? 'NOT SET' : ($masked ? '••••••••••' : htmlspecialchars($val));
          $cls     = $missing ? 'config-row__val--missing' : ($masked ? 'config-row__val--masked' : 'config-row__val');
        ?>
        <div class="config-row">
          <span class="config-row__key"><?= $key ?></span>
          <span class="<?= $cls ?>"><?= $display ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="config-panel">
      <div class="config-panel__header">Site configuration</div>
      <div class="config-panel__body">
        <?php
        foreach ([
          'SITE_URL'    => getenv('SITE_URL')   ?: 'https://justinjanson.com',
          'SITE_TITLE'  => getenv('SITE_TITLE') ?: 'Justin Janson',
          'Database'    => file_exists(DB_PATH) ? 'OK (' . round(filesize(DB_PATH)/1024,1) . ' KB)' : 'not created yet',
          'Last post'   => $stats['last_post'] ?: 'none',
          'PHP version' => PHP_VERSION,
        ] as $key => $val):
          $display = htmlspecialchars($val ?: 'NOT SET');
          $cls     = $val ? 'config-row__val' : 'config-row__val--missing';
        ?>
        <div class="config-row">
          <span class="config-row__key"><?= htmlspecialchars($key) ?></span>
          <span class="<?= $cls ?>"><?= $display ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- Console — output streamed here in real time -->
  <div class="console">
    <div class="console__header">
      <div class="console__title">
        <div class="console__dot" id="consoleDot"></div>
        <span id="consoleLabel">output</span>
      </div>
      <span class="console__badge" id="consoleBadge" style="display:none"></span>
    </div>
    <div class="console__body" id="consoleBody">
      <div class="console__idle" id="consoleIdle"></div>
      <pre id="output" style="display:none"></pre>
    </div>
  </div>

</div><!-- /.page -->

<script>
  // ============================================================
  // Real-time streaming console
  //
  // Fetches admin.php?stream=<action> and reads the response as
  // a ReadableStream, appending each line to <pre id="output">
  // as it arrives. No page reload required.
  // ============================================================
  const dot   = document.getElementById('consoleDot');
  const label = document.getElementById('consoleLabel');
  const badge = document.getElementById('consoleBadge');
  const body  = document.getElementById('consoleBody');
  const out   = document.getElementById('output');
  const idle  = document.getElementById('consoleIdle');

  // Assign a color class based on line content
  function classifyLine(line) {
    if (/✓|complete/ui.test(line))          return 'line-ok';
    if (/✗|error:|failed|cannot/ui.test(line)) return 'line-err';
    if (/^\[[\d/]+\]|^===/u.test(line))     return 'line-section';
    if (/^\s{2}/.test(line))               return 'line-muted';
    return '';
  }

  function appendLine(line) {
    if (line.includes('__EXIT__:')) return; // hide sentinel from output
    const span = document.createElement('span');
    const cls  = classifyLine(line);
    if (cls) span.className = cls;
    span.textContent = line + '\n';
    out.appendChild(span);
    body.scrollTop = body.scrollHeight; // auto-scroll
  }

  let running = false;
  const buttons = () => document.querySelectorAll('.btn');

  function setRunning(actionLabel) {
    running = true;
    idle.style.display = 'none';
    out.style.display  = 'block';
    out.innerHTML      = '';
    dot.className      = 'console__dot console__dot--running';
    label.textContent  = actionLabel + ' — running…';
    badge.style.display = 'none';
    buttons().forEach(b => b.disabled = true);
  }

  function setDone(exitCode) {
    running = false;
    const ok = exitCode === 0;
    dot.className      = 'console__dot ' + (ok ? 'console__dot--success' : 'console__dot--error');
    label.textContent  = label.textContent.replace('running…', ok ? 'complete' : 'failed');
    badge.style.display = 'inline';
    badge.className    = 'console__badge ' + (ok ? 'console__badge--ok' : 'console__badge--err');
    badge.textContent  = 'exit ' + exitCode;
    buttons().forEach(b => b.disabled = false);
  }

  const actionLabels = { build: 'Build', publish: 'Publish (dry run)', deploy: 'Build + Deploy', wipe: 'Wipe DB + Rebuild' };

  function confirmWipe() {
    if (running) return;
    if (!confirm('This will permanently delete the SQLite database and rebuild it from your .md files.\n\nAny data not sourced from .md files will be lost.\n\nContinue?')) return;
    runAction('wipe');
  }

  async function runAction(action) {
    if (running) return;
    setRunning(actionLabels[action] || action);

    try {
      const resp   = await fetch('?stream=' + encodeURIComponent(action));
      const reader = resp.body.getReader();
      const dec    = new TextDecoder();
      let buffer   = '';
      let exitCode = 0;

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        // Buffer chunks and split on newlines
        buffer += dec.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop(); // incomplete last line stays in buffer

        for (const line of lines) {
          // Extract exit code from sentinel before it's discarded
          const m = line.match(/__EXIT__:(\d+)/);
          if (m) exitCode = parseInt(m[1]);
          appendLine(line);
        }
      }

      // Flush remaining buffer content
      if (buffer) {
        const m = buffer.match(/__EXIT__:(\d+)/);
        if (m) exitCode = parseInt(m[1]);
        appendLine(buffer);
      }

      setDone(exitCode);

    } catch (err) {
      appendLine('Fetch error: ' + err.message);
      setDone(1);
    }
  }
</script>

</body>
</html>
