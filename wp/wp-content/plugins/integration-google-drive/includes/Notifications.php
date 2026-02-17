<?php

namespace CodeConfig\IGD;

use CodeConfig\IGD\Models\Notices;
use CodeConfig\IGD\Models\Shortcode;
use CodeConfig\IGD\Utils\Singleton;

defined('ABSPATH') || exit('No direct script access allowed');

class Notifications
{
    use Singleton;

    /**
     * Handle notifications for download, upload, or delete actions.
     *
     * @param string $action 'download', 'upload', 'delete', 'new_folder', 'rename', 'move', 'copy', 'view'
     * @param string $shortcodeId
     * @param string|array $fileKeys
     *
     * @return mixed
     */
    private function handleNotification($action, $shortcodeId, $fileKeys, $data = [])
    {
        $notification = Shortcode::getInstance()->getShortcode($shortcodeId, 'notification');

        if (empty($notification['enable'])) {
            return false;
        }

        if (is_string($fileKeys)) {
            $fileKeys = [$fileKeys];
        }

        $files = ccpigdGetFileAttributesByKeys($fileKeys, ['key','name', 'size', 'description', 'thumbnails', 'mimeType']);

        $fileKeysAndNames = implode('; ', array_map(
            fn ($file) => "File key: {$file['key']} and File name: {$file['name']}",
            $files
        ));

        $fileKeys  = implode(', ', array_column($files, 'key'));
        $fileNames = implode(', ', array_column($files, 'name'));

        $userId           = get_current_user_id()             ?? 0;
        $page             = wp_get_referer()                  ?? site_url();
        $userName         = wp_get_current_user()->user_login ?? "Guest";
        $title            = '';
        $type             = 'info';
        $mailDescription  = '';
        $siteName         = get_bloginfo('name');
        $actioned         = "";
        $totalFiles       = count($files);
        $fileText         = $totalFiles === 1 ? "File" : "Files";

        switch ($action) {
            case 'download':
                $title           = "File Downloaded";
                $actioned        = "downloaded";
                $mailDescription = "$totalFiles $fileText has been downloaded from Google Drive via $siteName.";
                break;
            case 'upload':
                $title           = "File Uploaded";
                $actioned        = "uploaded";
                $mailDescription = "$totalFiles new $fileText has been uploaded to Google Drive via $siteName.";
                break;
            case 'delete':
                $type            = 'warning';
                $title           = "File Deleted";
                $actioned        = "deleted";
                $mailDescription = "$totalFiles $fileText has been deleted from Google Drive via $siteName.";
                break;
            case 'new_folder':
                $title           = "New Folder Created";
                $actioned        = "created";
                $mailDescription = "A new folder has been created in Google Drive via $siteName.";
                break;
            case 'rename':
                $title           = "File Renamed";
                $actioned        = "renamed";
                $mailDescription = "A file has been renamed in Google Drive via $siteName.";
                break;
            case 'move':
                $title           = "File Moved";
                $actioned        = "moved";
                $mailDescription = "$totalFiles $fileText has been moved to a new location in Google Drive via $siteName.";
                break;
            case 'copy':
                $title           = "File Copied";
                $actioned        = "copied";
                $mailDescription = "$totalFiles $fileText has been copied in Google Drive via $siteName.";
                break;
            case 'create_share_link':
                $title           = "Share Link Created";
                $actioned        = "created";
                $mailDescription = "$totalFiles share link has been created in Google Drive via $siteName.";
                break;
            case 'view_share_file':
                $title           = "Share Link Viewed";
                $actioned        = "viewed";
                $mailDescription = "$totalFiles shared file link has been viewed in Google Drive via $siteName.";
                break;
        }

        $description = sprintf(
            'User #%s "%s", has "%s" the following files on page "%s": %s',
            $userId,
            $userName,
            $actioned,
            $page,
            $fileKeysAndNames
        );

        if (!empty($notification[$action]) && in_array('dashboard', $notification['enable'])) {
            Notices::getInstance()->add([
                'type'        => $type,
                'title'       => $title,
                'description' => $description,
                'userId'      => $userId,
                'page'        => $page,
                'fileKey'     => $fileKeys,
                'fileName'    => $fileNames,
                'moduleId'    => $shortcodeId
            ]);
        }

        if (!empty($notification[$action]) && in_array('email', $notification['enable'])) {
            $recipients = $notification['emailRecipients'] ?? [];
            $isSkipCU   = $notification['skipCurrentUser'] ?? false;
            $this->processEmailNotification($type, $shortcodeId, $files, $title, $mailDescription, $userId, $userName, $page, $recipients, $isSkipCU);
        }
    }

