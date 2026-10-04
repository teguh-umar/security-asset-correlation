<?php

namespace GlpiPlugin\Sac\Eol;

use GlpiPlugin\Sac\AssetResolver;
use GlpiPlugin\Sac\Code;
use GlpiPlugin\Sac\Config;
use GlpiPlugin\Sac\EolMatch;
use GlpiPlugin\Sac\FindingWriter;
use GlpiPlugin\Sac\Http;
use GlpiPlugin\Sac\Reconcile;
use GlpiPlugin\Sac\RunLog;
use GlpiPlugin\Sac\Severity;

/**
 * Module 1 orchestration — EOL/EOS scan, v1.1.0 grain: one Finding per
 * (operatingsystems_id, operatingsystemversions_id) pair (spec
 * 2026-09-10-eol-multidim-design.md §6), because GLPI 11 splits an OS across
 * dropdown dimensions and the OS name/id alone is not a valid EOL key.
 *
 * `run()` is two-pass:
 *   Pass 1 ({@see resolveMapping()}) walks every pair in live inventory and
 *   resolves each to either a `fields` "LifeCycle" date or a
 *   `{product,cycle}` to look up — via an existing `_eolmap` row, or a fresh
 *   seed-CSV match ({@see EolMatch}), which auto-writes `_eolmap` on the
 *   spot. A cache-fill step ({@see EolCache::ensureFresh()}) then fetches
 *   anything newly mapped, so a brand-new match still has a dated Finding to
 *   emit the SAME run (spec §15 Q2).
 *   Pass 2 grades each resolved pair and emits/clears its Finding.
 *
 * Hardware-warranty EOL is a separate, unchanged pass gated on
 * `eol_hardware_enabled` (spec §14 — out of scope for v1.1.0).
 *
 * Every mutation of riskregister state goes through {@see FindingWriter};
 * every reconcile signal through {@see Reconcile}. This class never writes
 * GLPI core tables or the `fields` tables.
 */
final class EolScan
{
    /** Product slug used by the VM selfChecks' synthetic endoflife.date rows. */
    private const SC_PRODUCT = '__sac_sc_eol__';

