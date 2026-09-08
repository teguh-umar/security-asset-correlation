<?php

namespace GlpiPlugin\Sac;

/**
 * Reconciliation signals: things a scan expected to find but didn't (missing
 * asset, unmapped OS, dropped install ...). One row per distinct problem in
 * `glpi_plugin_sac_reconcile`, UNIQUE on (signal_type, ref_itemtype, ref_id, reason).
 *
 * Task 6 ships only the write path used by Modules 1 & 2 (note / autoResolveMissing).
 * listForDisplay(), resolve(), resolveEolMapping() and the front page are Task 11.
 */
class Reconcile extends \CommonDBTM
{
    public static $rightname = 'plugin_sac_finding';

    public const TABLE = 'glpi_plugin_sac_reconcile';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    /**
     * Record (or refresh) a reconciliation signal. Upserts on the UNIQUE key
     * (signal_type, ref_itemtype, ref_id, reason): bumps date_lastseen + detail
     * on every call, and on the very first insert also stamps date_firstseen and
     * sets is_resolved = 0.
     */
    public static function note(string $signal, string $itemtype, int $id, string $reason, string $detail): void
    {
        global $DB;
        $now = self::now();
        $key = [
            'signal_type'  => $signal,
            'ref_itemtype' => $itemtype,
            'ref_id'       => $id,
            'reason'       => $reason,
        ];
        $exists = (int) ($DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::TABLE,
            'WHERE' => $key,
        ])->current()['cpt'] ?? 0);

        $fields = [
            'detail'        => $detail,
            'date_lastseen' => $now,
        ];
        if ($exists === 0) {
            $fields['date_firstseen'] = $now;
            $fields['is_resolved']    = 0;
        }

        $DB->updateOrInsert(self::TABLE, $fields, $key);
    }

    /**
     * Close out signals of $signal that a fresh scan no longer sees: any row of
     * that signal_type with is_resolved = 0 whose composite key is absent from
     * $seenKeys gets is_resolved = 1.
     *
     * @param string        $signal   the signal_type being reconciled
     * @param array<string> $seenKeys composite keys seen this run, each shaped
     *                                "<ref_itemtype>:<ref_id>:<reason>"
     */
    public static function autoResolveMissing(string $signal, array $seenKeys): void
    {
        global $DB;
        $seen = array_fill_keys($seenKeys, true);

        $stale = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'ref_itemtype', 'ref_id', 'reason'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['signal_type' => $signal, 'is_resolved' => 0],
        ]) as $row) {
            $composite = $row['ref_itemtype'] . ':' . $row['ref_id'] . ':' . $row['reason'];
            if (!isset($seen[$composite])) {
                $stale[] = (int) $row['id'];
            }
        }

        if ($stale !== []) {
            $DB->update(self::TABLE, ['is_resolved' => 1], ['id' => $stale]);
        }
    }

    // listForDisplay(), resolve(), resolveEolMapping() — Task 11.

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/reconcile-upsert' => static function (): void {
                global $DB;
                $sig = '__sac_selftest__';
                $key = [
                    'signal_type'  => $sig,
                    'ref_itemtype' => 'Computer',
                    'ref_id'       => 2147480999,
                    'reason'       => 'selftest',
                ];

                self::note($sig, 'Computer', 2147480999, 'selftest', 'first');
                $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current();
                sac_assert($row !== null, 'row inserted by note()');
                sac_assert_eq((int) $row['is_resolved'], 0, 'is_resolved 0 on first insert');
                sac_assert($row['date_firstseen'] !== null, 'date_firstseen stamped');

                // Backdate so the advance is observable regardless of clock granularity.
                $DB->update(self::TABLE, ['date_lastseen' => '2000-01-01 00:00:00'], $key);
                self::note($sig, 'Computer', 2147480999, 'selftest', 'second');

                $cpt = (int) $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => $key])->current()['c'];
                sac_assert_eq($cpt, 1, 'still exactly one row after re-note');

                $row2 = $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current();
                sac_assert($row2['date_lastseen'] > '2000-01-01 00:00:00', 'date_lastseen advanced');
                sac_assert_eq($row2['detail'], 'second', 'detail refreshed on re-note');

                $DB->delete(self::TABLE, $key);
            },
        ];
    }
}