    /**
     * Trigger a notification for the given action and shortcode ID.
     *
     * @param string $action The action being performed. Examples: 'download', 'upload', 'delete'.
     * @param string $shortcodeId The shortcode ID for the notification.
     * @param string|array $fileKeys The file keys or an array of file keys that have been acted upon.
     *
     * @return mixed
     */
    public function notify($action, $shortcodeId, $fileKeys, $additionalData = [])
    {
        $allowed = [
            'download',
            'upload',
            'delete',
            'new_folder',
            'copy',
            'move',
            'rename',
            'create_share_link',
            'view_share_file',
        ];

        if (!in_array($action, $allowed, true)) {
            Notices::getInstance()->add([
                'type'        => 'error',
                'title'       => 'Invalid Notification Action',
                'description' => sprintf('The action "%s" is not supported for notifications.', $action),
                'userId'      => get_current_user_id(),
                'page'        => wp_get_referer(),
                'fileKey'     => is_array($fileKeys) ? implode(', ', $fileKeys) : $fileKeys,
                'moduleId'    => $shortcodeId
            ]);
        }

        $data = [];

        if (!empty($additionalData) && in_array($action, ['copy', 'move'])) {
            $data = [
                'oldFolder'    => $additionalData['oldFolder'] ?? '',
                'newFolder'    => $additionalData['newFolder'] ?? '',
            ];
        }

        return $this->handleNotification($action, $shortcodeId, $fileKeys, $data);
    }

    private function processEmailNotification(string $type, string $shortcodeId, array $files, string $title, string $description, int $userId, string $userName, string $page, string $emailStrings, bool $isSkipCU)
    {

        $recipients       = $this->resolve_emails($emailStrings, $isSkipCU);
        $site_title       = get_bloginfo('name');
        $site_admin_email = get_bloginfo('admin_email');

        $to      = $recipients['to']    ?? null;
        $bcc     = $recipients['bcc']   ?? [];
        $subject = sprintf('%s | %s', $site_title, $title);

        if (empty($to)) {
            if ($isSkipCU) {
                return;
            }

            $to = $site_admin_email;
        }

        ob_start();
        ccpigdGetTemplate('notifications/email__premium_only', [
            'type'        => $type,
            'shortcodeId' => $shortcodeId,
            'files'       => $files,
            'subject'     => $subject,
            'description' => $description,
            'userId'      => $userId,
            'userName'    => $userName,
            'page'        => $page,
            'userEmail'   => wp_get_current_user()->user_email ?? '',
        ]);
        $message = ob_get_clean();

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . sprintf('%s <%s>', $site_title, $site_admin_email)
        ];

        if (!empty($bcc) && is_array($bcc)) {
            $headers[] = 'Bcc: ' . implode(',', $bcc);
        }

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Resolve email placeholders into structured array for wp_mail()
     *
     * @param string $emailsString
     * @return array
     */
    private function resolve_emails(string $emailsString, bool $skipCU = false): array
    {
        $admin_email = get_option('admin_email');

        $current_user_email = '';
        if (is_user_logged_in()) {
            $user               = wp_get_current_user();
            $current_user_email = $user->user_email;
        }

        $emailsString = str_replace('%admin_email%', $admin_email, $emailsString);
        $emailsString = str_replace('%current_user_email%', $current_user_email, $emailsString);

        $parts = preg_split('/[\s,]+/', $emailsString);

        $emails = [];
        foreach ($parts as $email) {
            $email = trim($email);
            if (!empty($email) && is_email($email)) {
                $emails[] = $email;
            }
        }

        $emails = array_values(array_unique($emails));

        if ($skipCU && $current_user_email) {
            $emails = array_values(array_diff($emails, [$current_user_email]));
        }

        $result = [
            'to'    => null,
            'bcc'   => null,
        ];

        if (empty($emails)) {
            return $result;
        } elseif (count($emails) === 1) {
            $result['to']  = $emails[0];
            $result['bcc'] = null;
        } elseif (count($emails) > 1) {
            $result['to']  = $emails[0];
            $result['bcc'] = array_slice($emails, 1);
        }

        return $result;
    }
}
