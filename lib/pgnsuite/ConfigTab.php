<?php

/**
 * PGN Suite — shared suite tab on Setup > General. Its title is the customisable
 * suite label (default "ICT Tools"), edited in this tab's own "General" section.
 *
 * CANONICAL COPY: …/Documents/PGN GLPI Suite/shared/pgnsuite/ConfigTab.php
 * Every PGN plugin ships this file byte-identical at lib/pgnsuite/ConfigTab.php.
 * Never edit a plugin's copy on its own — change the canonical copy, bump
 * CONTRACT_VERSION, and redistribute to every plugin (see hub file §5).
 */

namespace GlpiPlugin\Pgnsuite;

use CommonGLPI;
use Config;
use Html;
use Plugin;
use Session;

final class ConfigTab extends CommonGLPI
{
    public const CONTRACT_VERSION = 1;

    public const HOOK = 'pgnsuite_config_section';

    /** Same context, key and default as Menu and AssetTab (each reads it itself). */
    public const CONFIG_CONTEXT = 'pgnsuite';
    public const DEFAULT_LABEL  = 'ICT Tools';
    public const LABEL_MAX      = 40;

    private const SESSION_KEY = 'pgnsuite_active_section';

    /** Not a valid plugin key (those match /^[a-z0-9]+$/), so it never collides. */
    private const GENERAL = '_general';

    public static function register(): void
    {
        // registerStandardTab() keys tabs by class name, so repeated calls from
        // several plugins still yield exactly one tab.
        Plugin::registerClass(self::class, ['addtabon' => Config::class]);
    }

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

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Config || self::allSections() === []) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(), 0, null, 'ti ti-adjustments-alt');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Config) {
            return false;
        }
        $sections = self::allSections();
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
            // The sub-nav is as wide as its longest title (capped), not a quarter of the page.
            . '.pgnsuite-config-nav{flex:0 0 auto}'
            . '.pgnsuite-config-nav .nav-link{white-space:nowrap}'
            . '@media (min-width:768px){.pgnsuite-config-nav{width:max-content;max-width:16rem}'
            . '.pgnsuite-config-nav .nav-link{overflow:hidden;text-overflow:ellipsis}}'
            . '.pgnsuite-config-body{flex:1 1 auto;min-width:0}'
            . '</style>';
        echo '<div class="d-flex flex-column flex-md-row gap-3 pgnsuite-config">';
        echo '<div class="pgnsuite-config-nav"><div class="nav flex-column pgnsuite-subnav" role="tablist">';
        foreach ($sections as $key => $section) {
            $is_active = $key === $active;
            echo sprintf(
                '<button type="button" class="nav-link%s" data-bs-toggle="pill" data-bs-target="#pgnsuite-%s" role="tab" aria-selected="%s" title="%s"><i class="%s me-2"></i>%s</button>',
                $is_active ? ' active' : '',
                htmlescape($key),
                $is_active ? 'true' : 'false',
                htmlescape($section['title']),
                htmlescape($section['icon']),
                htmlescape($section['title'])
            );
        }
        echo '</div></div>';

        echo '<div class="pgnsuite-config-body"><div class="tab-content">';
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
     * The suite's own "General" section: the label form. Posts to core
     * config.form.php with config_context, which saves through configUpdate() below.
     */
    public static function renderGeneral(): void
    {
        $stored = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['label'])['label'] ?? '';

        echo '<form method="post" action="' . htmlescape(Config::getFormURL()) . '">';
        echo '<div class="card"><div class="card-body">';
        echo '<div class="mb-3" style="max-width:32rem">';
        echo '<label class="form-label" for="pgnsuite-label">' . htmlescape(__('Name')) . '</label>';
        echo sprintf(
            '<input type="text" class="form-control" id="pgnsuite-label" name="label" value="%s" maxlength="%d" placeholder="%s">',
            htmlescape(is_string($stored) ? $stored : ''),
            self::LABEL_MAX,
            htmlescape(self::DEFAULT_LABEL)
        );
        echo '<div class="form-text">'
            . htmlescape(sprintf(
                'Shown as the sidebar menu, this tab and the asset tab. Leave empty for "%s". '
                . 'Other users see a new name after they log in again.',
                self::DEFAULT_LABEL
            ))
            . '</div>';
        echo '</div>';
        echo Html::hidden('config_context', ['value' => self::CONFIG_CONTEXT]);
        echo Html::hidden('config_class', ['value' => self::class]);
        echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        echo '</div></div>';
        Html::closeForm();
    }

    /**
     * Called by core Config::prepareInputForUpdate() (config_class). Its return value
     * is exactly what gets stored, so only "label" can be written through this form.
     * core config.form.php checks only config READ, so UPDATE is enforced here.
     */
    public static function configUpdate(array $input): array
    {
        if (!Session::haveRight('config', UPDATE)) {
            return [];
        }
        $label = preg_replace('/[\p{C}]+/u', '', (string) ($input['label'] ?? ''));
        $label = trim(preg_replace('/\s+/u', ' ', (string) $label) ?? '');
        $label = mb_substr($label, 0, self::LABEL_MAX);

        // The sidebar is cached per session: rebuild it for this admin now.
        unset($_SESSION['glpimenu']);
        $_SESSION[self::SESSION_KEY] = self::GENERAL;

        return ['label' => $label];
    }

    private static function canEditLabel(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /**
     * Plugin sections plus, for core config UPDATE holders, the suite's General section.
     *
     * @return array<string, array{title: string, icon: string, order: int, render: string}>
     */
    private static function allSections(): array
    {
        $sections = self::sections();
        if ($sections !== [] && self::canEditLabel()) {
            $sections[self::GENERAL] = [
                'title'  => __('General'),
                'icon'   => 'ti ti-tag',
                'order'  => PHP_INT_MAX,
                'render' => self::class . '::renderGeneral',
            ];
        }
        return $sections;
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
