<?php

namespace CodeConfig\IGD\Ajax;

use CodeConfig\IGD\Models\Notices;

defined('ABSPATH') || exit('No direct script access allowed');

/**
 * AJAX endpoints for handling admin notices.
 */
class Notice extends BaseAjax
{
    /**
     * Return all active notices.
     */
    public static function getNotices(): void
    {
        self::verifyRequest();

        if (!current_user_can('manage_options')) {
            self::sendError(__('You do not have permission to view notices.', 'integration-google-drive'), 403);
        }

        $page    = absint(self::getPostParam('page', 1));
        $perPage = absint(self::getPostParam('perPage', 10));
        $status  = self::getPostParam('status', 'all');

        // Validate status
        $allowedStatuses = ['all', 'read', 'unread'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'all';
        }

        $notices = Notices::getInstance()->getAll(['page' => $page, 'perPage' => $perPage, 'status' => $status]);

        if (is_wp_error($notices)) {
            self::sendError($notices->get_error_message(), $notices->get_error_code());
        }

        self::sendSuccess($notices, __('Notices fetched successfully.', 'integration-google-drive'));
    }

    /**
     * Return a specific notice by ID.
     */
    public static function getNotice(): void
    {
        self::verifyRequest();

        $id     = absint(self::getPostParam('id', 0));
        
        if (empty($id)) {
            self::sendError(__('Notice ID is required.', 'integration-google-drive'), 400);
        }
        
        $notice = Notices::getInstance()->get($id);

        self::sendSuccess($notice ? (array) $notice : [], __('Notice fetched successfully.', 'integration-google-drive'));
    }

    /**
     * Delete a specific notice by ID.
     */
    public static function deleteNotice(): void
    {
        self::verifyRequest();

        $id = absint(self::getPostParam('id', 0));
        
        if (empty($id)) {
            self::sendError(__('Notice ID is required.', 'integration-google-drive'), 400);
        }
        
        Notices::getInstance()->deleteNotice($id);
        self::sendSuccess([], __('Notice deleted successfully.', 'integration-google-drive'));
    }

    /**
     * Clear all notices.
     */
    public static function clearNotices(): void
    {
        self::verifyRequest();

        Notices::getInstance()->deleteAll();
        self::sendSuccess([], __('All notices cleared successfully.', 'integration-google-drive'));
    }

    /**
     * Add a new notice.
     */
    public static function addNotice(): void
    {
        self::verifyRequest();

        $title       = sanitize_text_field(self::getPostParam('title', ''));
        $type        = sanitize_text_field(self::getPostParam('type', ''));
        $description = sanitize_textarea_field(self::getPostParam('description', ''));
        $status      = sanitize_text_field(self::getPostParam('status', 'unread'));

        if (empty($title) || empty($type)) {
            self::sendError(__('Notice title/type is required.', 'integration-google-drive'), 400);
        }

        // Validate type
        $allowedTypes = ['success', 'error', 'warning', 'info'];
        if (!in_array($type, $allowedTypes, true)) {
            self::sendError(__('Invalid notice type.', 'integration-google-drive'), 400);
        }

        // Validate status
        $allowedStatuses = ['read', 'unread'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'unread';
        }

        $notices = Notices::getInstance()->add(['title'=> $title, 'type' => $type, 'description' => $description, 'status' => $status]);

        wp_send_json_success([
            'notices' => $notices,
            'message' => __('Notice added successfully.', 'integration-google-drive'),
        ]);

        self::sendSuccess([$notices], __('Notice added successfully.', 'integration-google-drive'));
    }
    /**
     * Change the status of a notice.
     */
    public static function changeStatus(): void
    {
        self::verifyRequest();

        $id     = absint(self::getPostParam('id', 0));
        $status = sanitize_text_field(self::getPostParam('status', 'read'));
        
        if (empty($id)) {
            self::sendError(__('Notice ID is required.', 'integration-google-drive'), 400);
        }
        
        // Validate status
        $allowedStatuses = ['read', 'unread'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'read';
        }

        Notices::getInstance()->changeStatus($id, $status);

        self::sendSuccess([], __('Notice status changed successfully.', 'integration-google-drive'));
    }

    /**
     * Mark all notice as read
     */
    public static function markAllAsRead(): void
    {
        self::verifyRequest();

        Notices::getInstance()->markAllAsRead();
        self::sendSuccess([], __('All notices marked as read successfully.', 'integration-google-drive'));
    }
}
