<?php
/**
 * Plugin Name: Elegant ShortURL & Share
 * Plugin URI: https://github.com/ohmpwa/pluginwpshorturl
 * Description: ระบบสร้าง Short URL อัตโนมัติ (เช่น yoursite.com/s/xyz) พร้อมปุ่มแชร์และ Modal สไตล์ The Standard / Blockdit ที่สวยงาม สะอาด น่าเชื่อถือ
 * Version: 1.1.0
 * Author: @iOhm
 * Author URI: https://github.com/ohmpwa
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: elegant-shorturl
 */

// ป้องกันการเข้าถึงไฟล์โดยตรง
if (!defined('ABSPATH')) {
    exit;
}

define('WP_SHORTURL_VERSION', '1.1.0');
define('WP_SHORTURL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_SHORTURL_PLUGIN_URL', plugin_dir_url(__FILE__));

class WP_ShortURL_Plugin {

    private static $instance = null;
    private $post_meta_key = '_wpshorturl_code';
    private $clicks_meta_key = '_wpshorturl_clicks';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // ลงทะเบียน activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Rewrite rules สำหรับ Short URL
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('template_redirect', array($this, 'handle_shorturl_redirect'));

        // สร้าง shortcode เมื่อบันทึกโพสต์
        add_action('save_post', array($this, 'auto_generate_shorturl_on_save'), 10, 2);

        // Assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Append UI ในหน้าโพสต์
        add_filter('the_content', array($this, 'append_share_box_to_content'));

        // Shortcodes: [elegant_shorturl] และ [elegant_share_button]
        add_shortcode('elegant_shorturl', array($this, 'render_shortcode'));
        add_shortcode('elegant_share_button', array($this, 'render_button_shortcode'));

        // Admin Settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_post_meta_box'));

        // REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public function activate() {
        $this->add_rewrite_rules();
        flush_rewrite_rules();

        $defaults = array(
            'prefix' => 's',
            'code_length' => 6,
            'auto_display' => array('post', 'page'),
            'display_mode' => 'modal', // 'modal' (แบบป๊อปอัปตามรูป) หรือ 'box' (กล่องแบบเดิม)
            'modal_theme' => 'dark',   // 'dark' (สีดำเข้มตามรูป) หรือ 'light' (สีขาว)
            'button_text' => 'แชร์',
            'box_title' => 'แชร์',
            'show_platforms' => array('line', 'facebook', 'copy', 'x'),
        );

        if (!get_option('wpshorturl_settings')) {
            update_option('wpshorturl_settings', $defaults);
        }
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function get_options() {
        $defaults = array(
            'prefix' => 's',
            'code_length' => 6,
            'auto_display' => array('post'),
            'display_mode' => 'modal',
            'modal_theme' => 'dark',
            'button_text' => 'แชร์',
            'box_title' => 'แชร์',
            'show_platforms' => array('line', 'facebook', 'copy', 'x'),
        );
        $options = get_option('wpshorturl_settings', $defaults);
        return wp_parse_args($options, $defaults);
    }

    public function add_rewrite_rules() {
        $options = $this->get_options();
        $prefix = sanitize_title($options['prefix']) ?: 's';
        add_rewrite_rule(
            '^' . preg_quote($prefix, '/') . '/([a-zA-Z0-9_-]+)/?$',
            'index.php?wpshorturl_code=$matches[1]',
            'top'
        );
    }

    public function register_query_vars($vars) {
        $vars[] = 'wpshorturl_code';
        return $vars;
    }

