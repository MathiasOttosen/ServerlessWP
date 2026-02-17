<?php

namespace CodeConfig\IGD\App;

use CodeConfig\IGD\App\API\Files as APIFiles;
use CodeConfig\IGD\App\API\Permission;
use CodeConfig\IGD\App\API\Upload;
use CodeConfig\IGD\Models\Files;
use CodeConfig\IGD\Models\Notices;
use CodeConfig\IGD\Models\Shortcode;
use CodeConfig\IGD\Notifications;
use CodeConfig\IGD\Utils\Helpers;
use CodeConfig\IGD\Utils\Singleton;
use function defined;
use function in_array;
use function is_array;
use WP_Error;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class App {
    use Singleton;
    public $accountId;

    private $files;

    private $client;

    public function __construct( $accountId = null ) {
        $this->prepareApiFiles( $accountId );
    }

    /**
     * Retrieves a file by its id.
     *
     * If the file is cached, this function will attempt to retrieve it from the cache.
     * Otherwise, it will fetch the file from the Google Drive API.
     *
     * @param string $id The id of the file to retrieve.
     * @param string $accountId The id of the account associated with the file.
     * @param bool $force Whether to force the request to the API and save the result to the database.
     *
     * @return array|WP_Error The file object or false if the file does not exist.
     */
    public function getFile( $id, $accountId, $force = false ) {
        if ( empty( $id ) || empty( $accountId ) ) {
            return new WP_Error(400, 'Missing file id or account id.');
        }
        if ( empty( $force ) ) {
            $file = Files::getInstance()->getFile( $id, $accountId );
            if ( !empty( $file ) && !is_wp_error( $file ) ) {
                return $file->toArray();
            }
        }
        $client = Client::getInstance( $accountId );
        $fileApi = new APIFiles($client);
        $file = $fileApi->getFileById( $id );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        if ( empty( $file ) ) {
            return new WP_Error(400, 'Something went wrong while fetching the file. Please try again.');
        }
        return $file->save();
    }

    /**
     * Retrieves a file by its key.
     *
     * If the key matches an entry in the database, the function will return the file object from the database.
     * If the key does not match an entry in the database, the function will return false.
     *
     * If the $force parameter is set to true, the function will request the file from the API and save it to the
     * database, even if the file is already cached.
     *
     * @param string $key The file key.
     * @param bool $force Whether to force the request to the API and save the result to the database.
     *
     * @return array|false|WP_Error The file object or false if the file does not exist.
     */
    public function getFileByKey( $key, $force = false ) {
        if ( empty( $key ) ) {
            return false;
        }
        $file = Files::getInstance()->getFileByKey( $key );
        if ( empty( $file ) || is_wp_error( $file ) ) {
            return false;
        }
        if ( empty( $force ) ) {
            return $file->toArray();
        }
        $accountId = $file['accountId'] ?? null;
        $id = $file['id'] ?? null;
        if ( !$accountId || !$id ) {
            return false;
        }
        $client = Client::getInstance( $accountId );
        $fileApi = new APIFiles($client);
        $file = $fileApi->getFileById( $id );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        return $file->save();
    }

    /**
     * Retrieves a folder by its key.
     *
     * This function fetches a folder's details using the provided key and
     * returns the associated files if the folder is found.
     *
     * @param array $args An associative array containing:
     *                    - 'key': The key of the folder to retrieve.
     *                    - 'type': The type of the key (e.g., 'my-drive' or 'folder').
     *
     * @return mixed False if the folder is not found or the key is empty,
     *               otherwise returns the files associated with the folder.
     */
    public function getFolderByKey( $args ) {
        $key = $args['key'] ?? null;
        if ( empty( $key ) ) {
            return false;
        }
        $data = $this->getDataByKey( $key, $args['type'] );
        if ( empty( $data ) || is_wp_error( $data ) ) {
            return false;
        }
        $folderId = $data['folderId'] ?? null;
        $accountId = $data['accountId'] ?? null;
        if ( !$folderId || !$accountId ) {
            return false;
        }
        $args['id'] = $folderId;
        $args['accountId'] = $accountId;
        return $this->getFiles( $args );
    }

    private function separateFilesAndFolders( array $files ) : array {
        $result = [
            'files'   => [],
            'folders' => [],
        ];
        foreach ( $files as $file ) {
            if ( !empty( $file['isDirectory'] ) ) {
                $result['folders'][] = $file;
            } else {
                $result['files'][] = $file;
            }
        }
        return $result;
    }

    /**
     * Retrieves a breadcrumb array by its key.
     *
     * This function fetches a breadcrumb array using the provided key and
     * returns the associated breadcrumb if the folder is found.
     *
     * @param string $key The key of the folder to retrieve.
     * @param string $type The type of the key (e.g., 'my-drive' or 'folder').
     *
     * @return array|WP_Error False if the folder is not found or the key is empty,
     *                        otherwise returns the breadcrumb associated with the folder.
     */
    public function getBreadcrumbByKey( $key, $type ) {
        if ( empty( $key ) ) {
            return [];
        }
        $data = $this->getDataByKey( $key, $type );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( empty( $data ) ) {
            return [];
        }
        $folderId = $data['folderId'] ?? null;
        $accountId = $data['accountId'] ?? null;
        $folder = $data['folder'] ?? [];
        if ( !$folderId || !$accountId ) {
            return [];
        }
        // Handle special types
        if ( in_array( $type, [
            'my-drive',
            'shared',
            'starred',
            'computers',
            'shared-drives'
        ] ) ) {
            $labels = [
                'my-drive'      => __( 'My Drive', 'integration-google-drive' ),
                'shared'        => __( 'Shared with me', 'integration-google-drive' ),
                'starred'       => __( 'Starred', 'integration-google-drive' ),
                'computers'     => __( 'Computers', 'integration-google-drive' ),
                'shared-drives' => __( 'Shared Drives', 'integration-google-drive' ),
            ];
            return [[
                'key'  => $key,
                'name' => $labels[$type] ?? ucfirst( $type ),
            ]];
        }
        // Default to folder name
        $breadcrumb = [[
            'key'  => $key,
            'name' => $folder['name'] ?? __( 'Unknown Folder', 'integration-google-drive' ),
        ]];
        // Handle special parents
        $specialParents = [
            'shared-drives' => __( 'Shared Drives', 'integration-google-drive' ),
            'computers'     => __( 'Computers', 'integration-google-drive' ),
            'shared'        => __( 'Shared with me', 'integration-google-drive' ),
        ];
        $parentId = $folder['parentId'] ?? null;
        if ( !empty( $parentId ) ) {
            if ( isset( $specialParents[$parentId] ) ) {
                $breadcrumb[] = [
                    'key'  => $parentId,
                    'name' => $specialParents[$parentId],
                ];
                return $breadcrumb;
            }
            $account = Accounts::getInstance()->getAccount( $folder['accountId'] );
            if ( is_wp_error( $account ) ) {
                return $account;
            }
            if ( $account && $account->getRootId() === $parentId ) {
                $breadcrumb[] = [
                    'key'  => $account->getKey(),
                    'name' => __( 'My Drive', 'integration-google-drive' ),
                ];
                return $breadcrumb;
            }
            // Recursively get parent breadcrumb
            $parentFolder = $this->getFile( $parentId, $accountId );
            if ( !empty( $parentFolder['key'] ) && !is_wp_error( $parentFolder ) ) {
                $_breadcrumb = $this->getBreadcrumbByKey( $parentFolder['key'], 'folder' );
                if ( is_wp_error( $_breadcrumb ) ) {
                    return $_breadcrumb;
                }
                return array_merge( $breadcrumb, $_breadcrumb );
            }
        }
        return $breadcrumb;
    }

    /**
     * Retrieve a list of files from the specified folder and account.
     *
     * If the files are cached, this function will attempt to retrieve them from the cache.
     * Otherwise, it will fetch the files from the Google Drive API.
     *
     * @param array $args {
     *                    An associative array of arguments.
     *
     * @type string $id The ID of the folder to retrieve files from.
     * @type string $accountId The ID of the account associated with the files.
     * @type string $from Where to retrieve files from. Can be either 'cache' or 'server'.
     * @type int $limit The maximum number of files to retrieve.
     * @type int $fileNumbers The number of files to show in the response. If this is less than the total number of files,
     *           the function will return a subset of the files.
     *           }
     *
     * @return array|WP_Error The list of files, sorted by name and limited to the specified number of items.
     */
    public function getFiles( array $args = [] ) {
        $args = $this->prepareArgs( $args );
        $folderId = $args['id'] ?? null;
        $accountId = $args['accountId'] ?? null;
        if ( empty( $folderId ) || empty( $accountId ) ) {
            return [];
        }
        $isCached = Files::getInstance()->isCachedFolder( $folderId, $accountId );
        $files = ( $isCached && $args['from'] !== 'server' ? $this->fetchFilesFromCache( $args ) : $this->fetchFilesFromServer( $args ) );
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        return $this->prepareResponse( $files, $args );
    }

    /**
     * Create a new folder
     *
     * @param $args array
     * @option folderName string
     * @option parentId string
     * @option accountId string
     *
     * @return array|WP_Error
     */
    public function newFolder( $args = [] ) {
        $folderName = $args['folderName'] ?? null;
        $parentId = $args['parentId'] ?? null;
        $accountId = $args['accountId'] ?? null;
        if ( empty( $folderName ) || empty( $parentId ) || empty( $accountId ) ) {
            return new WP_Error(400, 'Folder name or parent folder not found for new folder creation');
        }
        if ( empty( $parentId ) ) {
            return new WP_Error(400, 'Parent folder not found for new folder creation');
        }
        $folder = $this->files->createNewFolder( $folderName, $parentId );
        if ( empty( $folder ) ) {
            return new WP_Error(500, 'Failed to create folder');
        }
        // add new folder to log
        do_action(
            'ccpigd_insert_log',
            'folder',
            $folder['id'],
            $this->accountId
        );
        return $folder;
    }

    /**
     * Upload a file
     *
     * @param array $args
     * @option name string
     * @option description string
     * @option type string
     * @option folderId string
     * @option size int
     *
     * @return array|null|WP_Error The URL to upload the file to
     */
    public function upload( $args = [] ) {
        $file = [
            'name'        => $args['name'] ?? '',
            'description' => $args['description'] ?? '',
            'type'        => $args['type'] ?? '',
            'folderId'    => $args['folderId'] ?? '',
            'size'        => $args['size'] ?? '',
        ];
        if ( empty( $file['name'] ) || empty( $file['type'] ) || empty( $file['folderId'] ) ) {
            return;
        }
        $upload = Upload::getInstance( $this->client );
        $resumeData = $upload->getResumeUrl( $file );
        if ( is_wp_error( $resumeData ) ) {
            return $resumeData;
        }
        if ( empty( $resumeData['url'] ) ) {
            return;
        }
        return $resumeData;
    }

    /**
     * Rename a file
     *
     * @param array $args The arguments
     * @option fileId string The ID of the file to rename
     * @option name string The new name of the file
     *
     * @return string|null|WP_Error The new name of the file
     */
    public function rename( $args = [] ) {
        $fileId = $args['fileId'] ?? null;
        $name = $args['name'] ?? null;
        if ( empty( $fileId ) || empty( $name ) ) {
            return;
        }
        return $this->files->rename( $fileId, $name );
    }

    /**
     * Update the description of a file.
     *
     * @param array $args The arguments.
     * @option fileId string The ID of the file to be updated.
     * @option description string The new description of the file.
     *
     * @return string|WP_Error The new description of the file, or null on failure.
     */
    public function updateDescription( $args = [] ) {
        $fileId = $args['fileId'] ?? null;
        $description = $args['description'] ?? null;
        if ( empty( $fileId ) || empty( $description ) ) {
            return new WP_Error(400, __( "File ID and description are required to update the description.", 'integration-google-drive' ));
        }
        return $this->files->updateDescription( $fileId, $description );
    }

    /**
     * Deletes files based on the provided file IDs.
     *
     * @param array|string $fileKeys The Keys of the files to be deleted.
     *
     * @return mixed|WP_Error Returns the result of the delete operation if successful,
     *                        otherwise null if there was an error or if $fileIds is empty.
     */
    public function delete( $fileIds ) {
        if ( empty( $fileIds ) ) {
            return new WP_Error(400, __( 'File IDs are required to delete files.', 'integration-google-drive' ));
        }
        $delete = $this->files->deleteFile( $fileIds );
        if ( is_wp_error( $delete ) ) {
            return $delete;
        }
        if ( !empty( $delete['error'] ) || empty( $delete ) ) {
            return new WP_Error(500, __( 'Failed to delete file.', 'integration-google-drive' ));
        }
        return $delete;
    }

    /**
     * Generate a preview link for a file.
     *
     * @param array $args The arguments.
     * @option fileId string The ID of the file to generate a preview link for.
     *
     * @return string|WP_Error The preview link, or null on failure.
     */
    public function preview( $args = [] ) {
        $fileId = $args['fileId'] ?? null;
        if ( empty( $fileId ) ) {
            return new WP_Error(400, __( 'A file ID is required to generate a preview link. Please provide a valid ID and try again.', 'integration-google-drive' ));
        }
        $file = Files::getInstance()->getFile( $fileId, $this->accountId );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        if ( empty( $file ) || !$file instanceof File ) {
            return new WP_Error(404, __( 'Unable to load file data. Please verify the file ID and try again.', 'integration-google-drive' ));
        }
        $hasPermission = $file->hasPermission( ['reader'] );
        if ( !$hasPermission ) {
            $generatePermission = $this->generatePermission( $file );
            if ( is_wp_error( $generatePermission ) ) {
                return $generatePermission;
            }
            if ( !$generatePermission ) {
                return new WP_Error(403, __( 'Unable to generate preview link due to insufficient permissions.', 'integration-google-drive' ));
            }
        }
        if ( $file->isDirectory() ) {
            return "https://drive.google.com/drive/folders/{$fileId}/";
        }
        $mode = $args['mode'] ?? 'preview';
        return $this->getEmbedUrl( $fileId, $file->getMimeType(), $mode );
    }

    private function getEmbedUrl( $fileId, $mimeType, $mode = 'preview' ) {
        $editorMimes = [
            'application/vnd.google-apps.document'      => 'document',
            'application/vnd.google-apps.spreadsheet'   => 'spreadsheets',
            'application/vnd.google-apps.presentation'  => 'presentation',
            'application/vnd.google-apps.form'          => 'forms',
            'application/vnd.google-apps.drawing'       => 'drawings',
            'application/vnd.google-apps.jam'           => 'jam',
            'application/vnd.google-apps.site'          => 'site',
            'application/vnd.google-apps.map'           => 'maps',
            'application/vnd.google-apps.script'        => 'script',
            'application/vnd.google-apps.script+json'   => 'script',
            'application/vnd.google-apps.script+webapp' => 'script',
            'application/vnd.google-apps.addon'         => 'addon',
        ];
        $service = $editorMimes[$mimeType] ?? null;
        if ( empty( $service ) ) {
            return "https://drive.google.com/file/d/{$fileId}/preview?rm=minimal";
        }
        if ( $service === 'forms' ) {
            return "https://docs.google.com/forms/d/{$fileId}/viewform";
        }
        if ( $mode === 'editable' ) {
            return "https://docs.google.com/{$service}/d/{$fileId}/edit?usp=drivesdk&rm=minimal&embedded=true";
        } elseif ( $mode === 'full-editable' ) {
            return "https://docs.google.com/{$service}/d/{$fileId}/edit?usp=drivesdk&rm=embedded&embedded=true";
        } else {
            return "https://drive.google.com/file/d/{$fileId}/preview?rm=minimal";
        }
    }

    /**
     * Generate a shareable link for a file or folder on Google Drive.
     *
     * @param array $args The arguments.
     * @option fileId string The ID of the file or folder to generate a share link for.
     *
     * @return string|WP_Error The share link, or null if the file ID is invalid or the file cannot be shared.
     */
    public function shareLink( $args = [] ) {
        $shortcodeId = $args['shortcodeId'] ?? null;
        $isAdmin = $args['isAdmin'] ?? false;
        $fileKey = $args['key'] ?? null;
        $fileId = $args['fileId'] ?? null;
        if ( empty( $fileKey ) ) {
            return new WP_Error(400, __( 'A file key is required to generate a share link. Please provide a valid key and try again.', 'integration-google-drive' ));
        }
        if ( empty( $shortcodeId ) && !$isAdmin ) {
            return new WP_Error(403, __( 'You do not have permission to generate a share link.', 'integration-google-drive' ));
        }
        if ( $shortcodeId ) {
            if ( $this->checkShortcodePermissions( $shortcodeId, 'allowShare' ) === false ) {
                return new WP_Error(403, __( 'You do not have permission to generate a share link for this file.', 'integration-google-drive' ));
            }
            if ( Shortcode::getInstance()->checkAllowShortcodeScopes( $shortcodeId, $fileKey ) === false ) {
                return new WP_Error(403, __( 'You do not have permission to generate a share link for this file.', 'integration-google-drive' ));
            }
            Notifications::getInstance()->notify( 'create_share_link', $shortcodeId, $fileKey );
        }
        $fileId = $args['fileId'] ?? null;
        if ( empty( $fileId ) ) {
            return new WP_Error(400, __( "A file ID is required to generate a share link. Please provide a valid ID and try again.", 'integration-google-drive' ));
        }
        $file = Files::getInstance()->getFile( $fileId, $this->accountId );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        if ( empty( $file ) || !$file instanceof File ) {
            return new WP_Error(404, __( "Couldn't find a valid file to share. Please check the file ID and try again.", 'integration-google-drive' ));
        }
        $hasPermission = $file->hasPermission( ['reader'] );
        if ( !$hasPermission ) {
            $generatePermission = $this->generatePermission( $file );
            if ( !$generatePermission ) {
                return new WP_Error(403, __( "Unable to generate share link due to insufficient permissions.", 'integration-google-drive' ));
            }
        }
        $url = $this->generateShortcodeUrl( $args );
        return $url;
    }

    /**
     * Downloads a file from Google Drive by ID.
     *
     * @param string $fileId The ID of the file to download.
     *
     * @return string|WP_Error The download link or false if the file is not found or not a regular file.
     */
    public function download( $fileId, $mimeType = null ) {
        if ( empty( $fileId ) ) {
            return new WP_Error(400, __( 'A file ID is required to download a file. Please provide a valid ID and try again.', 'integration-google-drive' ));
        }
        $file = Files::getInstance()->getFile( $fileId, $this->accountId );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        if ( empty( $file ) || !$file instanceof File ) {
            return new WP_Error(404, __( 'Unable to load file data. Please verify the file ID and try again.', 'integration-google-drive' ));
        }
        $hasPermission = $file->hasPermission( ['reader'] );
        if ( !$hasPermission ) {
            $generatePermission = $this->generatePermission( $file );
            if ( !$generatePermission ) {
                return new WP_Error(403, __( 'Unable to download file due to insufficient permissions.', 'integration-google-drive' ));
            }
        }
        if ( !empty( $file->getExportLinks() ) ) {
            $exportLinks = $file->getExportLinks();
            if ( !empty( $mimeType ) ) {
                return $exportLinks[$mimeType] ?? reset( $exportLinks );
            } else {
                return reset( $exportLinks );
            }
        }
        if ( !empty( $file->isDirectory() ) ) {
            Notices::getInstance()->add( [
                'title'       => __( 'This file is a directory', 'integration-google-drive' ),
                'description' => __( 'This file is a directory. Please download the file as a zip file.', 'integration-google-drive' ),
                'type'        => 'warning',
                'fileId'      => $fileId,
            ] );
            return "https://drive.google.com/drive/folders/{$fileId}/?usp=sharing";
        }
        return "https://drive.google.com/uc?export=download&id={$fileId}";
    }

    /**
     * Search files in Google Drive.
     *
     * @param array $data {
     *                    An array of arguments.
     *
     * @type string $from            From where to search, either 'cache' or 'server'.
     * @type string $scope           Scope of the search, either 'parent' or 'global'.
     * @type string $query           Search query.
     * @type string $orderBy         Field to order by.
     * @type string $order           Order direction, either 'ASC' or 'DESC'.
     * @type array $types           Types of files to search.
     * @type int $limit           The number of files to return.
     * @type bool $fullText        Whether to search full text or not.
     * @type bool $trashed         Whether to include trashed files or not.
     * @type string $folderId        Folder ID to search in.
     * @type string $accountId       Account ID to search in.
     * @type string $modifiedAfter   Date after which to search.
     *              }
     *
     * @return array|WP_Error An array of file objects.
     */
    public function search( $data ) {
        $data = wp_parse_args( $data, [
            'from'          => 'cache',
            'scope'         => 'parent',
            'types'         => ['all'],
            'limit'         => 100,
            'query'         => '',
            'orderBy'       => 'name',
            'order'         => 'ASC',
            'fullText'      => false,
            'trashed'       => false,
            'folderId'      => null,
            'accountId'     => null,
            'modifiedAfter' => null,
        ] );
        $query = trim( $data['query'] );
        if ( $query === '' ) {
            return [];
        }
        $data['scope'] = ( in_array( $data['scope'], ['parent', 'global'] ) ? $data['scope'] : 'parent' );
        $data['from'] = ( in_array( $data['from'], ['cache', 'server'] ) ? $data['from'] : 'cache' );
        if ( $data['from'] === 'cache' ) {
            return Files::getInstance()->search( $data );
        } elseif ( $data['from'] === 'server' ) {
            $fullText = filter_var( $data['fullText'], FILTER_VALIDATE_BOOLEAN );
            $mimeTypes = ccpigdGetMimeTypesByGroup( $data['types'] );
            $params = [
                'query'         => $query,
                'fullText'      => $fullText,
                'mimeTypes'     => $mimeTypes,
                'parent'        => $data['folderId'],
                'trashed'       => false,
                'modifiedAfter' => $data['modifiedAfter'],
            ];
            if ( $data['scope'] === 'global' && current_user_can( CCPIGD_ACCESS_CAP ) ) {
                $params['parent'] = null;
            }
            $searchQuery = $this->buildSearchQuery( $params );
            $files = $this->fetchFilesFromServer( [
                'accountId' => $data['accountId'],
                'id'        => $data['folderId'],
                'query'     => $searchQuery,
            ] );
            if ( is_wp_error( $files ) ) {
                return $files;
            }
            return Files::getInstance()->search( $data );
        }
        return [];
    }

    // ================================== Shortcode Methods ================================
    public function getFilesByShortcode( $id, $args = [] ) {
        // Prepare the arguments for the App methods
    }

    public function getFilesByKeys( $fileKeys, $queryConfig = [] ) {
        if ( empty( $fileKeys ) ) {
            return new WP_Error(404, __( 'No file keys provided.', 'integration-google-drive' ));
        }
        if ( !is_array( $fileKeys ) ) {
            return new WP_Error(400, __( 'File keys must be an array.', 'integration-google-drive' ));
        }
        $queryConfig = wp_parse_args( $queryConfig, [
            'returnType'  => 'array',
            'recursive'   => true,
            'page'        => 1,
            'perPage'     => 20,
            'orderBy'     => 'createdAt',
            'order'       => 'DESC',
            'search'      => '',
            'searchScope' => 'folder',
            'from'        => 'cache',
        ] );
        $keys = array_column( $fileKeys, 'key' );
        $filesModel = Files::getInstance();
        if ( isset( $queryConfig['from'] ) && $queryConfig['from'] === 'server' ) {
            $filesData = $filesModel->getFileAttributesByKeys( $keys, ['id', 'accountId', 'extension'] );
            if ( is_wp_error( $filesData ) ) {
                return $filesData;
            }
            if ( empty( $filesData ) ) {
                return new WP_Error(404, __( 'No files found.', 'integration-google-drive' ));
            }
            $filterFolderIds = array_filter( $filesData, fn( $file ) => !empty( $file['extension'] ) && $file['extension'] === 'folder' );
            $searchQuery = '';
            if ( !empty( $queryConfig['search'] ) ) {
                $params = [
                    'query'    => $queryConfig['search'],
                    'fullText' => false,
                    'trashed'  => false,
                ];
                $searchQuery = $this->buildSearchQuery( $params );
            }
            foreach ( $filterFolderIds as $file ) {
                $this->fetchFilesFromServer( [
                    'accountId' => $file['accountId'],
                    'id'        => $file['id'],
                    'query'     => $searchQuery,
                ] );
            }
        }
        $filesData = $filesModel->getFilesByKeys( $keys, $queryConfig );
        // TODO: Need to handle thumbnails if required
        // foreach ($fileKeys as $key) {
        //     $thumbnailKey = $key['thumbnailKey'] ?? '';
        //     if (!empty($thumbnailKey)) {
        //         $thumbnails = $filesModel->getFileByKey($thumbnailKey, 'array');
        //         if (!is_wp_error($thumbnails) && !empty($thumbnails)) {
        //             $file['thumbnail'] = $thumbnails;
        //         }
        //     }
        //     $files[] = $file;
        // }
        return $filesData;
    }

    public function checkShortcodePermissions( int $shortcodeId, string $action, array $additionalConditions = [] ) : bool {
        if ( empty( $shortcodeId ) || empty( $action ) ) {
            return false;
        }
        $shortcode = Shortcode::getInstance()->getShortcode( $shortcodeId, ['permissions', 'type'] );
        if ( is_wp_error( $shortcode ) ) {
            return false;
        }
        $permissions = $shortcode['permissions'] ?? [];
        $type = $shortcode['type'] ?? '';
        if ( empty( $type ) ) {
            return false;
        }
        if ( $type === 'file-uploader' && $action === 'upload' ) {
            return true;
        }
        if ( !empty( $permissions['passwordProtect']['enable'] ) ) {
            $cookie_token_key = "ccpigd_token_{$shortcodeId}";
            $cookie_token = ( isset( $_COOKIE[$cookie_token_key] ) ? sanitize_text_field( wp_unslash( $_COOKIE[$cookie_token_key] ) ) : '' );
            if ( empty( $cookie_token ) ) {
                return false;
            }
            $password = $permissions['passwordProtect']['password'] ?? '';
            if ( !hash_equals( $cookie_token, hash( 'sha256', $password ) ) ) {
                return false;
            }
        }
        if ( !isset( $permissions[$action] ) ) {
            return false;
        }
        if ( !isset( $permissions[$action]['enable'] ) ) {
            return false;
        }
        if ( empty( $permissions[$action]['enable'] ) ) {
            return false;
        }
        $permission = $permissions[$action] ?? null;
        if ( empty( $permission ) ) {
            return false;
        }
        if ( !empty( $additionalConditions ) ) {
            foreach ( $additionalConditions as $additionalCondition ) {
                if ( !isset( $permission[$additionalCondition] ) || $permission[$additionalCondition] !== true ) {
                    return false;
                }
            }
        }
        if ( empty( $permission['userAccess'] ) ) {
            return false;
        }
        if ( $permission['userAccess'] === 'everyone' ) {
            return true;
        }
        if ( !is_user_logged_in() ) {
            return false;
        }
        if ( empty( $permission['displayFor'] ) ) {
            return true;
        }
        $currentUser = wp_get_current_user();
        if ( empty( $currentUser->ID ) ) {
            return false;
        }
        $userId = $currentUser->ID;
        $loggedInUserType = $permission['loggedInUserType'] ?? 'users';
        if ( 'users' === $loggedInUserType ) {
            return in_array( $userId, $permission['displayFor'], true );
        }
        if ( 'roles' === $loggedInUserType ) {
            $userRoles = $currentUser->roles;
            if ( empty( $userRoles ) ) {
                return false;
            }
            foreach ( $userRoles as $role ) {
                if ( in_array( $role, $permission['displayFor'], true ) ) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    // ================================== PRIVATE METHODS ==================================
    /**
     * Prepares the search response by filtering and limiting the files based on the specified criteria.
     *
     * @param array $files The list of files retrieved from the server.
     * @param array $data The search parameters including:
     *                    - 'limit': The maximum number of files to return.
     *                    - 'types': The types of files to filter by.
     *
     * @return array The filtered and limited list of files based on specified types and limit.
     */
    private function prepareSearchResponse( array $files, array $data ) : array {
        $limit = ( isset( $data['limit'] ) ? (int) $data['limit'] : 100 );
        $types = $data['types'] ?? ['all'];
        $extensions = ccpigdGetExtensionGroups( $types );
        if ( empty( $extensions ) ) {
            return array_slice( $files, 0, $limit );
        }
        $filteredFiles = [];
        foreach ( $files as $file ) {
            $extension = $file['extension'] ?? null;
            if ( $extension && in_array( $extension, $extensions, true ) ) {
                $filteredFiles[] = $file;
                if ( count( $filteredFiles ) >= $limit ) {
                    break;
                }
            }
        }
        return $filteredFiles;
    }

    /**
     * Prepares the arguments for the App methods by merging the input arguments with the default arguments.
     *
     * @param array $args The input arguments.
     *
     * @return array The prepared arguments.
     */
    private function prepareArgs( $args ) {
        $defaultArgs = [
            'from'        => 'cache',
            'order'       => 'ASC',
            'orderBy'     => "folder,name",
            'filters'     => [],
            'limit'       => 0,
            'fileNumbers' => 0,
        ];
        $args = wp_parse_args( $args, $defaultArgs );
        return $args;
    }

    /**
     * Fetches files from the server using the provided arguments.
     *
     * This function retrieves files from the Google Drive API based on the specified
     * folder ID and account ID. If the folder ID is 'shared-drives', it lists the shared
     * drives instead. It also removes stale files from the database after fetching.
     *
     * @param array $args An associative array of arguments, including:
     *                    - 'id': The folder ID to fetch files from.
     *                    - 'accountId': The account ID associated with the files.
     *
     * @return array|WP_Error The list of files retrieved from the server or an empty array if
     *                        the folder ID or account ID is not provided, or if no files are found.
     */
    private function fetchFilesFromServer( $args ) {
        $folderId = $args['id'] ?? null;
        $accountId = $args['accountId'] ?? null;
        if ( empty( $folderId ) || empty( $accountId ) ) {
            return [];
        }
        if ( $folderId === 'shared-drives' ) {
            $files = $this->files->listDrives();
            return $files;
        }
        $params = $this->buildServerParams( $args, $folderId );
        $files = $this->files->listFiles( $params );
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        if ( empty( $files ) ) {
            return [];
        }
        if ( empty( $args['query'] ) ) {
            $this->removeStaleFilesFromDatabase( $files, $args );
        }
        return $this->fetchFilesFromCache( $args );
    }

    private function buildServerParams( $args, $folderId ) {
        $query = "trashed=false";
        switch ( true ) {
            case !empty( $args['query'] ):
                $query = $args['query'];
                break;
            case $folderId === 'computers':
                $query = "'me' in owners and mimeType='application/vnd.google-apps.folder' and trashed=false";
                break;
            case $folderId === 'shared':
                $query = "sharedWithMe=true and trashed=false";
                break;
            case $folderId === 'starred':
                $query = "starred=true and trashed=false";
                break;
            default:
                $query .= " and '{$folderId}' in parents";
                break;
        }
        $replaceOrderBy = [
            'name'      => 'folder,name',
            'size'      => 'folder,quotaBytesUsed',
            'createdAt' => 'folder,createdTime',
            'updatedAt' => 'folder,modifiedTime',
        ];
        $requestedField = $args['orderBy'] ?? 'name';
        $sortDirection = ( strtolower( $args['order'] ?? 'asc' ) === 'desc' ? 'desc' : 'asc' );
        $mappedField = $replaceOrderBy[$requestedField] ?? $requestedField;
        $mappedFields = explode( ',', $mappedField );
        $orderByParts = [];
        foreach ( $mappedFields as $field ) {
            $field = trim( $field );
            if ( in_array( $field, ['folder'] ) ) {
                $orderByParts[] = $field;
            } elseif ( in_array( $field, [
                'name',
                'name_natural',
                'createdTime',
                'modifiedTime',
                'quotaBytesUsed'
            ] ) ) {
                $orderByParts[] = "{$field} {$sortDirection}";
            }
        }
        $orderBy = implode( ',', $orderByParts );
        $params = [
            'fields'                    => CCPIGD_LIST_FIELDS,
            'pageSize'                  => 300,
            'orderBy'                   => ( $orderBy ?: 'folder,name' ),
            'pageToken'                 => '',
            'supportsAllDrives'         => true,
            'includeItemsFromAllDrives' => true,
            'corpora'                   => 'allDrives',
            'q'                         => $query,
        ];
        return $params;
    }

    private function fetchFilesFromCache( $args ) {
        return Files::getInstance()->getFolder( $args['id'], $args['accountId'], $args );
    }

    private function filterAndSortFiles( $files, $args ) {
        if ( $args['from'] == 'server' ) {
            $order = strtoupper( $args['order'] ?? 'ASC' );
            $orderBy = $args['orderBy'] ?? "folder,name";
            usort( $files, fn( $a, $b ) => ( $order === 'ASC' ? $a->{$orderBy} <=> $b->{$orderBy} : $b->{$orderBy} <=> $a->{$orderBy} ) );
        }
        return $files;
    }

    private function prepareResponse( $files, $args ) {
        $folderId = $args['id'] ?? null;
        $accountId = $args['accountId'] ?? null;
        $page = $args['page'] ?? 1;
        $perPage = $args['perPage'] ?? 10;
        if ( empty( $folderId ) || empty( $accountId ) ) {
            return [];
        }
        if ( $args['from'] == 'server' ) {
            $files = array_slice( $files, ($page - 1) * $perPage, $perPage );
        }
        $totalFiles = Files::getInstance()->childrenCount( $folderId, $accountId );
        $totalPages = ceil( 100 / $perPage );
        $hasMore = $page < $totalPages;
        $filteredFiles = array_filter( $files, fn( $file ) => $file['parentId'] === $folderId || $folderId === 'starred' );
        $response = [
            'files'       => array_values( $filteredFiles ),
            'hasMore'     => (bool) $hasMore,
            'totalFiles'  => intval( $totalFiles ),
            'totalPages'  => intval( $totalPages ),
            'currentPage' => intval( $page ),
        ];
        if ( $hasMore ) {
            $response['nextPage'] = $page + 1;
        }
        return $response;
    }

    private function prepareApiFiles( $accountId ) {
        $this->accountId = $accountId;
        $this->client = Client::getInstance( $accountId );
        $this->files = new APIFiles($this->client);
    }

    private function getDataByKey( $key, $type ) {
        $folderId = null;
        $accountId = null;
        $folder = null;
        if ( in_array( $type, [
            'my-drive',
            'shared',
            'starred',
            'computers',
            'shared-drives'
        ] ) ) {
            $account = Accounts::getInstance()->getAccountByKey( $key );
            if ( empty( $account ) || is_wp_error( $account ) ) {
                return false;
            }
            $accountId = $account->getId();
            $folderId = ( $type === 'my-drive' ? $account->getRootId() : $type );
        } elseif ( $type === 'folder' ) {
            $folder = $this->getFileByKey( $key );
            if ( empty( $folder ) || is_wp_error( $folder ) ) {
                return false;
            }
            $folderId = $folder['id'] ?? null;
            $accountId = $folder['accountId'] ?? null;
        } else {
            return [false, false, false];
        }
        return [
            'folderId'  => $folderId,
            'accountId' => $accountId,
            'folder'    => $folder,
        ];
    }

    /**
     * Generates a permission for the given file, if the file is not already shared.
     *
     * @param File $file The file to generate a permission for.
     *
     * @return bool|WP_Error True if the permission was generated successfully, false if the file is already shared,
     *                       or a WP_Error if the permission generation failed.
     */
    private function generatePermission( File $file ) {
        $users = $file->getPermission( 'users' ) ?? [];
        if ( isset( $users['anyoneWithLink']['type'] ) && $users['anyoneWithLink']['type'] === 'anyone' ) {
            return true;
        }
        $permission = new Permission($this->client);
        $isShared = $permission->isShared( $file );
        if ( is_wp_error( $isShared ) ) {
            return $isShared;
        }
        $domain = Helpers::getSetting( 'advanced.googleWorkspaceDomain', false );
        $isSharingPermission = Helpers::getSetting( 'advanced.sharingPermission', true );
        if ( $isShared ) {
            $users['anyoneWithLink'] = [
                'domain' => $domain,
                'role'   => "reader",
                'type'   => "anyone",
            ];
            $file->setPermission( 'users', $users );
            $file->save();
            return true;
        } elseif ( $isSharingPermission ) {
            $getPermission = $permission->cratePermission( $file->getId(), [
                'domain' => $domain,
                'type'   => ( $domain ? 'domain' : 'anyone' ),
                'role'   => 'reader',
            ] );
            if ( empty( $getPermission ) || is_wp_error( $getPermission ) ) {
                return new WP_Error(401, __( 'Unable to create a permission for this file. Please try again.', 'integration-google-drive' ));
            }
            $users[$getPermission->getId()] = [
                'type'   => $getPermission->getType(),
                'role'   => $getPermission->getRole(),
                'domain' => $getPermission->getDomain(),
            ];
            $file->setPermission( 'users', $users );
            $file->save();
            return true;
        }
        return new WP_Error(401, __( 'Failed to create preview/share due to insufficient permissions. Please enable the required access in Google Drive or in the plugin by going to Settings → Advanced → Manage Sharing Permissions.', 'integration-google-drive' ));
    }

    /**
     * Builds a Google Drive search query from the given parameters.
     *
     * @param array $params The parameters to build the search query from.
     *                      The following keys are supported:
     *                      - query: The search query string.
     *                      - fullText: Whether to search the full text of the file.
     *                      - mimeTypes: An array of MIME types to filter by.
     *                      - parent: The ID of the parent folder to search in.
     *                      - trashed: Whether to include trashed files.
     *                      - modifiedAfter: A timestamp (in RFC 3339 format) to filter by.
     *                      - sharedWithMe: Whether to only return files shared with the current user.
     * @return string The search query string.
     */
    private function buildSearchQuery( array $params ) : string {
        $queryParts = [];
        if ( !empty( $params['query'] ) ) {
            $search = addslashes( $params['query'] );
            $queryParts[] = ( !empty( $params['fullText'] ) ? "fullText contains '{$search}'" : "name contains '{$search}'" );
        }
        if ( !empty( $params['mimeTypes'] ) && is_array( $params['mimeTypes'] ) ) {
            $mimeQueries = array_map( fn( $type ) => "mimeType = '{$type}'", $params['mimeTypes'] );
            $queryParts[] = '(' . implode( ' or ', $mimeQueries ) . ')';
        }
        if ( !empty( $params['parent'] ) ) {
            $queryParts[] = "'{$params['parent']}' in parents";
        }
        $queryParts[] = ( isset( $params['trashed'] ) && $params['trashed'] === true ? "trashed = true" : "trashed = false" );
        if ( !empty( $params['modifiedAfter'] ) ) {
            $queryParts[] = "modifiedTime > '{$params['modifiedAfter']}'";
        }
        if ( !empty( $params['sharedWithMe'] ) ) {
            $queryParts[] = "sharedWithMe";
        }
        return implode( ' and ', $queryParts );
    }

    /**
     * Removes stale files from the database.
     *
     * Given a list of files retrieved from the server and a set of query arguments,
     * this function will remove any files from the database that do not exist in the
     * provided list of files or do not match the query arguments.
     *
     * @param array $currentFiles The list of files retrieved from the server.
     * @param array $queryArgs The query arguments used to retrieve the files.
     */
    private function removeStaleFilesFromDatabase( array $currentFiles, array $queryArgs ) : void {
        $cachedFiles = $this->fetchFilesFromCache( $queryArgs );
        $currentFileIds = array_column( $currentFiles, 'id' );
        foreach ( $cachedFiles as $file ) {
            if ( !in_array( $file['id'], $currentFileIds, true ) ) {
                $files = Files::getInstance()->deleteFile( $file['id'], $file['accountId'] );
                if ( is_wp_error( $files ) ) {
                    continue;
                }
            }
        }
    }

    private function generateShortcodeUrl( array $args ) : string {
        $fileId = $args['fileId'] ?? null;
        $fileKey = $args['key'] ?? null;
        $isPasswordProtected = false;
        $shortcodeId = null;
        $password = '';
        $referer = $args['referer'] ?? '';
        $isAdmin = $args['isAdmin'] ?? false;
        $lifetime = 1;
        if ( empty( $fileId ) || empty( $fileKey ) ) {
            return false;
        }
        $key = bin2hex( random_bytes( 8 ) );
        $transientKey = "ccpigd_share_{$key}";
        $transientLifeTime = ( $lifetime === 'unlimited' ? 0 : intval( $lifetime ?? 1 ) * HOUR_IN_SECONDS );
        $params = [
            'fileKey'             => $fileKey,
            'isPasswordProtected' => $isPasswordProtected,
            'shortcodeId'         => $shortcodeId,
            'referer'             => $referer,
            'isAdmin'             => $isAdmin,
            'lifetime'            => $lifetime,
            'key'                 => $key,
        ];
        set_transient( $transientKey, serialize( $params ), $transientLifeTime );
        $filterParams = array_filter( $params );
        $jsonParams = wp_json_encode( $filterParams );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return false;
        }
        $encodedParams = Helpers::encode( $jsonParams );
        return add_query_arg( [
            'ccpigd-share' => $encodedParams,
        ], home_url() );
    }

    private function checkMediaLibraryPermissions() : bool {
        return current_user_can( 'manage_options' );
    }

}
