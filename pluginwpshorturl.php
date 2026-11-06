<?php
/**
 * Plugin Name: Elegant ShortURL & Share
 * Plugin URI: https://github.com/ohmpwa/pluginwpshorturl
 * Description: ระบบสร้าง Short URL อัตโนมัติ (เช่น yoursite.com/s/xyz) พร้อมปุ่มแชร์และคัดลอกลิงก์ที่สวยงาม สะอาด ทันสมัย และน่าเชื่อถือ
 * Version: 1.0.0
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

define('WP_SHORTURL_VERSION', '1.0.0');
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

        // จัดการสร้าง shortcode เมื่อบันทึกโพสต์
        add_action('save_post', array($this, 'auto_generate_shorturl_on_save'), 10, 2);

        // โหลด Assets (CSS / JS)
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // เพิ่มกล่องแชร์ท้ายเนื้อหาอัตโนมัติ
        add_filter('the_content', array($this, 'append_share_box_to_content'));

        // Shortcode [elegant_shorturl]
        add_shortcode('elegant_shorturl', array($this, 'render_shortcode'));

        // เมนูตั้งค่า Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Meta box ในหน้าแก้ไข Post/Page
        add_action('add_meta_boxes', array($this, 'add_post_meta_box'));

        // REST API endpoint (สำหรับ copy หรือสร้าง dynamically หากต้องการ)
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Activation Hook
     */
    public function activate() {
        $this->add_rewrite_rules();
        flush_rewrite_rules();

        // บันทึก default options
        $defaults = array(
            'prefix' => 's',
            'code_length' => 6,
            'auto_display' => array('post', 'page'),
            'box_title' => 'แชร์หน้านี้ผ่าน Short URL',
            'box_subtitle' => 'ลิงก์สั้น สะอาด ปลอดภัย พร้อมแชร์ไปยังแพลตฟอร์มต่างๆ',
            'button_theme' => 'clean-modern', // clean-modern, elegant-dark, minimal-outline
            'show_platforms' => array('facebook', 'x', 'line', 'linkedin', 'telegram'),
            'show_qr' => '1',
            'show_counter' => '1',
        );

        if (!get_option('wpshorturl_settings')) {
            update_option('wpshorturl_settings', $defaults);
        }
    }

    /**
     * Deactivation Hook
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * ดึงค่า Options
     */
    public function get_options() {
        $defaults = array(
            'prefix' => 's',
            'code_length' => 6,
            'auto_display' => array('post'),
            'box_title' => 'แชร์หน้านี้ผ่าน Short URL',
            'box_subtitle' => 'ลิงก์ย่อสั้น สะอาด น่าเชื่อถือ พร้อมส่งต่อได้ทันที',
            'button_theme' => 'clean-modern',
            'show_platforms' => array('facebook', 'x', 'line', 'linkedin', 'telegram'),
            'show_qr' => '1',
            'show_counter' => '1',
        );
        $options = get_option('wpshorturl_settings', $defaults);
        return wp_parse_args($options, $defaults);
    }

    /**
     * เพิ่ม Rewrite Rule สำหรับ URL เช่น domain.com/s/abc123
     */
    public function add_rewrite_rules() {
        $options = $this->get_options();
        $prefix = sanitize_title($options['prefix']) ?: 's';
        add_rewrite_rule(
            '^' . preg_quote($prefix, '/') . '/([a-zA-Z0-9_-]+)/?$',
            'index.php?wpshorturl_code=$matches[1]',
            'top'
        );
    }

    /**
     * เพิ่ม Query Var
     */
    public function register_query_vars($vars) {
        $vars[] = 'wpshorturl_code';
        return $vars;
    }

    /**
     * ดักจับ Request และ Redirect ไปยัง URL ปลายทาง
     */
    public function handle_shorturl_redirect() {
        $short_code = get_query_var('wpshorturl_code');
        if (empty($short_code)) {
            return;
        }

        $short_code = sanitize_text_field($short_code);

        // ค้นหา Post ID จาก Meta Key
        global $wpdb;
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $this->post_meta_key,
            $short_code
        ));

        if ($post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                // อัปเดตสถิติจำนวนการคลิก
                $clicks = (int) get_post_meta($post_id, $this->clicks_meta_key, true);
                update_post_meta($post_id, $this->clicks_meta_key, $clicks + 1);

                $target_url = get_permalink($post_id);
                // 301 Permanent Redirect เพื่อ SEO และความรวดเร็ว
                wp_redirect($target_url, 301);
                exit;
            }
        }

        // หากไม่พบ ให้ Redirect ไป 404 หรือหน้าหลัก
        wp_safe_redirect(home_url('/404'));
        exit;
    }

    /**
     * สร้างรหัส Shortcode อัตโนมัติเมื่อบันทึกเนื้อหา
     */
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

    /**
     * ฟังก์ชันสุ่มรหัสที่ไม่ซ้ำ
     */
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

            // ตรวจสอบความซ้ำ
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

        // Fallback: ใช้ Base36 ของ Post ID + random
        return dechex($post_id) . substr(md5(uniqid()), 0, 3);
    }

    /**
     * ดึง Short URL ของโพสต์
     */
    public function get_short_url($post_id) {
        $code = get_post_meta($post_id, $this->post_meta_key, true);
        if (empty($code)) {
            $code = $this->generate_unique_code($post_id);
            update_post_meta($post_id, $this->post_meta_key, $code);
        }

        $options = $this->get_options();
        $prefix = sanitize_title($options['prefix']) ?: 's';

        // รองรับทั้งกรณี Pretty Permalinks และ Plain Permalinks
        if (get_option('permalink_structure')) {
            return home_url('/' . $prefix . '/' . $code);
        } else {
            return add_query_arg('wpshorturl_code', $code, home_url('/'));
        }
    }

    /**
     * Enqueue Frontend Assets
     */
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
            'copiedText' => __('คัดลอกแล้ว!', 'elegant-shorturl'),
            'copyText'   => __('คัดลอกลิงก์', 'elegant-shorturl'),
            'errorText'  => __('ไม่สามารถคัดลอกได้', 'elegant-shorturl'),
        ));
    }

    /**
     * Enqueue Admin Assets
     */
    public function enqueue_admin_assets($hook) {
        wp_enqueue_style(
            'wpshorturl-admin',
            WP_SHORTURL_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WP_SHORTURL_VERSION
        );
    }

    /**
     * แสดงกล่อง Short URL ท้ายเนื้อหาโพสต์อัตโนมัติ
     */
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

    /**
     * Shortcode [elegant_shorturl]
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => get_the_ID(),
            'title' => '',
        ), $atts, 'elegant_shorturl');

        $post_id = intval($atts['id']);
        if (!$post_id) {
            return '';
        }

        return $this->render_share_component($post_id, $atts['title']);
    }

    /**
     * เรนเดอร์ UI กล่องแชร์ Short URL สไตล์ Modern, Clean, Credible
     */
    public function render_share_component($post_id, $custom_title = '') {
        $post = get_post($post_id);
        if (!$post) return '';

        $options = $this->get_options();
        $short_url = $this->get_short_url($post_id);
        $post_title = get_the_title($post_id);
        $encoded_url = urlencode($short_url);
        $encoded_title = urlencode($post_title);

        $box_title = !empty($custom_title) ? esc_html($custom_title) : esc_html($options['box_title']);
        $box_subtitle = esc_html($options['box_subtitle']);
        $theme = esc_attr($options['button_theme']);
        $clicks = (int) get_post_meta($post_id, $this->clicks_meta_key, true);

        // แพลตฟอร์มที่เปิดใช้งาน
        $platforms = (array) $options['show_platforms'];

        ob_start();
        ?>
        <div class="wpsu-container wpsu-theme-<?php echo $theme; ?>" id="wpsu-box-<?php echo esc_attr($post_id); ?>">
            <div class="wpsu-card">
                <!-- Header ส่วนหัว -->
                <div class="wpsu-header">
                    <div class="wpsu-badge">
                        <svg class="wpsu-icon-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            <path d="M9 12l2 2 4-4"/>
                        </svg>
                        <span>Official Short Link</span>
                    </div>
                    <?php if ($options['show_counter'] === '1' && $clicks > 0): ?>
                        <div class="wpsu-clicks-count" title="จำนวนครั้งที่มีการเปิดผ่าน Short URL">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <span><?php echo number_format_i18n($clicks); ?> views</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="wpsu-title-group">
                    <h4 class="wpsu-title"><?php echo $box_title; ?></h4>
                    <?php if (!empty($box_subtitle)): ?>
                        <p class="wpsu-subtitle"><?php echo $box_subtitle; ?></p>
                    <?php endif; ?>
                </div>

                <!-- Input Box สำหรับ Copy ลิงก์ย่อ -->
                <div class="wpsu-copy-group">
                    <div class="wpsu-input-wrapper">
                        <svg class="wpsu-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                        </svg>
                        <input type="text" class="wpsu-input" readonly value="<?php echo esc_url($short_url); ?>" aria-label="Short URL">
                    </div>
                    <button type="button" class="wpsu-btn-copy" data-url="<?php echo esc_url($short_url); ?>">
                        <span class="wpsu-btn-copy-default">
                            <svg class="wpsu-icon-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                            <span><?php _e('คัดลอกลิงก์', 'elegant-shorturl'); ?></span>
                        </span>
                        <span class="wpsu-btn-copy-success" style="display: none;">
                            <svg class="wpsu-icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            <span><?php _e('คัดลอกแล้ว!', 'elegant-shorturl'); ?></span>
                        </span>
                    </button>
                </div>

                <!-- ปุ่มแชร์ไปยัง Social Media ต่างๆ -->
                <?php if (!empty($platforms)): ?>
                <div class="wpsu-share-section">
                    <span class="wpsu-share-label"><?php _e('ส่งต่อให้เพื่อน:', 'elegant-shorturl'); ?></span>
                    <div class="wpsu-buttons-grid">
                        
                        <?php if (in_array('facebook', $platforms)): ?>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $encoded_url; ?>" 
                           target="_blank" rel="noopener noreferrer" 
                           class="wpsu-share-btn wpsu-share-facebook" title="Share to Facebook">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                            <span>Facebook</span>
                        </a>
                        <?php endif; ?>

                        <?php if (in_array('x', $platforms)): ?>
                        <a href="https://twitter.com/intent/tweet?url=<?php echo $encoded_url; ?>&text=<?php echo $encoded_title; ?>" 
                           target="_blank" rel="noopener noreferrer" 
                           class="wpsu-share-btn wpsu-share-x" title="Share to X (Twitter)">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                            </svg>
                            <span>X</span>
                        </a>
                        <?php endif; ?>

                        <?php if (in_array('line', $platforms)): ?>
                        <a href="https://social-plugins.line.me/lineit/share?url=<?php echo $encoded_url; ?>" 
                           target="_blank" rel="noopener noreferrer" 
                           class="wpsu-share-btn wpsu-share-line" title="Share to LINE">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M24 10.304c0-5.369-5.383-9.738-12-9.738-6.616 0-12 4.369-12 9.738 0 4.814 4.269 8.846 10.035 9.608.391.084.922.258 1.057.592.121.3.079.77.039 1.085l-.171 1.026c-.053.304-.242 1.19.988.648 1.23-.541 6.634-3.906 9.05-6.685C22.617 14.887 24 12.744 24 10.304z"/>
                            </svg>
                            <span>LINE</span>
                        </a>
                        <?php endif; ?>

                        <?php if (in_array('linkedin', $platforms)): ?>
                        <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo $encoded_url; ?>" 
                           target="_blank" rel="noopener noreferrer" 
                           class="wpsu-share-btn wpsu-share-linkedin" title="Share to LinkedIn">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.28 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.75M6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37h2.77z"/>
                            </svg>
                            <span>LinkedIn</span>
                        </a>
                        <?php endif; ?>

                        <?php if (in_array('telegram', $platforms)): ?>
                        <a href="https://t.me/share/url?url=<?php echo $encoded_url; ?>&text=<?php echo $encoded_title; ?>" 
                           target="_blank" rel="noopener noreferrer" 
                           class="wpsu-share-btn wpsu-share-telegram" title="Share to Telegram">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 0 0-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.75-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/>
                            </svg>
                            <span>Telegram</span>
                        </a>
                        <?php endif; ?>

                        <?php if ($options['show_qr'] === '1'): ?>
                        <button type="button" class="wpsu-share-btn wpsu-share-qr" data-qr-url="<?php echo esc_url($short_url); ?>" title="แสดง QR Code">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                                <line x1="7" y1="7" x2="7.01" y2="7"></line>
                                <line x1="17" y1="7" x2="17.01" y2="7"></line>
                                <line x1="7" y1="17" x2="7.01" y2="17"></line>
                                <line x1="17" y1="17" x2="17.01" y2="17"></line>
                            </svg>
                            <span>QR Code</span>
                        </button>
                        <?php endif; ?>

                    </div>
                </div>
                <?php endif; ?>

                <!-- QR Modal Popup -->
                <div class="wpsu-qr-modal" style="display: none;">
                    <div class="wpsu-qr-dialog">
                        <button type="button" class="wpsu-qr-close" aria-label="Close">&times;</button>
                        <h5 class="wpsu-qr-title"><?php _e('สแกน QR Code เพื่อเปิดลิงก์', 'elegant-shorturl'); ?></h5>
                        <div class="wpsu-qr-image-wrapper">
                            <img src="" alt="QR Code" class="wpsu-qr-img" loading="lazy" />
                        </div>
                        <p class="wpsu-qr-subtext"><?php echo esc_url($short_url); ?></p>
                    </div>
                </div>

            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Add Meta Box ในหน้าแก้ไขโพสต์
     */
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

    /**
     * เรนเดอร์ Meta Box ในแอดมิน
     */
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

    /**
     * เพิ่มหน้า Settings Menu ใน WordPress Admin
     */
    public function add_admin_menu() {
        add_options_page(
            __('ตั้งค่า Elegant ShortURL', 'elegant-shorturl'),
            __('Short URL & Share', 'elegant-shorturl'),
            'manage_options',
            'wpshorturl-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * ลงทะเบียนการตั้งค่า
     */
    public function register_settings() {
        register_setting('wpshorturl_settings_group', 'wpshorturl_settings', array($this, 'sanitize_settings'));
    }

    /**
     * Sanitize Settings Input
     */
    public function sanitize_settings($input) {
        $output = array();
        $output['prefix'] = !empty($input['prefix']) ? sanitize_title($input['prefix']) : 's';
        $output['code_length'] = max(4, min(12, intval($input['code_length'])));
        $output['box_title'] = sanitize_text_field($input['box_title']);
        $output['box_subtitle'] = sanitize_text_field($input['box_subtitle']);
        $output['button_theme'] = sanitize_text_field($input['button_theme']);
        $output['show_qr'] = !empty($input['show_qr']) ? '1' : '0';
        $output['show_counter'] = !empty($input['show_counter']) ? '1' : '0';
        $output['auto_display'] = !empty($input['auto_display']) && is_array($input['auto_display']) 
            ? array_map('sanitize_text_field', $input['auto_display']) 
            : array();
        $output['show_platforms'] = !empty($input['show_platforms']) && is_array($input['show_platforms'])
            ? array_map('sanitize_text_field', $input['show_platforms'])
            : array();

        // เมื่อมีการเปลี่ยน prefix จะ flush rewrite rules ให้อัตโนมัติ
        $this->add_rewrite_rules();
        flush_rewrite_rules();

        return $output;
    }

    /**
     * เรนเดอร์หน้าจอการตั้งค่าแอดมิน
     */
    public function render_settings_page() {
        $options = $this->get_options();
        ?>
        <div class="wrap wpsu-admin-wrap">
            <div class="wpsu-admin-header">
                <div class="wpsu-admin-header-title">
                    <span class="dashicons dashicons-admin-links" style="font-size: 30px; width: 30px; height: 30px; line-height: 1;"></span>
                    <h1 style="display: inline-block; margin: 0 0 0 10px;"><?php _e('Elegant ShortURL & Social Share Settings', 'elegant-shorturl'); ?></h1>
                </div>
                <p class="description"><?php _e('ปรับแต่งระบบสร้าง Short URL ภายในเว็บของคุณเอง และตกแต่งปุ่มแชร์ให้สวยงาม น่าเชื่อถือ', 'elegant-shorturl'); ?></p>
            </div>

            <form method="post" action="options.php" class="wpsu-settings-form">
                <?php
                settings_fields('wpshorturl_settings_group');
                ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wpsu_prefix"><?php _e('URL Prefix (คำนำหน้าลิงก์ย่อ)', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <code><?php echo esc_url(home_url('/')); ?></code>
                            <input type="text" id="wpsu_prefix" name="wpshorturl_settings[prefix]" value="<?php echo esc_attr($options['prefix']); ?>" class="regular-text" style="width: 120px;" />
                            <code>/abc123</code>
                            <p class="description"><?php _e('เช่น ตั้งเป็น "s", "go", หรือ "link"', 'elegant-shorturl'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpsu_code_length"><?php _e('ความยาวรหัสสุ่ม (ตัวอักษร)', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <input type="number" id="wpsu_code_length" name="wpshorturl_settings[code_length]" value="<?php echo esc_attr($options['code_length']); ?>" min="4" max="12" class="small-text" />
                            <p class="description"><?php _e('แนะนำ 5 หรือ 6 ตัวอักษร เช่น s/x7Kq9', 'elegant-shorturl'); ?></p>
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
                            <p class="description"><?php _e('สามารถใช้ Shortcode <code>[elegant_shorturl]</code> ไปวางในจุดที่ต้องการได้เช่นกัน', 'elegant-shorturl'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpsu_box_title"><?php _e('หัวข้อกล่องแชร์ (Box Title)', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <input type="text" id="wpsu_box_title" name="wpshorturl_settings[box_title]" value="<?php echo esc_attr($options['box_title']); ?>" class="large-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpsu_box_subtitle"><?php _e('คำอธิบายย่อย (Subtitle)', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <input type="text" id="wpsu_box_subtitle" name="wpshorturl_settings[box_subtitle]" value="<?php echo esc_attr($options['box_subtitle']); ?>" class="large-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpsu_button_theme"><?php _e('ธีมดีไซน์ปุ่ม (Design Theme)', 'elegant-shorturl'); ?></label></th>
                        <td>
                            <select id="wpsu_button_theme" name="wpshorturl_settings[button_theme]">
                                <option value="clean-modern" <?php selected($options['button_theme'], 'clean-modern'); ?>><?php _e('Clean Modern (ขาวสะอาด นุ่มนวล ดูพรีเมียม)', 'elegant-shorturl'); ?></option>
                                <option value="elegant-dark" <?php selected($options['button_theme'], 'elegant-dark'); ?>><?php _e('Elegant Dark (โทนดาร์กหรูหรา น่าเชื่อถือ)', 'elegant-shorturl'); ?></option>
                                <option value="minimal-outline" <?php selected($options['button_theme'], 'minimal-outline'); ?>><?php _e('Minimal Outline (เส้นสายมินิมอล บางเบา)', 'elegant-shorturl'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('ปุ่มแชร์แพลตฟอร์มที่ต้องการ', 'elegant-shorturl'); ?></th>
                        <td>
                            <?php 
                            $available_platforms = array(
                                'facebook' => 'Facebook',
                                'x'        => 'X (Twitter)',
                                'line'     => 'LINE',
                                'linkedin' => 'LinkedIn',
                                'telegram' => 'Telegram',
                            );
                            foreach ($available_platforms as $key => $label): 
                            ?>
                                <label style="display: inline-block; margin-right: 15px; margin-bottom: 8px;">
                                    <input type="checkbox" name="wpshorturl_settings[show_platforms][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, (array)$options['show_platforms'])); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('ฟังก์ชันเสริม', 'elegant-shorturl'); ?></th>
                        <td>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wpshorturl_settings[show_qr]" value="1" <?php checked($options['show_qr'], '1'); ?>>
                                <?php _e('แสดงปุ่มสร้าง QR Code ให้ผู้ใช้นำไปสแกนได้ทันที', 'elegant-shorturl'); ?>
                            </label>
                            <label style="display: block;">
                                <input type="checkbox" name="wpshorturl_settings[show_counter]" value="1" <?php checked($options['show_counter'], '1'); ?>>
                                <?php _e('แสดงจำนวนครั้งที่มีการเปิดผ่าน Short URL (เพิ่ม Social Proof)', 'elegant-shorturl'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('บันทึกการตั้งค่า', 'elegant-shorturl'), 'primary'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * REST API Routes
     */
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

// Initialise Plugin
function wpshorturl_init() {
    return WP_ShortURL_Plugin::get_instance();
}
add_action('plugins_loaded', 'wpshorturl_init');
