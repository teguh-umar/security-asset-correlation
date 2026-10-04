<?php

namespace GlpiPlugin\Sac;

final class Config
{
    public const CONTEXT = 'plugin:sac';

    /**
     * Keys removed in v1.5.0: a finding now lives in the entity of its
     * assets and is never recursive, so there is nothing left to configure.
     * `Migration::install()` deletes any stored value.
     */
    public const REMOVED = ['finding_entity', 'finding_recursive'];

    public const DEFAULTS = [
        'scan_entities'                => '[]',
        'scan_entities_recursive'      => 1,
        'eol_scan_enabled'             => 1,
        'eol_horizon_days'             => 365,
        'eol_severity_high_days'       => 90,
        'eol_severity_medium_days'     => 180,
        'eol_hardware_enabled'         => 1,
        'eol_hardware_horizon_days'    => 180,
        'eol_cache_max_age_days'       => 180,
        'license_scan_enabled'         => 1,
        'license_check_overdeployment' => 0,
        'license_ignore_categories'    => '["system_component"]',
        'license_2a_products_threshold'=> 5,
        'license_2a_installs_threshold'=> 20,
    ];

    /**
     * Explicit per-key widget/validation type for every DEFAULTS key. Drives the
     * config template (checkbox vs number vs entity dropdown vs JSON/text) and
     * front/config.php's POST validation. Never infer the widget from the default
     * value — an int `0` default is not a boolean.
     *
     * Types: 'bool' | 'int' | 'json' | 'entities' | 'text'. 'entities' is a
     * JSON array of entity ids, edited with a multiple Entity dropdown.
     */
    public const FIELD_TYPES = [
        'scan_entities'                => 'entities',
        'scan_entities_recursive'      => 'bool',
        'eol_scan_enabled'             => 'bool',
        'eol_horizon_days'             => 'int',
        'eol_severity_high_days'       => 'int',
        'eol_severity_medium_days'     => 'int',
        'eol_hardware_enabled'         => 'bool',
        'eol_hardware_horizon_days'    => 'int',
        'eol_cache_max_age_days'       => 'int',
        'license_scan_enabled'         => 'bool',
        'license_check_overdeployment' => 'bool',
        'license_ignore_categories'    => 'json',
        'license_2a_products_threshold'=> 'int',
        'license_2a_installs_threshold'=> 'int',
    ];

