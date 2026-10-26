<?php

use GlpiPlugin\Sac\EntityScope;
use GlpiPlugin\Sac\License\LicenseRule;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_finding', READ);

$canAct = Session::haveRight('plugin_sac_finding', UPDATE);

/** @var \DBmysql $DB */
global $DB;

$action = Html::getPrefixedUrl('/plugins/sac/front/licenserules.php');
$table  = 'glpi_plugin_sac_licenserules';
$error  = null;

$form = [
    'id'             => 0,
    'entities_id'    => (int) ($_SESSION['glpiactive_entity'] ?? 0),
    'pattern'        => '',
    'match_on'       => 'name',
    'classification' => 'needs_license',
    'reason'         => '',
    'is_recursive'   => 0,
];

if ($canAct && isset($_POST['save'])) {
    $id = (int) ($_POST['id'] ?? 0);

    // Raw values: LicenseRule::validate() owns the type checks, so an array
    // posted as `pattern[]` must reach it as an array, not as "Array".
    $input = [
        'entities_id'    => (int) ($_POST['entities_id'] ?? 0),
        'pattern'        => $_POST['pattern'] ?? '',
        'match_on'       => $_POST['match_on'] ?? '',
        'classification' => $_POST['classification'] ?? '',
        'reason'         => $_POST['reason'] ?? '',
        'is_recursive'   => isset($_POST['is_recursive']) ? 1 : 0,
    ];

    $form = [
        'id'             => $id,
        'entities_id'    => $input['entities_id'],
        // Display-safe: validate() above owns the raw type checks, but an
        // array posted as `pattern[]` must not reach Twig as an array.
        'pattern'        => is_string($input['pattern']) ? $input['pattern'] : '',
        'match_on'       => is_string($input['match_on']) ? $input['match_on'] : 'name',
        'classification' => is_string($input['classification']) ? $input['classification'] : 'needs_license',
        'reason'         => is_string($input['reason']) ? $input['reason'] : '',
        'is_recursive'   => $input['is_recursive'],
    ];

    $existing = null;
    if ($id > 0) {
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
            $existing = $row;
        }
    }

    if ($id > 0 && $existing === null) {
        $error = __('That rule no longer exists', 'sac');
    } elseif ($id > 0 && !Session::haveAccessToEntity((int) $existing['entities_id'])) {
        $error = __('You may not edit a rule outside the entities you can see', 'sac');
    } elseif (!Session::haveAccessToEntity($input['entities_id'])) {
        $error = __('You may not write a rule in an entity you cannot see', 'sac');
    } elseif ($input['is_recursive'] === 1 && !Session::haveRecursiveAccessToEntity($input['entities_id'])) {
        $error = __('You may not share this entity\'s rules with its sub-entities', 'sac');
    } else {
        $error = LicenseRule::validate($input);
    }

    if ($error === null) {
        // The table has UNIQUE (entities_id, match_on, pattern). Check first so
        // the operator gets a sentence, not a raw duplicate-key error page.
        foreach ($DB->request([
            'FROM'  => $table,
            'WHERE' => [
                'entities_id' => $input['entities_id'],
                'match_on'    => $input['match_on'],
                'pattern'     => $input['pattern'],
            ],
        ]) as $row) {
            if ((int) $row['id'] !== $id) {
                $error = __('A rule with this pattern and match type already exists in that entity', 'sac');
                break;
            }
        }
    }

    if ($error === null) {
        $now  = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $data = [
            'entities_id'    => $input['entities_id'],
            'is_recursive'   => $input['is_recursive'],
            'pattern'        => $input['pattern'],
            'match_on'       => $input['match_on'],
            'classification' => $input['classification'],
            'reason'         => $input['reason'],
            'date_mod'       => $now,
        ];

        if ($id > 0) {
            $saved = $DB->update($table, $data, ['id' => $id]) !== false;
        } else {
            $data['date_creation'] = $now;
            $saved = $DB->insert($table, $data) !== false;
        }

        if ($saved) {
            Session::addMessageAfterRedirect($id > 0
                ? __('License rule updated', 'sac')
                : __('License rule added', 'sac'));
            Html::redirect($action);
        }
        $error = __('The rule could not be saved. Reload the page and try again.', 'sac');
    }
} elseif ((int) ($_GET['edit'] ?? 0) > 0) {
    $editId = (int) $_GET['edit'];
    $found  = false;
    foreach ($DB->request(['FROM' => $table, 'WHERE' => ['id' => $editId], 'LIMIT' => 1]) as $row) {
        $found = true;
        if (!Session::haveAccessToEntity((int) $row['entities_id'])) {
            $error = __('You may not edit a rule outside the entities you can see', 'sac');
            break;
        }
        $form = [
            'id'             => (int) $row['id'],
            'entities_id'    => (int) $row['entities_id'],
            'pattern'        => (string) $row['pattern'],
            'match_on'       => (string) $row['match_on'],
            'classification' => (string) $row['classification'],
            'reason'         => (string) ($row['reason'] ?? ''),
            'is_recursive'   => (int) ($row['is_recursive'] ?? 0),
        ];
    }
    if (!$found) {
        $error = __('That rule no longer exists', 'sac');
    }
}

// Read path: a rule from an ancestor is visible when it is recursive, because
// that is the rule that decides the software in the user's own entity.
$groups = [];
foreach ($DB->request([
    'FROM'  => $table,
    'WHERE' => getEntitiesRestrictCriteria($table, 'entities_id', '', true),
    'ORDER' => ['entities_id ASC', 'match_on ASC', 'pattern ASC'],
]) as $row) {
    $entityId = (int) $row['entities_id'];
    if (!isset($groups[$entityId])) {
        $groups[$entityId] = [
            'entity_id' => $entityId,
            'name'      => (string) \Dropdown::getDropdownName('glpi_entities', $entityId),
            'rules'     => [],
        ];
    }
    $groups[$entityId]['rules'][] = [
        'id'             => (int) $row['id'],
        'pattern'        => (string) $row['pattern'],
        'match_on'       => (string) $row['match_on'],
        'classification' => (string) $row['classification'],
        'reason'         => (string) ($row['reason'] ?? ''),
        'is_recursive'   => (int) ($row['is_recursive'] ?? 0),
        // An inherited ancestor rule is readable but not editable by a
        // sub-entity admin.
        'can_edit'       => Session::haveAccessToEntity($entityId),
    ];
}
$groups = array_values($groups);
usort(
    $groups,
    static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: $a['entity_id'] <=> $b['entity_id']
);

// The entities a rule may be written in. An ancestor is not offered: a child
// admin cannot maintain the parent's defaults.
$entities = [];
foreach ($DB->request([
    'SELECT' => ['id', 'completename'],
    'FROM'   => 'glpi_entities',
    'WHERE'  => getEntitiesRestrictCriteria('glpi_entities', 'id', '', false),
    'ORDER'  => 'completename',
]) as $entity) {
    $entities[] = ['id' => (int) $entity['id'], 'name' => (string) $entity['completename']];
}

Html::header(__('Security Assessment', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac', 'licenserules');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('sac', 'licenserules');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/licenserules.html.twig', [
    'action'   => $action,
    'groups'   => $groups,
    'entities' => $entities,
    'error'    => $error,
    'form'     => $form,
    'can_act'  => $canAct,
    'sees_all' => EntityScope::seesAll(),
]);

Html::footer();
