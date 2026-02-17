<?php

namespace CodeConfig\IGD;

use CodeConfig\IGD\App\App;
use CodeConfig\IGD\App\Authorization;
use CodeConfig\IGD\App\Stream;
use CodeConfig\IGD\Models\Attachment;
use CodeConfig\IGD\Models\Notices;
use CodeConfig\IGD\Utils\Helpers;
use CodeConfig\IGD\Utils\Singleton;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class Content {
    use Singleton;
    private $fileKey;

    private $shortcodeId;

    private $action = '';

    private $isAdmin = false;

    private $extension = null;

    private function doHooks() {
        add_filter( 'query_vars', [$this, 'addQueryVars'] );
        add_action( 'template_redirect', [$this, 'redirectTemplate'] );
    }

    public function addQueryVars( $vars ) {
        return array_merge( $vars, [
            'ccpigd-share',
            'ccpigd-thumbnail',
            'ccpigd-action',
            'ccpigd-key',
            'ccpigd-name',
            'ccpigd-ext',
            'authorization',
            'code'
        ] );
    }

    public function redirectTemplate() {
        foreach ( [
            'authorization'    => fn( $val ) => $this->doingAuth( $val, get_query_var( 'code', '' ) ),
            'ccpigd-share'     => fn( $val ) => $this->share( $val ),
            'ccpigd-thumbnail' => fn( $val ) => $this->thumbnail( $val ),
            'ccpigd-action'    => fn( $val ) => $this->ccpigdUrl(
                $val,
                get_query_var( 'ccpigd-key', 'full' ),
                get_query_var( 'ccpigd-name', 'unknown' ),
                get_query_var( 'ccpigd-ext', 'jpg' )
            ),
        ] as $queryVar => $callback ) {
            $value = get_query_var( $queryVar, false );
            if ( $value ) {
                $callback( sanitize_text_field( wp_unslash( $value ) ) );
                return;
            }
        }
    }

    private function ccpigdUrl(
        $action,
        $key,
        $name,
        $ext
    ) {
        if ( $action === 'authorize' ) {
            $this->authorization( $key );
            return;
        }
        $explodedAction = explode( '-', $action );
        $action = reset( $explodedAction );
        $shortcodeId = $explodedAction[1] ?? null;
        $this->shortcodeId = $shortcodeId;
        $this->fileKey = $key;
        $this->action = $action;
        $this->isAdmin = str_contains( wp_get_referer(), admin_url() );
        $this->extension = $ext;
        if ( !in_array( $action, ['attachment', 'preview', 'download'], true ) ) {
            $this->denyAccess( 'Invalid action!', 400 );
        }
        $nameArr = explode( '-', $name );
        $sizeWithExt = end( $nameArr );
        $extractSize = explode( '.', $sizeWithExt );
        $size = reset( $extractSize );
        if ( !in_array( $size, [
            'thumbnail',
            'medium',
            'large',
            'full'
        ] ) ) {
            if ( !preg_match( '/^\\d+x\\d+$/', $size ) ) {
                $size = 'full';
            } else {
                $dimensions = explode( 'x', $size );
                if ( count( $dimensions ) !== 2 || !is_numeric( $dimensions[0] ) || !is_numeric( $dimensions[1] ) ) {
                    $size = 'full';
                } else {
                    $width = (int) $dimensions[0];
                    $height = (int) $dimensions[1];
                    $size = ( $width <= 40 || $height <= 40 ? 'full' : "{$width}x{$height}" );
                }
            }
        }
        if ( $action === 'attachment' ) {
            $this->attachment( $key, $size, $ext );
            return;
        } elseif ( $action === 'preview' ) {
            $this->preview( $key );
            return;
        } elseif ( $action === 'download' ) {
            $this->download( $key );
            return;
        }
    }

    /* -------------------------
     * Helpers
     * ------------------------- */
    private function safeRedirect( string $url, $cache = HOUR_IN_SECONDS, $status = 302 ) : void {
        header( "Referrer-Policy: no-referrer" );
        header( "Cache-Control: public, max-age={$cache}" );
        wp_safe_redirect( $url, $status, CCPIGD_NAME . ' Safe Redirect' );
        exit;
    }

    private function safeProxy( string $url, $cache = HOUR_IN_SECONDS ) : void {
        header( "Referrer-Policy: no-referrer" );
        $response = wp_remote_get( $url, [
            'timeout'     => 15,
            'redirection' => 5,
            'sslverify'   => false,
        ] );
        if ( is_wp_error( $response ) ) {
            $this->safeRedirect( $this->getUnknownIcon( 'image/jpeg' ), 0 );
            exit;
        }
        $data = wp_remote_retrieve_body( $response );
        $contentType = wp_remote_retrieve_header( $response, 'content-type' );
        // Whitelist of safe content types to prevent XSS and other security issues
        $allowedContentTypes = [
            'application/vnd.google-apps.spreadsheet',
            'application/vnd.google-apps.folder',
            'application/vnd.google-apps.document',
            'application/vnd.google-apps.presentation',
            'application/vnd.google-apps.script',
            'application/vnd.google-apps.form',
            'application/vnd.google-apps.drawing',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/pdf',
            'text/plain',
            'text/csv',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'audio/mpeg',
            'audio/wav',
            'video/mp4',
            'video/x-msvideo'
        ];
        // Extract the base content type (remove charset and other parameters)
        $baseContentType = ( $contentType ? explode( ';', $contentType )[0] : '' );
        $baseContentType = trim( $baseContentType );
        // Validate content type is in the allowed list
        if ( !in_array( $baseContentType, $allowedContentTypes, true ) ) {
            $this->safeRedirect( $this->getUnknownIcon( 'image/jpeg' ), 0 );
            exit;
        }
        if ( $data ) {
            header( "Content-Type: {$baseContentType}" );
            header( "Cache-Control: public, max-age={$cache}" );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $data;
        } else {
            $this->safeRedirect( $this->getUnknownIcon( 'image/jpeg' ), 0 );
        }
        exit;
    }

    private function denyAccess( string $message = 'Access denied!', int $status = 403 ) : void {
        wp_die( esc_html( $message ), 'Invalid Request', [
            'response' => intval( $status ),
        ] );
        exit;
    }

    private function getUnknownIcon( string $mimeType = 'application/octet-stream' ) : string {
        return CCPIGD_ASSETS . '/images/icons/file.png';
    }

    /* -------------------------
     * Stream
     * ------------------------- */
    private function stream( string $key ) : void {
        new Stream($key);
    }

    /* -------------------------
     * Download
     * ------------------------- */
    private function download( string $key ) : void {
        // if (!wp_get_referer()) {
        //     $this->denyAccess('You are not allowed to download this file.', 400);
        // }
        $file = ccpigdGetFileByKey( $key );
        if ( is_wp_error( $file ) ) {
            $this->safeRedirect( $this->getUnknownIcon( 'image/jpeg' ), 0 );
        }
        $downloadLink = '';
        if ( empty( $downloadLink ) && ($this->isAdmin || Attachment::exists( $file['key'] )) ) {
            $downloadLink = App::getInstance( $file['accountId'] )->download( $file['id'], ccpigdGetMimeTypeByExtension( $this->extension ) ?? $file['mimeType'] );
        }
        if ( is_wp_error( $downloadLink ) ) {
            $this->denyAccess( $downloadLink->get_error_message(), 500 );
        }
        if ( empty( $downloadLink ) ) {
            $this->denyAccess( 'You are not allowed to download this file.' );
        }
        $this->safeRedirect( $downloadLink, $file["lifeTime"] ?? 0 );
    }

    /* -------------------------
     * Preview
     * ------------------------- */
    private function preview( string $key ) : void {
        // if (!wp_get_referer()) {
        //     $this->denyAccess('You are not allowed to access this page.', 400);
        // }
        if ( empty( $key ) || !$this->isAdmin && empty( $this->shortcodeId ) ) {
            $this->safeRedirect( $this->getUnknownIcon( 'image/jpeg' ), 0 );
        }
        $file = ccpigdGetFileByKey( $key );
        if ( is_wp_error( $file ) ) {
            $this->safeRedirect( $this->getUnknownIcon( 'image/jpeg' ), 0 );
        }
        $previewLink = '';
        if ( empty( $previewLink ) && ($this->isAdmin || Attachment::exists( $file['key'] )) ) {
            $previewLink = App::getInstance( $file['accountId'] )->preview( [
                'fileId' => $file['id'],
            ] );
        }
        if ( is_wp_error( $previewLink ) ) {
            $this->denyAccess( $previewLink->get_error_message(), 500 );
        }
        if ( empty( $previewLink ) ) {
            $this->safeRedirect( $this->getUnknownIcon( $file['mimeType'] ?? 'image/jpeg' ), 0 );
        }
        $this->safeRedirect( $previewLink, $file["lifeTime"] ?? 0 );
    }

    /* -------------------------
     * Attachment
     * ------------------------- */
    private function attachment( string $key, string $size, ?string $ext ) : void {
        $size = ccpigdSizeToString( $size );
        $file = App::getInstance()->getFileByKey( $key );
        if ( is_wp_error( $file ) || empty( $file ) ) {
            $this->safeRedirect( $this->getUnknownIcon( ccpigdGetMimeTypeByExtension( $ext ) ), 0 );
        }
        $this->processThumbnail( $file, $size, ( $ext === $file['extension'] ? $file['mimeType'] : 'image/jpeg' ) );
    }

    private function processThumbnail( $file, string $size = 'full', string $mimeType = 'application/octet-stream' ) : void {
        if ( !is_array( $file ) || empty( $file ) ) {
            $this->safeRedirect( $this->getUnknownIcon( $mimeType ), 0 );
        }
        if ( !str_starts_with( $mimeType, 'image/' ) && !$this->isEditable( $mimeType ) ) {
            if ( empty( $this->shortcodeId ) && !$this->isAdmin ) {
                if ( !Attachment::exists( $file['key'] ) ) {
                    $this->safeRedirect( $this->getUnknownIcon( $mimeType ), 0 );
                }
            }
            new Stream($file['key']);
            return;
        }
        if ( $this->isEditable( $mimeType ) ) {
            if ( empty( $this->shortcodeId ) ) {
                if ( !Attachment::exists( $file['key'] ) ) {
                    $this->safeRedirect( $this->getUnknownIcon( $mimeType ), 0 );
                }
            } elseif ( !empty( $this->shortcodeId ) ) {
                $this->safeRedirect( $this->getUnknownIcon( $mimeType ), 0 );
            }
            $this->download( $file['key'] );
        }
        if ( !empty( $file['needToSync'] ) && !$this->isUrlReachable( $file['thumbnailLink'] ?? '' ) ) {
            $file = App::getInstance( $file['accountId'] )->getFile( $file['id'], $file['accountId'], true );
        }
        if ( empty( $file ) || empty( $file['thumbnailLink'] ) ) {
            $this->safeRedirect( $this->getUnknownIcon( $file['mimeType'] ?? 'image/jpeg' ), 0 );
        }
        $thumbnailUrl = str_replace( '=s220', ( $size ? "={$size}" : '' ), $file['thumbnailLink'] );
        $redirection = Helpers::getSetting( 'integrations.mediaLibrary.redirection', true );
        if ( $redirection ) {
            $this->safeRedirect( apply_filters( 'ccpigd_thumbnail_url', $thumbnailUrl ), $file['lifeTime'] ?? 0 );
        } else {
            $this->safeProxy( apply_filters( 'ccpigd_thumbnail_url', $thumbnailUrl ), $file['lifeTime'] ?? 0 );
        }
    }

    private function thumbnail( $dataString ) {
        if ( $dataString ) {
            $thumbnail = Helpers::decode( $dataString );
            $thumbnail = json_decode( $thumbnail, true );
            $mimeType = $thumbnail['mimeType'] ?? 'application/octet-stream';
            $unknownIcon = $this->getUnknownIcon( $mimeType );
            if ( empty( $thumbnail['key'] ) || empty( $thumbnail['sz'] ) ) {
                $this->safeRedirect( $unknownIcon, 0 );
                exit;
            }
            $file = App::getInstance()->getFileByKey( $thumbnail['key'] ?? '' );
            if ( is_wp_error( $file ) || empty( $file ) ) {
                $this->safeRedirect( $unknownIcon, 0 );
            }
            $this->processThumbnail( $file, $thumbnail['sz'] ?? '', 'image/jpeg' );
        }
    }

    private function isUrlReachable( string $url ) : bool {
        if ( !filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }
        $response = wp_remote_head( $url );
        return !is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
    }

    private function share( string $share ) : void {
        $shareData = json_decode( Helpers::decode( $share ), true );
        $fileKey = sanitize_text_field( wp_unslash( $shareData['fileKey'] ?? '' ) );
        if ( empty( $fileKey ) ) {
            wp_safe_redirect( home_url(), 301 );
            exit;
        }
        $isAdmin = filter_var( $shareData['isAdmin'] ?? false, FILTER_VALIDATE_BOOLEAN );
        $shortcodeId = $shareData['shortcodeId'] ?? null;
        $key = sanitize_text_field( wp_unslash( $shareData['key'] ?? '' ) );
        $lifetime = intval( $shareData['lifetime'] ?? 1 );
        $referer = sanitize_text_field( wp_unslash( $shareData['referer'] ?? '' ) );
        $transientKey = "ccpigd_share_{$key}";
        $transientValue = maybe_unserialize( get_transient( $transientKey ) );
        if ( empty( $transientValue ) || !is_array( $transientValue ) ) {
            wp_die( esc_html__( 'Access denied! You do not have permission to access this resource.', 'integration-google-drive' ), 'Invalid Request', [
                'response' => 403,
            ] );
        }
        // Check if a password is required
        $passwordTransientKey = "ccpigd_share_password_{$key}";
        $passwordTransient = get_transient( $passwordTransientKey );
        if ( $passwordTransient && isset( $_SERVER['REQUEST_METHOD'] ) && sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) === 'POST' ) {
            $this->handleSharePassword( $passwordTransient, $fileKey, $shortcodeId );
        }
        if ( $passwordTransient ) {
            $this->renderPasswordForm();
        }
        // Validate transient vs incoming data
        $this->validateShareAccess(
            $transientValue,
            $fileKey,
            $shortcodeId,
            $isAdmin,
            $referer,
            $lifetime,
            $key
        );
        // Finally redirect to Google Drive
        $this->redirectToShare( $fileKey, $shortcodeId );
    }

    /**
     * Validate the access based on transient data.
     */
    private function validateShareAccess(
        array $transient,
        string $fileKey,
        ?int $shortcodeId,
        bool $isAdmin,
        string $referer,
        int $lifetime,
        string $key
    ) : void {
        $conditions = [
            empty( $shortcodeId ) && !$isAdmin,
            $shortcodeId !== ($transient['shortcodeId'] ?? null),
            $isAdmin !== ($transient['isAdmin'] ?? false),
            $fileKey !== ($transient['fileKey'] ?? null),
            $referer !== ($transient['referer'] ?? ''),
            (int) ($transient['lifetime'] ?? 0) !== $lifetime,
            empty( $key )
        ];
        if ( in_array( true, $conditions, true ) ) {
            wp_die( esc_html__( 'Access denied! You do not have permission to access this resource.', 'integration-google-drive' ), 'Invalid Request', [
                'response' => 403,
            ] );
        }
    }

    /**
     * Handle password submission for shared file.
     */
    private function handleSharePassword( string $passwordTransient, string $fileKey, ?int $shortcodeId ) : void {
        if ( !isset( $_POST['ccpigd_nonce'] ) || !wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ccpigd_nonce'] ?? '' ) ), 'ccpigd_form_action' ) ) {
            return;
        }
        $password = sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) );
        if ( $password === $passwordTransient ) {
            $file = ccpigdGetFileByKey( $fileKey );
            if ( is_wp_error( $file ) ) {
                wp_die( esc_html( $file->get_error_message() ), 'Invalid Request', [
                    'response' => 403,
                ] );
            }
            if ( $shortcodeId ) {
                Notifications::getInstance()->notify( 'view_share_file', $shortcodeId, $fileKey );
            }
            $shareLink = ( $file['isDirectory'] ?? false ? "https://drive.google.com/drive/folders/{$file['id']}?usp=sharing" : "https://drive.google.com/file/d/{$file['id']}/view?usp=sharing" );
            $this->safeRedirect( $shareLink, $file["lifeTime"] ?? 0 );
        } else {
            $message = __( 'Wrong password! Please try again.', 'integration-google-drive' );
            $description = sprintf( "A User '%s' tried to access a shared file with an incorrect password.", wp_get_current_user()->user_login ?? 'Guest' );
            Notices::getInstance()->add( [
                'type'        => 'error',
                'title'       => __( 'Share Link Password Error', 'integration-google-drive' ),
                'description' => $description,
            ] );
            $this->renderPasswordForm( $message );
        }
    }

    /**
     * Render the password form for shared file access.
     */
    private function renderPasswordForm( string $message = '' ) : void {
        status_header( 200 );
        nocache_headers();
        ?>
            <!DOCTYPE html>
            <html lang="en">

            <head>
                <title><?php 
        esc_attr__( 'CCPIGD Shared Form', 'integration-google-drive' );
        ?></title>
                <meta name="viewport" content="width=device-width, initial-scale=1.0" />
                <style>
                    * {
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                        text-decoration: none;
                        border: none;
                        outline: none;
                        scroll-behavior: smooth;
                    }

                    .ccpigd-password {
                        --primary: #15be7c;
                        --secondary: #1d9265ff;
                        --light: hsl(from var(--primary) h s l / 11%);
                        --extra-light: hsl(from var(--primary) h s l / 1%);
                        --white: #ffffff;
                        background: var(--extra-light);
                        margin: clamp(30px, 10vw, 100px) 0;
                        padding: 0 clamp(10px, 5vw, 30px);

                    }

                    .ccpigd-password .ccpigd-password-field {
                        font-family: Arial, Helvetica, sans-serif;
                        width: 100%;
                        max-width: 1024px;
                        margin: auto;
                        border: 1px solid var(--light);
                        padding: clamp(15px, 2vw, 30px);
                        border-radius: 12px;
                        background: var(--white);

                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper {
                        width: 100%;
                        text-align: center;
                        display: flex;
                        align-items: center;
                        flex-direction: column;
                        gap: 20px;
                        position: relative;
                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-content,
                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input {
                        width: 100%;
                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-content .ccpigd-icon {
                        height: clamp(30px, 10vw, 100px);
                        aspect-ratio: 1 / 1;
                        fill: var(--primary);

                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-content .ccpigd-password-field__title {
                        font-size: clamp(20px, 5vw, 30px);
                        font-weight: 600;
                        line-height: 1.2em;
                        color: #000000;
                        margin-bottom: 10px;
                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-content .ccpigd-password-field__description {
                        font-size: clamp(14px, 3vw, 18px);
                        font-weight: 400;
                        line-height: 1.2em;
                        color: #424242ff;
                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input {
                        display: flex;
                        align-items: stretch;
                        justify-content: center;
                        gap: 10px;
                        flex-wrap: wrap;
                        padding-bottom: 10px;

                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input .ccpigd-input {
                        width: 100%;
                        max-width: 400px;
                        background: var(--white);
                        border: 1px solid;
                        border-color: var(--light);
                        border-radius: 4px;
                        text-align: left;
                        padding: 10px 15px;
                        transition: all 0.3s ease;
                        position: relative;

                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input .ccpigd-input:hover {
                        border-color: var(--primary);
                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input .ccpigd-input input[type="password"] {
                        font-family: Verdana, sans-serif;
                        font-size: clamp(14px, 3vw, 20px);
                        color: #000000;
                        width: 100%;
                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input .ccpigd-submit-btn {
                        background: var(--primary);
                        color: var(--white);
                        padding: 8px 15px;
                        border-radius: 4px;
                        font-size: clamp(14px, 3vw, 18px);
                        cursor: pointer;
                        transition: all 0.3s ease;
                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input .ccpigd-submit-btn:hover {
                        background: var(--secondary);
                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input .ccpigd-submit-btn {
                        background: var(--primary);
                        color: var(--white);
                        padding: 8px 15px;
                        border-radius: 4px;
                        font-size: clamp(14px, 3vw, 18px);
                        cursor: pointer;
                        transition: all 0.3s ease;

                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input .ccpigd-submit-btn:hover {
                        background: var(--secondary);
                    }

                    .ccpigd-password .ccpigd-password-field .ccpigd-password-field__wrapper .ccpigd-password-field__wrapper-input .ccpigd-input .ccpigd-password-field__wrapper-error {
                        position: absolute;
                        bottom: -20px;
                        left: 0;
                        font-size: 14px;
                        font-weight: 500;
                        color: red;
                    }
                </style>
            </head>

            <body class="ccpigd-password">
                <div class="ccpigd-password-field">
                    <div class="ccpigd-password-field__wrapper">
                        <div class="ccpigd-password-field__wrapper-content">
                            <svg class="ccpigd-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960">
                                <path d="M420-360h120l-23-129q20-10 31.5-29t11.5-42q0-33-23.5-56.5T480-640q-33 0-56.5 23.5T400-560q0 23 11.5 42t31.5 29l-23 129Zm60 280q-139-35-229.5-159.5T160-516v-244l320-120 320 120v244q0 152-90.5 276.5T480-80Zm0-84q104-33 172-132t68-220v-189l-240-90-240 90v189q0 121 68 220t172 132Zm0-316Z" />
                            </svg>
                            <h5 class="ccpigd-password-field__title"><?php 
        esc_attr_e( 'You do not have access to this file.', 'integration-google-drive' );
        ?></h5>
                            <p class="ccpigd-password-field__description"><?php 
        esc_attr_e( 'Enter the secret password to access this.', 'integration-google-drive' );
        ?></p>
                        </div>

                        <form method="post" class="ccpigd-password-field__wrapper-input">
                            <?php 
        wp_nonce_field( 'ccpigd_form_action', 'ccpigd_nonce' );
        ?>
                            <div class="ccpigd-input">
                                <input id="password" type="password" name="password" placeholder="Enter Password" class="ccpigd-input__input" aria-invalid="false" required>
                                <?php 
        if ( !empty( $message ) ) {
            echo '<p class="ccpigd-password-field__wrapper-error">' . esc_html( $message ) . '</p>';
        }
        ?>
                            </div>

                            <button type="submit" class="ccpigd-submit-btn">
                                <?php 
        esc_attr_e( 'Submit', 'integration-google-drive' );
        ?>
                            </button>
                        </form>
                    </div>
                </div>
            </body>

            </html>
            <?php 
        exit;
    }

    private function redirectToShare( string $fileKey, ?int $shortcodeId = null ) : void {
        $file = ccpigdGetFileByKey( $fileKey );
        if ( is_wp_error( $file ) || empty( $file['id'] ) ) {
            $this->denyAccess();
        }
        if ( $shortcodeId ) {
            Notifications::getInstance()->notify( 'view_share_file', $shortcodeId, $fileKey );
        } else {
            Notices::getInstance()->add( [
                'type'        => 'info',
                'title'       => __( 'Viewed shared file', 'integration-google-drive' ),
                'description' => sprintf(
                    "User '%s' viewed a shared file, file: %s (%s)",
                    wp_get_current_user()->user_login ?? 'Guest',
                    $file['name'] ?? 'Unknown',
                    $fileKey
                ),
            ] );
        }
        $link = ( $file['isDirectory'] ?? false ? "https://drive.google.com/drive/folders/{$file['id']}?usp=sharing" : "https://drive.google.com/file/d/{$file['id']}/view?usp=sharing" );
        // $previewLink = App::getInstance($file['accountId'])->preview(['fileId' => $file['id']]);
        // if (! empty($previewLink)) {
        //     printf('<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0" /><meta http-equiv="Content-Type" content="text/html; charset=utf-8" /><title>%s | %s</title></head><body style="margin:0px; padding:0px; overflow:hidden;"><iframe src="%s" style="width:100%%; height:100vh; border:none;"></iframe></body></html>', get_bloginfo('name') ?? CCPIGD_NAME, esc_html($file['name']), esc_url($previewLink));
        //     exit;
        // }
        $this->safeRedirect( $link, $file["lifeTime"] ?? 0 );
    }

    private function embedFile( array $file ) : void {
        if ( empty( $file ) || empty( $file['id'] ) ) {
            $this->safeRedirect( $this->getUnknownIcon( 'application/octet-stream' ), 0 );
        }
        // $previewLink = App::getInstance($file['accountId'])->preview(['fileId' => $file['id']]);
        // if (is_wp_error($previewLink) || empty($previewLink)) {
        //     $this->safeRedirect($this->getUnknownIcon($file['mimeType'] ?? 'application/octet-stream'));
        // }
        // printf(
        //     '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0" /><meta http-equiv="Content-Type" content="text/html; charset=utf-8" /><title>%s | %s</title></head><body style="margin:0px; padding:0px; overflow:hidden;"><iframe src="%s" style="width:100%%; height:100vh; border:none;"></iframe></body></html>',
        //     get_bloginfo('name') ?? CCPIGD_NAME,
        //     esc_html($file['name'] ?? 'Document'),
        //     esc_url($previewLink)
        // );
        $link = 'https://docs.google.com/spreadsheets/d/1CTzhMm9YTR-5zC8DzAfDKp-WddYgCKX2bHtDuesp0yo/edit?usp=drivesdk&rm=embedded&embedded=true';
        $this->safeRedirect( $link );
        exit;
    }

    private function isEditable( $mimeType ) {
        $editorMimes = [
            'application/vnd.google-apps.document',
            'application/vnd.google-apps.spreadsheet',
            'application/vnd.google-apps.presentation',
            'application/vnd.google-apps.form',
            'application/vnd.google-apps.drawing',
            'application/vnd.google-apps.map',
            'application/vnd.google-apps.script',
            'application/vnd.google-apps.site',
            'application/vnd.google-apps.jam',
            'application/vnd.google-apps.script+json',
            'application/vnd.google-apps.script+webapp',
            'application/vnd.google-apps.addon'
        ];
        return in_array( $mimeType, $editorMimes, true );
    }

    private function authorization( $key ) {
        if ( empty( $key ) ) {
            $this->denyAccess( 'Invalid authorization code!', 400 );
        }
        Authorization::getInstance()->doingAuth( urldecode( base64_decode( $key ) ) );
    }

    private function doingAuth( $action, $code ) {
        if ( 'integration-google-drive' !== $action || empty( $code ) ) {
            return;
        }
        Authorization::getInstance()->doingAuth( $code );
    }

}
