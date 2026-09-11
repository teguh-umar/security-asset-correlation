<?php

namespace GlpiPlugin\Sac\Eol;

/**
 * Loads the shipped `endoflife.date` product-matching seed:
 * `install/data/eol_products.csv`. Rows are consulted in file order — author
 * the most specific `name_pattern` first (e.g. "red hat enterprise linux"
 * before the bare "oracle" fallback). Never written to; re-shipped verbatim
 * on every `plugin:install --force` (spec §4.4/§15 Q4 — no rules-editor UI,
 * no database copy; admins extend coverage per-exception through Reconcile
 * instead).
 */
final class EolProducts
{
    private const KNOWN_SOURCES = ['version', 'name'];
    private const KNOWN_RULES   = ['major', 'yy.mm', 'xy', 'winsrv', 'winclient', 'exact'];

    /** @var array<int,array{pattern:string,product:string,cycle_source:string,cycle_rule:string}>|null */
    private static ?array $rules = null;

    private static function csvPath(): string
    {
        return dirname(__DIR__, 2) . '/install/data/eol_products.csv';
    }

    /**
     * Parsed, cached seed rows in file order. A `product` of `_skip` marks a
     * non-OS name (e.g. an appliance) that must never produce a Finding or a
     * Reconcile row; such rows carry empty `cycle_source`/`cycle_rule`.
     *
     * @return array<int,array{pattern:string,product:string,cycle_source:string,cycle_rule:string}>
     */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        self::$rules = [];

        $path = self::csvPath();
        if (!is_file($path)) {
            return self::$rules;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return self::$rules;
        }

        fgetcsv($fh, 0, ',', '"', '\\'); // discard the header row
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (!isset($row[0]) || trim((string) $row[0]) === '') {
                continue; // blank line
            }
            self::$rules[] = [
                'pattern'      => strtolower(trim((string) $row[0])),
                'product'      => trim((string) ($row[1] ?? '')),
                'cycle_source' => trim((string) ($row[2] ?? '')),
                'cycle_rule'   => trim((string) ($row[3] ?? '')),
            ];
        }
        fclose($fh);

        return self::$rules;
    }

    /** Test hook — drop the cache so a check re-parses the file. */
    public static function resetCache(): void
    {
        self::$rules = null;
    }

    /** @return array<string,callable():void> pure — reads the shipped file, no DB. */
    public static function selfChecks(): array
    {
        return [
            'eol/products-csv-valid' => static function (): void {
                self::resetCache();
                $rules = self::rules();
                sac_assert(count($rules) > 0, 'the shipped CSV parses to at least one rule');

                foreach ($rules as $r) {
                    sac_assert($r['pattern'] !== '', 'every row has a non-empty name_pattern');
                    sac_assert($r['product'] !== '', 'every row has a product (or _skip)');
                    if ($r['product'] === '_skip') {
                        sac_assert(
                            $r['cycle_source'] === '' && $r['cycle_rule'] === '',
                            '_skip rows carry no cycle_source/cycle_rule'
                        );
                        continue;
                    }
                    sac_assert(
                        in_array($r['cycle_source'], self::KNOWN_SOURCES, true),
                        "cycle_source '{$r['cycle_source']}' for '{$r['pattern']}' is version|name"
                    );
                    sac_assert(
                        in_array($r['cycle_rule'], self::KNOWN_RULES, true),
                        "cycle_rule '{$r['cycle_rule']}' for '{$r['pattern']}' is a known rule"
                    );
                }
            },
        ];
    }
}
