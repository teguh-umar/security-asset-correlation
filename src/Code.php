<?php

namespace GlpiPlugin\Sac;

final class Code
{
    public static function eolOs(int $id): string       { return 'SAC-EOL-OS-' . $id; }
    public static function eolHw(int $id): string        { return 'SAC-EOL-HW-' . $id; }
    public static function licMissing(int $id): string   { return 'SAC-LIC-MISSING-' . $id; }
    public static function licOver(int $id): string      { return 'SAC-LIC-OVER-' . $id; }

    public static function selfChecks(): array
    {
        return [
            'code/format' => static function (): void {
                sac_assert_eq(self::eolOs(42), 'SAC-EOL-OS-42', 'eolOs');
                sac_assert_eq(self::licMissing(7), 'SAC-LIC-MISSING-7', 'licMissing');
            },
            'code/length' => static function (): void {
                // longest prefix + max 10-digit id must stay <= 50
                sac_assert(strlen(self::licMissing(2147483647)) <= 50, 'licMissing <=50');
                sac_assert(strlen(self::eolHw(2147483647)) <= 50, 'eolHw <=50');
            },
        ];
    }
}
