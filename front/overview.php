<?php

use GlpiPlugin\Sac\FindingWriter;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

/** @var \DBmysql $DB */
global $DB;

$riskregister = FindingWriter::isAvailable();

$cards = [];
foreach (['eol', 'license', 'eolcache'] as $module) {
    $run = $DB->request([
        'FROM'  => 'glpi_plugin_sac_run',
        'WHERE' => ['module' => $module],
        'ORDER' => ['id DESC'],
        'LIMIT' => 1,
    ])->current();
    $run = is_array($run) ? $run : null;

    // eolcache is an optional card — only show it once it has run at least once.
    if ($run === null && $module === 'eolcache') {
        continue;
    }

    $openReconcile = 0;
    if ($module !== 'eolcache') {
        $openReconcile = (int) ($DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_sac_reconcile',
            'WHERE' => ['signal_type' => $module, 'is_resolved' => 0],
        ])->current()['cpt'] ?? 0);
    }

    $findingsUrl = null;
    if ($riskregister && $module !== 'eolcache'
        && class_exists(\GlpiPlugin\Riskregister\Finding::class)) {
        // The riskregister search-option id for `assessment_ref` is not knowable
        // offline, so link to the unfiltered Finding search. See task-11 report.
        $findingsUrl = \GlpiPlugin\Riskregister\Finding::getSearchURL(false);
    }

    $cards[] = [
        'module'         => $module,
        'date_end'       => $run['date_end'] ?? null,
        'status'         => $run['status'] ?? null,
        'message'        => (string) ($run['message'] ?? ''),
        'counts'         => $run ? (json_decode((string) ($run['counts_json'] ?? ''), true) ?: []) : [],
        'open_reconcile' => $openReconcile,
        'findings_url'   => $findingsUrl,
    ];
}

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'overview');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'overview');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/overview.html.twig', [
    'cards'        => $cards,
    'riskregister' => $riskregister,
]);

Html::footer();
