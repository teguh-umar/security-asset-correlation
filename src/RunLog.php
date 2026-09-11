<?php

namespace GlpiPlugin\Sac;

/**
 * Scan-run audit trail. One row per module scan in `glpi_plugin_sac_run`.
 *
 * Usage:
 *   $rl = RunLog::begin('eol');
 *   $rl->bump('scanned', 12);
 *   $rl->finish('ok');
 */
final class RunLog
{
    private const TABLE = 'glpi_plugin_sac_run';

    private int $id;

    /** @var array<string,int> */
    private array $counts = [
        'scanned'           => 0,
        'findings_upserted' => 0,
        'findings_cleared'  => 0,
        'reconcile_open'    => 0,
        'errors'            => 0,
        'skipped'           => 0,       // v1.1.0: a seed-CSV `_skip` pattern (e.g. Kubernetes)
        'cache_pending'     => 0,       // v1.1.0: mapped, but endoflife.date not cached yet — self-healing
    ];

    private function __construct(int $id)
    {
        $this->id = $id;
    }

    public function id(): int
    {
        return $this->id;
    }

    /** GLPI's current-time string, with a plain fallback for non-GLPI contexts. */
    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    /** Insert a `status=running` row and return a handle holding its id + counters. */
    public static function begin(string $module): RunLog
    {
        global $DB;
        $DB->insert(self::TABLE, [
            'module'     => $module,
            'date_start' => self::now(),
            'status'     => 'running',
        ]);
        return new self((int) $DB->insertId());
    }

    /** Increment one counter. Unknown counter names are ignored. */
    public function bump(string $counter, int $by = 1): void
    {
        if (array_key_exists($counter, $this->counts)) {
            $this->counts[$counter] += $by;
        }
    }

    /** Close the run: write date_end, status, message and the counters JSON. */
    public function finish(string $status = 'ok', string $message = ''): void
    {
        global $DB;
        $DB->update(self::TABLE, [
            'date_end'    => self::now(),
            'status'      => $status,
            'message'     => $message,
            'counts_json' => json_encode($this->counts),
        ], ['id' => $this->id]);
    }

    /** Flip orphaned `running` rows older than $olderThanHours to `stale`. */
    public static function sweepStale(int $olderThanHours = 6): void
    {
        global $DB;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$olderThanHours} hours"));
        $DB->update(self::TABLE, [
            'status'   => 'stale',
            'date_end' => self::now(),
        ], [
            'status'     => 'running',
            'date_start' => ['<', $cutoff],
        ]);
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/runlog-lifecycle' => static function (): void {
                global $DB;
                $rl = self::begin('selftest');
                $id = $rl->id();
                sac_assert($id > 0, 'begin returned an id');

                $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]])->current();
                sac_assert_eq($row['status'], 'running', 'row is running');

                $rl->bump('scanned', 3);
                $rl->bump('errors');
                $rl->finish('ok', 'done');

                $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]])->current();
                sac_assert_eq($row['status'], 'ok', 'row closed ok');
                $counts = json_decode($row['counts_json'], true);
                sac_assert_eq($counts['scanned'], 3, 'scanned counter persisted');
                sac_assert_eq($counts['errors'], 1, 'errors counter persisted');
                sac_assert($row['date_end'] !== null, 'date_end set');

                $DB->delete(self::TABLE, ['id' => $id]);
            },
        ];
    }
}
