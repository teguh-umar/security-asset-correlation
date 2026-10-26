<?php

namespace GlpiPlugin\Sac;

/**
 * The single point through which SAC creates/updates `riskregister` Finding
 * records. Nothing else in this plugin may touch `\GlpiPlugin\Riskregister\*`.
 *
 * All methods degrade safely when riskregister is absent: writes become a
 * `$run->bump('errors')` + null, reads become a no-op.
 */
final class FindingWriter
{
    /** @var array<int,string> keys never forwarded on the update path */
    private const STRIP_ON_UPDATE = ['status', 'id', 'is_deleted', 'date_creation', 'date_mod'];

    /**
     * True only when riskregister is installed, active, and its namespaced
     * Finding class is loadable. `class_exists('PluginRiskregisterFinding')` is
     * ALWAYS false for this plugin — never test that legacy name.
     */
    public static function isAvailable(): bool
    {
        return \Plugin::isPluginActive('riskregister')
            && class_exists(\GlpiPlugin\Riskregister\Finding::class);
    }

    /**
     * PURE. The subset of an add-payload that is safe to re-send on an update:
     * everything except lifecycle/identity columns and `status` (SAC never
     * reopens or force-closes a finding a human may have triaged).
     */
    public static function mergeableOnUpdate(array $input): array
    {
        return array_diff_key($input, array_flip(self::STRIP_ON_UPDATE));
    }

    /**
     * Find a Finding by (entities_id, code); update it (status-preserving) or
     * add it; then ensure one Finding_Item link per computer id (plus any
     * `$extraLinks`). Idempotent.
     *
     * @param array<string,mixed> $input       full add-payload (caller-built)
     * @param array<int,int|string> $computerIds  GLPI Computer ids to link
     * @param string $rawAssetText  the un-resolved asset string, for audit
     * @param array<int,array{itemtype:string,items_id:int}> $extraLinks
     *        Additional `Finding_Item` links beyond Computer — e.g. the
     *        affected `Software` item(s), so riskregister's own native
     *        "Findings" tab (already present on Software via `addtabon`,
     *        confirmed with risk-register-dashboard-c8) actually shows a
     *        SAC license Finding there, not just on the Computer side.
     * @return int|null  the Finding id, or null when riskregister is absent /
     *                    the add failed
     */
    public static function upsert(array $input, array $computerIds, string $rawAssetText, RunLog $run, array $extraLinks = []): ?int
    {
        if (!self::isAvailable()) {
            $run->bump('errors');
            return null;
        }

        if ($rawAssetText !== '') {
            $input['raw_asset_text'] = Text::clip($rawAssetText);
        }
        // Last line of defence for the other varchar(255) column: a caller-built
        // label (a long asset or product list) must never reach MySQL unclipped.
        if (isset($input['scope_label'])) {
            $input['scope_label'] = Text::clip((string) $input['scope_label']);
        }

        // Never recursive: in this instance a sub-entity is another company, and
        // a recursive finding would show its host list to every one of them.
        // Sent on the update path too, so a pre-v1.5.0 recursive row is fixed
        // the first time the scan re-asserts it.
        $input['is_recursive'] = 0;
        $entity = (int) $input['entities_id'];

        $f   = new \GlpiPlugin\Riskregister\Finding();
        $hit = $f->find(['entities_id' => $entity, 'code' => $input['code']]);

        if ($hit) {
            $id = (int) array_key_first($hit);
            $f->update(['id' => $id] + self::mergeableOnUpdate($input));
        } else {
            $new = $f->add($input);
            if ($new === false) {
                $run->bump('errors');
                \Toolbox::logInFile('sac', 'Finding->add failed: ' . json_encode($input) . "\n");
                return null;
            }
            $id = (int) $new;
        }
        $run->bump('findings_upserted');

        foreach ($computerIds as $cid) {
            self::linkOnce($id, 'Computer', (int) $cid);
        }
        foreach ($extraLinks as $link) {
            self::linkOnce($id, (string) $link['itemtype'], (int) $link['items_id']);
        }
        self::unlinkForeignComputers($id, $entity);

        return $id;
    }

