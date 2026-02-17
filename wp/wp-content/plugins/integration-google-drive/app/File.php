<?php

namespace CodeConfig\IGD\App;

use CodeConfig\IGD\Google\Service\ServiceDriveDriveFile;
use CodeConfig\IGD\Google\Service\ServiceDriveDriveFileCapabilities;
use CodeConfig\IGD\Utils\Helpers;
use WP_Error;

class File extends BaseFile
{
    /**
     *  The API file object
     *
     * @var ServiceDriveDriveFile
     */
    private $apiFile;

    public function processFile(ServiceDriveDriveFile $apiFile, bool $virtualFolder = false)
    {
        if (!$apiFile instanceof ServiceDriveDriveFile) {
            return new WP_Error(403, __('Google response is not a valid Entry.', 'integration-google-drive'));
        }

        $this->apiFile = $apiFile;

        $this->setVirtualFolder($virtualFolder);

        $this->setMetadata($apiFile);

        $this->setShortcutDetails($apiFile);

        $this->setDownloadExportLinks($apiFile);

        $this->setPreviewAndPermissions($apiFile);

        $this->setIconAndThumbnail($apiFile);

        $this->setMediaData($apiFile);

        $this->setAdditionalData([]);
    }

    public function setThumbnails($thumbnail)
    {
        $icon = $this->getIcon();

        if (empty($thumbnail)) {
            $this->setThumbnail('thumbnail', str_replace('/32/', '/64/', $icon));
            $this->setThumbnail('medium', str_replace('/32/', '/64/', $icon));
            $this->setThumbnail('large', str_replace('/32/', '/128/', $icon));
            $this->setThumbnail('full', str_replace('/32/', '/256/', $icon));
        } else {
            $this->setHasOwnThumbnail(true);
            $this->setThumbnailLink($thumbnail);
            $this->generateThumbnails($thumbnail);
        }
    }

    public function getThumbnailWithSize($width, $crop = false, $thumbnailUrl = null)
    {
        if (empty($thumbnailUrl)) {
            $thumbnailUrl = $this->getThumbnail();
        }

        $thumbnailSizeW = get_option('thumbnail_size_w', 150);
        $thumbnailSizeH = get_option('thumbnail_size_h', 150);
        $isCrop         = get_option('thumbnail_crop', true);

        $searchString = "w{$thumbnailSizeW}-h{$thumbnailSizeH}";

        if ($isCrop) {
            $searchString .= '-c';
        }

        $size = "s{$width}";

        if ($crop) {
            $size .= "-c";
        }

        return str_replace($searchString, $size, $thumbnailUrl);
    }

