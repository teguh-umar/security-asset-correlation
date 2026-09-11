<?php

namespace GlpiPlugin\Sac\Eol;

/**
 * Read-only view of the `fields` plugin's "LifeCycle" data on Operating
 * Systems AND Operating System Versions.
 *
 * The `fields` plugin lets an admin bolt a custom container onto an itemtype.
 * For a dropdown itemtype it creates a table named
 * `glpi_plugin_fields_<itemtype-lower><container-lower>s`, keyed by
 * `items_id` (the dropdown row id). SAC v1.1.0 (spec
 * 2026-09-10-eol-multidim-design.md §7.4) reads TWO such containers:
 *
 *   - `os`      — on `OperatingSystem`        (v1.0.x behaviour, unchanged)
 *   - `version` — on `OperatingSystemVersion` (new)
 *
 * When both carry a date for a pair, the `version` container wins (more
 * specific) — see {@see endOfLifeFor()}.
 *
 * This class NEVER writes the `fields` tables — every access is a guarded
 * SELECT. Real table/column names are VM-verified; the probe lists are
 * deliberately defensive and editable, and degrade to `''` / `null` rather
 * than fatal when the layout is not what we assume.
 */
final class LifeCycleReader
{
    /** Resolved container table per level ('os'|'version'). null = not yet probed, '' = none found. */
    private static array $table = ['os' => null, 'version' => null];

    /** Resolved EOL date column per level. null = not yet probed, '' = none found. */
    private static array $dateCol = ['os' => null, 'version' => null];

    private const EXACT_TABLE = [
        'os'      => 'glpi_plugin_fields_operatingsystemlifecycles',
        'version' => 'glpi_plugin_fields_operatingsystemversionlifecycles',
    ];

    /**
     * LIKE fallback per level. Tightened to require "operatingsystemlifecycle"
     * / "operatingsystemversionlifecycle" as a CONTIGUOUS substring (no
     * wildcard between the itemtype and "lifecycle") so the 'os' probe can
     * never also match the 'version' table (which contains "operatingsystem"
     * too) or vice versa.
     */
    private const TABLE_LIKE = [
        'os'      => 'glpi_plugin_fields_operatingsystemlifecycle%',
        'version' => 'glpi_plugin_fields_operatingsystemversionlifecycle%',
    ];

    private const ITEMTYPE = [
        'os'      => 'OperatingSystem',
        'version' => 'OperatingSystemVersion',
    ];

    public static function available(): bool
    {
        if (!\Plugin::isPluginActive('fields')) {
            return false;
        }
        return self::tableName('os') !== '' || self::tableName('version') !== '';
    }

    /**
     * Resolve one level's container table once and cache it. Probe order:
     *   1. the exact expected table name for this level
     *   2. the first table matching this level's LIKE pattern
     * Returns '' when nothing matches.
     */
    public static function tableName(string $level = 'os'): string
    {
        global $DB;
        if (self::$table[$level] !== null) {
            return self::$table[$level];
        }
        self::$table[$level] = '';

        if ($DB->tableExists(self::EXACT_TABLE[$level])) {
            self::$table[$level] = self::EXACT_TABLE[$level];
            return self::$table[$level];
        }

        $other = $level === 'os' ? 'version' : 'os';
        foreach ($DB->listTables(self::TABLE_LIKE[$level]) as $row) {
            $name = is_array($row) ? (string) reset($row) : (string) $row;
            if ($name === '' || $name === self::EXACT_TABLE[$other]) {
                continue; // defense-in-depth: never let a level claim the other's table
            }
            self::$table[$level] = $name;
            break;
        }
        return self::$table[$level];
    }

    /**
     * End-of-life date for one (OperatingSystem, OperatingSystemVersion) pair.
     * Checks the version-level container first when `$operatingsystemversionsId
     * > 0` (more specific — pass 0 to skip it), then the OS-level container. A
     * bad date at the more-specific level short-circuits — it does NOT fall
     * through to the OS-level container (the operator entered *something*;
     * surface it via `bad_date`, don't silently ignore it).
     *
     * @return string|false|null  `Y-m-d` when a row with a parseable date
     *                            exists; `false` when a row exists but the date
     *                            is blank / `0000-00-00` / unparseable (caller
     *                            writes a `bad_date` reconcile signal); `null`
     *                            when neither container has a usable row.
     */
    public static function endOfLifeFor(int $operatingsystemsId, int $operatingsystemversionsId = 0): string|false|null
    {
        if (!self::available()) {
            return null;
        }

        if ($operatingsystemversionsId > 0) {
            $fromVersion = self::readLevel('version', $operatingsystemversionsId);
            if ($fromVersion !== null) {
                return $fromVersion;
            }
        }

        return self::readLevel('os', $operatingsystemsId);
    }

