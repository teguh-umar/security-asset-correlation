<?php

namespace GlpiPlugin\Sac;

use CommonDBTM;
use CronTask;

class Cron extends CommonDBTM
{
    public static function cronInfo($name): array
    {
        return match ($name) {
            'EolScan'         => ['description' => __('SAC — scan operating systems for EOL/EOS', 'sac')],
            'EolCacheRefresh' => ['description' => __('SAC — refresh endoflife.date cache', 'sac')],
            'LicenseScan'     => ['description' => __('SAC — scan software for license governance', 'sac')],
            default           => ['description' => $name],
        };
    }

    public static function cronEolScan(CronTask $task): int
    {
        if (!Config::get('eol_scan_enabled')) { return 0; }
        RunLog::sweepStale();
        $run = RunLog::begin('eol');
        try {
            Eol\EolScan::run($run);
            $run->finish('ok');
            return 1;
        } catch (\Throwable $e) {
            $run->finish('error', $e->getMessage());
            \Toolbox::logInFile('sac', 'EolScan: ' . $e->getMessage() . "\n");
            return 0;
        }
    }

    public static function cronEolCacheRefresh(CronTask $task): int
    {
        $run = RunLog::begin('eolcache');
        try { Eol\EolScan::refreshCache($run); $run->finish('ok'); return 1; }
        catch (\Throwable $e) { $run->finish('error', $e->getMessage()); return 0; }
    }

    public static function cronLicenseScan(CronTask $task): int
    {
        if (!Config::get('license_scan_enabled')) { return 0; }
        RunLog::sweepStale();
        $run = RunLog::begin('license');
        try { License\LicenseScan::run($run); $run->finish('ok'); return 1; }
        catch (\Throwable $e) { $run->finish('error', $e->getMessage()); \Toolbox::logInFile('sac', 'LicenseScan: ' . $e->getMessage() . "\n"); return 0; }
    }

    public static function register(): void
    {
        CronTask::register(self::class, 'EolScan', WEEK_TIMESTAMP, ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL]);
        CronTask::register(self::class, 'EolCacheRefresh', WEEK_TIMESTAMP, ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL]);
        CronTask::register(self::class, 'LicenseScan', WEEK_TIMESTAMP, ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL]);
    }
}
