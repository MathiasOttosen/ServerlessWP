<?php

namespace CodeConfig\IGD;

defined('ABSPATH') || exit('No direct script access allowed');

use CodeConfig\IGD\Utils\Helpers;
use CodeConfig\IGD\Utils\Singleton;

class Update
{
    use Singleton;

    /**
     * List of available version update scripts
     */
    private static $updateList = [
        '1.1.0',
        '1.2.0',
        '1.3.0',
        '1.3.6',
    ];

    /**
     * Check if an update is available for the plugin
     *
     * @return bool
     */
    public function isUpdateAvailable(): bool
    {
        $installedVersion = Helpers::getInstalledVersion();

        foreach (self::$updateList as $version) {
            if (
                version_compare($version, $installedVersion, '>') &&
                version_compare($version, CCPIGD_VERSION, '<=')
            ) {
                return true;
            }
        }

        return false;
    }


    /**
     * Performs updates for the plugin by iterating over the list of available
     * version update scripts. For each version that is greater than the installed
     * version and less than or equal to the current version, it includes the
     * respective update script and updates the version option in the database.
     *
     * @return void
     */

    public function performUpdates(): void
    {
        $installedVersion = Helpers::getInstalledVersion();

        foreach (self::$updateList as $version) {
            if (
                version_compare($version, $installedVersion, '>') &&
                version_compare($version, CCPIGD_VERSION, '<=')
            ) {
                $filePath = CCPIGD_UPDATES . "/Version-$version.php";

                if (file_exists($filePath)) {
                    include_once $filePath;
                    update_option('ccpigd_version', $version);
                }
            }
        }

        update_option('ccpigd_version', CCPIGD_VERSION);
    }
}
