<?php

namespace CodeConfig\IGD\App;

use CodeConfig\IGD\Google\Service\ServiceDriveDriveFile;
use CodeConfig\IGD\Models\Files;
use CodeConfig\IGD\Utils\Helpers;

defined("ABSPATH") or die("Direct access is not allowed");

abstract class BaseFile
{
    public $id;
    public $key;
    public $name;
    public $path;
    public $size;
    public $driveId;
    public $baseName;
    public $parentId;
    public $parentKey;
    public $extension;
    public $accountId;
    public $isStarred;
    public $isTrashed;
    public $lastEdited;
    public $createdTime;
    public $description;
    public $mimeType    = '';
    public $isDirectory = false;
    public $lastEditedFormatted;
    public $createdTimeFormatted;
    public $previewLink;
    public $downloadLink;
    public $exportLinks;
    public $saveAs            = [];
    public $isShared          = false;
    public $isOwnedByMe       = false;
    public $canEditInCloud    = false;
    public $canPreviewInCloud = false;
    public $hasOwnThumbnail   = false;
    public $resourceKey;
    public $media;
    public $icon;
    public $thumbnailLink;
    public $thumbnails      = [];
    public $additionalData  = [];
    public $shortcutDetails = [];
    public $isParentFolder  = false;
    public $isVirtualFolder = false;
    public $needToSync      = false;
    public $lifeTime        = 0;
    public $count           = 0;
    public $permissions     = [
        'canDownload'                           => false,
        'canPreview'                            => false,
        'canDelete'                             => false,
        'canTrash'                              => false,
        'canMove'                               => false,
        'canEdit'                               => false,
        'canShare'                              => false,
        'canRename'                             => false,
        'copyRequiresWriterPermission'          => false,
        'canChangeCopyRequiresWriterPermission' => false,
    ];

    public function __construct(ServiceDriveDriveFile $apiFile, bool $isVirtualFolder = false)
    {
        $this->key = ccpigdGenerateKey($apiFile->getId(), $apiFile->getAccountId());

        if ($apiFile !== null) {
            $this->processFile($apiFile, $isVirtualFolder);
        }
    }

    abstract public function processFile(ServiceDriveDriveFile $apiFile, bool $isVirtualFolder = false);


    public function toArray()
    {
        $fileData = get_object_vars($this);

        if (isset($fileData['isDirectory']) && $fileData['isDirectory'] == 0) {
            unset($fileData['count']);
        }

        return $fileData;
    }

    public function dataForSave($isSerialized = true)
    {

        $file = [
            'id'            => $this->id,
            'key'           => $this->key,
            'name'          => $this->name,
            'parentId'      => $this->parentId,
            'accountId'     => $this->accountId,
            'size'          => $this->size,
            'mimeType'      => $this->mimeType,
            'extension'     => $this->extension,
            'icon'          => $this->icon,
            'thumbnailLink' => $this->thumbnailLink,
            'thumbnails'    => $isSerialized ? maybe_serialize($this->thumbnails) : $this->thumbnails,
            'exportLinks'   => $isSerialized ? maybe_serialize($this->exportLinks) : $this->exportLinks,
            'previewLink'   => $this->previewLink,
            'downloadLink'  => $this->downloadLink,
            'fileData'      => $isSerialized ? maybe_serialize($this) : $this,
            'isDirectory'   => $this->isDirectory,
            'isOwnedByMe'   => $this->isOwnedByMe,
            'isStarred'     => $this->isStarred,
            'isShared'      => $this->isShared,
        ];

        return $file;

    }

    public function getAccountId()
    {
        return $this->accountId;
    }

    public function setAccountId($accountId)
    {
        $this->accountId = $accountId;
    }

    public function hasOwnThumbnail()
    {
        return $this->hasOwnThumbnail;
    }

    public function setHasOwnThumbnail($value)
    {
        return $this->hasOwnThumbnail = (bool) $value;
    }

    public function getThumbnailLink($default = null)
    {
        if ($this->hasThumbnailLink()) {
            return $this->thumbnailLink;
        }

        return $default;
    }

    public function hasThumbnailLink()
    {
        return !empty($this->thumbnailLink);
    }

    public function setThumbnailLink($value)
    {
        return $this->thumbnailLink = $value;
    }

    public function getThumbnail($key = 'thumbnail')
    {
        return $this->thumbnails[$key] ?? '';
    }

    public function setThumbnail($key, $value)
    {
        $allowedKeys = ['thumbnail', 'medium', 'large', 'full'];
        if (!in_array($key, $allowedKeys)) {
            return false;
        }

        return $this->thumbnails[$key] = $value;
    }

    public function hasPreviewLink()
    {
        return !!$this->previewLink;
    }

    public function getPreviewLink()
    {
        return $this->previewLink;
    }

    public function setPreviewLink($previewLink)
    {
        return $this->previewLink = $previewLink;
    }

    public function getCanPreviewInCloud()
    {
        return $this->canPreviewInCloud;
    }

    public function setCanPreviewInCloud($canPreviewInCloud)
    {
        return $this->canPreviewInCloud = $canPreviewInCloud;
    }

    public function getCanEditInCloud()
    {
        return $this->canEditInCloud;
    }

    public function setCanEditInCloud($canEditInCloud)
    {
        return $this->canEditInCloud = $canEditInCloud;
    }

    public function getResourceKey()
    {
        return $this->resourceKey;
    }

    public function setResourceKey($resourceKey)
    {
        $this->resourceKey = $resourceKey;

        return $this;
    }

