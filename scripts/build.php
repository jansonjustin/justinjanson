#!/usr/bin/env php
<?php
/**
 * ============================================================
 * build.php — Site Builder
 * ============================================================
 * Scans /blog/*.md and /projects/*.md, parses frontmatter,
 * stores records in SQLite, then generates static HTML pages
 * into /var/www/html/.
 *
 * Usage (from inside the container):
 *   php /scripts/build.php
 *   php /scripts/build.php --verbose
 *
 * What it generates:
 *   /var/www/html/blog.html          — blog index
 *   /var/www/html/blog/[slug].html   — individual post pages
 *   /var/www/html/projects.html      — projects index
 *
 * Frontmatter format (YAML-style key: value at top of .md file):
 *
 *   ---
 *   title: My Post Title
 *   date: 2025-06-01
 *   description: One sentence shown in the listing.
 *   tags: docker, homelab, linux
 *   ---
 *
 *   Post body in Markdown follows...
 *
 * Project frontmatter also supports:
 *   status: active | wip | archive    (default: active)
 *   link: https://github.com/...      (optional external link)
 *
 * Video in markdown (blog posts and projects):
 *   ![My caption](/assets/img/demo.mp4)
 *   Supported formats: mp4, webm, mov, ogg
 *
 * ============================================================
 */

// ── Configuration ────────────────────────────────────────────
define('BLOG_DIR',     '/blog');                  // mounted NFS: blog .md files
define('PROJECTS_DIR', '/projects');              // mounted NFS: project .md files
define('HTML_DIR',     '/var/www/html');          // Apache document root
define('DB_PATH',      '/var/lib/sitedb/site.db'); // SQLite database location
define('SITE_URL',     getenv('SITE_URL') ?: 'https://justinjanson.com');
define('SITE_TITLE',   getenv('SITE_TITLE') ?: 'Justin Janson');

// Parsedown library — installed via composer in the Dockerfile
$parsedownPath = '/usr/local/lib/parsedown/Parsedown.php';
if (!file_exists($parsedownPath)) {
    die("Error: Parsedown not found at $parsedownPath\n"
      . "Run: composer require erusev/parsedown --working-dir=/usr/local/lib/parsedown\n");
}
require $parsedownPath;

// ── CLI flags ─────────────────────────────────────────────────
$verbose = in_array('--verbose', $argv ?? [], true);

function log_msg(string $msg): void {
    echo $msg . "\n";
}

function log_verbose(string $msg): void {
    global $verbose;
    if ($verbose) echo "  » $msg\n";
}

// ── Setup ─────────────────────────────────────────────────────
log_msg("=== justinjanson.com site builder ===");

// Create the blog subdirectory in HTML output if it doesn't exist
@mkdir(HTML_DIR . '/blog', 0755, true);

// Create SQLite DB directory
@mkdir(dirname(DB_PATH), 0755, true);

// Open (or create) the SQLite database
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Cannot open SQLite DB: " . $e->getMessage() . "\n");
}

// Create tables if they don't exist yet
$db->exec("
    CREATE TABLE IF NOT EXISTS posts (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        slug        TEXT UNIQUE NOT NULL,
        title       TEXT NOT NULL,
        date        TEXT NOT NULL,
        description TEXT,
        tags        TEXT,
        body_md     TEXT,
        body_html   TEXT,
        updated_at  TEXT
    );

    CREATE TABLE IF NOT EXISTS projects (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        slug        TEXT UNIQUE NOT NULL,
        title       TEXT NOT NULL,
        date        TEXT NOT NULL,
        description TEXT,
        tags        TEXT,
        status      TEXT DEFAULT 'active',
        link        TEXT,
        body_md     TEXT,
        body_html   TEXT,
        updated_at  TEXT
    );
");

// Safe migration — add columns that may not exist in older DBs
foreach (['github TEXT', 'blog_link TEXT'] as $col) {
    try { $db->exec("ALTER TABLE projects ADD COLUMN $col"); } catch (Exception $e) {}
}

$parsedown = new Parsedown();
$parsedown->setSafeMode(false); // Allow raw HTML in markdown if needed

// ── Helper: parse frontmatter from a .md file ─────────────────
/**
 * Returns ['meta' => [...], 'body' => '...']
 * Frontmatter block is delimited by --- on its own line.
 * Each line inside is "key: value".
 */
function parse_markdown_file(string $path): array {
    $content = file_get_contents($path);
    $meta    = [];
    $body    = $content;

    // Check for --- frontmatter block
    if (str_starts_with(trim($content), '---')) {
        $parts = preg_split('/^---\s*$/m', $content, 3);
        if (count($parts) >= 3) {
            // $parts[1] is the frontmatter block, $parts[2] is the body
            foreach (explode("\n", trim($parts[1])) as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $meta[trim(strtolower($key))] = trim($value);
                }
            }
            $body = trim($parts[2]);
        }
    }

    return ['meta' => $meta, 'body' => $body];
}

