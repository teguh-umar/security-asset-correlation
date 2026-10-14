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

    /** @return array<string,callable():void> VM-only. */
    public static function selfChecks(): array
    {
        return [
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
                    $DB->delete('glpi_plugin_sac_reconcile', ['ref_itemtype' => 'Computer', 'ref_id' => $cid]);
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