    public static function run(RunLog $run): void
    {
        // Hoist per-run-stable lookups out of the pair loop (I7): the severity
        // thresholds and the Obsolescence category id do not change within a run.
        $horizonDays = (int) Config::get('eol_horizon_days');
        $highDays    = (int) Config::get('eol_severity_high_days');
        $mediumDays  = (int) Config::get('eol_severity_medium_days');
        // riskregister looks a category up by name alone; the entity is only
        // used if it has to create one, and a shared category belongs to root.
        $obsolescenceCatId = FindingWriter::categoryId('Obsolescence', 0);
        $scope = Config::scanScope(); // null = every entity

        /** @var array<int,string> composite reconcile keys seen this run */
        $seen = [];

        $pairs     = self::pairsInInventory();
        $needCache = [];
        /** @var array<string,array{kind:string,date?:string,source?:string,product?:string,cycle?:string}> */
        $resolved  = [];

        // Pass 1 — resolve every pair to a lifecycle date or a {product,cycle}.
        foreach ($pairs as $pair) {
            $key     = $pair['os_id'] . ':' . $pair['ver_id'];
            $mapping = self::resolveMapping($pair['os_id'], $pair['ver_id'], $pair['os_name'], $pair['ver_name'], $seen, $run);
            if ($mapping === null) {
                continue; // bad_date / no_mapping / skip already handled inside resolveMapping()
            }
            $resolved[$key] = $mapping;
            if ($mapping['kind'] === 'mapped') {
                $needCache[] = ['product' => $mapping['product'], 'cycle' => $mapping['cycle']];
            }
        }

        // Cache fill BETWEEN the passes — a seed-CSV match made this run still
        // gets a dated Finding this run (spec §15 Q2).
        if ($needCache !== []) {
            EolCache::ensureFresh($needCache, $run);
        }

        // Pass 2 — grade + emit using whatever the cache fill just made available.
        foreach ($pairs as $pair) {
            $osId    = $pair['os_id'];
            $verId   = $pair['ver_id'];
            $osName  = $pair['os_name'];
            $verName = $pair['ver_name'];
            $key     = "$osId:$verId";

            if (!isset($resolved[$key])) {
                continue;
            }
            $mapping = $resolved[$key];

            if ($mapping['kind'] === 'lifecycle') {
                $date   = $mapping['date'];
                $source = $mapping['source'];
            } else {
                $cached = EolCache::get($mapping['product'], $mapping['cycle']);
                if ($cached === null || $cached['eol_date'] === null) {
                    // Mapped but not dated yet (endoflife.date unreachable, or
                    // the cycle genuinely absent upstream) — self-healing, not
                    // a problem to surface. Next run / EolCacheRefresh retries.
                    $run->bump('cache_pending');
                    $run->bump('scanned');
                    continue;
                }
                $date   = $cached['eol_date'];
                $source = 'endoflife.date';
            }

            $code = Code::eolOs($osId, $verId);
            $days = (int) floor((strtotime($date) - strtotime('today')) / 86400);
            $sev  = Severity::forEolDays($days, $horizonDays, $highDays, $mediumDays);

            // One finding per entity that has an affected host, linking only
            // that entity's hosts (suite entity rule).
            $byEntity = AssetResolver::groupByEntity(AssetResolver::computersForOsVersion($osId, $verId), $scope);

            // The pair still has an inventory row, but every Computer carrying
            // it (in the scanned entities) is trashed / a template. Treat as
            // "no longer in live inventory" (spec §4.5) — clear any prior
            // Finding and stop re-asserting.
            if ($byEntity === []) {
                FindingWriter::clearExcept($code, [], 'pair not in live inventory', $run, $scope);
                $run->bump('scanned');
                continue;
            }

            if ($sev === null) {
                FindingWriter::clearExcept($code, [], 'EOL beyond horizon', $run, $scope);
                $run->bump('scanned');
                continue;
            }

            $label = trim("$osName $verName");
            foreach ($byEntity as $entity => $hosts) {
                $input = [
                    'entities_id'  => $entity,
                    'code'         => $code,
                    'name'         => substr("EOL: $label", 0, 255),
                    'scope_label'  => \GlpiPlugin\Sac\Text::clip("$label ×" . count($hosts)),
                    'severity'     => $sev,
                    'content'      => "OS: $label\nEnd of life: $date (source: $source)\nAffected hosts:\n"
                        . implode("\n", array_map(fn ($n) => "- $n", $hosts)),
                    'assessment_ref' => 'SAC/eol',
                    'plugin_riskregister_riskcategories_id' => $obsolescenceCatId,
                    'status'       => 'Open',
                ];

                FindingWriter::upsert($input, array_keys($hosts), '', $run);
            }
            FindingWriter::clearExcept($code, array_keys($byEntity), 'no affected host left in this entity', $run, $scope);
            $run->bump('scanned');
        }

        Reconcile::autoResolveMissing('eol', $seen);
        $run->bump('reconcile_open', count($seen));

        if (Config::get('eol_hardware_enabled')) {
            // A hardware-pass failure (schema drift, strict-mode date) must not
            // fail the whole EOL run — the OS pass above already stands.
            try {
                self::runHardware($run, (int) Config::get('eol_hardware_horizon_days'), $obsolescenceCatId, $scope);
            } catch (\Throwable $e) {
                $run->bump('errors');
                \Toolbox::logInFile('sac', 'EolScan hardware pass: ' . $e->getMessage() . "\n");
            }
        }
    }

