<?php

namespace GlpiPlugin\Sac;

/**
 * What the current user may see and change on SAC's own pages (Overview,
 * Reconcile, Scan runs). In this instance a sub-entity is another company.
 *
 * SAC's own tables carry no `entities_id`, on purpose:
 *  - `_run` is the log of an instance-wide cron run. Its counters and error
 *    text cover every entity, so only a user who sees all entities gets them;
 *    anyone else sees that a scan ran, when, and whether it succeeded.
 *  - `_reconcile` rows point at a global dropdown (an OS + version, a
 *    publisher). An OS row belongs to every entity that has a Computer
 *    carrying that OS + version, so its entity is derived from the assets at
 *    read time. Publisher rows are about the shipped watch-list: group level.
 *  - `_eolmap`, `_eolcache`, `_commercialpublishers` are reference data shared
 *    by all entities; only someone with access to the root entity edits them.
 */
final class EntityScope
{
    /** True when the session covers every entity (always true on CLI / cron). */
    public static function seesAll(): bool
    {
        return \Session::canViewAllEntities();
    }

    /**
     * Resolving a reconcile row or mapping an OS changes what every entity's
     * next scan does, so it is a group-level action.
     */
    public static function canEditReference(): bool
    {
        return isCommandLine() || \Session::haveAccessToEntity(0);
    }

    /**
     * "osId:verId" pairs carried by a live Computer the current user may see;
     * null when the user sees all entities (no filtering needed).
     *
     * @return array<string,true>|null
     */
    public static function osPairsInView(): ?array
    {
        if (self::seesAll()) {
            return null;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $pairs = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_items_operatingsystems.operatingsystems_id AS os_id',
                'glpi_items_operatingsystems.operatingsystemversions_id AS ver_id',
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
                'glpi_items_operatingsystems.itemtype' => 'Computer',
                'glpi_computers.is_deleted'            => 0,
                'glpi_computers.is_template'           => 0,
            ] + getEntitiesRestrictCriteria('glpi_computers', '', '', true),
        ]) as $row) {
            $pairs[((int) $row['os_id']) . ':' . ((int) $row['ver_id'])] = true;
        }
        return $pairs;
    }

    /**
     * PURE. Whether a reconcile row is shown, given {@see osPairsInView()}.
     *
     * @param array<string,true>|null $pairs null = the user sees all entities
     */
    public static function reconcileRowVisible(string $itemtype, int $refId, int $subId, ?array $pairs): bool
    {
        if ($pairs === null) {
            return true;
        }
        return $itemtype === 'OperatingSystem' && isset($pairs["$refId:$subId"]);
    }

    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        return [
            // PURE — runs in tools/offline-selfcheck.php
            'entityscope/reconcile-row-visible' => static function (): void {
                sac_assert(self::reconcileRowVisible('Manufacturer', 4, 0, null), 'all-entity user sees every row');
                $pairs = ['7:3' => true];
                sac_assert(self::reconcileRowVisible('OperatingSystem', 7, 3, $pairs), 'OS + version present in the user\'s entities');
                sac_assert(!self::reconcileRowVisible('OperatingSystem', 7, 0, $pairs), 'same OS, another version: hidden');
                sac_assert(!self::reconcileRowVisible('Manufacturer', 7, 3, $pairs), 'publisher rows are group level');
            },
        ];
    }
}
