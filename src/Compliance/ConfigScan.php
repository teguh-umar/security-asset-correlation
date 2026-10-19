<?php

namespace GlpiPlugin\Sac\Compliance;

use GlpiPlugin\Sac\Code;
use GlpiPlugin\Sac\Config;
use GlpiPlugin\Sac\FindingWriter;
use GlpiPlugin\Sac\Reconcile;
use GlpiPlugin\Sac\RunLog;
use GlpiPlugin\Sac\Severity;

/**
 * Module 3 Phase 1 (Tier 1) orchestration — pulls the oscap summary line
 * from each admin-registered `glpi_plugin_sac_sshtargets` row over a
 * restricted SSH connection ({@see SshSummaryReader}), upserts the latest
 * reading into `glpi_plugin_sac_configscan`, and emits/clears a
 * per-computer Finding via {@see FindingWriter} when the score is below
 * threshold.
 *
 * v1.7.1: replaces a v1.7.0 design built on GLPI Inventory's Collect
 * feature — VM verification showed Collect only supports file-metadata/
 * registry/WMI collection, none of which can capture a Linux cron's
 * arbitrary text output, so there was no way to make that design work.
 *
 * `run()` only does two things: fetch the raw line over SSH, and hand it
 * to {@see processTarget()}. Keeping the Finding-emission logic in its own
 * method — independent of how the raw line arrived — lets it be exercised
 * directly with fixture strings in a VM test, with no real SSH host
 * required; only the fetch step itself needs one.
 */
final class ConfigScan
{
    public static function run(RunLog $run): void
    {
        $sshConfig = [
            'key_path'         => (string) Config::get('compliance_ssh_key_path'),
            'known_hosts_path' => (string) Config::get('compliance_ssh_known_hosts_path'),
            'remote_path'      => (string) Config::get('compliance_remote_summary_path'),
            'timeout_seconds'  => (int) Config::get('compliance_ssh_timeout_seconds'),
        ];

        // Checked ONCE here, not inside SshSummaryReader per host: a
        // missing key/known_hosts file is a configuration error, not "this
        // one host is unreachable" — one warning for the whole run, not one
        // per registered target.
        if (!is_readable($sshConfig['key_path']) || !is_readable($sshConfig['known_hosts_path'])) {
            $run->bump('skipped');
            \Toolbox::logInFile('sac', 'ConfigScan: SSH key or known_hosts file not readable (compliance_ssh_key_path / compliance_ssh_known_hosts_path) — skipped' . "\n");
            return;
        }

        $catId = FindingWriter::categoryId('Configuration Compliance', 0);
        $scope = Config::scanScope();

        foreach (self::activeTargets($scope) as $target) {
            $run->bump('scanned');

            $raw = SshSummaryReader::fetchRaw(
                ['host' => $target['host'], 'port' => $target['port'], 'ssh_user' => $target['ssh_user']],
                $sshConfig
            );
            if ($raw === null || !$raw['ok']) {
                // Unreachable / auth failed / timed out this run — real,
                // but routine enough (maintenance windows, transient
                // network blips) that it must not abort the batch. Logged
                // with stderr for diagnosis; counted so an admin watching
                // the run dashboard sees it's not silently fine.
                $run->bump('errors');
                \Toolbox::logInFile('sac', sprintf(
                    "ConfigScan: SSH pull failed for computers_id=%d (%s@%s:%d): %s\n",
                    $target['computers_id'],
                    $target['ssh_user'],
                    $target['host'],
                    $target['port'],
                    $raw['stderr'] ?? 'proc_open failed'
                ));
                continue;
            }

            $value = trim($raw['stdout']);
            if ($value === '') {
                continue; // nothing written on the remote host yet — not an error
            }

            self::processTarget($target, $value, $catId, $scope, $run);
        }
    }

