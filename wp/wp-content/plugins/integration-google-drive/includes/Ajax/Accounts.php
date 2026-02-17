<?php

namespace CodeConfig\IGD\Ajax;

use CodeConfig\IGD\App\Account;
use CodeConfig\IGD\App\Accounts as AppAccounts;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class Accounts extends BaseAjax {
    public static function get() : void {
        self::verifyRequest( true );
        $accountKey = self::getPostParam( 'accountKey' );
        if ( !empty( $accountKey ) ) {
            try {
                $account = ( ccpigdGetAccountByKey( $accountKey ) ?: AppAccounts::getInstance()->getAccount( $accountKey ) );
                if ( empty( $account ) ) {
                    self::sendError( __( 'Account not found or unauthorized.', 'integration-google-drive' ), 404 );
                }
                self::sendSuccess( [
                    'account' => $account,
                ] );
            } catch ( \Throwable $th ) {
                self::handleError( $th, __( 'Failed to retrieve account.', 'integration-google-drive' ) );
            }
        }
        try {
            $accounts = AppAccounts::getInstance()->getAccounts();
            if ( empty( $accounts ) || is_wp_error( $accounts ) ) {
                self::sendSuccess( [
                    'message' => __( 'No connected accounts found. Please add one first.', 'integration-google-drive' ),
                ], __( 'No connected accounts found. Please add one first.', 'integration-google-drive' ) );
            }
            self::sendSuccess( [
                'accounts' => $accounts,
            ] );
        } catch ( \Throwable $th ) {
            self::handleError( $th, __( 'Failed to retrieve accounts.', 'integration-google-drive' ) );
        }
    }

    public static function delete() : void {
        self::verifyRequest();
        $accountKey = self::getPostParam( 'accountKey' );
        if ( empty( $accountKey ) ) {
            self::sendError( __( 'Account key is required.', 'integration-google-drive' ), 400 );
        }
        $account = ccpigdGetAccountByKey( $accountKey );
        if ( is_wp_error( $account ) ) {
            self::handleError( $account, __( 'Failed to retrieve account.', 'integration-google-drive' ) );
        }
        if ( empty( $account ) || !$account instanceof Account ) {
            self::sendError( __( 'Invalid account key provided.', 'integration-google-drive' ), 404 );
        }
        try {
            $result = AppAccounts::getInstance()->deleteAccount( $account->getId() );
            if ( is_wp_error( $result ) ) {
                self::handleError( $result, __( 'Failed to delete account.', 'integration-google-drive' ) );
            }
            self::sendSuccess( [
                'message' => __( 'Account deleted successfully.', 'integration-google-drive' ),
            ], __( 'Account deleted successfully.', 'integration-google-drive' ) );
        } catch ( \Throwable $th ) {
            self::handleError( $th, __( 'Failed to delete account.', 'integration-google-drive' ) );
        }
    }

}
