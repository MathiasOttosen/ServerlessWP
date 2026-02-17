<?php

namespace CodeConfig\IGD\Integrations\Forms;

use CodeConfig\IGD\Integrations\BaseIntegration;
use CodeConfig\IGD\Shortcode;
use CodeConfig\IGD\Utils\Singleton;
use WPCF7_FormTag;
use WPCF7_Submission;
use WPCF7_TagGenerator;
use WPCF7_TagGeneratorGenerator;

defined('ABSPATH') or exit;

class ContactForm7 extends BaseIntegration
{
    use Singleton;
    public function __construct()
    {
        parent::__construct('contactForm7', 'Contact Form 7');
    }

    public function init(string $id, array $integration): void
    {
        add_action('wpcf7_init', [$this, 'wpcf7Init']);
        add_action('wpcf7_admin_init', [$this, 'addTagGenerator'], 99);

        add_filter('wpcf7_validate_google_drive', [ $this, 'validateField' ], 10, 2);
        add_filter('wpcf7_validate_google_drive*', [ $this, 'validateField' ], 10, 2);
    }

    public function wpcf7Init(): void
    {
        if (function_exists('wpcf7_add_form_tag')) {
            wpcf7_add_form_tag(['google_drive', 'google_drive*'], [$this, 'googleDriveTag'], true);
        }
    }

    public function googleDriveTag($tag)
    {
        $tag = new WPCF7_FormTag($tag);

        if (empty($tag->name)) {
            return '';
        }

        $validation_error = wpcf7_get_validation_error($tag->name);
        $class            = wpcf7_form_controls_class($tag->type, 'upload-file-list ccpigd-hidden');

        if ($validation_error) {
            $class .= ' wpcf7-not-valid';
        }

        $moduleId     = $tag->get_option('id', '', true);
        $integration  = 'contactForm7';


        if ('*' === substr($tag->type, -1)) {
            $integration .= '*';
        }

        $atts = [
            'name'          => $tag->name,
            'type'          => 'hidden',
            'class'         => $class,
            'tabindex'      => $tag->get_option('tabindex', 'signed_int', true),
            'aria-invalid'  => $validation_error ? 'true' : 'false',
            'aria-required' => $tag->is_required() ? 'true' : 'false',
        ];

        $atts_str = wpcf7_format_atts($atts);
        $user_id  = esc_attr(get_current_user_id());
        $tag_name = esc_attr($tag->name);

        $shortcode = Shortcode::getInstance()->render([
            'id'          => $moduleId,
            'integration' => $integration,
        ]);

        if (empty($shortcode)) {
            return sprintf('<div class="wpcf7-not-valid-tip">%s</div>', __('[File Uploader]: Your provided shortcode id is not valid for CF7 File Uploader.', 'integration-google-drive'));
        }

        $html = empty($moduleId) ? (
            sprintf('<div class="wpcf7-not-valid-tip">%s</div>', esc_html__('Please configure the uploader first', 'integration-google-drive'))
        ) : (
            sprintf(
                '<div class="ccpigd-cf7-file-uploader" data-user-id="%s" data-field-name="%s">%s<input %s /><span class="wpcf7-form-control-wrap %s"></span></div>%s',
                $user_id,
                $tag_name,
                $shortcode,
                $atts_str,
                $tag_name,
                $validation_error
            )
        );

        return $html;
    }

    public function addTagGenerator()
    {
        if (class_exists('WPCF7_TagGenerator')) {
            $tag_generator = WPCF7_TagGenerator::get_instance();

            $tag_generator->add(
                'google_drive',
                __('Google Drive', 'integration-google-drive'),
                [
                    $this,
                    version_compare(WPCF7_VERSION, '6.0', '>=') ? 'tag_generator_body_v6' : 'tag_generator_body',
                ],
                [
                    'version' => '2'
                ]
            );
        }
    }

