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

        self::$tables['glpi_plugin_sac_eolmap'] = "
            CREATE TABLE `glpi_plugin_sac_eolmap` (
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

        self::$tables['glpi_plugin_sac_reconcile'] = "
            CREATE TABLE `glpi_plugin_sac_reconcile` (
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
}
