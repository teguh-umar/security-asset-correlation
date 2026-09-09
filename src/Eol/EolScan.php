<?php

namespace GlpiPlugin\Sac\Eol;

use GlpiPlugin\Sac\AssetResolver;
use GlpiPlugin\Sac\Code;
use GlpiPlugin\Sac\Config;
use GlpiPlugin\Sac\FindingWriter;
use GlpiPlugin\Sac\Http;
use GlpiPlugin\Sac\Reconcile;
use GlpiPlugin\Sac\RunLog;
use GlpiPlugin\Sac\Severity;

/**
 * Module 1 orchestration — EOL/EOS scan.
 *
 * `run()` walks every OperatingSystem that has at least one Computer, resolves an
 * end-of-life date (fields "LifeCycle" first, then the endoflife.date mirror),
 * grades it against the configured horizon and emits one `Finding` per OS through
 * {@see FindingWriter}. Hardware-warranty EOL is a second pass gated on
 * `eol_hardware_enabled`. `refreshCache()` re-fetches stale endoflife.date rows.
 *
 * Every mutation of riskregister state goes through {@see FindingWriter}; every
 * reconcile signal through {@see Reconcile}. This class never writes GLPI core
 * tables or the `fields` tables.
 */
final class EolScan
{
    /** Product slug used by the VM selfChecks' synthetic endoflife.date rows. */
    private const SC_PRODUCT = '__sac_sc_eol__';

