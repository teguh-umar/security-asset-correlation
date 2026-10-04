<?php

namespace GlpiPlugin\Sac;

final class Text
{
    /** Width of riskregister's `scope_label` / `raw_*_text` columns. */
    public const VARCHAR = 255;

    /**
     * PURE. Clip to at most $max characters without splitting a multi-byte
     * character. A byte-wise `substr()` can cut "×" or "—" in half at the
     * boundary, and MySQL strict mode rejects the invalid UTF-8 that leaves.
     * No mbstring: the offline selfcheck's PHP does not have it.
     */
    public static function clip(string $s, int $max = self::VARCHAR): string
    {
        if (preg_match('/^.{0,' . $max . '}/us', $s, $m) === 1) {
            return $m[0];
        }
        // Not valid UTF-8 to begin with — a byte clip is the best left.
        return substr($s, 0, $max);
    }

    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        return [
            'text/clip-keeps-short' => static function (): void {
                sac_assert_eq(self::clip('RHEL 9 ×3'), 'RHEL 9 ×3', 'short string untouched');
                sac_assert_eq(self::clip(''), '', 'empty string');
            },
            'text/clip-counts-characters' => static function (): void {
                $long = str_repeat('—', 300);
                $out  = self::clip($long);
                sac_assert_eq(strlen($out), 255 * 3, '255 characters, not 255 bytes');
                sac_assert(preg_match('//u', $out) === 1, 'still valid UTF-8');
            },
            'text/clip-never-splits-a-character' => static function (): void {
                $out = self::clip(str_repeat('a', 254) . '×b');
                sac_assert_eq($out, str_repeat('a', 254) . '×', 'multi-byte character at the boundary kept whole');
            },
            'text/clip-invalid-utf8-falls-back' => static function (): void {
                sac_assert_eq(strlen(self::clip(str_repeat("\xff", 300))), 255, 'byte clip');
            },
        ];
    }
}
