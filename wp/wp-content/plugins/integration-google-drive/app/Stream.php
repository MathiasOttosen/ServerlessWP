<?php

namespace CodeConfig\IGD\App;

use CodeConfig\IGD\Models\Notices;
use CodeConfig\IGD\Utils\Helpers;

defined('ABSPATH') || exit;

class Stream
{
    private $file;

    public function __construct($key)
    {
        $referrer         = wp_get_raw_referer();


        if (empty($referrer) && !empty(Helpers::getSetting('advanced.secureVideoPlayback', false))) {
            $userIP      = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $description = 'A request was made to the streaming endpoint without a valid referrer. User IP: ' . $userIP . ', Video Key: ' . ($key ?? 'none');
            Notices::getInstance()->add([
                'title'       => __('Unauthorized access attempt to streaming endpoint.', 'integration-google-drive'),
                'description' => $description,
                'type'        => 'error',
                'status'      => 401,
                'context'     => 'streaming',
            ]);
            http_response_code(401);
            exit;
        }

        if (empty($key)) {
            $userIP = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            Notices::getInstance()->add([
                'title'       => __('Missing file key to stream.', 'integration-google-drive'),
                'description' => 'A request was made to the streaming endpoint without a valid key. User IP: ' . $userIP,
                'type'        => 'error',
                'status'      => 400,
                'context'     => 'streaming',
            ]);
            http_response_code(400);
            exit;
        }

        $this->streaming($key);
    }

    /**
     * Handle streaming requests.
     */
    private function streaming($key): void
    {
        if (empty($key)) {
            Notices::getInstance()->add([
                'title'   => __('Missing file key to stream.', 'integration-google-drive'),
                'type'    => 'error',
                'status'  => 400,
                'context' => 'streaming',
            ]);
            http_response_code(400);
            exit;
        }

        ignore_user_abort(true);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit(0);

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @ini_set('zlib.output_compression', 'Off');
        @session_write_close();

        // Clean all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        $this->file = ccpigdGetFileByKey($key);

        if (is_wp_error($this->file)) {
            Notices::getInstance()->add([
                'title'   => $this->file->get_error_message(),
                'type'    => 'error',
                'status'  => 400,
                'context' => 'streaming',
            ]);
            http_response_code(400);
            exit;
        }

        if (empty($this->file)) {
            Notices::getInstance()->add([
                'title'   => __('File not found or access denied.', 'integration-google-drive'),
                'type'    => 'error',
                'status'  => 404,
                'context' => 'streaming',
            ]);
            http_response_code(404);
            exit;
        }

        $is_tutor_lms = false;
        $chunk_size   = $this->getChunkSize($is_tutor_lms ? 'high' : '');

        $size   = (int) ($this->file['size'] ?? 0);
        $start  = 0;
        $end    = $size - 1;
        $length = $size;

        // Set basic headers
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . ($this->file['mimeType'] ?? 'application/octet-stream'));
        header('X-Accel-Buffering: no');

        $filename         = basename($this->file['name'] ?? 'file');
        $encoded_filename = rawurlencode($filename);
        header("Content-Disposition: inline; filename=\"$filename\"; filename*=UTF-8''$encoded_filename");

        // Remove any encoding headers that might interfere
        header_remove('Content-Encoding');
        header_remove('Transfer-Encoding');

        $cache_expiry = HOUR_IN_SECONDS * 4; // 4 hours
        $expires      = gmdate('D, d M Y H:i:s', time() + $cache_expiry) . ' GMT';
        header("Expires: {$expires}");
        header('Pragma: cache');
        header("Cache-Control: max-age=$cache_expiry");

        // Handle range requests
        if (isset($_SERVER['HTTP_RANGE'])) {
            [$range_start, $range_end] = $this->parseRange(sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'] ?? '')), $size);

            if ($range_start === null || $range_end === null) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes */{$size}");
                exit;
            }

            $start  = $range_start;
            $end    = $range_end;
            $length = $end - $start + 1;

            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        } else {
            header('HTTP/1.1 200 OK');
        }

        header("Content-Length: {$length}");