    public static function run(RunLog $run): void
    {
        global $DB;

        $entity    = (int) Config::get('finding_entity');
        $recursive = (int) Config::get('finding_recursive');

        // Hoist per-run-stable lookups out of the OS loop (I7): the severity
        // thresholds and the Obsolescence category id do not change within a run.
        $horizonDays = (int) Config::get('eol_horizon_days');
        $highDays    = (int) Config::get('eol_severity_high_days');
        $mediumDays  = (int) Config::get('eol_severity_medium_days');
        $obsolescenceCatId = FindingWriter::categoryId('Obsolescence', $entity);

        /** @var array<int,string> composite reconcile keys seen this run */
        $seen = [];

        foreach ($DB->request([
            'SELECT'     => [
                'glpi_operatingsystems.id AS id',
                'glpi_operatingsystems.name AS name',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_operatingsystems',
            'INNER JOIN' => [
                'glpi_items_operatingsystems' => [
                    'ON' => [
                        'glpi_items_operatingsystems' => 'operatingsystems_id',
                        'glpi_operatingsystems'       => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_items_operatingsystems.itemtype' => 'Computer',
            ],
        ]) as $osRow) {
            $osId   = (int) $osRow['id'];
            $osName = (string) ($osRow['name'] ?? '');

            [$date, $source] = self::resolveDate($osId, $osName, $run);

            if ($date === false) {
                $seen[] = "OperatingSystem:$osId:bad_date";
                $run->bump('scanned');
                continue;
            }
            if ($date === null) {
                $seen[] = "OperatingSystem:$osId:no_mapping";
                $run->bump('scanned');
                continue;
            }

            $days = (int) floor((strtotime($date) - strtotime('today')) / 86400);
            $sev  = Severity::forEolDays($days, $horizonDays, $highDays, $mediumDays);

            $assets    = AssetResolver::computersForOs($osId);
            $hostCount = count($assets['resolved']);

            // The OS still has an inventory row, but every Computer carrying it is
            // trashed / a template. Treat as "no longer in live inventory" (spec
            // §4.5) — clear any prior Finding and stop re-asserting.
            if ($hostCount === 0) {
                FindingWriter::appendClearedNote(Code::eolOs($osId), $entity, 'OS not in live inventory', $run);
                $run->bump('scanned');
                continue;
            }

            if ($sev === null) {
                FindingWriter::appendClearedNote(
                    Code::eolOs($osId),
                    $entity,
                    'EOL beyond horizon or OS not in inventory',
                    $run
                );
                $run->bump('scanned');
                continue;
            }

            $input = [
                'entities_id'  => $entity,
                'is_recursive' => $recursive,
                'code'         => Code::eolOs($osId),
                'name'         => substr("EOL: $osName", 0, 255),
                'scope_label'  => substr("$osName ×$hostCount", 0, 255),
                'severity'     => $sev,
                'content'      => "OS: $osName\nEnd of life: $date (source: $source)\nAffected hosts:\n"
                    . implode("\n", array_map(fn ($n) => "- $n", $assets['resolved'])),
                'assessment_ref' => 'SAC/eol',
                'plugin_riskregister_riskcategories_id' => $obsolescenceCatId,
                'status'       => 'Open',
            ];

            FindingWriter::upsert($input, array_keys($assets['resolved']), '', $run);
            $run->bump('scanned');
        }

        Reconcile::autoResolveMissing('eol', $seen);

        if (Config::get('eol_hardware_enabled')) {
            self::runHardware($run, $entity, $recursive, (int) Config::get('eol_hardware_horizon_days'), $obsolescenceCatId);
        }
    }

    /**
     * Resolve the end-of-life date for one OperatingSystem.
     *
     * @return array{0:string|false|null,1:string} `[date, sourceTag]` —
     *         `string` date → a real EOL date with its source tag;
     *         `false` → a bad_date reconcile was noted, skip the OS;
     *         `null` → a no_mapping reconcile was noted, skip the OS.
     */
    private static function resolveDate(int $osId, string $osName, RunLog $run): array
    {
        // (a) fields "LifeCycle" — read-only, operator-entered.
        $life = LifeCycleReader::endOfLifeFor($osId);
        if (is_string($life)) {
            return [$life, 'lifecycle'];
        }
        if ($life === false) {
            Reconcile::note('eol', 'OperatingSystem', $osId, 'bad_date', $osName);
            return [false, ''];
        }
        // $life === null → no LifeCycle row, continue.

        // (b) _eolmap → _eolcache mirror of endoflife.date.
        $map = EolMap::forOs($osId);
        if ($map !== null) {
            $cached = EolCache::get($map['eol_product'], $map['eol_cycle']);
            if ($cached !== null && $cached['eol_date'] !== null) {
                return [$cached['eol_date'], 'endoflife.date'];
            }
            // Cache row missing / eol_date null → treat as a gap; do NOT fetch
            // synchronously. EolCacheRefresh fills it. Fall to (d).
        }

        // (c) CONTROLLER RULING: auto-match via api/all.json is DEFERRED for v1.

        // (d) nothing resolved.
        Reconcile::note('eol', 'OperatingSystem', $osId, 'no_mapping', $osName);
        return [null, ''];
    }

    /**
     * Hardware-warranty EOL pass. One Finding per Computer whose
     * `warranty_date + warranty_duration months` falls inside the hardware
     * horizon.
     *
     * @param int $hwHorizonDays  hoisted `eol_hardware_horizon_days` (I7)
     * @param int $catId          hoisted Obsolescence category id (I7)
     */
    private static function runHardware(RunLog $run, int $entity, int $recursive, int $hwHorizonDays, int $catId): void
    {
        global $DB;

        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'warranty_date', 'warranty_duration'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => [
                'is_deleted'    => 0,
                'is_template'   => 0,
                'NOT'           => ['warranty_date' => null],
                'warranty_date' => ['<>', ''],
            ],
        ]) as $c) {
            $cid          = (int) $c['id'];
            $cname        = (string) ($c['name'] ?? '');
            $warrantyDate = (string) $c['warranty_date'];

            // Zero / unparseable warranty date — skip (strtotime() would fall to
            // the 1970 epoch and manufacture a spurious Critical).
            $endTs = strtotime($warrantyDate . ' +' . (int) $c['warranty_duration'] . ' months');
            if ($endTs === false || str_starts_with($warrantyDate, '0000')) {
                continue;
            }

            $endDate = date('Y-m-d', $endTs);
            $days    = (int) floor((strtotime($endDate) - strtotime('today')) / 86400);
            $sev     = Severity::forEolDays($days, $hwHorizonDays, 90, 180);

            if ($sev === null) {
                FindingWriter::appendClearedNote(Code::eolHw($cid), $entity, 'warranty end beyond horizon', $run);
                $run->bump('scanned');
                continue;
            }

            $input = [
                'entities_id'  => $entity,
                'is_recursive' => $recursive,
                'code'         => Code::eolHw($cid),
                'name'         => substr("Hardware EOL: $cname", 0, 255),
                'scope_label'  => substr($cname, 0, 255),
                'severity'     => $sev,
                'content'      => "Hardware EOL: $cname\nWarranty end: $endDate (warranty_date $warrantyDate + "
                    . (int) $c['warranty_duration'] . " months)",
                'assessment_ref' => 'SAC/eol',
                'plugin_riskregister_riskcategories_id' => $catId,
                'status'       => 'Open',
            ];

            FindingWriter::upsert($input, [$cid], '', $run);
            $run->bump('scanned');
        }
    }

