<?php

namespace GlpiPlugin\Sac\License;

use GlpiPlugin\Sac\Reconcile;

/**
 * The commercial-publisher watch list for Module 2 (License Governance).
 *
 * One row per `glpi_manufacturers` id in `glpi_plugin_sac_commercialpublishers`
 * (UNIQUE on `manufacturers_id`). {@see LicenseScan} raises a "missing licence"
 * Finding (type 2a) only for software whose publisher is on this list.
 *
 * The list is seeded on install from `install/data/commercial_publishers.csv`
 * (one publisher name per line); {@see seedFromCsv()} find-or-creates the
 * matching `Manufacturer` and upserts the row. Ambiguous / unresolvable names
 * are logged to the Reconcile queue for manual DB correction — there is no
 * "re-seed from Setup" workflow. Each such row carries `ref_id = crc32(name)`
 * so distinct publisher names do not collide on the `_reconcile` UNIQUE key
 * `(signal_type, ref_itemtype, ref_id, reason)`.
 */
class CommercialPublisher extends \CommonDBTM
{
    public static $rightname = 'plugin_sac_config';

    public const TABLE = 'glpi_plugin_sac_commercialpublishers';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    /** Absolute path to the install-time publisher seed list. */
    private static function csvPath(): string
    {
        return dirname(__DIR__, 2) . '/install/data/commercial_publishers.csv';
    }

    /**
     * Seed / refresh the watch list from the CSV. Per name:
     *   - exactly one `glpi_manufacturers` match (case-insensitive) → use it;
     *   - no match → create the `Manufacturer` (`entities_id = 0`) and use the
     *     new id;
     *   - more than one match → `Reconcile::note(... 'ambiguous' ...)`, skip.
     * Then upsert an active `_commercialpublishers` row for the resolved id.
     */
    public static function seedFromCsv(): void
    {
        global $DB;

        $path = self::csvPath();
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $name = trim($line);
            if ($name === '') {
                continue;
            }

            // Case-insensitive EQUALITY (GLPI's default collation is CI). A plain
            // ['name' => ['LIKE', $name]] would treat %/_ in a publisher name as
            // wildcards — e.g. "AT&T" is fine but "Something_Co" would over-match.
            $matches = [];
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_manufacturers',
                'WHERE'  => ['name' => $name],
            ]) as $m) {
                $matches[] = (int) $m['id'];
            }

            // Distinct ref_id per name so ambiguous/unresolvable publishers do
            // not overwrite one another on the _reconcile UNIQUE key (I9).
            $refId = (int) crc32($name);

            if (count($matches) > 1) {
                Reconcile::note('license', 'Manufacturer', $refId, 0, 'ambiguous', $name);
                continue;
            }

            if (count($matches) === 1) {
                $mid = $matches[0];
            } else {
                $mid = (int) (new \Manufacturer())->add(['name' => $name, 'entities_id' => 0]);
                if ($mid === 0) {
                    Reconcile::note('license', 'Manufacturer', $refId, 0, 'no_mapping', $name);
                    continue;
                }
            }

            self::activate($mid);
        }
    }

    /** Upsert one active row, stamping `date_creation` only on first insert. */
    private static function activate(int $manufacturersId): void
    {
        global $DB;

        $now    = self::now();
        $exists = (int) ($DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::TABLE,
            'WHERE' => ['manufacturers_id' => $manufacturersId],
        ])->current()['cpt'] ?? 0);

        if ($exists > 0) {
            $DB->update(self::TABLE, ['is_active' => 1, 'date_mod' => $now], ['manufacturers_id' => $manufacturersId]);
        } else {
            $DB->insert(self::TABLE, [
                'manufacturers_id' => $manufacturersId,
                'is_active'        => 1,
                'date_creation'    => $now,
                'date_mod'         => $now,
            ]);
        }
    }

    /**
     * `glpi_manufacturers` ids currently on the active watch list.
     *
     * @return array<int,int>
     */
    public static function activeManufacturerIds(): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['manufacturers_id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['is_active' => 1],
        ]) as $row) {
            $ids[] = (int) $row['manufacturers_id'];
        }

        return $ids;
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            // db/ — seed an improbable id, assert it surfaces, tear it down.
            'db/commercialpublisher-seed' => static function (): void {
                global $DB;
                $mid = 2147481777;
                try {
                    $DB->insert(self::TABLE, [
                        'manufacturers_id' => $mid,
                        'is_active'        => 1,
                        'date_creation'    => date('Y-m-d H:i:s'),
                        'date_mod'         => date('Y-m-d H:i:s'),
                    ]);
                    sac_assert(
                        in_array($mid, self::activeManufacturerIds(), true),
                        'activeManufacturerIds() contains the seeded id'
                    );

                    $DB->update(self::TABLE, ['is_active' => 0], ['manufacturers_id' => $mid]);
                    sac_assert(
                        !in_array($mid, self::activeManufacturerIds(), true),
                        'an inactive row is excluded'
                    );
                } finally {
                    $DB->delete(self::TABLE, ['manufacturers_id' => $mid]);
                }
            },
        ];
    }
}
