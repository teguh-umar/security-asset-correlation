<?php

namespace GlpiPlugin\Sac\License;

use GlpiPlugin\Sac\AssetResolver;
use GlpiPlugin\Sac\Code;
use GlpiPlugin\Sac\Config;
use GlpiPlugin\Sac\FindingWriter;
use GlpiPlugin\Sac\RunLog;
use GlpiPlugin\Sac\Severity;

/**
 * Module 2 orchestration — License Governance scan (spec §5).
 *
 * `run()` walks every installed, non-ignored Software once and produces:
 *   - **2a** one Finding per commercial publisher with unlicensed products
 *     (`SAC-LIC-MISSING-<manufacturers_id>`), always;
 *   - **2b** one Finding per over-deployed licensed product
 *     (`SAC-LIC-OVER-<softwares_id>`), only when
 *     `license_check_overdeployment` is on.
 *
 * `Cron::cronLicenseScan` owns the RunLog begin/finish; this class only bumps
 * counters. Every riskregister write goes through {@see FindingWriter}. Reads of
 * GLPI core tables are read-only.
 */
final class LicenseScan
{
    public static function run(RunLog $run): void
    {
        global $DB;

        $entity    = (int) Config::get('finding_entity');
        $recursive = (int) Config::get('finding_recursive');
        $ignore    = Config::ignoreCategories();
        $checkOver = (bool) Config::get('license_check_overdeployment');
        $commercial = CommercialPublisher::activeManufacturerIds();

        /** @var array<int,array<int,array{name:string,installs:int,cids:array<int,int>}>> */
        $byPub = [];

        foreach (self::candidateSoftware($ignore) as $sw) {
            $sid    = (int) $sw['id'];
            $swName = (string) ($sw['name'] ?? '');
            $mid    = (int) ($sw['manufacturers_id'] ?? 0);

            $assets   = AssetResolver::computersForSoftware($sid);
            $installs = $assets['count'];
            if ($installs === 0) {
                continue;
            }
            $run->bump('scanned');

            $lic        = self::licenseAggregate($sid);
            $hasLicense = $lic['n'] > 0;
            $unlimited  = $lic['unlim'] > 0;
            $seats      = $lic['seats'];
            $exp        = $lic['exp']; // 'Y-m-d' or null

            // --- 2a: commercial publisher, zero licence rows --------------------
            if ($mid > 0 && !$hasLicense && in_array($mid, $commercial, true)) {
                $byPub[$mid][] = [
                    'name'     => $swName,
                    'installs' => $installs,
                    'cids'     => array_map('intval', array_keys($assets['resolved'])),
                ];
            }

            // --- 2b: licensed, finite seats, over-deployed ---------------------
            if ($hasLicense) {
                $isOver = $checkOver && !$unlimited && $installs > $seats;
                if ($isOver) {
                    $expired = ($exp !== null && $exp < date('Y-m-d'));
                    $sev     = Severity::forLicenseOverage($installs, $seats, $expired);

                    $hosts   = array_values($assets['resolved']);
                    $content = "Software: $swName\n"
                        . "Installed: $installs\n"
                        . "Licensed seats: $seats\n"
                        . 'Overage: ' . ($installs - $seats) . "\n"
                        . 'Earliest licence expiry: ' . ($exp ?? 'n/a')
                        . ($expired ? ' (EXPIRED)' : '') . "\n"
                        . "Affected hosts:\n"
                        . implode("\n", array_map(static fn ($h) => "- $h", $hosts));

                    $input = [
                        'entities_id'  => $entity,
                        'is_recursive' => $recursive,
                        'code'         => Code::licOver($sid),
                        'name'         => substr("License over-deployment: $swName", 0, 255),
                        'scope_label'  => substr("$swName — $installs/$seats", 0, 255),
                        'severity'     => $sev,
                        'content'      => $content,
                        'assessment_ref' => 'SAC/license',
                        'plugin_riskregister_riskcategories_id' => FindingWriter::categoryId('License Compliance', $entity),
                        'status'       => 'Open',
                    ];
                    FindingWriter::upsert($input, array_map('intval', array_keys($assets['resolved'])), '', $run);
                } elseif ($checkOver) {
                    // 2b is enabled and this software was over-deployed before but
                    // is not now — clear the prior Finding. When 2b is off we never
                    // asserted a SAC-LIC-OVER Finding, so there is nothing to clear.
                    FindingWriter::appendClearedNote(Code::licOver($sid), $entity, 'no longer over-deployed', $run);
                }
            }
        }

        self::emitMissingLicenceFindings($byPub, $commercial, $entity, $recursive, $run);
    }

