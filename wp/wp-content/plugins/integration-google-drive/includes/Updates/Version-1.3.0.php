<?php

namespace CodeConfig\IGD\Updates;

defined('ABSPATH') || exit;

class Version_130 extends Updater
{
    public function __construct()
    {
        $this->alterModuleTable();
    }

    private function alterModuleTable()
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}integration_google_drive_shortcodes LIKE 'integration'");

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("ALTER TABLE {$wpdb->prefix}integration_google_drive_shortcodes ADD COLUMN `integration` VARCHAR(60) DEFAULT NULL AFTER `type`;");
        }
    }
}

Version_130::getInstance();
