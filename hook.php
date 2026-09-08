<?php

use GlpiPlugin\Sac\Migration as SacMigration;
use GlpiPlugin\Sac\RightDefinition;

function plugin_sac_install(): bool
{
    SacMigration::install();
    RightDefinition::addOnInstall();
    return true;
}

function plugin_sac_uninstall(): bool
{
    RightDefinition::removeOnUninstall();
    SacMigration::uninstall();
    return true;
}

function plugin_sac_getDropdown(): array
{
    return [];
}