    /**
     * One 2a Finding per publisher in $byPub; a cleared note for every active
     * commercial publisher that produced no unlicensed product this run.
     *
     * @param array<int,array<int,array{name:string,installs:int,cids:array<int,int>}>> $byPub
     * @param array<int,int> $commercial
     */
    private static function emitMissingLicenceFindings(
        array $byPub,
        array $commercial,
        int $entity,
        int $recursive,
        RunLog $run
    ): void {
        $prodThreshold    = (int) Config::get('license_2a_products_threshold');
        $installThreshold = (int) Config::get('license_2a_installs_threshold');

        foreach ($byPub as $mid => $rows) {
            $mid           = (int) $mid;
            $products      = count($rows);
            $totalInstalls = (int) array_sum(array_column($rows, 'installs'));
            $sev           = ($products > $prodThreshold || $totalInstalls > $installThreshold) ? 'Medium' : 'Low';

            $cidLists = array_column($rows, 'cids');
            $allCids  = $cidLists === [] ? [] : array_values(array_unique(array_merge(...$cidLists)));

            $pub   = self::manufacturerName($mid);
            $lines = [];
            foreach ($rows as $r) {
                $lines[] = '- ' . $r['name'] . ' · ' . $r['installs'] . ' install(s)';
            }
            $content = "Publisher: $pub\n"
                . "Unlicensed products: $products\n"
                . "Total installs: $totalInstalls\n\n"
                . "Product · installs\n"
                . implode("\n", $lines);

            $input = [
                'entities_id'  => $entity,
                'is_recursive' => $recursive,
                'code'         => Code::licMissing($mid),
                'name'         => substr("Unlicensed commercial software: $pub", 0, 255),
                'scope_label'  => substr("$pub — $products products", 0, 255),
                'severity'     => $sev,
                'content'      => $content,
                'assessment_ref' => 'SAC/license',
                'plugin_riskregister_riskcategories_id' => FindingWriter::categoryId('License Compliance', $entity),
                'status'       => 'Open',
            ];
            FindingWriter::upsert($input, $allCids, '', $run);
        }

        foreach ($commercial as $mid) {
            $mid = (int) $mid;
            if (!isset($byPub[$mid])) {
                FindingWriter::appendClearedNote(Code::licMissing($mid), $entity, 'all products now licensed', $run);
            }
        }
    }

    /**
     * Candidate software: installed on ≥1 non-deleted Computer, publisher-owned
     * or not, excluding any whose SoftwareCategory system-name is in $ignore
     * (NULL category is never excluded).
     *
     * Built SQL via `$DB->doQuery()`: the NULL-safe `NOT IN` over a LEFT JOIN
     * plus the "has a referenced version" `EXISTS` is awkward to express safely
     * through the iterator criteria array (same call as `EolCache::staleProducts`).
     * Category system-names are whitelist-filtered to `[A-Za-z0-9 _.-]` before
     * being quoted, so this is injection-safe.
     *
     * @param array<int,string> $ignore
     * @return iterable<array{id:int|string,name:?string,manufacturers_id:int|string}>
     */
    private static function candidateSoftware(array $ignore): iterable
    {
        global $DB;

        $catFilter = '';
        $safe      = [];
        foreach ($ignore as $c) {
            $c = preg_replace('/[^A-Za-z0-9 _.\-]/', '', (string) $c);
            if ($c !== '') {
                $safe[] = "'" . $c . "'";
            }
        }
        if ($safe !== []) {
            $catFilter = 'AND (`cat`.`name` IS NULL OR `cat`.`name` NOT IN (' . implode(',', $safe) . '))';
        }

        $sql = "SELECT DISTINCT `sw`.`id` AS `id`, `sw`.`name` AS `name`,
                       `sw`.`manufacturers_id` AS `manufacturers_id`
                FROM `glpi_softwares` AS `sw`
                LEFT JOIN `glpi_softwarecategories` AS `cat` ON `cat`.`id` = `sw`.`softwarecategories_id`
                WHERE `sw`.`is_deleted` = 0
                  AND `sw`.`is_template` = 0
                  $catFilter
                  AND EXISTS (
                        SELECT 1
                        FROM `glpi_softwareversions` AS `sv`
                        INNER JOIN `glpi_items_softwareversions` AS `isv`
                                ON `isv`.`softwareversions_id` = `sv`.`id`
                        WHERE `sv`.`softwares_id` = `sw`.`id`
                          AND `isv`.`itemtype` = 'Computer'
                          AND `isv`.`is_deleted` = 0
                      )";

        $res = $DB->doQuery($sql);
        if (!$res) {
            return;
        }
        while ($row = $res->fetch_assoc()) {
            yield $row;
        }
    }

