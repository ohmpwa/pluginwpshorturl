<?php
/**
 * Plugin Name: Elegant ShortURL & Share
 * Plugin URI: https://github.com/ohmpwa/pluginwpshorturl
 * Description: ระบบสร้าง Short URL อัตโนมัติ (เช่น yoursite.com/s/xyz) พร้อมปุ่มแชร์และ Modal สไตล์ The Standard / Blockdit ที่สวยงาม สะอาด น่าเชื่อถือ พร้อมหน้าตั้งค่าแบบ Seed Social
 * Version: 1.2.0
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

define('WP_SHORTURL_VERSION', '1.2.0');
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
        // Activation & Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Rewrite rules
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('template_redirect', array($this, 'handle_shorturl_redirect'));

        // Post save
        add_action('save_post', array($this, 'auto_generate_shorturl_on_save'), 10, 2);

        // Assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Content hook: Top and/or Bottom
        add_filter('the_content', array($this, 'display_share_buttons_in_content'));

        // Open Graph Meta Tags (หากเปิด Share featured image)
        add_action('wp_head', array($this, 'output_open_graph_tags'));

        // Shortcodes
        add_shortcode('elegant_shorturl', array($this, 'render_shortcode'));
        add_shortcode('elegant_share_button', array($this, 'render_button_shortcode'));

        // Admin Menu & Settings
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
            'post_types' => array('post' => 1, 'page' => 1),
            'positions'  => array('bottom' => 1),
            'facebook'   => 1,
            'facebook_label' => 'facebook',
            'x'          => 1,
            'x_label'    => 'twitter',
            'line'       => 1,
            'line_label' => 'line',
            'copy_url'   => 1,
            'copy_label' => 'คัดลอก URL',
            'fb_app_id'  => '',
            'fb_app_secret' => '',
            'share_featured_image' => 0,
            'prefix'     => 's',
            'code_length'=> 6,
            'modal_title'=> 'แชร์',
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
            'post_types' => array('post' => 1, 'page' => 1),
            'positions'  => array('bottom' => 1),
            'facebook'   => 1,
            'facebook_label' => 'facebook',
            'x'          => 1,
            'x_label'    => 'twitter',
            'line'       => 1,
            'line_label' => 'line',
            'copy_url'   => 1,
            'copy_label' => 'คัดลอก URL',
            'fb_app_id'  => '',
            'fb_app_secret' => '',
            'share_featured_image' => 0,
            'prefix'     => 's',
            'code_length'=> 6,
            'modal_title'=> 'แชร์',
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

    /**
     * แทรกปุ่มแชร์ที่ Top หรือ Bottom ตามตำแหน่งที่ผู้ใช้ตั้งค่าไว้
     */
    public function display_share_buttons_in_content($content) {
        if (!is_singular() || !is_main_query()) {
            return $content;
        }

        $options = $this->get_options();
        $post_type = get_post_type();

        // ตรวจสอบ Post Type to show
        $post_types = !empty($options['post_types']) && is_array($options['post_types']) ? $options['post_types'] : array();
        if (empty($post_types[$post_type])) {
            return $content;
        }

        $positions = !empty($options['positions']) && is_array($options['positions']) ? $options['positions'] : array();
        $show_top = !empty($positions['top']);
        $show_bottom = !empty($positions['bottom']);

        $share_html = $this->render_share_component(get_the_ID());

        if ($show_top && $show_bottom) {
            return $share_html . $content . $share_html;
        } elseif ($show_top) {
            return $share_html . $content;
        } elseif ($show_bottom) {
            return $content . $share_html;
        }

        return $content;
    }

    /**
     * สร้าง Open Graph Meta Tags เมื่อเลือก "Share featured image?"
     */
    public function output_open_graph_tags() {
        if (!is_singular()) {
            return;
        }

        $options = $this->get_options();
        if (empty($options['share_featured_image'])) {
            return;
        }

        $post_id = get_the_ID();
        $title = get_the_title($post_id);
        $short_url = $this->get_short_url($post_id);
        $image_url = get_the_post_thumbnail_url($post_id, 'large');

        echo "\n<!-- Seed Social & Elegant ShortURL Open Graph -->\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($short_url) . '" />' . "\n";
        echo '<meta property="og:type" content="article" />' . "\n";
        if (!empty($options['fb_app_id'])) {
            echo '<meta property="fb:app_id" content="' . esc_attr($options['fb_app_id']) . '" />' . "\n";
        }
        if ($image_url) {
            echo '<meta property="og:image" content="' . esc_url($image_url) . '" />' . "\n";
        }
        echo "<!-- End Open Graph -->\n\n";
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
        ), $atts, 'elegant_share_button');

        $post_id = intval($atts['id']);
        if (!$post_id) return '';

        return $this->render_share_component($post_id);
    }

    /**
     * เรนเดอร์ UI ปุ่มแชร์ และ Modal Popup ตามที่ตั้งค่าไว้
     */
    public function render_share_component($post_id) {
        $post = get_post($post_id);
        if (!$post) return '';

        $options = $this->get_options();
        $short_url = $this->get_short_url($post_id);
        $post_title = get_the_title($post_id);
        $encoded_url = urlencode($short_url);
        $encoded_title = urlencode($post_title);

        $line_label = !empty($options['line_label']) ? esc_html($options['line_label']) : 'line';
        $fb_label = !empty($options['facebook_label']) ? esc_html($options['facebook_label']) : 'facebook';
        $x_label = !empty($options['x_label']) ? esc_html($options['x_label']) : 'twitter';
        $copy_label = !empty($options['copy_label']) ? esc_html($options['copy_label']) : 'คัดลอก URL';
        $modal_title = !empty($options['modal_title']) ? esc_html($options['modal_title']) : 'แชร์';

        ob_start();
        ?>
        <div class="wpsu-trigger-wrap" id="wpsu-post-<?php echo esc_attr($post_id); ?>">
            <button type="button" class="wpsu-share-trigger-btn" data-target-modal="#wpsu-modal-<?php echo esc_attr($post_id); ?>" aria-haspopup="dialog" aria-label="<?php echo $modal_title; ?>">
                <svg class="wpsu-share-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="5" r="3"></circle>
                    <circle cx="6" cy="12" r="3"></circle>
                    <circle cx="18" cy="19" r="3"></circle>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                    <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                </svg>
                <span><?php echo $modal_title; ?></span>
            </button>
        </div>

        <div class="wpsu-modal-overlay wpsu-theme-dark" id="wpsu-modal-<?php echo esc_attr($post_id); ?>" role="dialog" aria-modal="true" style="display: none;">
            <div class="wpsu-modal-box">
                <div class="wpsu-modal-header">
                    <span class="wpsu-modal-title"><?php echo $modal_title; ?></span>
                    <button type="button" class="wpsu-modal-close" aria-label="ปิด">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                <div class="wpsu-modal-grid">

                    <?php if (!empty($options['line'])): ?>
                    <a href="https://social-plugins.line.me/lineit/share?url=<?php echo $encoded_url; ?>" 
                       target="_blank" rel="noopener noreferrer" 
                       class="wpsu-circle-item">
                        <div class="wpsu-circle-icon wpsu-icon-line">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M24 10.304c0-5.369-5.383-9.738-12-9.738-6.616 0-12 4.369-12 9.738 0 4.814 4.269 8.846 10.035 9.608.391.084.922.258 1.057.592.121.3.079.77.039 1.085l-.171 1.026c-.053.304-.242 1.19.988.648 1.23-.541 6.634-3.906 9.05-6.685C22.617 14.887 24 12.744 24 10.304z"/>
                            </svg>
                        </div>
                        <span class="wpsu-circle-label"><?php echo $line_label; ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($options['facebook'])): ?>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $encoded_url; ?>" 
                       target="_blank" rel="noopener noreferrer" 
                       class="wpsu-circle-item">
                        <div class="wpsu-circle-icon wpsu-icon-fb">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                        </div>
                        <span class="wpsu-circle-label"><?php echo $fb_label; ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($options['copy_url'])): ?>
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
                        <span class="wpsu-circle-label wpsu-label-copy-text"><?php echo $copy_label; ?></span>
                    </button>
                    <?php endif; ?>

                    <?php if (!empty($options['x'])): ?>
                    <a href="https://twitter.com/intent/tweet?url=<?php echo $encoded_url; ?>&text=<?php echo $encoded_title; ?>" 
                       target="_blank" rel="noopener noreferrer" 
                       class="wpsu-circle-item">
                        <div class="wpsu-circle-icon wpsu-icon-x">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                            </svg>
                        </div>
                        <span class="wpsu-circle-label"><?php echo $x_label; ?></span>
                    </a>
                    <?php endif; ?>

                </div>

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
            __('Seed Social & ShortURL', 'elegant-shorturl'),
            __('Seed Social', 'elegant-shorturl'),
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
        
        // Post Type to show
        $output['post_types'] = array();
        if (!empty($input['post_types']['post'])) $output['post_types']['post'] = 1;
        if (!empty($input['post_types']['page'])) $output['post_types']['page'] = 1;

        // Position to show
        $output['positions'] = array();
        if (!empty($input['positions']['top'])) $output['positions']['top'] = 1;
        if (!empty($input['positions']['bottom'])) $output['positions']['bottom'] = 1;

        // Social Platforms & custom labels
        $output['facebook'] = !empty($input['facebook']) ? 1 : 0;
        $output['facebook_label'] = sanitize_text_field($input['facebook_label']);

        $output['x'] = !empty($input['x']) ? 1 : 0;
        $output['x_label'] = sanitize_text_field($input['x_label']);

        $output['line'] = !empty($input['line']) ? 1 : 0;
        $output['line_label'] = sanitize_text_field($input['line_label']);

        $output['copy_url'] = !empty($input['copy_url']) ? 1 : 0;
        $output['copy_label'] = sanitize_text_field($input['copy_label']);

        // Facebook App Credentials
        $output['fb_app_id'] = sanitize_text_field($input['fb_app_id']);
        $output['fb_app_secret'] = sanitize_text_field($input['fb_app_secret']);

        // Share featured image
        $output['share_featured_image'] = !empty($input['share_featured_image']) ? 1 : 0;

        // Shortlink prefix
        $output['prefix'] = !empty($input['prefix']) ? sanitize_title($input['prefix']) : 's';
        $output['code_length'] = max(4, min(12, intval($input['code_length'] ?: 6)));
        $output['modal_title'] = !empty($input['modal_title']) ? sanitize_text_field($input['modal_title']) : 'แชร์';

        $this->add_rewrite_rules();
        flush_rewrite_rules();

        return $output;
    }

    /**
     * หน้าจอตั้งค่า (ตรงตามโครงสร้างและ UI ของรูป Seed Social 100%)
     */
    public function render_settings_page() {
        $options = $this->get_options();
        $post_types = !empty($options['post_types']) ? $options['post_types'] : array();
        $positions = !empty($options['positions']) ? $options['positions'] : array();
        ?>
        <div class="wrap wpsu-seed-settings-wrap">
            <h1 class="wpsu-seed-main-title"><?php _e('Seed Social', 'elegant-shorturl'); ?></h1>
            <p class="wpsu-seed-sublink">
                <?php _e('For more information, please visit', 'elegant-shorturl'); ?> 
                <a href="https://wordpress.org" target="_blank" rel="noopener noreferrer"><?php _e('FAQ on WordPress.org', 'elegant-shorturl'); ?></a>.
            </p>

            <h2 class="wpsu-seed-section-title"><?php _e('Social Sharing Buttons', 'elegant-shorturl'); ?></h2>

            <form method="post" action="options.php" class="wpsu-seed-form">
                <?php settings_fields('wpshorturl_settings_group'); ?>

                <table class="form-table wpsu-seed-table" role="presentation">
                    <!-- Post Type to show: -->
                    <tr>
                        <th scope="row"><?php _e('Post Type to show:', 'elegant-shorturl'); ?></th>
                        <td>
                            <label class="wpsu-seed-checkbox-label">
                                <input type="checkbox" name="wpshorturl_settings[post_types][post]" value="1" <?php checked(!empty($post_types['post'])); ?>>
                                <span><?php _e('Posts', 'elegant-shorturl'); ?></span>
                            </label>
                            <label class="wpsu-seed-checkbox-label">
                                <input type="checkbox" name="wpshorturl_settings[post_types][page]" value="1" <?php checked(!empty($post_types['page'])); ?>>
                                <span><?php _e('Pages', 'elegant-shorturl'); ?></span>
                            </label>
                        </td>
                    </tr>

                    <!-- Position to show: -->
                    <tr>
                        <th scope="row"><?php _e('Position to show:', 'elegant-shorturl'); ?></th>
                        <td>
                            <label class="wpsu-seed-checkbox-label">
                                <input type="checkbox" name="wpshorturl_settings[positions][top]" value="1" <?php checked(!empty($positions['top'])); ?>>
                                <span><?php _e('Top', 'elegant-shorturl'); ?></span>
                            </label>
                            <label class="wpsu-seed-checkbox-label">
                                <input type="checkbox" name="wpshorturl_settings[positions][bottom]" value="1" <?php checked(!empty($positions['bottom'])); ?>>
                                <span><?php _e('Bottom', 'elegant-shorturl'); ?></span>
                            </label>
                        </td>
                    </tr>

                    <!-- Facebook -->
                    <tr>
                        <th scope="row"><?php _e('Facebook', 'elegant-shorturl'); ?></th>
                        <td>
                            <div class="wpsu-seed-input-inline">
                                <input type="checkbox" name="wpshorturl_settings[facebook]" value="1" <?php checked(!empty($options['facebook'])); ?>>
                                <input type="text" name="wpshorturl_settings[facebook_label]" value="<?php echo esc_attr($options['facebook_label']); ?>" class="regular-text wpsu-seed-text-small" />
                            </div>
                        </td>
                    </tr>

                    <!-- X (Twitter) -->
                    <tr>
                        <th scope="row"><?php _e('X', 'elegant-shorturl'); ?></th>
                        <td>
                            <div class="wpsu-seed-input-inline">
                                <input type="checkbox" name="wpshorturl_settings[x]" value="1" <?php checked(!empty($options['x'])); ?>>
                                <input type="text" name="wpshorturl_settings[x_label]" value="<?php echo esc_attr($options['x_label']); ?>" class="regular-text wpsu-seed-text-small" />
                            </div>
                        </td>
                    </tr>

                    <!-- Line -->
                    <tr>
                        <th scope="row"><?php _e('Line', 'elegant-shorturl'); ?></th>
                        <td>
                            <div class="wpsu-seed-input-inline">
                                <input type="checkbox" name="wpshorturl_settings[line]" value="1" <?php checked(!empty($options['line'])); ?>>
                                <input type="text" name="wpshorturl_settings[line_label]" value="<?php echo esc_attr($options['line_label']); ?>" class="regular-text wpsu-seed-text-small" />
                            </div>
                        </td>
                    </tr>

                    <!-- คัดลอก URL (Short URL) -->
                    <tr>
                        <th scope="row"><?php _e('คัดลอก URL (Short URL)', 'elegant-shorturl'); ?></th>
                        <td>
                            <div class="wpsu-seed-input-inline">
                                <input type="checkbox" name="wpshorturl_settings[copy_url]" value="1" <?php checked(!empty($options['copy_url'])); ?>>
                                <input type="text" name="wpshorturl_settings[copy_label]" value="<?php echo esc_attr($options['copy_label']); ?>" class="regular-text wpsu-seed-text-small" />
                            </div>
                        </td>
                    </tr>

                    <!-- Facebook App ID -->
                    <tr>
                        <th scope="row"><label for="wpsu_fb_app_id"><?php _e('Facebook App ID', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <input type="text" id="wpsu_fb_app_id" name="wpshorturl_settings[fb_app_id]" value="<?php echo esc_attr($options['fb_app_id']); ?>" class="large-text wpsu-seed-input-wide" />
                        </td>
                    </tr>

                    <!-- Facebook App Secret -->
                    <tr>
                        <th scope="row"><label for="wpsu_fb_app_secret"><?php _e('Facebook App Secret', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <input type="password" id="wpsu_fb_app_secret" name="wpshorturl_settings[fb_app_secret]" value="<?php echo esc_attr($options['fb_app_secret']); ?>" class="large-text wpsu-seed-input-wide" />
                        </td>
                    </tr>

                    <!-- Share featured image? -->
                    <tr>
                        <th scope="row"><?php _e('Share featured image?', 'elegant-shorturl'); ?></th>
                        <td>
                            <label class="wpsu-seed-checkbox-label">
                                <input type="checkbox" name="wpshorturl_settings[share_featured_image]" value="1" <?php checked(!empty($options['share_featured_image'])); ?>>
                                <span><?php _e('Yes', 'elegant-shorturl'); ?></span>
                            </label>
                            <p class="description wpsu-seed-desc"><?php _e('This will add Open Graph meta tags. Do not check this if SEO plugin is installed.', 'elegant-shorturl'); ?></p>
                        </td>
                    </tr>

                    <!-- Shortlink Prefix Config -->
                    <tr>
                        <th scope="row"><label for="wpsu_prefix"><?php _e('Shortlink Prefix', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <code><?php echo esc_url(home_url('/')); ?></code>
                            <input type="text" id="wpsu_prefix" name="wpshorturl_settings[prefix]" value="<?php echo esc_attr($options['prefix']); ?>" class="regular-text" style="width: 100px;" />
                            <code>/7xKq9</code>
                            <p class="description wpsu-seed-desc"><?php _e('คำนำหน้า Short URL (ค่าเริ่มต้น: s)', 'elegant-shorturl'); ?></p>
                        </td>
                    </tr>
                </table>

                <div class="wpsu-seed-submit-wrap">
                    <button type="submit" name="submit" id="submit" class="button button-primary wpsu-seed-btn-submit">
                        <?php _e('บันทึกการเปลี่ยนแปลง', 'elegant-shorturl'); ?>
                    </button>
                </div>
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
