<?php

namespace GlpiPlugin\Sac\Compliance;

use GlpiPlugin\Sac\AssetResolver;
use GlpiPlugin\Sac\Code;
use GlpiPlugin\Sac\Config;
use GlpiPlugin\Sac\FindingWriter;
use GlpiPlugin\Sac\Reconcile;
use GlpiPlugin\Sac\RunLog;
use GlpiPlugin\Sac\Severity;

/**
 * Module 3 Phase 1 (Tier 1) orchestration — reads the oscap summary line
 * GLPI Inventory's Collect task reports per host, upserts the latest reading
 * into `glpi_plugin_sac_configscan`, and emits/clears a per-computer Finding
 * via {@see FindingWriter} when the score is below threshold.
 */
final class ConfigScan
{
    public static function run(RunLog $run): void
    {
        if (!CollectReader::isAvailable()) {
            $run->bump('skipped');
            \Toolbox::logInFile('sac', 'ConfigScan: glpiinventory Collect not available — skipped' . "\n");
            return;
        }

        $collectName = (string) Config::get('compliance_collect_name');

        // A configured task name that matches no defined Collect task is a
        // misconfiguration, not "nobody has reported yet" — check it ONCE
        // here so it logs a single warning, instead of CollectReader::latestFor()
        // quietly returning null for every host in the loop below with no
        // signal that the name itself is wrong.
        if (!CollectReader::taskExists($collectName)) {
            $run->bump('skipped');
            \Toolbox::logInFile('sac', "ConfigScan: Collect task '$collectName' is not defined — skipped" . "\n");
            return;
        }

        $critical    = (int) Config::get('compliance_score_critical');
        $high        = (int) Config::get('compliance_score_high');
        $medium      = (int) Config::get('compliance_score_medium');
        $staleDays   = (int) Config::get('compliance_stale_days');
        $catId       = FindingWriter::categoryId('Configuration Compliance', 0);
        $scope       = Config::scanScope();

        global $DB;

        foreach (AssetResolver::activeComputers($scope) as $cid => $asset) {
            $run->bump('scanned');
            $collected = CollectReader::latestFor($cid, $collectName);
            if ($collected === null || trim($collected['value']) === '') {
                continue; // agent hasn't reported under this task name yet — not an error
            }

            $parsed = ScoreLine::parse($collected['value']);
            if ($parsed === null) {
                Reconcile::note('config', 'Computer', $cid, 0, 'bad_summary', $collected['value']);
                $run->bump('errors');
                continue;
            }

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
                'date_collected' => $collected['date'],
                'raw_line'       => $collected['value'],
            ];
            if ($existing) {
                $DB->update('glpi_plugin_sac_configscan', $scanRow, ['id' => (int) $existing['id']]);
            } else {
                $DB->insert('glpi_plugin_sac_configscan', $scanRow);
            }

            $code = Code::configScore($cid);
            $sev  = Severity::forConfigScore($parsed['score'], $critical, $high, $medium);

            if ($sev === null) {
                FindingWriter::clearExcept($code, [], 'config score reached 100% on ' . date('Y-m-d'), $run, $scope);
                continue;
            }

            $stale = self::isStale($collected['date'], $staleDays);
            $content = sprintf(
                "Benchmark: %s\nScore: %.2f%%\nPass: %d  Fail: %d  Other: %d\nLast scan reported: %s%s",
                $parsed['benchmark_id'],
                $parsed['score'],
                $parsed['pass'],
                $parsed['fail'],
                $parsed['other'],
                $collected['date'] ?? __('unknown', 'sac'),
                $stale ? "\n\n[STALE — no fresher Collect report in over {$staleDays} days]" : ''
            );

            $entity = (int) $asset['entities_id'];

            // FindingWriter::upsert() finds-or-creates by (entities_id, code).
            // If this Computer moved to a different entity since its last
            // below-threshold scan, that lookup misses the old row entirely
            // and a SECOND row gets created here, leaving the old entity's
            // Finding open forever (entities are separate companies — hub §4
            // — so this is a wrong-attribution bug, not just a stale note).
            // Clearing every other entity's copy of this per-computer code
            // first keeps exactly one open row, at the computer's current
            // entity, same as the EOL module's entity-split handling.
            FindingWriter::clearExcept($code, [$entity], 'computer re-scanned under a different entity on ' . date('Y-m-d'), $run, $scope);

            $input = [
                'entities_id'    => $entity,
                'code'           => $code,
                'name'           => sprintf(__('Config compliance below threshold: %s', 'sac'), $asset['name']),
                'scope_label'    => sprintf('%s — %.1f%%', $asset['name'], $parsed['score']),
                'severity'       => $sev,
                'status'         => 'Open',
                'content'        => $content,
                'assessment_ref' => 'SAC/config',
                'plugin_riskregister_riskcategories_id' => $catId,
            ];

            FindingWriter::upsert($input, [$cid], '', $run);
        }
    }

    /** PURE. */
    private static function isStale(?string $collectedDate, int $staleDays): bool
    {
        if ($collectedDate === null || $staleDays <= 0) {
            return false;
        }
        $ts = strtotime($collectedDate);
        return $ts !== false && $ts < strtotime("-{$staleDays} days");
    }

    /** @return array<string,callable():void> First check is PURE (runs offline); the rest are VM-only. */
    public static function selfChecks(): array
    {
        return [
            // PURE — runs in tools/offline-selfcheck.php. Covers plan Review
            // Focus #2 (stale data) at the unit level; db/configscan-stale-report-flagged
            // below covers the same requirement end-to-end through a real Finding.
            'configscan/is-stale-pure' => static function (): void {
                sac_assert(!self::isStale(null, 14), 'no date at all is never stale');
                sac_assert(!self::isStale('2026-10-01 00:00:00', 0), 'staleDays<=0 disables the check');
                sac_assert(!self::isStale(date('Y-m-d H:i:s', strtotime('-13 days')), 14), 'just under the threshold is not stale');
                sac_assert(self::isStale(date('Y-m-d H:i:s', strtotime('-15 days')), 14), 'past the threshold is stale');
                sac_assert(!self::isStale('not-a-date', 14), 'an unparseable date is never flagged stale (fails safe)');
            },
            'db/configscan-emits-finding-below-threshold' => static function (): void {
                if (!CollectReader::isAvailable() || !FindingWriter::isAvailable()) {
                    echo "SKIP  db/configscan-emits-finding-below-threshold — Collect schema or riskregister not available\n";
                    return;
                }
                global $DB;
                $name = '__sac_sc_cs_collect__';
                $cid  = (int) (new \Computer())->add(['name' => '__sac_sc_cs_host__', 'entities_id' => 0]);
                $collectId = 0;
                $code = Code::configScore($cid);
                $f = new \GlpiPlugin\Riskregister\Finding();
                $originalCollectName = (string) Config::get('compliance_collect_name');
                try {
                    $DB->insert('glpi_plugin_glpiinventory_collects', ['name' => $name]);
                    $collectId = (int) $DB->insertId();
                    $DB->insert('glpi_plugin_glpiinventory_collects_contents', [
                        'plugin_glpiinventory_collects_id' => $collectId,
                        'computers_id'                      => $cid,
                        'value'                              => 'SACCFG1|xccdf_bm|40.00|10|20|0|2026-10-14T00:00:00Z',
                        'date'                                => '2026-10-14 00:00:00',
                    ]);
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_collect_name' => $name]);

                    $run = RunLog::begin('selftest');
                    self::run($run);
                    $run->finish('ok');

                    $rows = $f->find(['entities_id' => 0, 'code' => $code]);
                    sac_assert_eq(count($rows), 1, 'one Finding written for the below-threshold host');
                    $row = array_values($rows)[0];
                    sac_assert_eq($row['severity'], 'Critical', 'score 40 is Critical');

                    $scanRow = $DB->request(['FROM' => 'glpi_plugin_sac_configscan', 'WHERE' => ['computers_id' => $cid]])->current();
                    sac_assert($scanRow !== null, 'configscan row written');
                    sac_assert_eq((float) $scanRow['score'], 40.00, 'score persisted');

                    // Re-run with a fully-compliant line: the Finding must clear.
                    $DB->update('glpi_plugin_glpiinventory_collects_contents',
                        ['value' => 'SACCFG1|xccdf_bm|100.00|30|0|0|2026-10-15T00:00:00Z', 'date' => '2026-10-15 00:00:00'],
                        ['computers_id' => $cid]);
                    $run2 = RunLog::begin('selftest');
                    self::run($run2);
                    $run2->finish('ok');

                    $f->getFromDB((int) $row['id']);
                    sac_assert(str_contains((string) $f->fields['content'], 'Signal cleared'), 'full compliance clears the finding');
                } finally {
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_collect_name' => $originalCollectName]);
                    foreach (array_keys($f->find(['entities_id' => 0, 'code' => $code])) as $fid) {
                        $f->delete(['id' => (int) $fid], true);
                    }
                    $DB->delete('glpi_plugin_sac_configscan', ['computers_id' => $cid]);
                    $DB->delete('glpi_plugin_glpiinventory_collects_contents', ['computers_id' => $cid]);
                    if ($collectId > 0) {
                        $DB->delete('glpi_plugin_glpiinventory_collects', ['id' => $collectId]);
                    }
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            'db/configscan-malformed-line-reconciles' => static function (): void {
                if (!CollectReader::isAvailable()) {
                    echo "SKIP  db/configscan-malformed-line-reconciles — Collect schema not available\n";
                    return;
                }
                global $DB;
                $name = '__sac_sc_cs_bad__';
                $cid  = (int) (new \Computer())->add(['name' => '__sac_sc_cs_bad_host__', 'entities_id' => 0]);
                $collectId = 0;
                $originalCollectName = (string) Config::get('compliance_collect_name');
                try {
                    $DB->insert('glpi_plugin_glpiinventory_collects', ['name' => $name]);
                    $collectId = (int) $DB->insertId();
                    $DB->insert('glpi_plugin_glpiinventory_collects_contents', [
                        'plugin_glpiinventory_collects_id' => $collectId,
                        'computers_id'                      => $cid,
                        'value'                              => 'garbage not a summary line',
                        'date'                                => '2026-10-14 00:00:00',
                    ]);
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_collect_name' => $name]);

                    $run = RunLog::begin('selftest');
                    self::run($run);
                    $run->finish('ok');

                    $reconciled = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_reconcile',
                        'WHERE' => ['signal_type' => 'config', 'ref_itemtype' => 'Computer', 'ref_id' => $cid, 'reason' => 'bad_summary'],
                    ])->current();
                    sac_assert($reconciled !== null, 'malformed line produced a bad_summary reconcile row');
                } finally {
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_collect_name' => $originalCollectName]);
                    $DB->delete('glpi_plugin_sac_reconcile', ['ref_itemtype' => 'Computer', 'ref_id' => $cid]);
                    $DB->delete('glpi_plugin_glpiinventory_collects_contents', ['computers_id' => $cid]);
                    if ($collectId > 0) {
                        $DB->delete('glpi_plugin_glpiinventory_collects', ['id' => $collectId]);
                    }
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },

            'db/configscan-missing-collect-task-warns-once' => static function (): void {
                if (!CollectReader::isAvailable()) {
                    echo "SKIP  db/configscan-missing-collect-task-warns-once — Collect schema not available\n";
                    return;
                }
                global $DB;
                $bogusName = '__sac_sc_cs_no_such_task__';
                $originalCollectName = (string) Config::get('compliance_collect_name');
                $floor = (int) ($DB->request(['SELECT' => ['MAX(id) AS m'], 'FROM' => 'glpi_plugin_sac_run'])->current()['m'] ?? 0);
                try {
                    sac_assert(!CollectReader::taskExists($bogusName), 'fixture precondition: no Collect task has this name');
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_collect_name' => $bogusName]);

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
                    sac_assert_eq((int) ($counts['skipped'] ?? 0), 1, 'skipped is bumped once for the whole run, not per host');
                    sac_assert_eq((int) ($counts['scanned'] ?? 0), 0, 'no per-host scan loop ran once the task name itself was unresolvable');
                } finally {
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_collect_name' => $originalCollectName]);
                    $DB->delete('glpi_plugin_sac_run', ['module' => 'config', 'id' => ['>', $floor]]);
                }
            },

            'db/configscan-stale-report-flagged' => static function (): void {
                if (!CollectReader::isAvailable() || !FindingWriter::isAvailable()) {
                    echo "SKIP  db/configscan-stale-report-flagged — Collect schema or riskregister not available\n";
                    return;
                }
                global $DB;
                $name = '__sac_sc_cs_stale__';
                $cid  = (int) (new \Computer())->add(['name' => '__sac_sc_cs_stale_host__', 'entities_id' => 0]);
                $collectId = 0;
                $code = Code::configScore($cid);
                $f = new \GlpiPlugin\Riskregister\Finding();
                $originalCollectName = (string) Config::get('compliance_collect_name');
                try {
                    $DB->insert('glpi_plugin_glpiinventory_collects', ['name' => $name]);
                    $collectId = (int) $DB->insertId();
                    $oldDate = date('Y-m-d H:i:s', strtotime('-30 days'));
                    $DB->insert('glpi_plugin_glpiinventory_collects_contents', [
                        'plugin_glpiinventory_collects_id' => $collectId,
                        'computers_id'                      => $cid,
                        'value'                              => 'SACCFG1|xccdf_bm|40.00|10|20|0|' . str_replace(' ', 'T', $oldDate) . 'Z',
                        'date'                                => $oldDate,
                    ]);
                    \Config::setConfigurationValues(Config::CONTEXT, [
                        'compliance_collect_name' => $name,
                        'compliance_stale_days'   => 14,
                    ]);

                    $run = RunLog::begin('selftest');
                    self::run($run);
                    $run->finish('ok');

                    $rows = $f->find(['entities_id' => 0, 'code' => $code]);
                    sac_assert_eq(count($rows), 1, 'one Finding written');
                    $row = array_values($rows)[0];
                    sac_assert(str_contains((string) $row['content'], 'STALE'), 'a report older than compliance_stale_days is flagged STALE in the Finding content');
                } finally {
                    \Config::setConfigurationValues(Config::CONTEXT, ['compliance_collect_name' => $originalCollectName]);
                    foreach (array_keys($f->find(['entities_id' => 0, 'code' => $code])) as $fid) {
                        $f->delete(['id' => (int) $fid], true);
                    }
                    $DB->delete('glpi_plugin_sac_configscan', ['computers_id' => $cid]);
                    $DB->delete('glpi_plugin_glpiinventory_collects_contents', ['computers_id' => $cid]);
                    if ($collectId > 0) {
                        $DB->delete('glpi_plugin_glpiinventory_collects', ['id' => $collectId]);
                    }
                    (new \Computer())->delete(['id' => $cid], true);
                }
            },
        ];
    }
}