    /**
     * Finding_Item ids linking $findingId to a Computer that belongs to
     * another entity than the finding. Read-only.
     *
     * @return array<int,int>
     */
    private static function foreignComputerLinks(int $findingId, int $entity): array
    {
        global $DB;

        $table = \GlpiPlugin\Riskregister\Finding_Item::getTable();
        $ids   = [];
        foreach ($DB->request([
            'SELECT'     => ["$table.id AS id"],
            'FROM'       => $table,
            'INNER JOIN' => [
                'glpi_computers' => [
                    'ON' => [
                        $table           => 'items_id',
                        'glpi_computers' => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                "$table.plugin_riskregister_findings_id" => $findingId,
                "$table.itemtype"                        => 'Computer',
                'glpi_computers.entities_id'             => ['<>', $entity],
            ],
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    /**
     * Remove the links found by {@see foreignComputerLinks()}, through the
     * relation's own API. Those assets get (or already have) a finding of
     * their own in their own entity.
     */
    private static function unlinkForeignComputers(int $findingId, int $entity): int
    {
        $ids = self::foreignComputerLinks($findingId, $entity);
        $li  = new \GlpiPlugin\Riskregister\Finding_Item();
        foreach ($ids as $linkId) {
            $li->delete(['id' => $linkId], true);
        }
        return count($ids);
    }

    /**
     * Every finding carrying $code in an entity NOT in $keepEntities stops
     * being asserted: it is made non-recursive, loses its links to other
     * entities' Computers and gains a dated cleared note. `$keepEntities = []`
     * clears the code everywhere. This is also what re-homes a pre-v1.5.0
     * Root finding: Root keeps the row, its status and its risks, and only
     * its own assets.
     *
     * A finding in an entity outside $scope is not scanned, so nothing is
     * known about it: it is still made non-recursive and loses foreign links,
     * but gets no cleared note.
     *
     * @param array<int,int> $keepEntities entities whose finding was just upserted
     * @param array<int,int>|null $scope {@see Config::scanScope()}; null = every entity
     * @return int number of findings that actually gained a cleared note
     */
    public static function clearExcept(string $code, array $keepEntities, string $why, RunLog $run, ?array $scope = null): int
    {
        if (!self::isAvailable()) {
            return 0;
        }

        $cleared = 0;
        $f = new \GlpiPlugin\Riskregister\Finding();
        foreach ($f->find(['code' => $code]) as $id => $row) {
            $entity = (int) $row['entities_id'];
            if (in_array($entity, $keepEntities, true)) {
                continue;
            }
            self::unlinkForeignComputers((int) $id, $entity);
            if ($scope !== null && !in_array($entity, $scope, true)) {
                if ((int) ($row['is_recursive'] ?? 0) !== 0) {
                    $f->update(['id' => (int) $id, 'is_recursive' => 0]);
                }
                continue;
            }
            if (self::appendClearedNote((int) $id, $row, $why, $run)) {
                $cleared++;
            }
        }
        return $cleared;
    }

    /**
     * One-off report (and, with $apply, repair) over every SAC-written
     * finding, including the ones no scan asserts any more (Closed,
     * superseded, cleared). Used by tools/entity_isolate.php.
     *
     * @return array<int,array{id:int,code:string,entities_id:int,is_recursive:int,status:string,foreign_links:int,risks:int}>
     */
    public static function isolateAll(bool $apply): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $f      = new \GlpiPlugin\Riskregister\Finding();
        $report = [];
        foreach ($f->find(['assessment_ref' => ['LIKE', 'SAC/%']]) as $id => $row) {
            $id        = (int) $id;
            $entity    = (int) $row['entities_id'];
            $recursive = (int) ($row['is_recursive'] ?? 0);
            $foreign   = count(self::foreignComputerLinks($id, $entity));
            $risks     = class_exists(\GlpiPlugin\Riskregister\Risk::class)
                ? count((new \GlpiPlugin\Riskregister\Risk())->find(['plugin_riskregister_findings_id' => $id]))
                : 0;

            $report[] = [
                'id'            => $id,
                'code'          => (string) $row['code'],
                'entities_id'   => $entity,
                'is_recursive'  => $recursive,
                'status'        => (string) $row['status'],
                'foreign_links' => $foreign,
                'risks'         => $risks,
            ];

            if ($apply) {
                if ($recursive !== 0) {
                    $f->update(['id' => $id, 'is_recursive' => 0]);
                }
                self::unlinkForeignComputers($id, $entity);
            }
        }
        return $report;
    }

    /** Idempotent: add a `Finding_Item` link only if it doesn't already exist. */
    private static function linkOnce(int $findingId, string $itemtype, int $itemsId): void
    {
        $li  = new \GlpiPlugin\Riskregister\Finding_Item();
        $has = $li->find([
            'plugin_riskregister_findings_id' => $findingId,
            'itemtype'                        => $itemtype,
            'items_id'                        => $itemsId,
        ]);
        if (!$has) {
            $li->add([
                'plugin_riskregister_findings_id' => $findingId,
                'itemtype'                        => $itemtype,
                'items_id'                        => $itemsId,
            ]);
        }
    }

    /**
     * Resolve a category name to its riskregister id in $entity.
     *
     * `ImportSupport::categoryIdByName()` find-or-**creates** a RiskCategory
     * (confirmed in riskregister's source) — it does NOT return 0 on a miss.
     * SAC relies on riskregister v1.1.0+ having seeded the 4 canonical category
     * names (Obsolescence, License Compliance, …) so no duplicate rows are
     * created here. Returns 0 only when riskregister is absent or the name is
     * blank; callers pass 0 straight through.
     */
    public static function categoryId(string $name, int $entity): int
    {
        if (!class_exists(\GlpiPlugin\Riskregister\Import\ImportSupport::class)) {
            return 0;
        }
        return (int) \GlpiPlugin\Riskregister\Import\ImportSupport::categoryIdByName($name, $entity);
    }

    /**
     * Append a dated "Signal cleared" line to an existing Finding's `content`
     * and make the row non-recursive. Never touches `status` or `severity`.
     *
     * One note per clearing: if `content` already carries a "Signal cleared
     * on" note the call adds nothing (no counter bump). A re-assert rewrites
     * `content` from scratch, so a note present means "cleared since the last
     * assert". Until v1.4.x this was only idempotent per day, and a finding
     * that stayed cleared gained one note per weekly scan; since v1.5.0 a
     * pre-split Root finding can stay cleared for good, so that would grow
     * without bound.
     */
    private static function appendClearedNote(int $id, array $row, string $why, RunLog $run): bool
    {
        $f        = new \GlpiPlugin\Riskregister\Finding();
        $existing = (string) ($row['content'] ?? '');

        if (str_contains($existing, 'Signal cleared on ')) {
            // already cleared — only the recursive flag may still need fixing
            if ((int) ($row['is_recursive'] ?? 0) !== 0) {
                $f->update(['id' => $id, 'is_recursive' => 0]);
            }
            return false;
        }

        $note = "\n\n---\nSignal cleared on " . date('Y-m-d') . ': ' . $why;
        $f->update(['id' => $id, 'is_recursive' => 0, 'content' => $existing . $note]);
        $run->bump('findings_cleared');
        return true;
    }

    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        return [
            // PURE — runs in tools/offline-selfcheck.php
            'writer/merge-strips-status' => static function (): void {
                $in  = ['code' => 'X', 'severity' => 'High', 'status' => 'Open', 'name' => 'n'];
                $out = self::mergeableOnUpdate($in);
                sac_assert(!array_key_exists('status', $out), 'status stripped');
                sac_assert_eq($out['severity'], 'High', 'severity kept');
                sac_assert_eq($out['code'], 'X', 'code kept');
                // identity / lifecycle columns also go
                $in2  = ['id' => 5, 'is_deleted' => 1, 'date_creation' => 'x', 'date_mod' => 'y', 'name' => 'k'];
                $out2 = self::mergeableOnUpdate($in2);
                sac_assert_eq($out2, ['name' => 'k'], 'id/is_deleted/date_* all stripped');
            },

            // VM-only — skipped offline (db/ prefix), runs on the VM in Task 12
            'db/writer-upsert-idempotent' => static function (): void {
                if (!self::isAvailable()) {
                    return;
                }
                $run  = RunLog::begin('selftest');
                $code = '__SAC_SC_UPSERT__';
                $ent  = 0;
                $input = [
                    'entities_id' => $ent,
                    'code'        => $code,
                    'name'        => 'SAC selfcheck upsert',
                    'scope_label' => 'selftest',
                    'severity'    => 'Low',
                    'status'      => 'Open',
                ];

                $comp = new \Computer();
                $cid  = (int) $comp->add(['name' => '__sac_sc_comp__', 'entities_id' => $ent]);
                sac_assert($cid > 0, 'seed computer created');

                $f = new \GlpiPlugin\Riskregister\Finding();
                try {
                    $id1 = self::upsert($input, [$cid], 'raw asset text', $run);
                    $id2 = self::upsert($input, [$cid], 'raw asset text', $run);
                    sac_assert($id1 !== null && $id1 === $id2, 'same finding id on re-run');

                    $rows = $f->find(['entities_id' => $ent, 'code' => $code]);
                    sac_assert_eq(count($rows), 1, 'exactly one Finding row');

                    $li    = new \GlpiPlugin\Riskregister\Finding_Item();
                    $links = $li->find([
                        'plugin_riskregister_findings_id' => $id1,
                        'itemtype'                        => 'Computer',
                        'items_id'                        => $cid,
                    ]);
                    sac_assert_eq(count($links), 1, 'exactly one Finding_Item link');
                } finally {
                    // teardown must run even on an assert failure above, or the
                    // fixed-code Finding __SAC_SC_UPSERT__ leaks into real data.
                    foreach (array_keys($f->find(['entities_id' => $ent, 'code' => $code])) as $fid) {
                        $f->delete(['id' => (int) $fid], true);
                    }
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            // VM-only — a pre-v1.5.0 finding (root, recursive, linked to two
            // entities' Computers) is split: root keeps the row and its own
            // asset, the other entity gets its own row, nothing stays recursive.
            'db/writer-entity-split' => static function (): void {
                if (!self::isAvailable()) {
                    return;
                }
                global $DB;
                $other = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_entities',
                    'WHERE'  => ['id' => ['>', 0]],
                    'LIMIT'  => 1,
                ])->current();
                if (!$other) {
                    echo "SKIP  db/writer-entity-split — this GLPI has only the root entity\n";
                    return;
                }
                $e2   = (int) $other['id'];
                $run  = RunLog::begin('selftest');
                $code = '__SAC_SC_SPLIT__';
                $base = [
                    'code'        => $code,
                    'name'        => 'SAC selfcheck entity split',
                    'scope_label' => 'selftest',
                    'severity'    => 'Low',
                    'status'      => 'Open',
                ];

                $c0 = (int) (new \Computer())->add(['name' => '__sac_sc_split_root__', 'entities_id' => 0]);
                $c2 = (int) (new \Computer())->add(['name' => '__sac_sc_split_sub__', 'entities_id' => $e2]);
                sac_assert($c0 > 0 && $c2 > 0, 'seed computers created');

                $f  = new \GlpiPlugin\Riskregister\Finding();
                $fi = new \GlpiPlugin\Riskregister\Finding_Item();
                $linked = static function (int $findingId) use ($fi): array {
                    $ids = array_map(
                        static fn (array $l): int => (int) $l['items_id'],
                        $fi->find(['plugin_riskregister_findings_id' => $findingId, 'itemtype' => 'Computer'])
                    );
                    sort($ids);
                    return array_values($ids);
                };
                try {
                    $old = (int) $f->add(['entities_id' => 0, 'is_recursive' => 1] + $base);
                    sac_assert($old > 0, 'old-shape finding created');
                    self::linkOnce($old, 'Computer', $c0);
                    self::linkOnce($old, 'Computer', $c2);

                    $id0 = self::upsert(['entities_id' => 0] + $base, [$c0], '', $run);
                    $id2 = self::upsert(['entities_id' => $e2] + $base, [$c2], '', $run);
                    sac_assert_eq($id0, $old, 'root keeps the existing row');
                    sac_assert($id2 !== null && $id2 !== $old, 'the other entity gets its own row');

                    $f->getFromDB($old);
                    sac_assert_eq((int) $f->fields['is_recursive'], 0, 'root row is no longer recursive');
                    sac_assert_eq($linked($old), [$c0], 'root row keeps only root\'s Computer');

                    $f->getFromDB($id2);
                    sac_assert_eq((int) $f->fields['entities_id'], $e2, 'new row is in the Computer\'s entity');
                    sac_assert_eq((int) $f->fields['is_recursive'], 0, 'new row is not recursive');
                    sac_assert_eq($linked($id2), [$c2], 'new row links only its own entity\'s Computer');

                    self::clearExcept($code, [$e2], 'selftest', $run);
                    $f->getFromDB($old);
                    sac_assert(str_contains((string) $f->fields['content'], 'Signal cleared'), 'entity not kept gets the cleared note');
                    $f->getFromDB($id2);
                    sac_assert(!str_contains((string) $f->fields['content'], 'Signal cleared'), 'kept entity is left alone');
                } finally {
                    foreach (array_keys($f->find(['code' => $code])) as $fid) {
                        foreach (array_keys($fi->find(['plugin_riskregister_findings_id' => (int) $fid])) as $liid) {
                            $fi->delete(['id' => (int) $liid], true);
                        }
                        $f->delete(['id' => (int) $fid], true);
                    }
                    (new \Computer())->delete(['id' => $c0], true);
                    (new \Computer())->delete(['id' => $c2], true);
                    $DB->delete('glpi_plugin_sac_run', ['id' => $run->id()]);
                }
            },

            // VM-only — the $extraLinks mechanism itself, independent of any
            // one caller (LicenseScan exercises it end-to-end separately).
            'db/writer-extralinks-idempotent' => static function (): void {
                if (!self::isAvailable()) {
                    return;
                }
                $run  = RunLog::begin('selftest');
                $code = '__SAC_SC_EXTRALINKS__';
                $ent  = 0;
                $input = [
                    'entities_id' => $ent,
                    'code'        => $code,
                    'name'        => 'SAC selfcheck extraLinks',
                    'scope_label' => 'selftest',
                    'severity'    => 'Low',
                    'status'      => 'Open',
                ];

                $sw = new \Software();
                $sid = (int) $sw->add(['name' => '__sac_sc_extralinks_sw__', 'entities_id' => $ent]);
                sac_assert($sid > 0, 'seed software created');

                $f  = new \GlpiPlugin\Riskregister\Finding();
                $fi = new \GlpiPlugin\Riskregister\Finding_Item();
                try {
                    $extraLinks = [['itemtype' => 'Software', 'items_id' => $sid]];
                    $id1 = self::upsert($input, [], '', $run, $extraLinks);
                    $id2 = self::upsert($input, [], '', $run, $extraLinks);
                    sac_assert($id1 !== null && $id1 === $id2, 'same finding id on re-run');

                    $links = $fi->find([
                        'plugin_riskregister_findings_id' => $id1,
                        'itemtype'                         => 'Software',
                        'items_id'                         => $sid,
                    ]);
                    sac_assert_eq(count($links), 1, 'exactly one Finding_Item link after two upserts — idempotent, not doubled');
                } finally {
                    foreach (array_keys($f->find(['entities_id' => $ent, 'code' => $code])) as $fid) {
                        $f->delete(['id' => (int) $fid], true);
                    }
                    (new \Software())->delete(['id' => $sid], true);
                }
            },
        ];
    }
}