/**
 * Turn a filename into a URL-safe slug.
 * "My Blog Post.md" → "my-blog-post"
 */
function slugify(string $filename): string {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = strtolower($name);
    $name = preg_replace('/[^a-z0-9]+/', '-', $name);
    return trim($name, '-');
}

// ── Shared HTML snippets ──────────────────────────────────────
/**
 * Returns the <head> block used by all generated pages.
 * $title     — page <title>
 * $desc      — meta description
 * $canonical — canonical URL path fragment (e.g. "blog/my-post")
 * $depth     — how many directories deep (affects asset path prefix)
 */
function html_head(string $title, string $desc = '', string $canonical = '', int $depth = 0): string {
    $prefix   = str_repeat('../', $depth);
    $siteTitle = SITE_TITLE;
    $siteUrl   = SITE_URL;
    $fullTitle = $title === $siteTitle ? $title : "$title — $siteTitle";
    $canonUrl  = $canonical ? "$siteUrl/$canonical.html" : $siteUrl;
    $desc      = htmlspecialchars($desc ?: $fullTitle, ENT_QUOTES);

    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>{$fullTitle}</title>
      <meta name="description" content="{$desc}">
      <meta property="og:title" content="{$fullTitle}">
      <meta property="og:description" content="{$desc}">
      <meta property="og:type" content="website">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,600;1,300;1,600&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
      <link rel="stylesheet" href="{$prefix}assets/style.css">
      <link rel="canonical" href="{$canonUrl}">
    </head>
    HTML;
}

/**
 * Returns the sticky navigation bar HTML.
 * $activeLink — which nav item to mark active: 'blog', 'projects', 'about'
 * $depth      — directory depth for relative hrefs
 */
function html_nav(string $activeLink = '', int $depth = 0): string {
    $prefix = str_repeat('../', $depth);
    $a = fn($page) => $activeLink === $page ? ' class="active"' : '';

    return <<<HTML
      <nav class="site-nav" aria-label="Main navigation">
        <div class="site-nav__inner">
          <a href="{$prefix}index.html" class="site-nav__brand">{SITE_TITLE}</a>
          <ul class="site-nav__links">
            <li><a href="{$prefix}blog.html"{$a('blog')}>Blog</a></li>
            <li><a href="{$prefix}projects.html"{$a('projects')}>Projects</a></li>
            <li><a href="{$prefix}about.html"{$a('about')}>About</a></li>
          </ul>
        </div>
      </nav>
    HTML;
}

/**
 * Returns the site footer HTML.
 */
function html_footer(): string {
    $year = date('Y');
    $siteTitle = SITE_TITLE;
    return <<<HTML
      <footer class="site-footer">
        <div class="site-footer__inner">
          <p class="site-footer__copy">&copy; {$year} {$siteTitle}</p>
          <ul class="site-footer__links">
            <li><a href="https://github.com/justinjanson" target="_blank" rel="noopener">GitHub</a></li>
          </ul>
        </div>
      </footer>
    HTML;
}

/**
 * Post-processes HTML to inline any <img src="*.svg"> tags.
 *
 * Parsedown turns ![alt](file.svg) into a standard <img> tag, which is
 * unreliable for hand-drawn SVGs (no guaranteed dimensions, MIME type
 * quirks on shared hosts). Inlining the SVG content directly is more
 * robust and lets the browser size it correctly from the viewBox.
 *
 * Only fires for absolute paths under /assets/ that exist on disk.
 * Falls back to the original <img> tag if the file can't be read.
 */
