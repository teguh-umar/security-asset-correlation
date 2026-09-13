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

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            // WRITER-1 (spec §9.2) — with riskregister inactive, a cron tick must
            // still complete cleanly and close its `_run` row `ok` (every write
            // degrades to a counter bump inside FindingWriter). Only meaningful
            // when riskregister is NOT available on this VM.
            'db/writer-riskregister-inactive' => static function (): void {
                if (Config::get('eol_scan_enabled') === 0 || Config::get('eol_scan_enabled') === '0') {
                    return; // scan disabled on this VM — nothing to assert
                }
                if (\GlpiPlugin\Sac\FindingWriter::isAvailable()) {
                    return; // skip: riskregister active
                }
                global $DB;
                $floorRes = $DB->doQuery('SELECT MAX(`id`) AS `m` FROM `glpi_plugin_sac_run`');
                $floor    = 0;
                if ($floorRes) {
                    $floorRow = $floorRes->fetch_assoc();
                    $floor    = (int) (is_array($floorRow) ? ($floorRow['m'] ?? 0) : 0);
                }
                try {
                    $rc = self::cronEolScan(new \CronTask());
                    sac_assert($rc === 0 || $rc === 1, 'cronEolScan returned a valid code without throwing');

                    $row = $DB->request([
                        'FROM'  => 'glpi_plugin_sac_run',
                        'WHERE' => ['module' => 'eol', 'id' => ['>', $floor]],
                        'ORDER' => ['id DESC'],
                    ])->current();
                    sac_assert($row !== null, 'a _run row was opened for the tick');
                    sac_assert_eq((string) $row['status'], 'ok', 'the _run row closed ok');
                } finally {
                    $DB->delete('glpi_plugin_sac_run', ['module' => 'eol', 'id' => ['>', $floor]]);
                }
            },
        ];
    }

    public static function register(): void
    {
        CronTask::register(self::class, 'EolScan', WEEK_TIMESTAMP, ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL]);
        CronTask::register(self::class, 'EolCacheRefresh', WEEK_TIMESTAMP, ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL]);
        CronTask::register(self::class, 'LicenseScan', WEEK_TIMESTAMP, ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL]);
    }
}
