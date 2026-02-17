<?php

namespace CodeConfig\IGD;

use CodeConfig\IGD\Ajax\Accounts;
use CodeConfig\IGD\Ajax\Auth;
use CodeConfig\IGD\Ajax\Files;
use CodeConfig\IGD\Ajax\Notice;
use CodeConfig\IGD\Ajax\Permission;
use CodeConfig\IGD\Ajax\Settings;
use CodeConfig\IGD\Ajax\Shortcodes;
use CodeConfig\IGD\Models\Notices;
use CodeConfig\IGD\Utils\Singleton;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class Ajax {
    use Singleton;
    /**
     * Initialize hooks for ajax requests.
     *
     * @return void
     */
    private function doHooks() {
        $this->registerAuthActions();
        $this->registerAccountActions();
        $this->registerSettingActions();
        $this->registerShortcodeActions();
        $this->registerFileActions();
        $this->registerNoticeActions();
        $this->registerPermissionActions();
    }

    private function registerAuthActions() {
        // Authentication
        $this->addAction( 'GetAuthUrl', [Auth::class, 'getAuthUrl'] );
    }

    private function registerAccountActions() {
        // Accounts
        $this->addAction( 'GetAccounts', [Accounts::class, 'get'] );
        $this->addAction( 'DeleteAccount', [Accounts::class, 'delete'] );
    }

    private function registerSettingActions() {
        // Settings
        $this->addAction( 'GetSettings', [Settings::class, 'get'] );
        $this->addAction( 'UpdateSettings', [Settings::class, 'update'] );
    }

    private function registerShortcodeActions() {
        // Shortcodes
        $this->addAction( 'GetShortcode', [Shortcodes::class, 'get'], true );
        $this->addAction( 'GetShortcodes', [Shortcodes::class, 'getAll'] );
        $this->addAction( 'AddShortcode', [Shortcodes::class, 'update'] );
        $this->addAction( 'UpdateShortcode', [Shortcodes::class, 'update'] );
        $this->addAction( 'DuplicateShortcode', [Shortcodes::class, 'duplicate'] );
        $this->addAction( 'DeleteShortcode', [Shortcodes::class, 'delete'] );
    }

    private function registerFileActions() {
        // Files
        $this->addAction( 'GetFile', [Files::class, 'get'] );
        $this->addAction( 'GetFolder', [Files::class, 'getFolder'] );
        $this->addAction( 'GetFolders', [Files::class, 'getFolders'] );
        $this->addAction( 'NewFolder', [Files::class, 'newFolder'] );
        $this->addAction( 'GetResumeUploadUrl', [Files::class, 'getResumeUploadUrl'] );
        $this->addAction( 'Uploaded', [Files::class, 'uploadedFiles'], true );
        $this->addAction( 'UpdateDescription', [Files::class, 'updateDescription'] );
        $this->addAction( 'DeleteFiles', [Files::class, 'delete'] );
        $this->addAction( 'PreviewLink', [Files::class, 'preview'] );
        $this->addAction( 'ShareLink', [Files::class, 'share'], true );
        $this->addAction( 'DownloadLink', [Files::class, 'download'] );
        $this->addAction( 'RenameFile', [Files::class, 'rename'] );
        $this->addAction( 'SearchFiles', [Files::class, 'search'] );
    }

    private function registerNoticeActions() {
        // Notice
        $this->addAction( 'GetNotices', [Notice::class, 'getNotices'] );
        $this->addAction( 'GetNotice', [Notice::class, 'getNotice'] );
        $this->addAction( 'DeleteNotice', [Notice::class, 'deleteNotice'] );
        $this->addAction( 'ClearNotices', [Notice::class, 'clearNotices'] );
        $this->addAction( 'AddNotice', [Notice::class, 'addNotice'] );
        $this->addAction( 'ChangeNotificationStatus', [Notice::class, 'changeStatus'] );
        $this->addAction( 'MarkAllAsRead', [Notice::class, 'markAllAsRead'] );
    }

    private function registerPermissionActions() {
        // Permission
        $this->addAction( 'GetUserRoles', [Permission::class, 'getUserRoles'] );
        $this->addAction( 'GetUserList', [Permission::class, 'getUserList'] );
    }

    /**
     * Add an AJAX action.
     *
     * @param string $hook The action hook name.
     * @param callable $callback The callback function.
     * @param bool $isNopriv Whether the callback should be accessible via the {@link wp_ajax_nopriv_{$hook}} action hook.
     * @param callable $noprivCallback Optional. The callback to be used when the action is accessed via the {@link wp_ajax_nopriv_{$hook}} action hook. If not provided, the same callback will be used.
     */
    private function addAction(
        $hook,
        $callback,
        $isNopriv = false,
        $noprivCallback = null
    ) {
        $hookName = "wp_ajax_ccpigd{$hook}";
        if ( !is_callable( $callback ) ) {
            Notices::getInstance()->add( [
                'type'        => 'error',
                'title'       => 'Invalid callback',
                'description' => "Invalid callback provided for action. Please check your code.",
            ] );
            return;
        }
        add_action( $hookName, $callback );
        if ( $isNopriv ) {
            $noprivHookName = "wp_ajax_nopriv_ccpigd{$hook}";
            $noprivCallback ??= $callback;
            add_action( $noprivHookName, $noprivCallback );
        }
    }

}
