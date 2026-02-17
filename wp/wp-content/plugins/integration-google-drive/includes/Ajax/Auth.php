<?php

namespace CodeConfig\IGD\Ajax;

use CodeConfig\IGD\App\Client;

defined('ABSPATH') || exit('No direct script access allowed');

class Auth extends BaseAjax
{
    public static function getAuthUrl(): void
    {
        self::verifyRequest(true);

        try {
            $url = Client::getInstance()->getAuthUrl();

            if (is_wp_error($url)) {
                self::sendError($url->get_error_message(), $url->get_error_code());
            }

            if (empty($url)) {
                self::sendError(__('Failed to generate authorization URL. Please try again.', 'integration-google-drive'));
            }

            self::sendSuccess(['url' => $url]);
        } catch (\Throwable $th) {
            self::handleError($th, __('Failed to generate authorization URL. Please try again.', 'integration-google-drive'));
        }
    }
}
