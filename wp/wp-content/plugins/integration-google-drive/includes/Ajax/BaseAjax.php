<?php

namespace CodeConfig\IGD\Ajax;

use CodeConfig\IGD\Models\Notices;
use CodeConfig\IGD\Utils\Helpers;
use WP_Error;

defined('ABSPATH') || exit('No direct script access allowed');

/**
 * Base Ajax class providing common functionality for all Ajax handlers.
 *
 * This class centralizes common patterns like nonce verification,
 * response handling, and data sanitization to reduce code duplication.
 */
abstract class BaseAjax
{
    /** @var string The nonce action for all Ajax requests */
    protected const NONCE_ACTION = 'ccpigd';

    /** @var string The capability required for protected actions */
    protected const ACCESS_CAPABILITY = CCPIGD_ACCESS_CAP;

    /**
     * Verify Ajax nonce and optionally check user capability.
     *
     * @param bool $checkCapability Whether to check user capability
     * @param string|null $capability Custom capability to check
     * @throws void Terminates execution on failure
     */
    protected static function verifyRequest(bool $checkCapability = false, ?string $capability = null): void
    {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            $message = __('Invalid nonce. Access denied. Action: ', 'integration-google-drive') . ' ' . sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));

            wp_send_json_error($message, 403);
        }

        if ($checkCapability) {
            $requiredCap = $capability ?? self::ACCESS_CAPABILITY;
            if (!current_user_can($requiredCap)) {
                self::sendError(__('Unauthorized request. Access denied.', 'integration-google-drive'), 403);
            }
        }
    }

    /**
     * Send error response and terminate execution.
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @param array $extra Additional data to include
     */
    protected static function sendError(string $message, int $code = 400, array $extra = []): void
    {
        Notices::getInstance()->add([
            'title'       => 'Ajax Request Error',
            'type'        => 'error',
            'description' => $message
        ]);

        wp_send_json_error(array_merge(['message' => $message], $extra), $code);
    }

    /**
     * Send success response and terminate execution.
     *
     * @param array $data Response data
     * @param string $message Optional success message
     * @param int $code HTTP status code
     */
    protected static function sendSuccess(array $data = [], string $message = '', int $code = 200): void
    {
        $response = $data;

        if (!empty($message)) {
            $response['message'] = $message;
        }

        wp_send_json_success($response, $code);
    }

    /**
     * Handle error response with proper error type detection.
     *
     * @param mixed $error WP_Error|Exception|array|null
     * @param string $defaultMessage Default error message
     * @param int $defaultCode Default error code
     */
    protected static function handleError($error, string $defaultMessage, int $defaultCode = 400): void
    {
        $message = $defaultMessage;
        $code    = $defaultCode;
        $extra   = [];

        if ($error instanceof WP_Error) {
            $message   = $error->get_error_message() ?: $defaultMessage;
            $errorCode = $error->get_error_code();
            $code      = is_numeric($errorCode) ? (int) $errorCode : $defaultCode;
        } elseif ($error instanceof \Exception) {
            $extra['error'] = $error->getMessage();
        } elseif (is_array($error) && isset($error['message'])) {
            $message = $error['message'];
            $code    = $error['code'] ?? $defaultCode;
        }

        self::sendError($message, $code, $extra);
    }

    /**
     * Get and sanitize request data from POST.
     *
     * @param array $requiredKeys Required keys with optional mapping
     * @param string $dataKey The POST key containing the data
     * @return array Sanitized and validated data
     * @throws void Terminates execution on validation failure
     */
    protected static function getRequestData(array $requiredKeys = [], string $dataKey = 'config'): array
    {
        self::verifyRequest();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!isset($_POST[$dataKey])) {
            self::sendError(__('Missing request data.', 'integration-google-drive'), 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $rawData = sanitize_text_field(wp_unslash($_POST[$dataKey] ?? ''));
        $data    = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            self::sendError(__('Invalid JSON data.', 'integration-google-drive'), 400);
        }

        // Sanitize all data recursively
        $data = Helpers::recursiveMap($data);

        // Validate required keys
        $normalizedKeys = array_map(
            fn ($k, $v) => is_int($k) ? $v : $k,
            array_keys($requiredKeys),
            $requiredKeys
        );

        foreach ($normalizedKeys as $key) {
            if (empty($data[$key])) {
                self::sendError(
                    sprintf('Missing required field: %s', $key),
                    400
                );
            }
        }

        return self::processAccountAndFileKeys($data, $requiredKeys);
    }

    /**
     * Process account and file key mappings.
     *
     * @param array $data Input data
     * @param array $requiredKeys Key mappings
     * @return array Processed data
     */
    private static function processAccountAndFileKeys(array $data, array $requiredKeys): array
    {
        // Handle account key
        if (!empty($data['accountKey'])) {
            $accountField = is_string($requiredKeys['accountKey'] ?? null)
                ? $requiredKeys['accountKey']
                : 'accountId';

            $account = ccpigdGetAccountByKey($data['accountKey']);

            if (is_wp_error($account)) {
                self::handleError($account, __('Account validation failed.', 'integration-google-drive'));
            }

            if (empty($account)) {
                self::sendError(__('Invalid account key provided.', 'integration-google-drive'), 404);
            }

            $data[$accountField] = $account->getId();
        }

        // Handle file/folder key mappings
        $keyMappings = [
            'fileKey'   => 'fileId',
            'folderKey' => 'folderId',
            'parentKey' => 'folderId'
        ];

        foreach ($keyMappings as $key => $defaultIdKey) {
            if (!empty($data[$key])) {
                $idKey = $requiredKeys[$key] ?? $defaultIdKey;
                $file  = ccpigdGetFileByKey($data[$key]);

                // Handle special case for folder/parent keys
                if (is_wp_error($file) && $file->get_error_code() === 404 && in_array($key, ['folderKey', 'parentKey'])) {
                    $account = ccpigdGetAccountByKey($data[$key]);

                    if (is_wp_error($account)) {
                        self::handleError($account, __('Account validation failed.', 'integration-google-drive'));
                    }

                    if (empty($account)) {
                        self::sendError(__('Invalid folder key provided.', 'integration-google-drive'), 404);
                    }

                    $file = [
                        'id'        => $account->getRootId(),
                        'accountId' => $account->getId(),
                    ];
                }

                if (is_wp_error($file)) {
                    self::handleError($file, sprintf('Invalid %s provided.', $key));
                }

                if (empty($file)) {
                    self::sendError(sprintf('Invalid %s provided.', $key), 404);
                }

                $data[$idKey] = $file['id'];
                $data['accountId'] ??= $file['accountId'];
            }
        }

        // Handle multiple file keys
        if (!empty($data['fileKeys']) && isset($requiredKeys['fileKeys'])) {
            $returnFields    = is_array($requiredKeys['fileKeys']) ? $requiredKeys['fileKeys'] : ['id'];
            $data['fileIds'] = ccpigdGetFileAttributesByKeys($data['fileKeys'], $returnFields);

            if (empty($data['fileIds'])) {
                self::sendError(__('Invalid file keys provided.', 'integration-google-drive'), 404);
            }

            $firstKey = reset($data['fileKeys']);
            $file     = ccpigdGetFileByKey($firstKey);

            if (empty($file)) {
                self::sendError(__('Invalid file keys provided.', 'integration-google-drive'), 404);
            }

            $data['accountId'] ??= $file['accountId'];
        }

        return $data;
    }

    /**
     * Get a simple POST parameter with optional sanitization.
     *
     * @param string $key Parameter key
     * @param mixed $default Default value if not found
     * @param callable|null $sanitizer Custom sanitization function
     * @return mixed Sanitized value
     */
    protected static function getPostParam(string $key, $default = '', ?callable $sanitizer = null)
    {
        self::verifyRequest();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        $rawValue = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;

        if ($sanitizer && is_callable($sanitizer)) {
            return $sanitizer($rawValue);
        }
        
        if (is_array($rawValue)) {
            return Helpers::recursiveMap($rawValue);
        }

        return sanitize_text_field($rawValue);
    }
}
