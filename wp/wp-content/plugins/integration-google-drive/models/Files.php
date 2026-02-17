<?php

namespace CodeConfig\IGD\Models;

use CodeConfig\IGD\Utils\Singleton;
use function count;
use function in_array;
use WP_Error;
class Files extends BaseModel {
    use Singleton;
    public function __construct() {
        parent::__construct( 'integration_google_drive_files' );
    }

    /**
     * Retrieves a list of files from the specified folder and account.
     *
     * @param string $rootId The ID of the root folder to retrieve files from.
     * @param string $accountId The ID of the account associated with the files.
     * @param array $config Optional configuration settings for retrieving files.
     *
     * @return array|null|WP_Error An array of processed file data from the specified folder.
     */
    public function getFolder( $rootId, $accountId, $config = [] ) {
        if ( $this->isValidAccount( $accountId ) === false ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $allowedOrderBy = [
            'createdAt',
            'name',
            'updatedAt',
            'size'
        ];
        $order = $this->sanitizeOrder( ( isset( $config['order'] ) ? $config['order'] : 'DESC' ) );
        $orderBy = $this->sanitizeOrderBy( ( isset( $config['orderBy'] ) ? $config['orderBy'] : 'createdAt' ), $allowedOrderBy );
        $page = ( isset( $config['page'] ) ? (int) $config['page'] : 1 );
        $perPage = ( isset( $config['perPage'] ) ? (int) $config['perPage'] : 24 );
        $pagination = $this->sanitizePagination( $page, $perPage );
        $files = $this->fetchAll( "SELECT * FROM %i WHERE parentId = %s AND accountId = %s ORDER BY (CASE WHEN extension = 'folder' THEN 0 ELSE 1 END), `{$orderBy}` {$order} LIMIT %d OFFSET %d", [
            $this->tableName,
            $rootId,
            $accountId,
            $pagination['perPage'],
            $pagination['offset']
        ] );
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        return $this->processFiles( $files );
    }

    public function search( array $data ) {
        $accountId = ( isset( $data['accountId'] ) ? $data['accountId'] : '' );
        $searchQuery = ( isset( $data['query'] ) ? $data['query'] : '' );
        $types = ( isset( $data['types'] ) ? $data['types'] : ['all'] );
        $limit = ( isset( $data['limit'] ) ? (int) $data['limit'] : 100 );
        $order = ( isset( $data['order'] ) ? $data['order'] : 'ASC' );
        $orderBy = ( isset( $data['orderBy'] ) ? $data['orderBy'] : 'name' );
        $folderId = ( isset( $data['folderId'] ) ? $data['folderId'] : '' );
        $scope = ( isset( $data['scope'] ) && in_array( $data['scope'], ['parent', 'global'] ) ? $data['scope'] : 'parent' );
        if ( !$this->isValidAccount( $accountId ) ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        if ( empty( $searchQuery ) || empty( $accountId ) ) {
            return new WP_Error(404, __( 'The requested file could not be found.', 'integration-google-drive' ));
        }
        $allowedOrderBy = [
            'name',
            'createdAt',
            'updatedAt',
            'size'
        ];
        $orderBy = $this->sanitizeOrderBy( $orderBy, $allowedOrderBy );
        $order = $this->sanitizeOrder( $order );
        $limit = max( 1, min( 1000, $limit ) );
        // Limit to prevent memory issues
        $extensions = ccpigdGetExtensionGroups( $types );
        $queryString = "SELECT * FROM %i WHERE name LIKE %s AND accountId = %s";
        $values = [$this->tableName, "%{$searchQuery}%", $accountId];
        if ( !empty( $extensions ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $extensions ), '%s' ) );
            $queryString .= " AND extension IN ({$placeholders})";
            $values = array_merge( $values, $extensions );
        }
        if ( $scope === 'parent' && !empty( $folderId ) ) {
            $queryString .= " AND parentId = %s";
            $values[] = $folderId;
        }
        $queryString .= " ORDER BY `{$orderBy}` {$order} LIMIT %d";
        $values[] = $limit;
        $files = $this->fetchAll( $queryString, $values );
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        return $this->processFiles( $files );
    }