function inline_svgs(string $html): string {
    return preg_replace_callback(
        '/<img\b([^>]*)\bsrc=["\']([^"\']+\.svg)["\']([^>]*)>/i',
        function (array $m) {
            [, $before, $src, $after] = $m;

            // Only handle absolute paths we can resolve on disk
            if (!str_starts_with($src, '/')) {
                return $m[0];
            }

            $fsPath = HTML_DIR . $src;
            if (!is_file($fsPath)) {
                return $m[0]; // file not found — leave img tag as-is
            }

            $svg = file_get_contents($fsPath);
            if ($svg === false) {
                return $m[0];
            }

            // Strip XML declaration — invalid inside HTML5
            $svg = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $svg);

            // Pull alt text from the surrounding img attrs for accessibility
            $allAttrs = $before . $after;
            $alt = '';
            if (preg_match('/\balt=["\']([^"\']*)["\']/', $allAttrs, $altMatch)) {
                $alt = htmlspecialchars($altMatch[1], ENT_QUOTES);
            }

            // Inject aria label on the <svg> element if we have alt text
            if ($alt !== '') {
                $svg = preg_replace(
                    '/<svg\b/i',
                    "<svg aria-label=\"$alt\" role=\"img\"",
                    $svg,
                    1
                );
            }

            // Wrap so CSS can target it (.post-body .svg-inline svg { max-width:100% })
            return '<div class="svg-inline">' . trim($svg) . '</div>';
        },
        $html
    );
}

/**
 * Converts <img src="*.mp4|webm|mov|ogg"> tags (produced by Parsedown
 * from ![alt](video.mp4) syntax) into styled <video> elements.
 *
 * Usage in markdown:
 *   ![My caption](/assets/img/demo.mp4)
 *
 * The alt text becomes an optional italic caption rendered below the player.
 * Falls back gracefully in browsers that don't support the format.
 *
 * NOTE: Run inline_svgs() before this so SVG files are already handled
 * and won't be accidentally matched by this regex.
 */
function inline_videos(string $html): string {
    return preg_replace_callback(
        '/<img\b([^>]*)\bsrc=["\']([^"\']+\.(?:mp4|webm|mov|ogg))["\']([^>]*)>/i',
        function (array $m) {
            [, $before, $src, $after] = $m;

            $allAttrs = $before . $after;
            $alt = '';
            if (preg_match('/\balt=["\']([^"\']*)["\']/', $allAttrs, $altMatch)) {
                $alt = htmlspecialchars($altMatch[1], ENT_QUOTES);
            }

            $caption = $alt ? "<p class=\"video-caption\">{$alt}</p>" : '';
            $safeSrc  = htmlspecialchars($src, ENT_QUOTES);

            return '<div class="video-wrap">'
                 . "<video controls playsinline preload=\"metadata\" src=\"{$safeSrc}\">"
                 . 'Your browser does not support video.'
                 . '</video>'
                 . $caption
                 . '</div>';
        },
        $html
    );
}


// ════════════════════════════════════════════════════════════
//  BLOG POSTS
// ════════════════════════════════════════════════════════════
log_msg("\n[1/4] Scanning blog posts...");

$blogFiles  = glob(BLOG_DIR . '/*.md') ?: [];
$postsBuilt = 0;

