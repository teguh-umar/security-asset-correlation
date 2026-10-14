<?php

use GlpiPlugin\Sac\Compliance\SshSummaryReader;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_config', READ);
$canAct = Session::haveRight('plugin_sac_config', UPDATE);

/** @var \DBmysql $DB */
global $DB;

if (isset($_POST['add'])) {
    Session::checkRight('plugin_sac_config', UPDATE);

    $cid  = (int) ($_POST['computers_id'] ?? 0);
    $host = trim((string) ($_POST['host'] ?? ''));
    $port = (int) ($_POST['port'] ?? 22);
    $user = trim((string) ($_POST['ssh_user'] ?? 'pgnusr'));

    if ($cid <= 0 || $host === '' || $user === '' || $port <= 0 || $port > 65535) {
        Session::addMessageAfterRedirect(__('Computer, host and SSH user are required; port must be 1-65535', 'sac'), false, ERROR);
        Html::back();
    }

    // Rejected here as a clear admin-facing error; SshSummaryReader also
    // refuses an unsafe value defensively at fetch time regardless of how
    // it got into the table, but failing fast here is the better UX and
    // keeps a bad row out of the database in the first place. A value
    // starting with "-" is the real risk: ssh would read it as an OPTION
    // (e.g. "-oProxyCommand=...") rather than a hostname/username, and
    // execute that locally on the GLPI host.
    if (!SshSummaryReader::isSafeSshIdentifier($host) || !SshSummaryReader::isSafeSshIdentifier($user)) {
        Session::addMessageAfterRedirect(
            __('Host and SSH user may only contain letters, digits, dots, underscores and hyphens, and may not start with a hyphen', 'sac'),
            false,
            ERROR
        );
        Html::back();
    }

    $existing = $DB->request(['FROM' => 'glpi_plugin_sac_sshtargets', 'WHERE' => ['computers_id' => $cid]])->current();
    if ($existing) {
        $DB->update('glpi_plugin_sac_sshtargets', [
            'host'      => $host,
            'port'      => $port,
            'ssh_user'  => $user,
            'is_active' => 1,
        ], ['id' => (int) $existing['id']]);
    } else {
        $DB->insert('glpi_plugin_sac_sshtargets', [
            'computers_id' => $cid,
            'host'         => $host,
            'port'         => $port,
            'ssh_user'     => $user,
            'is_active'    => 1,
        ]);
    }
    Session::addMessageAfterRedirect(__('SSH target saved', 'sac'));
    Html::back();
}

if (isset($_POST['toggle'])) {
    Session::checkRight('plugin_sac_config', UPDATE);
    $id  = (int) $_POST['toggle'];
    $row = $DB->request(['FROM' => 'glpi_plugin_sac_sshtargets', 'WHERE' => ['id' => $id]])->current();
    if ($row) {
        $DB->update('glpi_plugin_sac_sshtargets', ['is_active' => $row['is_active'] ? 0 : 1], ['id' => $id]);
    }
    Html::back();
}

if (isset($_POST['delete'])) {
    Session::checkRight('plugin_sac_config', UPDATE);
    $DB->delete('glpi_plugin_sac_sshtargets', ['id' => (int) $_POST['delete']]);
    Session::addMessageAfterRedirect(__('SSH target removed', 'sac'));
    Html::back();
}

$rows = [];
foreach ($DB->request([
    'SELECT'     => [
        'glpi_plugin_sac_sshtargets.*',
        'glpi_computers.name AS computer_name',
    ],
    'FROM'       => 'glpi_plugin_sac_sshtargets',
    'INNER JOIN' => [
        'glpi_computers' => [
            'ON' => [
                'glpi_plugin_sac_sshtargets' => 'computers_id',
                'glpi_computers'             => 'id',
            ],
        ],
    ],
    'ORDER' => ['glpi_computers.name ASC'],
]) as $row) {
    $rows[] = [
        'id'            => (int) $row['id'],
        'computer_name' => (string) $row['computer_name'],
        'host'          => (string) $row['host'],
        'port'          => (int) $row['port'],
        'ssh_user'      => (string) $row['ssh_user'],
        'is_active'     => (bool) $row['is_active'],
    ];
}

// Core helper, not hand-rolled markup (upgrade-resilience design principle,
// project-brief.md §4) — a future GLPI version that moves this to Twig
// changes the call site here, not every page that needs a Computer picker.
$computerDropdown = Dropdown::show('Computer', [
    'name'    => 'computers_id',
    'entity'  => 'all',
    'display' => false,
]);

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'compliance');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'compliance');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/sshtargets.html.twig', [
    'rows'              => $rows,
    'can_act'           => $canAct,
    'computer_dropdown' => $computerDropdown,
]);

Html::footer();
