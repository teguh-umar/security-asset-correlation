<?php

use GlpiPlugin\Sac\License\RetirePublisherFindings;
use GlpiPlugin\Sac\Migration as SacMigration;
use GlpiPlugin\Sac\RightDefinition;
use GlpiPlugin\Sac\RunLog;

function plugin_sac_install(): bool
{
    SacMigration::install();
    RightDefinition::addOnInstall();

    // One-shot, marker-guarded: runs on every upgrade, does work only once.
    // A failure must not abort the upgrade — the marker was not written, so the
    // next install retries — but it must be visible in the run log.
    $run = RunLog::begin('license');
    try {
        $closed = RetirePublisherFindings::run($run);
        $run->finish('ok', "retired $closed per-publisher licence Finding(s)");
    } catch (\Throwable $e) {
        $run->bump('errors');
        $run->finish('error', $e->getMessage());
        \Toolbox::logInFile('sac', 'RetirePublisherFindings: ' . $e->getMessage() . "\n");
    }

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
