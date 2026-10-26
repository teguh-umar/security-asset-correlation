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
        \Config::deleteConfigurationValues(Config::CONTEXT, Config::REMOVED);
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

        self::$tables['glpi_plugin_sac_configscan'] = "
            CREATE TABLE `glpi_plugin_sac_configscan` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `computers_id` int unsigned NOT NULL DEFAULT 0,
              `benchmark_id` varchar(255) NOT NULL DEFAULT '',
              `score` decimal(5,2) NOT NULL DEFAULT 0.00,
              `pass_count` int unsigned NOT NULL DEFAULT 0,
              `fail_count` int unsigned NOT NULL DEFAULT 0,
              `other_count` int unsigned NOT NULL DEFAULT 0,
              `date_scan` timestamp NULL DEFAULT NULL,
              `date_collected` timestamp NULL DEFAULT NULL,
              `raw_line` text,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `computer` (`computers_id`)
            ) $table_options";

        // v1.7.1: opt-in SSH pull targets, replacing the v1.7.0 design that
        // assumed GLPI Inventory's Collect feature could capture arbitrary
        // command output. VM verification showed Collect only supports
        // file-metadata/registry/WMI collection (none fit a Linux cron's
        // text output), so Module 3 Tier 1 now pulls the oscap summary line
        // over a restricted, forced-command SSH key instead — one row per
        // admin-registered Computer, never auto-discovered from inventory
        // network data.
        self::$tables['glpi_plugin_sac_sshtargets'] = "
            CREATE TABLE `glpi_plugin_sac_sshtargets` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `computers_id` int unsigned NOT NULL DEFAULT 0,
              `host` varchar(255) NOT NULL DEFAULT '',
              `port` int unsigned NOT NULL DEFAULT 22,
              `ssh_user` varchar(100) NOT NULL DEFAULT 'pgnusr',
              `is_active` tinyint(1) NOT NULL DEFAULT 1,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `computer` (`computers_id`)
            ) $table_options";

        // v1.8.0: Tier 2 — one row per failing SCAP rule per host.
        // `(computers_id, rule_id)` is a safe UNIQUE because rule_id is a
        // SCAP id and never empty — unlike the polymorphic
        // `(x_id, itemtype, items_id)` shape riskregister hit (hub §7
        // 2026-10-15), where every untargeted row shares ('' , 0) and the
        // index silently permits only ONE of them.
        self::$tables['glpi_plugin_sac_configrules'] = "
            CREATE TABLE `glpi_plugin_sac_configrules` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `computers_id` int unsigned NOT NULL DEFAULT 0,
              `rule_id` varchar(255) NOT NULL DEFAULT '',
              `result` varchar(20) NOT NULL DEFAULT '',
              `severity` varchar(20) NOT NULL DEFAULT 'unknown',
              `framework_refs` varchar(255) NOT NULL DEFAULT '',
              `title` text,
              `state` varchar(20) NOT NULL DEFAULT 'open',
              `date_first_seen` timestamp NULL DEFAULT NULL,
              `date_last_seen` timestamp NULL DEFAULT NULL,
              `date_resolved` timestamp NULL DEFAULT NULL,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `computer_rule` (`computers_id`,`rule_id`),
              KEY `computer_state` (`computers_id`,`state`)
            ) $table_options";

        // v1.9.0: Module 2 licence classification — one row per per-entity rule
        // that decides whether a product is `free` or `needs_license`, replacing
        // the publisher-based decision. The UNIQUE key is `(entities_id, match_on,
        // pattern)` rather than `pattern` alone because the same pattern may
        // legitimately exist in two entities (each entity classifies on its own),
        // and a name-rule and a version-rule may share a pattern string — the
        // `match_on` column is what keeps those two apart.
        self::$tables['glpi_plugin_sac_licenserules'] = "
            CREATE TABLE `glpi_plugin_sac_licenserules` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `entities_id` int unsigned NOT NULL DEFAULT 0,
              `is_recursive` tinyint(1) NOT NULL DEFAULT 0,
              `pattern` varchar(255) NOT NULL DEFAULT '',
              `match_on` varchar(10) NOT NULL DEFAULT 'name',
              `classification` varchar(20) NOT NULL DEFAULT 'needs_license',
              `reason` text,
              `date_creation` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `entity_match` (`entities_id`,`match_on`,`pattern`),
              KEY `entity_classification` (`entities_id`,`classification`)
            ) $table_options";

        // Review queue: software no rule decides. A row means SAC does not know
        // whether it needs a licence and deliberately asserts nothing. One row per
        // (software, entity); `decided` rows are never reopened by a scan.
        self::$tables['glpi_plugin_sac_licensereview'] = "
            CREATE TABLE `glpi_plugin_sac_licensereview` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `softwares_id` int unsigned NOT NULL DEFAULT 0,
              `entities_id` int unsigned NOT NULL DEFAULT 0,
              `state` varchar(20) NOT NULL DEFAULT 'new',
              `note` text,
              `date_first_seen` timestamp NULL DEFAULT NULL,
              `date_mod` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `software_entity` (`softwares_id`,`entities_id`),
              KEY `entity_state` (`entities_id`,`state`)
            ) $table_options";
    }

    /**
     * Whether a KEY/INDEX named $keyName exists on $table. GLPI's `DB` /
     * `DBmysql` class has **no** `indexExists()` method — an earlier version
     * of this file called `$DB->indexExists(...)` and crashed a real VM
     * upgrade with "Attempted to call an undefined method named indexExists
     * of class DB". This checks directly via `SHOW KEYS`, which needs no
     * database/schema name at all — deliberately, since VM-HQ-GLPI's actual
     * database is named `itsm`, not `glpi` (hub §3/§7 nvd 2.4.1 gotcha:
     * never hardcode `TABLE_SCHEMA = 'glpi'` in an `INFORMATION_SCHEMA`
     * query). `$table`/`$keyName` are always one of this file's own hardcoded
     * literal constants — never external input — so no escaping is needed,
     * consistent with `$t` already being interpolated raw elsewhere in this
     * file (e.g. the `DROP TABLE IF EXISTS` calls above).
     */
    private static function keyExists(\DBmysql $DB, string $table, string $keyName): bool
    {
        $res = $DB->doQuery("SHOW KEYS FROM `$table` WHERE `Key_name` = '$keyName'");
        return $res !== false && $DB->numrows($res) > 0;
    }

    /**
     * Whether $field on $table is an unsigned integer column, via
     * `$DB->listFields()` (same proven-on-VM pattern `LifeCycleReader`
     * already uses to read a column's `Type` string).
     */
    private static function isUnsignedInt(\DBmysql $DB, string $table, string $field): bool
    {
        // $usecache = false: $DB caches SHOW COLUMNS per table, and \Migration
        // does not refresh it after an ALTER.
        foreach ($DB->listFields($table, false) ?: [] as $name => $meta) {
            if ((string) $name !== $field) {
                continue;
            }
            $meta = (array) $meta;
            $type = strtolower((string) ($meta['Type'] ?? $meta['type'] ?? ''));
            return str_contains($type, 'unsigned');
        }
        return false;
    }

    /**
     * v1.0.x -> v1.1.0: add the version dimension to `_eolmap` and re-key its
     * UNIQUE. Idempotent — `fieldExists()` / `keyExists()` checks ARE the
     * version gate (spec §5.2), never a version-number comparison. No-op on
     * a fresh install (the fresh DDL above already has the target shape).
     * Every pre-migration row gets `operatingsystemversions_id = 0` (the
     * column's DDL default), so `(os_id, 0)` is still unique — the key swap
     * cannot collide.
     *
     * Type keyword is `'fkey'`, NOT `'integer'`/`'int'` — GLPI's
     * `\Migration::fieldFormat()` only emits `UNSIGNED` for `'fkey'` (and
     * `'autoincrement'` for a PK); `'integer'` silently produces a signed
     * `INT` even though every `CREATE TABLE` in this file says `int
     * unsigned` (suite-wide gotcha, hub §4 rule 2b — drillreport hit this
     * exact bug first). Confirm on any VM: `SHOW COLUMNS FROM
     * glpi_plugin_sac_eolmap LIKE 'operatingsystemversions_id'` must say
     * `unsigned`.
     */
    private static function migrateEolmapVersionKey(\Migration $m, \DBmysql $DB, string $t = 'glpi_plugin_sac_eolmap'): void
    {
        if (!$DB->tableExists($t)) {
            return;
        }

        if (!$DB->fieldExists($t, 'operatingsystemversions_id')) {
            $m->addField($t, 'operatingsystemversions_id', 'fkey', [
                'after' => 'operatingsystems_id',
                'value' => 0,
            ]);
        } elseif (!self::isUnsignedInt($DB, $t, 'operatingsystemversions_id')) {
            // Retrofit for any VM that already upgraded through v1.1.0-v1.1.6,
            // which added this column via addField(..., 'integer', ...) --
            // signed. changeField() with a literal type string (not a keyword)
            // per hub §4 rule 2b's documented fix pattern.
            $m->changeField($t, 'operatingsystemversions_id', 'operatingsystemversions_id', 'int unsigned NOT NULL DEFAULT 0');
        }
        $m->migrationOneTable($t); // flush the column before the key below depends on it

        if (self::keyExists($DB, $t, 'os')) {
            $m->dropKey($t, 'os');
        }
        if (!self::keyExists($DB, $t, 'os_ver')) {
            $m->addKey($t, ['operatingsystems_id', 'operatingsystemversions_id'], 'os_ver', 'UNIQUE');
        }
    }

    /**
     * v1.0.x -> v1.1.0: add `ref_subid` to `_reconcile` and widen its UNIQUE
     * so two versions of the same OS no longer collide. Same idempotent
     * pattern as above — and, like `os` -> `os_ver`, the widened key gets a
     * NEW name (`signal_sub`, not `signal`). Reusing the old name would make
     * the add-guard `!self::keyExists($DB, $t, 'signal')` read a name whose
     * drop was only just QUEUED (not yet flushed by `\Migration`), so it
     * would still see the old key as present and skip re-adding it — the
     * table would end up with no unique constraint on this column set at
     * all. Different names sidestep that ordering hazard entirely.
     *
     * Type keyword is `'fkey'`, NOT `'integer'` — see
     * {@see migrateEolmapVersionKey()}'s docblock; same bug, same fix.
     * `ref_subid` doesn't match the `*_id` naming convention the hub §4
     * audit query (and possibly core's `migration:unsigned_keys`) looks
     * for, so it needs this explicit retrofit regardless of whether the
     * core command ever gets pointed at it — which also means it's the
     * column no name-based audit will ever catch on its own, so it needs
     * its own direct selfCheck assertion (`db/migration-reconcile-subid-key`),
     * not just eyeballing.
     */
    private static function migrateReconcileSubId(\Migration $m, \DBmysql $DB, string $t = 'glpi_plugin_sac_reconcile'): void
    {
        if (!$DB->tableExists($t)) {
            return;
        }

        if (!$DB->fieldExists($t, 'ref_subid')) {
            $m->addField($t, 'ref_subid', 'fkey', [
                'after' => 'ref_id',
                'value' => 0,
            ]);
        } elseif (!self::isUnsignedInt($DB, $t, 'ref_subid')) {
            // Retrofit for any VM that already upgraded through v1.1.0-v1.1.6.
            $m->changeField($t, 'ref_subid', 'ref_subid', 'int unsigned NOT NULL DEFAULT 0');
        }
        $m->migrationOneTable($t);

        if (self::keyExists($DB, $t, 'signal')) {
            $m->dropKey($t, 'signal');
        }
        if (!self::keyExists($DB, $t, 'signal_sub')) {
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
            'db/migration-configscan-table' => static function (): void {
                global $DB;
                sac_assert($DB->tableExists('glpi_plugin_sac_configscan'), 'table created by install()');
                sac_assert($DB->fieldExists('glpi_plugin_sac_configscan', 'computers_id'), 'computers_id column');
                sac_assert($DB->fieldExists('glpi_plugin_sac_configscan', 'score'), 'score column');
                sac_assert(self::keyExists($DB, 'glpi_plugin_sac_configscan', 'computer'), 'UNIQUE key on computers_id');
            },

            'db/migration-configrules-table' => static function (): void {
                global $DB;
                $t = 'glpi_plugin_sac_configrules';
                sac_assert($DB->tableExists($t), 'table created by install()');
                foreach ([
                    'computers_id', 'rule_id', 'result', 'severity', 'framework_refs',
                    'title', 'state', 'date_first_seen', 'date_last_seen', 'date_resolved',
                ] as $col) {
                    sac_assert($DB->fieldExists($t, $col), "$col column");
                }
                sac_assert(self::keyExists($DB, $t, 'computer_rule'), 'UNIQUE key on (computers_id, rule_id)');
            },

            'db/migration-licenserules-table' => static function (): void {
                global $DB;
                $t = 'glpi_plugin_sac_licenserules';
                sac_assert($DB->tableExists($t), 'table created by install()');
                foreach ([
                    'entities_id', 'is_recursive', 'pattern', 'match_on',
                    'classification', 'reason', 'date_creation', 'date_mod',
                ] as $col) {
                    sac_assert($DB->fieldExists($t, $col), "$col column");
                }
                sac_assert(self::keyExists($DB, $t, 'entity_match'), 'UNIQUE key on (entities_id, match_on, pattern)');
                sac_assert(self::keyExists($DB, $t, 'entity_classification'), 'KEY on (entities_id, classification)');
            },

            'db/migration-sshtargets-table' => static function (): void {
                global $DB;
                sac_assert($DB->tableExists('glpi_plugin_sac_sshtargets'), 'table created by install()');
                sac_assert($DB->fieldExists('glpi_plugin_sac_sshtargets', 'computers_id'), 'computers_id column');
                sac_assert($DB->fieldExists('glpi_plugin_sac_sshtargets', 'host'), 'host column');
                sac_assert($DB->fieldExists('glpi_plugin_sac_sshtargets', 'port'), 'port column');
                sac_assert($DB->fieldExists('glpi_plugin_sac_sshtargets', 'ssh_user'), 'ssh_user column');
                sac_assert(self::keyExists($DB, 'glpi_plugin_sac_sshtargets', 'computer'), 'UNIQUE key on computers_id — one target row per Computer');
            },
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
                    sac_assert(self::keyExists($DB, $t, 'os'), 'fixture starts with the old os key');

                    $migration = new \Migration('9.9.9');
                    self::migrateEolmapVersionKey($migration, $DB, $t);
                    $migration->executeMigration();

                    // Uncached: the fixture assert above primed $DB's column cache.
                    sac_assert($DB->fieldExists($t, 'operatingsystemversions_id', false), 'operatingsystemversions_id added by the migration');
                    sac_assert(self::isUnsignedInt($DB, $t, 'operatingsystemversions_id'), 'operatingsystemversions_id is unsigned (hub §4 rule 2b -- would have caught the addField(\'integer\',...) bug)');
                    sac_assert(self::keyExists($DB, $t, 'os_ver'), 'os_ver UNIQUE key added by the migration');
                    sac_assert(!self::keyExists($DB, $t, 'os'), 'old os key dropped by the migration');
                } finally {
                    $DB->doQuery("DROP TABLE IF EXISTS `$t`");
                }
            },

            'db/migration-reconcile-subid-key' => static function (): void {
                global $DB;
                $t = 'glpi_plugin_sac_test_reconcile_migration';
                try {
                    $DB->doQuery("DROP TABLE IF EXISTS `$t`");
                    $DB->doQuery("
                        CREATE TABLE `$t` (
                          `id` int unsigned NOT NULL AUTO_INCREMENT,
                          `signal_type` varchar(20) NOT NULL DEFAULT '',
                          `ref_itemtype` varchar(100) NOT NULL DEFAULT '',
                          `ref_id` int unsigned NOT NULL DEFAULT 0,
                          `reason` varchar(30) NOT NULL DEFAULT '',
                          `detail` text,
                          `date_firstseen` timestamp NULL DEFAULT NULL,
                          `date_lastseen` timestamp NULL DEFAULT NULL,
                          `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
                          PRIMARY KEY (`id`),
                          UNIQUE KEY `signal` (`signal_type`,`ref_itemtype`,`ref_id`,`reason`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    sac_assert(!$DB->fieldExists($t, 'ref_subid'), 'fixture starts without ref_subid');
                    sac_assert(self::keyExists($DB, $t, 'signal'), 'fixture starts with the old signal key');

                    $migration = new \Migration('9.9.9');
                    self::migrateReconcileSubId($migration, $DB, $t);
                    $migration->executeMigration();

                    sac_assert($DB->fieldExists($t, 'ref_subid', false), 'ref_subid added by the migration');
                    sac_assert(self::isUnsignedInt($DB, $t, 'ref_subid'), 'ref_subid is unsigned (hub §4 rule 2b -- ref_subid does not match the *_id naming pattern, so no name-based audit will ever catch a regression here)');
                    sac_assert(self::keyExists($DB, $t, 'signal_sub'), 'signal_sub UNIQUE key added by the migration');
                    sac_assert(!self::keyExists($DB, $t, 'signal'), 'old signal key dropped by the migration');
                } finally {
                    $DB->doQuery("DROP TABLE IF EXISTS `$t`");
                }
            },
        ];
    }
}
