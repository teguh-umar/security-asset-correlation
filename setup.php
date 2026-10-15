<?php

use GlpiPlugin\Sac\Menu;
use GlpiPlugin\Sac\Profile as SacProfile;
use GlpiPlugin\Sac\SuiteConfig;

define('PLUGIN_SAC_VERSION', '1.7.1');
define('PLUGIN_SAC_MIN_GLPI', '11.0.0');
define('PLUGIN_SAC_MAX_GLPI', '11.9.99');

function plugin_version_sac(): array
{
    return [
        'name'         => 'Security-Asset Correlation',
        'version'      => PLUGIN_SAC_VERSION,
        'author'       => 'ICT',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_SAC_MIN_GLPI, 'max' => PLUGIN_SAC_MAX_GLPI],
        ],
    ];
}

function plugin_sac_check_prerequisites(): bool
{
    return true; // version gate handled by 'requirements'
}

function plugin_sac_check_config($verbose = false): bool
{
    return true;
}

function plugin_init_sac(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['sac'] = true;

    // One entry in the shared suite sidebar sector (hub §5 contract). Same
    // byte-identical-copy rule as ConfigTab below; class_exists(…, false)
    // skips the autoloader, which would look for a nonexistent plugins/pgnsuite/.
    if (!class_exists(\GlpiPlugin\Pgnsuite\Menu::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/Menu.php';
    }
    \GlpiPlugin\Pgnsuite\Menu::register('sac');
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\Menu::HOOK]['sac'] = Menu::class . '::entry';

    // Rights tab on Profile.
    Plugin::registerClass(SacProfile::class, ['addtabon' => ['Profile']]);

    // Shared suite tab on Setup > General (hub §5 contract). The class
    // is a byte-identical copy of the canonical file (SHA-256 verified before
    // committing) — never edit lib/pgnsuite/ConfigTab.php on its own; changes
    // go through the canonical copy and get redistributed.
    if (!class_exists(\GlpiPlugin\Pgnsuite\ConfigTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/ConfigTab.php';
    }
    \GlpiPlugin\Pgnsuite\ConfigTab::register();
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\ConfigTab::HOOK]['sac'] = SuiteConfig::class . '::section';

    // Cron tasks are registered in plugin_sac_install(); nothing needed here.
}