    /**
     * Retrieves a list of all files associated with the specified account ID.
     *
     * @param string $accountId The ID of the account associated with the files.
     * @param array $config Optional configuration settings for retrieving files.
     *
     * @return array|WP_Error An array of processed file data associated with the specified account.
     */
    public function getFilesByAccountId( $accountId, $config = [] ) {
        if ( $this->isValidAccount( $accountId ) === false ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $allowedOrderBy = [
            'createdAt',
            'name',
            'updatedAt',
            'size'
        ];
        $order = $this->sanitizeOrder( ( isset( $config['order'] ) ? $config['order'] : 'DESC' ) );
        $orderBy = $this->sanitizeOrderBy( ( isset( $config['orderBy'] ) ? $config['orderBy'] : 'createdAt' ), $allowedOrderBy );
        $page = ( isset( $config['page'] ) ? (int) $config['page'] : 1 );
        $perPage = ( isset( $config['perPage'] ) ? (int) $config['perPage'] : 24 );
        $pagination = $this->sanitizePagination( $page, $perPage );
        $files = $this->fetchAll( "SELECT * FROM %i WHERE accountId = %s ORDER BY `{$orderBy}` {$order} LIMIT %d OFFSET %d", [
            $this->tableName,
            $accountId,
            $pagination['perPage'],
            $pagination['offset']
        ] );
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        return $this->processFiles( $files );
    }

    /**
     * Retrieves a file by its ID and account ID.
     *
     * This method queries the database for a file associated with the given
     * ID and account ID. If a matching file is found, it processes and returns
     * the file data. If no file is found, an error notice is added and null is
     * returned.
     *
     * @param string $id The ID of the file to retrieve.
     * @param string $accountId The ID of the account associated with the file.
     *
     * @return \CodeConfig\IGD\App\File|array|WP_Error The processed file data if found, otherwise null.
     */
    public function getFile( $id, $accountId, $returnType = 'object' ) {
        global $wpdb;
        if ( $this->isValidAccount( $accountId ) === false ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $file = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM %i WHERE id = %s AND accountId = %s",
            $this->tableName,
            $id,
            $accountId
        ) );
        if ( empty( $file ) ) {
            return new WP_Error(404, __( 'The requested file could not be found.', 'integration-google-drive' ));
        }
        return $this->processFile( $file, $returnType );
    }

