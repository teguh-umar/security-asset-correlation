<?php

/**
 * PGN Suite — shared "PGN Suite" tab on Setup > General.
 *
 * CANONICAL COPY: …/Documents/PGN GLPI Suite/shared/pgnsuite/ConfigTab.php
 * Every PGN plugin ships this file byte-identical at lib/pgnsuite/ConfigTab.php.
 * Never edit a plugin's copy on its own — change the canonical copy, bump
 * CONTRACT_VERSION, and redistribute to every plugin (see hub file §5).
 */

namespace GlpiPlugin\Pgnsuite;

use CommonGLPI;
use Config;
use Plugin;

final class ConfigTab extends CommonGLPI
{
    public const CONTRACT_VERSION = 1;

    public const HOOK = 'pgnsuite_config_section';

    private const SESSION_KEY = 'pgnsuite_active_section';

    public static function register(): void
    {
        // registerStandardTab() keys tabs by class name, so repeated calls from
        // several plugins still yield exactly one tab.
        Plugin::registerClass(self::class, ['addtabon' => Config::class]);
    }

    public static function getTypeName($nb = 0)
    {
        return 'PGN Suite';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Config || self::sections() === []) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(), 0, null, 'ti ti-adjustments-alt');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Config) {
            return false;
        }
        $sections = self::sections();
        if ($sections === []) {
            return false;
        }

        $active = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!isset($sections[$active])) {
            $active = array_key_first($sections);
        }

        // Not .nav-pills: its primary-tinted active state (#FEC95C on the default palette)
        // is unreadable. Core's --glpi-tabs-* variables match GLPI's own vertical tabs in
        // every palette; the active marker uses the active text color, which each
        // palette pairs with its active background.
        echo '<style>'
            . '.pgnsuite-subnav .nav-link{background:var(--glpi-tabs-bg);color:var(--glpi-tabs-fg);'
            . 'border:1px solid var(--glpi-tabs-border-color);border-left:5px solid transparent;'
            . 'border-radius:0;margin-bottom:-1px;text-align:left}'
            . '.pgnsuite-subnav .nav-link:hover{color:var(--glpi-tabs-active-fg)}'
            . '.pgnsuite-subnav .nav-link.active{background:var(--glpi-tabs-active-bg);'
            . 'color:var(--glpi-tabs-active-fg);border-left-color:var(--glpi-tabs-active-fg);font-weight:bold}'
            . '</style>';
        echo '<div class="row g-3 pgnsuite-config">';
        echo '<div class="col-12 col-md-3"><div class="nav flex-column pgnsuite-subnav" role="tablist">';
        foreach ($sections as $key => $section) {
            $is_active = $key === $active;
            echo sprintf(
                '<button type="button" class="nav-link%s" data-bs-toggle="pill" data-bs-target="#pgnsuite-%s" role="tab" aria-selected="%s"><i class="%s me-2"></i>%s</button>',
                $is_active ? ' active' : '',
                htmlescape($key),
                $is_active ? 'true' : 'false',
                htmlescape($section['icon']),
                htmlescape($section['title'])
            );
        }
        echo '</div></div>';

        echo '<div class="col-12 col-md-9"><div class="tab-content">';
        foreach ($sections as $key => $section) {
            echo sprintf(
                '<div class="tab-pane fade%s" id="pgnsuite-%s" role="tabpanel">',
                $key === $active ? ' show active' : '',
                htmlescape($key)
            );
            call_user_func($section['render']);
            echo '</div>';
        }
        echo '</div></div></div>';

        return true;
    }

    /**
     * URL a plugin's config controller redirects to after saving, landing back
     * on this tab with the plugin's own section selected.
     */
    public static function getReturnUrl(string $section): string
    {
        $_SESSION[self::SESSION_KEY] = $section;
        return Config::getFormURL() . '?forcetab=' . urlencode(self::class . '$1');
    }

    /**
     * @return array<string, array{title: string, icon: string, order: int, render: string}>
     */
    private static function sections(): array
    {
        global $PLUGIN_HOOKS;

        $sections = [];
        foreach ($PLUGIN_HOOKS[self::HOOK] ?? [] as $plugin => $provider) {
            if (
                !is_string($plugin)
                || !preg_match('/^[a-z0-9]+$/', $plugin)
                || !is_string($provider)
                || !Plugin::isPluginActive($plugin)
                || !is_callable($provider)
            ) {
                continue;
            }
            // A provider returns null when the current user may not see its section.
            $section = call_user_func($provider);
            if (
                !is_array($section)
                || !is_string($section['title'] ?? null)
                || !is_string($section['render'] ?? null)
                || !is_callable($section['render'])
            ) {
                continue;
            }
            $sections[$plugin] = [
                'title'  => $section['title'],
                'icon'   => is_string($section['icon'] ?? null) ? $section['icon'] : 'ti ti-settings',
                'order'  => (int) ($section['order'] ?? 500),
                'render' => $section['render'],
            ];
        }

        uasort(
            $sections,
            static fn(array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]
        );

        return $sections;
    }
}
