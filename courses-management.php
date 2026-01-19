<?php
/**
 * Plugin Name: Courses Management
 * Plugin URI: https://wa.me/201020170951
 * Description: Complete course and attendance management system
 * Version: 3.0.0
 * Author: Abdelrhman Naeim
 * Text Domain: courses-management
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('CM_VERSION', '3.0.0');
define('CM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CM_PLUGIN_FILE', __FILE__);

/**
 * Main Plugin Class
 */
final class Courses_Management {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(CM_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(CM_PLUGIN_FILE, [$this, 'deactivate']);
        
        add_action('plugins_loaded', [$this, 'load_includes']);
        add_action('init', [$this, 'init']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
    }
    
    /**
     * Load includes after plugins loaded
     */
    public function load_includes() {
        $files = [
            'includes/class-post-types.php',
            'includes/class-meta-boxes.php',
            'includes/class-admin-pages.php',
            'includes/class-attendance.php',
            'includes/class-shortcodes.php',
            'includes/class-dynamic-data.php',
        ];
        
        foreach ($files as $file) {
            $filepath = CM_PLUGIN_DIR . $file;
            if (file_exists($filepath)) {
                require_once $filepath;
            }
        }
        
        // Initialize classes if they exist
        if (class_exists('CM_Post_Types')) {
            CM_Post_Types::get_instance();
        }
        if (class_exists('CM_Meta_Boxes')) {
            CM_Meta_Boxes::get_instance();
        }
        if (class_exists('CM_Admin_Pages')) {
            CM_Admin_Pages::get_instance();
        }
        if (class_exists('CM_Attendance')) {
            CM_Attendance::get_instance();
        }
        if (class_exists('CM_Shortcodes')) {
            CM_Shortcodes::get_instance();
        }
        if (class_exists('CM_Dynamic_Data')) {
            CM_Dynamic_Data::get_instance();
        }
    }
    
    /**
     * Plugin Activation
     */
    public function activate() {
        $this->create_tables();
        
        if (class_exists('CM_Post_Types')) {
            CM_Post_Types::get_instance()->register();
        } else {
            $this->register_post_type_fallback();
        }
        
        flush_rewrite_rules();
    }
    
    /**
     * Fallback post type registration
     */
    private function register_post_type_fallback() {
        register_post_type('cm_course', [
            'labels' => [
                'name' => 'Courses',
                'singular_name' => 'Course',
            ],
            'public' => true,
            'has_archive' => true,
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
            'show_in_rest' => true,
        ]);
    }
    
    /**
     * Plugin Deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * Create Database Tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Sessions Table
        $sql_sessions = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cm_sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            course_id BIGINT(20) UNSIGNED NOT NULL,
            session_title VARCHAR(255) NOT NULL,
            session_description TEXT NULL,
            session_date DATE NULL,
            session_time TIME NULL,
            session_duration INT NULL,
            session_link VARCHAR(500) NULL,
            session_order INT(11) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY course_id (course_id),
            KEY session_date (session_date)
        ) $charset_collate;";
        
        // Attendance Table
        $sql_attendance = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cm_attendance (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            course_id BIGINT(20) UNSIGNED NOT NULL,
            session_id BIGINT(20) UNSIGNED NOT NULL,
            attended TINYINT(1) DEFAULT 0,
            attended_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_session (user_id, session_id),
            KEY user_id (user_id),
            KEY course_id (course_id)
        ) $charset_collate;";
        
        // User Courses Table
        $sql_user_courses = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cm_user_courses (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            course_id BIGINT(20) UNSIGNED NOT NULL,
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY user_course (user_id, course_id),
            KEY user_id (user_id),
            KEY course_id (course_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_sessions);
        dbDelta($sql_attendance);
        dbDelta($sql_user_courses);
    }
    
    /**
     * Init
     */
    public function init() {
        load_plugin_textdomain('courses-management', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Admin Assets
     */
    public function admin_assets($hook) {
        $screen = get_current_screen();
        
        if (strpos($hook, 'cm-') !== false || ($screen && $screen->post_type === 'cm_course')) {
            
            $css_file = CM_PLUGIN_DIR . 'assets/css/admin.css';
            $js_file = CM_PLUGIN_DIR . 'assets/js/admin.js';
            
            if (file_exists($css_file)) {
                wp_enqueue_style('cm-admin', CM_PLUGIN_URL . 'assets/css/admin.css', [], CM_VERSION);
            }
            
            wp_enqueue_script('jquery-ui-sortable');
            
            if (file_exists($js_file)) {
                wp_enqueue_script('cm-admin', CM_PLUGIN_URL . 'assets/js/admin.js', ['jquery', 'jquery-ui-sortable'], CM_VERSION, true);
            }
            
            wp_localize_script('cm-admin', 'CM', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('cm_nonce'),
                'strings'  => [
                    'saving'  => __('Saving...', 'courses-management'),
                    'saved'   => __('Saved!', 'courses-management'),
                    'error'   => __('Error occurred', 'courses-management'),
                    'confirm_delete' => __('Are you sure?', 'courses-management'),
                    'loading' => __('Loading...', 'courses-management'),
                ]
            ]);
        }
    }
    
    /**
     * Frontend Assets
     */
    public function frontend_assets() {
        $css_file = CM_PLUGIN_DIR . 'assets/css/frontend.css';
        $js_file = CM_PLUGIN_DIR . 'assets/js/frontend.js';
        
        if (file_exists($css_file)) {
            wp_enqueue_style('cm-frontend', CM_PLUGIN_URL . 'assets/css/frontend.css', [], CM_VERSION);
        }
        
        if (file_exists($js_file)) {
            wp_enqueue_script('cm-frontend', CM_PLUGIN_URL . 'assets/js/frontend.js', ['jquery'], CM_VERSION, true);
        }
        
        wp_localize_script('cm-frontend', 'CM', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('cm_nonce'),
            'is_logged_in' => is_user_logged_in(),
            'user_id'      => get_current_user_id(),
            'strings'      => [
                'loading'        => __('Loading...', 'courses-management'),
                'attended'       => __('✓ تم الحضور', 'courses-management'),
                'join_now'       => __('انضم الان', 'courses-management'),
                'login_required' => __('Please login first', 'courses-management'),
                'error'          => __('Error occurred', 'courses-management'),
            ]
        ]);
    }
}

// ========================================
// HELPER FUNCTIONS
// ========================================

/**
 * Get plugin instance
 */
function CM() {
    return Courses_Management::get_instance();
}

/**
 * Get course sessions
 */
function cm_get_sessions($course_id) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'cm_sessions';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        return [];
    }
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE course_id = %d ORDER BY session_order ASC",
        $course_id
    ));
}

