<?php

namespace GlpiPlugin\Sac\Eol;

/**
 * Maps a GLPI OperatingSystem to an endoflife.date product/cycle pair.
 * One row per OS in `glpi_plugin_sac_eolmap` (UNIQUE on operatingsystems_id).
 */
final class EolMap
{
    private const TABLE = 'glpi_plugin_sac_eolmap';

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    /** @return array{eol_product:string,eol_cycle:string,confidence:string}|null */
    public static function forOs(int $osId): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['operatingsystems_id' => $osId],
        ])->current();
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
    public static function putAuto(int $osId, string $product, string $cycle): void
    {
        self::put($osId, $product, $cycle, 'auto', null);
    }

    /** Store a human-confirmed mapping, stamping the confirming user + time. */
    public static function putConfirmed(int $osId, string $product, string $cycle, int $userId): void
    {
        self::put($osId, $product, $cycle, 'confirmed', $userId);
    }

    private static function put(int $osId, string $product, string $cycle, string $confidence, ?int $userId): void
    {
        global $DB;
        $now    = self::now();
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
            'WHERE' => ['operatingsystems_id' => $osId],
        ])->current()['cpt'] ?? 0;

        if ($exists) {
            $DB->update(self::TABLE, $fields, ['operatingsystems_id' => $osId]);
        } else {
            $DB->insert(self::TABLE, $fields + [
                'operatingsystems_id' => $osId,
                'date_creation'       => $now,
            ]);
        }
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/eolmap-upsert' => static function (): void {
                global $DB;
                $osId = 2147480000; // improbable OS id, safe to seed/delete

                self::putAuto($osId, 'ubuntu', '22.04');
                $m = self::forOs($osId);
                sac_assert_eq($m['eol_product'], 'ubuntu', 'auto product stored');
                sac_assert_eq($m['confidence'], 'auto', 'confidence auto');

                self::putConfirmed($osId, 'ubuntu', '20.04', 7);
                $m = self::forOs($osId);
                sac_assert_eq($m['eol_cycle'], '20.04', 'cycle updated in place');
                sac_assert_eq($m['confidence'], 'confirmed', 'confidence confirmed');

                $cpt = $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => ['operatingsystems_id' => $osId]])
                    ->current()['c'];
                sac_assert_eq((int) $cpt, 1, 'still exactly one row');

                $DB->delete(self::TABLE, ['operatingsystems_id' => $osId]);
            },
        ];
    }
}