    /** @return string|false|null one level's read; null = no usable row at this level. */
    private static function readLevel(string $level, int $id): string|false|null
    {
        global $DB;
        $table = self::tableName($level);
        if ($table === '') {
            return null;
        }
        $col = self::dateColumn($level);
        if ($col === '') {
            return null; // table present but the date column could not be resolved
        }
        if (!$DB->fieldExists($table, 'items_id')) {
            return null;
        }

        $where = ['items_id' => $id];
        if ($DB->fieldExists($table, 'itemtype')) {
            $where['itemtype'] = self::ITEMTYPE[$level];
        }

        $row = $DB->request(['SELECT' => [$col], 'FROM' => $table, 'WHERE' => $where])->current();
        if (!$row || !array_key_exists($col, (array) $row)) {
            return null;
        }

        $raw = $row[$col];
        if ($raw === null || $raw === '' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
            return false;
        }

        $ts = strtotime((string) $raw);
        return $ts === false ? false : date('Y-m-d', $ts);
    }

    /**
     * Resolve one level's EOL date column once and cache it. Probe order:
     *   1. `endoflifefield` (the `fields` plugin's `Field::prepareName()`
     *      appends `field` to the label-derived system name)
     *   2. `endoflife` / `endoflife_date` / `endoflifedatefield`
     *   3. the sole non-audit `date`/`datetime`/`timestamp` column
     * Returns '' when the table is unknown or no single date column stands out.
     */
    private static function dateColumn(string $level): string
    {
        global $DB;
        if (self::$dateCol[$level] !== null) {
            return self::$dateCol[$level];
        }
        self::$dateCol[$level] = '';

        $table = self::tableName($level);
        if ($table === '') {
            return '';
        }

        foreach (['endoflifefield', 'endoflife', 'endoflife_date', 'endoflifedatefield'] as $col) {
            if ($DB->fieldExists($table, $col)) {
                self::$dateCol[$level] = $col;
                return self::$dateCol[$level];
            }
        }

        $audit      = ['date_creation', 'date_mod'];
        $candidates = [];
        foreach ($DB->listFields($table) as $name => $meta) {
            $name = (string) $name;
            if (in_array($name, $audit, true)) {
                continue;
            }
            $meta = (array) $meta;
            $type = strtolower((string) ($meta['Type'] ?? $meta['type'] ?? ''));
            if (str_starts_with($type, 'date') || str_starts_with($type, 'timestamp') || str_starts_with($type, 'datetime')) {
                $candidates[] = $name;
            }
        }
        if (count($candidates) === 1) {
            self::$dateCol[$level] = $candidates[0];
        }
        return self::$dateCol[$level];
    }

    /**
     * Resolved EOL date column name for one level, or '' when none could be
     * resolved. Public so the VM selfChecks can seed a real `fields` LifeCycle
     * row into the correct column. Not used by production code.
     */
    public static function dateColumnName(string $level = 'os'): string
    {
        return self::dateColumn($level);
    }

    /** Test hook — drop the resolved-name caches so a selfCheck re-probes. */
    public static function resetProbeCache(): void
    {
        self::$table   = ['os' => null, 'version' => null];
        self::$dateCol = ['os' => null, 'version' => null];
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/lifecycle-reads-seeded-date' => static function (): void {
                self::resetProbeCache();

                if (!self::available()) {
                    sac_assert_eq(self::endOfLifeFor(999999, 999998), null, 'endOfLifeFor() degrades to null when fields absent');
                    return;
                }

                sac_assert(
                    self::tableName('os') !== '' || self::tableName('version') !== '',
                    'available() implies at least one resolved table name'
                );
                sac_assert_eq(
                    self::endOfLifeFor(2147480931, 2147480932),
                    null,
                    'endOfLifeFor() returns null (no row at either level), never false, for unknown ids'
                );
            },
        ];
    }
}