/**
 * Get user courses
 */
function cm_get_user_courses($user_id = null) {
    if (!$user_id) $user_id = get_current_user_id();
    if (!$user_id) return [];
    
    global $wpdb;
    
    $table = $wpdb->prefix . 'cm_user_courses';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        return [];
    }
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT c.*, uc.enrolled_at, uc.status 
         FROM {$wpdb->posts} c 
         INNER JOIN $table uc ON c.ID = uc.course_id 
         WHERE uc.user_id = %d AND c.post_status = 'publish'",
        $user_id
    ));
}

/**
 * Check if user is enrolled
 */
function cm_is_enrolled($user_id, $course_id) {
    if (!$user_id || !$course_id) return false;
    
    global $wpdb;
    
    $table = $wpdb->prefix . 'cm_user_courses';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        return false;
    }
    
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE user_id = %d AND course_id = %d AND status = 'active'",
        $user_id, $course_id
    ));
}

/**
 * Get attendance progress
 */
function cm_get_progress($user_id, $course_id) {
    if (!$user_id || !$course_id) {
        return ['total' => 0, 'attended' => 0, 'percent' => 0];
    }
    
    global $wpdb;
    
    $sessions_table = $wpdb->prefix . 'cm_sessions';
    $attendance_table = $wpdb->prefix . 'cm_attendance';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$sessions_table'") != $sessions_table) {
        return ['total' => 0, 'attended' => 0, 'percent' => 0];
    }
    
    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $sessions_table WHERE course_id = %d",
        $course_id
    ));
    
    $attended = 0;
    if ($wpdb->get_var("SHOW TABLES LIKE '$attendance_table'") == $attendance_table) {
        $attended = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $attendance_table WHERE user_id = %d AND course_id = %d AND attended = 1",
            $user_id, $course_id
        ));
    }
    
    return [
        'total'    => $total,
        'attended' => $attended,
        'percent'  => $total > 0 ? round(($attended / $total) * 100) : 0,
    ];
}

/**
 * Check if session is attended
 */
function cm_is_attended($user_id, $session_id) {
    if (!$user_id || !$session_id) return false;
    
    global $wpdb;
    
    $table = $wpdb->prefix . 'cm_attendance';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        return false;
    }
    
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT attended FROM $table WHERE user_id = %d AND session_id = %d",
        $user_id, $session_id
    ));
}

// ========================================
// DYNAMIC DATA HELPER FUNCTIONS
// ========================================

/**
 * Get course field value
 * Usage: cm_field('price') or cm_field('title', 123)
 */
function cm_field($field, $post_id = null) {
    if (!class_exists('CM_Dynamic_Data')) return '';
    
    $dynamic = CM_Dynamic_Data::get_instance();
    $method = 'get_course_' . $field;
    
    if (method_exists($dynamic, $method)) {
        return $dynamic->$method($post_id);
    }
    
    return $dynamic->get_field_value('cm_course_' . $field, $post_id);
}

/**
 * Echo course field value
 * Usage: cm_the_field('price')
 */
function cm_the_field($field, $post_id = null) {
    echo cm_field($field, $post_id);
}

/**
 * Get user progress for a course
 * Usage: cm_user_progress('percent') or cm_user_progress('text', 123)
 */
function cm_user_progress($type = 'percent', $course_id = null, $user_id = null) {
    if (!class_exists('CM_Dynamic_Data')) return '';
    
    $dynamic = CM_Dynamic_Data::get_instance();
    
    switch ($type) {
        case 'percent':
            return $dynamic->get_user_progress_percent($course_id, $user_id);
        case 'attended':
            return $dynamic->get_user_progress_attended($course_id, $user_id);
        case 'total':
            return $dynamic->get_user_progress_total($course_id, $user_id);
        case 'text':
            return $dynamic->get_user_progress_text($course_id, $user_id);
        default:
            return '';
    }
}

/**
 * Check if user is enrolled (Dynamic Data version)
 * Usage: if (cm_user_enrolled_check()) { ... }
 */
function cm_user_enrolled_check($course_id = null, $user_id = null) {
    if (!class_exists('CM_Dynamic_Data')) return false;
    
    $dynamic = CM_Dynamic_Data::get_instance();
    return $dynamic->get_user_is_enrolled($course_id, $user_id) === 'yes';
}

/**
 * Get session field
 * Usage: cm_session_field('title', $session_id)
 */
function cm_session_field($field, $session_id = null) {
    if (!class_exists('CM_Dynamic_Data')) return '';
    
    $dynamic = CM_Dynamic_Data::get_instance();
    $method = 'get_session_' . $field;
    
    if (method_exists($dynamic, $method)) {
        return $dynamic->$method($session_id);
    }
    
    return '';
}

// ========================================
// INITIALIZE PLUGIN
// ========================================
CM();