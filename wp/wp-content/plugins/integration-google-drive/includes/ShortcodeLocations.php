<?php

namespace CodeConfig\IGD;

use CodeConfig\IGD\Utils\Singleton;
use WPCF7_ContactForm;
defined( 'ABSPATH' ) || exit( 'No direct script access allowed' );
class ShortcodeLocations {
    use Singleton;
    public function __construct() {
        // Monitoring hooks.
        add_action(
            'save_post',
            [$this, 'save_post'],
            10,
            3
        );
        add_action(
            'post_updated',
            [$this, 'post_updated'],
            10,
            3
        );
        add_action( 'wp_trash_post', [$this, 'trash_post'] );
        add_action( 'untrash_post', [$this, 'untrash_post'] );
        add_action( 'delete_post', [$this, 'trash_post'] );
        add_action(
            'elementor/editor/after_save',
            [$this, 'elementor_editor_after_save'],
            10,
            2
        );
    }

    public function elementor_editor_after_save( $post_id, $editor_data ) {
        $post = get_post( $post_id );
        if ( !in_array( $post->post_type, $this->get_post_types(), true ) || !in_array( $post->post_status, $this->get_post_statuses(), true ) ) {
            return;
        }
        $shortcode_ids_before = $this->get_shortcode_ids_by_post_id( $post_id );
        $shortcode_ids_after = [];
        $this->process_element( $editor_data, $shortcode_ids_after );
        $this->update_shortcode_locations( $post, $shortcode_ids_before, $shortcode_ids_after );
    }

