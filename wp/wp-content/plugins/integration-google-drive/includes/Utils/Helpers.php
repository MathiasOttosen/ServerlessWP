<?php

namespace CodeConfig\IGD\Utils;

use CodeConfig\IGD\App\App;
use CodeConfig\IGD\Models\Notices;

use function array_key_exists;
use function is_array;
use function is_string;

defined('ABSPATH') || exit();

class Helpers
{
    /**
     * Deactivates the plugin and displays an error message.
     *
     * This method deactivates the plugin and terminates execution with a
     * specified error message. It is typically used when plugin activation
     * fails, providing the user with a link to return to the Plugins page.
     *
     * @param string $message The error message to display to the user.
     */
    public static function deactivateAndNotify($message)
    {
        deactivate_plugins(plugin_basename(CCPIGD_FILE));
        wp_die(
            sprintf(
                '<p>%s</p><p><a href="%s">%s</a></p>',
                esc_html($message),
                esc_url(admin_url('plugins.php')),
                esc_html__('Return to the Plugins page', 'integration-google-drive')
            ),
            esc_html__('Plugin Activation Failed', 'integration-google-drive'),
            ['back_link' => true]
        );
    }

    public static function checkPluginRequirements()
    {
        if (version_compare(get_bloginfo('version'), CCPIGD_WP_VERSION, '<')) {
            self::deactivateAndNotify(__('WordPress version ', 'integration-google-drive') . CCPIGD_WP_VERSION . __(' or higher is required.', 'integration-google-drive'));
        }

        if (version_compare(PHP_VERSION, CCPIGD_PHP_VERSION, '<')) {
            self::deactivateAndNotify(__('PHP version ', 'integration-google-drive') . CCPIGD_PHP_VERSION . __(' or higher is required.', 'integration-google-drive'));
        }
    }

    public static function getVersion()
    {
        return CCPIGD_VERSION;
    }

    public static function getPluginName()
    {
        return CCPIGD_NAME;
    }

    public static function getPluginSlug()
    {
        return 'integration-google-drive';
    }

    public static function getPluginFile()
    {
        return CCPIGD_FILE;
    }

    public static function getPluginPath()
    {
        return CCPIGD_PATH;
    }

    public static function getPluginUrl()
    {
        return CCPIGD_URL;
    }

    public static function getPluginTextDomain()
    {
        return 'integration-google-drive';
    }

    public static function getPluginTextDomainPath()
    {
        return dirname(plugin_basename(CCPIGD_FILE)) . '/languages/';
    }

    public static function getInstalledVersion()
    {
        return get_option('ccpigd_version', '0.0.0');
    }

    public static function getInstallTime()
    {
        return get_option('ccpigd_install_time');
    }