    public function hasResourceKey()
    {
        return !empty($this->resourceKey);
    }

    public function getExportLinks()
    {
        return $this->exportLinks;
    }

    public function setExportLinks($exportLinks)
    {
        return $this->exportLinks = $exportLinks;
    }

    public function getSaveAs()
    {
        return $this->saveAs;
    }

    public function setSaveAs($saveAs)
    {
        return $this->saveAs = $saveAs;
    }


    public function getDownloadLink()
    {
        return $this->downloadLink;
    }

    public function setDownloadLink($downloadLink)
    {
        return $this->downloadLink = $downloadLink;
    }

    public function getShared()
    {
        return $this->isShared;
    }

    public function setShared($shared = true)
    {
        $this->isShared = $shared;

        return $this;
    }

    public function getOwnedByMe()
    {
        return $this->isOwnedByMe;
    }

    public function setOwnedByMe($isOwnedByMe = true)
    {
        $this->isOwnedByMe = $isOwnedByMe;

        return $this;
    }

    public function getTrashed()
    {
        return $this->isTrashed;
    }

    public function setTrashed($isTrashed)
    {
        return $this->isTrashed = (bool) $isTrashed;
    }

    public function getStarred()
    {
        return $this->isStarred;
    }

    public function setStarred($isStarred)
    {
        return $this->isStarred = (bool) $isStarred;
    }

    public function getMedia($setting = null)
    {
        if (!empty($setting)) {
            if (isset($this->media[$setting])) {
                return $this->media[$setting];
            }

            return null;
        }

        return $this->media;
    }

    public function setMedia($media)
    {
        $this->media = $media;
    }

    public function getIcon()
    {
        return $this->icon;
    }

    public function setIcon($icon)
    {
        $this->icon = $icon;
    }

    public function getAdditionalData()
    {
        return $this->additionalData;
    }

    public function setAdditionalData($data)
    {
        $this->additionalData = $data;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getDriveId()
    {
        return $this->driveId;
    }

    public function setDriveId($driveId)
    {
        $this->driveId = $driveId;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getBaseName()
    {
        return $this->baseName;
    }

    public function setBaseName($baseName)
    {
        $this->baseName = $baseName;
    }

    public function getPath()
    {
        return $this->path;
    }

    public function setPath($path)
    {
        $this->path = $path;
    }

    public function getParentId()
    {
        return $this->parentId;
    }

    public function setParentId($parentId)
    {
        $this->parentId = is_array($parentId) ? reset($parentId) : $parentId;
    }

    public function getParentKey()
    {
        $parentKey = $this->parentKey;

        if (empty($parentKey) && !empty($this->parentId) && !empty($this->accountId)) {
            $parentKey = ccpigdGenerateKey($this->parentId, $this->accountId);
        }

        return $parentKey;
    }

    public function setParentKey($parentKey)
    {
        $this->parentKey = $parentKey;
    }

    public function hasParent()
    {
        return !empty($this->parentId);
    }

    public function getExtension()
    {
        return $this->extension;
    }

    public function setExtension($extension)
    {
        $this->extension = $extension;
    }

    public function getMimeType()
    {
        return $this->mimeType;
    }

    public function setMimeType($mimeType)
    {
        $this->mimeType = !empty($mimeType) ? $mimeType : '';
    }

    public function isDirectory()
    {
        return !empty($this->isDirectory);
    }

    public function isFile()
    {
        return !$this->isDirectory;
    }

    public function setIsDirectory($isDirectory)
    {
        $this->isDirectory = (bool) $isDirectory;
    }

    public function getSize()
    {
        return $this->size;
    }

    public function setSize($size)
    {
        $this->size = (int) $size;
    }

    public function getDescription()
    {
        return $this->description ?? '';
    }

    public function setDescription($description)
    {
        $this->description = !empty($description) ? trim($description) : '';
    }

    public function getCreatedTime()
    {
        return $this->createdTime;
    }

    public function setCreatedTime($createdTime)
    {
        $this->createdTime = $createdTime;
    }

    public function getCreatedTimeFormatted($isShort = true)
    {
        return empty($this->createdTime) ? '' : Helpers::formatDateTime(strtotime($this->createdTime), $isShort);
    }

    public function getLastEdited()
    {
        return $this->lastEdited;
    }

    public function setLastEdited($lastEdited)
    {
        $this->lastEdited = $lastEdited;
    }

    public function getLastEditedFormatted($isShort = true)
    {
        return empty($this->lastEdited) ? '' : Helpers::formatDateTime(strtotime($this->lastEdited), $isShort);
    }

    public function isVirtualFolder()
    {
        return $this->isVirtualFolder;
    }

    public function setVirtualFolder($isVirtualFolder)
    {
        $this->isVirtualFolder = $isVirtualFolder;
    }

    public function setPermissions(array $permissions)
    {
        $this->permissions = $permissions;
    }

    public function getPermission(string $key)
    {
        return $this->permissions[$key] ?? null;
    }
    public function setPermission(string $key, $value)
    {
        return $this->permissions[$key] = $value;
    }

    public function getKey()
    {
        return $this->key;
    }
    public function setKey($key)
    {
        $this->key = $key;
    }

    public function save()
    {
        Files::getInstance()->addFile($this->dataForSave());

        return $this->toArray();
    }

    public function setLifeTime($lifeTime)
    {
        $this->lifeTime = $lifeTime;
    }

    public function getLifeTime()
    {
        return $this->lifeTime;
    }
}
