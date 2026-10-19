<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

/** @var \DBmysql $DB */
global $DB;

$seesAll   = \GlpiPlugin\Sac\EntityScope::seesAll();
$staleDays = (int) \GlpiPlugin\Sac\Config::get('compliance_stale_days');
$cutoff    = date('Y-m-d H:i:s', strtotime("-{$staleDays} days"));

$where = [];
if (!$seesAll) {
    $where = getEntitiesRestrictCriteria('glpi_computers', '', '', true);
}

$rows = [];
foreach ($DB->request([
    'SELECT'     => [
        'glpi_plugin_sac_configscan.*',
        'glpi_computers.name AS computer_name',
        'glpi_computers.entities_id AS entities_id',
    ],
    'FROM'       => 'glpi_plugin_sac_configscan',
    'INNER JOIN' => [
        'glpi_computers' => [
            'ON' => [
                'glpi_plugin_sac_configscan' => 'computers_id',
                'glpi_computers'             => 'id',
            ],
        ],
    ],
    'WHERE'      => [
        'glpi_computers.is_deleted'  => 0,
        'glpi_computers.is_template' => 0,
    ] + $where,
    'ORDER'      => ['glpi_plugin_sac_configscan.score ASC'],
]) as $row) {
    $rows[] = [
        'computer_name'  => (string) $row['computer_name'],
        // Core helper, so the URL follows GLPI's own routing rather than a
        // hand-built path. Passed as a plain string and linked in the
        // template, keeping the "no |raw on plugin data" rule intact.
        'computer_url'   => Computer::getFormURLWithID((int) $row['computers_id']),
        'detail_url'     => Html::getPrefixedUrl('/plugins/sac/front/compliancedetail.php?computers_id=' . (int) $row['computers_id']),
        'benchmark_id'   => (string) $row['benchmark_id'],
        'score'          => (float) $row['score'],
        'pass_count'     => (int) $row['pass_count'],
        'fail_count'     => (int) $row['fail_count'],
        'other_count'    => (int) $row['other_count'],
        'date_collected' => $row['date_collected'],
        // Judged from the host's OWN reported scan timestamp (date_scan),
        // not date_collected ("did SAC last manage to SSH in") — same
        // semantic ConfigScan::isStale() uses for the Finding content, so
        // this dashboard and the Finding never disagree about staleness.
        'is_stale'       => $row['date_scan'] !== null && $row['date_scan'] < $cutoff,
    ];
}

$canManageTargets = \Session::haveRight('plugin_sac_config', UPDATE);

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'compliance');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'compliance');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/compliance.html.twig', [
    'rows'                => $rows,
    'stale_days'          => $staleDays,
    'can_manage_targets'  => $canManageTargets,
    'sshtargets_url'      => Html::getPrefixedUrl('/plugins/sac/front/sshtargets.php'),
]);

Html::footer();
