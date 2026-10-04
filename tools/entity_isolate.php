<?php

/**
 * Security-Asset Correlation plugin — entity isolation report / repair (VM).
 *
 * Usage (from the GLPI host):
 *   php plugins/sac/tools/entity_isolate.php            # dry run: report only
 *   php plugins/sac/tools/entity_isolate.php --apply    # repair
 *
 * Until v1.4.x every SAC finding was written to one configured entity,
 * recursive, linked to the Computers of every entity. This lists each
 * SAC-written finding (CSV on stdout) with what is wrong with it:
 *   is_recursive   1 = visible from every child entity
 *   foreign_links  links to Computers that belong to another entity
 *   risks          risks derived from the finding (they stay where they are)
 *
 * --apply makes every listed finding non-recursive and removes its foreign
 * links through riskregister's own API. Nothing is deleted, and status,
 * history and derived risks are untouched. The scans do the same for the
 * findings they still assert, and create the per-entity findings; --apply
 * also covers the ones no scan touches any more (Closed, superseded, cleared).
 */

// CLI-only. Never reachable over HTTP even if the plugins dir is web-served.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Same bootstrap as tools/selfcheck.php — inc/includes.php loads nothing in GLPI 11.
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel())->boot();

if (!\GlpiPlugin\Sac\FindingWriter::isAvailable()) {
    fwrite(STDERR, "entity_isolate: riskregister is not active\n");
    exit(2);
}

$apply  = in_array('--apply', $argv, true);
$report = \GlpiPlugin\Sac\FindingWriter::isolateAll($apply);

$out = fopen('php://stdout', 'w');
fputcsv($out, ['id', 'code', 'entities_id', 'is_recursive', 'status', 'foreign_links', 'risks']);
$recursive = 0;
$links     = 0;
$touched   = 0;
foreach ($report as $row) {
    fputcsv($out, $row);
    $recursive += $row['is_recursive'] !== 0 ? 1 : 0;
    $links     += $row['foreign_links'];
    $touched   += ($row['is_recursive'] !== 0 || $row['foreign_links'] > 0) ? 1 : 0;
}
fclose($out);

fwrite(STDERR, sprintf(
    "\n%d SAC findings; %d need a change (%d recursive, %d links to another entity's Computers). %s\n",
    count($report),
    $touched,
    $recursive,
    $links,
    $apply ? 'APPLIED.' : 'Dry run: nothing was changed. Re-run with --apply to repair.'
));
