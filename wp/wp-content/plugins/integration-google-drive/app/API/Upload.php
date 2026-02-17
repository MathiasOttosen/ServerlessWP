<?php

namespace CodeConfig\IGD\App\API;

use CodeConfig\IGD\Google\Http\HttpMediaFileUpload;

defined('ABSPATH') || exit('No direct script access allowed');

use CodeConfig\IGD\App\API;
use CodeConfig\IGD\App\Client;
use CodeConfig\IGD\Google\Service\ServiceDriveDriveFile;
use WP_Error;

class Upload extends API
{
    /**
     * Constructor
     *
     * @param Client $client The client instance with the account ID
     */
    public function __construct(Client $client)
    {
        $this->client = $client->getClient();
        parent::__construct($this->client);
    }

    public function getResumeUrl(array $data)
    {
        try {
            $file = new ServiceDriveDriveFile();
            $file->setName($data['name']);
            $file->setDescription($data['description']);
            $file->setMimeType($data['type']);

            $file->setParents([$data['folderId']]);

            $this->client->setDefer(true);

            $request = $this->files->create($file, [
                'fields'            => CCPIGD_FILE_FIELDS,
                'supportsAllDrives' => true,
            ]);

            $request_headers = $request->getRequestHeaders();

            if (! empty($_SERVER['HTTP_ORIGIN'])) {
                $request_headers['Origin'] = esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN'] ?? ''));
            }

            $request->setRequestHeaders($request_headers);

            $chunkSizeBytes = 50 * 1024 * 1024;
            $media          = new HttpMediaFileUpload($this->client, $request, $data['type'], null, true, $chunkSizeBytes);
            $media->setFileSize($data['size']);

            $url = $media->getResumeUri();

            $this->client->setDefer(false);

            $uploadId = $this->setUploadIdInTransients($url, $data['folderId']);

            return [
                'url'       => $url,
                'uploadId'  => $uploadId,
            ];
        } catch (\Throwable $th) {
            return new WP_Error('error', $th->getMessage());
        }
    }

    private function setUploadIdInTransients($url, $folderId)
    {
        $parts = wp_parse_url($url);

        if (!isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $queryParams);

        $uploadId = $queryParams['upload_id'] ?? null;

        if ($uploadId) {
            set_transient("ccpigd-upload-id-{$uploadId}", $folderId, 10 * MINUTE_IN_SECONDS);
        }

        return $uploadId;
    }
}
