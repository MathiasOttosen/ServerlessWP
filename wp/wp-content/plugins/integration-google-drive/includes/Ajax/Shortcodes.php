<?php

namespace CodeConfig\IGD\Ajax;

use CodeConfig\IGD\App\App;
use CodeConfig\IGD\Models\Shortcode;
use CodeConfig\IGD\Utils\Helpers;
defined( 'ABSPATH' ) || exit;
/**
 * Handle all Shortcode–related AJAX requests.
 */
class Shortcodes extends BaseAjax {
    public static function get() : void {
        self::verifyRequest();
        $id = (int) self::getPostParam( 'id', 0, 'intval' );
        $config = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['config'] ) ) {
            $config = self::getRequestData();
        }
        $referer = wp_get_referer();
        $ccpigdAdminUrl = admin_url( 'admin.php?page=integration-google-drive' );
        $isAdmin = current_user_can( 'manage_options' ) && $referer === $ccpigdAdminUrl;
        $queryArgs = [
            'fileKey'     => sanitize_text_field( wp_unslash( $config['fileKey'] ?? '' ) ),
            'page'        => (int) ($config['page'] ?? 1),
            'order'       => sanitize_text_field( wp_unslash( $config['order'] ?? '' ) ),
            'orderBy'     => sanitize_text_field( wp_unslash( $config['orderBy'] ?? '' ) ),
            'search'      => sanitize_text_field( wp_unslash( $config['search'] ?? '' ) ),
            'from'        => sanitize_text_field( wp_unslash( $config['from'] ?? 'cache' ) ),
            'searchScope' => sanitize_text_field( wp_unslash( $config['searchScope'] ?? 'folder' ) ),
            'accountId'   => sanitize_text_field( wp_unslash( $config['accountId'] ?? '' ) ),
            'fileId'      => sanitize_text_field( wp_unslash( $config['fileId'] ?? '' ) ),
            'password'    => sanitize_text_field( wp_unslash( $config['password'] ?? '' ) ),
            'isAdmin'     => $isAdmin,
        ];
        if ( !$id ) {
            self::sendError( __( 'Shortcode ID not found.', 'integration-google-drive' ), 400 );
        }
        try {
            $shortcode = Shortcode::getInstance()->get( $id, $queryArgs );
            if ( is_wp_error( $shortcode ) ) {
                self::handleError( $shortcode, __( 'Failed to retrieve shortcode.', 'integration-google-drive' ) );
            }
            if ( empty( $shortcode ) ) {
                self::sendError( __( 'Shortcode not found.', 'integration-google-drive' ), 404 );
            }
            if ( $queryArgs['page'] > 1 && empty( $shortcode['data']['source']['files'] ) ) {
                self::sendError( __( 'No Page found.', 'integration-google-drive' ), 404 );
            }
            if ( isset( $shortcode['data']['permissions']['passwordProtect']['password'] ) && !current_user_can( 'manage_options' ) ) {
                unset($shortcode['data']['permissions']['passwordProtect']['password']);
            }
            $files = $shortcode['data']['source']['files'] ?? [];
            if ( is_wp_error( $files ) ) {
                self::handleError( $files, __( 'Failed to retrieve files.', 'integration-google-drive' ) );
            }
            $response = [
                'shortcode' => $shortcode,
            ];
            if ( $queryArgs['page'] > 1 && isset( $shortcode['data']['source'] ) ) {
                $response = [
                    'shortcode' => [
                        'data' => [
                            'source' => $shortcode['data']['source'] ?? [],
                        ],
                    ],
                ];
            }
            self::sendSuccess( $response, __( 'Shortcode found successfully!', 'integration-google-drive' ) );
        } catch ( \Throwable $e ) {
            self::handleError( $e, __( 'Failed to retrieve shortcode.', 'integration-google-drive' ) );
        }
    }

    public static function getAll() : void {
        self::verifyRequest();
        $config = self::getRequestData();
        $defaults = [
            'type'    => 'all',
            'search'  => '',
            'status'  => 'all',
            'order'   => 'DESC',
            'orderBy' => 'createdAt',
            'page'    => 1,
            'perPage' => 10,
        ];
        $queryArgs = wp_parse_args( $config, $defaults );
        $queryArgs = array_intersect_key( $queryArgs, array_flip( [
            'type',
            'status',
            'order',
            'orderBy',
            'page',
            'perPage',
            'search'
        ] ) );
        try {
            $shortcodeApi = Shortcode::getInstance();
            $shortcodes = $shortcodeApi->getAll( $queryArgs );
            $total = $shortcodeApi->totalCount( $queryArgs );
            if ( is_wp_error( $shortcodes ) ) {
                self::handleError( $shortcodes, __( 'Failed to retrieve shortcodes.', 'integration-google-drive' ) );
            }
            if ( is_wp_error( $total ) ) {
                self::handleError( $total, __( 'Failed to count shortcodes.', 'integration-google-drive' ) );
            }
            self::sendSuccess( [
                'shortcodes' => $shortcodes,
                'total'      => $total,
                'pagination' => [
                    'page'       => $queryArgs['page'],
                    'perPage'    => $queryArgs['perPage'],
                    'totalPages' => ceil( $total / $queryArgs['perPage'] ),
                ],
            ], __( 'Shortcodes retrieved successfully.', 'integration-google-drive' ) );
        } catch ( \Throwable $e ) {
            self::handleError( $e, __( 'Failed to retrieve shortcodes.', 'integration-google-drive' ) );
        }
    }

    public static function update() : void {
        self::verifyRequest();
        $config = self::getRequestData();
        if ( empty( $config['type'] ) || empty( $config['data'] ) ) {
            self::sendError( __( 'Shortcode type and data is required.', 'integration-google-drive' ), 400 );
        }
        try {
            $shortcodeApi = Shortcode::getInstance();
            $shortcode = $shortcodeApi->add( $config );
            if ( is_wp_error( $shortcode ) ) {
                self::handleError( $shortcode, __( 'Failed to save shortcode.', 'integration-google-drive' ) );
                return;
            }
            $message = ( isset( $config['id'] ) && !empty( $config['id'] ) ? __( 'Shortcode updated successfully.', 'integration-google-drive' ) : __( 'Shortcode created successfully.', 'integration-google-drive' ) );
            self::sendSuccess( [
                'shortcode' => $shortcode,
            ], $message );
        } catch ( \Throwable $e ) {
            self::handleError( $e, __( 'Failed to save shortcode.', 'integration-google-drive' ) );
        }
    }

    public static function duplicate() : void {
        self::verifyRequest();
        $id = (int) self::getPostParam( 'id', 0, 'intval' );
        if ( !$id ) {
            self::sendError( __( 'Shortcode ID is required.', 'integration-google-drive' ), 400 );
        }
        try {
            $shortcodeApi = Shortcode::getInstance();
            $duplicated = $shortcodeApi->duplicate( $id );
            if ( is_wp_error( $duplicated ) ) {
                self::handleError( $duplicated, __( 'Failed to duplicate shortcode.', 'integration-google-drive' ) );
            }
            self::sendSuccess( [
                'shortcode' => $duplicated,
            ], __( 'Shortcode duplicated successfully.', 'integration-google-drive' ) );
        } catch ( \Throwable $e ) {
            self::handleError( $e, __( 'Failed to duplicate shortcode.', 'integration-google-drive' ) );
        }
    }

    public static function delete() : void {
        self::verifyRequest();
        $id = (int) self::getPostParam( 'id', 0, 'intval' );
        if ( !$id ) {
            self::sendError( __( 'Shortcode ID is required.', 'integration-google-drive' ), 400 );
        }
        try {
            $shortcodeApi = Shortcode::getInstance();
            $result = $shortcodeApi->remove( $id );
            if ( is_wp_error( $result ) ) {
                self::handleError( $result, __( 'Failed to delete shortcode.', 'integration-google-drive' ) );
                return;
            }
            if ( !$result ) {
                self::sendError( __( 'Shortcode not found or could not be deleted.', 'integration-google-drive' ), 404 );
                return;
            }
            self::sendSuccess( [], __( 'Shortcode deleted successfully.', 'integration-google-drive' ) );
        } catch ( \Throwable $e ) {
            self::handleError( $e, __( 'Failed to delete shortcode.', 'integration-google-drive' ) );
        }
    }

}
