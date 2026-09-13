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
     * Fetch and cache every distinct product in $pairs whose `_eolcache` row
     * is missing or older than `Config::get('eol_cache_max_age_days')`.
     * Called between EolScan's two passes (spec §6/§10) so a fresh seed-CSV
     * match still has a dated Finding to emit the same run (spec §15 Q2).
     * One GET per product upserts every cycle it returns (not just the one
     * asked for — cheap, and pre-warms sibling versions). Network/shape
     * failures bump `errors` and leave the existing cache untouched.
     *
     * @param array<int,array{product:string,cycle:string}> $pairs
     */
    public static function ensureFresh(array $pairs, \GlpiPlugin\Sac\RunLog $run): void
    {
        $maxAge = (int) \GlpiPlugin\Sac\Config::get('eol_cache_max_age_days');

        $products = [];
        foreach ($pairs as $p) {
            $products[$p['product']] = true;
        }

        foreach (array_keys($products) as $product) {
            if ($product === '' || !self::isStale($product, $maxAge)) {
                continue;
            }

            $body = \GlpiPlugin\Sac\Http::get('https://endoflife.date/api/' . rawurlencode($product) . '.json');
            if ($body === null) {
                $run->bump('errors');
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                $run->bump('errors');
                continue;
            }
            foreach ($data as $entry) {
                if (!is_array($entry) || !isset($entry['cycle'])) {
                    continue;
                }
                $eol     = $entry['eol'] ?? null;
                $support = $entry['support'] ?? null;
                self::upsert($product, (string) $entry['cycle'], [
                    'eol_date' => is_string($eol) ? $eol : null,
                    'eos_date' => is_string($support) ? $support : null,
                    'is_lts'   => (bool) ($entry['lts'] ?? false),
                    'raw_json' => json_encode($entry),
                ]);
            }
        }
    }

    /**
     * Whether $product has no cache row at all, or none fresher than
     * $maxAgeDays.
     *
     * Built via raw `doQuery()` + `fetch_assoc()`, NOT the `$DB->request()`
     * array builder: passing an aggregate expression like
     * `'SELECT' => ['MAX(date_fetched) AS newest']` makes the builder quote
     * the WHOLE string as one literal column identifier (backtick-wrapping
     * `MAX(date_fetched)` verbatim) instead of treating it as a raw SQL
     * expression — `Unknown column 'MAX(date_fetched)' in 'field list'`, a
     * real crash caught on a live VM upgrade. `$DB->quote()` (VM-proven
     * since `LicenseScan`'s v1.0.0/v1.0.1 candidate-software query) keeps
     * `$product` — which ultimately traces back to admin-typed Reconcile
     * form input, not just the shipped seed CSV — safely escaped.
     */
    private static function isStale(string $product, int $maxAgeDays): bool
    {
        global $DB;
        $days   = (int) $maxAgeDays;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $res    = $DB->doQuery(
            'SELECT MAX(`date_fetched`) AS `newest` FROM `' . self::TABLE . '`'
            . ' WHERE `eol_product` = ' . $DB->quote($product)
        );
        $newest = null;
        if ($res) {
            $row    = $res->fetch_assoc();
            $newest = is_array($row) ? ($row['newest'] ?? null) : null;
        }
        return $newest === null || (string) $newest < $cutoff;
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
            'db/eolcache-ensurefresh' => static function (): void {
                global $DB;
                $product = '__sac_ensurefresh__';
                $runFloorRes = $DB->doQuery('SELECT MAX(`id`) AS `m` FROM `glpi_plugin_sac_run`');
                $runFloor = 0;
                if ($runFloorRes) {
                    $runFloorRow = $runFloorRes->fetch_assoc();
                    $runFloor = (int) (is_array($runFloorRow) ? ($runFloorRow['m'] ?? 0) : 0);
                }
                \GlpiPlugin\Sac\Http::$transport = static function (string $url, int $timeout): ?string {
                    return json_encode([
                        ['cycle' => '1', 'eol' => '2020-01-01', 'support' => '2019-01-01', 'lts' => false],
                        ['cycle' => '2', 'eol' => '2030-01-01', 'support' => '2029-01-01', 'lts' => true],
                    ]);
                };
                try {
                    $run = \GlpiPlugin\Sac\RunLog::begin('eol');
                    self::ensureFresh([['product' => $product, 'cycle' => '2']], $run);
                    $run->finish('ok');

                    $hit = self::get($product, '2');
                    sac_assert($hit !== null, 'ensureFresh cached the requested cycle');
                    sac_assert_eq($hit['eol_date'], '2030-01-01', 'requested cycle date cached');

                    $hit1 = self::get($product, '1');
                    sac_assert($hit1 !== null, 'ensureFresh also cached a sibling cycle from the same API response');
                } finally {
                    \GlpiPlugin\Sac\Http::$transport = null;
                    $DB->delete(self::TABLE, ['eol_product' => $product]);
                    $DB->delete('glpi_plugin_sac_run', ['module' => 'eol', 'id' => ['>', $runFloor]]);
                }
            },
        ];
    }
}
