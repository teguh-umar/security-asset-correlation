<?php

namespace GlpiPlugin\Sac;

/**
 * Reconciliation signals: things a scan expected to find but didn't (missing
 * asset, unmapped OS, dropped install ...). One row per distinct problem in
 * `glpi_plugin_sac_reconcile`, UNIQUE on (signal_type, ref_itemtype, ref_id, reason).
 *
 * Task 6 ships only the write path used by Modules 1 & 2 (note / autoResolveMissing).
 * listForDisplay(), resolve(), resolveEolMapping() and the front page are Task 11.
 */
class Reconcile extends \CommonDBTM
{
    public static $rightname = 'plugin_sac_finding';

    public const TABLE = 'glpi_plugin_sac_reconcile';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    /**
     * Record (or refresh) a reconciliation signal. Upserts on the UNIQUE key
     * (signal_type, ref_itemtype, ref_id, reason): bumps date_lastseen + detail
     * on every call, and on the very first insert also stamps date_firstseen and
     * sets is_resolved = 0.
     */
    public static function note(string $signal, string $itemtype, int $id, int $subId, string $reason, string $detail): void
    {
        global $DB;
        $now = self::now();
        $key = [
            'signal_type'  => $signal,
            'ref_itemtype' => $itemtype,
            'ref_id'       => $id,
            'ref_subid'    => $subId,
            'reason'       => $reason,
        ];
        $exists = (int) ($DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::TABLE,
            'WHERE' => $key,
        ])->current()['cpt'] ?? 0);

        $fields = [
            'detail'        => $detail,
            'date_lastseen' => $now,
        ];
        if ($exists === 0) {
            $fields['date_firstseen'] = $now;
            $fields['is_resolved']    = 0;
        }

        $DB->updateOrInsert(self::TABLE, $fields, $key);
    }

    /**
     * Close out signals of $signal that a fresh scan no longer sees: any row of
     * that signal_type with is_resolved = 0 whose composite key is absent from
     * $seenKeys gets is_resolved = 1.
     *
     * @param string        $signal   the signal_type being reconciled
     * @param array<string> $seenKeys composite keys seen this run, each shaped
     *                                "<ref_itemtype>:<ref_id>:<reason>"
     */
    public static function autoResolveMissing(string $signal, array $seenKeys): void
    {
        global $DB;
        $seen = array_fill_keys($seenKeys, true);

        $stale = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'ref_itemtype', 'ref_id', 'ref_subid', 'reason'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['signal_type' => $signal, 'is_resolved' => 0],
        ]) as $row) {
            $composite = $row['ref_itemtype'] . ':' . $row['ref_id'] . ':' . $row['ref_subid'] . ':' . $row['reason'];
            if (!isset($seen[$composite])) {
                $stale[] = (int) $row['id'];
            }
        }

        if ($stale !== []) {
            $DB->update(self::TABLE, ['is_resolved' => 1], ['id' => $stale]);
        }
    }

    /**
     * Open (or, with $includeResolved, all) reconcile rows, newest activity
     * first, each enriched with a human `label`: the referenced item's display
     * name (plus its version name, for an OS row with `ref_subid > 0`) when
     * `ref_id > 0`, otherwise the free-text `detail`.
     *
     * @return array<int,array{
     *     id:int, signal_type:string, ref_itemtype:string, ref_id:int,
     *     ref_subid:int, reason:string, detail:string, label:string,
     *     is_resolved:bool, eol_product:string, eol_cycle:string,
     *     date_firstseen:?string, date_lastseen:?string
     * }>
     */
    public static function listForDisplay(bool $includeResolved = false): array
    {
        global $DB;

        $where = $includeResolved ? [] : ['is_resolved' => 0];

        // Current (OS, version) -> endoflife.date mappings, keyed "osId:verId",
        // so an `eol` row shows what it was (or would be) mapped to.
        $maps = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_sac_eolmap']) as $m) {
            $key = ((int) $m['operatingsystems_id']) . ':' . ((int) $m['operatingsystemversions_id']);
            $maps[$key] = [
                (string) ($m['eol_product'] ?? ''),
                (string) ($m['eol_cycle'] ?? ''),
            ];
        }

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => $where,
            'ORDER' => ['is_resolved ASC', 'date_lastseen DESC', 'id DESC'],
        ]) as $row) {
            $refId    = (int) $row['ref_id'];
            $subId    = (int) ($row['ref_subid'] ?? 0);
            $itemtype = (string) $row['ref_itemtype'];
            $detail   = (string) ($row['detail'] ?? '');
            $label    = $detail;

            if ($refId > 0 && $itemtype !== '') {
                $named = self::refLabel($itemtype, $refId);
                if ($subId > 0 && $itemtype === 'OperatingSystem') {
                    $verName = self::refLabel('OperatingSystemVersion', $subId);
                    if ($verName !== '') {
                        $named = trim($named . ' ' . $verName);
                    }
                }
                if ($named !== '') {
                    $label = $named;
                }
            }

            $mapKey = $refId . ':' . $subId;
            [$prod, $cyc] = ($itemtype === 'OperatingSystem' && isset($maps[$mapKey]))
                ? $maps[$mapKey]
                : ['', ''];

            $out[] = [
                'id'             => (int) $row['id'],
                'signal_type'    => (string) $row['signal_type'],
                'ref_itemtype'   => $itemtype,
                'ref_id'         => $refId,
                'ref_subid'      => $subId,
                'reason'         => (string) $row['reason'],
                'detail'         => $detail,
                'label'          => $label,
                'is_resolved'    => (bool) ($row['is_resolved'] ?? 0),
                'eol_product'    => $prod,
                'eol_cycle'      => $cyc,
                'date_firstseen' => $row['date_firstseen'],
                'date_lastseen'  => $row['date_lastseen'],
            ];
        }

        return $out;
    }

    /**
     * Display name for a referenced GLPI row. Derives the table as
     * `glpi_<lower(itemtype)>s` for Dropdown::getDropdownName(); falls back to
     * getItemForItemtype()+getFromDB(), then '' when nothing resolves.
     */
    private static function refLabel(string $itemtype, int $refId): string
    {
        if (class_exists(\Dropdown::class)) {
            $name = (string) \Dropdown::getDropdownName('glpi_' . strtolower($itemtype) . 's', $refId);
            $name = trim(str_replace('&nbsp;', '', $name));
            if ($name !== '' && $name !== '-') {
                return $name;
            }
        }

        if (function_exists('getItemForItemtype')) {
            $obj = \getItemForItemtype($itemtype);
            if ($obj instanceof \CommonDBTM && $obj->getFromDB($refId)) {
                return (string) ($obj->fields['name'] ?? '');
            }
        }

        return '';
    }

    /** Mark one reconcile row resolved (idempotent). */
    public static function resolve(int $id): void
    {
        global $DB;
        $DB->update(self::TABLE, ['is_resolved' => 1], ['id' => (int) $id]);
    }

    /**
     * Send a resolved row back to the open queue so an admin can re-map / re-act
     * on it (e.g. a wrong EOL mapping). The existing `_eolmap` row, if any, is
     * left in place — the inline form is pre-filled with it and re-submitting
     * `map` overwrites it.
     */
    public static function reopen(int $id): void
    {
        global $DB;
        $DB->update(self::TABLE, ['is_resolved' => 0], ['id' => (int) $id]);
    }

    /**
     * Resolve an EOL "no_mapping" signal: write a human-confirmed
     * (OperatingSystem, OperatingSystemVersion) -> endoflife.date mapping for
     * the row's (ref_id, ref_subid), then resolve. The version id is read
     * from the stored row only — never from client input — so a tampered
     * request cannot mis-map a different pair.
     */
    public static function resolveEolMapping(int $id, string $product, string $cycle): void
    {
        global $DB;

        $row   = $DB->request([
            'SELECT' => ['ref_id', 'ref_subid'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['id' => (int) $id],
        ])->current();
        $osId  = (int) (is_array($row) ? ($row['ref_id'] ?? 0) : 0);
        $verId = (int) (is_array($row) ? ($row['ref_subid'] ?? 0) : 0);

        // Stale / already-deleted reconcile row -> don't write a junk
        // operatingsystems_id=0 mapping.
        if ($osId === 0) {
            return;
        }

        \GlpiPlugin\Sac\Eol\EolMap::putConfirmed(
            $osId,
            $verId,
            $product,
            $cycle,
            (int) ($_SESSION['glpiID'] ?? 0)
        );
        self::resolve((int) $id);
    }

    /** @return array<string,callable():void> VM-only checks — they touch $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/reconcile-upsert' => static function (): void {
                global $DB;
                $sig = '__sac_selftest__';
                $key = [
                    'signal_type'  => $sig,
                    'ref_itemtype' => 'Computer',
                    'ref_id'       => 2147480999,
                    'ref_subid'    => 0,
                    'reason'       => 'selftest',
                ];

                self::note($sig, 'Computer', 2147480999, 0, 'selftest', 'first');
                $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current();
                sac_assert($row !== null, 'row inserted by note()');
                sac_assert_eq((int) $row['is_resolved'], 0, 'is_resolved 0 on first insert');
                sac_assert($row['date_firstseen'] !== null, 'date_firstseen stamped');

                // Backdate so the advance is observable regardless of clock granularity.
                $DB->update(self::TABLE, ['date_lastseen' => '2000-01-01 00:00:00'], $key);
                self::note($sig, 'Computer', 2147480999, 0, 'selftest', 'second');

                $cpt = (int) $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => $key])->current()['c'];
                sac_assert_eq($cpt, 1, 'still exactly one row after re-note');

                $row2 = $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current();
                sac_assert($row2['date_lastseen'] > '2000-01-01 00:00:00', 'date_lastseen advanced');
                sac_assert_eq($row2['detail'], 'second', 'detail refreshed on re-note');

                $DB->delete(self::TABLE, $key);
            },

            'db/reconcile-resolve' => static function (): void {
                global $DB;
                $sig = '__sac_selftest_resolve__';
                $key = [
                    'signal_type'  => $sig,
                    'ref_itemtype' => 'Computer',
                    'ref_id'       => 2147480998,
                    'ref_subid'    => 0,
                    'reason'       => 'selftest',
                ];

                try {
                    self::note($sig, 'Computer', 2147480998, 0, 'selftest', 'resolve-me');

                    $rid = (int) $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current()['id'];
                    sac_assert($rid > 0, 'note() row present');

                    $openIds = array_column(self::listForDisplay(), 'id');
                    sac_assert(in_array($rid, $openIds, true), 'unresolved row listed by listForDisplay()');

                    self::resolve($rid);

                    $openIds = array_column(self::listForDisplay(), 'id');
                    sac_assert(!in_array($rid, $openIds, true), 'resolved row dropped from listForDisplay()');

                    $allIds = array_column(self::listForDisplay(true), 'id');
                    sac_assert(in_array($rid, $allIds, true), 'resolved row still in listForDisplay(true)');

                    $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => $key])->current();
                    sac_assert_eq((int) $row['is_resolved'], 1, 'is_resolved flipped to 1');
                } finally {
                    $DB->delete(self::TABLE, $key);
                }
            },

            'db/reconcile-subid-distinct' => static function (): void {
                global $DB;
                $osId = 2147480997;
                try {
                    self::note('eol', 'OperatingSystem', $osId, 100, 'no_mapping', 'v100');
                    self::note('eol', 'OperatingSystem', $osId, 200, 'no_mapping', 'v200');

                    $cpt = $DB->request([
                        'COUNT' => 'c', 'FROM' => self::TABLE,
                        'WHERE' => ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId],
                    ])->current()['c'];
                    sac_assert_eq((int) $cpt, 2, 'two distinct rows for the same OS, different ref_subid');

                    self::autoResolveMissing('eol', ['OperatingSystem:' . $osId . ':100:no_mapping']);
                    $resolved = [];
                    foreach ($DB->request([
                        'FROM'  => self::TABLE,
                        'WHERE' => ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId],
                    ]) as $r) {
                        $resolved[(int) $r['ref_subid']] = (bool) $r['is_resolved'];
                    }
                    sac_assert_eq($resolved[100], false, 'the still-seen subid stays open');
                    sac_assert_eq($resolved[200], true, 'the no-longer-seen subid auto-resolves');
                } finally {
                    $DB->delete(self::TABLE, ['ref_itemtype' => 'OperatingSystem', 'ref_id' => $osId]);
                }
            },
        ];
    }
}