    /**
     * Re-fetch endoflife.date rows whose local mirror is missing or stale.
     * Network / shape failures bump `errors` and are skipped — never fatal.
     */
    public static function refreshCache(RunLog $run): void
    {
        foreach (EolCache::staleProducts((int) Config::get('eol_cache_max_age_days')) as $p) {
            $body = Http::get('https://endoflife.date/api/' . rawurlencode($p['product']) . '.json');
            if ($body === null) {
                $run->bump('errors');
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                $run->bump('errors');
                continue;
            }
            foreach ($data as $entry) {
                if (!is_array($entry) || (string) ($entry['cycle'] ?? '') !== $p['cycle']) {
                    continue;
                }
                $eol     = $entry['eol'] ?? null;
                $support = $entry['support'] ?? null;
                EolCache::upsert($p['product'], $p['cycle'], [
                    'eol_date' => is_string($eol) ? $eol : null,
                    'eos_date' => is_string($support) ? $support : null,
                    'is_lts'   => (bool) ($entry['lts'] ?? false),
                    'raw_json' => json_encode($entry),
                ]);
                break;
            }
        }
    }

    // ---------------------------------------------------------------------
    // VM-only selfChecks (db/ prefix) — spec §9.2 EOL-1..EOL-5.
    // ---------------------------------------------------------------------

    /**
     * Seed a throwaway OperatingSystem + Computer + link, and (optionally) an
     * endoflife.date mapping+cache row with the given EOL date.
     *
     * Core rows are created with `->add()` and the generated ids captured/returned
     * (no hardcoded ~2.1e9 ids — those permanently bump InnoDB AUTO_INCREMENT).
     *
     * @return array{osId:int,cid:int}
     */
    private static function scSeed(?string $eolDate): array
    {
        global $DB;

        $osId = (int) (new \OperatingSystem())->add(['name' => '__sac_sc_os__']);
        $cid  = (int) (new \Computer())->add([
            'name'        => '__sac_sc_pc__',
            'entities_id' => 0,
        ]);
        $DB->insert('glpi_items_operatingsystems', [
            'items_id'            => $cid,
            'itemtype'            => 'Computer',
            'operatingsystems_id' => $osId,
        ]);
        if ($eolDate !== null) {
            EolMap::putConfirmed($osId, self::SC_PRODUCT, '1', 1);
            EolCache::upsert(self::SC_PRODUCT, '1', ['eol_date' => $eolDate, 'raw_json' => '{}']);
        }

        return ['osId' => $osId, 'cid' => $cid];
    }

    /** Seed a real `fields` LifeCycle row for one OS. Requires LifeCycleReader::available(). */
    private static function scSeedLifecycle(int $osId, string $rawDate): void
    {
        global $DB;
        $table = LifeCycleReader::tableName();
        $col   = LifeCycleReader::dateColumnName();
        $row   = ['items_id' => $osId, $col => $rawDate];
        if ($DB->fieldExists($table, 'itemtype')) {
            $row['itemtype'] = 'OperatingSystem';
        }
        if ($DB->fieldExists($table, 'entities_id')) {
            $row['entities_id'] = 0;
        }
        $DB->insert($table, $row);
    }

