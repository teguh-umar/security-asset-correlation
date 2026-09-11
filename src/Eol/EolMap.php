<?php

namespace GlpiPlugin\Sac\Eol;

/**
 * Maps a GLPI (OperatingSystem, OperatingSystemVersion) pair to an
 * endoflife.date product/cycle. One row per pair in `glpi_plugin_sac_eolmap`
 * (UNIQUE on operatingsystems_id + operatingsystemversions_id).
 *
 * `operatingsystemversions_id = 0` is a legal key: the "version unknown /
 * not inventoried" fallback for that OS (spec
 * 2026-09-10-eol-multidim-design.md §4.1).
 */
final class EolMap
{
    private const TABLE = 'glpi_plugin_sac_eolmap';

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    /**
     * @return array{eol_product:string,eol_cycle:string,confidence:string}|null
     *         Tries the exact (osId, verId) pair first, then falls back to the
     *         (osId, 0) "version unknown" row.
     */
    public static function forOsVersion(int $osId, int $verId): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['operatingsystems_id' => $osId, 'operatingsystemversions_id' => $verId],
        ])->current();

        if (!$row && $verId !== 0) {
            $row = $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['operatingsystems_id' => $osId, 'operatingsystemversions_id' => 0],
            ])->current();
        }

        if (!$row) {
            return null;
        }
        return [
            'eol_product' => (string) $row['eol_product'],
            'eol_cycle'   => (string) $row['eol_cycle'],
            'confidence'  => (string) $row['confidence'],
        ];
    }

    /** Store/refresh an auto-detected mapping (confidence = 'auto'). */
    public static function putAuto(int $osId, int $verId, string $product, string $cycle): void
    {
        self::put($osId, $verId, $product, $cycle, 'auto', null);
    }

    /** Store a human-confirmed mapping, stamping the confirming user + time. */
    public static function putConfirmed(int $osId, int $verId, string $product, string $cycle, int $userId): void
    {
        self::put($osId, $verId, $product, $cycle, 'confirmed', $userId);
    }

    private static function put(int $osId, int $verId, string $product, string $cycle, string $confidence, ?int $userId): void
    {
        global $DB;
        $now    = self::now();
        $key    = ['operatingsystems_id' => $osId, 'operatingsystemversions_id' => $verId];
        $fields = [
            'eol_product' => $product,
            'eol_cycle'   => $cycle,
            'confidence'  => $confidence,
            'date_mod'    => $now,
        ];
        if ($confidence === 'confirmed') {
            $fields['users_id_confirmed'] = $userId;
            $fields['date_confirmed']     = $now;
        }

        $exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::TABLE,
            'WHERE' => $key,
        ])->current()['cpt'] ?? 0;

        if ($exists) {
            $DB->update(self::TABLE, $fields, $key);
        } else {
            $DB->insert(self::TABLE, $fields + $key + ['date_creation' => $now]);
        }
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/eolmap-upsert' => static function (): void {
                global $DB;
                $osId  = 2147480000; // improbable OS id, safe to seed/delete
                $verId = 2147480010;

                self::putAuto($osId, $verId, 'ubuntu', '22.04');
                $m = self::forOsVersion($osId, $verId);
                sac_assert_eq($m['eol_product'], 'ubuntu', 'auto product stored');
                sac_assert_eq($m['confidence'], 'auto', 'confidence auto');

                self::putConfirmed($osId, $verId, 'ubuntu', '20.04', 7);
                $m = self::forOsVersion($osId, $verId);
                sac_assert_eq($m['eol_cycle'], '20.04', 'cycle updated in place');
                sac_assert_eq($m['confidence'], 'confirmed', 'confidence confirmed');

                $cpt = $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => ['operatingsystems_id' => $osId]])
                    ->current()['c'];
                sac_assert_eq((int) $cpt, 1, 'still exactly one row for this (os,ver) pair');

                $DB->delete(self::TABLE, ['operatingsystems_id' => $osId]);
            },

            'db/eolmap-version-distinct' => static function (): void {
                global $DB;
                $osId = 2147480001; // one OS, two versions
                try {
                    self::putAuto($osId, 100, 'rhel', '7');
                    self::putAuto($osId, 200, 'rhel', '9');

                    sac_assert_eq(self::forOsVersion($osId, 100)['eol_cycle'], '7', 'version 100 keeps its own cycle');
                    sac_assert_eq(self::forOsVersion($osId, 200)['eol_cycle'], '9', 'version 200 keeps its own cycle');

                    $cpt = $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => ['operatingsystems_id' => $osId]])
                        ->current()['c'];
                    sac_assert_eq((int) $cpt, 2, 'two distinct rows for the same OS');
                } finally {
                    $DB->delete(self::TABLE, ['operatingsystems_id' => $osId]);
                }
            },

            'db/eolmap-zero-version-fallback' => static function (): void {
                global $DB;
                $osId = 2147480002;
                try {
                    self::putConfirmed($osId, 0, 'debian', '11', 1); // "version unknown" fallback
                    $m = self::forOsVersion($osId, 999); // a specific, unmapped version
                    sac_assert($m !== null, 'an unmapped version falls back to the (os,0) row');
                    sac_assert_eq($m['eol_product'], 'debian', 'fallback product returned');
                } finally {
                    $DB->delete(self::TABLE, ['operatingsystems_id' => $osId]);
                }
            },
        ];
    }
}
