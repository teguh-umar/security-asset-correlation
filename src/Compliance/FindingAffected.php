<?php

namespace GlpiPlugin\Sac\Compliance;

/**
 * The only place SAC calls riskregister's affected-areas API
 * (`Finding_Item::upsertAffected()`, contract in hub §7 2026-10-15/19).
 *
 * Guarded with `method_exists()` as well as `isPluginActive()`: the method
 * arrives in riskregister 2.1.0, so an older installed copy is active but
 * has no such method, and calling it would fatal the whole cron run.
 *
 * Field semantics that shape what SAC sends (riskregister's 2026-10-19
 * change): an OMITTED key means "saying nothing, keep the stored value",
 * while a key sent as '' means "empty it". Hence two deliberate choices in
 * {@see rowsFor()} — no `description` ever, and no empty `framework_refs`.
 */
final class FindingAffected
{
    /** SCAP severity -> riskregister's Finding::SEVERITIES vocabulary. */
    private const SEVERITY_MAP = [
        'high'    => 'High',
        'medium'  => 'Medium',
        'low'     => 'Low',
        'unknown' => 'Info',
    ];

    private const STATE_MAP = ['open' => 'Open', 'resolved' => 'Resolved'];

    public static function isAvailable(): bool
    {
        return \Plugin::isPluginActive('riskregister')
            && class_exists(\GlpiPlugin\Riskregister\Finding_Item::class)
            && method_exists(\GlpiPlugin\Riskregister\Finding_Item::class, 'upsertAffected');
    }

