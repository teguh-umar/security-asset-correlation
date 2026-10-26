<?php

namespace GlpiPlugin\Sac\License;

use GlpiPlugin\Sac\Code;
use GlpiPlugin\Sac\Config;
use GlpiPlugin\Sac\FindingWriter;
use GlpiPlugin\Sac\RunLog;

/**
 * One-shot retirement of the pre-1.9.0 per-publisher licence Findings
 * (`SAC-LIC-MISSING-<manufacturers_id>`), spec "Consequences of decisions 10
 * and 11".
 *
 * 1.9.0 keys Findings per software and stops consulting the publisher list, so
 * nothing visits the old codes any more; without this step they would sit Open
 * forever on production riskregister.
 *
 * The step runs from the install hook, and `plugin:install --force` is the
 * supported upgrade path, so it runs on EVERY upgrade. It is therefore guarded
 * by a config marker: an absent/0 marker means "not done yet". The marker is
 * written only after the loop, so a run that dies halfway is retried.
 *
 * Findings are closed, never deleted: a human may have promoted one to a Risk
 * or linked controls, and riskregister keeps that history. The close goes
 * through {@see FindingWriter::clearExcept()}, which appends the dated cleared
 * note and leaves `status` alone.
 */
final class RetirePublisherFindings
{
    /** Absent/0 = not done yet. Internal state, not a Setup-page setting. */
    public const MARKER = 'license_publisher_findings_retired';

    private const WHY = 'retired: licensing is now decided per software';

    /**
     * Close every `SAC-LIC-MISSING-*` Finding once.
     *
     * @return int number of Findings that gained a cleared note (0 when the
     *             marker is already set, riskregister is absent, or there is
     *             nothing to retire)
     */
    public static function run(RunLog $run): int
    {
        // riskregister absent/inactive: there is nothing to close and no reason
        // to burn the marker — a later install with it active must still run.
        if (!FindingWriter::isAvailable()) {
            return 0;
        }
        if (Config::marker(self::MARKER)) {
            return 0;
        }

        $f = new \GlpiPlugin\Riskregister\Finding();
        $rows = $f->find([
            'code'           => ['LIKE', 'SAC-LIC-MISSING-%'],
            'assessment_ref' => ['LIKE', 'SAC/%'],
        ]);

        // One call per distinct code: clearExcept() clears every entity carrying
        // that code, so iterating rows instead of codes would revisit rows.
        $codes = [];
        foreach ($rows as $row) {
            $codes[(string) $row['code']] = true;
        }

        $closed = 0;
        foreach (array_keys($codes) as $code) {
            $closed += FindingWriter::clearExcept($code, [], self::WHY, $run, null);
        }

        // Only now: a throw above leaves the marker unset and the next install
        // retries. Never delete, never touch `status`.
        Config::markDone(self::MARKER);

        return $closed;
    }

