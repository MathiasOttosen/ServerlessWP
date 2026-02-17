<?php

namespace CodeConfig\IGD\Models;

use CodeConfig\IGD\App\App;
use CodeConfig\IGD\Utils\Helpers;
use CodeConfig\IGD\Utils\Singleton;
use WP_Error;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class Shortcode extends BaseModel {
    use Singleton;
    private $breadcrumbs;

    public function __construct() {
        parent::__construct( 'integration_google_drive_shortcodes' );
    }

    /**
     * Retrieve a shortcode by its ID.
     *
     * @param int $id The ID of the shortcode to retrieve.
     * @return array|WP_Error Array containing shortcode data or WP_Error if the ID is invalid or an error occurs.
     */
    public function get( $id, array $config = [] ) {
        if ( empty( $id ) ) {
            return new WP_Error(404, __( 'Shortcode ID is required.', 'integration-google-drive' ));
        }
        $shortcode = $this->fetchShortcode( $id );
        if ( is_wp_error( $shortcode ) ) {
            return $shortcode;
        }
        return $this->processData( $shortcode, $config );
    }

    public function getAll( array $config ) {
        $defaults = [
            'type'    => 'all',
            'search'  => '',
            'status'  => 'all',
            'order'   => 'DESC',
            'orderBy' => 'createdAt',
            'page'    => 1,
            'perPage' => 10,
        ];
        $config = array_merge( $defaults, $config );
        $allowedOrderBy = [
            'title',
            'type',
            'status',
            'id',
            'createdAt',
            'updatedAt'
        ];
        $orderBy = $this->sanitizeOrderBy( $config['orderBy'], $allowedOrderBy );
        $order = $this->sanitizeOrder( $config['order'] );
        $pagination = $this->sanitizePagination( $config['page'], $config['perPage'] );
        $sqlParts = $this->wpdb->prepare( "SELECT * FROM %i WHERE 1=1", $this->tableName );
        if ( $config['type'] !== 'all' ) {
            $sqlParts .= $this->wpdb->prepare( " AND type = %s", $config['type'] );
        }
        if ( $config['status'] !== 'all' ) {
            $sqlParts .= $this->wpdb->prepare( " AND status = %s", $config['status'] );
        }
        if ( !empty( $config['search'] ) ) {
            $sqlParts .= $this->wpdb->prepare( " AND title LIKE %s", '%' . $config['search'] . '%' );
        }
        $sqlParts .= $this->wpdb->prepare( " ORDER BY %s %s", $orderBy, $order );
        if ( $pagination['perPage'] > 0 ) {
            $sqlParts .= $this->wpdb->prepare( " LIMIT %d OFFSET %d", $pagination['perPage'], $pagination['offset'] );
        }
        $results = $this->fetchAll( $sqlParts, [], ARRAY_A );
        if ( is_wp_error( $results ) ) {
            return $results;
        }
        $processData = [];
        foreach ( $results as $result ) {
            $processData[] = $this->processData( $result, [
                'dataProcess' => false,
            ] );
        }
        return $processData;
    }

    public function add( array $data ) {
        $now = current_time( 'mysql' );
        if ( isset( $data['data'] ) ) {
            $data['data'] = maybe_serialize( $data['data'] );
        }
        if ( isset( $data['locations'] ) ) {
            $data['locations'] = maybe_serialize( $data['locations'] );
        }
        $is_update = !empty( $data['id'] ) && is_numeric( $data['id'] );
        if ( $is_update ) {
            $id = $data['id'];
            unset($data['id'], $data['createdAt']);
            $data['updatedAt'] = $now;
            $format = $this->generateFormat( $data );
            $where_format = ['%d'];
            $result = $this->update(
                $data,
                [
                    'id' => $id,
                ],
                $format,
                $where_format,
                ARRAY_A
            );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return $this->processData( $result );
        } else {
            if ( empty( $data['type'] ) ) {
                return new WP_Error(404, __( 'Shortcode type is required.', 'integration-google-drive' ));
            }
            if ( empty( $data['data'] ) ) {
                return new WP_Error(404, __( 'Shortcode data is required.', 'integration-google-drive' ));
            }
            // Apply defaults
            $data['title'] = ( isset( $data['title'] ) ? $data['title'] : 'Untitled' );
            $data['status'] = ( isset( $data['status'] ) ? $data['status'] : 'on' );
            $data['createdAt'] = $now;
            $data['updatedAt'] = $now;
            $format = $this->generateFormat( $data );
            $inserted = $this->insert( $data, $format, ARRAY_A );
            if ( is_wp_error( $inserted ) ) {
                return $inserted;
            }
            return $this->processData( $inserted );
        }
    }

    public function getShortcode( $id, $key = null ) {
        if ( empty( $id ) ) {
            return new WP_Error(404, __( 'Invalid ID provided.', 'integration-google-drive' ));
        }
        $shortcode = $this->fetchShortcode( $id );
        if ( is_wp_error( $shortcode ) ) {
            return $shortcode;
        }
        if ( isset( $shortcode['data'] ) && is_serialized( $shortcode['data'] ) ) {
            $shortcode['data'] = maybe_unserialize( $shortcode['data'] );
        }
        if ( $key === null ) {
            return $shortcode;
        }
        if ( is_string( $key ) ) {
            return $shortcode[$key] ?? $shortcode['data'][$key] ?? null;
        }
        if ( is_array( $key ) ) {
            $results = [];
            foreach ( $key as $k ) {
                $results[$k] = $shortcode[$k] ?? $shortcode['data'][$k] ?? null;
            }
            return $results;
        }
        return new WP_Error(400, __( 'Invalid key type provided.', 'integration-google-drive' ));
    }

    /**
     * Delete shortcodes from the database.
     *
     * @param int|array $ids The ID or IDs of the shortcodes to delete.
     * @return int|WP_Error The number of rows affected or a WP_Error object if an error occurs.
     */
    public function remove( $ids ) {
        if ( !is_array( $ids ) ) {
            $ids = [$ids];
        }
        if ( empty( $ids ) ) {
            return 0;
        }
        foreach ( $ids as $id ) {
            if ( !is_numeric( $id ) ) {
                return new WP_Error(404, __( 'Invalid ID provided.', 'integration-google-drive' ));
            }
        }
        $success_count = 0;
        $total_count = count( $ids );
        foreach ( $ids as $id ) {
            $result = $this->delete( [
                'id' => (int) $id,
            ], ['%d'] );
            if ( !is_wp_error( $result ) && $result ) {
                $success_count++;
            }
        }
        if ( $success_count === 0 ) {
            return new WP_Error(500, __( 'Failed to delete any shortcodes.', 'integration-google-drive' ));
        }
        return $success_count;
    }

    public function duplicate( $ids ) {
        global $wpdb;
        if ( !is_array( $ids ) ) {
            $ids = [$ids];
        }
        if ( empty( $ids ) ) {
            return new WP_Error(404, __( 'Invalid ID provided.', 'integration-google-drive' ));
        }
        foreach ( $ids as $id ) {
            if ( !is_numeric( $id ) ) {
                return new WP_Error(404, __( 'Invalid ID provided.', 'integration-google-drive' ));
            }
        }
        $shortcodes = [];
        foreach ( $ids as $id ) {
            $shortcode = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->tableName, $id ), ARRAY_A );
            if ( is_wp_error( $shortcode ) ) {
                return $shortcode;
            }
            if ( !empty( $shortcode ) ) {
                $shortcodes[] = $shortcode;
            }
        }
        if ( empty( $shortcodes ) ) {
            return new WP_Error(404, __( 'Invalid ID provided.', 'integration-google-drive' ));
        }
        $results = 0;
        foreach ( $shortcodes as $shortcode ) {
            $shortcode['title'] .= ' - Copy';
            $shortcode['status'] = 'off';
            unset($shortcode['id']);
            $shortcode['createdAt'] = current_time( 'mysql' );
            $shortcode['updatedAt'] = current_time( 'mysql' );
            $result = $this->insert( $shortcode, $this->generateFormat( $shortcode ) );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $results++;
        }
        return $results;
    }

    public function totalCount( $config = [] ) {
        $defaultConfig = [
            'type'   => 'all',
            'search' => '',
            'status' => 'all',
        ];
        $config = wp_parse_args( $config, $defaultConfig );
        $type = $config['type'];
        $search = $config['search'];
        $status = $config['status'];
        $sql = $this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE 1=1", $this->tableName );
        if ( $type !== 'all' ) {
            $sql .= $this->wpdb->prepare( " AND type = %s", $type );
        }
        if ( $status !== 'all' ) {
            $sql .= $this->wpdb->prepare( " AND status = %s", $status );
        }
        if ( !empty( $search ) ) {
            $search = '%' . $this->wpdb->esc_like( $search ) . '%';
            $sql .= $this->wpdb->prepare( " AND title LIKE %s", $search );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = $this->wpdb->get_var( $sql );
        if ( $this->wpdb->last_error ) {
            return new WP_Error(400, __( 'A database error occurred: ', 'integration-google-drive' ) . $this->wpdb->last_error);
        }
        return (int) $count;
    }

    // ========================= Utility methods =========================
    /**
     * Check if a shortcode exists by ID.
     *
     * @param int $id The shortcode ID.
     * @return bool True if exists, false otherwise.
     */
    public function shortcodeExists( $id ) {
        return $this->exists( [
            'id' => (string) $id,
        ] );
    }

    /**
     * Get a specific column value for a shortcode.
     *
     * @param string $column The column title.
     * @param int $id The shortcode ID.
     * @return mixed|null The column value or null if not found.
     */
    public function getShortcodeColumn( $column, $id ) {
        return $this->getColumn( $column, [
            'id' => (string) $id,
        ] );
    }

    /**
     * Get shortcode title by ID.
     *
     * @param int $id The shortcode ID.
     * @return string|null The shortcode title or null if not found.
     */
    public function getShortcodeTitle( $id ) {
        return $this->getColumn( 'title', [
            'id' => (string) $id,
        ] );
    }

    /**
     * Update shortcode status.
     *
     * @param int $id The shortcode ID.
     * @param string $status The new status.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function updateStatus( $id, $status ) {
        return $this->update(
            [
                'status'     => $status,
                'updated_at' => current_time( 'mysql' ),
            ],
            [
                'id' => (string) $id,
            ],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function checkAllowShortcodeScopes( int $shortcodeId, $fileKeys ) : bool {
        if ( is_string( $fileKeys ) ) {
            $fileKeys = [$fileKeys];
        }
        if ( empty( $shortcodeId ) || empty( $fileKeys ) ) {
            return false;
        }
        $source = $this->getShortcode( $shortcodeId, 'source' );
        if ( empty( $source ) || !is_array( $source ) || !isset( $source['fileKeys'] ) || !is_array( $source['fileKeys'] ) ) {
            return false;
        }
        $sourceFileKeys = $source['fileKeys'];
        if ( empty( $sourceFileKeys ) ) {
            return false;
        }
        foreach ( $fileKeys as $fileKey ) {
            $isAllowed = Helpers::validateFileKey( $fileKey, $sourceFileKeys );
            if ( !$isAllowed ) {
                Notices::getInstance()->add( [
                    'type'        => 'error',
                    'title'       => 'File key not allowed.',
                    'description' => "File key not allowed for this shortcode: {$fileKey}",
                ] );
                return false;
            }
        }
        return true;
    }

    // ========================= Private methods =========================
    private function generateFormat( $data ) {
        $format = [];
        foreach ( $data as $key => $value ) {
            $format[] = ( is_numeric( $value ) && (int) $value == $value ? '%d' : '%s' );
        }
        return $format;
    }

    /**
     * Processes the input data for a shortcode, handling serialization, file retrieval,
     * and optional schema validation and sanitization.
     *
     * @param array $data The data array containing 'type' and serialized 'data'.
     * @param bool $validateSchema Whether to validate the data against a schema.
     *
     * @return array|WP_Error Processed and optionally validated data.
     */
    private function processData( $data, $config = [] ) {
        if ( empty( $data['type'] ) || empty( $data['data'] ) ) {
            return [];
        }
        $moduleType = $data['type'] ?? '';
        $id = $data['id'] ?? 0;
        if ( empty( $id ) ) {
            return [];
        }
        $default = [
            'validateSchema' => true,
            'returnType'     => 'array',
            'recursive'      => !in_array( $moduleType, ['file-browser'] ),
            'page'           => 1,
            'perPage'        => 20,
            'fileKey'        => null,
            'order'          => null,
            'orderBy'        => null,
            'search'         => null,
            'searchScope'    => 'folder',
            'from'           => 'cache',
            'moduleType'     => $moduleType,
            'password'       => null,
            'dataProcess'    => true,
        ];
        $wp_referer = wp_get_raw_referer();
        $isAdmin = current_user_can( 'manage_options' ) && $wp_referer === admin_url( 'admin.php?page=integration-google-drive' );
        $queryConfig = wp_parse_args( $config, $default );
        $validateSchema = $queryConfig['validateSchema'] ?? true;
        $fileKey = $queryConfig['fileKey'] ?? null;
        $order = $queryConfig['order'] ?? null;
        $orderBy = $queryConfig['orderBy'] ?? null;
        $password = $queryConfig['password'] ?? null;
        $breadcrumbs = null;
        $processedData = [];
        foreach ( $data as $key => $value ) {
            if ( is_serialized( $value ) ) {
                $value = unserialize( $value );
                if ( $key === 'data' && $queryConfig['dataProcess'] ) {
                    $permissions = $value['permissions'] ?? [];
                    if ( !empty( $permissions ) && !$isAdmin ) {
                        $passwordProtect = $permissions['passwordProtect'] ?? '';
                        if ( isset( $passwordProtect['enable'] ) && $passwordProtect['enable'] && isset( $passwordProtect['password'] ) && !empty( $passwordProtect['password'] ) ) {
                            $storedPassword = $passwordProtect['password'];
                            $cookieKey = "ccpigd_token_{$id}";
                            $secure_hash = hash( 'sha256', $storedPassword );
                            if ( isset( $_COOKIE[$cookieKey] ) && sanitize_text_field( wp_unslash( $_COOKIE[$cookieKey] ) ) !== $secure_hash || empty( $_COOKIE[$cookieKey] ) ) {
                                if ( empty( $password ) ) {
                                    $value['source']['files'] = new WP_Error(401, __( 'Password is required', 'integration-google-drive' ));
                                    $processedData[$key] = $value;
                                    return $processedData;
                                }
                                $new_hash = hash( 'sha256', $password );
                                if ( $secure_hash !== $new_hash ) {
                                    $value['source']['files'] = new WP_Error(401, __( 'Password is incorrect', 'integration-google-drive' ));
                                    $processedData[$key] = $value;
                                    Notices::getInstance()->add( [
                                        'type'        => 'error',
                                        'title'       => __( 'Password Error', 'integration-google-drive' ),
                                        'description' => sprintf(
                                            "A User '%s' tried to access #%d: %s module with an incorrect password.",
                                            wp_get_current_user()->user_login ?? 'Guest',
                                            $id,
                                            $moduleType
                                        ),
                                    ] );
                                    return $processedData;
                                } else {
                                    setcookie(
                                        $cookieKey,
                                        $secure_hash,
                                        time() + DAY_IN_SECONDS,
                                        COOKIEPATH,
                                        COOKIE_DOMAIN,
                                        is_ssl(),
                                        true
                                    );
                                }
                            }
                        }
                        if ( !$this->checkShortCodePermission( $permissions, $queryConfig ) ) {
                            $processedData[$key] = $value;
                            $processedData['error'] = true;
                            $processedData['message'] = __( 'You do not have permission to access this content', 'integration-google-drive' );
                            return $processedData;
                        }
                    }
                    $fileKeys = $value['source']['fileKeys'] ?? [];
                    if ( empty( $fileKeys ) ) {
                        continue;
                    }
                    if ( !empty( $fileKey ) ) {
                        if ( $breadcrumbs = Helpers::validateFileKey( $fileKey, $fileKeys ) ) {
                            $fileKeys = [[
                                'key'          => $fileKey,
                                'thumbnailKey' => '',
                            ]];
                            $queryConfig['recursive'] = true;
                        } else {
                            return [];
                        }
                    }
                    $advancedTab = $value['advanced'] ?? false;
                    if ( $advancedTab ) {
                        $queryConfig['perPage'] = ( $advancedTab['filesInFirstRender'] ?: 20 );
                        if ( isset( $advancedTab['file-browser'] ) && !empty( $advancedTab['file-browser'] ) ) {
                            $queryConfig['orderBy'] = $advancedTab['sort']['orderBy'] ?? 'name';
                            $queryConfig['order'] = strtoupper( $advancedTab['sort']['order'] ?? 'ASC' );
                            $queryConfig['from'] = 'cache';
                        } else {
                            if ( empty( $this->isModuleAutoFetch( $id, $advancedTab ?? [] ) ) ) {
                                $queryConfig['from'] = 'cache';
                            }
                            $queryConfig['orderBy'] = $advancedTab['sort']['orderBy'] ?? 'name';
                            $queryConfig['order'] = strtoupper( $advancedTab['sort']['order'] ?? 'ASC' );
                        }
                    }
                    if ( !empty( $value['filter'] ) ) {
                        // Extensions filter
                        $allowAllExtensions = $value['filter']['allowAllExtensions'] ?? true;
                        $allowExtensions = $value['filter']['allowExtensions'] ?? [];
                        $allowExceptExtensions = $value['filter']['allowExceptExtensions'] ?? [];
                        $extensions = ( $allowAllExtensions ? $allowExceptExtensions : $allowExtensions );
                        $extensionsFilterType = ( $allowAllExtensions ? 'exclude' : 'include' );
                        $queryConfig['extensions'] = $extensions;
                        $queryConfig['extensionsFilterType'] = $extensionsFilterType;
                        $queryConfig['applyNameFilter'] = [];
                        $queryConfig['names'] = '';
                    }
                    $app = App::getInstance();
                    $filesData = $app->getFilesByKeys( $fileKeys, $queryConfig );
                    if ( empty( $filesData ) ) {
                        $queryConfig['from'] = 'server';
                        $filesData = $app->getFilesByKeys( $fileKeys, $queryConfig );
                    }
                    if ( is_wp_error( $filesData ) ) {
                        continue;
                    }
                    $files = $filesData['files'] ?? [];
                    $totalCount = ( isset( $filesData['totalCount'] ) ? (int) $filesData['totalCount'] : count( $filesData['files'] ?? [] ) );
                    $perPage = ( isset( $queryConfig['perPage'] ) ? (int) $queryConfig['perPage'] : 20 );
                    $currentPage = ( isset( $queryConfig['page'] ) ? (int) $queryConfig['page'] : 1 );
                    $hasMore = $currentPage * $perPage < $totalCount;
                    $totalPages = ceil( $totalCount / $perPage );
                    $value['source']['files'] = $files;
                    $value['source']['totalCount'] = $totalCount;
                    $value['source']['currentPage'] = $currentPage;
                    $value['source']['perPage'] = $perPage;
                    $value['source']['totalPages'] = $totalPages;
                    $value['source']['hasMore'] = $hasMore;
                    if ( $breadcrumbs ) {
                        $value['source']['breadcrumbs'] = array_reverse( $breadcrumbs );
                    }
                    $value['source']['nextPage'] = ( $hasMore ? $currentPage + 1 : null );
                    if ( current_user_can( 'manage_options' ) ) {
                        $queryConfig['recursive'] = false;
                        $queryConfig['returnType'] = 'array';
                        $queryConfig['page'] = 1;
                        $queryConfig['perPage'] = 1000;
                        $queryConfig['from'] = 'cache';
                        $recursiveFiles = $app->getFilesByKeys( $fileKeys, $queryConfig );
                        if ( !is_wp_error( $recursiveFiles ) ) {
                            $value['source']['selectedFiles'] = $recursiveFiles['files'] ?? [];
                        }
                    }
                }
                $processedData[$key] = $value;
            } else {
                $processedData[$key] = ( $key === 'id' ? intval( $value ) : $value );
            }
        }
        if ( $validateSchema || !is_admin() || !current_user_can( CCPIGD_ACCESS_CAP ) ) {
            $type = $processedData['type'] ?? '';
            if ( empty( $type ) ) {
                return [];
            }
            $shortcodeTypesSchema = ccpigdGetShortcodeTypesSchema();
            if ( !isset( $shortcodeTypesSchema[$type] ) ) {
                return [];
            }
            $schema = $shortcodeTypesSchema[$type];
            $processedData = $this->validateAndSanitize( $processedData, $schema );
        }
        return $processedData;
    }

    private function getFilesByKeys( $fileKeys, $queryConfig = [] ) {
        if ( empty( $fileKeys ) ) {
            return new WP_Error(404, __( 'No file keys provided.', 'integration-google-drive' ));
        }
        if ( !is_array( $fileKeys ) ) {
            return new WP_Error(400, __( 'File keys must be an array.', 'integration-google-drive' ));
        }
        $queryConfig = wp_parse_args( $queryConfig, [
            'returnType'     => 'array',
            'recursive'      => true,
            'page'           => 1,
            'perPage'        => 20,
            'orderBy'        => 'createdAt',
            'order'          => 'DESC',
            'search'         => '',
            'searchScope'    => 'folder',
            'searchLocation' => 'cache',
        ] );
        $keys = array_column( $fileKeys, 'key' );
        $filesModel = Files::getInstance();
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

    private function validateAndSanitize( array $data, array $schema ) {
        $result = [];
        $schema['data']['source']['selectedFiles[]'] = $schema['data']['source']['files[]'] ?? 'null';
        foreach ( $schema as $key => $expectedType ) {
            $filteredKey = str_replace( '[]', '', $key );
            if ( !isset( $data[$filteredKey] ) ) {
                continue;
            }
            $value = $data[$filteredKey];
            if ( is_array( $expectedType ) ) {
                if ( is_array( $value ) ) {
                    $isNestedArray = strpos( $key, '[]' ) !== false;
                    if ( $isNestedArray ) {
                        foreach ( $value as $index => $item ) {
                            $nested = $this->validateAndSanitize( $item, $expectedType );
                            if ( !empty( $nested ) ) {
                                $result[$filteredKey][$index] = $nested;
                            }
                        }
                    } else {
                        $nested = $this->validateAndSanitize( $value, $expectedType );
                        if ( !empty( $nested ) ) {
                            $result[$filteredKey] = $nested;
                        }
                    }
                }
            } else {
                if ( $this->isTypeMatch( $value, $expectedType ) ) {
                    $result[$filteredKey] = $value;
                }
            }
        }
        return $result;
    }

    private function isTypeMatch( $value, $type ) {
        $types = explode( '|', $type );
        foreach ( $types as $t ) {
            switch ( $t ) {
                case 'integer':
                    if ( is_int( $value ) || is_numeric( $value ) ) {
                        return true;
                    }
                    break;
                case 'string':
                    if ( is_string( $value ) ) {
                        return true;
                    }
                    break;
                case 'boolean':
                    if ( is_bool( $value ) ) {
                        return true;
                    }
                    break;
                case 'array':
                    if ( is_array( $value ) ) {
                        return true;
                    }
                    break;
                case 'object':
                    if ( is_object( $value ) ) {
                        return true;
                    }
                    break;
                case 'NULL':
                    if ( $value === null ) {
                        return true;
                    }
                    break;
                case 'any':
                    return true;
                default:
                    if ( gettype( $value ) === $t ) {
                        return true;
                    }
            }
        }
        return false;
    }

    private function fetchShortcode( $id ) {
        if ( empty( $id ) ) {
            return new WP_Error(404, __( 'Shortcode ID is required.', 'integration-google-drive' ));
        }
        $result = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->tableName, $id ), ARRAY_A );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result ) ) {
            return new WP_Error(404, __( 'Shortcode not found.', 'integration-google-drive' ));
        }
        return $result;
    }

    private function checkShortCodePermission( $permissions, $queryConfig ) {
        if ( is_wp_error( $permissions ) ) {
            return false;
        }
        if ( $queryConfig['moduleType'] !== 'search-box' ) {
            $search = $permissions['searchPermission'] ?? '';
            if ( empty( $search['enable'] ) && !empty( $queryConfig['search'] ) ) {
                return false;
            }
            if ( !empty( $search['enable'] ) && !empty( $queryConfig['search'] ) ) {
                $searchLocation = array_filter( $search['searchLocation'] );
                $searchScope = array_filter( $search['searchScope'] );
                if ( !in_array( $queryConfig['searchScope'], $searchScope ) ) {
                    return false;
                }
                if ( !in_array( $queryConfig['from'], $searchLocation ) ) {
                    return false;
                }
            }
        }
        $displayFor = $permissions['displayFor'] ?? [];
        if ( $displayFor['whoCanViewModule'] === 'everyone' ) {
            return true;
        }
        if ( !is_user_logged_in() ) {
            return false;
        }
        if ( empty( $displayFor['displayFor'] ) ) {
            return true;
        }
        $currentUser = wp_get_current_user();
        if ( empty( $currentUser->ID ) ) {
            return false;
        }
        $userId = $currentUser->ID;
        $loggedInUserType = $displayFor['loggedInUserType'] ?? 'users';
        if ( 'users' === $loggedInUserType ) {
            $isPermission = in_array( $userId, $displayFor['displayFor'], true );
            if ( !$isPermission ) {
                return false;
            }
            return true;
        }
        if ( 'roles' === $loggedInUserType ) {
            $userRoles = $currentUser->roles;
            if ( empty( $userRoles ) ) {
                return false;
            }
            foreach ( $userRoles as $role ) {
                if ( in_array( $role, $displayFor['displayFor'], true ) ) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    private function isModuleAutoFetch( $id, $moduleConfig ) {
        if ( empty( $moduleConfig ) ) {
            return false;
        }
        if ( empty( $moduleConfig['autoFetch'] ) ) {
            return false;
        }
        $transientKey = "ccpigd_module_auto_fetch_{$id}";
        $autoFetch = get_transient( $transientKey );
        if ( empty( $autoFetch ) ) {
            $autoFetchInterval = $moduleConfig['autoFetchInterval'] ?? 60;
            set_transient( $transientKey, true, $autoFetchInterval );
            return true;
        }
        return false;
    }

}