    public function createSaveAs()
    {
        switch ($this->getMimeType()) {
            case 'application/vnd.google-apps.document':
                return [
                    'MS Word document'     => ['mimetype' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'extension' => 'docx', 'icon' => 'eva-download'],
                    'HTML'                 => ['mimetype' => 'text/html', 'extension' => 'html', 'icon' => 'eva-download'],
                    'Text'                 => ['mimetype' => 'text/plain', 'extension' => 'txt', 'icon' => 'eva-download'],
                    'Open Office document' => ['mimetype' => 'application/vnd.oasis.opendocument.text', 'extension' => 'odt', 'icon' => 'eva-download'],
                    'PDF'                  => ['mimetype' => 'application/pdf', 'extension' => 'pdf', 'icon' => 'eva-download'],
                    'ZIP'                  => ['mimetype' => 'application/zip', 'extension' => 'zip', 'icon' => 'eva-download'],
                ];

            case 'application/vnd.google-apps.spreadsheet':
                return [
                    'MS Excel document'      => ['mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'extension' => 'xlsx', 'icon' => 'eva-download'],
                    'Open Office sheet'      => ['mimetype' => 'application/x-vnd.oasis.opendocument.spreadsheet', 'extension' => 'ods', 'icon' => 'eva-download'],
                    'PDF'                    => ['mimetype' => 'application/pdf', 'extension' => 'pdf', 'icon' => 'eva-download'],
                    'CSV (first sheet only)' => ['mimetype' => 'text/csv', 'extension' => 'csv', 'icon' => 'eva-download'],
                    'ZIP'                    => ['mimetype' => 'application/zip', 'extension' => 'zip', 'icon' => 'eva-download'],
                ];

            case 'application/vnd.google-apps.drawing':
                return [
                    'JPEG' => ['mimetype' => 'image/jpeg', 'extension' => 'jpeg', 'icon' => 'eva-download'],
                    'PNG'  => ['mimetype' => 'image/png', 'extension' => 'png', 'icon' => 'eva-download'],
                    'SVG'  => ['mimetype' => 'image/svg+xml', 'extension' => 'svg', 'icon' => 'eva-download'],
                    'PDF'  => ['mimetype' => 'application/pdf', 'extension' => 'pdf', 'icon' => 'eva-download'],
                ];

            case 'application/vnd.google-apps.presentation':
                return [
                    'MS PowerPoint document' => ['mimetype' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'extension' => 'pptx', 'icon' => 'eva-download'],
                    'PDF'                    => ['mimetype' => 'application/pdf', 'extension' => 'pdf', 'icon' => 'eva-download'],
                    'Text'                   => ['mimetype' => 'text/plain', 'extension' => 'txt', 'icon' => 'eva-download'],
                ];

            case 'application/vnd.google-apps.script':
                return [
                    'JSON' => ['mimetype' => 'application/vnd.google-apps.script+json', 'extension' => 'json', 'icon' => 'eva-download'],
                ];

            case 'application/vnd.google-apps.form':
                return [
                    'ZIP' => ['mimetype' => 'application/zip', 'extension' => 'zip', 'icon' => 'eva-download'],
                ];

            default:
                return [];
        }
    }

    public function editSupportedInCloud()
    {
        switch ($this->getMimeType()) {
            case 'application/msword':
            case 'application/vnd.ms-excel':
            case 'application/vnd.ms-powerpoint':
            case 'application/vnd.google-apps.drawing':
            case 'application/vnd.google-apps.document':
            case 'application/vnd.google-apps.spreadsheet':
            case 'application/vnd.google-apps.presentation':
            case 'application/vnd.ms-excel.sheet.macroenabled.12':
            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
            case 'application/vnd.openxmlformats-officedocument.presentationml.slideshow':
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
            case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
                return true;

            default:
                return false;
        }
    }

    public function hasPermission($permission_role = ['reader', 'writer'])
    {
        $workspaceDomain = Helpers::getSetting('advanced.googleWorkspaceDomain', '');
        $users           = $this->permissions['users'] ?? [];

        if (empty($users)) {
            return false;
        }

        $type = empty($workspaceDomain) ? 'anyone' : 'domain';
        foreach ($users as $user) {
            if (
                $user['type'] == $type                    &&
                in_array($user['role'], $permission_role) &&
                (empty($workspaceDomain) || $user['domain'] == $workspaceDomain)
            ) {
                return true;
            }
        }

        return false;
    }

    // ========================== Private methods ==========================
    private function setMetadata(ServiceDriveDriveFile $apiFile)
    {
        $this->setId($apiFile->getId());
        $this->setName($apiFile->getName());
        $this->setDriveId($apiFile->getDriveId());
        $this->setStarred($apiFile->getStarred());
        $this->setAccountId($apiFile->getAccountId());

        if (!empty($apiFile->getFileExtension())) {
            $this->setExtension(strtolower($apiFile->getFileExtension()));
        }

        if ('application/vnd.google-apps.shortcut' === $apiFile->getMimeType()) {
            $pathInfo = Helpers::getPathinfo($this->getName());
            if (isset($pathInfo['extension'])) {
                $this->setExtension($pathInfo['extension']);
            }
        } elseif (empty($apiFile->getFileExtension())) {
            $mimeType  = $apiFile->getMimeType();
            $extension = ccpigdGetExtensionByMimeType($mimeType);
            $this->setExtension($extension ?: 'unknown');
        }

        $this->setMimeType($apiFile->getMimeType());

        if (empty($this->extension)) {
            $this->setBasename($this->getName());
        } else {
            $this->setBasename(str_ireplace('.' . $this->getExtension(), '', $apiFile->getName()));
        }

        $this->setTrashed($apiFile->getTrashed());
        $this->setIsDirectory('application/vnd.google-apps.folder' === $apiFile->getMimeType());
        $this->setSize($this->isDirectory() ? 0 : $apiFile->getSize());
        $this->setDescription($apiFile->getDescription());
        $this->setLastEdited($apiFile->getModifiedTime());
        $this->setCreatedTime($apiFile->getCreatedTime());

        $this->setOwnedByMe($this->isOwnedByMe($apiFile));
        $this->setShared($this->isShared($apiFile));

        $this->setParentId($apiFile->getParents());
        $this->setParentFolder($apiFile);
    }

    private function setShortcutDetails(ServiceDriveDriveFile $apiFile)
    {
        $shortcutDetails = $apiFile->getShortcutDetails();
        if (!empty($shortcutDetails)) {
            $this->setShortcutDetailsAttributes($shortcutDetails);
        }
    }

    private function setDownloadExportLinks(ServiceDriveDriveFile $apiFile)
    {
        $this->setDownloadLink($apiFile->getWebContentLink());
        $this->setSaveAs($this->createSaveAs());
        $this->setExportLinks($apiFile->getExportLinks());
        $this->setResourceKey($apiFile->getResourceKey());

        $previewLink = $apiFile->getWebViewLink();
        if (!empty($previewLink) && !in_array($this->getExtension(), ['zip']) && $this->isFile()) {
            $this->setCanPreviewInCloud(true);
        }

        if (!empty($previewLink)) {
            $previewLink = str_replace('view?usp=drivesdk', 'preview?rm=minimal', $previewLink);
        }

        $this->setPreviewLink($previewLink);
    }

    private function setPreviewAndPermissions(ServiceDriveDriveFile $apiFile)
    {
        $capabilities = $apiFile->getCapabilities();
        if (empty($capabilities)) {
            return;
        }
        $this->setCapabilities($capabilities);

        $permissions = $this->getPermissions($apiFile);
        $this->setPermissions($permissions);
    }

    private function setIconAndThumbnail(ServiceDriveDriveFile $apiFile)
    {
        $icon = $apiFile->getIconLink();
        if (!empty($icon)) {
            $this->setIcon(str_replace(['/16/'], ['/32/'], $icon));
        }

        $this->setThumbnails($apiFile->getThumbnailLink());
    }

    private function setMediaData(ServiceDriveDriveFile $apiFile)
    {
        $mediaData = [];

        $imageMetadata = $apiFile->getImageMediaMetadata();
        $videoMetadata = $apiFile->getVideoMediaMetadata();

        if (!empty($imageMetadata)) {
            $mediaData = $this->getImageMetadata($imageMetadata);
        } elseif (!empty($videoMetadata)) {
            $mediaData = $this->getVideoMetadata($videoMetadata);
        }

        $this->setMedia($mediaData);
    }

    private function setParentFolder(ServiceDriveDriveFile $apiFile)
    {
        if (empty($apiFile->getParents()) && !$this->isVirtualFolder()) {
            if ($apiFile->getDriveId() === $apiFile->getId()) {
                $this->setParentId('shared-drives');
                $this->setVirtualFolder('shared-drive');
            } elseif ($apiFile->getShared() && !$apiFile->getOwnedByMe()) {
                $this->setParentId('shared');
            } elseif (!empty($apiFile->getSharedWithMeTime()) && !$apiFile->getOwnedByMe()) {
                $this->setParentId('shared');
            } elseif (!$apiFile->getShared() && $apiFile->getOwnedByMe()) {
                $this->setParentId('computers');
                $this->setVirtualFolder('computer');
            } else {
                return new WP_Error(403, __('Found an item without a parent folder (orphaned):', 'integration-google-drive'));
            }
        }
    }

    private function isOwnedByMe(ServiceDriveDriveFile $apiFile)
    {
        return ('mydrive' !== $apiFile->getDriveId()) ? true : $apiFile->getOwnedByMe();
    }

    /**
     * Checks if a file is shared.
     *
     * Checks if a file is shared with the current user.
     *
     * @param ServiceDriveDriveFile|null $apiFile The file to be checked. If empty, uses the current file.
     * @return bool True if the file is shared, false if not.
     */
    private function isShared($apiFile = null)
    {
        if (empty($apiFile)) {
            $apiFile = $this->apiFile;
        }

        return $apiFile->getShared();
    }

    private function setCapabilities(ServiceDriveDriveFileCapabilities $capabilities)
    {
        if (!$capabilities instanceof ServiceDriveDriveFileCapabilities) {
            return;
        }

        $this->setCanEditInCloud($capabilities->getCanEdit() && $this->editSupportedInCloud());
        $this->setPermission('canEdit', $capabilities->getCanEdit());
        $this->setPermission('canRename', $capabilities->getCanRename());
        $this->setPermission('canShare', $capabilities->getCanShare());
        $this->setPermission('canDelete', $capabilities->getCanDelete());
        $this->setPermission('canTrash', $capabilities->getCanTrash());
        $this->setPermission('canMove', $capabilities->getCanMoveItemWithinDrive());
        $this->setPermission('canChangeCopyRequiresWriterPermission', $capabilities->getCanChangeCopyRequiresWriterPermission() ?? false);
    }

    private function getPermissions(ServiceDriveDriveFile $apiFile)
    {
        $users          = [];
        $apiPermissions = $apiFile->getPermissions();

        if (count($apiPermissions) > 0) {
            foreach ($apiPermissions as $permission) {
                $users[$permission->getId()] = [
                    'type'   => $permission->getType(),
                    'role'   => $permission->getRole(),
                    'domain' => $permission->getDomain()
                ];
            }
        }

        return [
            'users'                                 => $users,
            'canPreview'                            => true,
            'canDownload'                           => true,
            'canAdd'                                => $apiFile->getOwnedByMe(),
            'canMove'                               => $apiFile->getOwnedByMe(),
            'canShare'                              => $apiFile->getOwnedByMe(),
            'canTrash'                              => $apiFile->getOwnedByMe(),
            'canRename'                             => $apiFile->getOwnedByMe(),
            'canDelete'                             => $apiFile->getOwnedByMe(),
            'copyRequiresWriterPermission'          => $apiFile->getCopyRequiresWriterPermission(),
            'canChangeCopyRequiresWriterPermission' => $this->getPermission('canChangeCopyRequiresWriterPermission'),
        ];
    }

    private function setShortcutDetailsAttributes($shortcutDetails)
    {
        // $this->setShortcutDetails([
        //     'targetId' => $shortcutDetails->getTargetId(),
        //     'targetMimeType' => $shortcutDetails->getTargetMimeType(),
        //     'targetResourceKey' => $shortcutDetails->getTargetResourceKey()
        // ]);

        $this->setMimeType($shortcutDetails->getTargetMimeType());

        if ('application/vnd.google-apps.folder' === $shortcutDetails->getTargetMimeType()) {
            $this->setIsDirectory(true);
        }
    }

    private function getImageMetadata($imageMetadata)
    {
        $mediaData = [];
        if (empty($imageMetadata->rotation) || 0 === $imageMetadata->getRotation() || 2 === $imageMetadata->getRotation()) {
            $mediaData['width']  = $imageMetadata->getWidth();
            $mediaData['height'] = $imageMetadata->getHeight();
        } else {
            $mediaData['width']  = $imageMetadata->getHeight();
            $mediaData['height'] = $imageMetadata->getWidth();
        }

        if (!empty($imageMetadata->time)) {
            $dtime = \DateTime::createFromFormat('Y:m:d H:i:s', $imageMetadata->getTime(), new \DateTimeZone('UTC'));

            if ($dtime) {
                $mediaData['time'] = $dtime->getTimestamp();
            }
        }

        return $mediaData;
    }

    private function getVideoMetadata($videoMetadata)
    {
        return [
            'width'    => $videoMetadata->getWidth(),
            'height'   => $videoMetadata->getHeight(),
            'duration' => $videoMetadata->getDurationMillis(),
        ];
    }

    private function generateThumbnails($thumbnail)
    {
        $isCrop         = get_option('thumbnail_crop', true);
        $largeSizeW     = get_option('large_size_w', 1024);
        $largeSizeH     = get_option('large_size_h', 1024);
        $mediumSizeW    = get_option('medium_size_w', 300);
        $mediumSizeH    = get_option('medium_size_h', 300);
        $thumbnailSizeW = get_option('thumbnail_size_w', 150);
        $thumbnailSizeH = get_option('thumbnail_size_h', 150);

        $thumbnails = [
            'thumbnail' => $this->generateThumbnail($thumbnailSizeW, $thumbnailSizeH, $isCrop),
            'medium'    => $this->generateThumbnail($mediumSizeW, $mediumSizeH, $isCrop),
            'large'     => $this->generateThumbnail($largeSizeW, $largeSizeH, $isCrop),
            'full'      => $this->generateThumbnail(),
        ];

        $this->setThumbnail('thumbnail', $thumbnails['thumbnail']);
        $this->setThumbnail('medium', $thumbnails['medium']);
        $this->setThumbnail('large', $thumbnails['large']);
        $this->setThumbnail('full', $thumbnails['full']);

        return $thumbnails;
    }

    private function generateThumbnail($width = 'full', $height = 'auto', $crop = false)
    {
        if ($this->isVirtualFolder()) {
            return $this->getIcon();
        }

        $size = $width === 'full' ? 'w1920-h1080' : "s{$width}";

        if ($height !== 'auto' && $width !== 'full') {
            $size = "w{$width}-h{$height}";
        }

        if ($crop) {
            $size .= "-c-nu";
        }

        $thumbnailParams = [
            'id'       => $this->getId(),
            'sz'       => $size,
            'mimeType' => $this->getMimeType()
        ];

        // TODO: need to add settings in admin panel
        if (get_option('ccpigd_shared_thumbnails_type', 'generated') === 'google') {
            $userPermissions = $this->getPermission('users');

            if (
                $this->isShared() && isset($userPermissions['anyoneWithLink']) &&
                $userPermissions['anyoneWithLink']['type'] === 'anyone'
            ) {
                return add_query_arg($thumbnailParams, "https://drive.google.com/thumbnail");
            }
        }

        unset($thumbnailParams["id"]);
        $thumbnailParams['key'] = $this->getKey();

        $encodedParams = [
            'ccpigd-thumbnail' => Helpers::encode(wp_json_encode($thumbnailParams)),
        ];

        $thumbnailUrl = add_query_arg($encodedParams, site_url());

        return $thumbnailUrl;
    }
}
