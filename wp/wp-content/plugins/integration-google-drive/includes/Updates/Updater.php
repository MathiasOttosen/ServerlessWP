<?php

namespace CodeConfig\IGD\Updates;

use CodeConfig\IGD\Utils\Singleton;

defined('ABSPATH') || exit;

class Updater
{
    use Singleton;

    public function __construct()
    {
        // Initialize the updater
    }

    protected function columnExists($table, $column)
    {
        global $wpdb;

        $cacheKey = "column_exists_{$table}_{$column}";
        $cache    = wp_cache_get($cacheKey, 'column_exists');

        if ($cache !== false) {
            return $cache;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table, $column));
        wp_cache_set($cacheKey, !empty($result), 'column_exists', 3600);

        return !empty($result);
    }
}