    /**
     * Retrieves a file by its unique key.
     *
     * This method queries the database for a file associated with the given key.
     * If a matching file is found, it processes and returns the file data.
     * If no file is found, an error notice is added and null is returned.
     *
     * @param string $key The unique key identifying the file.
     * @param string $returnType The type of return value, either 'object' or 'array'.
     *
     * @return \CodeConfig\IGD\App\File|array|WP_Error The processed file data if found, otherwise null.
     */
    public function getFileByKey( $key, $returnType = 'object' ) {
        global $wpdb;
        if ( empty( $key ) ) {
            return new WP_Error(404, __( 'The requested file could not be found.', 'integration-google-drive' ));
        }
        $file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE `fileKey` = %s", $this->tableName, $key ) );
        if ( empty( $file ) ) {
            return new WP_Error(404, __( 'The requested file could not be found.', 'integration-google-drive' ));
        }
        $file = $this->processFile( $file );
        if ( !$file instanceof \CodeConfig\IGD\App\File ) {
            return new WP_Error(404, __( 'The requested file could not be found.', 'integration-google-drive' ));
        }
        if ( $this->isValidAccount( $file->accountId ) === false ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        if ( $returnType === 'array' ) {
            return $file->toArray();
        }
        return $file;
    }

    public function getFilesByKeys( array $keys, array $args = [] ) {
        if ( empty( $keys ) ) {
            return [];
        }
        $defaults = [
            'recursive'      => false,
            'returnType'     => 'array',
            'page'           => 1,
            'perPage'        => 24,
            'orderBy'        => 'createdAt',
            'order'          => 'DESC',
            'search'         => '',
            'searchScope'    => 'folder',
            'searchLocation' => 'cache',
        ];
        $args = wp_parse_args( $args, $defaults );
        $recursive = $args['recursive'];
        $returnType = $args['returnType'];
        $moduleType = $args['moduleType'] ?? '';
        $additionalExtensions = $args['extensions'] ?? [];
        $extensionsFilterType = $args['extensionsFilterType'] ?? '';
        $search = $args['search'];
        $searchScope = $args['searchScope'];
        $namesString = $args['names'] ?? '';
        $namesFilterType = $args['namesFilterType'] ?? '';
        $applyNamesFilter = $args['applyNameFilter'] ?? [];
        $extensions = ccpigdGetAllowedModuleExtensions( $moduleType );
        $allowedExtensions = $this->processExtensions( $extensions, $additionalExtensions, $extensionsFilterType );
        $filesData = $this->getFileAttributesByKeys( $keys, [
            'id',
            'accountId',
            'name',
            'isDirectory'
        ] );
        if ( is_wp_error( $filesData ) || empty( $filesData ) ) {
            return ( $filesData ?: [] );
        }
        if ( empty( $filesData ) ) {
            return [];
        }
        $ids = array_map( fn( $file ) => $file['id'], $filesData );
        $params = $ids;
        $sql = '';
        if ( !empty( $search ) ) {
            $searchIds = [];
            if ( $searchScope === 'global' ) {
                foreach ( $filesData as $file ) {
                    $searchIds[] = $this->getSuccessors( $file['id'], $file['accountId'] );
                }
                $params = array_merge( ...$searchIds );
            } elseif ( $searchScope === 'folder' && !empty( $args['fileId'] ) ) {
                $params = [$args['fileId']];
            }
            if ( empty( $params ) ) {
                return [];
            }
            $placeholders = implode( ',', array_fill( 0, count( $params ), '%s' ) );
            $sql = "SELECT * FROM %i WHERE (`id` IN ({$placeholders}) OR `parentId` IN ({$placeholders})) AND `name` LIKE %s";
            $params = array_merge( $params, $params, ["%{$search}%"] );
        } elseif ( $recursive ) {
            $placeholders = implode( ',', array_fill( 0, count( $params ), '%s' ) );
            $sql = "SELECT * FROM %i WHERE 1 = 1";
            if ( $moduleType === 'file-browser' ) {
                $sql .= " AND `parentId` IN ({$placeholders})";
                // if (!empty($allowedExtensions) && !in_array('folder', $allowedExtensions)) {
                //     $allowedExtensions[] = 'folder';
                // }
            } else {
                $sql .= " AND (`id` IN ({$placeholders}) OR `parentId` IN ({$placeholders})) AND `extension` != 'folder'";
                $params = array_merge( $params, $params );
            }
        } else {
            if ( !empty( $allowedExtensions ) && !in_array( 'folder', $allowedExtensions ) ) {
                $allowedExtensions[] = 'folder';
            }
            $placeholders = implode( ',', array_fill( 0, count( $params ), '%s' ) );
            $sql = "SELECT * FROM %i WHERE `id` IN ({$placeholders})";
        }
        $filterSql = '';
        $filterParams = [];
        if ( !empty( $allowedExtensions ) ) {
            $extPlaceholders = implode( ',', array_fill( 0, count( $allowedExtensions ), '%s' ) );
            $filterSql .= " AND `extension` IN ({$extPlaceholders})";
            $filterParams = array_merge( $filterParams, $allowedExtensions );
        }
        if ( !empty( $filterSql ) && !empty( $filterParams ) ) {
            $sql .= $filterSql;
            $params = array_merge( $params, $filterParams );
        }
        if ( !empty( $args['orderBy'] ) && !empty( $args['order'] ) ) {
            $allowedOrderBy = [
                'id',
                'name',
                'size',
                'createdAt',
                'updatedAt'
            ];
            $orderBy = $this->sanitizeOrderBy( $args['orderBy'], $allowedOrderBy );
            $order = $this->sanitizeOrder( $args['order'] );
            $offset = $this->sanitizePagination( $args['page'], $args['perPage'] );
            $sql .= " ORDER BY (CASE WHEN extension = 'folder' THEN 0 ELSE 1 END), `{$orderBy}` {$order} LIMIT %d OFFSET %d";
            $params[] = $offset['perPage'];
            $params[] = $offset['offset'];
        }
        $files = $this->fetchAll( $sql, array_merge( [$this->tableName], $params ) );
        $totalParams = $params;
        $totalCountSQL = str_replace( ['SELECT *'], ['SELECT COUNT(*) as count'], $sql );
        if ( strpos( $sql, 'LIMIT %d' ) !== false ) {
            array_pop( $totalParams );
            $totalCountSQL = str_replace( ['LIMIT %d'], [''], $totalCountSQL );
        }
        if ( strpos( $sql, 'OFFSET %d' ) !== false ) {
            array_pop( $totalParams );
            $totalCountSQL = str_replace( ['OFFSET %d'], [''], $totalCountSQL );
        }
        $totalCount = $this->fetch( $totalCountSQL, array_merge( [$this->tableName], $totalParams ) );
        if ( empty( $files ) || is_wp_error( $files ) || is_wp_error( $totalCount ) ) {
            return [];
        }
        $files = $this->processFiles( $files, $returnType, [
            'filterSql'    => $filterSql,
            'filterParams' => $filterParams,
        ] );
        return [
            'files'      => $files,
            'totalCount' => ( isset( $totalCount->count ) ? (int) $totalCount->count : count( $files ) ),
        ];
    }

    /**
     * Retrieve selected attributes from files by their keys.
     * @param array $keys An array of file keys to search for.
     * @param array $attributes An array of attributes to return for each file.
     *                          Defaults to ['id'].
     *                          Example: ['key', 'name'].
     *
     * @return WP_Error|array Returns:
     *                        - A flat array if one attribute is requested (e.g., ['id1', 'id2']).
     *                        - An array of associative arrays if multiple attributes are requested.
     *                        Example:
     *                        [
     *                        ['key' => 'abc123', 'name' => 'File A'],
     *                        ['key' => 'def456', 'name' => 'File B']
     *                        ]
     */
    public function getFileAttributesByKeys( array $keys, array $attributes = ['id'] ) {
        if ( empty( $keys ) ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $files = $this->fetchAll( "SELECT * FROM %i WHERE `fileKey` IN ({$placeholders})", array_merge( [$this->tableName], $keys ) );
        if ( empty( $files ) ) {
            return [];
        }
        $processedFiles = $this->processFiles( $files, 'object' );
        $firstFile = reset( $processedFiles );
        if ( $this->isValidAccount( $firstFile->accountId ) === false ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        if ( count( $attributes ) === 1 ) {
            $attr = $attributes[0];
            $result = [];
            foreach ( $processedFiles as $file ) {
                $result[] = $file->{$attr} ?? null;
            }
            return $result;
        }
        $result = [];
        foreach ( $processedFiles as $file ) {
            $fileData = [];
            foreach ( $attributes as $attr ) {
                $fileData[$attr] = $file->{$attr} ?? null;
            }
            $result[] = $fileData;
        }
        return $result;
    }

    public function addFile( array $data ) {
        $accountId = ( isset( $data['accountId'] ) ? $data['accountId'] : null );
        if ( !$this->isValidAccount( $accountId ) ) {
            return new WP_Error('error', __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $file = [
            'id'            => ( isset( $data['id'] ) ? $data['id'] : null ),
            'fileKey'       => ( isset( $data['key'] ) ? $data['key'] : null ),
            'name'          => ( isset( $data['name'] ) ? $data['name'] : null ),
            'parentId'      => ( isset( $data['parentId'] ) ? $data['parentId'] : null ),
            'accountId'     => ( isset( $data['accountId'] ) ? $data['accountId'] : null ),
            'size'          => ( isset( $data['size'] ) ? $data['size'] : null ),
            'mimeType'      => ( isset( $data['mimeType'] ) ? $data['mimeType'] : null ),
            'extension'     => ( isset( $data['extension'] ) ? $data['extension'] : null ),
            'icon'          => ( isset( $data['icon'] ) ? $data['icon'] : null ),
            'thumbnailLink' => ( isset( $data['thumbnailLink'] ) ? $data['thumbnailLink'] : null ),
            'thumbnails'    => ( isset( $data['thumbnails'] ) ? $data['thumbnails'] : null ),
            'exportLinks'   => ( isset( $data['exportLinks'] ) ? $data['exportLinks'] : null ),
            'previewLink'   => ( isset( $data['previewLink'] ) ? $data['previewLink'] : null ),
            'downloadLink'  => ( isset( $data['downloadLink'] ) ? $data['downloadLink'] : null ),
            'fileData'      => ( isset( $data['fileData'] ) ? $data['fileData'] : null ),
            'isDirectory'   => ( isset( $data['isDirectory'] ) ? $data['isDirectory'] : null ),
            'isOwnedByMe'   => ( isset( $data['isOwnedByMe'] ) ? $data['isOwnedByMe'] : null ),
            'isStarred'     => ( isset( $data['isStarred'] ) ? $data['isStarred'] : null ),
            'isShared'      => ( isset( $data['isShared'] ) ? $data['isShared'] : null ),
            'createdAt'     => current_time( 'mysql' ),
            'updatedAt'     => current_time( 'mysql' ),
        ];
        if ( empty( $file['id'] ) || empty( $file['accountId'] ) ) {
            return new WP_Error(404, __( 'The requested file could not be found.', 'integration-google-drive' ));
        }
        $format = [
            '%s',
            // id
            '%s',
            // fileKey
            '%s',
            // name
            '%s',
            // parentId
            '%s',
            // accountId
            '%s',
            // size
            '%s',
            // mimeType
            '%s',
            // extension
            '%s',
            // icon
            '%s',
            // thumbnailLink
            '%s',
            // thumbnails
            '%s',
            // exportLinks
            '%s',
            // previewLink
            '%s',
            // downloadLink
            '%s',
            // fileData
            '%s',
            // isDirectory
            '%s',
            // isOwnedByMe
            '%s',
            // isStarred
            '%s',
            // isShared
            '%s',
            // createdAt
            '%s',
        ];
        if ( $this->isCachedFile( $file["id"], $file["accountId"] ) ) {
            $id = $file['id'];
            $accountId = $file['accountId'];
            unset($file["id"]);
            unset($file["fileKey"]);
            unset($file["createdAt"]);
            $updateFormat = array_slice( $format, 2 );
            // Remove id, key, and createdAt formats
            array_pop( $updateFormat );
            // Remove createdAt format
            return $this->update(
                $file,
                [
                    'id'        => $id,
                    'accountId' => $accountId,
                ],
                $updateFormat,
                ['%s', '%s']
            );
        }
        return $this->insert( $file, $format );
    }

    /**
     * Delete a file from the database
     *
     * @param string $id The ID of the file to be deleted
     *
     * @return bool|WP_Error True if the deletion was successful, false otherwise
     */
    public function deleteFile( $id, $accountId ) {
        if ( !$this->isValidAccount( $accountId ) ) {
            return new WP_Error('error', __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $file = $this->getFile( $id, $accountId );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        if ( $file->isDirectory ) {
            $successors = $this->getSuccessors( $id, $accountId );
            if ( is_wp_error( $successors ) ) {
                return $successors;
            }
            if ( empty( $successors ) ) {
                return 0;
            }
            $placeholders = implode( ',', array_fill( 0, count( $successors ), '%s' ) );
            $whereClause = "(id IN ({$placeholders}) OR parentId IN ({$placeholders})) AND accountId = %s";
            $data = array_merge( $successors, $successors, [$accountId] );
            return $this->deleteCustom( $whereClause, $data );
        }
        return $this->delete( [
            'id'        => $id,
            'accountId' => $accountId,
        ], ['%s', '%s'] );
    }

    /**
     * Deletes all files associated with a given account ID from the database.
     *
     * @param string $accountId The ID of the account whose files are to be deleted.
     * @return bool|WP_Error True if the deletion was successful, false otherwise or an error.
     */
    public function deleteFilesByAccountId( $accountId ) {
        return $this->delete( [
            'accountId' => $accountId,
        ], ['%s'] );
    }

    /**
     * Update a file in the database
     *
     * @param string $id The ID of the file to be updated
     * @param array $data The data to be updated
     * @param array $dataFormat The format of the data
     * @return bool|WP_Error True if the update was successful, false otherwise
     */
    public function updateFile( $id, $data, $dataFormat ) {
        return $this->update(
            $data,
            [
                'id' => $id,
            ],
            $dataFormat,
            ['%s']
        );
    }

    public function isCachedFolder( $folderId, $accountId ) {
        global $wpdb;
        if ( $this->isValidAccount( $accountId ) === false ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $folder = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM %i WHERE parentId = %s AND accountId = %s",
            $this->tableName,
            $folderId,
            $accountId
        ) );
        return !empty( $folder );
    }

    public function isCachedFile( $folderId, $accountId ) {
        if ( $this->isValidAccount( $accountId ) === false ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $folder = $this->fetch( "SELECT * FROM %i WHERE id = %s AND accountId = %s", [$this->tableName, $folderId, $accountId] );
        return !empty( $folder );
    }

    /**
     * Check if a file exists by ID and account ID
     *
     * @param string $id File ID
     * @param string $accountId Account ID
     * @return bool|WP_Error True if file exists, false otherwise
     */
    public function fileExists( $id, $accountId ) {
        if ( empty( $id ) || empty( $accountId ) ) {
            return false;
        }
        return $this->exists( [
            'id'        => $id,
            'accountId' => $accountId,
        ] );
    }

    /**
     * Get file count for an account
     *
     * @param string $accountId Account ID
     * @return int|WP_Error Number of files or WP_Error on failure
     */
    public function getFileCountByAccount( $accountId ) {
        global $wpdb;
        if ( empty( $accountId ) ) {
            return new WP_Error(400, __( 'Account ID is required.', 'integration-google-drive' ));
        }
        if ( !$this->isValidAccount( $accountId ) ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $result = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE accountId = %s", $this->tableName, $accountId ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return (int) $result ?? 0;
    }

    /**
     * Get files with pagination and filtering
     *
     * @param array $args Query arguments
     * @return array|WP_Error Array of files or WP_Error on failure
     */
    public function getFilesPaginated( $args = [] ) {
        $defaults = [
            'accountId' => '',
            'parentId'  => '',
            'page'      => 1,
            'perPage'   => 24,
            'orderBy'   => 'createdAt',
            'order'     => 'DESC',
            'search'    => '',
            'extension' => '',
        ];
        $args = array_merge( $defaults, $args );
        if ( !$this->isValidAccount( $args['accountId'] ) ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $allowedOrderBy = [
            'id',
            'name',
            'size',
            'createdAt',
            'updatedAt'
        ];
        $orderBy = $this->sanitizeOrderBy( $args['orderBy'], $allowedOrderBy );
        $order = $this->sanitizeOrder( $args['order'] );
        $pagination = $this->sanitizePagination( $args['page'], $args['perPage'] );
        $where = ['accountId = %s'];
        $values = [$args['accountId']];
        if ( !empty( $args['parentId'] ) ) {
            $where[] = 'parentId = %s';
            $values[] = $args['parentId'];
        }
        if ( !empty( $args['search'] ) ) {
            $where[] = 'name LIKE %s';
            $values[] = '%' . $args['search'] . '%';
        }
        if ( !empty( $args['extension'] ) ) {
            $where[] = 'extension = %s';
            $values[] = $args['extension'];
        }
        $whereClause = implode( ' AND ', $where );
        $values[] = $pagination['perPage'];
        $values[] = $pagination['offset'];
        $files = $this->fetchAll( "SELECT * FROM %i WHERE {$whereClause} ORDER BY `{$orderBy}` {$order} LIMIT %d OFFSET %d", array_merge( [$this->tableName], $values ) );
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        return [
            'files'      => $this->processFiles( $files ),
            'pagination' => $pagination,
            'total'      => $this->getFileCountByAccount( $args['accountId'] ),
        ];
    }

    /**
     * Get files by extension
     *
     * @param string $accountId Account ID
     * @param string $extension File extension
     * @param int $limit Limit number of results
     * @return array|WP_Error Array of files or WP_Error on failure
     */
    public function getFilesByExtension( $accountId, $extension, $limit = 100 ) {
        if ( !$this->isValidAccount( $accountId ) ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $limit = max( 1, min( 1000, (int) $limit ) );
        $files = $this->fetchAll( "SELECT * FROM %i WHERE accountId = %s AND extension = %s LIMIT %d", [
            $this->tableName,
            $accountId,
            $extension,
            $limit
        ] );
        if ( is_wp_error( $files ) ) {
            return $files;
        }
        return $this->processFiles( $files );
    }

    /**
     * Batch delete files by IDs and account ID
     *
     * @param array $fileIds Array of file IDs
     * @param string $accountId Account ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function batchDeleteFiles( $fileIds, $accountId ) {
        if ( empty( $fileIds ) || !is_array( $fileIds ) ) {
            return new WP_Error(400, __( 'File IDs are required.', 'integration-google-drive' ));
        }
        if ( !$this->isValidAccount( $accountId ) ) {
            return new WP_Error(403, __( 'This account is lost or does not exist. Please re-authorize it.', 'integration-google-drive' ));
        }
        $success_count = 0;
        $total_count = count( $fileIds );
        foreach ( $fileIds as $fileId ) {
            if ( empty( $fileId ) ) {
                continue;
            }
            $result = $this->delete( [
                'id'        => $fileId,
                'accountId' => $accountId,
            ], ['%s', '%s'] );
            if ( !is_wp_error( $result ) && $result ) {
                $success_count++;
            }
        }
        if ( $success_count === 0 ) {
            return new WP_Error(500, __( 'Failed to delete any files in batch operation.', 'integration-google-drive' ));
        }
        return $success_count === $total_count;
    }

    /**
     * Counts the number of files in a folder
     *
     * @param string $folderId The ID of the folder
     * @param string $accountId The ID of the account
     *
     * @return int|WP_Error The number of files in the folder, or a WP_Error if the query fails
     */
    public function childrenCount( $folderId, $accountId, $filter = null ) {
        global $wpdb;
        $sql = $wpdb->prepare( "SELECT COUNT(*) as count FROM %i WHERE parentId = %s AND accountId = %s", [$this->tableName, $folderId, $accountId] );
        if ( !empty( $filter['filterParams'] ) && !empty( $filter['filterSql'] ) ) {
            $sql .= $wpdb->prepare( $filter['filterSql'], $filter['filterParams'] );
        }
        $count = $this->fetch( $sql );
        if ( is_wp_error( $count ) ) {
            return $count;
        }
        if ( !isset( $count->count ) ) {
            return 0;
        }
        return $count->count;
    }

    public function getSuccessors( $parentId, $accountId ) {
        $successor = [];
        $folders = $this->getChildFolderIds( $parentId, $accountId );
        foreach ( $folders as $folderRow ) {
            $folderId = $folderRow['id'];
            $successor[] = $folderId;
            $childFolders = $this->getChildFolderIds( $folderId, $accountId );
            if ( !empty( $childFolders ) ) {
                $successor = array_merge( $successor, $this->getSuccessors( $folderId, $accountId ) );
            }
        }
        $successor[] = $parentId;
        return array_unique( $successor );
    }

    // =============================== PRIVATE METHODS =============================== //
    private function processFiles( $files, $returnType = 'array', $filter = null ) {
        $processedFiles = [];
        foreach ( $files as $file ) {
            $processedFiles[] = $this->processFile( $file, $returnType, $filter );
        }
        return $processedFiles;
    }

    /**
     * Process a file object and return an enriched array representation.
     *
     * @param object|null $file
     * @return array|\CodeConfig\IGD\App\File
     */
    private function processFile( $file, $returnType = 'object', $filter = null ) {
        if ( empty( $file ) ) {
            return [];
        }
        $rawFileData = maybe_unserialize( $file->fileData );
        if ( !$rawFileData instanceof \CodeConfig\IGD\App\File ) {
            return [];
        }
        $previewLink = ccpigdGetUrl(
            'preview',
            $file->fileKey,
            $file->name,
            'full',
            $file->extension
        );
        $rawFileData->setPreviewLink( $previewLink );
        $rawFileData->setParentKey( $rawFileData->getParentKey() );
        $lifeTime = $this->checkLifeTime( $file );
        $rawFileData->needToSync = !$lifeTime;
        $rawFileData->setLifeTime( $lifeTime );
        if ( $rawFileData->mimeType === 'application/vnd.google-apps.folder' ) {
            $rawFileData->count = $this->childrenCount( $file->id, $file->accountId, $filter );
        }
        if ( $returnType === 'object' ) {
            return $rawFileData;
        } elseif ( $returnType === 'array' ) {
            $fileArray = $rawFileData->toArray();
            return $fileArray;
        }
        return [
            'id'         => $file->id,
            'name'       => $file->name,
            'key'        => $file->fileKey,
            'mimeType'   => $file->mimeType,
            'size'       => $file->size,
            'thumbnails' => maybe_unserialize( $file->thumbnails ),
        ];
    }

    private function checkLifeTime( $file ) {
        $lifeTime = (float) get_option( 'ccpigd_thumbnail_lifetime', 1 );
        $lifeTime = intval( apply_filters( 'ccpigd_thumbnail_lifetime', $lifeTime ) );
        $lifeTime *= HOUR_IN_SECONDS;
        if ( $lifeTime ) {
            $currentTime = current_time( 'mysql' );
            $fileTime = strtotime( $file->updatedAt );
            $currentTimestamp = strtotime( $currentTime );
            $fileLifeTime = $fileTime + $lifeTime;
            $diff = $fileLifeTime - $currentTimestamp;
            return max( 0, $diff );
        }
        return 0;
    }

    private function processExtensions( array $extensions, array $additionalExtensions, string $filterType ) : array {
        if ( empty( $additionalExtensions ) ) {
            return $extensions;
        }
        if ( empty( $extensions ) ) {
            return $additionalExtensions;
        }
        if ( $filterType === 'include' ) {
            $filterExtensions = array_filter( $extensions, function ( $ext ) use($additionalExtensions) {
                return in_array( $ext, $additionalExtensions );
            } );
            return array_values( $filterExtensions );
        } elseif ( $filterType === 'exclude' ) {
            $filterExtensions = array_filter( $extensions, function ( $ext ) use($additionalExtensions) {
                return !in_array( $ext, $additionalExtensions );
            } );
            return array_values( $filterExtensions );
        }
        return $extensions;
    }

    private function getChildFolderIds( $parentId, $accountId ) {
        return $this->fetchAll( "SELECT `id` FROM %i WHERE `parentId` = %s AND `accountId` = %s AND `extension` = 'folder'", [$this->tableName, $parentId, $accountId], ARRAY_A );
    }

}
