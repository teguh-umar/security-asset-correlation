<?php

use GlpiPlugin\Sac\License\LicenseRule;
use GlpiPlugin\Sac\License\RetirePublisherFindings;
use GlpiPlugin\Sac\Migration as SacMigration;
use GlpiPlugin\Sac\RightDefinition;
use GlpiPlugin\Sac\RunLog;

function plugin_sac_install(): bool
{
    SacMigration::install();
    RightDefinition::addOnInstall();

    // Baseline licence rules, on every install/upgrade. Idempotent: an existing
    // (entity 0, match_on, pattern) row is never rewritten, so an admin's edit
    // survives. Logged, not fatal — the tables are already in place, and the
    // next install simply retries.
    try {
        LicenseRule::seedFromCsv();
    } catch (\Throwable $e) {
        \Toolbox::logInFile('sac', 'LicenseRule::seedFromCsv: ' . $e->getMessage() . "\n");
    }

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
