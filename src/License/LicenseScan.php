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
        /** @var array<int,array<int,int>> softwares_id => entities where this run asserted its Finding */
        $asserted = [];
        /** @var array<int,true> software whose processing threw: its Finding state is unknown, so never cleared */
        $failed = [];

        foreach (self::candidateSoftware($ignore) as $sw) {
          try {
            $sid    = (int) $sw['id'];
            $swName = (string) ($sw['name'] ?? '');
            $mid    = (int) ($sw['manufacturers_id'] ?? 0);

            $groups = AssetResolver::groupByEntity(AssetResolver::computersForSoftware($sid), $scope);
            if ($groups === []) {
                continue; // not installed anywhere in the scanned entities
            }
            $run->bump('scanned');
            $asserted[$sid] = [];

            // Everything below is per entity of the installs: installs, seats
            // and the finding itself. A licence counts for an entity only when
            // GLPI would show it there (see licenseAggregate()).
            $overEntities = [];
            $byEntity     = (array) ($sw['versions_by_entity'] ?? []);
            // A manufacturers_id pointing at a deleted row is simply "not on the
            // watch list": the rule below still decides. No manufacturer lookup
            // gates classification.
            $onWatchList  = self::onWatchList($mid, $commercial);
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
                // Version evidence is this entity's own installs only: a version
                // installed solely in another entity must not undecide it here.
                $versions = self::versionsForEntity($byEntity, (int) $entity);
                $verdict = self::classify($swName, $versions, $rules, self::ancestry((int) $entity), $onWatchList, $hasLicense);
                if ($verdict['action'] === 'finding') {
                    $rule  = (array) $verdict['rule'];
                    $hosts = array_values($hostsById);
                    $input = [
                        'entities_id'  => $entity,
                        'code'         => Code::licSoftware($sid),
                        'name'         => substr("Software needing a licence: $swName", 0, 255),
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
                    $asserted[$sid][] = (int) $entity;
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
          } catch (\Throwable $e) {
            // One bad software row must not abort the whole scan.
            $run->bump('errors');
            $failed[(int) ($sw['id'] ?? 0)] = true;
            \Toolbox::logInFile('sac', 'LicenseScan software ' . ($sw['id'] ?? '?') . ': ' . $e->getMessage() . "\n");
          }
        }

        // Clear every classification Finding this run no longer asserts: rule
        // changed, licence added, or the software has no installs left in scope.
        // The plan is seeded from the Findings themselves, not from the
        // candidate set, so a software the candidate query no longer returns
        // (all hosts trashed) is still reached and cleared. A software whose
        // processing threw is absent from the plan: its state is unknown.
        foreach (self::stalePlan($hadFinding, $asserted, $failed) as $sid => $keep) {
            FindingWriter::clearExcept(Code::licSoftware($sid), $keep, 'no longer classified as needing a licence, now licensed, or no longer installed in scope', $run, $scope);
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
            // product is deliberately NOT queued: the queue is a worklist of
            // potential gaps, and padding it with settled software would make
            // it grow unread.
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

    /**
     * PURE. Installed version names of one entity (already distinct and non-blank).
     *
     * @param array<int,array<int,string>> $byEntity entities_id => version names
     * @return string[]
     */
    public static function versionsForEntity(array $byEntity, int $entity): array
    {
        return array_values($byEntity[$entity] ?? []);
    }

    /** PURE. Whether a software's publisher is on the watch list. 0 and dangling ids are never on it. */
    public static function onWatchList(int $manufacturersId, array $watch): bool
    {
        return $manufacturersId > 0 && in_array($manufacturersId, $watch, true);
    }

    /**
     * PURE. Which existing SAC-LIC-SW Findings to clear, and the entities to keep.
     * Every software that has a Finding is cleared except in the entities where
     * this run asserted it; a software this run never reached (uninstalled,
     * all hosts trashed, ignored category) keeps nothing. A software whose
     * processing failed is left alone: its state is unknown.
     *
     * @param array<int,true>           $hadFinding softwares_id => true
     * @param array<int,array<int,int>> $asserted   softwares_id => entities asserted this run
     * @param array<int,true>           $failed
     * @return array<int,array<int,int>> softwares_id => entities to keep
     */
    public static function stalePlan(array $hadFinding, array $asserted, array $failed): array
    {
        $plan = [];
        foreach (array_keys($hadFinding) as $sid) {
            if (isset($failed[$sid])) {
                continue;
            }
            $plan[(int) $sid] = $asserted[$sid] ?? [];
        }
        return $plan;
    }

    /**
     * PURE. The entity first, then its ancestors, nearest first.
     *
     * Order is derived from `glpi_entities.level` (deeper = nearer), never from
     * the order getAncestorsOf() returns. Guarantee: strictly descending level,
     * ties (not possible in a tree) broken by id.
     *
     * @param int[]            $ancestorIds ancestors in any order
     * @param array<int,int>   $levelById   entities_id => level
     * @return int[]
     */
    public static function nearestFirst(int $entity, array $ancestorIds, array $levelById): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ancestorIds),
            static fn (int $id): bool => $id !== $entity
        )));
        usort($ids, static fn (int $a, int $b): int =>
            [(int) ($levelById[$b] ?? 0), $a] <=> [(int) ($levelById[$a] ?? 0), $b]);
        return array_merge([$entity], $ids);
    }

    /** @return array<int,int> the entity first, then its ancestors, nearest first */
    private static function ancestry(int $entity): array
    {
        global $DB;

        /** @var array<int,array<int,int>> $cache */
        static $cache = [];
        if (!isset($cache[$entity])) {
            if ($entity === 0) {
                $cache[$entity] = [0];
            } else {
                $ancestors = array_values(array_map('intval', getAncestorsOf('glpi_entities', $entity)));
                $levels = [];
                if ($ancestors !== []) {
                    foreach ($DB->request(['SELECT' => ['id', 'level'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $ancestors]]) as $row) {
                        $levels[(int) $row['id']] = (int) $row['level'];
                    }
                }
                $cache[$entity] = self::nearestFirst($entity, $ancestors, $levels);
            }
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
     * @return iterable<array{id:int|string,name:?string,manufacturers_id:int|string,versions_by_entity:array<int,array<int,string>>}>
     */
    private static function candidateSoftware(array $ignore): iterable
    {
        global $DB;

        $quoted = [];
        foreach ($ignore as $c) {
            $c = (string) $c;
            if ($c !== '') {
                $quoted[] = $DB->quote($c);
            }
        }

        $res = $DB->doQuery(self::candidateSql($quoted));
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
                    'versions_by_entity' => [],
                ];
            }
            $vn = (string) ($row['version_name'] ?? '');
            // GLPI routinely yields blank versions; a blank would undecide a
            // software whose every real version matched.
            if ($vn !== '') {
                $current['versions_by_entity'][(int) $row['entities_id']][$vn] = $vn;
            }
        }
        if ($current !== null) {
            yield $current;
        }
    }

    /**
     * The candidate SELECT. Every predicate here is a promise: removing one
     * silently changes what "installed" means (and the clearing path with it),
     * and adding a publisher predicate restores the 817-false-Finding bug.
     *
     * PURE (no $DB): $quotedIgnore holds SQL-quoted category names, so the
     * shape of the query can be asserted offline.
     *
     * @param array<int,string> $quotedIgnore already-quoted category names
     */
    public static function candidateSql(array $quotedIgnore): string
    {
        $catFilter = '';
        if ($quotedIgnore !== []) {
            $catFilter = 'AND (`cat`.`name` IS NULL OR `cat`.`name` NOT IN (' . implode(',', $quotedIgnore) . '))';
        }

        // One row per (software, entity, distinct version name), folded back
        // into one software with a per-entity version map below. Computers are
        // joined (not deleted, not template) so "installed" means exactly what
        // AssetResolver::computersForSoftware() means. Not GROUP_CONCAT: its 1024-byte
        // default would silently truncate a long version list, and the matcher
        // needs every installed version. The INNER JOINs keep the old "has a
        // referenced version on a Computer" rule. There is deliberately no
        // glpi_manufacturers join: a dangling manufacturers_id must not drop
        // the software.
        $sql = "SELECT DISTINCT `sw`.`id` AS `id`, `sw`.`name` AS `name`,
                       `sw`.`manufacturers_id` AS `manufacturers_id`,
                       `sv`.`name` AS `version_name`, `c`.`entities_id` AS `entities_id`
                FROM `glpi_softwares` AS `sw`
                LEFT JOIN `glpi_softwarecategories` AS `cat` ON `cat`.`id` = `sw`.`softwarecategories_id`
                INNER JOIN `glpi_softwareversions` AS `sv` ON `sv`.`softwares_id` = `sw`.`id`
                INNER JOIN `glpi_items_softwareversions` AS `isv`
                        ON `isv`.`softwareversions_id` = `sv`.`id`
                       AND `isv`.`itemtype` = 'Computer'
                       AND `isv`.`is_deleted` = 0
                INNER JOIN `glpi_computers` AS `c`
                        ON `c`.`id` = `isv`.`items_id`
                       AND `c`.`is_deleted` = 0
                       AND `c`.`is_template` = 0
                WHERE `sw`.`is_deleted` = 0
                  AND `sw`.`is_template` = 0
                  $catFilter
                ORDER BY `sw`.`id`";

        return $sql;
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
                // manufacturers_id 987654 points at a deleted row; 0 is blank.
                $watch = [5, 6, 0];
                sac_assert_eq(self::onWatchList(987654, $watch), false, 'a dangling id is not on the watch list');
                sac_assert_eq(self::onWatchList(0, $watch), false, 'a blank id is never on the watch list');
                sac_assert_eq(self::onWatchList(5, $watch), true, 'a listed publisher is');
                foreach ([987654, 0] as $mid) {
                    sac_assert_eq(self::classify('acme-db', ['1.0'], [$needs], [0], self::onWatchList($mid, $watch), false)['action'], 'finding', "manufacturers_id $mid still reaches classification and fires the rule");
                }
            },
            'license/candidate-query-never-filters-by-publisher' => static function (): void {
                // The 817-false-Finding regression path is a publisher gate:
                // either a `glpi_manufacturers` join in the candidate query or
                // a `$mid > 0` skip in run(). The join is assertable offline;
                // the skip is not (see db/lic-4 for the full path).
                $publisherFilter = static function (string $sql): bool {
                    return str_contains(strtolower($sql), 'glpi_manufacturers')
                        || (bool) preg_match('/`?manufacturers_id`?\s*(?:=|\bIN\b|\bIS\b|>)/i', $sql);
                };
                $sql = self::candidateSql(["'system_component'"]);
                sac_assert(str_contains($sql, "'system_component'"), 'an ignored category still reaches the query');
                sac_assert(!$publisherFilter($sql), 'the candidate query joins no publisher table and filters on no manufacturers_id');
                sac_assert(
                    $publisherFilter($sql . " INNER JOIN `glpi_manufacturers` AS `m` ON `m`.`id` = `sw`.`manufacturers_id`"),
                    'the probe itself catches a reintroduced publishers join - it is not vacuous'
                );
                sac_assert(str_contains($sql, 'glpi_computers'), 'and it still resolves installs through live computers');
            },
            'license/stale-plan-clears-vanished-software' => static function (): void {
                $had = [10 => true, 11 => true, 12 => true, 13 => true];
                // 10: still asserted in entity 2; 11: reached but no longer asserted;
                // 12: never reached (all hosts trashed); 13: processing failed.
                $plan = self::stalePlan($had, [10 => [2], 11 => []], [13 => true]);
                sac_assert_eq($plan, [10 => [2], 11 => [], 12 => []], 'unreached software keeps nothing; failed software is left alone');
                sac_assert_eq(self::stalePlan([], [5 => [1]], []), [], 'software without a Finding is never cleared');
            },
            'license/rule-recursion-respected' => static function () use ($needs): void {
                $local = $needs;
                $local['is_recursive'] = 0;
                sac_assert_eq(self::classify('acme-db', ['1.0'], [$local], [5, 0], false, false)['action'], 'none', 'a non-recursive parent rule does not reach a child entity');
                sac_assert_eq(self::classify('acme-db', ['1.0'], [$needs], [5, 0], false, false)['action'], 'finding', 'a recursive parent rule does');
            },
            'license/ancestry-nearest-first-whatever-the-input-order' => static function () use ($needs): void {
                // 3 levels: 0 (root) > 3 > 7. Ancestors arrive in BOTH orders.
                $levels = [0 => 0, 3 => 1, 7 => 2];
                sac_assert_eq(self::nearestFirst(9, [0, 3, 7], $levels), [9, 7, 3, 0], 'root-first input');
                sac_assert_eq(self::nearestFirst(9, [7, 3, 0], $levels), [9, 7, 3, 0], 'nearest-first input');
                $rootExact = ['id' => 1, 'entities_id' => 0, 'is_recursive' => 1, 'pattern' => 'acme-db', 'match_on' => 'name', 'classification' => 'needs_license'];
                $nearBroad = ['id' => 2, 'entities_id' => 7, 'is_recursive' => 1, 'pattern' => 'acme-*', 'match_on' => 'name', 'classification' => 'free'];
                foreach ([[0, 3, 7], [7, 3, 0]] as $input) {
                    $c = self::classify('acme-db', ['1'], [$rootExact, $nearBroad], self::nearestFirst(9, $input, $levels), false, false);
                    sac_assert_eq($c['action'], 'none', 'the nearest entity broad rule beats a root exact rule');
                    sac_assert_eq((int) $c['rule']['id'], 2, 'rule from the nearest ancestor decided');
                }
            },
            'license/versions-are-per-entity' => static function (): void {
                $by = [1 => ['1.0'], 2 => ['9.9-el9']];
                sac_assert_eq(self::versionsForEntity($by, 1), ['1.0'], 'entity 1 sees only its own versions');
                sac_assert_eq(self::versionsForEntity($by, 2), ['9.9-el9'], 'entity 2');
                sac_assert_eq(self::versionsForEntity($by, 3), [], 'an entity with none');
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

            // LIC-4 — Review Focus 5 / the 817-false-Finding regression path.
            // A blank (0) or dangling manufacturers_id must still reach
            // classification through the REAL run() loop: the offline checks can
            // prove the candidate SQL carries no publisher predicate, but only a
            // full run() can prove no `$mid > 0` skip sits between the query and
            // the rule. Both are the way back to the 817 false Findings.
            'db/lic-4-missing-manufacturer-is-not-skipped' => static function (): void {
                if (!FindingWriter::isAvailable()) { return; }
                global $DB;
                $f = new \GlpiPlugin\Riskregister\Finding();
                // 0 = blank; 987654 = points at a row that does not exist.
                foreach ([0, 987654] as $mid) {
                    $ids     = self::scSeed(false);
                    $code    = Code::licSoftware($ids['sid']);
                    $startId = self::scRunFloor();
                    try {
                        $DB->update('glpi_softwares', ['manufacturers_id' => $mid], ['id' => $ids['sid']]);
                        self::scRule();
                        $run = RunLog::begin('license');
                        self::run($run);
                        $run->finish('ok');
                        sac_assert_eq(
                            count($f->find(['code' => $code])),
                            1,
                            "LIC-4: manufacturers_id $mid still produces a Finding (no publisher gate in run)"
                        );
                    } finally {
                        self::scTeardown($ids, [$code], $startId);
                    }
                }
            },
        ];
    }
}
