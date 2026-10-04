<?php

namespace GlpiPlugin\Sac;

/**
 * Maps an OperatingSystem or Software id to the Computer assets it touches.
 *
 * Read-only: every method is a guarded SELECT, no INSERT/UPDATE/DELETE.
 * Module 1 (EOL) uses {@see computersForOsVersion()} (the pair-exact grain);
 * `computersForOs()` is kept for its own selfChecks and any future
 * whole-OS use case. Module 2 (License) uses
 * {@see computersForSoftware()} / {@see installCount()}.
 *
 * `unresolved` is always empty here — an OS/Software id either resolves to
 * Computer rows or it does not. The key is kept for signature symmetry with
 * Module 3, where a finding can point at an asset that has since vanished.
 */
final class AssetResolver
{
    /**
     * Computers currently running the given OperatingSystem.
     *
     * @return array{resolved:array<int,string>,unresolved:array<int,string>}
     *         `resolved` is [computers_id => name]; `unresolved` is always [].
     */
    public static function computersForOs(int $osId): array
    {
        global $DB;
        $resolved = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_computers.id AS id',
                'glpi_computers.name AS name',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_items_operatingsystems',
            'INNER JOIN' => [
                'glpi_computers' => [
                    'ON' => [
                        'glpi_items_operatingsystems' => 'items_id',
                        'glpi_computers'              => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_items_operatingsystems.operatingsystems_id' => $osId,
                'glpi_items_operatingsystems.itemtype'            => 'Computer',
                'glpi_computers.is_deleted'                       => 0,
                'glpi_computers.is_template'                      => 0,
            ],
        ]) as $row) {
            $resolved[(int) $row['id']] = (string) $row['name'];
        }

