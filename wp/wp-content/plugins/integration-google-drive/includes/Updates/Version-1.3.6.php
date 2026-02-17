<?php

namespace CodeConfig\IGD\Updates;

use CodeConfig\IGD\Utils\Helpers;

defined('ABSPATH') || exit;

class Version_136 extends Updater
{
    public function __construct()
    {
        $this->migrationSettings();
    }

    private function migrationSettings()
    {
        $settings = get_option(CCPIGD_OPTIONS_NAME, []);
        Helpers::updateSettings($settings);
    }
}

Version_136::getInstance();