    public static function get(string $key): mixed
    {
        $conf = \Config::getConfigurationValues(self::CONTEXT, [$key]);
        return $conf[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function ignoreCategories(): array
    {
        $raw = self::get('license_ignore_categories');
        $arr = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
    }

    /**
     * The stored `scan_entities` selection, as entity ids.
     *
     * @return array<int,int>
     */
    public static function scanEntities(): array
    {
        $raw = self::get('scan_entities');
        $arr = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($arr) ? array_values(array_unique(array_map('intval', $arr))) : [];
    }

    /**
     * Entities the scans cover: null = every entity (nothing selected), else
     * the selected entities, plus their child entities when
     * `scan_entities_recursive` is on. The scans themselves stay instance-wide
     * cron jobs; this only decides whose assets they look at.
     *
     * @return array<int,int>|null
     */
    public static function scanScope(): ?array
    {
        $ids = self::scanEntities();
        if ($ids === []) {
            return null;
        }
        if (self::get('scan_entities_recursive')) {
            $all = array_flip($ids);
            foreach ($ids as $id) {
                foreach (getSonsOf('glpi_entities', $id) as $son) {
                    $all[(int) $son] = true;
                }
            }
            $ids = array_keys($all);
        }
        sort($ids);
        return $ids;
    }

    /**
     * Setup-page layout: sections, and for each setting its label, hint and
     * unit. Every DEFAULTS key appears exactly once (selfcheck below).
     *
     * @return array<int,array{title:string,icon:string,hint:string,fields:array<string,array{label:string,hint:string,unit?:string}>}>
     */
    public static function sections(): array
    {
        $days = __('days', 'sac');
        return [
            [
                'title'  => __('Scan scope', 'sac'),
                'icon'   => 'ti ti-building-community',
                'hint'   => __('The scans run for the whole GLPI. A finding is always written in the entity of the affected assets and is never shared with child entities.', 'sac'),
                'fields' => [
                    'scan_entities' => [
                        'label' => __('Entities to scan', 'sac'),
                        'hint'  => __('Leave empty to scan every entity. Findings of an entity that is no longer scanned are kept as they are.', 'sac'),
                    ],
                    'scan_entities_recursive' => [
                        'label' => __('Include their child entities', 'sac'),
                        'hint'  => '',
                    ],
                ],
            ],
            [
                'title'  => __('End of life (EOL / EOS)', 'sac'),
                'icon'   => 'ti ti-hourglass-low',
                'hint'   => __('Operating systems and hardware warranty reaching their end of life.', 'sac'),
                'fields' => [
                    'eol_scan_enabled' => [
                        'label' => __('Scan operating systems', 'sac'),
                        'hint'  => '',
                    ],
                    'eol_hardware_enabled' => [
                        'label' => __('Scan hardware warranty', 'sac'),
                        'hint'  => '',
                    ],
                    'eol_horizon_days' => [
                        'label' => __('Report an operating system this long before its end of life', 'sac'),
                        'hint'  => '',
                        'unit'  => $days,
                    ],
                    'eol_hardware_horizon_days' => [
                        'label' => __('Report hardware this long before its warranty ends', 'sac'),
                        'hint'  => '',
                        'unit'  => $days,
                    ],
                    'eol_severity_high_days' => [
                        'label' => __('Severity High when fewer than', 'sac'),
                        'hint'  => __('Past end of life is always Critical.', 'sac'),
                        'unit'  => $days,
                    ],
                    'eol_severity_medium_days' => [
                        'label' => __('Severity Medium when fewer than', 'sac'),
                        'hint'  => __('Anything later, within the reporting window, is Low.', 'sac'),
                        'unit'  => $days,
                    ],
                    'eol_cache_max_age_days' => [
                        'label' => __('Refresh endoflife.date data older than', 'sac'),
                        'hint'  => '',
                        'unit'  => $days,
                    ],
                ],
            ],
            [
                'title'  => __('License governance', 'sac'),
                'icon'   => 'ti ti-license',
                'hint'   => __('A licence covers an entity when that entity owns it, or a parent entity owns it with "child entities" set to yes.', 'sac'),
                'fields' => [
                    'license_scan_enabled' => [
                        'label' => __('Scan software licences', 'sac'),
                        'hint'  => __('Reports commercial software installed with no licence recorded.', 'sac'),
                    ],
                    'license_check_overdeployment' => [
                        'label' => __('Also report over-deployment', 'sac'),
                        'hint'  => __('More installs than licensed seats.', 'sac'),
                    ],
                    'license_2a_products_threshold' => [
                        'label' => __('Unlicensed software is Medium above', 'sac'),
                        'hint'  => __('Per publisher and entity; otherwise Low.', 'sac'),
                        'unit'  => __('products', 'sac'),
                    ],
                    'license_2a_installs_threshold' => [
                        'label' => __('…or above', 'sac'),
                        'hint'  => '',
                        'unit'  => __('installs', 'sac'),
                    ],
                    'license_ignore_categories' => [
                        'label' => __('Software categories to ignore', 'sac'),
                        'hint'  => __('A JSON array of category names, e.g. ["system_component"].', 'sac'),
                    ],
                ],
            ],
        ];
    }

    public static function setDefaults(): void
    {
        $existing = \Config::getConfigurationValues(self::CONTEXT);
        $missing  = array_diff_key(self::DEFAULTS, $existing);
        if ($missing !== []) {
            \Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    public static function selfChecks(): array
    {
        return [
            'config/defaults-shape' => static function (): void {
                sac_assert_eq(array_intersect(self::REMOVED, array_keys(self::DEFAULTS)), [], 'no removed key is still a setting');
                sac_assert_eq(self::DEFAULTS['license_check_overdeployment'], 0, '2b off by default');
                $cats = json_decode(self::DEFAULTS['license_ignore_categories'], true);
                sac_assert_eq($cats, ['system_component'], 'ignore cats seed');
            },

            // VM-only: sections() calls __().
            'db/config-sections-cover-all-keys' => static function (): void {
                $keys = [];
                foreach (self::sections() as $section) {
                    foreach (array_keys($section['fields']) as $key) {
                        $keys[] = $key;
                    }
                }
                $expected = array_keys(self::DEFAULTS);
                sort($keys);
                sort($expected);
                sac_assert_eq($keys, $expected, 'every setting is on the Setup page exactly once');
            },

            'config/field-types-cover-all-keys' => static function (): void {
                sac_assert_eq(
                    array_keys(self::FIELD_TYPES),
                    array_keys(self::DEFAULTS),
                    'FIELD_TYPES has exactly the DEFAULTS keys, in order'
                );
                $known = ['bool', 'int', 'json', 'entities', 'text'];
                foreach (self::FIELD_TYPES as $k => $t) {
                    sac_assert(in_array($t, $known, true), "$k has a known field type ($t)");
                }
                sac_assert_eq(self::FIELD_TYPES['license_ignore_categories'], 'json', 'ignore categories is json');
            },
        ];
    }
}
