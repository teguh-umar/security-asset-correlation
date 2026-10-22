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

    /**
     * Hard ceiling on `framework_refs`, which riskregister stores as
     * `varchar(255)` (its Migration.php line 174, confirmed against the VM
     * by the riskregister session 2026-10-22).
     *
     * Overflowing it is not a tidy truncation. MySQL cuts mid-ref, and
     * riskregister's `RefList::parse()` then drops the malformed tail with a
     * bare `continue` that `refs_unmatched` never counts, so the loss is
     * invisible on both sides. Worse, a cut can land INSIDE a code and leave
     * something that still parses: `CISv7:19` cut to `CISv7:1` is a valid
     * pair pointing at the wrong control. Silent loss is bad; a silent wrong
     * answer is the thing this plugin exists to prevent.
     *
     * Measured in bytes via strlen(): the column counts characters, so for
     * the ASCII that ref codes are made of the two agree, and bytes are the
     * stricter of the two if a non-ASCII code ever appears.
     */
    private const REFS_MAX = 255;

    public static function isAvailable(): bool
    {
        return \Plugin::isPluginActive('riskregister')
            && class_exists(\GlpiPlugin\Riskregister\Finding_Item::class)
            && method_exists(\GlpiPlugin\Riskregister\Finding_Item::class, 'upsertAffected');
    }

    /**
     * PURE. Trims a ref list to {@see REFS_MAX} bytes on whole refs only.
     *
     * Drops from the END, so the refs the content listed first survive —
     * SSG lists them in benchmark order, and an arbitrary subset is more
     * useful than a corrupt one. Never cuts inside a ref.
     *
     * @return array{refs: string, dropped: int}
     */
    public static function capRefs(string $refs): array
    {
        $refs = trim($refs);
        if ($refs === '' || strlen($refs) <= self::REFS_MAX) {
            return ['refs' => $refs, 'dropped' => 0];
        }

        $kept    = [];
        $dropped = 0;
        $len     = 0;
        foreach (explode(',', $refs) as $ref) {
            $ref = trim($ref);
            if ($ref === '') {
                continue;
            }
            // +1 for the comma this ref would need, except for the first.
            $need = strlen($ref) + ($kept === [] ? 0 : 1);
            if ($len + $need > self::REFS_MAX) {
                $dropped++;      // keep counting: the caller reports the total
                continue;
            }
            $kept[] = $ref;
            $len   += $need;
        }

        return ['refs' => implode(',', $kept), 'dropped' => $dropped];
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
     * @param int|null $refsDropped out: refs dropped for length across the
     *                 whole batch. An out-param rather than a changed return
     *                 type, so the many existing callers and checks that only
     *                 want the rows keep working unchanged.
     * @return array<int,array<string,string>>
     */
    public static function rowsFor(array $rules, ?int &$refsDropped = null): array
    {
        $refsDropped = 0;
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
            $capped = self::capRefs((string) ($r['framework_refs'] ?? ''));
            $refsDropped += $capped['dropped'];
            if ($capped['refs'] !== '') {
                $row['framework_refs'] = $capped['refs'];
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

        $rows    = self::rowsFor($rules, $refsDropped);
        $result  = \GlpiPlugin\Riskregister\Finding_Item::upsertAffected($findingId, $rows);

        // Surfaced to the caller, which knows the Computer this scan was for
        // and can record it against that host. Returned rather than logged
        // only, because a ref lost to the column width is data the content
        // did supply, and nobody would ever go looking for it in a log.
        $result['refs_dropped'] = (int) $refsDropped;

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

            // riskregister stores framework_refs as varchar(255) until its
            // 2.2.0 moves refs to a child table (its session, 2026-10-22).
            // A row that overflows is cut MID-REF by MySQL, and RefList::parse()
            // then drops the malformed tail without counting it in
            // refs_unmatched — so neither side can see the loss. Build the
            // list to fit instead, dropping whole refs and reporting how many.
            'affected/caps-framework-refs-at-255' => static function (): void {
                // 40 refs of 8-9 chars each is ~340 chars: a realistic rule in
                // v8 content, which cites several safeguards per rule.
                $many = implode(',', array_map(
                    static fn(int $i): string => 'CISv7:' . $i,
                    range(1, 40)
                ));
                sac_assert(strlen($many) > 255, 'fixture must actually overflow, or this check proves nothing');

                $rows = self::rowsFor([
                    ['rule_id' => 'r', 'severity' => 'low', 'title' => 't', 'framework_refs' => $many, 'state' => 'open'],
                ], $dropped);

                $sent = $rows[0]['framework_refs'];
                sac_assert(strlen($sent) <= 255, 'what SAC sends must fit the column: got ' . strlen($sent));
                sac_assert($dropped > 0, 'the refs that did not fit are counted, not silently gone');

                // The real failure this guards: a cut inside a ref. Every
                // piece that arrives must still be a whole framework:code.
                foreach (explode(',', $sent) as $ref) {
                    sac_assert((bool) preg_match('/^CISv7:\d+$/', $ref), 'every surviving ref is whole, never cut mid-ref: got ' . $ref);
                }

                // CISv7:19 cut to CISv7:1 would still parse — and point at the
                // wrong control. Pin that no such mutation reached the output.
                $kept = explode(',', $sent);
                sac_assert(count($kept) + $dropped === 40, 'kept + dropped accounts for every ref the content supplied');

                // A list that already fits is passed through untouched.
                $short = self::rowsFor([
                    ['rule_id' => 'r2', 'severity' => 'low', 'title' => 't', 'framework_refs' => 'CISv7:4,CISv7:19', 'state' => 'open'],
                ], $none);
                sac_assert_eq($short[0]['framework_refs'], 'CISv7:4,CISv7:19', 'a list within the limit is never reshaped');
                sac_assert_eq($none, 0, 'nothing dropped when nothing overflows');
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
