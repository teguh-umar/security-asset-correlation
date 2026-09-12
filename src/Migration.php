<?php

namespace GlpiPlugin\Sac;

final class Migration
{
    /** @var array<string,string> table => CREATE TABLE DDL */
    private static array $tables = [];

    public static function install(): void
    {
        global $DB;
        self::define();
        foreach (self::$tables as $name => $ddl) {
            if (!$DB->tableExists($name)) {
                $DB->doQuery($ddl);
            }
        }

        $migration = new \Migration(PLUGIN_SAC_VERSION);
        self::migrateEolmapVersionKey($migration, $DB);
        self::migrateReconcileSubId($migration, $DB);
        $migration->executeMigration();

        self::supersedeOldEolFindings($DB);
        self::wipeStaleEolReconcile($DB);
        self::purgeAutoEolmap($DB);

        Config::setDefaults();
        Cron::register();
        License\CommercialPublisher::seedFromCsv();
    }

    public static function uninstall(): void
    {
        global $DB;
        foreach ($DB->listTables('glpi\\_plugin\\_sac\\_%') as $row) {
            $DB->doQuery('DROP TABLE IF EXISTS `' . $row['TABLE_NAME'] . '`');
        }
        \Config::deleteConfigurationValues(Config::CONTEXT, array_keys(Config::DEFAULTS));
        \CronTask::unregister('sac');
    }

    private static function define(): void
    {
        $table_options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // v1.1.0: operatingsystemversions_id + the (os,ver) UNIQUE ship
        // directly in a fresh install's DDL. An upgrade from v1.0.x reaches
        // this shape via migrateEolmapVersionKey() below instead.
        self::$tables['glpi_plugin_sac_eolmap'] = "
            CREATE TABLE `glpi_plugin_sac_eolmap` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `operatingsystems_id` int unsigned NOT NULL DEFAULT 0,
              `operatingsystemversions_id` int unsigned NOT NULL DEFAULT 0,
              `eol_product` varchar(100) NOT NULL DEFAULT '',
              `eol_cycle` varchar(50) NOT NULL DEFAULT '',
              `confidence` varchar(20) NOT NULL DEFAULT 'auto',
              `users_id_confirmed` int unsigned DEFAULT NULL,
              `date_confirmed` timestamp NULL DEFAULT NULL,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `os_ver` (`operatingsystems_id`,`operatingsystemversions_id`)
            ) $table_options";

