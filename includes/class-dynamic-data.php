<?php
/**
 * Dynamic Data Integration for Page Builders
 * Supports: Bricks Builder, Elementor, Oxygen, Beaver Builder
 */

if (!defined('ABSPATH')) {
    exit;
}

class CM_Dynamic_Data {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Bricks Builder
        add_filter('bricks/dynamic_tags_list', [$this, 'register_bricks_tags']);
        add_filter('bricks/dynamic_data/render_tag', [$this, 'render_bricks_tag'], 10, 3);
        add_filter('bricks/dynamic_data/render_content', [$this, 'render_bricks_content'], 10, 3);
        
        // Elementor
        add_action('elementor/dynamic_tags/register', [$this, 'register_elementor_tags']);
        
        // ACF-like filter for other builders
        add_filter('cm/dynamic_field', [$this, 'get_field_value'], 10, 3);
        
        // Register REST API for dynamic data
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }
    
    /**
     * Get all available dynamic fields
     */
    public function get_available_fields() {
        return [
            // Course Fields
            'cm_course_title' => [
                'label'    => __('Course Title', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_title',
            ],
            'cm_course_price' => [
                'label'    => __('Course Price', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_price',
            ],
            'cm_course_duration' => [
                'label'    => __('Course Duration', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_duration',
            ],
            'cm_course_age_from' => [
                'label'    => __('Age From', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_age_from',
            ],
            'cm_course_age_to' => [
                'label'    => __('Age To', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_age_to',
            ],
            'cm_course_age_range' => [
                'label'    => __('Age Range', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_age_range',
            ],
            'cm_course_sessions_count' => [
                'label'    => __('Sessions Count', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_sessions_count',
            ],
            'cm_course_students_count' => [
                'label'    => __('Students Count', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_students_count',
            ],
            'cm_course_thumbnail' => [
                'label'    => __('Course Thumbnail URL', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_thumbnail',
            ],
            'cm_course_excerpt' => [
                'label'    => __('Course Excerpt', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_excerpt',
            ],
            'cm_course_url' => [
                'label'    => __('Course URL', 'courses-management'),
                'group'    => 'course',
                'callback' => 'get_course_url',
            ],
            
            // User/Progress Fields
            'cm_user_progress_percent' => [
                'label'    => __('User Progress %', 'courses-management'),
                'group'    => 'user',
                'callback' => 'get_user_progress_percent',
            ],
            'cm_user_progress_attended' => [
                'label'    => __('Sessions Attended', 'courses-management'),
                'group'    => 'user',
                'callback' => 'get_user_progress_attended',
            ],
            'cm_user_progress_total' => [
                'label'    => __('Total Sessions', 'courses-management'),
                'group'    => 'user',
                'callback' => 'get_user_progress_total',
            ],
            'cm_user_progress_text' => [
                'label'    => __('Progress Text (3/10)', 'courses-management'),
                'group'    => 'user',
                'callback' => 'get_user_progress_text',
            ],
            'cm_user_is_enrolled' => [
                'label'    => __('Is User Enrolled (yes/no)', 'courses-management'),
                'group'    => 'user',
                'callback' => 'get_user_is_enrolled',
            ],
            'cm_user_courses_count' => [
                'label'    => __('User Courses Count', 'courses-management'),
                'group'    => 'user',
                'callback' => 'get_user_courses_count',
            ],
            'cm_user_total_attendance' => [
                'label'    => __('User Total Attendance', 'courses-management'),
                'group'    => 'user',
                'callback' => 'get_user_total_attendance',
            ],
            
            // Session Fields (for loops)
            'cm_session_title' => [
                'label'    => __('Session Title', 'courses-management'),
                'group'    => 'session',
                'callback' => 'get_session_title',
            ],
            'cm_session_date' => [
                'label'    => __('Session Date', 'courses-management'),
                'group'    => 'session',
                'callback' => 'get_session_date',
            ],
            'cm_session_time' => [
                'label'    => __('Session Time', 'courses-management'),
                'group'    => 'session',
                'callback' => 'get_session_time',
            ],
            'cm_session_link' => [
                'label'    => __('Session Link', 'courses-management'),
                'group'    => 'session',
                'callback' => 'get_session_link',
            ],
            'cm_session_is_attended' => [
                'label'    => __('Is Session Attended', 'courses-management'),
                'group'    => 'session',
                'callback' => 'get_session_is_attended',
            ],
            'cm_session_status' => [
                'label'    => __('Session Status (attended/pending)', 'courses-management'),
                'group'    => 'session',
                'callback' => 'get_session_status',
            ],
        ];
    }
    
    // ========================================
    // FIELD VALUE GETTERS
    // ========================================
    
    public function get_course_title($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        return $post_id ? get_the_title($post_id) : '';
    }
    
    public function get_course_price($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        return $post_id ? get_post_meta($post_id, '_cm_price', true) : '';
    }
    
    public function get_course_duration($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        return $post_id ? get_post_meta($post_id, '_cm_duration', true) : '';
    }
    
    public function get_course_age_from($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        return $post_id ? get_post_meta($post_id, '_cm_age_from', true) : '';
    }
    
    public function get_course_age_to($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        return $post_id ? get_post_meta($post_id, '_cm_age_to', true) : '';
    }
    
    public function get_course_age_range($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        if (!$post_id) return '';
        
        $from = get_post_meta($post_id, '_cm_age_from', true);
        $to = get_post_meta($post_id, '_cm_age_to', true);
        
        if ($from && $to) {
            return $from . ' - ' . $to;
        } elseif ($from) {
            return $from . '+';
        } elseif ($to) {
            return __('Up to', 'courses-management') . ' ' . $to;
        }
        
        return '';
    }
    
    public function get_course_sessions_count($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        return $post_id ? count(cm_get_sessions($post_id)) : 0;
    }
    
    public function get_course_students_count($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        if (!$post_id) return 0;
        
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cm_user_courses WHERE course_id = %d AND status = 'active'",
            $post_id
        ));
    }
    
    public function get_course_thumbnail($post_id = null, $size = 'large') {
        $post_id = $this->get_course_id($post_id);
        return $post_id ? get_the_post_thumbnail_url($post_id, $size) : '';
    }
    
    public function get_course_excerpt($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        if (!$post_id) return '';
        
        $post = get_post($post_id);
        return $post ? $post->post_excerpt : '';
    }
    
    public function get_course_url($post_id = null) {
        $post_id = $this->get_course_id($post_id);
        return $post_id ? get_permalink($post_id) : '';
    }
    
    // User Progress Fields
    public function get_user_progress_percent($post_id = null, $user_id = null) {
        $post_id = $this->get_course_id($post_id);
        $user_id = $user_id ?: get_current_user_id();
        
        if (!$post_id || !$user_id) return 0;
        
        $progress = cm_get_progress($user_id, $post_id);
        return $progress['percent'];
    }
    
    public function get_user_progress_attended($post_id = null, $user_id = null) {
        $post_id = $this->get_course_id($post_id);
        $user_id = $user_id ?: get_current_user_id();
        
        if (!$post_id || !$user_id) return 0;
        
        $progress = cm_get_progress($user_id, $post_id);
        return $progress['attended'];
    }
    
    public function get_user_progress_total($post_id = null, $user_id = null) {
        $post_id = $this->get_course_id($post_id);
        $user_id = $user_id ?: get_current_user_id();
        
        if (!$post_id || !$user_id) return 0;
        
        $progress = cm_get_progress($user_id, $post_id);
        return $progress['total'];
    }
    
    public function get_user_progress_text($post_id = null, $user_id = null) {
        $post_id = $this->get_course_id($post_id);
        $user_id = $user_id ?: get_current_user_id();
        
        if (!$post_id || !$user_id) return '';
        
        $progress = cm_get_progress($user_id, $post_id);
        return $progress['attended'] . '/' . $progress['total'];
    }
    
    public function get_user_is_enrolled($post_id = null, $user_id = null) {
        $post_id = $this->get_course_id($post_id);
        $user_id = $user_id ?: get_current_user_id();
        
        if (!$post_id || !$user_id) return 'no';
        
        return cm_is_enrolled($user_id, $post_id) ? 'yes' : 'no';
    }
    
    public function get_user_courses_count($user_id = null) {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return 0;
        
        return count(cm_get_user_courses($user_id));
    }
    
    public function get_user_total_attendance($user_id = null) {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return 0;
        
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cm_attendance WHERE user_id = %d AND attended = 1",
            $user_id
        ));
    }
    
    // Session Fields
    public function get_session_title($session_id = null) {
        $session = $this->get_session($session_id);
        return $session ? $session->session_title : '';
    }
    
    public function get_session_date($session_id = null, $format = null) {
        $session = $this->get_session($session_id);
        if (!$session || !$session->session_date) return '';
        
        $format = $format ?: get_option('date_format');
        return date_i18n($format, strtotime($session->session_date));
    }
    
    public function get_session_time($session_id = null, $format = null) {
        $session = $this->get_session($session_id);
        if (!$session || !$session->session_time) return '';
        
        $format = $format ?: get_option('time_format');
        return date_i18n($format, strtotime($session->session_time));
    }
    
    public function get_session_link($session_id = null) {
        $session = $this->get_session($session_id);
        return $session ? $session->session_link : '';
    }
    
    public function get_session_is_attended($session_id = null, $user_id = null) {
        $session_id = $session_id ?: $this->get_current_session_id();
        $user_id = $user_id ?: get_current_user_id();
        
        if (!$session_id || !$user_id) return 'no';
        
        return cm_is_attended($user_id, $session_id) ? 'yes' : 'no';
    }
    
    public function get_session_status($session_id = null, $user_id = null) {
        $is_attended = $this->get_session_is_attended($session_id, $user_id);
        return $is_attended === 'yes' ? 'attended' : 'pending';
    }
    
    // ========================================
    // HELPER METHODS
    // ========================================
    
    private function get_course_id($post_id = null) {
        if ($post_id) return $post_id;
        
        // Try to get from current post
        $post_id = get_the_ID();
        
        if ($post_id && get_post_type($post_id) === 'cm_course') {
            return $post_id;
        }
        
        // Try to get from query var
        $course_id = get_query_var('cm_course_id');
        if ($course_id) return $course_id;
        
        return null;
    }
    
    private function get_session($session_id = null) {
        $session_id = $session_id ?: $this->get_current_session_id();
        if (!$session_id) return null;
        
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cm_sessions WHERE id = %d",
            $session_id
        ));
    }
    
    private function get_current_session_id() {
        // From loop
        global $cm_current_session;
        if (isset($cm_current_session) && $cm_current_session) {
            return $cm_current_session->id;
        }
        
        // From query var
        return get_query_var('cm_session_id');
    }
    
    public function get_field_value($field_name, $post_id = null, $args = []) {
        $fields = $this->get_available_fields();
        
        if (!isset($fields[$field_name])) {
            return '';
        }
        
        $callback = $fields[$field_name]['callback'];
        
        if (method_exists($this, $callback)) {
            return $this->$callback($post_id);
        }
        
        return '';
    }
    
    // ========================================
    // BRICKS BUILDER INTEGRATION
    // ========================================
    
    public function register_bricks_tags($tags) {
        $fields = $this->get_available_fields();
        
        // Group tags
        $groups = [
            'course' => __('CM - Course', 'courses-management'),
            'user'   => __('CM- User', 'courses-management'),
            'session'=> __('CM - Session', 'courses-management'),
        ];
        
        foreach ($fields as $tag_name => $field) {
            $group_name = $groups[$field['group']] ?? __('Course Management', 'courses-management');
            
            $tags[] = [
                'name'  => '{' . $tag_name . '}',
                'label' => $field['label'],
                'group' => $group_name,
            ];
        }
        
        return $tags;
    }
    
    public function render_bricks_tag($tag, $post, $context = 'text') {
        // Remove curly braces
        $tag_name = trim($tag, '{}');
        
        $fields = $this->get_available_fields();
        
        if (!isset($fields[$tag_name])) {
            return $tag;
        }
        
        $callback = $fields[$tag_name]['callback'];
        $post_id = is_object($post) ? $post->ID : $post;
        
        if (method_exists($this, $callback)) {
            return $this->$callback($post_id);
        }
        
        return $tag;
    }
    
    public function render_bricks_content($content, $post, $context = 'text') {
        $fields = $this->get_available_fields();
        
        foreach ($fields as $tag_name => $field) {
            if (strpos($content, '{' . $tag_name . '}') !== false) {
                $value = $this->get_field_value($tag_name, is_object($post) ? $post->ID : $post);
                $content = str_replace('{' . $tag_name . '}', $value, $content);
            }
        }
        
        return $content;
    }
    
    // ========================================
    // ELEMENTOR INTEGRATION
    // ========================================
    
    public function register_elementor_tags($dynamic_tags_manager) {
        // Register group
        $dynamic_tags_manager->register_group(
            'cm-course-fields',
            [
                'title' => __('Course Management', 'courses-management'),
            ]
        );
        
        // Register tags
        require_once CM_PLUGIN_DIR . 'includes/elementor/class-cm-elementor-tags.php';
        
        // Course Tags
        $dynamic_tags_manager->register(new CM_Elementor_Tag_Course_Title());
        $dynamic_tags_manager->register(new CM_Elementor_Tag_Course_Price());
        $dynamic_tags_manager->register(new CM_Elementor_Tag_Course_Duration());
        $dynamic_tags_manager->register(new CM_Elementor_Tag_Course_Age_Range());
        $dynamic_tags_manager->register(new CM_Elementor_Tag_Course_Sessions_Count());
        $dynamic_tags_manager->register(new CM_Elementor_Tag_Course_Students_Count());
        $dynamic_tags_manager->register(new CM_Elementor_Tag_Course_Thumbnail());
        $dynamic_tags_manager->register(new CM_Elementor_Tag_Course_URL());
        
        // User/Progress Tags
        $dynamic_tags_manager->register(new CM_Elementor_Tag_User_Progress());
        $dynamic_tags_manager->register(new CM_Elementor_Tag_User_Is_Enrolled());
    }
    
    // ========================================
    // REST API
    // ========================================
    
    public function register_rest_routes() {
        register_rest_route('cm/v1', '/dynamic-data', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_get_dynamic_data'],
            'permission_callback' => '__return_true',
        ]);
        
        register_rest_route('cm/v1', '/dynamic-data/(?P<field>[a-zA-Z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_get_field_value'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    public function rest_get_dynamic_data($request) {
        $post_id = $request->get_param('post_id') ?: get_the_ID();
        $fields = $this->get_available_fields();
        
        $data = [];
        foreach ($fields as $field_name => $field) {
            $data[$field_name] = [
                'label' => $field['label'],
                'group' => $field['group'],
                'value' => $this->get_field_value($field_name, $post_id),
            ];
        }
        
        return rest_ensure_response($data);
    }
    
    public function rest_get_field_value($request) {
        $field = $request->get_param('field');
        $post_id = $request->get_param('post_id') ?: get_the_ID();
        
        $value = $this->get_field_value($field, $post_id);
        
        return rest_ensure_response([
            'field' => $field,
            'value' => $value,
        ]);
    }
}