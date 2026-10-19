<?php

namespace GlpiPlugin\Sac\Compliance;

/**
 * Persistence and lifecycle for per-rule SCAP results
 * (`glpi_plugin_sac_configrules`).
 *
 * Lifecycle, exactly:
 *  - rule in the scan, no row        -> insert `open`, both dates = scan date
 *  - rule in the scan, row `open`    -> refresh fields + `date_last_seen`
 *  - rule in the scan, row `resolved`-> REOPEN: `open`, clear `date_resolved`,
 *    and **keep `date_first_seen`** — a rule that keeps coming back is one
 *    long-running problem, not a new finding each time
 *  - rule absent, row `open`         -> `resolved`, stamp `date_resolved`
 *  - rule absent, row already `resolved` -> untouched
 *
 * SAC deliberately stores NO human-set state here: `is_manual_state` lives
 * on riskregister's affected-area row (hub §7 2026-10-15), and this table
 * holds only what the scanner observed.
 */
final class RuleStore
{
    private const TABLE = 'glpi_plugin_sac_configrules';

    /** Worst-first ordering for display and for the Finding digest. */
    private const SEVERITY_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2, 'unknown' => 3];

    /**
     * @param array<int,array{rule_id:string,result:string,severity:string,framework_refs:string,title:string}> $rules
     * @return array{open:int,resolved:int,reopened:int}
     */
    public static function sync(int $computerId, array $rules, string $dateScan): array
    {
        global $DB;

        $existing = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['computers_id' => $computerId]]) as $row) {
            $existing[(string) $row['rule_id']] = $row;
        }

        $counts = ['open' => 0, 'resolved' => 0, 'reopened' => 0];
        $seen   = [];

        foreach ($rules as $rule) {
            $ruleId        = $rule['rule_id'];
            $seen[$ruleId] = true;
            $common = [
                'result'         => $rule['result'],
                'severity'       => $rule['severity'],
                'framework_refs' => $rule['framework_refs'],
                'title'          => $rule['title'],
                'date_last_seen' => $dateScan,
            ];

            if (!isset($existing[$ruleId])) {
                $DB->insert(self::TABLE, $common + [
                    'computers_id'    => $computerId,
                    'rule_id'         => $ruleId,
                    'state'           => 'open',
                    'date_first_seen' => $dateScan,
                ]);
                $counts['open']++;
                continue;
            }

            $row = $existing[$ruleId];
            if ((string) $row['state'] === 'resolved') {
                $DB->update(self::TABLE, $common + ['state' => 'open', 'date_resolved' => null], ['id' => (int) $row['id']]);
                $counts['reopened']++;
            } else {
                $DB->update(self::TABLE, $common, ['id' => (int) $row['id']]);
                $counts['open']++;
            }
        }

        foreach ($existing as $ruleId => $row) {
            if (isset($seen[$ruleId]) || (string) $row['state'] === 'resolved') {
                continue;
            }
            $DB->update(self::TABLE, ['state' => 'resolved', 'date_resolved' => $dateScan], ['id' => (int) $row['id']]);
            $counts['resolved']++;
        }

        return $counts;
    }

    /** @return array<int,array<string,mixed>> open rules, worst severity first */
    public static function openFor(int $computerId): array
    {
        return self::fetch(['computers_id' => $computerId, 'state' => 'open']);
    }

    /** @return array<int,array<string,mixed>> every rule ever recorded for this host */
    public static function allFor(int $computerId): array
    {
        return self::fetch(['computers_id' => $computerId]);
    }

    /** @return array<int,array<string,mixed>> */
    private static function fetch(array $where): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where]) as $row) {
            $out[] = (array) $row;
        }

        usort($out, static function (array $a, array $b): int {
            $sa = self::SEVERITY_ORDER[(string) $a['severity']] ?? 9;
            $sb = self::SEVERITY_ORDER[(string) $b['severity']] ?? 9;
            return $sa <=> $sb ?: strcmp((string) $a['rule_id'], (string) $b['rule_id']);
        });

        return $out;
    }

    /** @return array<string,callable():void> VM-only — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/rulestore-lifecycle' => static function (): void {
                global $DB;
                $cid = (int) (new \Computer())->add(['name' => '__sac_rs_host__', 'entities_id' => 0]);
                $mk = static fn (string $id, string $sev = 'high'): array => [
                    'rule_id' => $id, 'result' => 'fail', 'severity' => $sev,
                    'framework_refs' => '', 'title' => 'T ' . $id,
                ];
                try {
                    $r1 = self::sync($cid, [$mk('rule_a'), $mk('rule_b')], '2026-10-01 02:00:00');
                    sac_assert_eq($r1['open'], 2, 'two rules opened');
                    sac_assert_eq(count(self::openFor($cid)), 2, 'both listed as open');

                    // Second scan: rule_a fixed, rule_b still failing.
                    $r2 = self::sync($cid, [$mk('rule_b')], '2026-11-01 02:00:00');
                    sac_assert_eq($r2['resolved'], 1, 'the rule that stopped failing resolved');
                    sac_assert_eq(array_column(self::openFor($cid), 'rule_id'), ['rule_b'], 'only the still-failing rule remains open');

                    $a = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['computers_id' => $cid, 'rule_id' => 'rule_a']])->current();
                    sac_assert_eq((string) $a['state'], 'resolved', 'rule_a resolved');
                    sac_assert($a['date_resolved'] !== null, 'resolved date stamped');
                    $firstSeenA = (string) $a['date_first_seen'];

                    // Third scan: rule_a regresses.
                    $r3 = self::sync($cid, [$mk('rule_a'), $mk('rule_b')], '2026-12-01 02:00:00');
                    sac_assert_eq($r3['reopened'], 1, 'the regressed rule reopened');
                    $a2 = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['computers_id' => $cid, 'rule_id' => 'rule_a']])->current();
                    sac_assert_eq((string) $a2['state'], 'open', 'reopened');
                    sac_assert($a2['date_resolved'] === null, 'resolved date cleared on reopen');
                    sac_assert_eq((string) $a2['date_first_seen'], $firstSeenA, 'first-seen preserved — a recurring regression is not a brand-new finding');
                } finally {
                    $DB->delete(self::TABLE, ['computers_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            'db/rulestore-empty-list-resolves-everything' => static function (): void {
                // Records the behaviour deliberately: an empty list DOES
                // resolve every open rule, which is exactly why ConfigScan
                // must only call sync() for a payload whose summary line
                // parsed (Review Focus #1). Pinned so nobody "fixes" the
                // caller's guard by loosening it here.
                global $DB;
                $cid = (int) (new \Computer())->add(['name' => '__sac_rs_empty__', 'entities_id' => 0]);
                try {
                    self::sync($cid, [[
                        'rule_id' => 'r1', 'result' => 'fail', 'severity' => 'low',
                        'framework_refs' => '', 'title' => 't',
                    ]], '2026-10-01 02:00:00');
                    $out = self::sync($cid, [], '2026-11-01 02:00:00');
                    sac_assert_eq($out['resolved'], 1, 'an empty list resolves open rules — ConfigScan must never call sync() for an unusable pull');
                } finally {
                    $DB->delete(self::TABLE, ['computers_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            'db/rulestore-orders-worst-first' => static function (): void {
                global $DB;
                $cid = (int) (new \Computer())->add(['name' => '__sac_rs_order__', 'entities_id' => 0]);
                $mk = static fn (string $id, string $sev): array => [
                    'rule_id' => $id, 'result' => 'fail', 'severity' => $sev,
                    'framework_refs' => '', 'title' => 'T',
                ];
                try {
                    self::sync($cid, [$mk('r_low', 'low'), $mk('r_high', 'high'), $mk('r_med', 'medium')], '2026-10-01 02:00:00');
                    sac_assert_eq(
                        array_column(self::openFor($cid), 'rule_id'),
                        ['r_high', 'r_med', 'r_low'],
                        'worst severity first, so the Finding digest shows what matters most'
                    );
                } finally {
                    $DB->delete(self::TABLE, ['computers_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },
        ];
    }
}
