<?php

namespace GlpiPlugin\Sac\Compliance;

/**
 * Reads the Tier 1 oscap summary line GLPI Inventory's Collect task reports
 * per Computer. Schema (table/column names) is a documented guess — see
 * project-brief.md §10.2 and the implementation plan's Global Constraints —
 * confirmed/corrected on the VM (plan Task 12). Guarded throughout so a wrong
 * guess degrades to "unavailable" (a logged skip upstream in ConfigScan),
 * never a fatal.
 */
final class CollectReader
{
    private const COLLECTS_TABLE = 'glpi_plugin_glpiinventory_collects';
    private const VALUES_TABLE   = 'glpi_plugin_glpiinventory_collects_contents';

    /**
     * Every column this class reads from or filters on, across both tables,
     * must exist or the schema guess is unusable — checked exhaustively so a
     * PARTIALLY-right guess (tables present, one column named differently)
     * still degrades to "unavailable" instead of throwing a SQL error out of
     * latestFor()/taskExists() mid-cron. A prior version only checked
     * `computers_id`/`value`, leaving `collects.name` and the FK column
     * unchecked — exactly the partial-match case this now closes.
     */
    public static function isAvailable(): bool
    {
        global $DB;
        return \Plugin::isPluginActive('glpiinventory')
            && $DB->tableExists(self::COLLECTS_TABLE)
            && $DB->tableExists(self::VALUES_TABLE)
            && $DB->fieldExists(self::COLLECTS_TABLE, 'id')
            && $DB->fieldExists(self::COLLECTS_TABLE, 'name')
            && $DB->fieldExists(self::VALUES_TABLE, 'computers_id')
            && $DB->fieldExists(self::VALUES_TABLE, 'value')
            && $DB->fieldExists(self::VALUES_TABLE, 'plugin_glpiinventory_collects_id');
    }

