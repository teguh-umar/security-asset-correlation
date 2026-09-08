<?php

/**
 * Security-Asset Correlation plugin — self-check assertion runner (VM).
 *
 * Usage (from the GLPI host):
 *   php plugins/sac/tools/selfcheck.php [filter]
 *
 * Bootstraps GLPI, reflects over every class under `src/`, and runs every
 *   GlpiPlugin\Sac\*::selfChecks(): array<string, callable>
 * printing a PASS/FAIL line per check and exiting non-zero on any failure.
 *
 * This is the FULL runner: unlike tools/offline-selfcheck.php it also executes
 * the `db/`-prefixed checks (the ones that need $DB / GLPI fixtures) — that is
 * its whole point. A class without a selfChecks() method is silently skipped.
 * The optional [filter] argument keeps only checks whose name contains that
 * substring.
 */

// --- Assertion helpers -------------------------------------------------------
// Defined ABOVE the bootstrap require so they exist regardless of what GLPI
// pulls in, and are callable from every check closure.

function sac_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

function sac_assert_eq(mixed $a, mixed $b, string $msg = ''): void
{
    if ($a !== $b) {
        throw new \RuntimeException(
            ($msg ?: 'not equal')
            . ' — expected ' . var_export($b, true)
            . ', got ' . var_export($a, true)
        );
    }
}

// --- Bootstrap GLPI --------------------------------------------------------
// This file lives at <glpi>/plugins/sac/tools/selfcheck.php, so the GLPI root is
// three levels up. We probe a few candidates and use whichever one actually
// contains inc/includes.php (GLPI 11's CLI/legacy entrypoint — still referenced
// by src/Glpi/Kernel/Kernel.php) so the harness keeps working if the plugin is
// nested differently (e.g. marketplace/).

$glpiRoot = null;
foreach ([dirname(__DIR__, 3), dirname(__DIR__, 4), dirname(__DIR__, 2), dirname(__DIR__, 5)] as $candidate) {
    if (is_file($candidate . '/inc/includes.php')) {
        $glpiRoot = $candidate;
        break;
    }
}

if ($glpiRoot === null) {
    fwrite(STDERR, "selfcheck: cannot locate GLPI root (inc/includes.php) from " . __DIR__ . "\n");
    exit(2);
}

require $glpiRoot . '/inc/includes.php';

// GLPI 11's CLI/legacy bootstrap does NOT auto-load plugins, so the PSR-4
// autoloader for GlpiPlugin\Sac\ is never registered and every discovered class
// below would be missing -> "0 checks discovered" false pass.
// Plugin::load($key, true) registers the autoloader + runs plugin_init_*().
if (class_exists('\Plugin')) {
    \Plugin::load('sac', true);
}

// --- Discover the plugin's classes ---------------------------------------
// Walk src/**/*.php and require_once each file, then keep every declared class
// in the GlpiPlugin\Sac\ namespace that exposes a selfChecks() method.

$srcDir = dirname(__DIR__) . '/src';
$rii    = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if ($file->getExtension() === 'php') {
        require_once $file->getPathname();
    }
}

// --- Run the checks --------------------------------------------------------

$filter = $argv[1] ?? '';
$fail   = 0;
$count  = 0;

foreach (get_declared_classes() as $class) {
    if (!str_starts_with($class, 'GlpiPlugin\\Sac\\')) {
        continue;
    }
    if (!method_exists($class, 'selfChecks')) {
        continue;
    }
    foreach ($class::selfChecks() as $name => $fn) {
        if ($filter !== '' && !str_contains($name, $filter)) {
            continue;
        }
        $count++;
        try {
            $fn();
            echo "PASS  $name\n";
        } catch (\Throwable $e) {
            $fail++;
            echo "FAIL  $name — " . $e->getMessage() . "\n";
        }
    }
}

if ($count === 0) {
    fwrite(STDERR, "selfcheck: 0 checks discovered — plugin classes not autoloadable? (is the plugin installed + active?)\n");
    exit(2);
}

echo "\n$count checks, $fail failed\n";
exit($fail > 0 ? 1 : 0);