    /** @return array<string,callable():void> */
    public static function selfChecks(): array
    {
        return [
            // VM-only: needs riskregister's tables. Both checks save and restore
            // the real marker, so running them on the VM never retires anything
            // for real and never leaves the instance marked as retired.
            'db/retire-closes-old-codes' => static function (): void {
                if (!FindingWriter::isAvailable()) {
                    return;
                }
                global $DB;
                $marker = self::MARKER;
                $prev   = \Config::getConfigurationValues(Config::CONTEXT, [$marker])[$marker] ?? 0;
                $run    = RunLog::begin('license');
                $f      = new \GlpiPlugin\Riskregister\Finding();
                $codes  = [Code::licMissing(9101), Code::licMissing(9102)];
                $decoy  = Code::licMissing(9103);
                try {
                    \Config::setConfigurationValues(Config::CONTEXT, [$marker => 0]);
                    foreach ($codes as $code) {
                        $f->add([
                            'entities_id'    => 0,
                            'code'           => $code,
                            'name'           => '__sac_sc_retire__',
                            'severity'       => 'Low',
                            'content'        => 'old per-publisher finding',
                            'assessment_ref' => 'SAC/license',
                            'status'         => 'Open',
                        ]);
                    }
                    // A non-SAC Finding carrying a similar code must be left alone.
                    $f->add([
                        'entities_id'    => 0,
                        'code'           => $decoy,
                        'name'           => '__sac_sc_retire_decoy__',
                        'severity'       => 'Low',
                        'content'        => 'not ours',
                        'assessment_ref' => 'OTHER/import',
                        'status'         => 'Open',
                    ]);

                    sac_assert_eq(self::run($run), 2, 'two distinct old codes closed');

                    foreach ($codes as $code) {
                        $rows = $f->find(['code' => $code]);
                        sac_assert_eq(count($rows), 1, "$code still exists — closed, not deleted");
                        $row = $rows[array_key_first($rows)];
                        sac_assert(str_contains((string) $row['content'], 'Signal cleared on '), "$code gained the dated cleared note");
                        sac_assert_eq($row['status'], 'Open', "$code status untouched");
                    }

                    $decoyRows = $f->find(['code' => $decoy]);
                    sac_assert_eq(count($decoyRows), 1, 'the decoy exists');
                    $decoyRow = $decoyRows[array_key_first($decoyRows)];
                    sac_assert(!str_contains((string) $decoyRow['content'], 'Signal cleared on '), 'a non-SAC Finding with a similar code is not touched');

                    sac_assert(Config::marker($marker), 'marker written after the loop');
                    sac_assert_eq(self::run($run), 0, 'a marked install closes nothing');
                } finally {
                    foreach (array_merge($codes, [$decoy]) as $code) {
                        foreach (array_keys($f->find(['code' => $code])) as $fid) {
                            $f->delete(['id' => (int) $fid], true);
                        }
                    }
                    \Config::setConfigurationValues(Config::CONTEXT, [$marker => $prev]);
                    $DB->delete('glpi_plugin_sac_run', ['id' => $run->id()]);
                }
            },

            'db/retire-is-idempotent' => static function (): void {
                if (!FindingWriter::isAvailable()) {
                    return;
                }
                global $DB;
                $marker = self::MARKER;
                $prev   = \Config::getConfigurationValues(Config::CONTEXT, [$marker])[$marker] ?? 0;
                $run    = RunLog::begin('license');
                $f      = new \GlpiPlugin\Riskregister\Finding();
                $code   = Code::licMissing(9111);
                try {
                    \Config::setConfigurationValues(Config::CONTEXT, [$marker => 0]);
                    $f->add([
                        'entities_id'    => 0,
                        'code'           => $code,
                        'name'           => '__sac_sc_retire_idem__',
                        'severity'       => 'Low',
                        'content'        => 'old per-publisher finding',
                        'assessment_ref' => 'SAC/license',
                        'status'         => 'Open',
                    ]);

                    sac_assert_eq(self::run($run), 1, 'first run closes one');

                    // A human triages the (now cleared) Finding.
                    $rows = $f->find(['code' => $code]);
                    $fid  = (int) array_key_first($rows);
                    $f->update(['id' => $fid, 'status' => 'Closed']);
                    $before = $f->find(['code' => $code]);
                    $before = $before[array_key_first($before)];

                    sac_assert_eq(self::run($run), 0, 'second run returns 0');
                    $after = $f->find(['code' => $code]);
                    $after = $after[array_key_first($after)];

                    sac_assert_eq($after['status'], 'Closed', 'a Finding a human closed stays Closed');
                    sac_assert_eq((string) $after['content'], (string) $before['content'], 'no second note');
                    sac_assert_eq((string) $after['date_mod'], (string) $before['date_mod'], 'the second run wrote nothing');
                } finally {
                    foreach (array_keys($f->find(['code' => $code])) as $fid) {
                        $f->delete(['id' => (int) $fid], true);
                    }
                    \Config::setConfigurationValues(Config::CONTEXT, [$marker => $prev]);
                    $DB->delete('glpi_plugin_sac_run', ['id' => $run->id()]);
                }
            },
        ];
    }
}
