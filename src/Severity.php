<?php

namespace GlpiPlugin\Sac;

final class Severity
{
    public static function forEolDays(
        int $daysUntilEol,
        int $horizon = 365,
        int $highDays = 90,
        int $mediumDays = 180
    ): ?string {
        if ($daysUntilEol < 0) {
            return 'Critical';
        }
        if ($daysUntilEol <= $highDays) {
            return 'High';
        }
        if ($daysUntilEol <= $mediumDays) {
            return 'Medium';
        }
        if ($daysUntilEol <= $horizon) {
            return 'Low';
        }
        return null;
    }

    public static function forLicenseOverage(int $installs, int $seats, bool $expired): string
    {
        if ($expired) {
            return 'High';
        }
        if ($seats <= 0) {
            // Unlimited/unknown seat count should never reach here (LicenseScan
            // excludes number<=0 from 2b), but guard the division regardless.
            return 'High';
        }
        $ratio = $installs / $seats;
        if ($ratio > 1.5) {
            return 'High';
        }
        if ($ratio > 1.1) {
            return 'Medium';
        }
        return 'Low';
    }

    public static function forConfigScore(
        float $score,
        int $critical = 50,
        int $high = 70,
        int $medium = 85
    ): ?string {
        if ($score < $critical) {
            return 'Critical';
        }
        if ($score < $high) {
            return 'High';
        }
        if ($score < $medium) {
            return 'Medium';
        }
        if ($score < 100.0) {
            return 'Low';
        }
        return null;
    }

    public static function selfChecks(): array
    {
        return [
            'severity/eol-past' => static function (): void {
                sac_assert_eq(self::forEolDays(-1), 'Critical', 'past due');
                sac_assert_eq(self::forEolDays(0), 'High', 'today');
            },
            'severity/eol-bands' => static function (): void {
                sac_assert_eq(self::forEolDays(90), 'High', '90d boundary');
                sac_assert_eq(self::forEolDays(91), 'Medium', '91d');
                sac_assert_eq(self::forEolDays(180), 'Medium', '180d boundary');
                sac_assert_eq(self::forEolDays(181), 'Low', '181d');
                sac_assert_eq(self::forEolDays(365), 'Low', '365d boundary');
                sac_assert_eq(self::forEolDays(366), null, 'beyond horizon');
            },
            'severity/eol-custom-thresholds' => static function (): void {
                sac_assert_eq(self::forEolDays(200, 400, 30, 90), 'Low', 'custom med<200<=horizon');
                sac_assert_eq(self::forEolDays(30, 400, 30, 90), 'High', 'custom high boundary');
            },
            'severity/license-overage' => static function (): void {
                sac_assert_eq(self::forLicenseOverage(11, 10, false), 'Low', '110%');
                sac_assert_eq(self::forLicenseOverage(15, 10, false), 'Medium', '150%');
                sac_assert_eq(self::forLicenseOverage(16, 10, false), 'High', '160%');
                sac_assert_eq(self::forLicenseOverage(11, 10, true), 'High', 'expired beats ratio');
            },
            'severity/license-zero-seats' => static function (): void {
                sac_assert_eq(self::forLicenseOverage(5, 0, false), 'High', 'zero seats');
            },
            'severity/config-score-bands' => static function (): void {
                sac_assert_eq(self::forConfigScore(40.0), 'Critical', 'below critical');
                sac_assert_eq(self::forConfigScore(50.0), 'High', 'critical boundary is exclusive');
                sac_assert_eq(self::forConfigScore(70.0), 'Medium', 'high boundary is exclusive');
                sac_assert_eq(self::forConfigScore(85.0), 'Low', 'medium boundary is exclusive');
                sac_assert_eq(self::forConfigScore(99.9), 'Low', 'just under full compliance');
                sac_assert_eq(self::forConfigScore(100.0), null, 'fully compliant: no Finding');
            },
            'severity/config-score-custom-thresholds' => static function (): void {
                sac_assert_eq(self::forConfigScore(60.0, 30, 60, 80), 'Medium', 'custom high boundary');
            },
        ];
    }
}
