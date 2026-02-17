<?php

namespace CodeConfig\IGD\Ajax;

use WP_Roles;

defined('ABSPATH') || exit('No direct script access allowed');

class Permission extends BaseAjax
{
    public static function getUserRoles()
    {
        self::verifyRequest();

        global $wp_roles;

        if (! isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        $roles = $wp_roles->get_names();

        $rolesList = [];

        foreach ($roles as $role_key => $role_name) {
            $rolesList[] = [
                'roleKey'   => $role_key,
                'roleName'  => $role_name
            ];
        }

        self::sendSuccess([
            'roles' => $rolesList
        ]);
    }

    public static function getUserList()
    {
        self::verifyRequest();

        $args = [
        'orderby' => 'display_name',
        'order'   => 'ASC'
    ];

        $users     = get_users($args);
        $user_list = [];

        foreach ($users as $user) {
            $user_list[] = [
                'id'           => $user->ID,
                'displayName'  => $user->display_name,
            ];
        }

        self::sendSuccess([
            'users' => $user_list
        ]);
    }
}
