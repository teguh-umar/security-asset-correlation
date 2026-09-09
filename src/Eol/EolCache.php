<?php

namespace GlpiPlugin\Sac\Eol;

/**
 * Local cache of endoflife.date lookups, one row per product/cycle in
 * `glpi_plugin_sac_eolcache` (UNIQUE on eol_product + eol_cycle).
 */
final class EolCache
{
    private const TABLE = 'glpi_plugin_sac_eolcache';

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    /** @return array{eol_date:?string,eos_date:?string,is_lts:bool}|null */
    public static function get(string $product, string $cycle): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['eol_product' => $product, 'eol_cycle' => $cycle],
        ])->current();
        if (!$row) {
            return null;
        }
        return [
            'eol_date' => $row['eol_date'] !== null ? (string) $row['eol_date'] : null,
            'eos_date' => $row['eos_date'] !== null ? (string) $row['eos_date'] : null,
            'is_lts'   => (bool) $row['is_lts'],
        ];
    }

    /**
     * Replace the cached row for a product/cycle.
     * $row keys honoured: eol_date, eos_date, is_lts, raw_json.
     */
    public static function upsert(string $product, string $cycle, array $row): void
    {
        global $DB;
        $DB->updateOrInsert(self::TABLE, [
            'eol_product'  => $product,
            'eol_cycle'    => $cycle,
            'eol_date'     => $row['eol_date'] ?? null,
            'eos_date'     => $row['eos_date'] ?? null,
            'is_lts'       => !empty($row['is_lts']) ? 1 : 0,
            'raw_json'     => $row['raw_json'] ?? null,
            'date_fetched' => self::now(),
        ], [
            'eol_product' => $product,
            'eol_cycle'   => $cycle,
        ]);
    }

    /**
     * Product/cycle pairs mapped in `_eolmap` whose cache row is missing or
     * older than $maxAgeDays.
     *
     * @return array<int,array{product:string,cycle:string}>
     */
    public static function staleProducts(int $maxAgeDays): array
    {
        global $DB;
        $days = (int) $maxAgeDays; // cast — value is interpolated into SQL below
        // Built SQL string via $DB->doQuery(): the two-column LEFT JOIN ON plus the
        // DATE_SUB(NOW(), INTERVAL n DAY) predicate is awkward to express safely
        // through the DBmysqlIterator criteria array; $days is int-cast so this is
        // injection-safe.
        $sql = "SELECT DISTINCT `map`.`eol_product` AS `eol_product`, `map`.`eol_cycle` AS `eol_cycle`
                FROM `glpi_plugin_sac_eolmap` AS `map`
                LEFT JOIN `glpi_plugin_sac_eolcache` AS `cache`
                    ON `cache`.`eol_product` = `map`.`eol_product`
                   AND `cache`.`eol_cycle` = `map`.`eol_cycle`
                WHERE `map`.`eol_product` <> ''
                  AND (
                        `cache`.`date_fetched` IS NULL
                        OR `cache`.`date_fetched` < DATE_SUB(NOW(), INTERVAL {$days} DAY)
                      )";
        $res = $DB->doQuery($sql);
        $out = [];
        if ($res) {
            while ($data = $res->fetch_assoc()) {
                $out[] = [
                    'product' => (string) $data['eol_product'],
                    'cycle'   => (string) $data['eol_cycle'],
                ];
            }
        }
        return $out;
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/eolcache-roundtrip' => static function (): void {
                global $DB;
                $p = '__sac_selftest__';
                $c = '1.0';

                try {
                    sac_assert_eq(self::get($p, $c), null, 'cache miss before seed');

                    self::upsert($p, $c, ['eol_date' => '2030-01-01', 'is_lts' => true, 'raw_json' => '{}']);
                    $hit = self::get($p, $c);
                    sac_assert_eq($hit['eol_date'], '2030-01-01', 'eol_date cached');
                    sac_assert_eq($hit['is_lts'], true, 'is_lts cached');

                    self::upsert($p, $c, ['eol_date' => '2031-06-30', 'is_lts' => false]);
                    $hit = self::get($p, $c);
                    sac_assert_eq($hit['eol_date'], '2031-06-30', 'eol_date replaced in place');
                    $cpt = $DB->request(['COUNT' => 'x', 'FROM' => self::TABLE, 'WHERE' => ['eol_product' => $p]])
                        ->current()['x'];
                    sac_assert_eq((int) $cpt, 1, 'still one cache row');

                    // stale detection: seed a map row, force the cache row old.
                    $DB->insert('glpi_plugin_sac_eolmap', [
                        'operatingsystems_id' => 2147480001,
                        'eol_product'         => $p,
                        'eol_cycle'           => $c,
                        'confidence'          => 'auto',
                    ]);
                    $DB->update(self::TABLE, ['date_fetched' => '2000-01-01 00:00:00'], ['eol_product' => $p, 'eol_cycle' => $c]);
                    $stale = self::staleProducts(30);
                    $found = false;
                    foreach ($stale as $s) {
                        if ($s['product'] === $p && $s['cycle'] === $c) {
                            $found = true;
                        }
                    }
                    sac_assert($found, 'stale product surfaced');
                } finally {
                    $DB->delete(self::TABLE, ['eol_product' => $p]);
                    $DB->delete('glpi_plugin_sac_eolmap', ['operatingsystems_id' => 2147480001]);
                }
            },
        ];
    }
}
