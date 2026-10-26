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
 *   - **2a** one Finding per software a classification rule says needs a
 *     licence and that has none (`SAC-LIC-SW-<softwares_id>`), always; software
 *     no rule decides goes to the review queue (watch-list publishers only);
 *   - **2b** one Finding per over-deployed licensed product
 *     (`SAC-LIC-OVER-<softwares_id>`), only when
 *     `license_check_overdeployment` is on.
 *
 * `Cron::cronLicenseScan` owns the RunLog begin/finish; this class only bumps
 * counters. Every riskregister write goes through {@see FindingWriter}. Reads of
 * GLPI core tables are read-only.
 *
 * Every Finding here is also linked (via `FindingWriter`'s `$extraLinks`) to
 * the affected `Software` item(s), not just the affected Computers —
 * riskregister already has a native "Findings" tab on Software (confirmed
 * with risk-register-dashboard-c8), so this is what makes a SAC license
 * Finding actually show up there.
 */
final class LicenseScan
{
    public static function run(RunLog $run): void
    {
        global $DB;

        $ignore    = Config::ignoreCategories();
        $checkOver = (bool) Config::get('license_check_overdeployment');
        $commercial = CommercialPublisher::activeManufacturerIds();
        $rules      = self::loadRules();
        $missingSev = self::licenseSeverity(Config::get('license_missing_severity'));
        $now        = date('Y-m-d H:i:s');

        // Hoist the per-run-stable category id out of the software loop (I7).
        // riskregister looks a category up by name alone; root is only where a
        // missing one would be created.
        $licenseCatId = FindingWriter::categoryId('License Compliance', 0);
        $scope = Config::scanScope(); // null = every entity

        /** @var array<int,array<int,int>> [entity] => softwares_id list for the review queue */
        $toQueue = [];
        /** @var array<int,true> software that already has a SAC-LIC-SW Finding row somewhere */
        $hadFinding = self::softwareWithFinding();
        /** @var array<int,true> software this run reached (so a stale Finding of an unreached one may be cleared) */
        $touched = [];

        foreach (self::candidateSoftware($ignore) as $sw) {
          try {
            $sid    = (int) $sw['id'];
            $swName = (string) ($sw['name'] ?? '');
            $mid    = (int) ($sw['manufacturers_id'] ?? 0);
            $touched[$sid] = true;

            $groups = AssetResolver::groupByEntity(AssetResolver::computersForSoftware($sid), $scope);
            if ($groups === []) {
                continue; // not installed anywhere in the scanned entities
            }
            $run->bump('scanned');

            // Everything below is per entity of the installs: installs, seats
            // and the finding itself. A licence counts for an entity only when
            // GLPI would show it there (see licenseAggregate()).
            $overEntities = [];
            $swEntities   = []; // entities where this run asserts the classification Finding
            $versions     = (array) ($sw['versions'] ?? []);
            // A manufacturers_id pointing at a deleted row is simply "not on the
            // watch list": the rule below still decides. No manufacturer lookup
            // gates classification.
            $onWatchList  = $mid > 0 && in_array($mid, $commercial, true);
            $anyLicense   = false;
            foreach ($groups as $entity => $hostsById) {
                $installs   = count($hostsById);
                $lic        = self::licenseAggregate($sid, $entity);
                $hasLicense = $lic['n'] > 0;
                $unlimited  = $lic['unlim'] > 0;
                $seats      = $lic['seats'];
                $exp        = $lic['exp']; // 'Y-m-d' or null
                $anyLicense = $anyLicense || $hasLicense;

                // --- 2a: the classification rule decides; the watch list only feeds the queue
                $verdict = self::classify($swName, $versions, $rules, self::ancestry((int) $entity), $onWatchList, $hasLicense);
                if ($verdict['action'] === 'finding') {
                    $rule  = (array) $verdict['rule'];
                    $hosts = array_values($hostsById);
                    $input = [
                        'entities_id'  => $entity,
                        'code'         => Code::licSoftware($sid),
                        'name'         => substr("Unlicensed commercial software: $swName", 0, 255),
                        'scope_label'  => \GlpiPlugin\Sac\Text::clip("$swName — $installs install(s)"),
                        'severity'     => $missingSev,
                        'content'      => "Software: $swName\n"
                            . 'Publisher: ' . ($mid > 0 ? self::manufacturerName($mid) : 'unknown') . "\n"
                            . "Installed: $installs\n"
                            . 'Classified as needing a licence by rule #' . (int) $rule['id']
                            . ' (' . (string) $rule['match_on'] . ' ' . (string) $rule['pattern'] . ', matched on '
                            . (string) $rule['matched_on'] . ")\n"
                            . 'Reason: ' . (string) ($rule['reason'] ?? '') . "\n"
                            . "No licence is recorded for this entity.\n"
                            . "Affected hosts:\n"
                            . implode("\n", array_map(static fn ($h) => "- $h", $hosts)),
                        'assessment_ref' => 'SAC/license',
                        'plugin_riskregister_riskcategories_id' => $licenseCatId,
                        'status'       => 'Open',
                    ];
                    FindingWriter::upsert(
                        $input,
                        array_map('intval', array_keys($hostsById)),
                        '',
                        $run,
                        [['itemtype' => 'Software', 'items_id' => $sid]]
                    );
                    $swEntities[] = (int) $entity;
                } elseif ($verdict['action'] === 'queue') {
                    $toQueue[(int) $entity][] = $sid;
                }

                // --- 2b: licensed, finite seats, over-deployed -----------------
                if (!($hasLicense && $checkOver && !$unlimited && $installs > $seats)) {
                    continue;
                }
                $expired = ($exp !== null && $exp < date('Y-m-d'));
                $sev     = Severity::forLicenseOverage($installs, $seats, $expired);

                $content = "Software: $swName\n"
                    . "Installed: $installs\n"
                    . "Licensed seats: $seats\n"
                    . 'Overage: ' . ($installs - $seats) . "\n"
                    . 'Earliest licence expiry: ' . ($exp ?? 'n/a')
                    . ($expired ? ' (EXPIRED)' : '') . "\n"
                    . "Affected hosts:\n"
                    . implode("\n", array_map(static fn ($h) => "- $h", array_values($hostsById)));

                $input = [
                    'entities_id'  => $entity,
                    'code'         => Code::licOver($sid),
                    'name'         => substr("License over-deployment: $swName", 0, 255),
                    'scope_label'  => \GlpiPlugin\Sac\Text::clip("$swName — $installs/$seats"),
                    'severity'     => $sev,
                    'content'      => $content,
                    'assessment_ref' => 'SAC/license',
                    'plugin_riskregister_riskcategories_id' => $licenseCatId,
                    'status'       => 'Open',
                ];
                FindingWriter::upsert(
                    $input,
                    array_map('intval', array_keys($hostsById)),
                    '',
                    $run,
                    [['itemtype' => 'Software', 'items_id' => $sid]]
                );
                $overEntities[] = (int) $entity;
            }

            // 2b is enabled and this software is licensed somewhere: clear the
            // over-deployment Finding in every entity that is not over now.
            // When 2b is off we never asserted one, so there is nothing to clear.
            if ($checkOver && $anyLicense) {
                FindingWriter::clearExcept(Code::licOver($sid), $overEntities, 'no longer over-deployed', $run, $scope);
            }

            // A classification Finding this run no longer asserts (rule changed,
            // licence added) gets its cleared note. Only software that has one.
            if (isset($hadFinding[$sid])) {
                FindingWriter::clearExcept(Code::licSoftware($sid), $swEntities, 'no longer classified as needing a licence, or now licensed', $run, $scope);
            }
          } catch (\Throwable $e) {
            // One bad software row must not abort the whole scan.
            $run->bump('errors');
            \Toolbox::logInFile('sac', 'LicenseScan software ' . ($sw['id'] ?? '?') . ': ' . $e->getMessage() . "\n");
          }
        }

        // Software that has a Finding but was not reached at all (uninstalled,
        // moved into an ignored category): clear it everywhere in scope.
        foreach (array_keys($hadFinding) as $sid) {
            if (!isset($touched[$sid])) {
                FindingWriter::clearExcept(Code::licSoftware((int) $sid), [], 'software no longer installed or in scope', $run, $scope);
            }
        }

        foreach ($toQueue as $entity => $softwareIds) {
            try {
                ReviewQueue::sync((int) $entity, $softwareIds, $now);
            } catch (\Throwable $e) {
                $run->bump('errors');
                \Toolbox::logInFile('sac', 'LicenseScan review queue entity ' . $entity . ': ' . $e->getMessage() . "\n");
            }
        }
    }

    /**
     * PURE. The 2a verdict for one software in one entity.
     *
     * The rule decides; the publisher watch list only decides whether an
     * undecided software enters the review queue. A `needs_license` rule fires
     * for ANY publisher, including a blank or dangling manufacturers_id.
     *
     * @param string[]                       $versionNames installed version names
     * @param array<int,array<string,mixed>> $rules        licenserules rows
     * @param int[]                          $ancestry     the entity first, then its ancestors, nearest first
     * @return array{action:string,rule:?array<string,mixed>} action: finding | queue | none
     */
    public static function classify(
        string $softwareName,
        array $versionNames,
        array $rules,
        array $ancestry,
        bool $onWatchList,
        bool $hasLicense
    ): array {
        // A rule owned by an ancestor applies only when it is recursive.
        $own = (int) ($ancestry[0] ?? 0);
        $applicable = array_values(array_filter(
            $rules,
            static fn (array $r): bool => (int) $r['entities_id'] === $own || (int) ($r['is_recursive'] ?? 0) === 1
        ));

        $rule = RuleMatcher::decide($softwareName, $versionNames, $applicable, $ancestry);
        if ($rule === null) {
            // Undecided: a question for a human, never a Finding. A licensed
            // product is not a question.
            return ['action' => $onWatchList && !$hasLicense ? 'queue' : 'none', 'rule' => null];
        }
        if ((string) $rule['classification'] === 'needs_license' && !$hasLicense) {
            return ['action' => 'finding', 'rule' => $rule];
        }
        return ['action' => 'none', 'rule' => $rule];
    }

    /** PURE. A configured severity, or Medium when it is not one of the four. */
    public static function licenseSeverity(mixed $value): string
    {
        $v = is_string($value) ? ucfirst(strtolower(trim($value))) : '';
        return in_array($v, ['Critical', 'High', 'Medium', 'Low'], true) ? $v : 'Medium';
    }

    /** @return array<int,array<string,mixed>> every licence classification rule */
    private static function loadRules(): array
    {
        global $DB;

        $rules = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_sac_licenserules']) as $row) {
            $rules[] = (array) $row;
        }
        return $rules;
    }

    /** @return array<int,int> the entity first, then its ancestors, nearest first */
    private static function ancestry(int $entity): array
    {
        /** @var array<int,array<int,int>> $cache */
        static $cache = [];
        if (!isset($cache[$entity])) {
            // getAncestorsOf() lists root first; the matcher wants nearest first.
            $cache[$entity] = $entity === 0
                ? [0]
                : array_merge([$entity], array_reverse(array_values(array_map('intval', getAncestorsOf('glpi_entities', $entity)))));
        }
        return $cache[$entity];
    }

    /** @return array<int,true> softwares_id => true for every existing SAC-LIC-SW Finding */
    private static function softwareWithFinding(): array
    {
        if (!FindingWriter::isAvailable()) {
            return [];
        }
        $out    = [];
        $prefix = substr(Code::licSoftware(0), 0, -1);
        foreach ((new \GlpiPlugin\Riskregister\Finding())->find(['code' => ['LIKE', $prefix . '%']]) as $row) {
            $out[(int) substr((string) $row['code'], strlen($prefix))] = true;
        }
        return $out;
    }

    /**
     * Candidate software: installed on ≥1 non-deleted Computer, publisher-owned
     * or not, excluding any whose SoftwareCategory system-name is in $ignore
     * (NULL category is never excluded).
     *
     * Built SQL via `$DB->doQuery()`: the NULL-safe `NOT IN` over a LEFT JOIN
     * plus the "has a referenced version" `EXISTS` is awkward to express safely
     * through the iterator criteria array (same call as `EolCache::staleProducts`).
     * Each ignored category name is passed through `$DB->quote()` verbatim — no
     * character stripping — so a name like `Système` keeps its accents and its
     * ignore rule still matches (I14).
     *
     * @param array<int,string> $ignore
     * @return iterable<array{id:int|string,name:?string,manufacturers_id:int|string,versions:array<int,string>}>
     */
    private static function candidateSoftware(array $ignore): iterable
    {
        global $DB;

        $catFilter = '';
        $safe      = [];
        foreach ($ignore as $c) {
            $c = (string) $c;
            if ($c !== '') {
                $safe[] = $DB->quote($c);
            }
        }
        if ($safe !== []) {
            $catFilter = 'AND (`cat`.`name` IS NULL OR `cat`.`name` NOT IN (' . implode(',', $safe) . '))';
        }

        // One row per (software, installed version name), folded back into one
        // software with a `versions` list below. Not GROUP_CONCAT: its 1024-byte
        // default would silently truncate a long version list, and the matcher
        // needs every installed version. The INNER JOINs keep the old "has a
        // referenced version on a Computer" rule. There is deliberately no
        // glpi_manufacturers join: a dangling manufacturers_id must not drop
        // the software.
        $sql = "SELECT DISTINCT `sw`.`id` AS `id`, `sw`.`name` AS `name`,
                       `sw`.`manufacturers_id` AS `manufacturers_id`,
                       `sv`.`name` AS `version_name`
                FROM `glpi_softwares` AS `sw`
                LEFT JOIN `glpi_softwarecategories` AS `cat` ON `cat`.`id` = `sw`.`softwarecategories_id`
                INNER JOIN `glpi_softwareversions` AS `sv` ON `sv`.`softwares_id` = `sw`.`id`
                INNER JOIN `glpi_items_softwareversions` AS `isv`
                        ON `isv`.`softwareversions_id` = `sv`.`id`
                       AND `isv`.`itemtype` = 'Computer'
                       AND `isv`.`is_deleted` = 0
                WHERE `sw`.`is_deleted` = 0
                  AND `sw`.`is_template` = 0
                  $catFilter
                ORDER BY `sw`.`id`";

        $res = $DB->doQuery($sql);
        if (!$res) {
            return;
        }
        $current = null;
        while ($row = $res->fetch_assoc()) {
            if ($current !== null && (int) $current['id'] !== (int) $row['id']) {
                yield $current;
                $current = null;
            }
            if ($current === null) {
                $current = [
                    'id'               => $row['id'],
                    'name'             => $row['name'],
                    'manufacturers_id' => $row['manufacturers_id'],
                    'versions'         => [],
                ];
            }
            $current['versions'][] = (string) ($row['version_name'] ?? '');
        }
        if ($current !== null) {
            yield $current;
        }
    }

    /**
     * Aggregate the non-deleted licences for one software that cover installs
     * in $entity: licences owned by that entity, plus recursive ("child
     * entities") licences owned by one of its ancestors. A licence of another
     * subsidiary never counts (user decision 2026-10-04); a group-wide licence
     * is one placed on the root entity with child entities = yes.
     *
     * @return array{seats:int,exp:?string,n:int,unlim:int}
     */
    private static function licenseAggregate(int $softwareId, int $entity): array
    {
        global $DB;

        /** @var array<int,array<int,int>> ancestors per entity, stable within a run */
        static $ancestors = [];
        if (!isset($ancestors[$entity])) {
            $ancestors[$entity] = $entity === 0
                ? []
                : array_values(array_map('intval', getAncestorsOf('glpi_entities', $entity)));
        }
        $scope = '`entities_id` = ' . (int) $entity;
        if ($ancestors[$entity] !== []) {
            $scope = "($scope OR (`is_recursive` = 1 AND `entities_id` IN ("
                . implode(',', $ancestors[$entity]) . ')))';
        }

        $sid = (int) $softwareId;
        // `expire` is a DATE column; legacy rows hold '0000-00-00', which MySQL in
        // NO_ZERO_DATE / strict mode rejects as a DATE literal inside an
        // expression. Compare as CHAR so no DATE validation runs; the caller does
        // a lexical 'Y-m-d' compare anyway.
        $sql = "SELECT COALESCE(SUM(`number`), 0)                       AS `seats`,
                       MIN(NULLIF(CAST(`expire` AS CHAR), '0000-00-00')) AS `exp`,
                       COUNT(*)                                         AS `n`,
                       COALESCE(SUM(`number` <= 0), 0)                  AS `unlim`
                FROM `glpi_softwarelicenses`
                WHERE `softwares_id` = $sid AND `is_deleted` = 0 AND $scope";

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
     *
     * Core rows are created with `->add()` and the generated ids captured — no
     * hardcoded ~2.1e9 ids (those permanently bump InnoDB AUTO_INCREMENT).
     *
     * @return array{mid:int,sid:int,svid:int,cid:int}
     */
    private static function scSeed(bool $onWatchList, ?int $categoryId = null): array
    {
        global $DB;

        $mid = (int) (new \Manufacturer())->add(['name' => '__sac_sc_pub__']);
        if ($onWatchList) {
            $DB->insert(CommercialPublisher::TABLE, [
                'manufacturers_id' => $mid,
                'is_active'        => 1,
                'date_creation'    => date('Y-m-d H:i:s'),
                'date_mod'         => date('Y-m-d H:i:s'),
            ]);
        }
        $sid = (int) (new \Software())->add([
            'name'                  => '__sac_sc_sw__',
            'entities_id'           => 0,
            'manufacturers_id'      => $mid,
            'softwarecategories_id' => (int) ($categoryId ?? 0),
        ]);
        $svid = (int) (new \SoftwareVersion())->add([
            'softwares_id' => $sid,
            'name'         => '1.0',
            'entities_id'  => 0,
        ]);
        $cid = (int) (new \Computer())->add([
            'name'        => '__sac_sc_pc1__',
            'entities_id' => 0,
        ]);
        $DB->insert('glpi_items_softwareversions', [
            'softwareversions_id' => $svid,
            'itemtype'            => 'Computer',
            'items_id'            => $cid,
            'is_deleted'          => 0,
        ]);

        return ['mid' => $mid, 'sid' => $sid, 'svid' => $svid, 'cid' => $cid];
    }

    /** A recursive entity-0 rule that says the seeded software needs a licence. */
    private static function scRule(string $classification = 'needs_license'): int
    {
        global $DB;

        $DB->insert('glpi_plugin_sac_licenserules', [
            'entities_id'    => 0,
            'is_recursive'   => 1,
            'pattern'        => '__sac_sc_sw__',
            'match_on'       => 'name',
            'classification' => $classification,
            'reason'         => '__sac_sc_rule__',
            'date_creation'  => date('Y-m-d H:i:s'),
            'date_mod'       => date('Y-m-d H:i:s'),
        ]);
        return (int) $DB->insertId();
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

        $DB->delete('glpi_plugin_sac_licenserules', ['reason' => '__sac_sc_rule__']);
        $DB->delete('glpi_plugin_sac_licensereview', ['softwares_id' => $ids['sid']]);
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
        $needs = ['id' => 1, 'entities_id' => 0, 'is_recursive' => 1, 'pattern' => 'acme-db', 'match_on' => 'name', 'classification' => 'needs_license'];
        $free  = ['id' => 2, 'entities_id' => 0, 'is_recursive' => 1, 'pattern' => 'openssl*', 'match_on' => 'name', 'classification' => 'free'];

        return [
            'license/unmatched-goes-to-queue-not-finding' => static function (): void {
                $c = self::classify('mystery-app', ['1.0'], [], [0], true, false);
                sac_assert_eq($c['action'], 'queue', 'watch-list publisher, no rule: review queue');
                sac_assert(!in_array($c['action'], ['finding'], true), 'and never a Finding input');
                sac_assert_eq(self::classify('mystery-app', ['1.0'], [], [0], false, false)['action'], 'none', 'publisher not on the list, no rule: nothing (accepted blind spot)');
            },
            'license/free-rule-raises-nothing' => static function () use ($free): void {
                sac_assert_eq(self::classify('openssl-libs', ['3.0'], [$free], [0], true, false)['action'], 'none', 'a free rule: no Finding and no queue row, even for a watch-list publisher');
            },
            'license/needs-license-rule-fires-for-any-publisher' => static function () use ($needs): void {
                sac_assert_eq(self::classify('acme-db', ['1.0'], [$needs], [0], false, false)['action'], 'finding', 'needs_license decides although the publisher is NOT on the watch list');
                sac_assert_eq(self::classify('acme-db', ['1.0'], [$needs], [0], false, true)['action'], 'none', 'a licence row for the entity settles it');
            },
            'license/missing-manufacturer-row-is-not-skipped' => static function () use ($needs): void {
                // manufacturers_id 987654 points at a deleted row: it is simply not on the watch list.
                $watch = [5, 6];
                $onList = in_array(987654, $watch, true);
                sac_assert_eq(self::classify('acme-db', ['1.0'], [$needs], [0], $onList, false)['action'], 'finding', 'a dangling manufacturers_id still reaches classification');
                sac_assert_eq(self::classify('acme-db', ['1.0'], [$needs], [0], in_array(0, $watch, true), false)['action'], 'finding', 'so does manufacturers_id 0');
            },
            'license/rule-recursion-respected' => static function () use ($needs): void {
                $local = $needs;
                $local['is_recursive'] = 0;
                sac_assert_eq(self::classify('acme-db', ['1.0'], [$local], [5, 0], false, false)['action'], 'none', 'a non-recursive parent rule does not reach a child entity');
                sac_assert_eq(self::classify('acme-db', ['1.0'], [$needs], [5, 0], false, false)['action'], 'finding', 'a recursive parent rule does');
            },
            'license/severity-fallback' => static function (): void {
                sac_assert_eq(self::licenseSeverity('High'), 'High', 'a valid severity is kept');
                sac_assert_eq(self::licenseSeverity('bogus'), 'Medium', 'an invalid value falls back to Medium');
                sac_assert_eq(self::licenseSeverity(null), 'Medium', 'null falls back to Medium');
            },
            // LIC-1 — a needs_license rule, one install, no licence → one 2a Finding.
            'db/lic-1-missing-license' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                $ids   = self::scSeed(true);
                $code  = Code::licSoftware($ids['sid']);
                $startId = self::scRunFloor();
                try {
                    self::scRule();
                    $run = RunLog::begin('license');
                    self::run($run);
                    $run->finish('ok');

                    $f    = new \GlpiPlugin\Riskregister\Finding();
                    $rows = $f->find(['code' => $code]);
                    sac_assert_eq(count($rows), 1, 'LIC-1: exactly one 2a Finding');
                    $row = $rows[array_key_first($rows)];
                    sac_assert_eq($row['severity'], self::licenseSeverity(Config::get('license_missing_severity')), 'LIC-1: severity comes from license_missing_severity');
                    sac_assert_eq($row['assessment_ref'], 'SAC/license', 'LIC-1: assessment_ref');

                    $fi    = new \GlpiPlugin\Riskregister\Finding_Item();
                    $links = $fi->find([
                        'plugin_riskregister_findings_id' => (int) array_key_first($rows),
                        'itemtype'                        => 'Computer',
                        'items_id'                        => $ids['cid'],
                    ]);
                    sac_assert_eq(count($links), 1, 'LIC-1: one Finding_Item to the seeded Computer');

                    $swLinks = $fi->find([
                        'plugin_riskregister_findings_id' => (int) array_key_first($rows),
                        'itemtype'                        => 'Software',
                        'items_id'                        => $ids['sid'],
                    ]);
                    sac_assert_eq(count($swLinks), 1, 'LIC-1: one Finding_Item to the seeded Software (riskregister\'s native tab there)');
                } finally {
                    self::scTeardown($ids, [$code], $startId);
                }
            },

            // LIC-2 — add a licence + 2nd install, flip the toggle → 2b Finding,
            // and the 2a Finding gains a cleared note.
            'db/lic-2-overdeployment-and-clear' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $ids  = self::scSeed(true);
                $missCode = Code::licSoftware($ids['sid']);
                $overCode = Code::licOver($ids['sid']);
                $startId = self::scRunFloor();
                // Capture the real config value BEFORE the check and restore it
                // in finally — never force it to 0 (I8).
                $prevOver = (int) Config::get('license_check_overdeployment');
                try {
                    self::scRule();
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
                    $ids['cid2'] = (int) (new \Computer())->add([
                        'name' => '__sac_sc_pc2__', 'entities_id' => 0,
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

                    $fi      = new \GlpiPlugin\Riskregister\Finding_Item();
                    $swLinks = $fi->find([
                        'plugin_riskregister_findings_id' => (int) array_key_first($overRows),
                        'itemtype'                        => 'Software',
                        'items_id'                        => $ids['sid'],
                    ]);
                    sac_assert_eq(count($swLinks), 1, 'LIC-2: the 2b Finding is also linked to its own Software item');

                    $missRows = $f->find(['code' => $missCode]);
                    sac_assert_eq(count($missRows), 1, 'LIC-2: 2a Finding still one row');
                    sac_assert(
                        str_contains((string) $missRows[array_key_first($missRows)]['content'], 'Signal cleared'),
                        'LIC-2: 2a Finding gains a cleared note once licensed'
                    );
                } finally {
                    self::scConfig('license_check_overdeployment', $prevOver);
                    self::scTeardown($ids, [$missCode, $overCode], $startId);
                }
            },

            // LIC-3 — software in an ignored category is not scanned.
            'db/lic-3-ignored-category' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                $catid = (int) (new \SoftwareCategory())->add(['name' => 'system_component']);
                $ids   = self::scSeed(true, $catid) + ['catid' => $catid];
                $code  = Code::licSoftware($ids['sid']);
                $startId = self::scRunFloor();
                try {
                    self::scRule();
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
