<?php

namespace CodeConfig\IGD\App;

use CodeConfig\IGD\Google\Client as GoogleClient;
use CodeConfig\IGD\Google\Service\ServiceDrive;
use CodeConfig\IGD\Utils\Singleton;

defined('ABSPATH') || exit('No direct script access allowed');

abstract class API
{
    use Singleton;

    /**
     * Google Client instance.
     *
     * @var GoogleClient
     */
    protected $client;

    /**
     * ServiceDrive instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDrive
     */
    protected $service;

    /**
     * About API instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDriveAboutResource
     */
    protected $about;

    /**
     * Changes API instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDriveChangesResource
     */
    protected $changes;

    /**
     * Channels API instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDriveChannelsResource
     */
    protected $channels;

    /**
     * Comments API instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDriveCommentsResource
     */
    protected $comments;

    /**
     * Files API instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDriveFilesResource
     */
    protected $files;

    /**
     * Permissions API instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDrivePermissionsResource
     */
    protected $permissions;

    /**
     * Replies API instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDriveRepliesResource
     */
    protected $replies;

    /**
     * Revisions API instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDriveRevisionsResource
     */
    protected $revisions;

    /**
     * Drives API instance.
     *
     * @var \CodeConfig\IGD\Google\Service\ServiceDriveDrivesResource
     */
    protected $drives;

    /**
     * Service name.
     *
     * @var string
     */
    protected $serviceName;