    /**
     * Whether a Collect task named $collectName is defined at all. Lets a
     * caller log ONE warning for "the configured task name matches nothing"
     * instead of silently skipping every host, one at a time, with no signal
     * that the configuration itself is wrong.
     */
    public static function taskExists(string $collectName): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        global $DB;
        return (bool) $DB->request([
            'FROM'  => self::COLLECTS_TABLE,
            'WHERE' => ['name' => $collectName],
            'LIMIT' => 1,
        ])->current();
    }

    /**
     * The most recent Collect value for $computerId under the Collect task
     * named $collectName, with its reported date if the table carries one.
     * Null when the Collect feature/table isn't available, the named task
     * doesn't exist, or no value has been reported for this computer yet —
     * all three are normal, non-error states for a caller to skip over.
     *
     * @return array{value:string,date:?string}|null
     */
    public static function latestFor(int $computerId, string $collectName): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }
        global $DB;

        $collect = $DB->request([
            'FROM'  => self::COLLECTS_TABLE,
            'WHERE' => ['name' => $collectName],
            'LIMIT' => 1,
        ])->current();
        if (!$collect) {
            return null;
        }

        $hasDate = $DB->fieldExists(self::VALUES_TABLE, 'date');
        $row = $DB->request([
            'FROM'  => self::VALUES_TABLE,
            'WHERE' => [
                'computers_id'                      => $computerId,
                'plugin_glpiinventory_collects_id'   => (int) $collect['id'],
            ],
            'ORDER' => $hasDate ? ['date DESC', 'id DESC'] : ['id DESC'],
            'LIMIT' => 1,
        ])->current();
        if (!$row) {
            return null;
        }

        // $row['date'] is NULL, not '', when the column exists but this row
        // never got a value — (string) null would silently coerce to '' and
        // write an empty string into a timestamp column downstream.
        return [
            'value' => (string) $row['value'],
            'date'  => ($hasDate && $row['date'] !== null) ? (string) $row['date'] : null,
        ];
    }

    /** @return array<string,callable():void> VM-only — these tables may not exist/match yet; see Task 12. */
    public static function selfChecks(): array
    {
        return [
            'db/collectreader-unavailable-is-safe' => static function (): void {
                // This check only asserts the SAFE-DEGRADATION contract, not
                // real data — it must pass whether or not Task 12 has
                // confirmed the real schema yet.
                if (self::isAvailable()) {
                    return; // the real schema matched on this VM — nothing to prove here
                }
                sac_assert(self::latestFor(1, 'anything') === null, 'unavailable reader returns null, never throws');
            },

            'db/collectreader-roundtrip' => static function (): void {
                if (!self::isAvailable()) {
                    echo "SKIP  db/collectreader-roundtrip — Collect schema not confirmed on this VM yet (Task 12)\n";
                    return;
                }
                global $DB;
                $name = '__sac_sc_collect__';
                $cid  = (int) (new \Computer())->add(['name' => '__sac_sc_collect_host__', 'entities_id' => 0]);
                $collectId = 0;
                try {
                    sac_assert($cid > 0, 'seed computer created');
                    $DB->insert(self::COLLECTS_TABLE, ['name' => $name]);
                    $collectId = (int) $DB->insertId();
                    $DB->insert(self::VALUES_TABLE, [
                        'plugin_glpiinventory_collects_id' => $collectId,
                        'computers_id'                      => $cid,
                        'value'                              => 'SACCFG1|bm|90|1|2|3|2026-10-14T00:00:00Z',
                        'date'                                => '2026-10-14 00:00:00',
                    ]);

                    $got = self::latestFor($cid, $name);
                    sac_assert($got !== null, 'value found');
                    sac_assert_eq($got['value'], 'SACCFG1|bm|90|1|2|3|2026-10-14T00:00:00Z', 'value matches');
                } finally {
                    $DB->delete(self::VALUES_TABLE, ['computers_id' => $cid]);
                    if ($collectId > 0) {
                        $DB->delete(self::COLLECTS_TABLE, ['id' => $collectId]);
                    }
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            'db/collectreader-task-exists' => static function (): void {
                if (!self::isAvailable()) {
                    echo "SKIP  db/collectreader-task-exists — Collect schema not confirmed on this VM yet (Task 12)\n";
                    return;
                }
                global $DB;
                $name = '__sac_sc_taskexists__';
                sac_assert(!self::taskExists($name), 'absent task name reports false');
                $DB->insert(self::COLLECTS_TABLE, ['name' => $name]);
                $collectId = (int) $DB->insertId();
                try {
                    sac_assert(self::taskExists($name), 'defined task name reports true');
                } finally {
                    $DB->delete(self::COLLECTS_TABLE, ['id' => $collectId]);
                }
            },

            'db/collectreader-null-date-not-empty-string' => static function (): void {
                if (!self::isAvailable()) {
                    echo "SKIP  db/collectreader-null-date-not-empty-string — Collect schema not confirmed on this VM yet (Task 12)\n";
                    return;
                }
                global $DB;
                $name = '__sac_sc_nulldate__';
                $cid  = (int) (new \Computer())->add(['name' => '__sac_sc_nulldate_host__', 'entities_id' => 0]);
                $collectId = 0;
                try {
                    $DB->insert(self::COLLECTS_TABLE, ['name' => $name]);
                    $collectId = (int) $DB->insertId();
                    $DB->insert(self::VALUES_TABLE, [
                        'plugin_glpiinventory_collects_id' => $collectId,
                        'computers_id'                      => $cid,
                        'value'                              => 'SACCFG1|bm|90|1|2|3|2026-10-14T00:00:00Z',
                        'date'                                => null,
                    ]);

                    $got = self::latestFor($cid, $name);
                    sac_assert($got !== null, 'value found even with a null date');
                    sac_assert($got['date'] === null, 'a NULL date column stays null, never coerced to an empty string');
                } finally {
                    $DB->delete(self::VALUES_TABLE, ['computers_id' => $cid]);
                    if ($collectId > 0) {
                        $DB->delete(self::COLLECTS_TABLE, ['id' => $collectId]);
                    }
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },
        ];
    }
}