    /**
     * PURE. Maps stored rule rows onto the contract's row shape.
     *
     *  - `description` is NEVER sent. It is where a person writes the
     *    remediation note, and the only thing SAC could put there is
     *    boilerplate restating the rule id. Sending it would overwrite that
     *    note on every scan — and the scan is monthly, so the loss would
     *    look arbitrary rather than reproducible.
     *  - `framework_refs` is sent only when the content actually produced
     *    some. Sending '' for a rule with no CIS reference would erase refs
     *    that a later benchmark version, or a human, supplied — and on
     *    Oracle Linux (no CIS profile) that is most rules, so the first real
     *    run would mass-erase.
     *
     * @param array<int,array<string,mixed>> $rules
     * @return array<int,array<string,string>>
     */
    public static function rowsFor(array $rules): array
    {
        $rows = [];
        foreach ($rules as $r) {
            $row = [
                'external_key' => (string) $r['rule_id'],
                'label'        => (string) $r['title'],
                'severity'     => self::SEVERITY_MAP[(string) $r['severity']] ?? 'Info',
                'state'        => self::STATE_MAP[(string) $r['state']] ?? 'Open',
            ];
            // Verbatim, per the contract: a shape mismatch must surface in
            // riskregister's refs_unmatched, not be smoothed over here.
            $refs = trim((string) ($r['framework_refs'] ?? ''));
            if ($refs !== '') {
                $row['framework_refs'] = $refs;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @return array<string,int>|null null when riskregister cannot take them
     */
    public static function push(int $findingId, array $rules): ?array
    {
        if (!self::isAvailable() || $findingId <= 0 || $rules === []) {
            return null;
        }

        $result = \GlpiPlugin\Riskregister\Finding_Item::upsertAffected($findingId, self::rowsFor($rules));

        if (!empty($result['refs_unmatched'])) {
            // Not an error on either side: it means SAC's reference codes
            // and riskregister's seeded control codes disagree in shape.
            // Logged rather than swallowed so the first real run surfaces it.
            \Toolbox::logInFile('sac', sprintf(
                "FindingAffected: %d framework ref(s) matched no seeded control on finding %d\n",
                (int) $result['refs_unmatched'],
                $findingId
            ));
        }

        return $result;
    }

    /** @return array<string,callable():void> The mapping checks are PURE. */
    public static function selfChecks(): array
    {
        return [
            'affected/maps-rule-to-row' => static function (): void {
                $rows = self::rowsFor([
                    ['rule_id' => 'rule_a', 'severity' => 'high', 'title' => 'Disable root login', 'framework_refs' => 'CISv7:4', 'state' => 'open'],
                    ['rule_id' => 'rule_b', 'severity' => 'unknown', 'title' => 'Set umask', 'framework_refs' => '', 'state' => 'resolved'],
                ]);
                sac_assert_eq(count($rows), 2, 'one row per rule');
                sac_assert_eq($rows[0]['external_key'], 'rule_a', 'SCAP rule id is the upsert key');
                sac_assert_eq($rows[0]['severity'], 'High', 'SCAP high maps onto riskregister High');
                sac_assert_eq($rows[0]['state'], 'Open', 'open maps onto Open');
                sac_assert_eq($rows[0]['framework_refs'], 'CISv7:4', 'refs passed through verbatim, never re-interpreted');
                sac_assert_eq($rows[1]['severity'], 'Info', 'unknown severity maps onto Info');
                sac_assert_eq($rows[1]['state'], 'Resolved', 'resolved maps onto Resolved');
            },

            // Both of these pin riskregister's 2026-10-19 contract change:
            // an OMITTED field leaves the stored value alone, a field sent
            // as '' empties it. Getting this wrong means a monthly scan
            // quietly wipes what a person wrote.
            'affected/never-sends-description' => static function (): void {
                $rows = self::rowsFor([
                    ['rule_id' => 'rule_a', 'severity' => 'high', 'title' => 'Disable root login', 'framework_refs' => '', 'state' => 'open'],
                ]);
                sac_assert(!array_key_exists('description', $rows[0]), 'description is never sent: it holds a human remediation note, and SAC has nothing to say there worth overwriting a person monthly');
            },

            'affected/omits-empty-framework-refs' => static function (): void {
                $withRefs = self::rowsFor([
                    ['rule_id' => 'r1', 'severity' => 'low', 'title' => 't', 'framework_refs' => 'CISv7:4', 'state' => 'open'],
                ]);
                sac_assert_eq($withRefs[0]['framework_refs'], 'CISv7:4', 'refs sent when the content actually had some');

                $noRefs = self::rowsFor([
                    ['rule_id' => 'r2', 'severity' => 'low', 'title' => 't', 'framework_refs' => '', 'state' => 'open'],
                ]);
                sac_assert(!array_key_exists('framework_refs', $noRefs[0]), 'no refs = key omitted, NOT sent as empty — "" would erase refs a later benchmark or a human supplied');
            },

            // The OL9 SSG content references CIS Controls **v7** (codes run to
            // 20; v8 has only 18). Orchestrator ruling, hub §7 2026-10-22: send
            // them as `CISv7:<n>` exactly as the content states. riskregister
            // has no CISv7 framework, so every ref lands as stored text and is
            // counted in `refs_unmatched` — nothing is mislinked, and a later
            // v7->v8 mapping still has the original data to work from. The
            // danger this pins is the opposite: any "helpful" clamping of 19/20
            // into v8's 1-18 range would silently point at the wrong control.
            'affected/passes-cisv7-codes-through-untouched' => static function (): void {
                $rows = self::rowsFor([
                    ['rule_id' => 'r19', 'severity' => 'low', 'title' => 't', 'framework_refs' => 'CISv7:19', 'state' => 'open'],
                    ['rule_id' => 'r20', 'severity' => 'low', 'title' => 't', 'framework_refs' => 'CISv7:20', 'state' => 'open'],
                    ['rule_id' => 'rmulti', 'severity' => 'low', 'title' => 't', 'framework_refs' => 'CISv7:4,CISv7:20', 'state' => 'open'],
                ]);
                sac_assert_eq($rows[0]['framework_refs'], 'CISv7:19', 'code 19 has no v8 equivalent and is still sent verbatim, never dropped or clamped');
                sac_assert_eq($rows[1]['framework_refs'], 'CISv7:20', 'code 20 likewise: out of v8 range is not a reason to rewrite it');
                sac_assert_eq($rows[2]['framework_refs'], 'CISv7:4,CISv7:20', 'a multi-ref list keeps both codes and their order');
            },

            'db/affected-absent-riskregister-is-safe' => static function (): void {
                if (self::isAvailable()) {
                    return; // riskregister 2.1.0+ present — nothing to prove here
                }
                sac_assert(self::push(1, [['rule_id' => 'r', 'severity' => 'low', 'title' => 't', 'framework_refs' => '', 'state' => 'open']]) === null, 'push is a no-op when upsertAffected() does not exist');
            },
        ];
    }
}
