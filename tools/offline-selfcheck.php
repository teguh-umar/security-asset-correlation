<?php
// Runs the dependency-free selfChecks() with C:\php\php.exe — no GLPI, no network.
// Usage: "/c/php/php.exe" tools/offline-selfcheck.php [filter]
//
// Scans every class under src/** (like tools/selfcheck.php) but skips the `db/`
// prefixed checks — those need $DB / a GLPI runtime. Classes that cannot load
// without GLPI (they extend a core class) are silently skipped.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function sac_assert(bool $cond, string $msg): void
{
    if (!$cond) { throw new \RuntimeException($msg); }
}
function sac_assert_eq(mixed $got, mixed $want, string $msg = ''): void
{
    if ($got !== $want) {
        throw new \RuntimeException(($msg ?: 'not equal')
            . ' — expected ' . var_export($want, true) . ', got ' . var_export($got, true));
    }
}

$root   = dirname(__DIR__);
$srcDir = $root . '/src';
$filter = $argv[1] ?? '';
$fail = 0; $total = 0;

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    try {
        require_once $file->getPathname();
    } catch (\Throwable $e) {
        // Class needs the GLPI runtime (extends a core class) — not a pure check.
    }
}

foreach (get_declared_classes() as $class) {
    if (!str_starts_with($class, 'GlpiPlugin\\Sac\\')) { continue; }
    if (!method_exists($class, 'selfChecks')) { continue; }
    foreach ($class::selfChecks() as $name => $check) {
        if (str_starts_with($name, 'db/')) { continue; } // VM-only — needs $DB / GLPI
        if ($filter !== '' && !str_contains($name, $filter)) { continue; }
        $total++;
        try { $check(); echo "PASS  $name\n"; }
        catch (\Throwable $e) { $fail++; echo "FAIL  $name — {$e->getMessage()}\n"; }
    }
}

echo "\n$total checks, $fail failed\n";
exit($fail === 0 ? 0 : 1);