        // Stream the file and verify bytes sent
        $bytes_sent = $this->streamFile($start, $end, $chunk_size);
    }

    /**
     * Stream file in chunks - returns total bytes sent for verification.
     */
    private function streamFile(int $start, int $end, int $chunk_size): int
    {
        $position         = $start;
        $total_bytes_sent = 0;
        $max_retries      = 2;

        while ($position <= $end && connection_status() === CONNECTION_NORMAL) {

            $chunk_end   = min($position + $chunk_size - 1, $end);
            $retries     = 0;
            $chunk_bytes = false;

            // Retry failed chunks
            while ($retries < $max_retries && $chunk_bytes === false) {
                if ($retries > 0) {
                    usleep(500000); // 0.5 second delay
                }

                $chunk_bytes = $this->streamGetChunk($position, $chunk_end);
                $retries++;
            }

            if ($chunk_bytes === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("Failed to stream chunk {$position}-{$chunk_end} after {$max_retries} attempts");
                break; // Stop streaming on chunk failure to prevent length mismatch
            }

            $total_bytes_sent += $chunk_bytes;
            $position = $chunk_end + 1;
        }

        return $total_bytes_sent;
    }

    /**
     * Perform request for chunk from Google Drive with exact byte counting.
     */
    private function streamGetChunk($start, $end, $chunked = true): int|false
    {
        $headers = $chunked ? ['Range' => "bytes={$start}-{$end}"] : [];

        if (!empty($this->file['resourceKey'])) {
            $headers['X-Goog-Drive-Resource-Keys'] = $this->file['id'] . '/' . $this->file['resourceKey'];
        }

        $request = new \CodeConfig\IGD\Google\Http\HttpRequest($this->getApiUrl(), 'GET', $headers);
        $request->disableGzip();

        $bytes_written  = 0;
        $expected_bytes = $end - $start + 1;
        $client         = Client::getInstance($this->file['accountId'])->getClient();

        $client->getIo()->setOptions([
            CURLOPT_RETURNTRANSFER      => false,   // direct output, no full memory storage
            CURLOPT_FOLLOWLOCATION      => true,    // follow redirect if file hosted elsewhere
            CURLOPT_HEADER              => false,   // no headers in body
            CURLOPT_CONNECTTIMEOUT      => 10,      // max 10s to connect
            CURLOPT_TIMEOUT             => 0,       // unlimited time (big file download lagbe)
            CURLOPT_LOW_SPEED_LIMIT     => 1024,    // 1KB/sec minimum
            CURLOPT_LOW_SPEED_TIME      => 30,      // if <1KB/sec for 30s -> abort
            CURLOPT_TCP_NODELAY         => 1,       // faster packet sending
            CURLOPT_BUFFERSIZE          => 8192,    // buffer size per chunk (8KB, can increase to 64KB)
            CURLOPT_WRITEFUNCTION       => function ($ch, $data) use (&$bytes_written) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $data;      // direct output (streaming)
                flush();                        // send immediately to browser

                $bytes_written += strlen($data);

                return strlen($data);           // must return length
            },
        ]);

        try {
            $response = $client->getAuth()->authenticatedRequest($request);

            return $bytes_written;
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("Chunk streaming error ({$start}-{$end}): " . $e->getMessage());

            // Clean output buffers on error
            while (ob_get_level()) {
                ob_end_clean();
            }

            return false;
        }
    }

    /**
     * Determine chunk size optimized for video streaming.
     */
    private function getChunkSize(string $level = ''): int
    {
        // Optimized chunk sizes for video streaming
        switch ($level) {
            case 'high':
                $size = 256 * 1024; // 256KB - for mobile/slow connections
                break;
            case 'medium':
                $size = 512 * 1024; // 512KB - balanced for most connections
                break;
            case 'low':
                $size = 1024 * 1024; // 1MB - for fast connections
                break;
            default:
                $size = 20 * 1024 * 1024; // 20MB default - good balance
                break;
        }

        return $size; // Don't limit by memory for video streaming
    }

    /**
     * Get Google Drive media endpoint URL.
     */
    private function getApiUrl(): string
    {
        return 'https://www.googleapis.com/drive/v3/files/' . $this->file['id'] . '?alt=media';
    }

    /**
     * Parse HTTP range header and return start/end positions.
     */
    private function parseRange(string $range_header, int $total_size): array
    {
        if (!preg_match('/bytes=(\d*)-(\d*)/', $range_header, $matches)) {
            return [null, null];
        }

        $start = $matches[1] === '' ? 0 : (int) $matches[1];
        $end   = $matches[2] === '' ? $total_size - 1 : (int) $matches[2];

        // Validate range
        if ($start < 0 || $start >= $total_size || $end < $start || $end >= $total_size) {
            return [null, null];
        }

        return [$start, $end];
    }
}