    public function __construct(GoogleClient $client)
    {
        $this->init($client);
    }
    /*
    function igd_file_map($item, $account_id = null)
    {

        // if (empty($account_id)) {
        //     $account_id = Account::()->get_active_account()['id'];
        // }

        $file = [
            'id'                           => $item->getId(),
            'name'                         => $item->getName(),
            'type'                         => $item->getMimeType(),
            'size'                         => $item->getSize(),
            'iconLink'                     => $item->getIconLink(),
            'thumbnailLink'                => $item->getThumbnailLink(),
            'webViewLink'                  => $item->getWebViewLink(),
            'webContentLink'               => $item->getWebContentLink(),
            'created'                      => $item->getCreatedTime(),
            'updated'                      => $item->getModifiedTime(),
            'description'                  => $item->getDescription(),
            'shared'                       => $item->getShared(),
            'sharedWithMeTime'             => $item->getSharedWithMeTime(),
            'extension'                    => $item->getFileExtension(),
            'resourceKey'                  => $item->getResourceKey(),
            'copyRequiresWriterPermission' => $item->getCopyRequiresWriterPermission(),
            'starred'                      => $item->getStarred(),
            'exportLinks'                  => $item->getExportLinks(),
            'accountId'                    => $account_id,
        ];

        // Get parents
        $parents = $item->getParents();

        // Replace root folder id with 'root'
        if (! empty($parents)) {
            foreach ($parents as $key => $parent) {
                // Check if root item, replace original id with 'root'
                if (
                    Account::instance()->get_root_id($account_id) == $parent
                ) {
                    $parents[$key] = 'root';
                }
            }
        }

        $file['parents'] = $parents;

        $canPreview                            = true;
        $canDownload                           = ! empty($file['webContentLink']) || ! empty($file['exportLinks']);
        $canShare                              = false;
        $canEdit                               = false;
        $canDelete                             = $item->getOwnedByMe();
        $canTrash                              = $item->getOwnedByMe();
        $canMove                               = $item->getOwnedByMe();
        $canRename                             = $item->getOwnedByMe();
        $canChangeCopyRequiresWriterPermission = true;

        $capabilities = $item->getCapabilities();


        if (
            ! empty($capabilities)
        ) {
            $canEdit                               = $capabilities->getCanEdit() && igd_is_editable($file['type']);
            $canShare                              = $capabilities->getCanShare();
            $canRename                             = $capabilities->getCanRename();
            $canDelete                             = $capabilities->getCanDelete();
            $canTrash                              = $capabilities->getCanTrash();
            $canMove                               = $capabilities->getCanMoveItemWithinDrive();
            $canChangeCopyRequiresWriterPermission = $capabilities->getCanChangeCopyRequiresWriterPermission();
        }

        // Permission users
        $users = [];

        $permissions = $item->getPermissions();
        if (count($permissions) > 0) {
            foreach ($permissions as $permission) {
                $users[$permission->getId()] = [
                    'type'   => $permission->getType(),
                    'role'   => $permission->getRole(),
                    'domain' => $permission->getDomain()
                ];
            }
        }

        // Set the permissions
        $file['permissions'] = [
            'canPreview'                            => $canPreview,
            'canDownload'                           => $canDownload,
            'canEdit'                               => $canEdit,
            'canDelete'                             => $canDelete,
            'canTrash'                              => $canTrash,
            'canMove'                               => $canMove,
            'canRename'                             => $canRename,
            'canShare'                              => $canShare,
            'copyRequiresWriterPermission'          => $item->getCopyRequiresWriterPermission(),
            'canChangeCopyRequiresWriterPermission' => $canChangeCopyRequiresWriterPermission,
            'users'                                 => $users,
        ];

        // Set owner
        if (! empty($item->getOwners())) {
            $file['owner'] = $item->getOwners()[0]['displayName'];
        }

        // Get export as
        $file['exportAs'] = igd_get_export_as($item->getMimeType());


        // Shortcut details
        if (! empty($item->getShortcutDetails())) {
            $file['shortcutDetails'] = [
                'targetId'       => $item->getShortcutDetails()->getTargetId(),
                'targetMimeType' => $item->getShortcutDetails()->getTargetMimeType(),
            ];


            $original_file = App::instance($account_id)->get_file_by_id($file['shortcutDetails']['targetId']);

            if (
                ! empty($original_file)
            ) {
                $file['thumbnailLink'] = $original_file['thumbnailLink'];
                $file['iconLink']      = $original_file['iconLink'];
                $file['extension']     = $original_file['extension'];
                $file['exportAs']      = $original_file['exportAs'];
            }
        }

        //Meta Data
        $image_meta_data = $item->getImageMediaMetadata();
        $video_meta_data = $item->getVideoMediaMetadata();

        if (
            $image_meta_data
        ) {
            $file['metaData'] = [
                'width'  => $image_meta_data->getWidth(),
                'height' => $image_meta_data->getHeight(),
            ];
        } elseif ($video_meta_data) {
            $file['metaData'] = [
                'width'    => $video_meta_data->getWidth(),
                'height'   => $video_meta_data->getHeight(),
                'duration' => $video_meta_data->getDurationMillis(),
            ];
        }

        return $file;
    }

    */

    // ================= Protected methods ==================

    protected function getGoogleService()
    {
        return $this->service;
    }

    protected function getGoogleClient()
    {
        return $this->client;
    }

    protected function getGoogleAbout()
    {
        return $this->about;
    }

    protected function getGoogleDrives()
    {
        return $this->drives;
    }

    protected function getGoogleFiles()
    {
        return $this->files;
    }

    /**
     * Initializes the API service with the given client
     *
     * @param GoogleClient $client The client to use for the API service
     *
     * @return void
     */
    protected function init(GoogleClient $client)
    {
        $this->client  = $client;
        $this->service = new ServiceDrive($this->client);

        $this->drives      = $this->service->drives;
        $this->about       = $this->service->about;
        $this->changes     = $this->service->changes;
        $this->channels    = $this->service->channels;
        $this->files       = $this->service->files;
        $this->comments    = $this->service->comments;
        $this->permissions = $this->service->permissions;
        $this->replies     = $this->service->replies;
        $this->revisions   = $this->service->replies;
        $this->serviceName = $this->service->serviceName;

    }
}