    public static function getSettings(array $keys = [], $sensitive = 'mask')
    {
        $savedSettings = get_option(CCPIGD_OPTIONS_NAME);

        $defaultSettings = ccpigdGetDefaultSettings();

        $settings = wp_parse_args($savedSettings, $defaultSettings);

        $settings = self::sanitizeRecursiveSettings($settings, $defaultSettings, $sensitive);

        if (empty($keys)) {
            return $settings;
        }

        $filteredSettings = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $settings)) {
                $filteredSettings[$key] = $settings[$key];
            }
        }

        return $filteredSettings;
    }

    public static function getSetting($key = null, $defaultValue = null, $sensitive = 'mask')
    {
        $settings = self::getSettings([], $sensitive);

        if ($key === null) {
            return $settings;
        }

        if (strpos($key, '.') !== false) {
            $keys  = explode('.', $key);
            $value = $settings;

            foreach ($keys as $innerKey) {
                if (!is_array($value) || !array_key_exists($innerKey, $value)) {
                    return $defaultValue;
                }
                $value = $value[$innerKey];
            }

            return $value;
        }

        return $settings[$key] ?? $defaultValue;
    }


    public static function updateSettings($settings)
    {
        if (!is_array($settings)) {
            return false;
        }

        $defaultSettings = ccpigdGetDefaultSettings();
        $existingSettings = get_option(CCPIGD_OPTIONS_NAME, []);
        $validateDefault = self::sanitizeRecursiveSettings($existingSettings, $defaultSettings, 'decode');

        $validateData = self::sanitizeRecursiveSettings($settings, $validateDefault, 'encode');

        return update_option(CCPIGD_OPTIONS_NAME, $validateData);
    }

    private static function sanitizeRecursiveSettings(array $settings, array $defaultSettings, $sensitive = 'encode'): array
    {
        $sanitized       = [];
        foreach ($defaultSettings as $key => $value) {

            if (is_array($value)) {

                if (self::isSequentialArray($value) || (empty($value) && self::isSequentialArray($settings[$key]))) {
                    $sanitized[$key] = array_key_exists($key, $settings) && is_array($settings[$key])
                                ? array_values($settings[$key])
                                : $value;

                } else {
                    $sanitized[$key] = array_key_exists($key, $settings) && is_array($settings[$key]) ? self::sanitizeRecursiveSettings(wp_parse_args($settings[$key], $value), $value, $sensitive) : $value;
                }
            } else {

                if ('redirectUri' === $key) {
                    $sanitized[$key] = CCPIGD_MANUAL_REDIRECT_URI;
                    continue;
                } elseif ('appClientSecret' === $key) {
                    if ($sensitive === 'encode') {
                        $sanitized[$key] = (strpos($settings[$key], '******') !== false) ? self::encode($value) : self::encode($settings[$key] ?? '');
                    } elseif ($sensitive === 'mask') {
                        $decodedAppSecret = self::decode($settings[$key] ?? '');
                        $masked           = str_repeat('*', max(0, strlen($decodedAppSecret) - 4)) . substr($decodedAppSecret, -4);

                        $sanitized[$key] = $masked;
                    } elseif ($sensitive === 'decode') {
                        $sanitized[$key] = self::decode($settings[$key] ?? '');
                    } else {
                        $sanitized[$key] = $settings[$key] ?? '';
                    }
                    continue;
                }

                $sanitized[$key] = array_key_exists($key, $settings) ? $settings[$key] : $value;
            }
        }

        return $sanitized;
    }

    private static function isSequentialArray(array $array): bool
    {
        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Recursively applies a callback function to a given data structure.
     *
     * @param mixed $data The data structure to process.
     * @param string $callback The callback function to apply. Defaults to 'sanitize_text_field'.
     * @param array $options An array of options to customize the processing.
     *
     * Options:
     *
     * - `process_objects`: Whether to process object properties. Defaults to false.
     * - `process_nulls`: Whether to apply callback to null values. Defaults to false.
     * - `process_booleans`: Whether to apply callback to boolean values. Defaults to false.
     * - `process_numbers`: Whether to apply callback to numeric values. Defaults to false.
     * - `preserve_keys`: Whether to preserve array keys. Defaults to true.
     * - `max_depth`: Maximum recursion depth. Defaults to 100.
     * - `skip_types`: Array of types to skip processing.
     * - `only_types`: Array of types to only process (if specified).
     * - `key_callback`: Optional callback for array keys.
     * - `filter_callback`: Optional filter callback to determine if item should be processed.
     *
     * @return mixed The processed data structure.
     *
     * @throws \InvalidArgumentException If the callback is not a valid callable function.
     */
    public static function recursiveMap($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[ $key ] = self::recursiveMap($value);
            }

            return $data;
        }

        if (is_string($data)) {
            return sanitize_text_field(wp_unslash($data));
        }

        if (is_numeric($data)) {
            return $data + 0;
        }

        return $data;
    }

    /**
     * Format a timestamp according to the current locale and timezone.
     *
     * @param int $timestamp
     * @param bool $isShort
     * @return string
     */
    public static function formatDateTime($timestamp, $isShort = true)
    {
        $localTime = get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp));
        $now       = time();

        if (!$isShort) {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($localTime));
        }

        if ($timestamp > ($now - 86400)) {
            return date_i18n(get_option('time_format'), strtotime($localTime));
        } elseif ($timestamp > strtotime('first day of january this year')) {
            return date_i18n(str_replace([', Y', ',Y', 'Y-', '-Y', '/Y', 'Y/', ' Y'], '', get_option('date_format')), strtotime($localTime));
        } else {
            return date_i18n(get_option('date_format'), strtotime($localTime));
        }
    }

    public static function getPathinfo($path)
    {

        if (empty($path)) {
            return [];
        }

        preg_match('%^(.*?)[\\\\/]*([^\\\\/]*?)(?:\.([^\\\\/.]+))?$%im', $path, $matches);

        $result = [
            'dirname'   => $matches[1] ?? '',
            'basename'  => $matches[2] ?? '',
            'extension' => $matches[3] ?? '',
            'filename'  => isset($matches[2]) ? pathinfo($matches[2], PATHINFO_FILENAME) : ''
        ];

        if (substr($path, -1) === '.') {
            $result['basename'] .= '.';
            unset($result['extension']);
        }

        return $result;
    }

    public static function encode($input, $key = null, $prefix = 'IGD')
    {
        if (self::isEncoded($input, $prefix)) {
            return $input;
        }

        if (null === $key) {
            $key = get_option('ccpigd_encryption_key', 'ccpIgd');
        }

        $base64Encoded = base64_encode($input);

        $keyLength  = strlen($key);
        $xorEncoded = '';
        for ($i = 0, $len = strlen($base64Encoded); $i < $len; $i++) {
            $xorEncoded .= chr(ord($base64Encoded[$i]) ^ ord($key[$i % $keyLength]));
        }

        $hexEncoded = bin2hex($xorEncoded);

        return "CCP{$prefix}{$hexEncoded}";
    }

    public static function isEncoded($input, $prefix = 'IGD')
    {
        $prefix       = "CCP{$prefix}";
        $prefixLength = strlen($prefix);

        return substr($input, 0, $prefixLength) === $prefix;
    }

    public static function decode($input, $key = null, $prefix = "IGD")
    {
        if (null === $key) {
            $key = get_option('ccpigd_encryption_key', 'ccpIgd');
        }

        $prefix       = "CCP{$prefix}";
        $prefixLength = strlen($prefix);

        if (substr($input, 0, $prefixLength) === $prefix) {
            $input = substr($input, $prefixLength);
        } else {
            return false;
        }

        $xorEncoded = hex2bin($input);
        if ($xorEncoded === false) {
            return false;
        }

        $keyLength     = strlen($key);
        $base64Encoded = '';
        for ($i = 0, $len = strlen($xorEncoded); $i < $len; $i++) {
            $base64Encoded .= chr(ord($xorEncoded[$i]) ^ ord($key[$i % $keyLength]));
        }

        $decoded = base64_decode($base64Encoded);

        return $decoded;
    }

    public static function duplicateItems(array $input)
    {
        if (empty($input) || !is_array($input)) {
            return [];
        }

        return array_unique(array_diff_assoc($input, array_unique($input)));
    }

    public static function validateFileKey($targetFileKey, $allowedFiles)
    {
        $allowedKeys = array_column($allowedFiles, 'key');

        $breadcrumbTrail = App::getInstance()->getBreadcrumbByKey($targetFileKey, 'folder');

        if (empty($breadcrumbTrail)) {
            Notices::getInstance()->add([
                'type'        => 'error',
                'title'       => 'File key not found',
                'description' => "No breadcrumb found for file key: {$targetFileKey}"
            ]);

            return false;
        }

        $breadcrumbKeys = array_column($breadcrumbTrail, 'key');
        $checkedParents = [];

        foreach ($breadcrumbKeys as $parentKey) {
            $checkedParents[] = $parentKey;

            if (in_array($parentKey, $allowedKeys, true)) {
                return array_filter($breadcrumbTrail, fn ($crumb) => in_array($crumb['key'], $checkedParents, true));
            }
        }

        Notices::getInstance()->add([
            'type'        => 'error',
            'title'       => 'File key not allowed',
            'description' => "File key not permitted: {$targetFileKey}"
        ]);

        return false;
    }

}
