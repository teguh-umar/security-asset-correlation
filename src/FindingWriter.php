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
     * add it; then ensure one Finding_Item link per computer id. Idempotent.
     *
     * @param array<string,mixed> $input       full add-payload (caller-built)
     * @param array<int,int|string> $computerIds  GLPI Computer ids to link
     * @param string $rawAssetText  the un-resolved asset string, for audit
     * @return int|null  the Finding id, or null when riskregister is absent /
     *                    the add failed
     */
    public static function upsert(array $input, array $computerIds, string $rawAssetText, RunLog $run): ?int
    {
        if (!self::isAvailable()) {
            $run->bump('errors');
            return null;
        }

        if ($rawAssetText !== '') {
            // substr (not mb_substr): local PHP has no mbstring. 255 = column width.
            $input['raw_asset_text'] = substr($rawAssetText, 0, 255);
        }

        $f   = new \GlpiPlugin\Riskregister\Finding();
        $hit = $f->find(['entities_id' => $input['entities_id'], 'code' => $input['code']]);

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
            $li  = new \GlpiPlugin\Riskregister\Finding_Item();
            $has = $li->find([
                'plugin_riskregister_findings_id' => $id,
                'itemtype'                        => 'Computer',
                'items_id'                        => $cid,
            ]);
            if (!$has) {
                $li->add([
                    'plugin_riskregister_findings_id' => $id,
                    'itemtype'                        => 'Computer',
                    'items_id'                        => (int) $cid,
                ]);
            }
        }

        return $id;
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
     * Append a dated "Signal cleared" line to an existing Finding's `content`.
     * Never touches `status` or `severity`. No-op if the Finding is not found
     * or riskregister is absent.
     *
     * Idempotent per day: if `content` already contains "Signal cleared on
     * <today>" the call is a no-op (no update, no counter bump). A same-day
     * re-run is silent; a genuine re-clear on a later day appends once. This
     * stops unbounded `content` growth and repeated `glpi_logs` / findings_cleared
     * inflation when a signal stays cleared across many scans.
     */
    public static function appendClearedNote(string $code, int $entitiesId, string $why, RunLog $run): void
    {
        if (!self::isAvailable()) {
            return;
        }

        $f   = new \GlpiPlugin\Riskregister\Finding();
        $hit = $f->find(['entities_id' => $entitiesId, 'code' => $code]);
        if (!$hit) {
            return;
        }

        $id       = (int) array_key_first($hit);
        $row      = reset($hit);
        $existing = (string) ($row['content'] ?? '');

        if (str_contains($existing, 'Signal cleared on ' . date('Y-m-d'))) {
            return; // already cleared today — nothing to do
        }

        $note = "\n\n---\nSignal cleared on " . date('Y-m-d') . ': ' . $why;
        $f->update(['id' => $id, 'content' => $existing . $note]);
        $run->bump('findings_cleared');
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
        ];
    }
}
