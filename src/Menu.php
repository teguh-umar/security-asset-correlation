<?php

namespace GlpiPlugin\Sac;

use CommonGLPI;
use Session;

class Menu extends CommonGLPI
{
    public static $rightname = 'plugin_sac_finding';

    public static function getTypeName($nb = 0): string
    {
        return __('Security Assessment', 'sac');
    }

    public static function getIcon(): string
    {
        return 'ti ti-shield-search';
    }

    public static function canView(): bool
    {
        return Session::haveRight('plugin_sac_finding', READ)
            || Session::haveRight('plugin_sac_config', READ);
    }

    /**
     * `plugin_sac_config`'s own screen no longer has a menu entry here — it
     * moved to the shared "PGN Suite" tab on Setup > General (hub §5
     * contract, `SuiteConfig`); the standalone page at front/config.php
     * still works by direct URL for a config-only user with no route to
     * Setup > General.
     *
     * @return array<string,mixed>|false
     */
    public static function getMenuContent()
    {
        $finding_read = Session::haveRight('plugin_sac_finding', READ);

        $entries = [];

        if ($finding_read) {
            $overview = '/plugins/sac/front/overview.php';
            $entries['overview'] = [
                'title' => __('Overview', 'sac'),
                'page'  => $overview,
                'icon'  => 'ti ti-shield-search',
                'links' => ['search' => $overview],
            ];
            $reconcile = '/plugins/sac/front/reconcile.php';
            $entries['reconcile'] = [
                'title' => __('Reconcile', 'sac'),
                'page'  => $reconcile,
                'icon'  => 'ti ti-git-merge',
                'links' => ['search' => $reconcile],
            ];
            $runs = '/plugins/sac/front/run.php';
            $entries['runs'] = [
                'title' => __('Scan runs', 'sac'),
                'page'  => $runs,
                'icon'  => 'ti ti-history',
                'links' => ['search' => $runs],
            ];
        }

        if ($entries === []) {
            return false;
        }

        return ['is_multi_entries' => true, 'title' => self::getTypeName()] + $entries;
    }
}
