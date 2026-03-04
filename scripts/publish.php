#!/usr/bin/env php
<?php
/**
 * ============================================================
 * publish.php — FTP Deploy Script
 * ============================================================
 * Uploads the contents of /var/www/html to third-party
 * FTP host using lftp (installed in the Dockerfile).
 *
 * Usage (from inside the container):
 *   php /scripts/publish.php
 *   php /scripts/publish.php --dry-run   (prints lftp commands, no upload)
 *   php /scripts/publish.php --build     (runs build.php first, then publishes)
 *
 * Configuration via environment variables (set in .env / compose):
 *   FTP_HOST     — hostname or IP of FTP server
 *   FTP_USER     — FTP username
 *   FTP_PASS     — FTP password
 *   FTP_PORT     — FTP port (default: 21)
 *   FTP_REMOTE   — remote directory to upload to (default: /)
 *   SITE_URL     — public site URL (used in output messages)
 *
 * ============================================================
 */

// ── Read config from environment ──────────────────────────────
$ftpHost   = getenv('FTP_HOST')   ?: '';
$ftpUser   = getenv('FTP_USER')   ?: '';
$ftpPass   = getenv('FTP_PASS')   ?: '';
$ftpPort   = getenv('FTP_PORT')   ?: '21';
$ftpRemote = getenv('FTP_REMOTE') ?: '/';
$siteUrl   = getenv('SITE_URL')   ?: 'https://justinjanson.com';

$localDir  = '/var/www/html';

// ── CLI flags ─────────────────────────────────────────────────
$dryRun    = in_array('--dry-run', $argv ?? [], true);
$buildFirst = in_array('--build', $argv ?? [], true);

function log_msg(string $msg): void {
    echo $msg . "\n";
}

// ── Validate config ───────────────────────────────────────────
if (!$ftpHost || !$ftpUser || !$ftpPass) {
    log_msg("Error: FTP_HOST, FTP_USER, and FTP_PASS must be set.");
    log_msg("Check your .env file and docker-compose.");
    exit(1);
}

log_msg("=== justinjanson.com deploy ===");

// ── Optional: run build first ─────────────────────────────────
if ($buildFirst) {
    log_msg("\n[0] Running build first...");
    $buildOutput = [];
    $buildExit   = 0;
    exec('php /scripts/build.php 2>&1', $buildOutput, $buildExit);
    foreach ($buildOutput as $line) log_msg("  $line");
    if ($buildExit !== 0) {
        log_msg("Build failed. Aborting deploy.");
        exit(1);
    }
}

log_msg("\n[1] Preparing FTP upload...");
log_msg("  Host:   $ftpHost:$ftpPort");
log_msg("  Remote: $ftpRemote");
log_msg("  Local:  $localDir");

if ($dryRun) {
    log_msg("\n  [DRY RUN] No files will be uploaded.");
}

// ── Build the lftp command ────────────────────────────────────
//
// Credentials are passed directly to lftp's open command using
// --user and --password flags — avoids all shell variable
// expansion issues with the env var approach.
//
// Uses a temporary lftp settings file written to /tmp so the
// password never appears in the process list (ps aux).
//
$lftpPassFile = tempnam('/tmp', 'lftp_');
file_put_contents($lftpPassFile, $ftpPass);
register_shutdown_function(fn() => @unlink($lftpPassFile));

$lftpScript = implode('; ', [
    // Ignore the PASV IP the server advertises and use the same host we
    // connected to instead. Required for container-to-container connections
    // on the swarm overlay — pureftpd advertises the external VIP which
    // causes hairpin NAT failures from inside the swarm network.
    "set ftp:ignore-pasv-address yes",
    "set ftp:ssl-allow no",


    "open --user " . escapeshellarg($ftpUser)
    . " --password " . escapeshellarg($ftpPass)
    . " ftp://$ftpHost:$ftpPort",

    // Mirror local html dir to remote path
    // --verbose    : show each transferred file
    // --delete     : delete remote files that no longer exist locally
    // --no-perms   : don't try to set permissions (most shared hosts reject this)
    // --parallel=4 : concurrent transfers
    // --exclude-glob takes shell glob patterns (not regex)
    "mirror --reverse --delete --no-perms --verbose --parallel=4"
    . " --exclude-glob .DS_Store"
    . " --exclude-glob *.php"
    . " $localDir $ftpRemote",

    "bye",
]);

// Build the shell command — password is embedded in the lftp script
// via the open --password flag above, not via environment variable.
$cmd = "lftp -e " . escapeshellarg($lftpScript) . " 2>&1";

if ($dryRun) {
    $safecmd = "lftp -e 'open --user " . escapeshellarg($ftpUser)
             . " --password ••••••••"
             . " ftp://$ftpHost:$ftpPort; mirror ...' 2>&1";
    log_msg("\n  Would run:\n  $safecmd\n");
    log_msg("[DRY RUN] Done. Remove --dry-run to actually deploy.");
    exit(0);
}

log_msg("\n[2] Uploading...\n");

// Execute lftp and stream output line by line
$proc = popen($cmd, 'r');
if (!$proc) {
    log_msg("Error: Could not start lftp. Is it installed?");
    exit(1);
}

while (!feof($proc)) {
    $line = fgets($proc);
    if ($line !== false) {
        echo "  " . $line;
    }
}

$exitCode = pclose($proc);

// ── Report result ─────────────────────────────────────────────
echo "\n";
if ($exitCode === 0) {
    log_msg("✓ Deploy complete → $siteUrl");
} else {
    log_msg("✗ Deploy failed (exit code $exitCode).");
    log_msg("  Check FTP credentials and network access from inside the container.");
    exit(1);
}