        self::$tables['glpi_plugin_sac_eolcache'] = "
            CREATE TABLE `glpi_plugin_sac_eolcache` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `eol_product` varchar(100) NOT NULL DEFAULT '',
              `eol_cycle` varchar(50) NOT NULL DEFAULT '',
              `eol_date` date DEFAULT NULL,
              `eos_date` date DEFAULT NULL,
              `is_lts` tinyint(1) NOT NULL DEFAULT 0,
              `raw_json` text,
              `date_fetched` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `product_cycle` (`eol_product`,`eol_cycle`)
            ) $table_options";

        self::$tables['glpi_plugin_sac_commercialpublishers'] = "
            CREATE TABLE `glpi_plugin_sac_commercialpublishers` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `manufacturers_id` int unsigned NOT NULL DEFAULT 0,
              `is_active` tinyint(1) NOT NULL DEFAULT 1,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `manufacturer` (`manufacturers_id`)
            ) $table_options";

        // v1.1.0: ref_subid + the widened UNIQUE ship directly in a fresh
        // install's DDL; an upgrade reaches this shape via
        // migrateReconcileSubId() below.
        self::$tables['glpi_plugin_sac_reconcile'] = "
            CREATE TABLE `glpi_plugin_sac_reconcile` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `signal_type` varchar(20) NOT NULL DEFAULT '',
              `ref_itemtype` varchar(100) NOT NULL DEFAULT '',
              `ref_id` int unsigned NOT NULL DEFAULT 0,
              `ref_subid` int unsigned NOT NULL DEFAULT 0,
              `reason` varchar(30) NOT NULL DEFAULT '',
              `detail` text,
              `date_firstseen` timestamp NULL DEFAULT NULL,
              `date_lastseen` timestamp NULL DEFAULT NULL,
              `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `signal_sub` (`signal_type`,`ref_itemtype`,`ref_id`,`ref_subid`,`reason`)
            ) $table_options";

        self::$tables['glpi_plugin_sac_run'] = "
            CREATE TABLE `glpi_plugin_sac_run` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `module` varchar(20) NOT NULL DEFAULT '',
              `date_start` timestamp NULL DEFAULT NULL,
              `date_end` timestamp NULL DEFAULT NULL,
              `status` varchar(20) NOT NULL DEFAULT 'running',
              `counts_json` text,
              `message` text,
              PRIMARY KEY (`id`),
              KEY `module_start` (`module`,`date_start`)
            ) $table_options";
    }

    /**
     * v1.0.x -> v1.1.0: add the version dimension to `_eolmap` and re-key its
     * UNIQUE. Idempotent — `fieldExists`/`indexExists` checks ARE the version
     * gate (spec §5.2), never a version-number comparison. No-op on a fresh
     * install (the fresh DDL above already has the target shape). Every
     * pre-migration row gets `operatingsystemversions_id = 0` (the column's
     * DDL default), so `(os_id, 0)` is still unique — the key swap cannot
     * collide.
     */
    private static function migrateEolmapVersionKey(\Migration $m, \DBmysql $DB, string $t = 'glpi_plugin_sac_eolmap'): void
    {
        if (!$DB->tableExists($t)) {
            return;
        }

        if (!$DB->fieldExists($t, 'operatingsystemversions_id')) {
            $m->addField($t, 'operatingsystemversions_id', 'integer', [
                'after' => 'operatingsystems_id',
                'value' => 0,
            ]);
        }
        $m->migrationOneTable($t); // flush the column before the key below depends on it

        if ($DB->indexExists($t, 'os')) {
            $m->dropKey($t, 'os');
        }
        if (!$DB->indexExists($t, 'os_ver')) {
            $m->addKey($t, ['operatingsystems_id', 'operatingsystemversions_id'], 'os_ver', 'UNIQUE');
        }
    }

    /**
     * v1.0.x -> v1.1.0: add `ref_subid` to `_reconcile` and widen its UNIQUE
     * so two versions of the same OS no longer collide. Same idempotent
     * pattern as above — and, like `os` -> `os_ver`, the widened key gets a
     * NEW name (`signal_sub`, not `signal`). Reusing the old name would make
     * the add-guard `!$DB->indexExists($t, 'signal')` read a name whose drop
     * was only just QUEUED (not yet flushed by `\Migration`), so it would
     * still see the old key as present and skip re-adding it — the table
     * would end up with no unique constraint on this column set at all.
     * Different names sidestep that ordering hazard entirely.
     */
    private static function migrateReconcileSubId(\Migration $m, \DBmysql $DB): void
    {
        $t = 'glpi_plugin_sac_reconcile';
        if (!$DB->tableExists($t)) {
            return;
        }

        if (!$DB->fieldExists($t, 'ref_subid')) {
            $m->addField($t, 'ref_subid', 'integer', [
                'after' => 'ref_id',
                'value' => 0,
            ]);
        }
        $m->migrationOneTable($t);

        if ($DB->indexExists($t, 'signal')) {
            $m->dropKey($t, 'signal');
        }
        if (!$DB->indexExists($t, 'signal_sub')) {
            $m->addKey($t, ['signal_type', 'ref_itemtype', 'ref_id', 'ref_subid', 'reason'], 'signal_sub', 'UNIQUE');
        }
    }

    /**
     * Close every pre-v1.1.0 EOL Finding (`code` without the version segment)
     * with a superseded note. Never deleted — a promoted riskregister Risk
     * holds a FK to the Finding and riskregister does not cascade-delete
     * (spec §5.3, confirmed with risk-register-dashboard-98).
     */
    private static function supersedeOldEolFindings(\DBmysql $DB): void
    {
        if (!\Plugin::isPluginActive('riskregister')
            || !class_exists(\GlpiPlugin\Riskregister\Finding::class)) {
            return;
        }

        $new = '/^SAC-EOL-OS-\d+-\d+$/'; // two ints = the new (os_id, ver_id) grain
        $f   = new \GlpiPlugin\Riskregister\Finding();
        foreach ($f->find([
            'assessment_ref' => 'SAC/eol',
            'code'           => ['LIKE', 'SAC-EOL-OS-%'],
        ]) as $row) {
            $code = (string) $row['code'];
            if (preg_match($new, $code) || $row['status'] === 'Closed') {
                continue;
            }
            $f->update([
                'id'      => (int) $row['id'],
                'status'  => 'Closed',
                'content' => trim((string) $row['content'])
                    . "\n\n[superseded " . date('Y-m-d')
                    . " — SAC EOL scan re-keyed to (operatingsystems_id, operatingsystemversions_id)]",
            ]);
        }
    }

    /**
     * `_reconcile` `eol` rows are keyed to the pre-v1.1.0 grain (OS only) —
     * SAC owns this table outright, so wipe and let the next EolScan
     * regenerate them at the (os,ver) grain (spec §5.4).
     */
    private static function wipeStaleEolReconcile(\DBmysql $DB): void
    {
        if (!$DB->tableExists('glpi_plugin_sac_reconcile')) {
            return;
        }
        $DB->delete('glpi_plugin_sac_reconcile', ['signal_type' => 'eol']);
    }

    /**
     * `_eolmap` `auto` rows were machine guesses at the pre-v1.1.0 grain
     * (whole OS) — purge them; the next EolScan reseeds `auto` rows at the
     * (os,ver) grain. `confirmed` rows are kept as `(os_id, 0)` fallbacks
     * (spec §5.4).
     */
    private static function purgeAutoEolmap(\DBmysql $DB): void
    {
        if (!$DB->tableExists('glpi_plugin_sac_eolmap')) {
            return;
        }
        $DB->delete('glpi_plugin_sac_eolmap', ['confidence' => 'auto']);
    }

    /** @return array<string,callable():void> VM-only — exercises the real \Migration + $DB. */
    public static function selfChecks(): array
    {
        return [
            'db/migration-eolmap-key' => static function (): void {
                global $DB;
                $t = 'glpi_plugin_sac_test_eolmap_migration';
                try {
                    $DB->doQuery("DROP TABLE IF EXISTS `$t`");
                    $DB->doQuery("
                        CREATE TABLE `$t` (
                          `id` int unsigned NOT NULL AUTO_INCREMENT,
                          `operatingsystems_id` int unsigned NOT NULL DEFAULT 0,
                          `eol_product` varchar(100) NOT NULL DEFAULT '',
                          `eol_cycle` varchar(50) NOT NULL DEFAULT '',
                          `confidence` varchar(20) NOT NULL DEFAULT 'auto',
                          `users_id_confirmed` int unsigned DEFAULT NULL,
                          `date_confirmed` timestamp NULL DEFAULT NULL,
                          `date_creation` timestamp NULL DEFAULT NULL,
                          `date_mod` timestamp NULL DEFAULT NULL,
                          PRIMARY KEY (`id`),
                          UNIQUE KEY `os` (`operatingsystems_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    sac_assert(!$DB->fieldExists($t, 'operatingsystemversions_id'), 'fixture starts without the version column');
                    sac_assert($DB->indexExists($t, 'os'), 'fixture starts with the old os key');

                    $migration = new \Migration('9.9.9');
                    self::migrateEolmapVersionKey($migration, $DB, $t);
                    $migration->executeMigration();

                    sac_assert($DB->fieldExists($t, 'operatingsystemversions_id'), 'operatingsystemversions_id added by the migration');
                    sac_assert($DB->indexExists($t, 'os_ver'), 'os_ver UNIQUE key added by the migration');
                    sac_assert(!$DB->indexExists($t, 'os'), 'old os key dropped by the migration');
                } finally {
                    $DB->doQuery("DROP TABLE IF EXISTS `$t`");
                }
            },
        ];
    }
}
