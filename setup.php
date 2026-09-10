<?php

use GlpiPlugin\Sac\Menu;
use GlpiPlugin\Sac\Profile as SacProfile;

define('PLUGIN_SAC_VERSION', '1.0.2');
define('PLUGIN_SAC_MIN_GLPI', '11.0.0');
define('PLUGIN_SAC_MAX_GLPI', '11.9.99');

function plugin_version_sac(): array
{
    return [
        'name'         => 'Security-Asset Correlation',
        'version'      => PLUGIN_SAC_VERSION,
        'author'       => 'PGN ICT',
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

    // Own top-level sidebar sector (array-of-classes = new sector).
    $PLUGIN_HOOKS['menu_toadd']['sac'] = ['sac' => [Menu::class]];

    // Rights tab on Profile.
    Plugin::registerClass(SacProfile::class, ['addtabon' => ['Profile']]);

    // Cron tasks are registered in plugin_sac_install(); nothing needed here.
}
