<?php

namespace CodeConfig\IGD\Pages;

defined('ABSPATH') || exit('No direct script access allowed');

class AdminPages
{
    /**
     * Adds the top level menu item for the Integration Google Drive settings page.
     *
     * @since 1.0.0
     */
    public static function adminMenu()
    {
        add_menu_page(
            'Integration Google Drive',
            'Google Drive',
            'manage_options',
            CCPIGD_SLUG,
            [self::class, 'adminPage'],
            CCPIGD_ASSETS . '/images/icons/drive.png',
            10
        );

        add_submenu_page(
            CCPIGD_SLUG,
            __("File Browser - Integration Google Drive", 'integration-google-drive'),
            __('File Browser', 'integration-google-drive'),
            'manage_options',
            CCPIGD_SLUG . '#/file-browser/my-drive',
            '__return_null'
        );
        remove_submenu_page(CCPIGD_SLUG, CCPIGD_SLUG);

        add_submenu_page(
            CCPIGD_SLUG,
            __('Module Builder - Integration Google Drive', 'integration-google-drive'),
            __('Module Builder', 'integration-google-drive'),
            'manage_options',
            CCPIGD_SLUG . '#/module-builder',
            '__return_null'
        );

        add_submenu_page(
            CCPIGD_SLUG,
            __('Settings - Integration Google Drive', 'integration-google-drive'),
            __('Settings', 'integration-google-drive'),
            'manage_options',
            CCPIGD_SLUG . '#/settings/accounts',
            '__return_null'
        );
    }

    public static function adminPage()
    {
        wp_enqueue_style('ccpigd-admin');
        echo '<div id="ccpigd-admin" class="ccpigd-admin ccpigd-top-level-wrapper"></div>';
    }

    public static function settingsPage()
    {
        echo '<div id="ccpigd-settings" class="ccpigd-settings ccpigd-top-level-wrapper"></div>';
    }

    public static function gettingStartedPage()
    {
        echo '<div id="ccpigd-getting-started" class="ccpigd-getting-started ccpigd-top-level-wrapper"></div>';
    }
}