    /**
     * Every distinct (operatingsystems_id, operatingsystemversions_id) pair
     * currently on a live Computer, with both dropdown names joined in.
     * LEFT JOIN on the version dropdown so ver_id = 0 (version dimension
     * empty/unset) rows survive with ver_name = ''.
     *
     * @return array<int,array{os_id:int,ver_id:int,os_name:string,ver_name:string}>
     */
    private static function pairsInInventory(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_items_operatingsystems.operatingsystems_id AS os_id',
                'glpi_items_operatingsystems.operatingsystemversions_id AS ver_id',
                'glpi_operatingsystems.name AS os_name',
                'glpi_operatingsystemversions.name AS ver_name',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_items_operatingsystems',
            'INNER JOIN' => [
                'glpi_operatingsystems' => [
                    'ON' => [
                        'glpi_items_operatingsystems' => 'operatingsystems_id',
                        'glpi_operatingsystems'       => 'id',
                    ],
                ],
            ],
            'LEFT JOIN'  => [
                'glpi_operatingsystemversions' => [
                    'ON' => [
                        'glpi_items_operatingsystems'  => 'operatingsystemversions_id',
                        'glpi_operatingsystemversions' => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_items_operatingsystems.itemtype' => 'Computer',
            ],
        ]) as $row) {
            $out[] = [
                'os_id'    => (int) $row['os_id'],
                'ver_id'   => (int) $row['ver_id'],
                'os_name'  => (string) ($row['os_name'] ?? ''),
                'ver_name' => (string) ($row['ver_name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Resolve one (os,ver) pair to either a lifecycle date or a
     * {product,cycle} to look up in the cache. Writes Reconcile signals and
     * bumps run counters for every terminal outcome (bad_date / no_mapping /
     * skip) so `run()` only has to act on a non-null return — every pair
     * bumps `scanned` exactly once, either here (terminal cases) or in
     * Pass 2 (every other case).
     *
     * @param array<int,string> $seen composite reconcile keys, updated by reference
     * @return array{kind:'lifecycle',date:string,source:string}
     *       | array{kind:'mapped',product:string,cycle:string}
     *       | null
     */
    private static function resolveMapping(
        int $osId,
        int $verId,
        string $osName,
        string $verName,
        array &$seen,
        RunLog $run
    ): ?array {
        // (a) fields "LifeCycle" — version-level wins over OS-level
        // (LifeCycleReader handles that precedence internally).
        $life = LifeCycleReader::endOfLifeFor($osId, $verId);
        if (is_string($life)) {
            return ['kind' => 'lifecycle', 'date' => $life, 'source' => 'lifecycle'];
        }
        if ($life === false) {
            Reconcile::note('eol', 'OperatingSystem', $osId, $verId, 'bad_date', trim("$osName $verName"));
            $seen[] = "OperatingSystem:$osId:$verId:bad_date";
            $run->bump('scanned');
            return null;
        }
        // $life === null -> no LifeCycle row at either level, continue.

        // (b) existing _eolmap (exact pair, or the (os,0) "version unknown" fallback).
        $map = EolMap::forOsVersion($osId, $verId);
        if ($map !== null) {
            return ['kind' => 'mapped', 'product' => $map['eol_product'], 'cycle' => $map['eol_cycle']];
        }

        // (c) seed-CSV match (spec §15 Q2 — auto-write _eolmap, resolve this run).
        $match = EolMatch::resolve($osName, $verName);
        if ($match !== null && isset($match['skip'])) {
            $run->bump('skipped');
            $run->bump('scanned');
            return null;
        }
        if ($match !== null) {
            EolMap::putAuto($osId, $verId, $match['product'], $match['cycle']);
            return ['kind' => 'mapped', 'product' => $match['product'], 'cycle' => $match['cycle']];
        }

        // (d) nothing resolved.
        Reconcile::note('eol', 'OperatingSystem', $osId, $verId, 'no_mapping', trim("$osName $verName"));
        $seen[] = "OperatingSystem:$osId:$verId:no_mapping";
        $run->bump('scanned');
        return null;
    }

    /**
     * Hardware-warranty EOL pass. One Finding per Computer whose
     * `warranty_date + warranty_duration months` falls inside the hardware
     * horizon. UNCHANGED from v1.0.1 (spec §14 — out of scope for v1.1.0).
     *
     * Warranty data lives in `glpi_infocoms` (Infocom / "Financial and
     * administrative information"), keyed by `itemtype` + `items_id` — NOT on
     * `glpi_computers` in GLPI 11. Guarded so an unexpected schema degrades to
     * a no-op instead of a fatal.
     *
     * @param int $hwHorizonDays  hoisted `eol_hardware_horizon_days` (I7)
     * @param int $catId          hoisted Obsolescence category id (I7)
     */
    private static function runHardware(RunLog $run, int $hwHorizonDays, int $catId, ?array $scope): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_infocoms') || !$DB->fieldExists('glpi_infocoms', 'warranty_date')) {
            \Toolbox::logInFile('sac', "EolScan: hardware pass skipped — glpi_infocoms.warranty_date not found\n");
            return;
        }

        foreach ($DB->request([
            'SELECT'     => [
                'glpi_computers.id AS id',
                'glpi_computers.name AS name',
                'glpi_computers.entities_id AS entities_id',
                'glpi_infocoms.warranty_date AS warranty_date',
                'glpi_infocoms.warranty_duration AS warranty_duration',
            ],
            'FROM'       => 'glpi_infocoms',
            'INNER JOIN' => [
                'glpi_computers' => [
                    'ON' => [
                        'glpi_infocoms'  => 'items_id',
                        'glpi_computers' => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_infocoms.itemtype'          => 'Computer',
                'glpi_computers.is_deleted'       => 0,
                'glpi_computers.is_template'      => 0,
                'NOT'                             => ['glpi_infocoms.warranty_date' => null],
                // -1 = permanent (never EOL), 0 = no warranty — skip both.
                'glpi_infocoms.warranty_duration' => ['>', 0],
            ],
        ]) as $c) {
            $cid          = (int) $c['id'];
            $entity       = (int) $c['entities_id']; // the finding lives where the asset lives
            if ($scope !== null && !in_array($entity, $scope, true)) {
                continue; // entity not scanned
            }
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
                FindingWriter::clearExcept(Code::eolHw($cid), [], 'warranty end beyond horizon', $run);
                $run->bump('scanned');
                continue;
            }

            $input = [
                'entities_id'  => $entity,
                'code'         => Code::eolHw($cid),
                'name'         => substr("Hardware EOL: $cname", 0, 255),
                'scope_label'  => \GlpiPlugin\Sac\Text::clip($cname),
                'severity'     => $sev,
                'content'      => "Hardware EOL: $cname\nWarranty end: $endDate (warranty_date $warrantyDate + "
                    . (int) $c['warranty_duration'] . " months)",
                'assessment_ref' => 'SAC/eol',
                'plugin_riskregister_riskcategories_id' => $catId,
                'status'       => 'Open',
            ];

            FindingWriter::upsert($input, [$cid], '', $run);
            FindingWriter::clearExcept(Code::eolHw($cid), [$entity], 'asset belongs to another entity', $run, $scope);
            $run->bump('scanned');
        }
    }

    /**
     * Re-fetch endoflife.date rows whose local mirror is missing or stale.
     * UNCHANGED from v1.0.x — kept for the weekly `EolCacheRefresh` cron, which
     * sweeps everything already in `_eolmap` regardless of this run's needs.
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
    // VM-only selfChecks (db/ prefix) — spec §9.2 / §11.2 EOL-1..5 plus the
    // new v1.1.0 pair-grain checks.
    // ---------------------------------------------------------------------

    /**
     * Seed a throwaway OperatingSystem (+ optional OperatingSystemVersion, if
     * $verName is non-empty) and a Computer linking them, and (optionally) an
     * endoflife.date mapping+cache row with the given EOL date at that exact
     * (os,ver) pair.
     *
     * Core rows are created with `->add()` and the generated ids
     * captured/returned (no hardcoded ~2.1e9 ids — those permanently bump
     * InnoDB AUTO_INCREMENT).
     *
     * @return array{osId:int,verId:int,cid:int}
     */
    private static function scSeed(?string $eolDate, string $osName = '__sac_sc_os__', string $verName = ''): array
    {
        global $DB;

        $osId  = (int) (new \OperatingSystem())->add(['name' => $osName]);
        $verId = $verName === ''
            ? 0
            : (int) (new \OperatingSystemVersion())->add(['name' => $verName, 'operatingsystems_id' => $osId]);
        $cid   = (int) (new \Computer())->add([
            'name'        => '__sac_sc_pc__',
            'entities_id' => 0,
        ]);
        $DB->insert('glpi_items_operatingsystems', [
            'items_id'                   => $cid,
            'itemtype'                   => 'Computer',
            'operatingsystems_id'        => $osId,
            'operatingsystemversions_id' => $verId,
        ]);
        if ($eolDate !== null) {
            EolMap::putConfirmed($osId, $verId, self::SC_PRODUCT, '1', 1);
            EolCache::upsert(self::SC_PRODUCT, '1', ['eol_date' => $eolDate, 'raw_json' => '{}']);
        }

        return ['osId' => $osId, 'verId' => $verId, 'cid' => $cid];
    }

    /**
     * Seed a real `fields` LifeCycle row at one level ('os' keyed by $osId, or
     * 'version' keyed by $targetId). Requires LifeCycleReader::available()
     * (checked by the caller).
     */
    private static function scSeedLifecycle(int $osId, string $rawDate, string $level = 'os', int $targetId = 0): void
    {
        global $DB;
        $id    = $level === 'version' ? $targetId : $osId;
        $table = LifeCycleReader::tableName($level);
        $col   = LifeCycleReader::dateColumnName($level);
        $row   = ['items_id' => $id, $col => $rawDate];
        if ($DB->fieldExists($table, 'itemtype')) {
            $row['itemtype'] = $level === 'version' ? 'OperatingSystemVersion' : 'OperatingSystem';
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
        $res = $DB->doQuery('SELECT MAX(`id`) AS `m` FROM `glpi_plugin_sac_run`');
        if (!$res) {
            return 0;
        }
        $row = $res->fetch_assoc();
        return (int) (is_array($row) ? ($row['m'] ?? 0) : 0);
    }

    /**
     * Tear every seeded row for a selfCheck back down. Safe to call twice.
     * Also deletes any `glpi_plugin_sac_run` rows this check's RunLog::begin('eol')
     * calls created (id > $sinceRunId) so selfCheck runs never pollute audit history.
     *
     * @param array<int,int> $verIds OperatingSystemVersion ids to remove, if any
     */
    private static function scTeardown(array $osIds, array $cids, array $codes, int $sinceRunId = 0, array $verIds = []): void
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

        $osLcTable  = \Plugin::isPluginActive('fields') ? LifeCycleReader::tableName('os') : '';
        $verLcTable = \Plugin::isPluginActive('fields') ? LifeCycleReader::tableName('version') : '';

        foreach ($osIds as $osId) {
            $osId = (int) $osId;
            if ($osId <= 0) {
                continue;
            }
            $DB->delete('glpi_plugin_sac_eolmap', ['operatingsystems_id' => $osId]);
            $DB->delete('glpi_items_operatingsystems', ['operatingsystems_id' => $osId]);
            $DB->delete('glpi_operatingsystems', ['id' => $osId]);
            $DB->delete('glpi_plugin_sac_reconcile', ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId]);
            if ($osLcTable !== '' && $DB->fieldExists($osLcTable, 'items_id')) {
                $DB->delete($osLcTable, ['items_id' => $osId]);
            }
        }
        foreach ($verIds as $verId) {
            $verId = (int) $verId;
            if ($verId <= 0) {
                continue;
            }
            $DB->delete('glpi_operatingsystemversions', ['id' => $verId]);
            if ($verLcTable !== '' && $DB->fieldExists($verLcTable, 'items_id')) {
                $DB->delete($verLcTable, ['items_id' => $verId]);
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
            // EOL-1 — LifeCycle path ONLY, ver_id=0. Seeds a real `fields`
            // OS-level LifeCycle row (no _eolmap/_eolcache) and asserts the
            // Finding names the lifecycle source.
            'db/eol-1-lifecycle-source' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                if (!LifeCycleReader::available() || LifeCycleReader::dateColumnName('os') === '') {
                    echo "SKIP  db/eol-1-lifecycle-source — fields LifeCycle (os level) table/column not present on this VM\n";
                    return;
                }
                $seed = self::scSeed(null);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
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

            // LifeCycle (os-level) beats a differing endoflife.date mirror.
            'db/eol-lifecycle-wins' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                if (!LifeCycleReader::available() || LifeCycleReader::dateColumnName('os') === '') {
                    echo "SKIP  db/eol-lifecycle-wins — fields LifeCycle (os level) table/column not present on this VM\n";
                    return;
                }
                // endoflife.date mirror says +200 days (would be Low); LifeCycle
                // says yesterday (Critical).
                $seed = self::scSeed(date('Y-m-d', strtotime('+200 days')));
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
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

            // NEW v1.1.0 — a version-level LifeCycle date beats a differing
            // OS-level one for the same pair.
            'db/eol-version-lifecycle-wins' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                $hasOs      = LifeCycleReader::available() && LifeCycleReader::dateColumnName('os') !== '';
                $hasVersion = LifeCycleReader::dateColumnName('version') !== '';
                if (!$hasOs || !$hasVersion) {
                    echo "SKIP  db/eol-version-lifecycle-wins — fields LifeCycle not present at both levels on this VM\n";
                    return;
                }
                $seed    = self::scSeed(null, '__sac_sc_os__', '__sac_sc_ver__');
                $osId    = $seed['osId']; $verId = $seed['verId']; $cid = $seed['cid'];
                $code    = Code::eolOs($osId, $verId);
                $startId = self::scRunFloor();
                try {
                    // OS-level says +200d (would be Low); version-level says yesterday (Critical).
                    self::scSeedLifecycle($osId, date('Y-m-d', strtotime('+200 days')), 'os');
                    self::scSeedLifecycle($osId, $yesterday, 'version', $verId);
                    self::run(RunLog::begin('eol'));

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'eol-version-lifecycle-wins: one Finding');
                    $row = $rows[array_key_first($rows)];
                    sac_assert(str_contains((string) $row['content'], 'source: lifecycle'), 'lifecycle source wins');
                    sac_assert(str_contains((string) $row['content'], $yesterday), 'version-level date used, not the OS-level date');
                    sac_assert_eq($row['severity'], 'Critical', 'graded on the version-level (past) date');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId, [$verId]);
                }
            },

            // A LifeCycle row with 0000-00-00 yields no Finding and one
            // bad_date reconcile row.
            'db/eol-bad-date' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                LifeCycleReader::resetProbeCache();
                if (!LifeCycleReader::available() || LifeCycleReader::dateColumnName('os') === '') {
                    echo "SKIP  db/eol-bad-date — fields LifeCycle (os level) table/column not present on this VM\n";
                    return;
                }
                global $DB;
                $seed = self::scSeed(null);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
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
                            'ref_subid'    => 0,
                            'reason'       => 'bad_date',
                        ],
                    ])->current();
                    sac_assert($rc !== null, 'EOL-bad-date: a bad_date reconcile row was written');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // Re-run after the endoflife.date date moves in-horizon -> same
            // row, re-graded in place.
            'db/eol-2-update-in-place' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                $seed = self::scSeed($yesterday);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
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

            // No LifeCycle, no _eolmap, name doesn't match the seed CSV ->
            // no Finding, one no_mapping reconcile.
            'db/eol-3-no-mapping-reconcile' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed = self::scSeed(null);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
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
                            'ref_subid'    => 0,
                            'reason'       => 'no_mapping',
                        ],
                    ])->current();
                    sac_assert($rc !== null, 'EOL-3: a no_mapping reconcile row was written');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId);
                }
            },

            // A Finding that drops beyond the horizon gains a cleared note.
            'db/eol-4-signal-cleared' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed = self::scSeed($yesterday);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
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

            // endoflife.date-only path (no LifeCycle row) is reported as the
            // source in `content`.
            'db/eol-5-endoflife-source' => static function () use ($yesterday): void {
                if (!FindingWriter::isAvailable()) { return; }
                $seed = self::scSeed($yesterday);
                $osId = $seed['osId']; $cid = $seed['cid'];
                $code = Code::eolOs($osId, 0);
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

            // NEW v1.1.0 — a fresh seed-CSV match auto-writes _eolmap AND
            // emits the Finding in the SAME run (spec §15 Q2).
            'db/eol-seed-automap' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed    = self::scSeed(null, 'Ubuntu', '22.04.3 LTS'); // no _eolmap — must come from the seed CSV
                $osId    = $seed['osId']; $verId = $seed['verId']; $cid = $seed['cid'];
                $code    = Code::eolOs($osId, $verId);
                $startId = self::scRunFloor();
                try {
                    // Pre-warm the cache so this check needs no network stub —
                    // it is testing the auto-map + same-run-emit behaviour, not
                    // EolCache::ensureFresh() (that has its own check, Task 9).
                    EolCache::upsert('ubuntu', '22.04', [
                        'eol_date' => date('Y-m-d', strtotime('-1 day')),
                        'raw_json' => '{}',
                    ]);

                    self::run(RunLog::begin('eol'));

                    $map = EolMap::forOsVersion($osId, $verId);
                    sac_assert($map !== null, 'a seed-CSV match auto-writes _eolmap');
                    sac_assert_eq($map['eol_product'], 'ubuntu', 'auto-mapped to the ubuntu product');
                    sac_assert_eq($map['eol_cycle'], '22.04', 'auto-mapped to the 22.04 cycle');
                    sac_assert_eq($map['confidence'], 'auto', 'auto-mapped rows are confidence=auto');

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'the Finding is emitted the SAME run as the auto-mapping');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId, [$verId]);
                    $DB->delete('glpi_plugin_sac_eolcache', ['eol_product' => 'ubuntu', 'eol_cycle' => '22.04']);
                }
            },

            // NEW v1.1.0 — a _skip seed pattern never produces a Finding or a
            // Reconcile row, and bumps the `skipped` counter.
            'db/eol-skip-non-os' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $seed    = self::scSeed(null, 'Kubernetes', '1.29');
                $osId    = $seed['osId']; $verId = $seed['verId']; $cid = $seed['cid'];
                $code    = Code::eolOs($osId, $verId);
                $startId = self::scRunFloor();
                try {
                    $run = RunLog::begin('eol');
                    self::run($run);
                    $run->finish('ok');

                    $f = new \GlpiPlugin\Riskregister\Finding();
                    sac_assert_eq(count($f->find(['code' => $code])), 0, 'a _skip pattern never produces a Finding');

                    $rc = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_reconcile',
                        'WHERE' => ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId, 'ref_subid' => $verId],
                    ])->current();
                    sac_assert($rc === null, 'a _skip pattern never produces a Reconcile row either');

                    $runRow = $DB->request(['FROM' => 'glpi_plugin_sac_run', 'WHERE' => ['id' => $run->id()]])->current();
                    $counts = json_decode((string) $runRow['counts_json'], true);
                    sac_assert((int) ($counts['skipped'] ?? 0) >= 1, 'the skipped counter is bumped');
                } finally {
                    self::scTeardown([$osId], [$cid], [$code], $startId, [$verId]);
                }
            },

            // NEW v1.1.0 — two versions of the same OS get distinct Reconcile
            // rows, no UNIQUE-key collision.
            'db/eol-two-versions-distinct' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $osId = (int) (new \OperatingSystem())->add(['name' => '__sac_sc_multi__']);
                $ver1 = (int) (new \OperatingSystemVersion())->add(['name' => '7.9', 'operatingsystems_id' => $osId]);
                $ver2 = (int) (new \OperatingSystemVersion())->add(['name' => '9.7', 'operatingsystems_id' => $osId]);
                $cid1 = (int) (new \Computer())->add(['name' => '__sac_sc_multi_a__', 'entities_id' => 0]);
                $cid2 = (int) (new \Computer())->add(['name' => '__sac_sc_multi_b__', 'entities_id' => 0]);
                $DB->insert('glpi_items_operatingsystems', [
                    'items_id' => $cid1, 'itemtype' => 'Computer',
                    'operatingsystems_id' => $osId, 'operatingsystemversions_id' => $ver1,
                ]);
                $DB->insert('glpi_items_operatingsystems', [
                    'items_id' => $cid2, 'itemtype' => 'Computer',
                    'operatingsystems_id' => $osId, 'operatingsystemversions_id' => $ver2,
                ]);
                $startId = self::scRunFloor();
                try {
                    self::run(RunLog::begin('eol'));

                    $rc1 = $DB->request(['FROM' => 'glpi_plugin_sac_reconcile', 'WHERE' => [
                        'ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId, 'ref_subid' => $ver1, 'reason' => 'no_mapping',
                    ]])->current();
                    $rc2 = $DB->request(['FROM' => 'glpi_plugin_sac_reconcile', 'WHERE' => [
                        'ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId, 'ref_subid' => $ver2, 'reason' => 'no_mapping',
                    ]])->current();
                    sac_assert($rc1 !== null, 'version 7.9 gets its own no_mapping row');
                    sac_assert($rc2 !== null, 'version 9.7 gets its own no_mapping row — no UNIQUE-key collision with 7.9');
                } finally {
                    self::scTeardown([$osId], [$cid1, $cid2], [], $startId, [$ver1, $ver2]);
                }
            },
        ];
    }
}