    public function tag_generator_body_v6($contact_form, $options = '')
    {
        wp_enqueue_script('ccpigd-form-integrations');
        $tag = new WPCF7_TagGeneratorGenerator($options['content']);

        $description = esc_html__('Generate a form-tag for this upload field.', 'integration-google-drive');
        $form_data   = [
            'id'        => $contact_form->id(),
            'name'      => $contact_form->name(),
            'url'       => get_edit_post_link($contact_form->id()),
            'version'   => WPCF7_VERSION,
            'hash'      => $contact_form->hash(),
            'shortcode' => '[contact-form-7 id="' . $contact_form->hash() . ' title="' . $contact_form->title() . '"]',
        ];

        ?>
        <header data-ccpigd_cf7_data="<?php echo esc_attr(base64_encode(wp_json_encode($form_data))); ?>" class="description-box">
            <h3><?php echo esc_html__('Google Drive Upload', 'integration-google-drive'); ?></h3>

            <p>
                <?php

        echo wp_kses(
            $description,
            [
                            'a'      => ['href' => true],
                            'strong' => [],
                        ],
            ['http', 'https']
        );
        ?>
            </p>
        </header>

        <div class="control-box">
            <?php

        $tag->print('field_type', [
            'with_required'  => true,
            'select_options' => [
        'google_drive' => esc_html__('Google Drive Upload', 'integration-google-drive'),
            ],
        ]);

        $tag->print('field_name');

        ?>

            <fieldset>
                <legend><?php echo esc_html__('Configure Uploader', 'integration-google-drive'); ?></legend>

                <input type="hidden"
                    data-tag-part="option"
                    data-tag-option="id:"
                    id="<?php echo esc_attr($options['content'] . '-data'); ?>" />

                <button id="ccpigd-form-uploader-config-cf7" type="button"
                    class="ccpigd-form-uploader-trigger ccpigd-form-uploader-trigger-cf7 ccpigd-btn btn-primary">
                    <i class="dashicons dashicons-admin-generic"></i>
                    <span><?php esc_html_e('Configure Uploader', 'integration-google-drive'); ?></span>
                </button>
            </fieldset>

            <?php
        ?>
        </div>

        <footer class="insert-box">
            <?php
                $tag->print('insert_box_content');
        $tag->print('mail_tag_tip');
        ?>
        </footer>


    <?php

    }

    public function tag_generator_body($contact_form, $args = '')
    {
        wp_enqueue_script('ccpigd-cf7');
        $args = wp_parse_args($args, []);
        $type = 'google_drive';

        $description = esc_html__('Generate a form-tag for this upload field.', 'integration-google-drive');
        ?>
        <div class="control-box">
            <fieldset>
                <legend><?php echo esc_html($description); ?></legend>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Field type', 'integration-google-drive'); ?></th>
                            <td>
                                <fieldset>
                                    <legend
                                        class="screen-reader-text"><?php echo esc_html__('Field type', 'integration-google-drive'); ?></legend>
                                    <label>
                                        <input type="checkbox"
                                            name="required" /> <?php echo esc_html__('Required field', 'integration-google-drive'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($args['content'] . '-name'); ?>">
                                    <?php echo esc_html__('Name', 'integration-google-drive'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="name" class="tg-name oneline" id="<?php echo esc_attr($args['content'] . '-name'); ?>" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($args['content'] . '-data'); ?>">
                                    <?php echo esc_html__('Configure', 'integration-google-drive'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="hidden" name="data" class="option oneline" id="<?php echo esc_attr($args['content'] . '-data'); ?>" />

                                <button id="ccpigd-form-uploader-config-cf7" type="button"
                                    class="ccpigd-form-uploader-trigger ccpigd-form-uploader-trigger-cf7 ccpigd-btn btn-primary">
                                    <i class="dashicons dashicons-admin-generic"></i>
                                    <span><?php esc_html_e('Configure Uploader', 'integration-google-drive'); ?></span>
                                </button>
                            </td>
                        </tr>

                    </tbody>
                </table>
            </fieldset>
        </div>

        <div class="insert-box">
            <input type="text" name="<?php echo esc_attr($type); ?>" class="tag code" readonly="readonly" onfocus="this.select()" />

            <div class="submitbox">
                <input type="button" class="button button-primary insert-tag"
                    value="<?php echo esc_attr__('Insert Tag', 'integration-google-drive'); ?>" />
            </div>

            <br class="clear" />

            <p class="description mail-tag">
                <label for="<?php echo esc_attr($args['content'] . '-mailtag'); ?>">
                    <?php printf('To list the uploads in your email, insert the mail-tag (%s) in the Mail tab.', '<strong><span class="mail-tag"></span></strong>'); ?>
                    <input type="text" class="mail-tag code ccpigd-hidden" readonly="readonly"
                        id="<?php echo esc_attr($args['content'] . '-mailtag'); ?>" />
                </label>
            </p>
        </div>
<?php
    }

    public function validateField($result, $tag)
    {
        $submission = WPCF7_Submission::get_instance();

        if (! $submission) {
            return $result;
        }

        $value       = $submission->get_posted_data($tag->name);
        $is_required = '*' === substr($tag->type, -1);

        if ($is_required && empty($value)) {
            $result->invalidate($tag, __('This field is required.', 'integration-google-drive'));

            return $result;
        }

        return $result;
    }
}
