<?php

use GlpiPlugin\Sac\Config;
use GlpiPlugin\Sac\SuiteConfig;

include('../../../inc/includes.php');

Session::checkRight('plugin_sac_config', READ);

if (isset($_POST['update'])) {
    Session::checkRight('plugin_sac_config', UPDATE);

    $save = [];
    foreach (Config::FIELD_TYPES as $k => $type) {
        if (!array_key_exists($k, $_POST)) {
            continue;
        }
        $v = $_POST[$k];
        if ($type === 'softwarecategories') {
            // The multi-select posts its own array name; the hidden input is the fallback.
            $v = $_POST['license_ignore_categories_picked'] ?? [];
        }

        switch ($type) {
            case 'bool':
                $save[$k] = ($v === '1' || $v === 1 || $v === true) ? '1' : '0';
                break;

            case 'int':
                if (!ctype_digit((string) $v)) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('Invalid value for %s — expected a non-negative integer', 'sac'), $k),
                        false,
                        ERROR
                    );
                    break;
                }
                $save[$k] = (string) (int) $v;
                break;

            case 'entities':
                // The select posts `scan_entities[]`; with nothing selected only
                // the hidden empty `scan_entities` arrives, which means "all".
                $ids = is_array($v) ? array_filter($v, static fn ($id): bool => ctype_digit((string) $id)) : [];
                $save[$k] = json_encode(array_values(array_unique(array_map('intval', $ids))));
                break;

            case 'softwarecategories':
                // Posted as `license_ignore_categories[]` (names). Keep only names
                // GLPI actually has, so a stale or tampered value cannot be stored.
                $known = \GlpiPlugin\Sac\Config::softwareCategoryNames();
                $picked = is_array($v) ? array_values(array_intersect(array_map('strval', $v), $known)) : [];
                $save[$k] = json_encode($picked);
                break;

            case 'json':
                $decoded = json_decode((string) $v, true);
                if (!is_array($decoded)) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('Invalid value for %s — expected a JSON array', 'sac'), $k),
                        false,
                        ERROR
                    );
                    break;
                }
                $save[$k] = json_encode(array_values($decoded));
                break;

            default: // 'text'
                $save[$k] = is_array($v) ? json_encode(array_values($v)) : (string) $v;
        }
    }

    if ($save !== []) {
        \Config::setConfigurationValues(Config::CONTEXT, $save);
        Session::addMessageAfterRedirect(__('Updated', 'sac'));
    }
    // Both the standalone page and the shared ICT Tools tab post here.
    // `_from_pgnsuite` (set only by the tab's render, see SuiteConfig::render())
    // tells us which one: a tab-originated save redirects back to the tab
    // (hub §5 contract); the standalone page must Html::back() instead — a
    // plugin_sac_config-only user (no core config READ) has no route to
    // Setup > General at all, so redirecting them there would strand them.
    // getReturnUrl() writes the selected section into the session, so only
    // call it on the branch that actually redirects.
    if (!empty($_POST['_from_pgnsuite'])) {
        Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('sac'));
    }
    Html::back();
}

// A user with core `config` READ can reach the shared tab directly — send
// them there instead of showing a second, divergent copy of this form. Only
// a user with `plugin_sac_config` but NOT core `config` (so no route to
// Setup > General at all) sees this standalone page.
if (Session::haveRight('config', READ)) {
    Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('sac'));
}

// No option and no sub-nav: this page is not one of the menu entry's options.
Html::header(__('Security-Asset Correlation', 'sac'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'sac');

SuiteConfig::render();

Html::footer();