    /**
     * Aggregate the non-deleted licences for one software.
     *
     * @return array{seats:int,exp:?string,n:int,unlim:int}
     */
    private static function licenseAggregate(int $softwareId): array
    {
        global $DB;

        $sid = (int) $softwareId;
        $sql = "SELECT COALESCE(SUM(`number`), 0)              AS `seats`,
                       MIN(NULLIF(`expire`, '0000-00-00'))     AS `exp`,
                       COUNT(*)                                AS `n`,
                       COALESCE(SUM(`number` <= 0), 0)         AS `unlim`
                FROM `glpi_softwarelicenses`
                WHERE `softwares_id` = $sid AND `is_deleted` = 0";

        $res = $DB->doQuery($sql);
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            return ['seats' => 0, 'exp' => null, 'n' => 0, 'unlim' => 0];
        }

        return [
            'seats' => (int) $row['seats'],
            'exp'   => $row['exp'] !== null ? (string) $row['exp'] : null,
            'n'     => (int) $row['n'],
            'unlim' => (int) $row['unlim'],
        ];
    }

    /** Publisher display name, or a stable placeholder when the row is gone. */
    private static function manufacturerName(int $manufacturersId): string
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_manufacturers',
            'WHERE'  => ['id' => $manufacturersId],
        ])->current();

        return $row && $row['name'] !== null && $row['name'] !== ''
            ? (string) $row['name']
            : ('Manufacturer #' . $manufacturersId);
    }

    // ---------------------------------------------------------------------
    // VM-only selfChecks (db/ prefix) — spec §9.2 LIC-1..LIC-3.
    // ---------------------------------------------------------------------

    /**
     * Seed Manufacturer (+ optional watch-list row), Software under it, a
     * SoftwareVersion and an install on a fresh Computer.
     */
    private static function scSeed(array $ids, bool $onWatchList, ?int $categoryId = null): void
    {
        global $DB;

        $DB->insert('glpi_manufacturers', ['id' => $ids['mid'], 'name' => '__sac_sc_pub__']);
        if ($onWatchList) {
            $DB->insert(CommercialPublisher::TABLE, [
                'manufacturers_id' => $ids['mid'],
                'is_active'        => 1,
                'date_creation'    => date('Y-m-d H:i:s'),
                'date_mod'         => date('Y-m-d H:i:s'),
            ]);
        }
        $DB->insert('glpi_softwares', [
            'id'                   => $ids['sid'],
            'name'                 => '__sac_sc_sw__',
            'entities_id'          => 0,
            'manufacturers_id'     => $ids['mid'],
            'softwarecategories_id' => (int) ($categoryId ?? 0),
            'is_deleted'           => 0,
            'is_template'          => 0,
        ]);
        $DB->insert('glpi_softwareversions', [
            'id'           => $ids['svid'],
            'softwares_id' => $ids['sid'],
            'name'         => '1.0',
            'entities_id'  => 0,
        ]);
        $DB->insert('glpi_computers', [
            'id'          => $ids['cid'],
            'name'        => '__sac_sc_pc1__',
            'entities_id' => 0,
            'is_deleted'  => 0,
            'is_template' => 0,
        ]);
        $DB->insert('glpi_items_softwareversions', [
            'softwareversions_id' => $ids['svid'],
            'itemtype'            => 'Computer',
            'items_id'            => $ids['cid'],
            'is_deleted'          => 0,
        ]);
    }

    /** Highest `glpi_plugin_sac_run.id` right now — the floor for selfCheck cleanup. */
    private static function scRunFloor(): int
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['MAX(id) AS m'], 'FROM' => 'glpi_plugin_sac_run'])->current();
        return (int) (is_array($row) ? ($row['m'] ?? 0) : 0);
    }

    private static function scTeardown(array $ids, array $codes, int $sinceRunId = 0): void
    {
        global $DB;

        $DB->delete('glpi_plugin_sac_run', ['module' => 'license', 'id' => ['>', $sinceRunId]]);

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

        $DB->delete('glpi_items_softwareversions', ['softwareversions_id' => $ids['svid']]);
        $DB->delete('glpi_softwareversions', ['id' => $ids['svid']]);
        $DB->delete('glpi_softwarelicenses', ['softwares_id' => $ids['sid']]);
        $DB->delete('glpi_softwares', ['id' => $ids['sid']]);
        $DB->delete('glpi_plugin_sac_commercialpublishers', ['manufacturers_id' => $ids['mid']]);
        $DB->delete('glpi_manufacturers', ['id' => $ids['mid']]);
        foreach (['cid', 'cid2'] as $k) {
            if (isset($ids[$k])) {
                $DB->delete('glpi_computers', ['id' => $ids[$k]]);
            }
        }
        if (isset($ids['catid'])) {
            $DB->delete('glpi_softwarecategories', ['id' => $ids['catid']]);
        }
    }

    private static function scConfig(string $key, int $value): void
    {
        \Config::setConfigurationValues(Config::CONTEXT, [$key => $value]);
    }

    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        return [
            // LIC-1 — commercial publisher, one install, no licence → one 2a Low.
            'db/lic-1-missing-license' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                $ids   = ['mid' => 2147482001, 'sid' => 2147482002, 'svid' => 2147482003, 'cid' => 2147482004];
                $code  = Code::licMissing($ids['mid']);
                $startId = self::scRunFloor();
                try {
                    self::scSeed($ids, true);
                    $run = RunLog::begin('license');
                    self::run($run);
                    $run->finish('ok');

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'LIC-1: exactly one 2a Finding');
                    $row = $rows[array_key_first($rows)];
                    sac_assert_eq($row['severity'], 'Low', 'LIC-1: severity Low');
                    sac_assert_eq($row['assessment_ref'], 'SAC/license', 'LIC-1: assessment_ref');

                    $fi    = new \GlpiPlugin\Riskregister\Finding_Item();
                    $links = $fi->find([
                        'plugin_riskregister_findings_id' => (int) array_key_first($rows),
                        'itemtype'                        => 'Computer',
                        'items_id'                        => $ids['cid'],
                    ]);
                    sac_assert_eq(count($links), 1, 'LIC-1: one Finding_Item to the seeded Computer');
                } finally {
                    self::scTeardown($ids, [$code], $startId);
                }
            },

            // LIC-2 — add a licence + 2nd install, flip the toggle → 2b Finding,
            // and the 2a Finding gains a cleared note.
            'db/lic-2-overdeployment-and-clear' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $ids  = [
                    'mid' => 2147482011, 'sid' => 2147482012, 'svid' => 2147482013,
                    'cid' => 2147482014, 'cid2' => 2147482015,
                ];
                $missCode = Code::licMissing($ids['mid']);
                $overCode = Code::licOver($ids['sid']);
                $startId = self::scRunFloor();
                try {
                    self::scSeed($ids, true);
                    self::scConfig('license_check_overdeployment', 0);
                    $run = RunLog::begin('license');
                    self::run($run);
                    $run->finish('ok');

                    $f = new \GlpiPlugin\Riskregister\Finding();
                    sac_assert_eq(count($f->find(['code' => $missCode])), 1, 'LIC-2: 2a Finding present after first run');

                    // A licence for 1 seat, a second install, toggle 2b on.
                    $DB->insert('glpi_softwarelicenses', [
                        'softwares_id' => $ids['sid'],
                        'name'         => '__sac_sc_lic__',
                        'number'       => 1,
                        'entities_id'  => 0,
                        'is_deleted'   => 0,
                    ]);
                    $DB->insert('glpi_computers', [
                        'id' => $ids['cid2'], 'name' => '__sac_sc_pc2__',
                        'entities_id' => 0, 'is_deleted' => 0, 'is_template' => 0,
                    ]);
                    $DB->insert('glpi_items_softwareversions', [
                        'softwareversions_id' => $ids['svid'],
                        'itemtype'            => 'Computer',
                        'items_id'            => $ids['cid2'],
                        'is_deleted'          => 0,
                    ]);
                    self::scConfig('license_check_overdeployment', 1);

                    $run = RunLog::begin('license');
                    self::run($run);
                    $run->finish('ok');

                    $overRows = $f->find(['code' => $overCode]);
                    sac_assert_eq(count($overRows), 1, 'LIC-2: one 2b Finding');
                    sac_assert_eq($overRows[array_key_first($overRows)]['severity'], 'High', 'LIC-2: 2 installs / 1 seat → High');

                    $missRows = $f->find(['code' => $missCode]);
                    sac_assert_eq(count($missRows), 1, 'LIC-2: 2a Finding still one row');
                    sac_assert(
                        str_contains((string) $missRows[array_key_first($missRows)]['content'], 'Signal cleared'),
                        'LIC-2: 2a Finding gains a cleared note once licensed'
                    );
                } finally {
                    self::scConfig('license_check_overdeployment', 0);
                    self::scTeardown($ids, [$missCode, $overCode], $startId);
                }
            },

            // LIC-3 — software in an ignored category is not scanned.
            'db/lic-3-ignored-category' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $ids  = [
                    'mid' => 2147482021, 'sid' => 2147482022, 'svid' => 2147482023,
                    'cid' => 2147482024, 'catid' => 2147482025,
                ];
                $code = Code::licMissing($ids['mid']);
                $startId = self::scRunFloor();
                try {
                    $DB->insert('glpi_softwarecategories', ['id' => $ids['catid'], 'name' => 'system_component']);
                    self::scSeed($ids, true, $ids['catid']);

                    $run = RunLog::begin('license');
                    self::run($run);
                    $run->finish('ok');

                    $f = new \GlpiPlugin\Riskregister\Finding();
                    sac_assert_eq(count($f->find(['code' => $code])), 0, 'LIC-3: ignored-category software produces no Finding');
                } finally {
                    self::scTeardown($ids, [$code], $startId);
                }
            },
        ];
    }
}
