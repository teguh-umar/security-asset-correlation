<?php

use GlpiPlugin\Sac\FindingWriter;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

/** @var \DBmysql $DB */
global $DB;

$riskregister = FindingWriter::isAvailable();

// A scan run covers every entity. A user who does not see all entities gets
// the run's time and status, but not its instance-wide counters or error
// text, and an open-reconcile count limited to their own entities.
$seesAll = \GlpiPlugin\Sac\EntityScope::seesAll();

$openBySignal = [];
foreach (\GlpiPlugin\Sac\Reconcile::listForDisplay() as $row) {
    $openBySignal[$row['signal_type']] = ($openBySignal[$row['signal_type']] ?? 0) + 1;
}

$cards = [];
foreach ($seesAll ? ['eol', 'license', 'eolcache'] : ['eol', 'license'] as $module) {
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

    $openReconcile = $openBySignal[$module] ?? 0;

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
        'message'        => $seesAll ? (string) ($run['message'] ?? '') : '',
        'counts'         => ($run && $seesAll) ? (json_decode((string) ($run['counts_json'] ?? ''), true) ?: []) : [],
        'open_reconcile' => $openReconcile,
        'findings_url'   => $findingsUrl,
    ];
}

Html::header(__('Security-Asset Correlation', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'overview');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'overview');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/overview.html.twig', [
    'cards'        => $cards,
    'riskregister' => $riskregister,
    'summary'      => \GlpiPlugin\Sac\Summary::build(),
]);

Html::footer();
