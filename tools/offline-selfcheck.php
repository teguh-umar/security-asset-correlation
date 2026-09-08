<?php
// Runs the dependency-free selfChecks() with C:\php\php.exe — no GLPI, no network.
// Usage: "/c/php/php.exe" tools/offline-selfcheck.php [filter]

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

$root  = dirname(__DIR__);
$pure  = ['Severity', 'Code', 'EolMatch', 'FindingWriter', 'Config', 'Http']; // classes with a pure selfChecks() subset
$filter = $argv[1] ?? '';
$fail = 0; $total = 0;

foreach ($pure as $short) {
    $file = "$root/src/$short.php";
    if (!is_file($file)) { continue; }
    require_once $file;
    $class = "GlpiPlugin\\Sac\\$short";
    if (!method_exists($class, 'selfChecks')) { continue; }
    foreach ($class::selfChecks() as $name => $check) {
        if ($filter !== '' && !str_contains($name, $filter)) { continue; }
        $total++;
        try { $check(); echo "PASS  $name\n"; }
        catch (\Throwable $e) { $fail++; echo "FAIL  $name — {$e->getMessage()}\n"; }
    }
}
echo "\n$total checks, $fail failed\n";
exit($fail === 0 ? 0 : 1);
