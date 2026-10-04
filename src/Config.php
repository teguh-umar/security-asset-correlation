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
     * Types: 'bool' | 'int' | 'json' | 'text'.
     */
    public const FIELD_TYPES = [
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

            'config/field-types-cover-all-keys' => static function (): void {
                sac_assert_eq(
                    array_keys(self::FIELD_TYPES),
                    array_keys(self::DEFAULTS),
                    'FIELD_TYPES has exactly the DEFAULTS keys, in order'
                );
                $known = ['bool', 'int', 'json', 'text'];
                foreach (self::FIELD_TYPES as $k => $t) {
                    sac_assert(in_array($t, $known, true), "$k has a known field type ($t)");
                }
                sac_assert_eq(self::FIELD_TYPES['license_ignore_categories'], 'json', 'ignore categories is json');
            },
        ];
    }
}
