<?php
// CLI-only. This script has no session/auth; refuse any HTTP invocation.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Same bootstrap as tools/selfcheck.php — inc/includes.php loads nothing in GLPI 11.
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel())->boot();
if (!\Plugin::isPluginActive('riskregister')) { fwrite(STDERR, "riskregister inactive\n"); exit(1); }
$out = $argv[1] ?? 'php://stdout';
$fh  = fopen($out, 'w');
fputcsv($fh, ['code','name','severity','status','scope_label','assessment_ref','entities_id','content']);
$f = new \GlpiPlugin\Riskregister\Finding();
foreach ($f->find(['assessment_ref' => ['LIKE', 'SAC/%']]) as $row) {
    fputcsv($fh, [$row['code'],$row['name'],$row['severity'],$row['status'],$row['scope_label'],$row['assessment_ref'],$row['entities_id'],$row['content']]);
}
fclose($fh);
