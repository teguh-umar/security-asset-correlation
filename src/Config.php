<?php

namespace GlpiPlugin\Sac;

final class Config
{
    public const CONTEXT = 'plugin:sac';

    public const DEFAULTS = [
        'finding_entity'                => 0,
        'finding_recursive'             => 1,
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
                sac_assert_eq(self::DEFAULTS['finding_entity'], 0, 'root entity default');
                sac_assert_eq(self::DEFAULTS['license_check_overdeployment'], 0, '2b off by default');
                $cats = json_decode(self::DEFAULTS['license_ignore_categories'], true);
                sac_assert_eq($cats, ['system_component'], 'ignore cats seed');
            },
        ];
    }
}