    /**
     * Everything that happens once a raw summary line for one target is in
     * hand: parse it, upsert `_configscan`, and emit/clear the Finding.
     * Independent of transport — takes the already-fetched string.
     *
     * @param array{computers_id:int,entities_id:int,name:string} $target
     * @param array<int,int>|null $scope {@see Config::scanScope()}
     */
    private static function processTarget(array $target, string $value, int $catId, ?array $scope, RunLog $run): void
    {
        global $DB;
        $cid = $target['computers_id'];

        // A pull is one summary line plus zero or more rule lines. Split
        // first: a payload with no summary line is unusable, and must NOT
        // reach RuleStore::sync() — an empty rule list there resolves every
        // open rule on the host, so a truncated transfer would read as
        // "everything got fixed".
        $payload = SummaryPayload::split($value);
        if ($payload['summary'] === null) {
            Reconcile::note('config', 'Computer', $cid, 0, 'bad_summary', $value);
            $run->bump('errors');
            return;
        }

        $parsed = ScoreLine::parse($payload['summary']);
        if ($parsed === null) {
            Reconcile::note('config', 'Computer', $cid, 0, 'bad_summary', $payload['summary']);
            $run->bump('errors');
            return;
        }

        $critical  = (int) Config::get('compliance_score_critical');
        $high      = (int) Config::get('compliance_score_high');
        $medium    = (int) Config::get('compliance_score_medium');
        $staleDays = (int) Config::get('compliance_stale_days');

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_sac_configscan',
            'WHERE' => ['computers_id' => $cid],
        ])->current();
        $scanRow = [
            'computers_id'   => $cid,
            'benchmark_id'   => $parsed['benchmark_id'],
            'score'          => $parsed['score'],
            'pass_count'     => $parsed['pass'],
            'fail_count'     => $parsed['fail'],
            'other_count'    => $parsed['other'],
            'date_scan'      => $parsed['date_scan'],
            'date_collected' => $now,
            'raw_line'       => $value,
        ];
        if ($existing) {
            $DB->update('glpi_plugin_sac_configscan', $scanRow, ['id' => (int) $existing['id']]);
        } else {
            $DB->insert('glpi_plugin_sac_configscan', $scanRow);
        }

        // Only reached with a summary that parsed, so an empty $rules here
        // genuinely means "this scan found nothing failing" — not "the pull
        // failed", which returned above.
        $rules = [];
        foreach ($payload['rules'] as $ruleLine) {
            $rule = RuleLine::parse($ruleLine);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }
        RuleStore::sync($cid, $rules, $parsed['date_scan']);

        $code = Code::configScore($cid);
        $sev  = Severity::forConfigScore($parsed['score'], $critical, $high, $medium);

        if ($sev === null) {
            FindingWriter::clearExcept($code, [], 'config score reached 100% on ' . date('Y-m-d'), $run, $scope);
            return;
        }

        // Staleness is judged from the oscap scan's OWN reported timestamp
        // (embedded in the summary line by the host's cron), not from "did
        // our SSH pull just succeed" — a pull can succeed this instant
        // while reading a file the host's cron hasn't refreshed in weeks.
        $stale = self::isStale($parsed['date_scan'], $staleDays);
        $content = sprintf(
            "Benchmark: %s\nScore: %.2f%%\nPass: %d  Fail: %d  Other: %d\nScan reported by host: %s%s",
            $parsed['benchmark_id'],
            $parsed['score'],
            $parsed['pass'],
            $parsed['fail'],
            $parsed['other'],
            $parsed['date_scan'],
            $stale ? "\n\n[STALE — host's own scan timestamp is over {$staleDays} days old]" : ''
        );

        // A digest of the worst open rules, so the Finding alone is enough
        // to start mitigating. The full checklist lives on SAC's own page —
        // 55 rules in one text field is unreadable, which is the complaint
        // this whole phase exists to answer.
        $openRules = RuleStore::openFor($cid);
        if ($openRules !== []) {
            $content .= "\n\n" . __('Top failing rules:', 'sac');
            foreach (array_slice($openRules, 0, 10) as $r) {
                $content .= sprintf("\n- [%s] %s", (string) $r['severity'], (string) $r['title']);
            }
            $remaining = count($openRules) - 10;
            if ($remaining > 0) {
                $content .= "\n" . sprintf(
                    __('…and %d more — see Config compliance in SAC for the full checklist.', 'sac'),
                    $remaining
                );
            }
        }

        $entity = $target['entities_id'];

        // FindingWriter::upsert() finds-or-creates by (entities_id, code).
        // If this Computer moved to a different entity since its last
        // below-threshold scan, that lookup misses the old row and a
        // SECOND row gets created here, leaving the old entity's Finding
        // open forever (entities are separate companies — hub §4 — a
        // wrong-attribution bug, not just a stale note).
        FindingWriter::clearExcept($code, [$entity], 'computer re-scanned under a different entity on ' . date('Y-m-d'), $run, $scope);

        $input = [
            'entities_id'    => $entity,
            'code'           => $code,
            'name'           => sprintf(__('Config compliance below threshold: %s', 'sac'), $target['name']),
            // %.2f, matching both the Finding's own Description and the
            // dashboard's decimal(5,2) column — %.1f here printed the same
            // score as "49.2%" next to "49.17%" elsewhere, which reads like
            // two different measurements of the same host.
            'scope_label'    => sprintf('%s — %.2f%%', $target['name'], $parsed['score']),
            'severity'       => $sev,
            'status'         => 'Open',
            'content'        => $content,
            'assessment_ref' => 'SAC/config',
            'plugin_riskregister_riskcategories_id' => $catId,
        ];

        $findingId = FindingWriter::upsert($input, [$cid], '', $run);
        if ($findingId !== null) {
            // Open AND resolved rules: a fixed item should show as Resolved
            // on the Finding, not vanish from it. No-op until riskregister
            // 2.1.0 is installed (FindingAffected guards on method_exists).
            FindingAffected::push($findingId, RuleStore::allFor($cid));
        }
    }

    /**
     * Active `glpi_plugin_sac_sshtargets` rows joined to their Computer,
     * restricted to {@see Config::scanScope()} and excluding a
     * deleted/template Computer.
     *
     * @param array<int,int>|null $scope null = every entity
     * @return array<int,array{computers_id:int,entities_id:int,name:string,host:string,port:int,ssh_user:string}>
     */
    private static function activeTargets(?array $scope): array
    {
        global $DB;
        $where = [
            'glpi_plugin_sac_sshtargets.is_active' => 1,
            'glpi_computers.is_deleted'            => 0,
            'glpi_computers.is_template'            => 0,
        ];
        if ($scope !== null) {
            $where['glpi_computers.entities_id'] = $scope;
        }

        $out = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_plugin_sac_sshtargets.computers_id AS computers_id',
                'glpi_plugin_sac_sshtargets.host AS host',
                'glpi_plugin_sac_sshtargets.port AS port',
                'glpi_plugin_sac_sshtargets.ssh_user AS ssh_user',
                'glpi_computers.name AS name',
                'glpi_computers.entities_id AS entities_id',
            ],
            'FROM'       => 'glpi_plugin_sac_sshtargets',
            'INNER JOIN' => [
                'glpi_computers' => [
                    'ON' => [
                        'glpi_plugin_sac_sshtargets' => 'computers_id',
                        'glpi_computers'              => 'id',
                    ],
                ],
            ],
            'WHERE' => $where,
        ]) as $row) {
            $out[] = [
                'computers_id' => (int) $row['computers_id'],
                'entities_id'  => (int) $row['entities_id'],
                'name'         => (string) $row['name'],
                'host'         => (string) $row['host'],
                'port'         => (int) $row['port'],
                'ssh_user'     => (string) $row['ssh_user'],
            ];
        }
        return $out;
    }

    /** PURE. */
    private static function isStale(?string $scanDate, int $staleDays): bool
    {
        if ($scanDate === null || $staleDays <= 0) {
            return false;
        }
        $ts = strtotime($scanDate);
        return $ts !== false && $ts < strtotime("-{$staleDays} days");
    }

    /** @return array<string,callable():void> First check is PURE (runs offline); the rest are VM-only. */
    public static function selfChecks(): array
    {
        return [
            // PURE — runs in tools/offline-selfcheck.php. Covers plan
            // Review Focus #2 (stale data) at the unit level;
            // db/configscan-stale-report-flagged below covers the same
            // requirement end-to-end through a real Finding.
            'configscan/is-stale-pure' => static function (): void {
                sac_assert(!self::isStale(null, 14), 'no date at all is never stale');
                sac_assert(!self::isStale('2026-10-01 00:00:00', 0), 'staleDays<=0 disables the check');
                sac_assert(!self::isStale(date('Y-m-d H:i:s', strtotime('-13 days')), 14), 'just under the threshold is not stale');
                sac_assert(self::isStale(date('Y-m-d H:i:s', strtotime('-15 days')), 14), 'past the threshold is stale');
                sac_assert(!self::isStale('not-a-date', 14), 'an unparseable date is never flagged stale (fails safe)');
            },

            'db/configscan-missing-key-warns-once' => static function (): void {
                global $DB;
                $originalKeyPath = (string) Config::get('compliance_ssh_key_path');
                $floor = (int) ($DB->request(['SELECT' => ['MAX(id) AS m'], 'FROM' => 'glpi_plugin_sac_run'])->current()['m'] ?? 0);
                try {
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_ssh_key_path' => '/this/path/does/not/exist']);

                    $run = RunLog::begin('config');
                    self::run($run);
                    $run->finish('ok');

                    $row = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_run',
                        'WHERE' => ['module' => 'config', 'id' => ['>', $floor]],
                        'ORDER' => ['id DESC'],
                    ])->current();
                    sac_assert($row !== null, 'a _run row was opened');
                    $counts = json_decode((string) ($row['counts_json'] ?? ''), true) ?: [];
                    sac_assert_eq((int) ($counts['skipped'] ?? 0), 1, 'skipped is bumped once for the whole run, not per target');
                    sac_assert_eq((int) ($counts['scanned'] ?? 0), 0, 'no per-target loop ran once the local key file itself was missing');
                } finally {
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_ssh_key_path' => $originalKeyPath]);
                    $DB->delete('glpi_plugin_sac_run', ['module' => 'config', 'id' => ['>', $floor]]);
                }
            },

            'db/configscan-active-targets-shape' => static function (): void {
                global $DB;
                $cid = (int) (new \Computer())->add(['name' => '__sac_sc_target_host__', 'entities_id' => 0]);
                try {
                    $DB->insert('glpi_plugin_sac_sshtargets', [
                        'computers_id' => $cid,
                        'host'         => '10.0.0.5',
                        'port'         => 22,
                        'ssh_user'     => 'pgnusr',
                        'is_active'    => 1,
                    ]);
                    $targets = self::activeTargets(null);
                    $match = null;
                    foreach ($targets as $t) {
                        if ($t['computers_id'] === $cid) { $match = $t; break; }
                    }
                    sac_assert($match !== null, 'active target found');
                    sac_assert_eq($match['host'], '10.0.0.5', 'host carried through the join');
                    sac_assert_eq($match['ssh_user'], 'pgnusr', 'ssh_user carried through the join');
                    sac_assert_eq($match['name'], '__sac_sc_target_host__', 'computer name carried through the join');

                    $DB->update('glpi_plugin_sac_sshtargets', ['is_active' => 0], ['computers_id' => $cid]);
                    $targets2 = self::activeTargets(null);
                    sac_assert(!in_array($cid, array_column($targets2, 'computers_id'), true), 'inactive target excluded');
                } finally {
                    $DB->delete('glpi_plugin_sac_sshtargets', ['computers_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            // The following exercise processTarget() DIRECTLY with a
            // fixture line, bypassing SshSummaryReader entirely — these
            // need riskregister (for the real Finding) but NOT a reachable
            // SSH host, since the transport step is skipped altogether.
            'db/configscan-processtarget-emits-finding-below-threshold' => static function (): void {
                if (!FindingWriter::isAvailable()) {
                    echo "SKIP  db/configscan-processtarget-emits-finding-below-threshold — riskregister not available\n";
                    return;
                }
                global $DB;
                $cid = (int) (new \Computer())->add(['name' => '__sac_sc_pt_host__', 'entities_id' => 0]);
                $code = Code::configScore($cid);
                $f = new \GlpiPlugin\Riskregister\Finding();
                $target = ['computers_id' => $cid, 'entities_id' => 0, 'name' => '__sac_sc_pt_host__'];
                $catId = FindingWriter::categoryId('Configuration Compliance', 0);
                try {
                    $run = RunLog::begin('selftest');
                    self::processTarget($target, 'SACCFG1|xccdf_bm|40.00|10|20|0|2026-10-14T00:00:00Z', $catId, null, $run);
                    $run->finish('ok');

                    $rows = $f->find(['entities_id' => 0, 'code' => $code]);
                    sac_assert_eq(count($rows), 1, 'one Finding written for the below-threshold host');
                    $row = array_values($rows)[0];
                    sac_assert_eq($row['severity'], 'Critical', 'score 40 is Critical');

                    $scanRow = $DB->request(['FROM' => 'glpi_plugin_sac_configscan', 'WHERE' => ['computers_id' => $cid]])->current();
                    sac_assert($scanRow !== null, 'configscan row written');
                    sac_assert_eq((float) $scanRow['score'], 40.00, 'score persisted');

                    // Re-run with a fully-compliant line: the Finding must clear.
                    $run2 = RunLog::begin('selftest');
                    self::processTarget($target, 'SACCFG1|xccdf_bm|100.00|30|0|0|2026-10-15T00:00:00Z', $catId, null, $run2);
                    $run2->finish('ok');

                    $f->getFromDB((int) $row['id']);
                    sac_assert(str_contains((string) $f->fields['content'], 'Signal cleared'), 'full compliance clears the finding');
                } finally {
                    foreach (array_keys($f->find(['entities_id' => 0, 'code' => $code])) as $fid) {
                        $f->delete(['id' => (int) $fid], true);
                    }
                    $DB->delete('glpi_plugin_sac_configscan', ['computers_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            // The dashboard links to this page by URL rather than through
            // the menu, so Menu's own page-exists loop does not cover it.
            'compliancedetail/page-exists' => static function (): void {
                sac_assert(
                    is_file(dirname(__DIR__, 2) . '/front/compliancedetail.php'),
                    'the per-host checklist page the dashboard links to exists'
                );
            },

            'db/configscan-processtarget-persists-rules' => static function (): void {
                global $DB;
                $cid = (int) (new \Computer())->add(['name' => '__sac_pt_rules__', 'entities_id' => 0]);
                $target = ['computers_id' => $cid, 'entities_id' => 0, 'name' => '__sac_pt_rules__'];
                $catId = FindingWriter::categoryId('Configuration Compliance', 0);
                try {
                    $payload = "SACCFG1|bm|49.17|22|55|753|2026-10-14T11:55:14+0700\n"
                             . "SACRULE1|rule_a|fail|high|CISv8:4.1|Disable root login\n"
                             . "SACRULE1|rule_b|fail|low||Set umask\n";
                    $run = RunLog::begin('selftest');
                    self::processTarget($target, $payload, $catId, null, $run);
                    $run->finish('ok');

                    $rows = iterator_to_array($DB->request([
                        'FROM'  => 'glpi_plugin_sac_configrules',
                        'WHERE' => ['computers_id' => $cid],
                    ]));
                    sac_assert_eq(count($rows), 2, 'both rules persisted');
                } finally {
                    $DB->delete('glpi_plugin_sac_configrules', ['computers_id' => $cid]);
                    $DB->delete('glpi_plugin_sac_configscan', ['computers_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            // Review Focus #1 end-to-end: a payload whose summary line was
            // lost must leave existing open rules alone. The naive reading
            // of "no summary, two rule lines missing" is "zero failing", and
            // RuleStore::sync() would then resolve the lot.
            'db/configscan-truncated-payload-resolves-nothing' => static function (): void {
                global $DB;
                $cid = (int) (new \Computer())->add(['name' => '__sac_pt_trunc__', 'entities_id' => 0]);
                $target = ['computers_id' => $cid, 'entities_id' => 0, 'name' => '__sac_pt_trunc__'];
                $catId = FindingWriter::categoryId('Configuration Compliance', 0);
                try {
                    $good = "SACCFG1|bm|49.17|22|55|753|2026-10-14T11:55:14+0700\n"
                          . "SACRULE1|rule_a|fail|high||Disable root login\n";
                    $run = RunLog::begin('selftest');
                    self::processTarget($target, $good, $catId, null, $run);

                    sac_assert_eq(count(RuleStore::openFor($cid)), 1, 'one rule open after the good scan');

                    // Same host, next run, payload truncated to nothing usable.
                    self::processTarget($target, "garbage with no summary line\n", $catId, null, $run);
                    $run->finish('ok');

                    sac_assert_eq(count(RuleStore::openFor($cid)), 1, 'the open rule survives an unusable pull — it is NOT silently resolved');
                } finally {
                    $DB->delete('glpi_plugin_sac_reconcile', ['ref_itemtype' => 'Computer', 'ref_id' => $cid]);
                    $DB->delete('glpi_plugin_sac_configrules', ['computers_id' => $cid]);
                    $DB->delete('glpi_plugin_sac_configscan', ['computers_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            'db/configscan-processtarget-malformed-line-reconciles' => static function (): void {
                global $DB;
                $cid = (int) (new \Computer())->add(['name' => '__sac_sc_pt_bad_host__', 'entities_id' => 0]);
                $target = ['computers_id' => $cid, 'entities_id' => 0, 'name' => '__sac_sc_pt_bad_host__'];
                $catId = FindingWriter::categoryId('Configuration Compliance', 0);
                try {
                    $run = RunLog::begin('selftest');
                    self::processTarget($target, 'garbage not a summary line', $catId, null, $run);
                    $run->finish('ok');

                    $reconciled = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_reconcile',
                        'WHERE' => ['signal_type' => 'config', 'ref_itemtype' => 'Computer', 'ref_id' => $cid, 'reason' => 'bad_summary'],
                    ])->current();
                    sac_assert($reconciled !== null, 'malformed line produced a bad_summary reconcile row');
                } finally {
                    $DB->delete('glpi_plugin_sac_reconcile', ['ref_itemtype' => 'Computer', 'ref_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            'db/configscan-processtarget-stale-report-flagged' => static function (): void {
                if (!FindingWriter::isAvailable()) {
                    echo "SKIP  db/configscan-processtarget-stale-report-flagged — riskregister not available\n";
                    return;
                }
                global $DB;
                $cid = (int) (new \Computer())->add(['name' => '__sac_sc_pt_stale_host__', 'entities_id' => 0]);
                $code = Code::configScore($cid);
                $target = ['computers_id' => $cid, 'entities_id' => 0, 'name' => '__sac_sc_pt_stale_host__'];
                $catId = FindingWriter::categoryId('Configuration Compliance', 0);
                $f = new \GlpiPlugin\Riskregister\Finding();
                $originalStaleDays = Config::get('compliance_stale_days');
                try {
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_stale_days' => 14]);
                    $oldTs = str_replace(' ', 'T', date('Y-m-d H:i:s', strtotime('-30 days'))) . 'Z';

                    $run = RunLog::begin('selftest');
                    self::processTarget($target, "SACCFG1|xccdf_bm|40.00|10|20|0|{$oldTs}", $catId, null, $run);
                    $run->finish('ok');

                    $rows = $f->find(['entities_id' => 0, 'code' => $code]);
                    sac_assert_eq(count($rows), 1, 'one Finding written');
                    $row = array_values($rows)[0];
                    sac_assert(str_contains((string) $row['content'], 'STALE'), 'a scan timestamp older than compliance_stale_days is flagged STALE in the Finding content');
                } finally {
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_stale_days' => $originalStaleDays]);
                    foreach (array_keys($f->find(['entities_id' => 0, 'code' => $code])) as $fid) {
                        $f->delete(['id' => (int) $fid], true);
                    }
                    $DB->delete('glpi_plugin_sac_configscan', ['computers_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            // A Computer's code (Code::configScore($cid)) is fixed, but
            // FindingWriter::upsert() finds-or-creates by (entities_id,
            // code) — if the Computer moves entities between scans, that
            // lookup misses the old row at its old entity and would leave
            // it open forever without the clearExcept() call processTarget()
            // makes before every upsert. Mirrors FindingWriter's own
            // "db/writer-entity-split" check for the EOL module's
            // equivalent scenario.
            'db/configscan-processtarget-entity-move-clears-old-finding' => static function (): void {
                if (!FindingWriter::isAvailable()) {
                    echo "SKIP  db/configscan-processtarget-entity-move-clears-old-finding — riskregister not available\n";
                    return;
                }
                global $DB;
                $other = $DB->request([
                    'SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => ['>', 0]], 'LIMIT' => 1,
                ])->current();
                if (!$other) {
                    echo "SKIP  db/configscan-processtarget-entity-move-clears-old-finding — this GLPI has only the root entity\n";
                    return;
                }
                $e2  = (int) $other['id'];
                $cid = (int) (new \Computer())->add(['name' => '__sac_sc_pt_move_host__', 'entities_id' => 0]);
                $code = Code::configScore($cid);
                $catId = FindingWriter::categoryId('Configuration Compliance', 0);
                $f = new \GlpiPlugin\Riskregister\Finding();
                try {
                    $run = RunLog::begin('selftest');
                    $targetAtRoot = ['computers_id' => $cid, 'entities_id' => 0, 'name' => '__sac_sc_pt_move_host__'];
                    self::processTarget($targetAtRoot, 'SACCFG1|xccdf_bm|40.00|10|20|0|2026-10-14T00:00:00Z', $catId, null, $run);

                    $rootRows = $f->find(['entities_id' => 0, 'code' => $code]);
                    sac_assert_eq(count($rootRows), 1, 'Finding written at the original entity');
                    $rootId = (int) array_key_first($rootRows);

                    // Same Computer, now scanned under a different entity.
                    $targetMoved = ['computers_id' => $cid, 'entities_id' => $e2, 'name' => '__sac_sc_pt_move_host__'];
                    self::processTarget($targetMoved, 'SACCFG1|xccdf_bm|40.00|10|20|0|2026-10-15T00:00:00Z', $catId, null, $run);
                    $run->finish('ok');

                    $f->getFromDB($rootId);
                    sac_assert(str_contains((string) $f->fields['content'], 'Signal cleared'), 'the old entity\'s Finding is cleared, not left open forever');

                    $newRows = $f->find(['entities_id' => $e2, 'code' => $code]);
                    sac_assert_eq(count($newRows), 1, 'a new Finding exists at the Computer\'s current entity');
                } finally {
                    foreach (array_keys($f->find(['code' => $code])) as $fid) {
                        $f->delete(['id' => (int) $fid], true);
                    }
                    $DB->delete('glpi_plugin_sac_configscan', ['computers_id' => $cid]);
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },
        ];
    }
}