    private function process_element( $elements, &$shortcode_ids ) {
        foreach ( $elements as $element ) {
            if ( isset( $element['elType'] ) && $element['elType'] === 'widget' ) {
                $modules = ccpigdGetModules();
                $modules_ids = array_map( fn( $m ) => "ccpigd-{$m['id']}", $modules );
                $modules_ids[] = 'ccpigd-shortcode';
                if ( in_array( $element['widgetType'], $modules_ids, true ) ) {
                    $settings = $element['settings'] ?? [];
                    if ( !empty( $settings['module_data'] ) ) {
                        $module_data = json_decode( stripslashes( $settings['module_data'] ), true );
                        if ( is_array( $module_data ) && isset( $module_data['id'] ) ) {
                            $shortcode_id = intval( $module_data['id'] );
                            if ( $shortcode_id > 0 && !in_array( $shortcode_id, $shortcode_ids, true ) ) {
                                $shortcode_ids[] = $shortcode_id;
                            }
                        }
                    } elseif ( $element['widgetType'] === 'ccpigd-shortcode' && !empty( $settings['select_shortcode'] ) ) {
                        $shortcode_id = intval( $settings['select_shortcode'] );
                        if ( $shortcode_id > 0 && !in_array( $shortcode_id, $shortcode_ids, true ) ) {
                            $shortcode_ids[] = $shortcode_id;
                        }
                    }
                }
            }
            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->process_element( $element['elements'], $shortcode_ids );
            }
        }
    }

    public function save_post( $post_ID, $post, $update ) {
        if ( $update || !in_array( $post->post_type, $this->get_post_types(), true ) || !in_array( $post->post_status, $this->get_post_statuses(), true ) ) {
            return;
        }
        $shortcode_ids = $this->get_shortcode_ids( $post->post_content );
        $this->update_shortcode_locations( $post, [], $shortcode_ids );
    }

    public function post_updated( $post_id, $post_after, $post_before ) {
        if ( !in_array( $post_after->post_type, $this->get_post_types(), true ) || !in_array( $post_after->post_status, $this->get_post_statuses(), true ) ) {
            return;
        }
        $shortcode_ids_before = $this->get_shortcode_ids( $post_before->post_content );
        $shortcode_ids_after = $this->get_shortcode_ids( $post_after->post_content );
        $this->update_shortcode_locations( $post_after, $shortcode_ids_before, $shortcode_ids_after );
    }

    public function trash_post( $post_id ) {
        $post = get_post( $post_id );
        $shortcode_ids_before = $this->get_shortcode_ids( $post->post_content );
        $shortcode_ids_after = [];
        $this->update_shortcode_locations( $post, $shortcode_ids_before, $shortcode_ids_after );
    }

    public function untrash_post( $post_id ) {
        $post = get_post( $post_id );
        $shortcode_ids_before = [];
        $shortcode_ids_after = $this->get_shortcode_ids( $post->post_content );
        $this->update_shortcode_locations( $post, $shortcode_ids_before, $shortcode_ids_after );
    }

    public function update_shortcode_locations(
        $post_after,
        $shortcode_ids_before,
        $shortcode_ids_after,
        $additional_info = []
    ) {
        global $wpdb;
        $table = "{$wpdb->prefix}integration_google_drive_shortcodes";
        $post_id = $post_after->ID;
        $url = get_permalink( $post_id );
        $url = ( $url === false || is_wp_error( $url ) ? '' : $url );
        $shortcode_ids_to_remove = array_diff( $shortcode_ids_before, $shortcode_ids_after );
        $shortcode_ids_to_add = array_diff( $shortcode_ids_after, $shortcode_ids_before );
        foreach ( $shortcode_ids_to_remove as $shortcode_id ) {
            $locations = $this->get_locations_without_current_post( $shortcode_id, $post_id );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update( $table, [
                'locations' => maybe_serialize( $locations ),
            ], [
                'id' => $shortcode_id,
            ] );
        }
        foreach ( $shortcode_ids_to_add as $shortcode_id ) {
            $locations = $this->get_locations_without_current_post( $shortcode_id, $post_id );
            $location = [
                'type'         => $post_after->post_type,
                'title'        => $post_after->post_title,
                'shortcode_id' => $shortcode_id,
                'post_id'      => $post_id,
                'status'       => $post_after->post_status,
                'url'          => $url,
            ];
            $locations[] = wp_parse_args( $additional_info, $location );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update( $table, [
                'locations' => maybe_serialize( $locations ),
            ], [
                'id' => $shortcode_id,
            ] );
        }
    }

    /**
     * Get post types for search in.
     *
     * @return string[]
     * @since 1.0.1
     *
     */
    public function get_post_types() {
        $args = [
            'public'             => true,
            'publicly_queryable' => true,
        ];
        $post_types = get_post_types( $args, 'names', 'or' );
        unset($post_types['attachment']);
        $post_types[] = 'wp_template';
        $post_types[] = 'wp_template_part';
        return $post_types;
    }

    public function get_post_statuses() {
        return [
            'publish',
            'pending',
            'draft',
            'future',
            'private'
        ];
    }

    public function get_shortcode_ids( $content ) {
        $shortcode_ids = [];
        $modules = ccpigdGetModules();
        $modules_id = wp_list_pluck( $modules, 'id' );
        $modules_id_string = implode( '|', $modules_id );
        if ( preg_match_all( 
            /**
             * Extract id from shortcode or block.
             * Examples:
             * [integration-google-drive id="1" ]
             * <!-- wp:integration-google-drive/gallery {"id":"1"} /-->
             * In both, we should find 1.
             */
            '#\\[\\s*integration-google-drive.+id\\s*=\\s*"(\\d+?)".*]|<!-- wp:integration-google-drive/(' . $modules_id_string . '|shortcode) {"id":(\\d+).*?} /-->#',
            $content,
            $matches
         ) ) {
            array_shift( $matches );
            $shortcode_ids = array_map( 'intval', array_unique( array_filter( array_merge( ...$matches ) ) ) );
        }
        return $shortcode_ids;
    }

    /**
     * Get shortcode IDs by post ID from the database.
     *
     * @param int $post_id The post ID to search for
     * @return array Array of shortcode IDs found for the given post ID
     */
    private function get_shortcode_ids_by_post_id( $post_id ) {
        global $wpdb;
        $table = "{$wpdb->prefix}integration_google_drive_shortcodes";
        $search_pattern = "%s:7:\"post_id\";i:{$post_id}%";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $locations_all = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM %i WHERE locations LIKE %s", $table, $search_pattern ), ARRAY_A );
        if ( empty( $locations_all ) ) {
            return [];
        }
        return array_map( fn( $item ) => intval( $item['id'] ), $locations_all );
    }

    private function get_locations_without_current_post( $shortcode_id, $post_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'integration_google_drive_shortcodes';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $locations = $wpdb->get_var( $wpdb->prepare( "SELECT locations FROM %i WHERE id = %d", $table, $shortcode_id ) );
        $locations = ( !empty( $locations ) ? array_values( maybe_unserialize( $locations ) ) : [] );
        if ( !is_array( $locations ) ) {
            $locations = [];
        }
        return array_filter( $locations, fn( $location ) => $location['post_id'] !== $post_id );
    }

}
