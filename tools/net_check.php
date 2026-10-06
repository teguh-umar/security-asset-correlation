<?php

/**
 * Security-Asset Correlation plugin — endoflife.date connectivity check (VM).
 *
 * Usage (from the GLPI host):
 *   php plugins/sac/tools/net_check.php [product]      # default product: ubuntu
 *
 * Answers one question: can this GLPI reach endoflife.date the same way the
 * EOL cron does, and if not, at which step does it fail? Read-only — it writes
 * nothing to the database and nothing to the cache.
 *
 * The steps follow the real call path, so the first FAIL is the cause:
 *   1. PHP has curl + openssl
 *   2. GLPI proxy settings (the password is never printed)
 *   3. GLPI's URL allowlist (GLPI_SERVERSIDE_URL_ALLOWLIST) accepts the URL
 *   4. DNS resolves endoflife.date
 *   5. direct TLS connect on 443 (tells "no egress" from "proxy required")
 *   6. the real fetch through Toolbox::getURLContent() — what the cron uses
 *   7. the answer parses and carries the fields the scan reads
 * then it reports how much of the local mirror is stale, i.e. what is at stake.
 */

// CLI-only. Never reachable over HTTP even if the plugins dir is web-served.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Same bootstrap as tools/selfcheck.php — inc/includes.php loads nothing in GLPI 11.
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel())->boot();

/** @var array $CFG_GLPI */
global $CFG_GLPI, $DB;

const SAC_NC_HOST = 'endoflife.date';

$product = preg_replace('/[^a-z0-9._-]/i', '', (string) ($argv[1] ?? 'ubuntu'));
$product = $product !== '' ? $product : 'ubuntu';
$url     = 'https://' . SAC_NC_HOST . '/api/' . rawurlencode($product) . '.json';

$fail    = 0;
$remedy  = [];

function sac_nc(string $state, string $step, string $detail = ''): void
{
    global $fail;
    if ($state === 'FAIL') { $fail++; }
    printf("%-5s %-34s %s\n", $state, $step, $detail);
}

echo "SAC endoflife.date connectivity check\n";
echo "URL: $url\n\n";

// --- 1. PHP extensions -----------------------------------------------------
$curl = extension_loaded('curl');
sac_nc($curl ? 'PASS' : 'FAIL', 'php curl extension', $curl ? curl_version()['version'] ?? '' : 'missing');
if (!$curl) { $remedy[] = 'Install/enable the PHP curl extension; GLPI cannot fetch anything without it.'; }
sac_nc(extension_loaded('openssl') ? 'PASS' : 'FAIL', 'php openssl extension');

// --- 2. GLPI proxy configuration -------------------------------------------
$proxyHost = (string) ($CFG_GLPI['proxy_name'] ?? '');
$proxyPort = (string) ($CFG_GLPI['proxy_port'] ?? '');
$proxyUser = (string) ($CFG_GLPI['proxy_user'] ?? '');
if ($proxyHost !== '') {
    // The password is deliberately not read or printed.
    sac_nc('INFO', 'GLPI proxy', "$proxyHost:$proxyPort" . ($proxyUser !== '' ? ' (authenticated)' : ' (no credentials)'));
} else {
    sac_nc('INFO', 'GLPI proxy', 'not configured — GLPI will connect directly');
}

// --- 3. URL allowlist ------------------------------------------------------
// getURLContent() runs this first; a locally narrowed allowlist blocks the
// call before any packet leaves the host.
$safe = \Toolbox::isUrlSafe($url);
sac_nc($safe ? 'PASS' : 'FAIL', 'GLPI URL allowlist', $safe ? 'URL accepted' : 'blocked by GLPI_SERVERSIDE_URL_ALLOWLIST');
if (!$safe) {
    $remedy[] = 'GLPI_SERVERSIDE_URL_ALLOWLIST (config/local_define.php) rejects the URL. Add an entry that matches https://' . SAC_NC_HOST . '/.';
}

// --- 4. DNS ----------------------------------------------------------------
$ips = @gethostbynamel(SAC_NC_HOST);
if ($ips) {
    sac_nc('PASS', 'DNS resolution', implode(', ', $ips));
} else {
    sac_nc('FAIL', 'DNS resolution', 'cannot resolve ' . SAC_NC_HOST);
    $remedy[] = 'The host cannot resolve ' . SAC_NC_HOST . '. Fix DNS, or configure a proxy in Setup > General > Network and let the proxy resolve it.';
}

