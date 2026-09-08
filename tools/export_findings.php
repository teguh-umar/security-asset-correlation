<?php
include(dirname(__DIR__, 3) . '/inc/includes.php');
if (!\Plugin::isPluginActive('riskregister')) { fwrite(STDERR, "riskregister inactive\n"); exit(1); }
$out = $argv[1] ?? 'php://stdout';
$fh  = fopen($out, 'w');
fputcsv($fh, ['code','name','severity','status','scope_label','assessment_ref','entities_id','content']);
$f = new \GlpiPlugin\Riskregister\Finding();
foreach ($f->find(['assessment_ref' => ['LIKE', 'SAC/%']]) as $row) {
    fputcsv($fh, [$row['code'],$row['name'],$row['severity'],$row['status'],$row['scope_label'],$row['assessment_ref'],$row['entities_id'],$row['content']]);
}
fclose($fh);
