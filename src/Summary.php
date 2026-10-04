<?php

namespace GlpiPlugin\Sac;

/**
 * Overview-page summaries of the open SAC findings the current user may see:
 * one row per entity, and the signals that affect the most hosts across
 * entities (the group-level view). Read-only.
 *
 * Entity-safe by construction: the rows come from riskregister's findings
 * table restricted with core's entity criteria, so a subsidiary's user gets
 * its own entity only, and a group-level user (root, recursive profile) gets
 * every entity and their total.
 */
final class Summary
{
    private const SEVERITIES = ['Critical', 'High', 'Medium', 'Low'];

    /**
     * @return array{entities:array<int,array<string,mixed>>,total:array<string,int>,signals:array<int,array<string,mixed>>}|null
     *         null when riskregister is not available
     */
    public static function build(int $topSignals = 10): ?array
    {
        if (!FindingWriter::isAvailable()) {
            return null;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $table = \GlpiPlugin\Riskregister\Finding::getTable();
        $rows  = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id', 'code', 'name', 'severity', 'assessment_ref'],
            'FROM'   => $table,
            'WHERE'  => [
                'is_deleted'     => 0,
                'assessment_ref' => ['LIKE', 'SAC/%'],
                'status'         => ['<>', 'Closed'],
            ] + getEntitiesRestrictCriteria($table, '', '', true),
        ]) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $hosts = [];
        if ($rows !== []) {
            $links = \GlpiPlugin\Riskregister\Finding_Item::getTable();
            foreach ($DB->request([
                'SELECT' => ['plugin_riskregister_findings_id'],
                'FROM'   => $links,
                'WHERE'  => [
                    'plugin_riskregister_findings_id' => array_keys($rows),
                    'itemtype'                        => 'Computer',
                ],
            ]) as $link) {
                $fid         = (int) $link['plugin_riskregister_findings_id'];
                $hosts[$fid] = ($hosts[$fid] ?? 0) + 1;
            }
        }

        $out = self::aggregate($rows, $hosts, $topSignals);
        foreach ($out['entities'] as &$entity) {
            $entity['name'] = \Dropdown::getDropdownName('glpi_entities', $entity['entities_id']);
        }
        unset($entity);
        usort($out['entities'], static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $out;
    }

    /**
     * PURE. Fold finding rows into the per-entity table, its total, and the
     * top signals (one line per finding code, across entities).
     *
     * @param array<int,array<string,mixed>> $rows  [finding id => row]
     * @param array<int,int>                 $hosts [finding id => linked Computers]
     * @return array{entities:array<int,array<string,mixed>>,total:array<string,int>,signals:array<int,array<string,mixed>>}
     */
    public static function aggregate(array $rows, array $hosts, int $topSignals = 10): array
    {
        $blank    = ['eol' => 0, 'license' => 0, 'hosts' => 0, 'findings' => 0] + array_fill_keys(self::SEVERITIES, 0);
        $entities = [];
        $total    = $blank;
        $signals  = [];

        foreach ($rows as $id => $row) {
            $entity   = (int) $row['entities_id'];
            $module   = str_starts_with((string) $row['assessment_ref'], 'SAC/license') ? 'license' : 'eol';
            $severity = in_array($row['severity'], self::SEVERITIES, true) ? (string) $row['severity'] : 'Low';
            $n        = (int) ($hosts[$id] ?? 0);

            $entities[$entity] ??= ['entities_id' => $entity, 'name' => (string) $entity] + $blank;
            foreach ([&$entities[$entity], &$total] as &$bucket) {
                $bucket[$module]++;
                $bucket[$severity]++;
                $bucket['findings']++;
                $bucket['hosts'] += $n;
            }
            unset($bucket);

            $code = (string) $row['code'];
            $signals[$code] ??= ['code' => $code, 'name' => (string) $row['name'], 'module' => $module,
                                 'severity' => $severity, 'entities' => 0, 'hosts' => 0];
            $signals[$code]['entities']++;
            $signals[$code]['hosts'] += $n;
            if (array_search($severity, self::SEVERITIES, true) < array_search($signals[$code]['severity'], self::SEVERITIES, true)) {
                $signals[$code]['severity'] = $severity; // keep the worst
            }
        }

        $signals = array_values($signals);
        usort($signals, static fn (array $a, array $b): int => [$b['hosts'], $b['entities'], $a['code']] <=> [$a['hosts'], $a['entities'], $b['code']]);

        return [
            'entities' => array_values($entities),
            'total'    => $total,
            'signals'  => array_slice($signals, 0, max(0, $topSignals)),
        ];
    }

    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        return [
            // PURE — runs in tools/offline-selfcheck.php
            'summary/aggregate' => static function (): void {
                $rows = [
                    1 => ['entities_id' => 0, 'code' => 'SAC-EOL-OS-1-1', 'name' => 'EOL: A', 'severity' => 'High', 'assessment_ref' => 'SAC/eol'],
                    2 => ['entities_id' => 5, 'code' => 'SAC-EOL-OS-1-1', 'name' => 'EOL: A', 'severity' => 'Critical', 'assessment_ref' => 'SAC/eol'],
                    3 => ['entities_id' => 5, 'code' => 'SAC-LIC-MISSING-9', 'name' => 'Unlicensed: P', 'severity' => 'Low', 'assessment_ref' => 'SAC/license'],
                ];
                $out = self::aggregate($rows, [1 => 2, 2 => 3], 1);

                sac_assert_eq(count($out['entities']), 2, 'one row per entity');
                $e5 = $out['entities'][1];
                sac_assert_eq([$e5['entities_id'], $e5['eol'], $e5['license'], $e5['findings'], $e5['hosts']], [5, 1, 1, 2, 3], 'entity 5 counts');
                sac_assert_eq([$e5['Critical'], $e5['Low'], $e5['High']], [1, 1, 0], 'entity 5 severities');
                sac_assert_eq([$out['total']['findings'], $out['total']['hosts']], [3, 5], 'total across entities');

                sac_assert_eq(count($out['signals']), 1, 'top-N limit applied');
                $top = $out['signals'][0];
                sac_assert_eq([$top['code'], $top['entities'], $top['hosts'], $top['severity']], ['SAC-EOL-OS-1-1', 2, 5, 'Critical'], 'one line per code, worst severity, hosts summed');
            },
        ];
    }
}
