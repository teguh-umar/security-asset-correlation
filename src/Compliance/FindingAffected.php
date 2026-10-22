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
     * Duplicates are removed first and are NOT counted as dropped: they were
     * never going to reach riskregister, whose RefList::parse() discards a
     * repeated framework:code pair. `dropped` means "a distinct ref the
     * content supplied that did not fit".
     *
     * @return array{refs: string, dropped: int, malformed: int, sent: int}
     */
    public static function capRefs(string $refs): array
    {
        $refs = trim($refs);
        if ($refs === '') {
            return ['refs' => '', 'dropped' => 0, 'malformed' => 0, 'sent' => 0];
        }

        // No "it already fits" shortcut: dedup and whitespace normalisation
        // have to happen to every list, not only the oversized ones, or a
        // short list keeps duplicates that make `sent` disagree with
        // riskregister's parsed count.
        $kept      = [];
        $dropped   = 0;
        $malformed = 0;
        $len       = 0;
        $seen      = [];
        foreach (explode(',', $refs) as $ref) {
            $ref = trim($ref);
            if ($ref === '') {
                continue;
            }

            // Normalise to exactly what riskregister's RefList::parse() will
            // make of this chunk, BEFORE dedup and before measuring length.
            // Their rules (their unit tests, relayed 2026-10-22): trim around
            // the pair and around each part, so `CISv7: 4` is `CISv7:4`;
            // split on the FIRST colon only, because a ref_code may itself
            // contain one; drop a malformed entry entirely.
            //
            // Sending the raw form instead would be a silent count mismatch:
            // `CISv7: 4,CISv7:4` is two refs to us and one to them, and 2.2.0
            // refuses the row — a failure that looks like truncation and
            // sends the investigation to the wrong place.
            if (!str_contains($ref, ':')) {
                $malformed++;
                continue;
            }
            [$framework, $code] = explode(':', $ref, 2);
            $framework = trim($framework);
            $code      = trim($code);
            if ($framework === '' || $code === '') {
                $malformed++;
                continue;
            }
            $ref = $framework . ':' . $code;
            // Deduplicate on the way in. riskregister's RefList::parse()
            // discards a repeated framework:code pair anyway, so a duplicate
            // buys nothing and spends the scarce 255 — it can push a DISTINCT
            // ref out of the list. It also keeps "how many refs did SAC send"
            // answerable: once riskregister 2.2.0 asks for a per-row count,
            // kept-count and their parsed count have to agree, and they only
            // do if both sides dedupe.
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            // +1 for the comma this ref would need, except for the first.
            $need = strlen($ref) + ($kept === [] ? 0 : 1);
            if ($len + $need > self::REFS_MAX) {
                $dropped++;      // keep counting: the caller reports the total
                continue;
            }
            $kept[] = $ref;
            $len   += $need;
        }

        return [
            'refs'      => implode(',', $kept),
            'dropped'   => $dropped,
            // Not a length problem and not riskregister's to report: these
            // never reach it. A malformed ref means SAC's own host wrapper
            // emitted something unparseable, so it is ours to surface.
            'malformed' => $malformed,
            // What riskregister 2.2.0 will compare against its own parse of
            // the string: the number of refs actually in what we send.
            'sent'      => count($kept),
        ];
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
    public static function rowsFor(array $rules, ?int &$refsDropped = null, ?int &$refsMalformed = null): array
    {
        $refsDropped   = 0;
        $refsMalformed = 0;
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
            $refsDropped   += $capped['dropped'];
            $refsMalformed += $capped['malformed'];
            if ($capped['refs'] !== '') {
                $row['framework_refs'] = $capped['refs'];
                // riskregister 2.2.0 will refuse a row whose parsed ref count
                // disagrees with this, which is how a truncated ref gets
                // caught — a cut ref cannot be recognised from itself.
                // riskregister 2.1.0 builds its input from an allow-list
                // (`Finding_Item::upsertRows()`), so today this key is simply
                // ignored: sending it now is preparation, not behaviour.
                // Omitted together with the refs, never sent as a bare 0.
                $row['framework_refs_sent'] = (string) $capped['sent'];
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

        $rows    = self::rowsFor($rules, $refsDropped, $refsMalformed);
        $result  = \GlpiPlugin\Riskregister\Finding_Item::upsertAffected($findingId, $rows);

        // Surfaced to the caller, which knows the Computer this scan was for
        // and can record it against that host. Returned rather than logged
        // only, because a ref lost to the column width is data the content
        // did supply, and nobody would ever go looking for it in a log.
        $result['refs_dropped']   = (int) $refsDropped;
        $result['refs_malformed'] = (int) $refsMalformed;

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

            // riskregister's RefList::parse() discards a repeated
            // framework:code pair. Sending duplicates therefore cannot add
            // information, but it CAN spend the 255 budget and push a
            // distinct ref out — turning a harmless repeat in the content
            // into real data loss.
            'affected/deduplicates-refs-before-capping' => static function (): void {
                $rows = self::rowsFor([
                    ['rule_id' => 'r', 'severity' => 'low', 'title' => 't', 'framework_refs' => 'CISv7:4,CISv7:4,CISv7:19', 'state' => 'open'],
                ], $dropped);
                sac_assert_eq($rows[0]['framework_refs'], 'CISv7:4,CISv7:19', 'a repeated ref is sent once, in first-seen order');
                sac_assert_eq($dropped, 0, 'a duplicate is not a drop: it was never going to reach riskregister anyway');

                // The point of doing it BEFORE the cap: duplicates must not
                // be able to evict a distinct ref. 35 copies of CISv7:1 is
                // 280 bytes undeduped, so without this the trailing distinct
                // ref would be pushed out by refs that carry no information.
                $withDupes = str_repeat('CISv7:1,', 35) . 'CISv7:999';
                sac_assert(strlen($withDupes) > 255, 'fixture must overflow BEFORE dedup, or it proves nothing');

                $capped = self::capRefs($withDupes);
                sac_assert_eq($capped['refs'], 'CISv7:1,CISv7:999', 'duplicates collapse to one, so the distinct ref still fits');
                sac_assert_eq($capped['dropped'], 0, 'nothing distinct was lost, so nothing is reported as dropped');
                sac_assert_eq($capped['sent'], 2, 'sent counts what riskregister will parse: two distinct refs');
            },

            // riskregister 2.2.0 refuses a row whose parsed ref count
            // disagrees with `framework_refs_sent` — that is how a ref
            // truncated in transit gets caught, since a cut ref cannot be
            // recognised from itself. So the number must match THEIR parse of
            // the exact string we send, not our idea of what we sent.
            // The local parse below mirrors riskregister's RefList::parse():
            // split on commas, require a non-empty side either side of the
            // first colon, and discard a repeated pair.
            'affected/refs-sent-count-matches-the-string' => static function (): void {
                $parseLikeRiskregister = static function (string $raw): int {
                    $seen = [];
                    foreach (explode(',', $raw) as $chunk) {
                        $chunk = trim($chunk);
                        if ($chunk === '' || !str_contains($chunk, ':')) {
                            continue;
                        }
                        [$fw, $code] = explode(':', $chunk, 2);
                        if (trim($fw) === '' || trim($code) === '') {
                            continue;
                        }
                        $seen[trim($fw) . ':' . trim($code)] = true;
                    }
                    return count($seen);
                };

                $cases = [
                    'plain'      => 'CISv7:4,CISv7:19',
                    'duplicates' => 'CISv7:4,CISv7:4,CISv7:19',
                    'untidy'     => ' CISv7:4 , , CISv7:19 ',
                    // riskregister's RefList::parse() normalisations, relayed
                    // from its unit tests 2026-10-22. Each of these sends a
                    // different count than the raw string suggests.
                    'inner space' => 'CISv7: 4,CISv7:4',
                    'malformed'   => 'CISv7:4,junk,CISv7:,:19,CISv7:19',
                    'colon code'  => 'CISv7:4:a,CISv7:4:a,CISv7:19',
                    'overflowing' => implode(',', array_map(
                        static fn(int $i): string => 'CISv7:' . $i,
                        range(1, 40)
                    )),
                ];

                foreach ($cases as $name => $raw) {
                    $rows = self::rowsFor([
                        ['rule_id' => 'r', 'severity' => 'low', 'title' => 't', 'framework_refs' => $raw, 'state' => 'open'],
                    ]);
                    $sent = $rows[0]['framework_refs'];
                    sac_assert_eq(
                        (int) $rows[0]['framework_refs_sent'],
                        $parseLikeRiskregister($sent),
                        "framework_refs_sent must equal riskregister's parse of the string sent [$name]"
                    );
                }

                // A rule with no refs sends neither key: a bare 0 would be a
                // claim about a string that is not there.
                $none = self::rowsFor([
                    ['rule_id' => 'r0', 'severity' => 'low', 'title' => 't', 'framework_refs' => '', 'state' => 'open'],
                ]);
                sac_assert(!array_key_exists('framework_refs_sent', $none[0]), 'no refs sent = no count sent');
            },

            // The normalisation itself, stated as values rather than as a
            // count, so a regression says what changed and not merely that a
            // number moved.
            'affected/normalises-refs-like-riskregister' => static function (): void {
                $c = self::capRefs('CISv7: 4,CISv7:4,junk,CISv7:,:19,CISv7:19');
                sac_assert_eq($c['refs'], 'CISv7:4,CISv7:19', 'trimmed around each part, malformed entries dropped, duplicates collapsed');
                sac_assert_eq($c['sent'], 2, 'the count describes the normalised string, not the raw input');
                sac_assert_eq($c['malformed'], 3, 'junk, an empty code and an empty framework are each malformed');
                sac_assert_eq($c['dropped'], 0, 'malformed is not dropped: dropped means a valid ref that did not fit');

                // Split on the FIRST colon only — a ref_code may contain one.
                $colon = self::capRefs('CISv7:4:a , CISv7:4:a');
                sac_assert_eq($colon['refs'], 'CISv7:4:a', 'a code containing a colon survives whole and still dedupes');
                sac_assert_eq($colon['sent'], 1, 'one distinct ref');

                // Dedup must run on the NORMALISED form, or these stay two.
                $equiv = self::capRefs('CISv7: 4, CISv7:4 ');
                sac_assert_eq($equiv['sent'], 1, 'CISv7: 4 and CISv7:4 are the same ref to riskregister, so they must be one to us');
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
