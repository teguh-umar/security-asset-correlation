<?php

namespace GlpiPlugin\Sac;

final class Config
{
    public const CONTEXT = 'plugin:sac';

    /**
     * Keys removed in v1.5.0 (a finding now lives in the entity of its
     * assets and is never recursive, so there is nothing left to configure)
     * and v1.7.1 (`compliance_collect_name` assumed GLPI Inventory's
     * Collect feature could capture arbitrary command output; VM
     * verification showed it can't — Module 3 Tier 1 now pulls over SSH
     * instead, see the `compliance_ssh_*` keys below).
     * `Migration::install()` deletes any stored value.
     */
    public const REMOVED = [
        'finding_entity',
        'finding_recursive',
        'compliance_collect_name',
        // Superseded by license_missing_severity when Module 2 moved from one
        // Finding per publisher to one Finding per software.
        'license_2a_products_threshold',
        'license_2a_installs_threshold',
    ];

    public const DEFAULTS = [
        'scan_entities'                => '[]',
        'scan_entities_recursive'      => 1,
        'eol_scan_enabled'             => 1,
        'eol_horizon_days'             => 365,
        'eol_severity_high_days'       => 90,
        'eol_severity_medium_days'     => 180,
        'eol_hardware_enabled'         => 1,
        'eol_hardware_horizon_days'    => 180,
        'eol_cache_refresh_enabled'    => 1,
        'eol_cache_max_age_days'       => 180,
        'license_scan_enabled'         => 1,
        'license_check_overdeployment' => 0,
        'license_ignore_categories'    => '["system_component"]',
        'license_missing_severity'     => 'Medium',
        'compliance_scan_enabled'      => 1,
        'compliance_ssh_key_path'      => '/etc/sac-collect/sac_collect_key',
        'compliance_ssh_known_hosts_path' => '/etc/sac-collect/known_hosts',
        'compliance_remote_summary_path'  => '/var/lib/sac/oscap_summary.txt',
        'compliance_ssh_timeout_seconds'  => 15,
        'compliance_score_critical'    => 50,
        'compliance_score_high'        => 70,
        'compliance_score_medium'      => 85,
        // 45, not 14: config-compliance scanning is monthly on both sides
        // (host cron and ConfigScan), so a freshly-pulled file is routinely
        // ~30 days old. A 14-day threshold would mark every host STALE
        // permanently. Fresh installs only — Config::setDefaults() never
        // overwrites a value an existing instance already stored.
        'compliance_stale_days'        => 45,
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
        'eol_cache_refresh_enabled'    => 'bool',
        'eol_cache_max_age_days'       => 'int',
        'license_scan_enabled'         => 'bool',
        'license_check_overdeployment' => 'bool',
        'license_ignore_categories'    => 'softwarecategories',
        'license_missing_severity'     => 'text',
        'compliance_scan_enabled'      => 'bool',
        'compliance_ssh_key_path'      => 'text',
        'compliance_ssh_known_hosts_path' => 'text',
        'compliance_remote_summary_path'  => 'text',
        'compliance_ssh_timeout_seconds'  => 'int',
        'compliance_score_critical'    => 'int',
        'compliance_score_high'        => 'int',
        'compliance_score_medium'      => 'int',
        'compliance_stale_days'        => 'int',
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
                    'eol_cache_refresh_enabled' => [
                        'label' => __('Refresh endoflife.date data', 'sac'),
                        'hint'  => __('Off = the scans use the data already stored and never call endoflife.date.', 'sac'),
                    ],
                    'eol_cache_max_age_days' => [
                        'label' => __('Refresh data older than', 'sac'),
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
                        'hint'  => __('Reports software a classification rule says needs a licence, installed with no licence recorded.', 'sac'),
                    ],
                    'license_check_overdeployment' => [
                        'label' => __('Also report over-deployment', 'sac'),
                        'hint'  => __('More installs than licensed seats.', 'sac'),
                    ],
                    'license_missing_severity' => [
                        'label' => __('Severity of a licence-required finding', 'sac'),
                        'hint'  => __('Critical, High, Medium or Low. Applies to software a classification rule says needs a licence and that has none recorded.', 'sac'),
                    ],
                    'license_ignore_categories' => [
                        'label' => __('Software categories to ignore', 'sac'),
                        'hint'  => __('Pick from the software categories defined in GLPI. Software in these categories is not checked.', 'sac'),
                    ],
                ],
            ],
            [
                'title'  => __('Config compliance (OpenSCAP Tier 1)', 'sac'),
                'icon'   => 'ti ti-shield-check',
                'hint'   => __('Pulls the one-line oscap summary from each registered host over a restricted, read-only SSH connection. Register hosts under Config compliance - SSH targets. Does not import per-rule results — that is a future phase.', 'sac'),
                'fields' => [
                    'compliance_scan_enabled' => [
                        'label' => __('Scan registered SSH targets', 'sac'),
                        'hint'  => '',
                    ],
                    'compliance_ssh_key_path' => [
                        'label' => __('SSH private key path', 'sac'),
                        'hint'  => __('On this GLPI host. Must be readable only by the user the cron runs as.', 'sac'),
                    ],
                    'compliance_ssh_known_hosts_path' => [
                        'label' => __('SSH known_hosts path', 'sac'),
                        'hint'  => __('Pre-populated with ssh-keyscan for every registered host, so the cron never blocks on a host-key prompt.', 'sac'),
                    ],
                    'compliance_remote_summary_path' => [
                        'label' => __('Remote summary file path', 'sac'),
                        'hint'  => __('Must match the path the host\'s oscap cron wrapper writes to, and the forced-command in its authorized_keys entry.', 'sac'),
                    ],
                    'compliance_ssh_timeout_seconds' => [
                        'label' => __('SSH timeout', 'sac'),
                        'hint'  => '',
                        'unit'  => __('seconds', 'sac'),
                    ],
                    'compliance_score_critical' => [
                        'label' => __('Severity Critical below', 'sac'),
                        'hint'  => '',
                        'unit'  => '%',
                    ],
                    'compliance_score_high' => [
                        'label' => __('Severity High below', 'sac'),
                        'hint'  => '',
                        'unit'  => '%',
                    ],
                    'compliance_score_medium' => [
                        'label' => __('Severity Medium below', 'sac'),
                        'hint'  => __('Anything at or above this, below 100%, is Low. 100% exactly clears the finding.', 'sac'),
                        'unit'  => '%',
                    ],
                    'compliance_stale_days' => [
                        'label' => __('Flag a host as stale after', 'sac'),
                        'hint'  => __('No fresher Collect report in this many days.', 'sac'),
                        'unit'  => __('days', 'sac'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Names of every software category in GLPI, for the ignore pick-list.
     * The stored value is the name, because LicenseScan matches on the name.
     *
     * @return array<int,string>
     */
    public static function softwareCategoryNames(): array
    {
        global $DB;
        $names = [];
        foreach ($DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_softwarecategories',
            'ORDER'  => ['name'],
        ]) as $row) {
            if ((string) $row['name'] !== '') {
                $names[] = (string) $row['name'];
            }
        }
        return array_values(array_unique($names));
    }

    public static function setDefaults(): void
    {
        $existing = \Config::getConfigurationValues(self::CONTEXT);
        $missing  = array_diff_key(self::DEFAULTS, $existing);
        if ($missing !== []) {
            \Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    /**
     * A one-shot install marker (absent = never done).
     *
     * Deliberately NOT in DEFAULTS/FIELD_TYPES: those are user settings, and
     * `db/config-sections-cover-all-keys` requires every one of them to be
     * rendered on the Setup page. Internal state has no widget and must not
     * pretend to have one.
     */
    public static function marker(string $key): bool
    {
        $conf = \Config::getConfigurationValues(self::CONTEXT, [$key]);
        return !empty($conf[$key]);
    }

    /** Set a one-shot install marker. Absence/0 means "not done, retry". */
    public static function markDone(string $key): void
    {
        \Config::setConfigurationValues(self::CONTEXT, [$key => 1]);
    }

    public static function selfChecks(): array
    {
        return [
            'config/defaults-shape' => static function (): void {
                sac_assert_eq(array_intersect(self::REMOVED, array_keys(self::DEFAULTS)), [], 'no removed key is still a setting');
                // The volume thresholds graded one publisher-wide Finding. There
                // is one Finding per software now, so they no longer decide
                // anything and must not stay on the Setup page pretending to.
                sac_assert(!array_key_exists('license_2a_products_threshold', self::DEFAULTS), 'the publisher-aggregate product threshold is not a setting');
                sac_assert(!array_key_exists('license_2a_installs_threshold', self::DEFAULTS), 'the publisher-aggregate install threshold is not a setting');
                sac_assert_eq(self::DEFAULTS['license_missing_severity'], 'Medium', 'one unlicensed-software Finding is Medium by default');
                sac_assert_eq(self::DEFAULTS['license_check_overdeployment'], 0, '2b off by default');
                sac_assert_eq(self::DEFAULTS['compliance_stale_days'], 45, 'stale threshold matches the monthly cadence — at 14 a monthly scan of a monthly-refreshed file would flag every host STALE permanently');
                $cats = json_decode(self::DEFAULTS['license_ignore_categories'], true);
                sac_assert_eq($cats, ['system_component'], 'ignore cats seed');
                sac_assert_eq(self::DEFAULTS['compliance_scan_enabled'], 1, 'config compliance scan on by default');
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
                $known = ['bool', 'int', 'json', 'entities', 'softwarecategories', 'text'];
                foreach (self::FIELD_TYPES as $k => $t) {
                    sac_assert(in_array($t, $known, true), "$k has a known field type ($t)");
                }
                sac_assert_eq(self::FIELD_TYPES['license_ignore_categories'], 'softwarecategories', 'ignore categories is a GLPI category pick-list');
            },
        ];
    }
}
