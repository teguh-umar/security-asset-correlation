<?php

namespace GlpiPlugin\Sac\License;

/**
 * The licence review queue (`glpi_plugin_sac_licensereview`): software that no
 * {@see LicenseRule} decides. A row means "SAC does not know whether this needs
 * a licence, and deliberately asserts nothing" — it is not a Finding.
 *
 * Lifecycle, exactly:
 *  - software seen, no row        -> insert `new`, both dates = scan date
 *  - software seen, row `new`/`flagged` -> refresh `date_mod`, state kept
 *  - software seen, row `decided` -> UNTOUCHED: a human settled it, and a scan
 *    that still sees the software must not reopen that decision
 *  - software not seen            -> untouched (the queue never auto-clears)
 */
final class ReviewQueue
{
    private const TABLE = 'glpi_plugin_sac_licensereview';

    /**
     * @param int[] $softwareIds
     * @return array{new:int,kept:int}
     */
    public static function sync(int $entityId, array $softwareIds, string $now): array
    {
        global $DB;

        $existing = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['entities_id' => $entityId]]) as $row) {
            $existing[(int) $row['softwares_id']] = $row;
        }

        $counts = ['new' => 0, 'kept' => 0];

        foreach (array_unique(array_map('intval', $softwareIds)) as $softwareId) {
            if (!isset($existing[$softwareId])) {
                $DB->insert(self::TABLE, [
                    'softwares_id'    => $softwareId,
                    'entities_id'     => $entityId,
                    'state'           => 'new',
                    'date_first_seen' => $now,
                    'date_mod'        => $now,
                ]);
                $counts['new']++;
                continue;
            }

            $row = $existing[$softwareId];
            if ((string) $row['state'] !== 'decided') {
                $DB->update(self::TABLE, ['date_mod' => $now], ['id' => (int) $row['id']]);
            }
            $counts['kept']++;
        }

        return $counts;
    }

    /** @return array<int,array<string,mixed>> rows not yet decided; every entity when $entityId is null */
    public static function openFor(?int $entityId): array
    {
        global $DB;

        $where = ['state' => ['<>', 'decided']];
        if ($entityId !== null) {
            $where['entities_id'] = $entityId;
        }

        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDER' => ['date_first_seen', 'id']]) as $row) {
            $out[] = (array) $row;
        }
        return $out;
    }

    /** @return array<string,callable():void> VM-only — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/reviewqueue-sync-is-idempotent' => static function (): void {
                global $DB;
                $eid = 987001;
                try {
                    $r1 = self::sync($eid, [9001, 9002], '2026-10-01 02:00:00');
                    sac_assert_eq($r1['new'], 2, 'first run queues both');
                    $r2 = self::sync($eid, [9001, 9002, 9002], '2026-11-01 02:00:00');
                    sac_assert_eq($r2['new'], 0, 'second run reports nothing new');
                    sac_assert_eq($r2['kept'], 2, 'second run keeps both, duplicates in the input collapsed');
                    sac_assert_eq(count(self::openFor($eid)), 2, 'no duplicate rows');
                } finally {
                    $DB->delete(self::TABLE, ['entities_id' => $eid]);
                }
            },

            'db/reviewqueue-decided-is-not-resurrected' => static function (): void {
                global $DB;
                $eid = 987002;
                try {
                    self::sync($eid, [9101, 9102], '2026-10-01 02:00:00');
                    $DB->update(self::TABLE, ['state' => 'decided', 'note' => 'ok'], ['entities_id' => $eid, 'softwares_id' => 9101]);
                    $r = self::sync($eid, [9101, 9102], '2026-11-01 02:00:00');
                    sac_assert_eq($r['new'], 0, 'a decided row is not re-reported as new');
                    $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['entities_id' => $eid, 'softwares_id' => 9101]])->current();
                    sac_assert_eq((string) $row['state'], 'decided', 'still decided');
                    sac_assert_eq((string) $row['date_mod'], '2026-10-01 02:00:00', 'untouched by the later scan');
                    sac_assert_eq(array_column(self::openFor($eid), 'softwares_id'), [9102], 'only the undecided row is open');
                } finally {
                    $DB->delete(self::TABLE, ['entities_id' => $eid]);
                }
            },
        ];
    }
}
