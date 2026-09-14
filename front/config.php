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

            case 'entity':
                if (!ctype_digit((string) $v)) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('Invalid value for %s — expected an entity id', 'sac'), $k),
                        false,
                        ERROR
                    );
                    break;
                }
                $save[$k] = (string) (int) $v;
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
    // Both the standalone page and the shared PGN Suite tab post here; land
    // back on the tab either way (hub §5 contract) rather than Html::back(),
    // which would strand a tab-originated save on this bare page.
    Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('sac'));
}

// A user with core `config` READ can reach the shared tab directly — send
// them there instead of showing a second, divergent copy of this form. Only
// a user with `plugin_sac_config` but NOT core `config` (so no route to
// Setup > General at all) sees this standalone page.
if (Session::haveRight('config', READ)) {
    Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('sac'));
}

Html::header(__('Security Assessment', 'sac'), '', 'sac', 'setup');

SuiteConfig::render();

Html::footer();
