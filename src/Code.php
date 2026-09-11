<?php

namespace GlpiPlugin\Sac;

final class Code
{
    public static function eolOs(int $osId, int $verId): string { return 'SAC-EOL-OS-' . $osId . '-' . $verId; }
    public static function eolHw(int $id): string        { return 'SAC-EOL-HW-' . $id; }
    public static function licMissing(int $id): string   { return 'SAC-LIC-MISSING-' . $id; }
    public static function licOver(int $id): string      { return 'SAC-LIC-OVER-' . $id; }

    public static function selfChecks(): array
    {
        return [
            'code/format' => static function (): void {
                sac_assert_eq(self::eolOs(42, 7), 'SAC-EOL-OS-42-7', 'eolOs');
                sac_assert_eq(self::licMissing(7), 'SAC-LIC-MISSING-7', 'licMissing');
            },
            'code/length' => static function (): void {
                // longest prefix + two max 10-digit ids must stay <= 50
                sac_assert(strlen(self::eolOs(2147483647, 2147483647)) <= 50, 'eolOs <=50');
                sac_assert(strlen(self::licMissing(2147483647)) <= 50, 'licMissing <=50');
                sac_assert(strlen(self::eolHw(2147483647)) <= 50, 'eolHw <=50');
            },
        ];
    }
}
