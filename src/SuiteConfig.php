<?php

namespace GlpiPlugin\Sac;

/**
 * Provider for the shared "PGN Suite" tab on Setup > General
 * (`\GlpiPlugin\Pgnsuite\ConfigTab`, hub §5 contract) — SAC's own section
 * inside that tab.
 *
 * `render()` is also called directly by SAC's own standalone config page
 * (`front/config.php`) — per the contract, "the tab section and the page
 * call the same render function." That page stays reachable by URL for
 * users who have `plugin_sac_config` but not core `config` (so cannot reach
 * Setup > General at all); a user who DOES have core `config` READ is
 * redirected from the standalone page to the shared tab instead, so nobody
 * sees two divergent copies of the same form.
 */
final class SuiteConfig
{
    /**
     * Called by `ConfigTab::sections()` for every active plugin registered
     * on its hook. Returning null hides SAC's section entirely for a user
     * without `plugin_sac_config` READ — `ConfigTab` itself only checks core
     * `config`, so this per-plugin gate is SAC's own responsibility.
     *
     * @return array{title:string,icon:string,order:int,render:string}|null
     */
    public static function section(): ?array
    {
        if (!\Session::haveRight('plugin_sac_config', READ)) {
            return null;
        }
        return [
            'title'  => Menu::getTypeName(),
            'icon'   => Menu::getIcon(),
            'order'  => 500,
            'render' => self::class . '::renderForTab',
        ];
    }

    /**
     * Entry point for the shared PGN Suite tab specifically (the `render`
     * callback in {@see section()}) — as opposed to `front/config.php`
     * calling `render()` directly for the standalone page. See `render()`'s
     * docblock for why the distinction matters.
     */
    public static function renderForTab(): void
    {
        self::render(true);
    }

    /**
     * Echoes SAC's config form. No `Html::header()`/`Html::footer()` here —
     * both callers (the shared tab via `renderForTab()`, and
     * `front/config.php`'s own page) own that themselves.
     *
     * `$fromPgnSuite` is true only when called via `renderForTab()` — it
     * makes the form include a hidden `_from_pgnsuite` field, so
     * `front/config.php`'s POST handler knows to redirect back to the
     * shared tab (hub §5 contract) rather than `Html::back()`. That
     * distinction matters: a user with `plugin_sac_config` but not core
     * `config` READ has no route to Setup > General at all, so saving from
     * the standalone page must `Html::back()`, never redirect to the tab —
     * v1.3.0 got this wrong (redirected unconditionally), fixed in v1.3.1.
     */
    public static function render(bool $fromPgnSuite = false): void
    {
        $values = \Config::getConfigurationValues(Config::CONTEXT);

        $entityCurrent  = (int) ($values['finding_entity'] ?? Config::DEFAULTS['finding_entity']);
        $entityDropdown = \Entity::dropdown([
            'name'    => 'finding_entity',
            'value'   => $entityCurrent,
            'entity'  => $_SESSION['glpiactive_entity'] ?? 0,
            'display' => false,
        ]);

        \Glpi\Application\View\TemplateRenderer::getInstance()->display('@sac/config.html.twig', [
            'action'          => \Html::getPrefixedUrl('/plugins/sac/front/config.php'),
            'values'          => $values,
            'defaults'        => Config::DEFAULTS,
            'types'           => Config::FIELD_TYPES,
            'entity_dropdown' => $entityDropdown,
            'from_pgnsuite'   => $fromPgnSuite,
        ]);
    }

    /** @return array<string,callable():void> VM-only — touches Session/rights. */
    public static function selfChecks(): array
    {
        return [
            'db/suiteconfig-section-shape' => static function (): void {
                $section = self::section();
                if ($section === null) {
                    // Correctly gated: the selfcheck-running user genuinely
                    // lacks plugin_sac_config READ. Not a failure.
                    sac_assert(!\Session::haveRight('plugin_sac_config', READ), 'null only when the right is genuinely absent');
                    return;
                }
                sac_assert(is_string($section['title']) && $section['title'] !== '', 'title is a non-empty string');
                sac_assert(is_string($section['icon']) && $section['icon'] !== '', 'icon is a non-empty string');
                sac_assert(is_int($section['order']), 'order is an int');
                sac_assert(is_callable($section['render']), 'render is callable');
            },
        ];
    }
}
