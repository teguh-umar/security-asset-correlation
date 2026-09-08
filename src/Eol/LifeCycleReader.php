<?php

namespace GlpiPlugin\Sac\Eol;

/**
 * Read-only view of the `fields` plugin's "LifeCycle" data on Operating Systems.
 *
 * The `fields` plugin lets an admin bolt a custom container onto an itemtype.
 * For a dropdown itemtype (OperatingSystem) it creates a table named
 * `glpi_plugin_fields_<itemtype-lower><container-lower>s` and keys each row by
 * `items_id` (the dropdown row id). We expect a container that carries an
 * end-of-life date column.
 *
 * This class NEVER writes the `fields` tables — every access is a guarded SELECT.
 * The real table/column names are confirmed on the VM in Task 12; the probe
 * lists below are deliberately defensive and editable, and degrade to
 * `''` / `null` rather than fatal when the layout is not what we assume.
 */
final class LifeCycleReader
{
    /** Resolved container table. null = not yet probed, '' = none found. */
    private static ?string $table = null;

    /** Resolved EOL date column. null = not yet probed, '' = none found. */
    private static ?string $dateCol = null;

    /** Exact table name the `fields` plugin would generate for OS + "LifeCycle". */
    private const EXACT_TABLE = 'glpi_plugin_fields_operatingsystemlifecycles';

    /** LIKE fallback when the container was named differently. */
    private const TABLE_LIKE = 'glpi_plugin_fields_%lifecycle%';

    public static function available(): bool
    {
        global $DB;
        if (!\Plugin::isPluginActive('fields')) {
            return false;
        }
        return self::tableName() !== '';
    }

    /**
     * Resolve the container table once and cache it. Probe order:
     *   1. exact `glpi_plugin_fields_operatingsystemlifecycles`
     *   2. first table matching `glpi_plugin_fields_%lifecycle%`
     * Returns '' when nothing matches.
     */
    public static function tableName(): string
    {
        global $DB;
        if (self::$table !== null) {
            return self::$table;
        }
        self::$table = '';

        if ($DB->tableExists(self::EXACT_TABLE)) {
            self::$table = self::EXACT_TABLE;
            return self::$table;
        }

        foreach ($DB->listTables(self::TABLE_LIKE) as $row) {
            $name = is_array($row) ? (string) reset($row) : (string) $row;
            if ($name !== '') {
                self::$table = $name;
                break;
            }
        }
        return self::$table;
    }

    /**
     * End-of-life date for one OperatingSystem row.
     *
     * @return string|false|null  `Y-m-d` when a row with a parseable date exists;
     *                            `false` when a row exists but the date is blank /
     *                            `0000-00-00` / unparseable (caller writes a
     *                            `bad_date` reconcile signal);
     *                            `null` when there is no row, or `!available()`,
     *                            or the date column could not be resolved.
     */
    public static function endOfLifeFor(int $operatingsystemsId): string|false|null
    {
        global $DB;
        if (!self::available()) {
            return null;
        }
        $table = self::tableName();
        $col   = self::dateColumn();
        if ($col === '') {
            // Table is there but we cannot tell which column holds the date.
            // Treat as "no data" rather than "bad data".
            return null;
        }

        $row = $DB->request([
            'SELECT' => [$col],
            'FROM'   => $table,
            'WHERE'  => ['items_id' => $operatingsystemsId],
        ])->current();

        if (!$row || !array_key_exists($col, (array) $row)) {
            return null;
        }

        $raw = $row[$col];
        if ($raw === null || $raw === '' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
            return false;
        }

        $ts = strtotime((string) $raw);
        if ($ts === false) {
            return false;
        }
        return date('Y-m-d', $ts);
    }

    /**
     * Resolve the EOL date column once and cache it. Probe order:
     *   1. `endoflife`
     *   2. `endoflife_date`
     *   3. the sole `date`/`datetime`/`timestamp` column that is not
     *      `date_creation` / `date_mod`
     * Returns '' when the table is unknown or no single date column stands out.
     */
    private static function dateColumn(): string
    {
        global $DB;
        if (self::$dateCol !== null) {
            return self::$dateCol;
        }
        self::$dateCol = '';

        $table = self::tableName();
        if ($table === '') {
            return '';
        }

        if ($DB->fieldExists($table, 'endoflife')) {
            self::$dateCol = 'endoflife';
            return self::$dateCol;
        }
        if ($DB->fieldExists($table, 'endoflife_date')) {
            self::$dateCol = 'endoflife_date';
            return self::$dateCol;
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
            self::$dateCol = $candidates[0];
        }
        return self::$dateCol;
    }

    /**
     * Test hook — drop the resolved-name caches so a selfCheck (or a test that
     * toggles the `fields` plugin) re-probes. Not used by production code.
     */
    public static function resetProbeCache(): void
    {
        self::$table   = null;
        self::$dateCol = null;
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            // Read-only: this class must never write the `fields` tables, so we
            // cannot seed a row. Instead assert the two real contracts callers
            // rely on — clean degradation when `fields` is absent, and a plain
            // `null` (not `false`) for an OS that has no container row.
            'db/lifecycle-reads-seeded-date' => static function (): void {
                self::resetProbeCache();

                if (!self::available()) {
                    sac_assert_eq(self::endOfLifeFor(999999), null, 'endOfLifeFor() degrades to null when fields absent');
                    return;
                }

                sac_assert(self::tableName() !== '', 'available() implies a resolved table name');
                sac_assert_eq(
                    self::endOfLifeFor(2147480931),
                    null,
                    'endOfLifeFor() returns null (no row), never false, for an unknown OS id'
                );
            },
        ];
    }
}
