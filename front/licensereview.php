<?php

use GlpiPlugin\Sac\EntityScope;
use GlpiPlugin\Sac\License\LicenseRule;
use GlpiPlugin\Sac\License\RulePreview;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

$canAct = Session::haveRight('plugin_sac_finding', UPDATE);

/** @var \DBmysql $DB */
global $DB;

$action     = Html::getPrefixedUrl('/plugins/sac/front/licensereview.php');
$table      = 'glpi_plugin_sac_licensereview';
$rulesTable = 'glpi_plugin_sac_licenserules';
$now        = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
$error      = null;
$panel      = null;
$preview    = null;
$decidedView = ((int) ($_GET['decided'] ?? 0)) === 1;

$loadQueueRow = static function (int $id): ?array {
    global $DB, $table;

    $row = $DB->request(['FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();

    return $row ? (array) $row : null;
};

// The decision is persisted as an exact rule for the software name, so that
// pair is how the generalisation step finds the rule it may replace.
$findExactRule = static function (int $entityId, string $pattern): ?array {
    global $DB, $rulesTable;

    $row = $DB->request([
        'FROM'  => $rulesTable,
        'WHERE' => ['entities_id' => $entityId, 'match_on' => 'name', 'pattern' => $pattern],
        'LIMIT' => 1,
    ])->current();

    return $row ? (array) $row : null;
};

$softwareName = static function (int $softwareId): string {
    global $DB;

    // Read the raw name: it becomes a rule pattern and must match exactly what
    // LicenseScan::candidateSql() matches. Dropdown::getDropdownName() builds
    // display output and is not safe to store as a pattern.
    $row = $DB->request([
        'SELECT' => ['name'],
        'FROM'   => 'glpi_softwares',
        'WHERE'  => ['id' => $softwareId],
        'LIMIT'  => 1,
    ])->current();

    return $row ? (string) $row['name'] : '';
};

$panelFrom = static function (array $row, array $rule) use ($softwareName): array {
    $name = $softwareName((int) $row['softwares_id']);

    return [
        'row_id'         => (int) $row['id'],
        'software'       => $name,
        'entity'         => (string) \Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
        'classification' => (string) $rule['classification'],
        'reason'         => (string) ($rule['reason'] ?? ''),
        // Offered, not enforced: the preview is what makes a wider choice safe.
        'pattern'        => RulePreview::suggestPattern($name),
    ];
};

// A crafted `field[]` would otherwise be cast to the string "Array" (or emit a
// warning on (string)); refuse the whole post instead.
$malformed = false;
foreach (['task', 'row_id', 'note', 'classification', 'reason', 'pattern', 'previewed'] as $key) {
    if (isset($_POST[$key]) && !is_string($_POST[$key])) {
        $malformed = true;
    }
}
if ($malformed) {
    $error = __('The form input could not be read. Reload the page and try again.', 'sac');
}

$task         = $malformed ? '' : (string) ($_POST['task'] ?? '');
$generaliseId = (int) ($_GET['generalise'] ?? 0);
$rowId        = (int) ($_POST['row_id'] ?? $generaliseId);
$row          = $rowId > 0 ? $loadQueueRow($rowId) : null;
$rowActionable = $row !== null
    && $canAct
    && Session::haveAccessToEntity((int) $row['entities_id']);

if ($task !== '') {
    if (!$canAct) {
        $error = __('Flagging or deciding a queue row needs the update right on Security Assessment findings.', 'sac');
    } elseif ($row === null) {
        $error = __('That queue row no longer exists', 'sac');
    } elseif (!Session::haveAccessToEntity((int) $row['entities_id'])) {
        $error = __('You may not act on a queue row outside the entities you can see', 'sac');
    }
}

if ($task === 'flag' && $error === null && $rowActionable) {
    $note = trim((string) ($_POST['note'] ?? ''));

    if ((string) $row['state'] === 'decided') {
        $error = __('That row is already decided', 'sac');
    } elseif ($note === '') {
        $error = __('A note is required when flagging a row: it is what the next reviewer reads', 'sac');
    } else {
        // The note is the mark; deciding leaves it in place so the history
        // survives the decision.
        $saved = $DB->update($table, [
            'state'    => 'flagged',
            'note'     => $note,
            'date_mod' => $now,
        ], ['id' => (int) $row['id']]) !== false;

        if ($saved) {
            Session::addMessageAfterRedirect(__('Queue row flagged', 'sac'));
            Html::redirect($action . ($decidedView ? '?decided=1' : ''));
        }
        $error = __('The row could not be updated. Reload the page and try again.', 'sac');
    }
}

if ($task === 'decide' && $error === null && $rowActionable) {
    if ((string) $row['state'] === 'decided') {
        $error = __('That row is already decided', 'sac');
    } else {
        $name = $softwareName((int) $row['softwares_id']);

        if ($name === '') {
            $error = __('This software no longer exists, so there is nothing to write a rule for', 'sac');
        } else {
            $input = [
                'entities_id'    => (int) $row['entities_id'],
                // The decision applies to this package first: an exact rule.
                'pattern'        => $name,
                'match_on'       => 'name',
                'classification' => (string) ($_POST['classification'] ?? ''),
                'reason'         => (string) ($_POST['reason'] ?? ''),
                'is_recursive'   => 0,
            ];
            $error = LicenseRule::validate($input);
        }

        if ($error === null) {
            $existing = $findExactRule($input['entities_id'], $name);
            $data     = [
                'classification' => $input['classification'],
                'reason'         => $input['reason'],
                'is_recursive'   => 0,
                'date_mod'       => $now,
            ];

            if ($existing !== null) {
                // The row was queued before a same-entity exact rule existed.
                // The operator's newer decision wins.
                $saved = $DB->update($rulesTable, $data, ['id' => (int) $existing['id']]) !== false;
            } else {
                $data['entities_id']   = $input['entities_id'];
                $data['pattern']       = $name;
                $data['match_on']      = 'name';
                $data['date_creation'] = $now;
                $saved = $DB->insert($rulesTable, $data) !== false;
            }

            if (!$saved) {
                $error = __('The rule could not be saved. Reload the page and try again.', 'sac');
            } else {
                $marked = $DB->update($table, ['state' => 'decided', 'date_mod' => $now], ['id' => (int) $row['id']]) !== false;
                if ($marked) {
                    Session::addMessageAfterRedirect(__('Decision saved as an exact rule for this software', 'sac'));
                    Html::redirect($action . '?generalise=' . (int) $row['id']);
                }
                $error = __('The decision was written but the queue row could not be marked decided. Decide it again.', 'sac');
            }
        }
    }
}

// Both the first view of the panel and the preview POST need the exact rule.
if (($task === 'preview' || $task === 'save_rule') && $error === null && $rowActionable) {
    if ((string) $row['state'] !== 'decided') {
        $error = __('Decide the row before generalising it', 'sac');
    } else {
        $name = $softwareName((int) $row['softwares_id']);
        $rule = $name === '' ? null : $findExactRule((int) $row['entities_id'], $name);

        if ($rule === null) {
            // Either the operator already replaced it, or it was edited on the
            // rules page. Either way an exact rule for this name is gone.
            $error = __('The per-software rule this decision wrote is no longer there. Edit the rule on the License rules page instead.', 'sac');
        } else {
            $panel = $panelFrom($row, $rule);

            if ($task === 'preview') {
                $postedPattern = (string) ($_POST['pattern'] ?? '');
                $postedReason  = (string) ($_POST['reason'] ?? '');

                // Scope is what the approver may see: names outside it stay
                // hidden and only their count is disclosed (Review Focus 4).
                $scope = EntityScope::seesAll()
                    ? null
                    : array_map('intval', $_SESSION['glpiactiveentities'] ?? []);

                $covered = RulePreview::forPattern($postedPattern, 'name', $scope);

                $preview = [
                    'pattern'        => $postedPattern,
                    'reason'         => $postedReason,
                    'classification' => (string) $rule['classification'],
                    'visible'        => $covered['visible'],
                    'hidden_count'   => $covered['hidden_count'],
                    // Shown with the coverage, but it does not block the
                    // preview: an operator may still be drafting the reason.
                    'error'          => LicenseRule::validate([
                        'pattern'        => $postedPattern,
                        'match_on'       => 'name',
                        'classification' => (string) $rule['classification'],
                        'reason'         => $postedReason,
                    ]),
                ];
                $panel['pattern'] = $postedPattern;
                $panel['reason']  = $postedReason;
            } else {
                $postedPattern = (string) ($_POST['pattern'] ?? '');
                $postedReason  = (string) ($_POST['reason'] ?? '');

                $panel['pattern'] = $postedPattern;
                $panel['reason']  = $postedReason;

                if ((string) ($_POST['previewed'] ?? '') !== '1') {
                    $error = __('Preview the pattern before saving it', 'sac');
                } else {
                    // The classification is the decision's; generalisation
                    // widens the pattern, it does not re-decide the class.
                    $input = [
                        'entities_id'    => (int) $rule['entities_id'],
                        'pattern'        => $postedPattern,
                        'match_on'       => 'name',
                        'classification' => (string) $rule['classification'],
                        'reason'         => $postedReason,
                        'is_recursive'   => (int) ($rule['is_recursive'] ?? 0),
                    ];
                    $error = LicenseRule::validate($input);
                }

                if ($error === null) {
                    foreach ($DB->request([
                        'FROM'  => $rulesTable,
                        'WHERE' => [
                            'entities_id' => $input['entities_id'],
                            'match_on'    => 'name',
                            'pattern'     => $input['pattern'],
                        ],
                    ]) as $other) {
                        if ((int) $other['id'] !== (int) $rule['id']) {
                            $error = __('A rule with this pattern already exists in that entity', 'sac');
                            break;
                        }
                    }
                }

                if ($error === null) {
                    // Replace, not add: an exact rule beside a wider one would
                    // always win, making the generalisation pointless.
                    $saved = $DB->update($rulesTable, [
                        'pattern'  => $input['pattern'],
                        'reason'   => $input['reason'],
                        'date_mod' => $now,
                    ], ['id' => (int) $rule['id']]) !== false;

                    if ($saved) {
                        Session::addMessageAfterRedirect(__('The per-software rule was replaced with the wider pattern', 'sac'));
                        Html::redirect($action);
                    }
                    $error = __('The rule could not be saved. Reload the page and try again.', 'sac');
                }
            }
        }
    }
}

// Opening the panel after a decision.
if ($generaliseId > 0 && $task === '' && $error === null) {
    if (!$canAct) {
        $error = __('Generalising a decision needs the update right on Security Assessment findings.', 'sac');
    } elseif ($row === null) {
        $error = __('That queue row no longer exists', 'sac');
    } elseif (!Session::haveAccessToEntity((int) $row['entities_id'])) {
        $error = __('You may not act on a queue row outside the entities you can see', 'sac');
    } elseif ((string) $row['state'] !== 'decided') {
        $error = __('Decide the row before generalising it', 'sac');
    } else {
        $name = $softwareName((int) $row['softwares_id']);
        $rule = $name === '' ? null : $findExactRule((int) $row['entities_id'], $name);

        if ($rule === null) {
            $error = __('The per-software rule this decision wrote is no longer there. Edit the rule on the License rules page instead.', 'sac');
        } else {
            $panel = $panelFrom($row, $rule);
        }
    }
}

$listWhere = $decidedView
    ? ['state' => 'decided']
    : ['state' => ['<>', 'decided']];
$listWhere += getEntitiesRestrictCriteria($table, 'entities_id', '', true);

$rows = [];
foreach ($DB->request([
    'FROM'  => $table,
    'WHERE' => $listWhere,
    'ORDER' => $decidedView ? ['date_mod DESC', 'id DESC'] : ['date_first_seen ASC', 'id ASC'],
]) as $dbRow) {
    $dbRow    = (array) $dbRow;
    $name     = $softwareName((int) $dbRow['softwares_id']);
    $rows[] = [
        'id'              => (int) $dbRow['id'],
        'software'        => $name !== '' ? $name : '#' . (int) $dbRow['softwares_id'],
        'entity'          => (string) \Dropdown::getDropdownName('glpi_entities', (int) $dbRow['entities_id']),
        'state'           => (string) $dbRow['state'],
        'note'            => (string) ($dbRow['note'] ?? ''),
        'date_first_seen' => (string) ($dbRow['date_first_seen'] ?? ''),
        'date_mod'        => (string) ($dbRow['date_mod'] ?? ''),
        'can_act'         => $canAct && Session::haveAccessToEntity((int) $dbRow['entities_id']),
    ];
}

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'licensereview');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'licensereview');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/licensereview.html.twig', [
    'action'     => $action,
    'rows'       => $rows,
    'decided'    => $decidedView,
    'toggle_url' => $action . ($decidedView ? '' : '?decided=1'),
    'error'      => $error,
    'panel'      => $panel,
    'preview'    => $preview,
    'can_act'    => $canAct,
    'sees_all'   => EntityScope::seesAll(),
]);

Html::footer();