// --- 5. Direct TLS connect (no proxy) --------------------------------------
// Separates "no egress at all" from "egress only via the proxy".
$err = $errno = null;
$sock = @stream_socket_client(
    'ssl://' . SAC_NC_HOST . ':443',
    $errno,
    $err,
    10,
    STREAM_CLIENT_CONNECT,
    stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
);
if ($sock) {
    sac_nc('PASS', 'direct TLS 443', 'connected and certificate verified');
    fclose($sock);
} else {
    sac_nc($proxyHost !== '' ? 'INFO' : 'FAIL', 'direct TLS 443', trim((string) $err) ?: "errno $errno");
    if ($proxyHost === '') {
        $remedy[] = 'No direct egress to ' . SAC_NC_HOST . ':443. Ask the network team to allow it BY HOSTNAME (the site is behind a CDN, so its IPs change), or configure a proxy in Setup > General > Network.';
    }
}

// --- 6. The real fetch, exactly as the cron does it ------------------------
$started = microtime(true);
$body    = \GlpiPlugin\Sac\Http::get($url);
$ms      = (int) ((microtime(true) - $started) * 1000);

if ($body !== null) {
    sac_nc('PASS', 'fetch via GLPI transport', strlen($body) . " bytes in {$ms} ms");
} else {
    sac_nc('FAIL', 'fetch via GLPI transport', \GlpiPlugin\Sac\Http::lastError() . " (after {$ms} ms)");
    $remedy[] = 'This is the step the EOL cron performs. Until it passes, every endoflife.date lookup counts as an error and OS versions stay without an end-of-life date.';
}

// --- 7. Payload shape ------------------------------------------------------
if ($body !== null) {
    $data = json_decode($body, true);
    if (!is_array($data) || $data === []) {
        sac_nc('FAIL', 'response is a JSON array', 'got ' . substr(trim((string) $body), 0, 60));
        $remedy[] = 'Something answered but it is not the API payload — most likely a captive portal or proxy error page.';
    } else {
        $first  = is_array($data[0] ?? null) ? $data[0] : [];
        $fields = array_values(array_filter(['cycle', 'eol', 'support', 'lts'], static fn ($f) => array_key_exists($f, $first)));
        sac_nc(
            in_array('cycle', $fields, true) && in_array('eol', $fields, true) ? 'PASS' : 'FAIL',
            'payload fields the scan reads',
            count($data) . ' cycles; first has: ' . (implode(', ', $fields) ?: 'none of cycle/eol/support/lts')
        );
    }
}

// --- What is at stake ------------------------------------------------------
echo "\nLocal mirror\n";
$maxAge = (int) \GlpiPlugin\Sac\Config::get('eol_cache_max_age_days');
$cached = (int) ($DB->request(['COUNT' => 'c', 'FROM' => 'glpi_plugin_sac_eolcache'])->current()['c'] ?? 0);
$stale  = count(\GlpiPlugin\Sac\Eol\EolCache::staleProducts($maxAge));
$noMap  = (int) ($DB->request([
    'COUNT' => 'c',
    'FROM'  => 'glpi_plugin_sac_reconcile',
    'WHERE' => ['signal_type' => 'eol', 'reason' => 'no_mapping', 'is_resolved' => 0],
])->current()['c'] ?? 0);
printf("  %-34s %d\n", 'cached product/cycle rows', $cached);
printf("  %-34s %d (older than %d days, or never fetched)\n", 'product/cycle pairs to refresh', $stale, $maxAge);
printf("  %-34s %d\n", 'open "no_mapping" reconcile rows', $noMap);
if ($noMap > 0) {
    echo "  (those need an endoflife.date slug on the Reconcile page, not network access)\n";
}

// --- Verdict ---------------------------------------------------------------
echo "\n";
if ($fail === 0) {
    echo "RESULT: endoflife.date is reachable from this host. Run the EOL Cache Refresh cron, then the EOL scan.\n";
} else {
    echo "RESULT: $fail check(s) failed.\n";
    foreach ($remedy as $i => $line) {
        echo '  ' . ($i + 1) . ". $line\n";
    }
}
exit($fail === 0 ? 0 : 1);
