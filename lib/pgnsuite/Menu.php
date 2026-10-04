<?php

/**
 * PGN Suite — one shared top-level sidebar menu, with one entry per plugin
 * (like core Assets / Assistance). Its label is customisable (see label()).
 *
 * CANONICAL COPY: …/Documents/PGN GLPI Suite/shared/pgnsuite/Menu.php
 * Every participating plugin ships this file byte-identical at lib/pgnsuite/Menu.php.
 * Never edit a plugin's copy on its own — change the canonical copy, bump
 * CONTRACT_VERSION if the API changes, and redistribute (see hub file §5).
 */

namespace GlpiPlugin\Pgnsuite;

use CommonGLPI;
use Config;
use Html;
use Plugin;

final class Menu extends CommonGLPI
{
    public const CONTRACT_VERSION = 1;

    public const HOOK = 'pgnsuite_menu_entry';

    /** Internal sector key; never shown. Front controllers pass it to Html::header(). */
    public const SECTOR = 'pgnsuite';

    /** Shared with ConfigTab and AssetTab, which read the same value. */
    public const CONFIG_CONTEXT = 'pgnsuite';
    public const DEFAULT_LABEL  = 'ICT Tools';

    private const KEY_PATTERN = '/^[a-z0-9_-]+$/';

    /** @var array<string, array>|null */
    private static ?array $entries = null;

    /**
     * Call from plugin_init_<plugin>(). Several plugins registering the same sector
     * merge into one (Html::generateMenuSession() appends types[]); duplicate
     * getMenuContent() results are harmless because core merges them with +=.
     */
    public static function register(string $plugin): void
    {
        global $PLUGIN_HOOKS;

        $PLUGIN_HOOKS['menu_toadd'][$plugin][self::SECTOR] = [self::class];
    }

    /**
     * The suite label: Config context "pgnsuite", key "label", edited on
     * Setup > General > (suite tab) > General. Empty means the default.
     */
    public static function label(): string
    {
        static $label = null;
        if ($label === null) {
            $value = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['label'])['label'] ?? '';
            $label = is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT_LABEL;
        }
        return $label;
    }

    public static function getTypeName($nb = 0)
    {
        return self::label();
    }

    public static function getIcon()
    {
        return 'ti ti-apps';
    }

    /**
     * @return array|false core's multi-entries shape, or false so the sector hides
     */
    public static function getMenuContent()
    {
        $entries = self::entries();
        if ($entries === []) {
            return false;
        }

        $content = ['is_multi_entries' => true, 'title' => self::label()];
        foreach ($entries as $plugin => $entry) {
            $menu = [
                'title' => $entry['title'],
                'icon'  => $entry['icon'],
                'page'  => $entry['page'],
            ];
            if ($entry['links'] !== []) {
                $menu['links'] = $entry['links'];
            }
            if ($entry['options'] !== []) {
                $menu['options'] = array_map(
                    static fn(array $o): array => array_diff_key($o, ['subnav' => true]),
                    $entry['options']
                );
            }
            $content[$plugin] = $menu;
        }

        return $content;
    }

    /**
     * Horizontal nav between a plugin's own pages. The sidebar shows one entry per
     * plugin and the breadcrumb shows only the current page, so call this right after
     * Html::header(…, Menu::SECTOR, '<plugin>', '<option>').
     */
    public static function renderSubNav(string $plugin, ?string $current = null): void
    {
        $options = array_filter(
            self::entries()[$plugin]['options'] ?? [],
            static fn(array $o): bool => $o['subnav']
        );
        if (count($options) < 2) {
            return;
        }

        echo '<ul class="nav nav-tabs mb-3 pgnsuite-menu-subnav">';
        foreach ($options as $key => $option) {
            $is_active = $key === $current;
            echo sprintf(
                '<li class="nav-item"><a class="nav-link%s" href="%s"%s><i class="%s me-2"></i>%s</a></li>',
                $is_active ? ' active' : '',
                htmlescape(Html::getPrefixedUrl($option['page'])),
                $is_active ? ' aria-current="page"' : '',
                htmlescape($option['icon']),
                htmlescape($option['title'])
            );
        }
        echo '</ul>';
    }

    /**
     * Provider contract: "Class::method" string returning null when the current user
     * may see nothing, else
     *   ['title' => string, 'icon' => 'ti ti-…', 'page' => '/plugins/<p>/front/x.php',
     *    'order' => int, 'links' => array,
     *    'options' => [<key> => ['title', 'icon', 'page', 'links', 'lists_itemtype',
     *                            'subnav' => bool (default true)]]]
     * Options are the plugin's own pages: they feed the breadcrumb, "+ Add" and renderSubNav().
     *
     * @return array<string, array{title: string, icon: string, page: string, order: int,
     *                             links: array, options: array<string, array>}>
     */
    private static function entries(): array
    {
        global $PLUGIN_HOOKS;

        if (self::$entries !== null) {
            return self::$entries;
        }

        $entries = [];
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
            $entry = call_user_func($provider);
            if (
                !is_array($entry)
                || !is_string($entry['title'] ?? null)
                || !is_string($entry['page'] ?? null)
            ) {
                continue;
            }

            $options = [];
            foreach (is_array($entry['options'] ?? null) ? $entry['options'] : [] as $key => $option) {
                if (
                    !is_string($key)
                    || !preg_match(self::KEY_PATTERN, $key)
                    || !is_array($option)
                    || !is_string($option['title'] ?? null)
                    || !is_string($option['page'] ?? null)
                ) {
                    continue;
                }
                $options[$key] = [
                    'title'  => $option['title'],
                    'icon'   => is_string($option['icon'] ?? null) ? $option['icon'] : 'ti ti-point',
                    'page'   => $option['page'],
                    'subnav' => ($option['subnav'] ?? true) !== false,
                ];
                if (is_array($option['links'] ?? null)) {
                    $options[$key]['links'] = $option['links'];
                }
                if (is_string($option['lists_itemtype'] ?? null)) {
                    $options[$key]['lists_itemtype'] = $option['lists_itemtype'];
                }
            }

            $entries[$plugin] = [
                'title'   => $entry['title'],
                'icon'    => is_string($entry['icon'] ?? null) ? $entry['icon'] : 'ti ti-puzzle',
                'page'    => $entry['page'],
                'order'   => (int) ($entry['order'] ?? 500),
                'links'   => is_array($entry['links'] ?? null) ? $entry['links'] : [],
                'options' => $options,
            ];
        }

        uasort(
            $entries,
            static fn(array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]
        );

        return self::$entries = $entries;
    }
}
