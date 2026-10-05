<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

/** @var \DBmysql $DB */
global $DB;

$seesAll = \GlpiPlugin\Sac\EntityScope::seesAll();

$runs = [];
foreach ($DB->request([
    'FROM'  => 'glpi_plugin_sac_run',
    // eolcache is internal maintenance: group level only.
    'WHERE' => $seesAll ? [] : ['module' => ['<>', 'eolcache']],
    'ORDER' => ['id DESC'],
    'LIMIT' => 50,
]) as $row) {
    $runs[] = [
        'module'     => (string) $row['module'],
        'date_start' => $row['date_start'],
        'date_end'   => $row['date_end'],
        'status'     => (string) $row['status'],
        // A run covers every entity: its counters and error text are shown
        // only to a Super-Admin.
        'message'    => $seesAll ? (string) ($row['message'] ?? '') : '',
        'counts'     => $seesAll ? (json_decode((string) ($row['counts_json'] ?? ''), true) ?: []) : [],
    ];
}

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'runs');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'runs');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/run.html.twig', ['runs' => $runs, 'sees_all' => $seesAll]);

Html::footer();