foreach ($blogFiles as $filepath) {
    $filename = basename($filepath);
    $slug     = slugify($filename);
    $parsed   = parse_markdown_file($filepath);
    $meta     = $parsed['meta'];
    $bodyMd   = $parsed['body'];
    $bodyHtml = $parsedown->text($bodyMd);
    $bodyHtml = inline_videos(inline_svgs($bodyHtml));

    // Fallback values for missing frontmatter
    $title       = $meta['title']       ?? ucwords(str_replace('-', ' ', $slug));
    $date        = $meta['date']        ?? date('Y-m-d', filemtime($filepath));
    $description = $meta['description'] ?? '';
    $tags        = $meta['tags']        ?? '';
    $updatedAt   = date('Y-m-d H:i:s', filemtime($filepath));

    log_verbose("$slug ($date)");

    // Upsert into SQLite
    $stmt = $db->prepare("
        INSERT INTO posts (slug, title, date, description, tags, body_md, body_html, updated_at)
        VALUES (:slug, :title, :date, :desc, :tags, :md, :html, :updated)
        ON CONFLICT(slug) DO UPDATE SET
            title       = excluded.title,
            date        = excluded.date,
            description = excluded.description,
            tags        = excluded.tags,
            body_md     = excluded.body_md,
            body_html   = excluded.body_html,
            updated_at  = excluded.updated_at
    ");
    $stmt->execute([
        ':slug'    => $slug,
        ':title'   => $title,
        ':date'    => $date,
        ':desc'    => $description,
        ':tags'    => $tags,
        ':md'      => $bodyMd,
        ':html'    => $bodyHtml,
        ':updated' => $updatedAt,
    ]);

    // ── Generate individual blog post HTML ────────────────────
    // Build tag pill HTML
    $tagHtml = '';
    if ($tags) {
        foreach (array_map('trim', explode(',', $tags)) as $tag) {
            if ($tag) $tagHtml .= "<span class=\"tag\">" . htmlspecialchars($tag) . "</span> ";
        }
    }

    // Format the date for display
    $displayDate = date('F j, Y', strtotime($date));

    $postHtml = html_head($title, $description, "blog/$slug", depth: 1)
    . "<body>\n<div class=\"site-wrapper\">\n"
    . html_nav('blog', depth: 1)
    . <<<HTML
      <main>
        <div class="container">

          <!-- Back navigation -->
          <a href="../blog.html" class="back-link">← All posts</a>

          <!-- Post header -->
          <header class="post-header">
            <h1 class="post-header__title">{$title}</h1>
            <div class="post-header__meta">
              <time class="meta" datetime="{$date}">{$displayDate}</time>
              {$tagHtml}
            </div>
          </header>

          <!-- Rendered Markdown body -->
          <article class="post-body">
            {$bodyHtml}
          </article>

          <!-- Divider + back link at bottom -->
          <hr>
          <a href="../blog.html" class="back-link">← All posts</a>

        </div>
      </main>
    HTML
    . html_footer()
    . "\n</div><!-- /.site-wrapper -->\n</body>\n</html>\n";

    // Replace the {SITE_TITLE} placeholder in nav (since we can't call constants in heredocs directly)
    $postHtml = str_replace('{SITE_TITLE}', SITE_TITLE, $postHtml);

    file_put_contents(HTML_DIR . "/blog/$slug.html", $postHtml);
    $postsBuilt++;
}

// Remove any blog post HTML files whose source .md no longer exists
$existingSlugs = array_map(fn($f) => slugify(basename($f)), $blogFiles);
foreach (glob(HTML_DIR . '/blog/*.html') as $htmlFile) {
    $slug = pathinfo($htmlFile, PATHINFO_FILENAME);
    if (!in_array($slug, $existingSlugs)) {
        unlink($htmlFile);
        log_verbose("Removed stale: $slug.html");
    }
}

log_msg("  Built $postsBuilt blog post(s).");


// ── Generate blog index ───────────────────────────────────────
log_msg("\n[2/4] Generating blog index...");

// Fetch all posts sorted newest first
$posts = $db->query("SELECT * FROM posts ORDER BY date DESC")->fetchAll(PDO::FETCH_ASSOC);

$listHtml = '';
if (empty($posts)) {
    $listHtml = '<p class="empty-state">No posts yet. Drop a .md file into /blog to get started.</p>';
} else {
    $listHtml = '<ul class="post-list">';
    foreach ($posts as $post) {
        $displayDate = date('M j, Y', strtotime($post['date']));
        $title       = htmlspecialchars($post['title']);
        $slug        = $post['slug'];
        $excerpt     = htmlspecialchars($post['description'] ?? '');
        $listHtml   .= <<<HTML
            <li class="post-entry fade-in">
              <a href="blog/{$slug}.html" class="post-entry__link">
                <div class="post-entry__left">
                  <div class="post-entry__title">{$title}</div>
                  <div class="post-entry__excerpt">{$excerpt}</div>
                </div>
                <time class="post-entry__date" datetime="{$post['date']}">{$displayDate}</time>
              </a>
            </li>
        HTML;
    }
    $listHtml .= '</ul>';
}

$blogIndexHtml = html_head('Blog', 'Notes, write-ups, and the occasional opinion.', 'blog')
. "<body>\n<div class=\"site-wrapper\">\n"
. html_nav('blog')
. <<<HTML
  <main>
    <div class="container">
      <header class="page-header">
        <span class="eyebrow">Writing</span>
        <h1 class="page-header__title">Blog</h1>
        <p class="page-header__subtitle">Notes, write-ups, and the occasional opinion.</p>
      </header>
      <div class="section--sm">
        {$listHtml}
      </div>
    </div>
  </main>
HTML
. html_footer()
. "\n</div><!-- /.site-wrapper -->\n</body>\n</html>\n";

$blogIndexHtml = str_replace('{SITE_TITLE}', SITE_TITLE, $blogIndexHtml);
file_put_contents(HTML_DIR . '/blog.html', $blogIndexHtml);
log_msg("  blog.html written.");


// ════════════════════════════════════════════════════════════
//  PROJECTS
// ════════════════════════════════════════════════════════════
log_msg("\n[3/4] Scanning projects...");

$projectFiles  = glob(PROJECTS_DIR . '/*.md') ?: [];
$projectsBuilt = 0;

foreach ($projectFiles as $filepath) {
    $filename = basename($filepath);
    $slug     = slugify($filename);
    $parsed   = parse_markdown_file($filepath);
    $meta     = $parsed['meta'];
    $bodyMd   = $parsed['body'];
    $bodyHtml = $parsedown->text($bodyMd);
    $bodyHtml = inline_videos(inline_svgs($bodyHtml));

    $title       = $meta['title']       ?? ucwords(str_replace('-', ' ', $slug));
    $date        = $meta['date']        ?? date('Y-m-d', filemtime($filepath));
    $description = $meta['description'] ?? '';
    $tags        = $meta['tags']        ?? '';
    $status      = $meta['status']      ?? 'active';  // active | wip | archive
    $link        = $meta['link']        ?? '';
    $github      = $meta['github']      ?? '';
    $blogLink    = $meta['blog']        ?? '';
    $updatedAt   = date('Y-m-d H:i:s', filemtime($filepath));

    log_verbose("$slug [$status]");

    $stmt = $db->prepare("
        INSERT INTO projects (slug, title, date, description, tags, status, link, github, blog_link, body_md, body_html, updated_at)
        VALUES (:slug, :title, :date, :desc, :tags, :status, :link, :github, :blog_link, :md, :html, :updated)
        ON CONFLICT(slug) DO UPDATE SET
            title       = excluded.title,
            date        = excluded.date,
            description = excluded.description,
            tags        = excluded.tags,
            status      = excluded.status,
            link        = excluded.link,
            github      = excluded.github,
            blog_link   = excluded.blog_link,
            body_md     = excluded.body_md,
            body_html   = excluded.body_html,
            updated_at  = excluded.updated_at
    ");
    $stmt->execute([
        ':slug'      => $slug,
        ':title'     => $title,
        ':date'      => $date,
        ':desc'      => $description,
        ':tags'      => $tags,
        ':status'    => $status,
        ':link'      => $link,
        ':github'    => $github,
        ':blog_link' => $blogLink,
        ':md'        => $bodyMd,
        ':html'      => $bodyHtml,
        ':updated'   => $updatedAt,
    ]);

    $projectsBuilt++;

    // ── Generate individual project detail page ───────────────
    $tagHtml = '';
    if ($tags) {
        foreach (array_map('trim', explode(',', $tags)) as $tag) {
            if ($tag) $tagHtml .= "<span class=\"tag\">" . htmlspecialchars($tag) . "</span> ";
        }
    }

    $displayDate  = date('F j, Y', strtotime($date));
    $statusLabel  = match ($status) {
        'active'  => 'Active',
        'wip'     => 'In Progress',
        'archive' => 'Archived',
        default   => ucfirst($status),
    };
    $statusClass = "status--" . $status;

    $externalLinksHtml = '';
    $detailLinks = [];
    if ($link)     $detailLinks[] = "<a href=\"" . htmlspecialchars($link)     . "\" class=\"project-card__link\" target=\"_blank\" rel=\"noopener\">Link →</a>";
    if ($github)   $detailLinks[] = "<a href=\"" . htmlspecialchars($github)   . "\" class=\"project-card__link\" target=\"_blank\" rel=\"noopener\">GitHub →</a>";
    if ($blogLink) $detailLinks[] = "<a href=\"" . htmlspecialchars($blogLink) . "\" class=\"project-card__link\">Blog →</a>";
    if ($detailLinks) {
        $externalLinksHtml = '<div class="project-detail__links">' . implode('', $detailLinks) . '</div>';
    }

    @mkdir(HTML_DIR . '/projects', 0755, true);

    $projHtml = html_head($title, $description, "projects/$slug", depth: 1)
    . "<body>\n<div class=\"site-wrapper\">\n"
    . html_nav('projects', depth: 1)
    . <<<HTML
      <main>
        <div class="container">

          <a href="../projects.html" class="back-link">← All projects</a>

          <header class="post-header">
            <h1 class="post-header__title">{$title}</h1>
            <div class="post-header__meta">
              <time class="meta" datetime="{$date}">{$displayDate}</time>
              <span class="status {$statusClass}">{$statusLabel}</span>
              {$tagHtml}
            </div>
          </header>

          <article class="post-body">
            {$bodyHtml}
          </article>

          {$externalLinksHtml}

          <hr>
          <a href="../projects.html" class="back-link">← All projects</a>

        </div>
      </main>
    HTML
    . html_footer()
    . "\n</div><!-- /.site-wrapper -->\n</body>\n</html>\n";

    $projHtml = str_replace('{SITE_TITLE}', SITE_TITLE, $projHtml);
    file_put_contents(HTML_DIR . "/projects/$slug.html", $projHtml);
}

log_msg("  Scanned $projectsBuilt project(s).");


// ── Generate projects index ───────────────────────────────────
log_msg("\n[4/4] Generating projects index...");

// Active first, then wip, then archive; within each, newest first
$projects = $db->query("
    SELECT * FROM projects
    ORDER BY
        CASE status
            WHEN 'active'  THEN 1
            WHEN 'wip'     THEN 2
            WHEN 'archive' THEN 3
            ELSE 4
        END ASC,
        date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$gridHtml = '';
if (empty($projects)) {
    $gridHtml = '<p class="empty-state">No projects yet. Drop a .md file into /projects to get started.</p>';
} else {
    $gridHtml = '<div class="project-grid">';
    foreach ($projects as $proj) {
        $title       = htmlspecialchars($proj['title']);
        $desc        = htmlspecialchars($proj['description'] ?? '');
        $status      = $proj['status'] ?? 'active';
        $statusLabel = match ($status) {
            'active'  => 'Active',
            'wip'     => 'In Progress',
            'archive' => 'Archived',
            default   => ucfirst($status),
        };
        $statusClass = "status--" . $status;

        // Build tag pills
        $tagHtml = '';
        if ($proj['tags']) {
            foreach (array_map('trim', explode(',', $proj['tags'])) as $tag) {
                if ($tag) $tagHtml .= "<span class=\"tag\">" . htmlspecialchars($tag) . "</span>";
            }
        }

        // External link buttons — only render those provided
        $cardLinks = '';
        if ($proj['link'])      $cardLinks .= "<a href=\"" . htmlspecialchars($proj['link'])      . "\" class=\"project-card__link\" target=\"_blank\" rel=\"noopener\">Link →</a>";
        if ($proj['github'])    $cardLinks .= "<a href=\"" . htmlspecialchars($proj['github'])    . "\" class=\"project-card__link\" target=\"_blank\" rel=\"noopener\">GitHub →</a>";
        if ($proj['blog_link']) $cardLinks .= "<a href=\"" . htmlspecialchars($proj['blog_link']) . "\" class=\"project-card__link\">Blog →</a>";
        $cardLinksHtml = $cardLinks ? '<div class="project-card__links">' . $cardLinks . '</div>' : '';

        $detailUrl = "projects/{$proj['slug']}.html";

        $gridHtml .= <<<HTML
          <article class="project-card fade-in">
            <h2 class="project-card__title">
              <a href="{$detailUrl}" class="project-card__title-link">{$title}</a>
            </h2>
            <p class="project-card__desc">{$desc}</p>
            <div class="project-card__footer">
              <div class="project-card__tags">{$tagHtml}</div>
              <span class="status {$statusClass}">{$statusLabel}</span>
            </div>
            {$cardLinksHtml}
          </article>
        HTML;
    }
    $gridHtml .= '</div><!-- /.project-grid -->';
}

$projectsIndexHtml = html_head('Projects', 'Things I\'ve built and things I\'m still building.', 'projects')
. "<body>\n<div class=\"site-wrapper\">\n"
. html_nav('projects')
. <<<HTML
  <main>
    <div class="container--wide">
      <header class="page-header">
        <span class="eyebrow">Work</span>
        <h1 class="page-header__title">Projects</h1>
        <p class="page-header__subtitle">Things I've built and things I'm still building.</p>
      </header>
      <div class="section--sm">
        {$gridHtml}
      </div>
    </div>
  </main>
HTML
. html_footer()
. "\n</div><!-- /.site-wrapper -->\n</body>\n</html>\n";

$projectsIndexHtml = str_replace('{SITE_TITLE}', SITE_TITLE, $projectsIndexHtml);
file_put_contents(HTML_DIR . '/projects.html', $projectsIndexHtml);
log_msg("  projects.html written.");

log_msg("\n✓ Build complete.\n");