    /** Highest `glpi_plugin_sac_run.id` right now — the floor for selfCheck cleanup. */
    private static function scRunFloor(): int
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['MAX(id) AS m'], 'FROM' => 'glpi_plugin_sac_run'])->current();
        return (int) (is_array($row) ? ($row['m'] ?? 0) : 0);
    }

    /**
     * Tear every seeded row for a selfCheck back down. Safe to call twice.
     * Also deletes any `glpi_plugin_sac_run` rows this check's RunLog::begin('eol')
     * calls created (id > $sinceRunId) so selfCheck runs never pollute audit history.
     */
    private static function scTeardown(array $osIds, array $cids, array $codes, int $sinceRunId = 0): void
    {
        global $DB;

        $DB->delete('glpi_plugin_sac_run', ['module' => 'eol', 'id' => ['>', $sinceRunId]]);

        if (FindingWriter::isAvailable()) {
            $f  = new \GlpiPlugin\Riskregister\Finding();
            $fi = new \GlpiPlugin\Riskregister\Finding_Item();
            foreach ($codes as $code) {
                foreach (array_keys($f->find(['code' => $code])) as $fid) {
                    foreach (array_keys($fi->find(['plugin_riskregister_findings_id' => (int) $fid])) as $liid) {
                        $fi->delete(['id' => (int) $liid], true);
                    }
                    $f->delete(['id' => (int) $fid], true);
                }
            }
        }

        $lcTable = \Plugin::isPluginActive('fields') ? LifeCycleReader::tableName() : '';

        foreach ($osIds as $osId) {
            $osId = (int) $osId;
            if ($osId <= 0) {
                continue;
            }
            $DB->delete('glpi_plugin_sac_eolmap', ['operatingsystems_id' => $osId]);
            $DB->delete('glpi_items_operatingsystems', ['operatingsystems_id' => $osId]);
            $DB->delete('glpi_operatingsystems', ['id' => $osId]);
            $DB->delete('glpi_plugin_sac_reconcile', ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId]);
            if ($lcTable !== '' && $DB->fieldExists($lcTable, 'items_id')) {
                $DB->delete($lcTable, ['items_id' => $osId]);
            }
        }
        $DB->delete('glpi_plugin_sac_eolcache', ['eol_product' => self::SC_PRODUCT]);
        foreach ($cids as $cid) {
            if ((int) $cid > 0) {
                $DB->delete('glpi_computers', ['id' => (int) $cid]);
            }
        }
    }

    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        return [
            // EOL-1 — LifeCycle path ONLY. Seeds a real `fields` LifeCycle row
            // (no _eolmap/_eolcache) and asserts the Finding names the lifecycle
            // source. Skips with a logged note when `fields` LifeCycle is absent
            // on this VM (the VM checklist already flags confirming that table).
            'db/eol-1-lifecycle-source' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                if (!LifeCycleReader::available() || LifeCycleReader::dateColumnName() === '') {
                    echo "SKIP  db/eol-1-lifecycle-source — fields LifeCycle table/column not present on this VM\n";
                    return;
                }
                $seed = self::scSeed(null); // no endoflife.date mapping
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId);
                $startId = self::scRunFloor();
                try {
                    self::scSeedLifecycle($osId, $yesterday);
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'EOL-1: exactly one Finding via the LifeCycle date');
                    $row = $rows[array_key_first($rows)];
                    sac_assert_eq($row['severity'], 'Critical', 'EOL-1: severity Critical');
                    sac_assert(
                        str_contains((string) $row['content'], 'source: lifecycle'),
                        'EOL-1: content names the lifecycle source'
                    );

                    $fi    = new \GlpiPlugin\Riskregister\Finding_Item();
                    $links = $fi->find([
                        'plugin_riskregister_findings_id' => (int) array_key_first($rows),
                        'itemtype'                        => 'Computer',
                        'items_id'                        => $cid,
                    ]);
                    sac_assert_eq(count($links), 1, 'EOL-1: one Finding_Item to the seeded Computer');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // EOL-lifecycle-wins — LifeCycle date beats a differing endoflife.date
            // mirror for the same OS.
            'db/eol-lifecycle-wins' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                if (!LifeCycleReader::available() || LifeCycleReader::dateColumnName() === '') {
                    echo "SKIP  db/eol-lifecycle-wins — fields LifeCycle table/column not present on this VM\n";
                    return;
                }
                // endoflife.date mirror says +200 days (would be Low); LifeCycle
                // says yesterday (Critical).
                $seed = self::scSeed(date('Y-m-d', strtotime('+200 days')));
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId);
                $startId = self::scRunFloor();
                try {
                    self::scSeedLifecycle($osId, $yesterday);
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'EOL-lifecycle-wins: one Finding');
                    $row = $rows[array_key_first($rows)];
                    sac_assert(str_contains((string) $row['content'], 'source: lifecycle'), 'lifecycle source wins');
                    sac_assert(str_contains((string) $row['content'], $yesterday), 'lifecycle date used, not the mirror date');
                    sac_assert_eq($row['severity'], 'Critical', 'graded on the LifeCycle (past) date');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // EOL-bad-date — a LifeCycle row with 0000-00-00 yields no Finding and
            // one bad_date reconcile row.
            'db/eol-bad-date' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                if (!LifeCycleReader::available() || LifeCycleReader::dateColumnName() === '') {
                    echo "SKIP  db/eol-bad-date — fields LifeCycle table/column not present on this VM\n";
                    return;
                }
                global $DB;
                $seed = self::scSeed(null);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId);
                $startId = self::scRunFloor();
                try {
                    self::scSeedLifecycle($osId, '0000-00-00');
                    self::run(RunLog::begin('eol'));

                    $f = new \GlpiPlugin\Riskregister\Finding();
                    sac_assert_eq(count($f->find(['code' => $code])), 0, 'EOL-bad-date: no Finding for an unparseable LifeCycle date');

                    $rc = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_reconcile',
                        'WHERE' => [
                            'signal_type'  => 'eol',
                            'ref_itemtype' => 'OperatingSystem',
                            'ref_id'       => $osId,
                            'reason'       => 'bad_date',
                        ],
                    ])->current();
                    sac_assert($rc !== null, 'EOL-bad-date: a bad_date reconcile row was written');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // EOL-2 — re-run after the endoflife.date date moves in-horizon → same
            // row, re-graded in place.
            'db/eol-2-update-in-place' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                $seed = self::scSeed($yesterday);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    EolCache::upsert(self::SC_PRODUCT, '1', [
                        'eol_date' => date('Y-m-d', strtotime('+120 days')),
                        'raw_json' => '{}',
                    ]);
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'EOL-2: still exactly one Finding row');
                    sac_assert_eq($rows[array_key_first($rows)]['severity'], 'Medium', 'EOL-2: severity re-graded to Medium');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // EOL-3 — no LifeCycle, no _eolmap → no Finding, one no_mapping reconcile.
            'db/eol-3-no-mapping-reconcile' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed = self::scSeed(null); // no mapping
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    $f = new \GlpiPlugin\Riskregister\Finding();
                    sac_assert_eq(count($f->find(['code' => $code])), 0, 'EOL-3: no Finding without a resolvable date');

                    $rc = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_reconcile',
                        'WHERE' => [
                            'signal_type'  => 'eol',
                            'ref_itemtype' => 'OperatingSystem',
                            'ref_id'       => $osId,
                            'reason'       => 'no_mapping',
                        ],
                    ])->current();
                    sac_assert($rc !== null, 'EOL-3: a no_mapping reconcile row was written');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // EOL-4 — a Finding that drops beyond the horizon gains a cleared note.
            'db/eol-4-signal-cleared' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed = self::scSeed($yesterday);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    $f       = new \GlpiPlugin\Riskregister\Finding();
                    $rows0   = $f->find(['code' => $code]);
                    $before  = $rows0[array_key_first($rows0)];
                    sac_assert_eq($before['severity'], 'Critical', 'EOL-4: starts Critical');
                    $status0 = $before['status'];

                    EolCache::upsert(self::SC_PRODUCT, '1', [
                        'eol_date' => date('Y-m-d', strtotime('+400 days')),
                        'raw_json' => '{}',
                    ]);
                    $run = RunLog::begin('eol');
                    self::run($run);
                    $run->finish('ok');

                    $rows1 = $f->find(['code' => $code]);
                    $after = $rows1[array_key_first($rows1)];
                    sac_assert(str_contains((string) $after['content'], 'Signal cleared'), 'EOL-4: content gains a cleared note');
                    sac_assert_eq($after['status'], $status0, 'EOL-4: status unchanged by the clear');

                    $runRow  = $DB->request(['FROM' => 'glpi_plugin_sac_run', 'WHERE' => ['id' => $run->id()]])->current();
                    $counts  = json_decode((string) $runRow['counts_json'], true);
                    sac_assert((int) ($counts['findings_cleared'] ?? 0) >= 1, 'EOL-4: findings_cleared counted');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // EOL-5 — endoflife.date-only path (no LifeCycle row) is reported as
            // the source in `content`.
            'db/eol-5-endoflife-source' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                $seed = self::scSeed($yesterday);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'EOL-5: Finding created via the endoflife.date map');
                    $row = $rows[array_key_first($rows)];
                    sac_assert(str_contains((string) $row['content'], 'source: endoflife.date'), 'EOL-5: content names the endoflife.date source');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },
        ];
    }
}
