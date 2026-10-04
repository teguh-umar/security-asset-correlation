<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

/** @var \DBmysql $DB */
global $DB;

$runs = [];
foreach ($DB->request([
    'FROM'  => 'glpi_plugin_sac_run',
    'ORDER' => ['id DESC'],
    'LIMIT' => 50,
]) as $row) {
    $runs[] = [
        'module'     => (string) $row['module'],
        'date_start' => $row['date_start'],
        'date_end'   => $row['date_end'],
        'status'     => (string) $row['status'],
        'message'    => (string) ($row['message'] ?? ''),
        'counts'     => json_decode((string) ($row['counts_json'] ?? ''), true) ?: [],
    ];
}

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'runs');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'runs');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/run.html.twig', ['runs' => $runs]);

Html::footer();
