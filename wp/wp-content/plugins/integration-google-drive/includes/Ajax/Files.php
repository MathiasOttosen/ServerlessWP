<?php

namespace CodeConfig\IGD\Ajax;

use CodeConfig\IGD\App\App;
use CodeConfig\IGD\Notifications;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class Files extends BaseAjax {
    public static function get() : void {
        $data = self::getRequestData( ['key', 'from'] );
        try {
            $file = App::getInstance()->getFileByKey( $data['key'], $data['from'] === 'server' );
            if ( is_wp_error( $file ) || empty( $file ) ) {
                self::sendError( __( 'The requested file could not be found.', 'integration-google-drive' ), 404 );
            }
            self::sendSuccess( [
                'file' => $file,
            ], __( 'File retrieved successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'An error occurred while fetching the file.', 'integration-google-drive' ) );
        }
    }

    public static function getFolder() : void {
        $data = self::getRequestData( ['key', 'type', 'from'] );
        // Apply defaults
        $data = wp_parse_args( $data, [
            'type'    => 'folder',
            'from'    => 'cache',
            'page'    => '1',
            'perPage' => '24',
            'order'   => 'ASC',
            'orderBy' => 'name',
        ] );
        try {
            $app = App::getInstance();
            // Get files and breadcrumbs in parallel to reduce API calls
            $files = $app->getFolderByKey( $data );
            if ( is_wp_error( $files ) ) {
                self::handleError( $files, __( 'Failed to retrieve folder contents.', 'integration-google-drive' ) );
                return;
            }
            $breadcrumbs = $app->getBreadcrumbByKey( $data['key'], $data['type'] );
            if ( is_wp_error( $breadcrumbs ) ) {
                self::handleError( $breadcrumbs, __( 'Failed to retrieve breadcrumbs.', 'integration-google-drive' ) );
                return;
            }
            $response = [
                'files'       => $files['files'] ?? [],
                'breadcrumbs' => array_reverse( $breadcrumbs ),
                'hasMore'     => $files['hasMore'] ?? false,
                'totalFiles'  => $files['totalFiles'] ?? 0,
                'totalPages'  => $files['totalPages'] ?? 0,
                'message'     => __( 'Files fetched successfully.', 'integration-google-drive' ),
            ];
            $hasMore = $response['hasMore'];
            if ( $hasMore ) {
                $response['nextPage'] = $files['nextPage'] ?? 1;
            }
            self::sendSuccess( $response, __( 'Folder fetched successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to retrieve folder contents.', 'integration-google-drive' ) );
        }
    }

    public static function getFolders() : void {
        $data = self::getRequestData( ['key'] );
        try {
            $folders = App::getInstance()->getFolderByKey( $data );
            if ( is_wp_error( $folders ) ) {
                self::handleError( $folders, __( 'Failed to retrieve folders.', 'integration-google-drive' ) );
                return;
            }
            // Extract only folder items if we have files array
            if ( isset( $folders['files'] ) ) {
                $folderItems = array_filter( $folders['files'], fn( $file ) => $file['mimeType'] === 'application/vnd.google-apps.folder' );
                self::sendSuccess( [
                    'folders' => array_values( $folderItems ),
                ], __( 'Folders retrieved successfully.', 'integration-google-drive' ) );
            } else {
                self::sendSuccess( [
                    'folders' => $folders,
                ], __( 'Folders retrieved successfully.', 'integration-google-drive' ) );
            }
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to retrieve folders.', 'integration-google-drive' ) );
        }
    }

    public static function delete() : void {
        $data = self::getRequestData( [
            'fileKeys' => ['id'],
        ] );
        if ( empty( $data['accountId'] ) || empty( $data['fileIds'] ) ) {
            self::sendError( __( 'Invalid request. Missing account or file information.', 'integration-google-drive' ), 400 );
        }
        try {
            $response = App::getInstance( $data['accountId'] )->delete( $data['fileIds'] );
            if ( is_wp_error( $response ) || empty( $response ) ) {
                self::sendError( __( 'Deletion failed. No files were removed.', 'integration-google-drive' ), 400 );
            }
            self::sendSuccess( [
                'response' => $response,
            ], __( 'Files deleted successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'An error occurred while deleting the selected files.', 'integration-google-drive' ) );
        }
    }

    public static function getResumeUploadUrl() : void {
        $data = self::getRequestData( [
            'folderKey' => 'folderId',
            'name',
            'type',
        ] );
        if ( empty( $data['accountId'] ) || empty( $data['folderId'] ) ) {
            self::sendError( __( 'Invalid data provided: account ID or folder ID missing.', 'integration-google-drive' ), 400 );
        }
        try {
            $resumableUploadData = App::getInstance( $data['accountId'] )->upload( $data );
            if ( is_wp_error( $resumableUploadData ) || empty( $resumableUploadData['url'] ) ) {
                self::sendError( __( 'Failed to generate upload URL.', 'integration-google-drive' ), 500 );
            }
            self::sendSuccess( $resumableUploadData, __( 'Upload URL generated successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to generate upload URL.', 'integration-google-drive' ) );
        }
    }

    public static function uploadedFiles() : void {
        self::verifyRequest();
        $data = self::getRequestData( ['id', 'uploadId', 'folderKey' => 'folderId'] );
        if ( empty( $data['id'] ) || empty( $data['accountId'] ) || empty( $data['uploadId'] ) || empty( $data['folderId'] ) ) {
            self::sendError( __( 'File ID not found.', 'integration-google-drive' ), 400 );
        }
        $id = sanitize_text_field( wp_unslash( $data['id'] ) );
        $folderId = sanitize_text_field( wp_unslash( $data['folderId'] ) );
        $uploadId = sanitize_text_field( wp_unslash( $data['uploadId'] ) );
        $accountId = sanitize_text_field( wp_unslash( $data['accountId'] ) );
        $transientKey = "ccpigd-upload-id-{$uploadId}";
        $transientId = get_transient( $transientKey );
        $shortcodeId = sanitize_key( wp_unslash( $data['shortcodeId'] ?? '' ) );
        if ( $folderId !== $transientId ) {
            self::sendError( __( 'Invalid folder ID.', 'integration-google-drive' ), 400 );
        }
        $getFile = App::getInstance( $accountId )->getFile( $id, $accountId, true );
        if ( !empty( $shortcodeId ) && !empty( $getFile['key'] ) ) {
            Notifications::getInstance()->notify( 'upload', $shortcodeId, $getFile['key'] );
        }
        if ( is_wp_error( $getFile ) ) {
            self::sendError( $getFile->get_error_message(), 400 );
        }
        if ( $folderId !== $getFile['parentId'] || $getFile['parentId'] !== $transientId ) {
            self::sendError( __( 'Folder ID mismatch.', 'integration-google-drive' ), 400 );
        }
        delete_transient( $transientKey );
        self::sendSuccess( [
            'file' => $getFile,
        ], __( 'File saved successfully.', 'integration-google-drive' ) );
    }

    public static function updateDescription() : void {
        $data = self::getRequestData( [
            'fileKey' => 'fileId',
            'description',
        ] );
        if ( empty( $data['accountId'] ) || empty( $data['fileId'] ) || !isset( $data['description'] ) ) {
            self::sendError( __( 'Invalid data provided.', 'integration-google-drive' ), 400 );
        }
        $args = [
            'description' => $data['description'],
            'fileId'      => $data['fileId'],
        ];
        try {
            $response = App::getInstance( $data['accountId'] )->updateDescription( $args );
            if ( is_wp_error( $response ) || empty( $response ) ) {
                self::sendError( __( 'Failed to update description.', 'integration-google-drive' ), 500 );
            }
            self::sendSuccess( [
                'response' => $response,
            ], __( 'Description updated successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to update description.', 'integration-google-drive' ) );
        }
    }

    public static function preview() : void {
        $data = self::getRequestData( [
            'fileKey' => 'fileId',
        ] );
        if ( empty( $data['accountId'] ) || empty( $data['fileId'] ) ) {
            self::sendError( __( 'Invalid data provided.', 'integration-google-drive' ), 400 );
        }
        try {
            $preview = App::getInstance( $data['accountId'] )->preview( $data );
            if ( is_wp_error( $preview ) || empty( $preview ) ) {
                self::sendError( __( 'Failed to preview file.', 'integration-google-drive' ), 500 );
            }
            self::sendSuccess( [
                'preview' => $preview,
            ], __( 'File previewed successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to generate file preview.', 'integration-google-drive' ) );
        }
    }

    public static function share() : void {
        $data = self::getRequestData( [
            'fileKey' => 'fileId',
        ] );
        if ( empty( $data['accountId'] ) || empty( $data['fileId'] ) ) {
            self::sendError( __( 'Invalid data provided.', 'integration-google-drive' ), 400 );
        }
        try {
            $args = [
                'lifetime'            => 1,
                'shortcodeId'         => null,
                'password'            => null,
                'isPasswordProtected' => false,
                'key'                 => $data['fileKey'],
                'fileId'              => $data['fileId'],
                'referer'             => wp_get_referer(),
                'isAdmin'             => strpos( wp_get_referer() ?? '', admin_url() ) !== false,
            ];
            $share = App::getInstance( $data['accountId'] )->shareLink( $args );
            if ( is_wp_error( $share ) ) {
                self::sendError( $share->get_error_message(), 401 );
            }
            if ( empty( $share ) ) {
                self::sendError( __( 'Failed to share file.', 'integration-google-drive' ), 500 );
            }
            self::sendSuccess( [
                'share' => $share,
            ], __( 'File shared successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to share file.', 'integration-google-drive' ) );
        }
    }

    public static function download() : void {
        $data = self::getRequestData( [
            'fileKey' => 'fileId',
        ] );
        if ( empty( $data['accountId'] ) || empty( $data['fileId'] ) ) {
            self::sendError( __( 'Invalid data provided.', 'integration-google-drive' ), 400 );
        }
        try {
            $download = App::getInstance( $data['accountId'] )->download( $data['fileId'] );
            if ( is_wp_error( $download ) || empty( $download ) ) {
                self::sendError( __( 'Failed to download file.', 'integration-google-drive' ), 500 );
            }
            self::sendSuccess( [
                'download' => $download,
            ], __( 'File downloaded successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to download file.', 'integration-google-drive' ) );
        }
    }

    public static function rename() : void {
        $data = self::getRequestData( [
            'fileKey' => 'fileId',
            'name',
        ] );
        if ( empty( $data['accountId'] ) || empty( $data['fileId'] ) || empty( $data['name'] ) ) {
            self::sendError( __( 'Invalid data provided.', 'integration-google-drive' ), 400 );
        }
        try {
            $rename = App::getInstance( $data['accountId'] )->rename( $data );
            if ( is_wp_error( $rename ) || empty( $rename ) ) {
                self::sendError( __( 'Failed to rename file.', 'integration-google-drive' ), 500 );
            }
            self::sendSuccess( [
                'rename' => $rename,
            ], __( 'File renamed successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to rename file.', 'integration-google-drive' ) );
        }
    }

    public static function newFolder() : void {
        $data = self::getRequestData( [
            'parentKey' => 'parentId',
            'folderName',
        ] );
        if ( empty( $data['accountId'] ) || empty( $data['parentId'] ) || empty( $data['folderName'] ) ) {
            self::sendError( __( 'Invalid data provided.', 'integration-google-drive' ), 400 );
        }
        try {
            $folder = App::getInstance( $data['accountId'] )->newFolder( $data );
            if ( empty( $folder ) ) {
                self::sendError( __( 'Failed to create folder.', 'integration-google-drive' ), 500 );
            }
            self::sendSuccess( [
                'folder' => $folder,
            ], __( 'Folder created successfully.', 'integration-google-drive' ) );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to create folder.', 'integration-google-drive' ) );
        }
    }

    public static function search() : void {
        $data = self::getRequestData( [
            'folderKey' => 'folderId',
            'query',
        ] );
        if ( empty( $data['accountId'] ) || empty( $data['folderId'] ) ) {
            self::sendError( __( 'Invalid data provided.', 'integration-google-drive' ), 400 );
        }
        try {
            $result = App::getInstance( $data['accountId'] )->search( $data );
            if ( is_wp_error( $result ) ) {
                self::handleError( $result, __( 'Failed to search files.', 'integration-google-drive' ) );
            }
            self::sendSuccess( [
                'files' => $result,
            ] );
        } catch ( \Exception $e ) {
            self::handleError( $e, __( 'Failed to search files.', 'integration-google-drive' ) );
        }
    }

}
