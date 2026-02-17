<?php

namespace CodeConfig\IGD\Ajax;

use CodeConfig\IGD\Utils\Helpers;

defined('ABSPATH') || exit;

/**
 * Handle AJAX requests related to plugin settings.
 */
class Settings extends BaseAjax
{
    /**
     * Get all settings.
     */
    public static function get(): void
    {
        self::verifyRequest();

        self::sendSuccess([
            'data' => Helpers::getSettings()
        ], __('Settings fetched successfully', 'integration-google-drive'));
    }

    /**
     * Update settings.
     */
    public static function update(): void
    {
        self::verifyRequest();

        $config = self::decodeConfig();

        if (Helpers::updateSettings($config)) {
            self::sendSuccess([
                'data' => Helpers::getSettings()
            ], __('Settings updated successfully', 'integration-google-drive'));
        }

        self::sendError(__('Failed to update settings', 'integration-google-drive'));
    }

    /**
     * Decode and sanitize JSON config from $_POST.
     */
    private static function decodeConfig(): array
    {
        $raw = self::getPostParam('config');

        if (empty($raw)) {
            self::sendError(__('Missing config parameter', 'integration-google-drive'), 400);
        }

        $config = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
            self::sendError(__('Invalid JSON config data', 'integration-google-drive'), 400);
        }

        return Helpers::recursiveMap($config);
    }
}