        return ['resolved' => $resolved, 'unresolved' => []];
    }

    /**
     * Computers currently running the exact (OperatingSystem,
     * OperatingSystemVersion) pair — the v1.1.0 EOL grain (spec
     * 2026-09-10-eol-multidim-design.md §7.1). `verId = 0` is a legal,
     * distinct bucket ("version dimension not set/known").
     *
     * @return array{resolved:array<int,string>,unresolved:array<int,string>,entities:array<int,int>}
     *         `entities` is [computers_id => entities_id], see {@see groupByEntity()}.
     */
    public static function computersForOsVersion(int $osId, int $verId): array
    {
        global $DB;
        $resolved = [];
        $entities = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_computers.id AS id',
                'glpi_computers.name AS name',
                'glpi_computers.entities_id AS entities_id',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_items_operatingsystems',
            'INNER JOIN' => [
                'glpi_computers' => [
                    'ON' => [
                        'glpi_items_operatingsystems' => 'items_id',
                        'glpi_computers'              => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_items_operatingsystems.operatingsystems_id'        => $osId,
                'glpi_items_operatingsystems.operatingsystemversions_id' => $verId,
                'glpi_items_operatingsystems.itemtype'                   => 'Computer',
                'glpi_computers.is_deleted'                              => 0,
                'glpi_computers.is_template'                             => 0,
            ],
        ]) as $row) {
            $resolved[(int) $row['id']] = (string) $row['name'];
            $entities[(int) $row['id']] = (int) $row['entities_id'];
        }

        return ['resolved' => $resolved, 'unresolved' => [], 'entities' => $entities];
    }

    /**
     * PURE. Split a resolver result by the entity each Computer belongs to.
     * A finding is written per entity and links only that entity's assets
     * (suite entity rule: entities are separate companies).
     *
     * @param array{resolved:array<int,string>,entities?:array<int,int>} $assets
     * @param array<int,int>|null $scope entities to keep ({@see Config::scanScope()}); null = all
     * @return array<int,array<int,string>> [entities_id => [computers_id => name]], entity ascending
     */
    public static function groupByEntity(array $assets, ?array $scope = null): array
    {
        $out = [];
        foreach ($assets['resolved'] as $cid => $name) {
            $entity = (int) ($assets['entities'][$cid] ?? 0);
            if ($scope !== null && !in_array($entity, $scope, true)) {
                continue;
            }
            $out[$entity][(int) $cid] = (string) $name;
        }
        ksort($out);
        return $out;
    }

    /**
     * Computers with any version of the given Software installed.
     *
     * @return array{count:int,resolved:array<int,string>,entities:array<int,int>}
     *         `resolved` is [computers_id => name]; `count` is its size;
     *         `entities` is [computers_id => entities_id].
     */
    public static function computersForSoftware(int $softwareId): array
    {
        global $DB;
        $resolved = [];
        $entities = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_computers.id AS id',
                'glpi_computers.name AS name',
                'glpi_computers.entities_id AS entities_id',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_items_softwareversions',
            'INNER JOIN' => [
                'glpi_softwareversions' => [
                    'ON' => [
                        'glpi_items_softwareversions' => 'softwareversions_id',
                        'glpi_softwareversions'       => 'id',
                    ],
                ],
                'glpi_computers'        => [
                    'ON' => [
                        'glpi_items_softwareversions' => 'items_id',
                        'glpi_computers'              => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_softwareversions.softwares_id'      => $softwareId,
                'glpi_items_softwareversions.itemtype'    => 'Computer',
                'glpi_items_softwareversions.is_deleted'  => 0,
                'glpi_computers.is_deleted'               => 0,
                'glpi_computers.is_template'              => 0,
            ],
        ]) as $row) {
            $resolved[(int) $row['id']] = (string) $row['name'];
            $entities[(int) $row['id']] = (int) $row['entities_id'];
        }

        return ['count' => count($resolved), 'resolved' => $resolved, 'entities' => $entities];
    }

    /** Distinct Computer count with the given Software installed. */
    public static function installCount(int $softwareId): int
    {
        return self::computersForSoftware($softwareId)['count'];
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            // PURE — runs in tools/offline-selfcheck.php
            'assets/group-by-entity' => static function (): void {
                $out = self::groupByEntity([
                    'resolved' => [7 => 'c', 3 => 'a', 5 => 'b'],
                    'entities' => [7 => 2, 3 => 0, 5 => 2],
                ]);
                sac_assert_eq($out, [0 => [3 => 'a'], 2 => [7 => 'c', 5 => 'b']], 'one bucket per entity, entity ascending');
                sac_assert_eq(self::groupByEntity(['resolved' => []]), [], 'no assets, no buckets');
                $scoped = self::groupByEntity(['resolved' => [3 => 'a', 5 => 'b'], 'entities' => [3 => 0, 5 => 2]], [2]);
                sac_assert_eq($scoped, [2 => [5 => 'b']], 'an entity outside the scan scope is dropped');
            },
            'db/os-computers' => static function (): void {
                global $DB;
                // Create via ->add() and capture the generated ids — never
                // hardcode ~2.1e9 ids into core tables (InnoDB keeps the bumped
                // AUTO_INCREMENT after the delete).
                $osId = 0;
                $cId  = 0;

                try {
                    $osId = (int) (new \OperatingSystem())->add(['name' => '__sac_os__']);
                    $cId  = (int) (new \Computer())->add(['name' => '__sac_pc__', 'entities_id' => 0]);
                    $DB->insert('glpi_items_operatingsystems', [
                        'items_id'            => $cId,
                        'itemtype'            => 'Computer',
                        'operatingsystems_id' => $osId,
                    ]);

                    $res = self::computersForOs($osId);
                    sac_assert_eq($res['resolved'], [$cId => '__sac_pc__'], 'computersForOs returns [cid => name]');
                    sac_assert_eq($res['unresolved'], [], 'unresolved is always empty for OS');

                    // A deleted computer must drop out.
                    $DB->update('glpi_computers', ['is_deleted' => 1], ['id' => $cId]);
                    $res = self::computersForOs($osId);
                    sac_assert_eq($res['resolved'], [], 'deleted computer excluded');
                } finally {
                    $DB->delete('glpi_items_operatingsystems', ['operatingsystems_id' => $osId]);
                    $DB->delete('glpi_computers', ['id' => $cId]);
                    $DB->delete('glpi_operatingsystems', ['id' => $osId]);
                }
            },
            'db/os-version-computers' => static function (): void {
                global $DB;
                $osId = 0; $verId = 0; $cId = 0; $cId2 = 0;

                try {
                    $osId  = (int) (new \OperatingSystem())->add(['name' => '__sac_os2__']);
                    $verId = (int) (new \OperatingSystemVersion())->add([
                        'name' => '__sac_ver__', 'operatingsystems_id' => $osId,
                    ]);
                    $cId   = (int) (new \Computer())->add(['name' => '__sac_pc2a__', 'entities_id' => 0]);
                    $cId2  = (int) (new \Computer())->add(['name' => '__sac_pc2b__', 'entities_id' => 0]);
                    $DB->insert('glpi_items_operatingsystems', [
                        'items_id' => $cId, 'itemtype' => 'Computer',
                        'operatingsystems_id' => $osId, 'operatingsystemversions_id' => $verId,
                    ]);
                    // Same OS, version 0 (unknown) — must NOT show up for the specific version.
                    $DB->insert('glpi_items_operatingsystems', [
                        'items_id' => $cId2, 'itemtype' => 'Computer',
                        'operatingsystems_id' => $osId, 'operatingsystemversions_id' => 0,
                    ]);

                    $res = self::computersForOsVersion($osId, $verId);
                    sac_assert_eq($res['resolved'], [$cId => '__sac_pc2a__'], 'only the exact (os,ver) pair is returned');

                    $res0 = self::computersForOsVersion($osId, 0);
                    sac_assert_eq($res0['resolved'], [$cId2 => '__sac_pc2b__'], 'ver_id=0 is its own distinct bucket');
                } finally {
                    $DB->delete('glpi_items_operatingsystems', ['operatingsystems_id' => $osId]);
                    $DB->delete('glpi_computers', ['id' => $cId]);
                    $DB->delete('glpi_computers', ['id' => $cId2]);
                    $DB->delete('glpi_operatingsystemversions', ['id' => $verId]);
                    $DB->delete('glpi_operatingsystems', ['id' => $osId]);
                }
            },
        ];
    }
}
