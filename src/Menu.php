<?php

namespace GlpiPlugin\Sac;

use Session;

/**
 * SAC's entry in the shared suite sidebar sector
 * (`\GlpiPlugin\Pgnsuite\Menu`, hub §5 contract). Until v1.3.6 this class
 * was SAC's own top-level sector; the sector now belongs to the shared class
 * and this one only provides the entry.
 */
final class Menu
{
    /** Position among the suite's entries (allocated in the hub). */
    public const ORDER = 40;

    public static function getTypeName($nb = 0): string
    {
        return __('Security Assessment', 'sac');
    }

    public static function getIcon(): string
    {
        return 'ti ti-shield-search';
    }

    /**
     * Provider for `Pgnsuite\Menu::HOOK`. Null hides the entry: every page
     * listed here needs `plugin_sac_finding` READ, so a user without it has
     * nothing to open.
     *
     * `plugin_sac_config`'s own screen has no option here — it lives on the
     * shared suite tab on Setup > General (`SuiteConfig`); the standalone
     * page at front/config.php still works by direct URL for a config-only
     * user with no route to Setup > General.
     *
     * @return array<string,mixed>|null
     */
    public static function entry(): ?array
    {
        if (!Session::haveRight('plugin_sac_finding', READ)) {
            return null;
        }

        $overview   = '/plugins/sac/front/overview.php';
        $compliance = '/plugins/sac/front/compliance.php';
        $rules      = '/plugins/sac/front/licenserules.php';
        $reconcile  = '/plugins/sac/front/reconcile.php';
        $runs       = '/plugins/sac/front/run.php';

        return [
            'title'   => self::getTypeName(),
            'icon'    => self::getIcon(),
            'page'    => $overview,
            'order'   => self::ORDER,
            'options' => [
                'overview' => [
                    'title' => __('Overview', 'sac'),
                    'page'  => $overview,
                    'icon'  => 'ti ti-shield-search',
                    'links' => ['search' => $overview],
                ],
                'compliance' => [
                    'title' => __('Config compliance', 'sac'),
                    'page'  => $compliance,
                    'icon'  => 'ti ti-shield-check',
                    'links' => ['search' => $compliance],
                ],
                'licenserules' => [
                    'title' => __('License rules', 'sac'),
                    'page'  => $rules,
                    'icon'  => 'ti ti-license',
                    'links' => ['search' => $rules],
                ],
                'reconcile' => [
                    'title' => __('Reconcile', 'sac'),
                    'page'  => $reconcile,
                    'icon'  => 'ti ti-git-merge',
                    'links' => ['search' => $reconcile],
                ],
                'runs' => [
                    'title' => __('Scan runs', 'sac'),
                    'page'  => $runs,
                    'icon'  => 'ti ti-history',
                    'links' => ['search' => $runs],
                ],
            ],
        ];
    }

    /** @return array<string,callable():void> VM-only — touches Session/rights. */
    public static function selfChecks(): array
    {
        return [
            'db/menu-entry-shape' => static function (): void {
                $entry = self::entry();
                if ($entry === null) {
                    sac_assert(!Session::haveRight('plugin_sac_finding', READ), 'null only when the right is genuinely absent');
                    return;
                }
                sac_assert_eq($entry['order'], self::ORDER, 'suite order');
                sac_assert_eq(array_keys($entry['options']), ['overview', 'compliance', 'licenserules', 'reconcile', 'runs'], 'option keys');
                sac_assert_eq($entry['page'], $entry['options']['overview']['page'], 'entry opens the first option');
                sac_assert(($entry['options']['licenserules']['title'] ?? '') !== '', 'the rules entry has a title');
                sac_assert_eq(
                    $entry['options']['licenserules']['page'] ?? '',
                    '/plugins/sac/front/licenserules.php',
                    'the rules entry points at its page'
                );
                foreach ($entry['options'] as $key => $option) {
                    sac_assert(is_file(dirname(__DIR__) . substr($option['page'], strlen('/plugins/sac'))), "$key page exists");
                }
            },
        ];
    }
}