    public function handle_shorturl_redirect() {
        $short_code = get_query_var('wpshorturl_code');
        if (empty($short_code)) {
            return;
        }

        $short_code = sanitize_text_field($short_code);

        global $wpdb;
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $this->post_meta_key,
            $short_code
        ));

        if ($post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                $clicks = (int) get_post_meta($post_id, $this->clicks_meta_key, true);
                update_post_meta($post_id, $this->clicks_meta_key, $clicks + 1);

                $target_url = get_permalink($post_id);
                wp_redirect($target_url, 301);
                exit;
            }
        }

        wp_safe_redirect(home_url('/404'));
        exit;
    }

    public function auto_generate_shorturl_on_save($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!in_array($post->post_type, array('post', 'page'))) {
            return;
        }

        $existing_code = get_post_meta($post_id, $this->post_meta_key, true);
        if (empty($existing_code)) {
            $code = $this->generate_unique_code($post_id);
            update_post_meta($post_id, $this->post_meta_key, $code);
        }
    }

    public function generate_unique_code($post_id, $length = 6) {
        $options = $this->get_options();
        $len = !empty($options['code_length']) ? intval($options['code_length']) : 6;
        $len = max(4, min(12, $len));

        $characters = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $char_len = strlen($characters);

        global $wpdb;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < $len; $i++) {
                $code .= $characters[wp_rand(0, $char_len - 1)];
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s AND post_id != %d LIMIT 1",
                $this->post_meta_key,
                $code,
                $post_id
            ));

            if (!$exists) {
                return $code;
            }
        }

        return dechex($post_id) . substr(md5(uniqid()), 0, 3);
    }

    public function get_short_url($post_id) {
        $code = get_post_meta($post_id, $this->post_meta_key, true);
        if (empty($code)) {
            $code = $this->generate_unique_code($post_id);
            update_post_meta($post_id, $this->post_meta_key, $code);
        }

        $options = $this->get_options();
        $prefix = sanitize_title($options['prefix']) ?: 's';

        if (get_option('permalink_structure')) {
            return home_url('/' . $prefix . '/' . $code);
        } else {
            return add_query_arg('wpshorturl_code', $code, home_url('/'));
        }
    }

    public function enqueue_frontend_assets() {
        if (!is_singular()) {
            return;
        }

        wp_enqueue_style(
            'wpshorturl-frontend',
            WP_SHORTURL_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            WP_SHORTURL_VERSION
        );

        wp_enqueue_script(
            'wpshorturl-frontend',
            WP_SHORTURL_PLUGIN_URL . 'assets/js/frontend.js',
            array(),
            WP_SHORTURL_VERSION,
            true
        );

        wp_localize_script('wpshorturl-frontend', 'wpShortUrlVars', array(
            'copiedToast' => __('คัดลอก URL เรียบร้อยแล้ว', 'elegant-shorturl'),
        ));
    }

    public function enqueue_admin_assets($hook) {
        wp_enqueue_style(
            'wpshorturl-admin',
            WP_SHORTURL_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WP_SHORTURL_VERSION
        );
    }

    public function append_share_box_to_content($content) {
        if (!is_singular() || !is_main_query()) {
            return $content;
        }

        $options = $this->get_options();
        $allowed_types = is_array($options['auto_display']) ? $options['auto_display'] : array();
        $post_type = get_post_type();

        if (in_array($post_type, $allowed_types)) {
            $box_html = $this->render_share_component(get_the_ID());
            return $content . $box_html;
        }

        return $content;
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => get_the_ID(),
        ), $atts, 'elegant_shorturl');

        $post_id = intval($atts['id']);
        if (!$post_id) return '';

        return $this->render_share_component($post_id);
    }

    public function render_button_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => get_the_ID(),
            'text' => '',
        ), $atts, 'elegant_share_button');

        $post_id = intval($atts['id']);
        if (!$post_id) return '';

        return $this->render_share_component($post_id, false, $atts['text']);
    }

    /**
     * เรนเดอร์ UI ปุ่มแชร์ และ Modal Popup ตรงตามรูปแบบในภาพเป๊ะๆ
     */
    public function render_share_component($post_id, $show_box = null, $custom_btn_text = '') {
        $post = get_post($post_id);
        if (!$post) return '';

        $options = $this->get_options();
        $short_url = $this->get_short_url($post_id);
        $post_title = get_the_title($post_id);
        $encoded_url = urlencode($short_url);
        $encoded_title = urlencode($post_title);

        $theme = !empty($options['modal_theme']) ? esc_attr($options['modal_theme']) : 'dark';
        $btn_label = !empty($custom_btn_text) ? esc_html($custom_btn_text) : esc_html($options['button_text']);
        $platforms = (array) $options['show_platforms'];

        ob_start();
        ?>
        <!-- Bar ปุ่มแชร์ (Trigger Button) -->
        <div class="wpsu-trigger-wrap" id="wpsu-post-<?php echo esc_attr($post_id); ?>">
            <button type="button" class="wpsu-share-trigger-btn" data-target-modal="#wpsu-modal-<?php echo esc_attr($post_id); ?>" aria-haspopup="dialog" aria-label="<?php echo $btn_label; ?>">
                <svg class="wpsu-share-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="5" r="3"></circle>
                    <circle cx="6" cy="12" r="3"></circle>
                    <circle cx="18" cy="19" r="3"></circle>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                    <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                </svg>
                <span><?php echo $btn_label; ?></span>
            </button>
        </div>

        <!-- The Modal Popup สไตล์ Dark Card ตามตัวอย่าง -->
        <div class="wpsu-modal-overlay wpsu-theme-<?php echo $theme; ?>" id="wpsu-modal-<?php echo esc_attr($post_id); ?>" role="dialog" aria-modal="true" style="display: none;">
            <div class="wpsu-modal-box">
                <!-- Header: หัวข้อ แชร์ + ปุ่มกากบาทปิด (X) -->
                <div class="wpsu-modal-header">
                    <span class="wpsu-modal-title"><?php echo esc_html($options['box_title']); ?></span>
                    <button type="button" class="wpsu-modal-close" aria-label="ปิดหน้ารับแชร์">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                <!-- Grid รายการปุ่มแชร์และคัดลอก (เรียง 4 ปุ่ม: แชท LINE, Facebook, คัดลอก URL, X) -->
                <div class="wpsu-modal-grid">

                    <?php if (in_array('line', $platforms)): ?>
                    <!-- LINE (แชท) -->
                    <a href="https://social-plugins.line.me/lineit/share?url=<?php echo $encoded_url; ?>" 
                       target="_blank" rel="noopener noreferrer" 
                       class="wpsu-circle-item">
                        <div class="wpsu-circle-icon wpsu-icon-line">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M24 10.304c0-5.369-5.383-9.738-12-9.738-6.616 0-12 4.369-12 9.738 0 4.814 4.269 8.846 10.035 9.608.391.084.922.258 1.057.592.121.3.079.77.039 1.085l-.171 1.026c-.053.304-.242 1.19.988.648 1.23-.541 6.634-3.906 9.05-6.685C22.617 14.887 24 12.744 24 10.304z"/>
                            </svg>
                        </div>
                        <span class="wpsu-circle-label"><?php _e('แชท', 'elegant-shorturl'); ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if (in_array('facebook', $platforms)): ?>
                    <!-- Facebook -->
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $encoded_url; ?>" 
                       target="_blank" rel="noopener noreferrer" 
                       class="wpsu-circle-item">
                        <div class="wpsu-circle-icon wpsu-icon-fb">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                        </div>
                        <span class="wpsu-circle-label">Facebook</span>
                    </a>
                    <?php endif; ?>

                    <?php if (in_array('copy', $platforms)): ?>
                    <!-- คัดลอก URL (Short URL พร้อม Feedback) -->
                    <button type="button" class="wpsu-circle-item wpsu-btn-copy-circle" data-url="<?php echo esc_url($short_url); ?>">
                        <div class="wpsu-circle-icon wpsu-icon-copy">
                            <svg class="wpsu-icon-link-default" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                            </svg>
                            <svg class="wpsu-icon-check-done" style="display: none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        </div>
                        <span class="wpsu-circle-label wpsu-label-copy-text"><?php _e('คัดลอก URL', 'elegant-shorturl'); ?></span>
                    </button>
                    <?php endif; ?>

                    <?php if (in_array('x', $platforms)): ?>
                    <!-- X (Twitter) -->
                    <a href="https://twitter.com/intent/tweet?url=<?php echo $encoded_url; ?>&text=<?php echo $encoded_title; ?>" 
                       target="_blank" rel="noopener noreferrer" 
                       class="wpsu-circle-item">
                        <div class="wpsu-circle-icon wpsu-icon-x">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                            </svg>
                        </div>
                        <span class="wpsu-circle-label">X</span>
                    </a>
                    <?php endif; ?>

                </div>

                <!-- Toast Alert แจ้งเตือนเมื่อคัดลอกสำเร็จ -->
                <div class="wpsu-toast" style="display: none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span><?php _e('คัดลอก URL เรียบร้อยแล้ว', 'elegant-shorturl'); ?></span>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function add_post_meta_box() {
        $screens = array('post', 'page');
        foreach ($screens as $screen) {
            add_meta_box(
                'wpshorturl_meta_box',
                __('🔗 Short URL & Share Stats', 'elegant-shorturl'),
                array($this, 'render_post_meta_box'),
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_post_meta_box($post) {
        $code = get_post_meta($post->ID, $this->post_meta_key, true);
        if (empty($code)) {
            $code = $this->generate_unique_code($post->ID);
            update_post_meta($post->ID, $this->post_meta_key, $code);
        }

        $short_url = $this->get_short_url($post->ID);
        $clicks = (int) get_post_meta($post->ID, $this->clicks_meta_key, true);
        ?>
        <div class="wpsu-admin-metabox">
            <p><strong><?php _e('Short URL สำหรับหน้านี้:', 'elegant-shorturl'); ?></strong></p>
            <div style="display: flex; gap: 6px; margin-bottom: 10px;">
                <input type="text" readonly value="<?php echo esc_url($short_url); ?>" id="wpsu-admin-shortlink" style="width: 100%; font-size: 12px; background: #f8fafc;" />
                <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('wpsu-admin-shortlink').value); alert('คัดลอกเรียบร้อยแล้ว!');">
                    📋
                </button>
            </div>
            <p style="margin: 0; color: #64748b; font-size: 13px;">
                📊 สถิติเปิดอ่านผ่าน Short URL: <strong><?php echo number_format_i18n($clicks); ?></strong> ครั้ง
            </p>
        </div>
        <?php
    }

    public function add_admin_menu() {
        add_options_page(
            __('ตั้งค่า Elegant ShortURL', 'elegant-shorturl'),
            __('Short URL & Share', 'elegant-shorturl'),
            'manage_options',
            'wpshorturl-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('wpshorturl_settings_group', 'wpshorturl_settings', array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input) {
        $output = array();
        $output['prefix'] = !empty($input['prefix']) ? sanitize_title($input['prefix']) : 's';
        $output['code_length'] = max(4, min(12, intval($input['code_length'])));
        $output['box_title'] = !empty($input['box_title']) ? sanitize_text_field($input['box_title']) : 'แชร์';
        $output['button_text'] = !empty($input['button_text']) ? sanitize_text_field($input['button_text']) : 'แชร์';
        $output['modal_theme'] = sanitize_text_field($input['modal_theme']);
        $output['auto_display'] = !empty($input['auto_display']) && is_array($input['auto_display']) 
            ? array_map('sanitize_text_field', $input['auto_display']) 
            : array();
        $output['show_platforms'] = !empty($input['show_platforms']) && is_array($input['show_platforms'])
            ? array_map('sanitize_text_field', $input['show_platforms'])
            : array();

        $this->add_rewrite_rules();
        flush_rewrite_rules();

        return $output;
    }

    public function render_settings_page() {
        $options = $this->get_options();
        ?>
        <div class="wrap wpsu-admin-wrap">
            <div class="wpsu-admin-header">
                <div class="wpsu-admin-header-title">
                    <span class="dashicons dashicons-share" style="font-size: 30px; width: 30px; height: 30px; line-height: 1;"></span>
                    <h1 style="display: inline-block; margin: 0 0 0 10px;"><?php _e('Elegant ShortURL & Social Share Settings', 'elegant-shorturl'); ?></h1>
                </div>
                <p class="description"><?php _e('ปรับแต่งระบบสร้าง Short URL และหน้าต่างแชร์โซเชียลมีเดียแบบพรีเมียม (ผู้จัดทำ: @iOhm)', 'elegant-shorturl'); ?></p>
            </div>

            <form method="post" action="options.php" class="wpsu-settings-form">
                <?php settings_fields('wpshorturl_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wpsu_prefix"><?php _e('URL Prefix (คำนำหน้าลิงก์ย่อ)', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <code><?php echo esc_url(home_url('/')); ?></code>
                            <input type="text" id="wpsu_prefix" name="wpshorturl_settings[prefix]" value="<?php echo esc_attr($options['prefix']); ?>" class="regular-text" style="width: 120px;" />
                            <code>/abc123</code>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpsu_modal_theme"><?php _e('โทนสีของหน้าต่างแชร์ (Modal Theme)', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <select id="wpsu_modal_theme" name="wpshorturl_settings[modal_theme]">
                                <option value="dark" <?php selected($options['modal_theme'], 'dark'); ?>><?php _e('Dark Theme (ดีไซน์สีดำคลาสสิก เหมือนตัวอย่าง)', 'elegant-shorturl'); ?></option>
                                <option value="light" <?php selected($options['modal_theme'], 'light'); ?>><?php _e('Light Theme (สีขาวสะอาดตา)', 'elegant-shorturl'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpsu_button_text"><?php _e('ข้อความบนปุ่มแชร์ท้ายบทความ', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <input type="text" id="wpsu_button_text" name="wpshorturl_settings[button_text]" value="<?php echo esc_attr($options['button_text']); ?>" class="regular-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpsu_box_title"><?php _e('หัวข้อในหน้าต่างป๊อปอัป', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <input type="text" id="wpsu_box_title" name="wpshorturl_settings[box_title]" value="<?php echo esc_attr($options['box_title']); ?>" class="regular-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('แสดงผลอัตโนมัติที่', 'elegant-shorturl'); ?></th>
                        <td>
                            <label style="margin-right: 15px;">
                                <input type="checkbox" name="wpshorturl_settings[auto_display][]" value="post" <?php checked(in_array('post', (array)$options['auto_display'])); ?>>
                                <?php _e('เรื่อง (Posts)', 'elegant-shorturl'); ?>
                            </label>
                            <label>
                                <input type="checkbox" name="wpshorturl_settings[auto_display][]" value="page" <?php checked(in_array('page', (array)$options['auto_display'])); ?>>
                                <?php _e('หน้า (Pages)', 'elegant-shorturl'); ?>
                            </label>
                            <p class="description"><?php _e('สามารถใช้ Shortcode <code>[elegant_share_button]</code> หรือ <code>[elegant_shorturl]</code> ได้ทุกที่', 'elegant-shorturl'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('ช่องทางที่ต้องการเปิดใช้งาน', 'elegant-shorturl'); ?></th>
                        <td>
                            <?php 
                            $available_platforms = array(
                                'line'     => 'LINE (แชท)',
                                'facebook' => 'Facebook',
                                'copy'     => 'คัดลอก URL (Short URL)',
                                'x'        => 'X (Twitter)',
                            );
                            foreach ($available_platforms as $key => $label): 
                            ?>
                                <label style="display: inline-block; margin-right: 18px; margin-bottom: 8px;">
                                    <input type="checkbox" name="wpshorturl_settings[show_platforms][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, (array)$options['show_platforms'])); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('บันทึกการตั้งค่า', 'elegant-shorturl'), 'primary'); ?>
            </form>
        </div>
        <?php
    }

    public function register_rest_routes() {
        register_rest_route('elegant-shorturl/v1', '/get-shortlink/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_shortlink'),
            'permission_callback' => '__return_true',
        ));
    }

    public function rest_get_shortlink($request) {
        $post_id = (int) $request['id'];
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error('not_found', 'Post not found', array('status' => 404));
        }

        return rest_ensure_response(array(
            'post_id' => $post_id,
            'short_url' => $this->get_short_url($post_id),
            'clicks' => (int) get_post_meta($post_id, $this->clicks_meta_key, true),
        ));
    }
}

function wpshorturl_init() {
    return WP_ShortURL_Plugin::get_instance();
}
add_action('plugins_loaded', 'wpshorturl_init');
