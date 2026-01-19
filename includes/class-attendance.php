<?php
/**
 * Advanced Attendance System
 * Features: QR Code, Alerts, Smart Rules, Instructor Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

class CM_Attendance {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Frontend AJAX
        add_action('wp_ajax_cm_mark_attended', [$this, 'ajax_mark_attended']);
        add_action('wp_ajax_cm_get_user_progress', [$this, 'ajax_get_progress']);
        add_action('wp_ajax_cm_get_course_sessions', [$this, 'ajax_get_sessions']);
        
        // QR Code AJAX
        add_action('wp_ajax_cm_generate_qr', [$this, 'ajax_generate_qr']);
        add_action('wp_ajax_cm_verify_qr', [$this, 'ajax_verify_qr']);
        add_action('wp_ajax_nopriv_cm_verify_qr', [$this, 'ajax_verify_qr']);
        add_action('wp_ajax_cm_scan_qr', [$this, 'ajax_scan_qr']);
        
        // Alerts & Intelligence
        add_action('wp_ajax_cm_get_attendance_alerts', [$this, 'ajax_get_alerts']);
        add_action('wp_ajax_cm_get_attendance_analytics', [$this, 'ajax_get_analytics']);
        add_action('wp_ajax_cm_check_subscription_rules', [$this, 'ajax_check_rules']);
        
        // Instructor Tools
        add_action('wp_ajax_cm_quick_attendance', [$this, 'ajax_quick_attendance']);
        add_action('wp_ajax_cm_bulk_mark_attendance', [$this, 'ajax_bulk_mark']);
        add_action('wp_ajax_cm_get_live_attendance', [$this, 'ajax_live_attendance']);
        add_action('wp_ajax_cm_send_attendance_reminder', [$this, 'ajax_send_reminder']);
        add_action('wp_ajax_cm_export_attendance', [$this, 'ajax_export_attendance']);
        
        // Shortcodes
        add_shortcode('cm_qr_scanner', [$this, 'shortcode_qr_scanner']);
        add_shortcode('cm_qr_code', [$this, 'shortcode_qr_code']);
        add_shortcode('cm_attendance_stats', [$this, 'shortcode_attendance_stats']);
        add_shortcode('cm_live_attendance', [$this, 'shortcode_live_attendance']);
        
        // Cron for alerts
        add_action('cm_check_attendance_alerts', [$this, 'process_attendance_alerts']);
        
        // Schedule cron if not exists
        if (!wp_next_scheduled('cm_check_attendance_alerts')) {
            wp_schedule_event(time(), 'hourly', 'cm_check_attendance_alerts');
        }
    }
    
    // ========================================
    // QR CODE SYSTEM
    // ========================================
    
    /**
     * Generate QR Code for Session
     */
    public function ajax_generate_qr() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'courses-management')]);
        }
        
        $session_id = intval($_POST['session_id'] ?? 0);
        $expiry_minutes = intval($_POST['expiry'] ?? 30);
        
        if (!$session_id) {
            wp_send_json_error(['message' => __('Invalid session', 'courses-management')]);
        }
        
        // Generate unique token
        $token = wp_generate_password(32, false);
        $expiry = time() + ($expiry_minutes * 60);
        
        // Store token
        $qr_data = [
            'token'      => $token,
            'session_id' => $session_id,
            'expiry'     => $expiry,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ];
        
        update_option('cm_qr_' . $token, $qr_data);
        
        // Generate QR URL
        $qr_url = add_query_arg([
            'cm_qr'   => $token,
            'session' => $session_id,
        ], home_url('/cm-attendance/'));
        
        // Generate QR image using Google Charts API (free)
        $qr_image = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qr_url) . '&choe=UTF-8';
        
        wp_send_json_success([
            'qr_image'   => $qr_image,
            'qr_url'     => $qr_url,
            'token'      => $token,
            'expiry'     => date_i18n('Y-m-d H:i:s', $expiry),
            'expiry_in'  => $expiry_minutes . ' ' . __('minutes', 'courses-management'),
        ]);
    }
    
    /**
     * Verify QR Code
     */
    public function ajax_verify_qr() {
        $token = sanitize_text_field($_POST['token'] ?? $_GET['token'] ?? '');
        
        if (!$token) {
            wp_send_json_error(['message' => __('Invalid QR code', 'courses-management')]);
        }
        
        $qr_data = get_option('cm_qr_' . $token);
        
        if (!$qr_data) {
            wp_send_json_error(['message' => __('QR code not found or expired', 'courses-management')]);
        }
        
        if (time() > $qr_data['expiry']) {
            delete_option('cm_qr_' . $token);
            wp_send_json_error(['message' => __('QR code has expired', 'courses-management')]);
        }
        
        // Get session info
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, p.post_title as course_title 
             FROM {$wpdb->prefix}cm_sessions s
             JOIN {$wpdb->posts} p ON s.course_id = p.ID
             WHERE s.id = %d",
            $qr_data['session_id']
        ));
        
        wp_send_json_success([
            'valid'        => true,
            'session_id'   => $qr_data['session_id'],
            'session'      => $session,
            'expires_in'   => $qr_data['expiry'] - time(),
        ]);
    }
    
    /**
     * Scan QR and Mark Attendance
     */
    public function ajax_scan_qr() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Please login first', 'courses-management')]);
        }
        
        $token = sanitize_text_field($_POST['token'] ?? '');
        $user_id = get_current_user_id();
        
        if (!$token) {
            wp_send_json_error(['message' => __('Invalid QR code', 'courses-management')]);
        }
        
        $qr_data = get_option('cm_qr_' . $token);
        
        if (!$qr_data || time() > $qr_data['expiry']) {
            wp_send_json_error(['message' => __('QR code expired', 'courses-management')]);
        }
        
        $session_id = $qr_data['session_id'];
        
        // Get session
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cm_sessions WHERE id = %d",
            $session_id
        ));
        
        if (!$session) {
            wp_send_json_error(['message' => __('Session not found', 'courses-management')]);
        }
        
        // Check if enrolled
        if (!cm_is_enrolled($user_id, $session->course_id)) {
            wp_send_json_error(['message' => __('You are not enrolled in this course', 'courses-management')]);
        }
        
        // Mark attendance
        $result = $wpdb->replace($wpdb->prefix . 'cm_attendance', [
            'user_id'     => $user_id,
            'course_id'   => $session->course_id,
            'session_id'  => $session_id,
            'attended'    => 1,
            'attended_at' => current_time('mysql'),
        ]);
        
        // Log QR scan
        $this->log_qr_scan($token, $user_id, $session_id);
        
        wp_send_json_success([
            'message'  => __('Attendance marked successfully!', 'courses-management'),
            'session'  => $session->session_title,
            'progress' => cm_get_progress($user_id, $session->course_id),
        ]);
    }
    
    /**
     * Log QR Scan
     */
    private function log_qr_scan($token, $user_id, $session_id) {
        $logs = get_option('cm_qr_logs', []);
        
        $logs[] = [
            'token'      => $token,
            'user_id'    => $user_id,
            'session_id' => $session_id,
            'scanned_at' => current_time('mysql'),
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        
        // Keep last 1000 logs
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        update_option('cm_qr_logs', $logs);
    }
    
    // ========================================
    // ATTENDANCE INTELLIGENCE & ALERTS
    // ========================================
    
    /**
     * Get Attendance Alerts
     */
    public function ajax_get_alerts() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'courses-management')]);
        }
        
        $course_id = intval($_POST['course_id'] ?? 0);
        
        $alerts = $this->get_attendance_alerts($course_id);
        
        wp_send_json_success(['alerts' => $alerts]);
    }
    
    /**
     * Get Attendance Alerts Data
     */
    public function get_attendance_alerts($course_id = 0) {
        global $wpdb;
        
        $alerts = [];
        $threshold_low = 50;  // Below 50% is low attendance
        $threshold_critical = 25; // Below 25% is critical
        
        // Get courses to check
        $where = $course_id ? $wpdb->prepare("AND uc.course_id = %d", $course_id) : "";
        
        // Students with low attendance
        $low_attendance = $wpdb->get_results("
            SELECT 
                u.ID as user_id,
                u.display_name,
                u.user_email,
                uc.course_id,
                p.post_title as course_title,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cm_sessions WHERE course_id = uc.course_id) as total_sessions,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cm_attendance 
                 WHERE user_id = u.ID AND course_id = uc.course_id AND attended = 1) as attended_sessions
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}cm_user_courses uc ON u.ID = uc.user_id
            INNER JOIN {$wpdb->posts} p ON uc.course_id = p.ID
            WHERE uc.status = 'active' AND p.post_status = 'publish' $where
            HAVING total_sessions > 0 AND (attended_sessions / total_sessions * 100) < $threshold_low
            ORDER BY (attended_sessions / total_sessions) ASC
            LIMIT 50
        ");
        
        foreach ($low_attendance as $student) {
            $percent = $student->total_sessions > 0 
                ? round(($student->attended_sessions / $student->total_sessions) * 100) 
                : 0;
            
            $type = $percent < $threshold_critical ? 'critical' : 'warning';
            
            $alerts[] = [
                'type'         => $type,
                'user_id'      => $student->user_id,
                'user_name'    => $student->display_name,
                'user_email'   => $student->user_email,
                'course_id'    => $student->course_id,
                'course_title' => $student->course_title,
                'attendance'   => $percent,
                'attended'     => $student->attended_sessions,
                'total'        => $student->total_sessions,
                'message'      => sprintf(
                    __('%s has only %d%% attendance in %s', 'courses-management'),
                    $student->display_name,
                    $percent,
                    $student->course_title
                ),
            ];
        }
        
        // Students who missed consecutive sessions
        $consecutive_missed = $wpdb->get_results("
            SELECT 
                u.ID as user_id,
                u.display_name,
                u.user_email,
                uc.course_id,
                p.post_title as course_title,
                (
                    SELECT COUNT(*) 
                    FROM {$wpdb->prefix}cm_sessions s
                    LEFT JOIN {$wpdb->prefix}cm_attendance a ON s.id = a.session_id AND a.user_id = u.ID
                    WHERE s.course_id = uc.course_id 
                    AND s.session_date <= CURDATE()
                    AND (a.attended IS NULL OR a.attended = 0)
                    AND s.session_order >= (
                        SELECT COALESCE(MAX(s2.session_order), 0)
                        FROM {$wpdb->prefix}cm_sessions s2
                        JOIN {$wpdb->prefix}cm_attendance a2 ON s2.id = a2.session_id
                        WHERE s2.course_id = uc.course_id AND a2.user_id = u.ID AND a2.attended = 1
                    )
                ) as consecutive_missed
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}cm_user_courses uc ON u.ID = uc.user_id
            INNER JOIN {$wpdb->posts} p ON uc.course_id = p.ID
            WHERE uc.status = 'active' AND p.post_status = 'publish' $where
            HAVING consecutive_missed >= 3
            ORDER BY consecutive_missed DESC
        ");
        
        foreach ($consecutive_missed as $student) {
            $alerts[] = [
                'type'         => 'consecutive',
                'user_id'      => $student->user_id,
                'user_name'    => $student->display_name,
                'user_email'   => $student->user_email,
                'course_id'    => $student->course_id,
                'course_title' => $student->course_title,
                'missed_count' => $student->consecutive_missed,
                'message'      => sprintf(
                    __('%s missed %d consecutive sessions in %s', 'courses-management'),
                    $student->display_name,
                    $student->consecutive_missed,
                    $student->course_title
                ),
            ];
        }
        
        // Sort by severity
        usort($alerts, function($a, $b) {
            $order = ['critical' => 0, 'consecutive' => 1, 'warning' => 2];
            return ($order[$a['type']] ?? 3) - ($order[$b['type']] ?? 3);
        });
        
        return $alerts;
    }
    
    /**
     * Get Attendance Analytics
     */
    public function ajax_get_analytics() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        $course_id = intval($_POST['course_id'] ?? 0);
        $date_range = sanitize_text_field($_POST['range'] ?? '30');
        
        $analytics = $this->get_attendance_analytics($course_id, $date_range);
        
        wp_send_json_success($analytics);
    }
    
    /**
     * Get Attendance Analytics Data
     */
    public function get_attendance_analytics($course_id = 0, $range = '30') {
        global $wpdb;
        
        $where_course = $course_id ? $wpdb->prepare("AND s.course_id = %d", $course_id) : "";
        $where_date = "";
        
        if ($range !== 'all') {
            $where_date = $wpdb->prepare("AND a.attended_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", intval($range));
        }
        
        $analytics = [];
        
        // Overall stats
        $analytics['overall'] = $wpdb->get_row("
            SELECT 
                COUNT(DISTINCT a.user_id) as unique_students,
                COUNT(CASE WHEN a.attended = 1 THEN 1 END) as total_attended,
                COUNT(*) as total_records
            FROM {$wpdb->prefix}cm_attendance a
            INNER JOIN {$wpdb->prefix}cm_sessions s ON a.session_id = s.id
            WHERE 1=1 $where_course $where_date
        ");
        
        // Attendance by day of week
        $analytics['by_day'] = $wpdb->get_results("
            SELECT 
                DAYNAME(a.attended_at) as day_name,
                DAYOFWEEK(a.attended_at) as day_num,
                COUNT(*) as count
            FROM {$wpdb->prefix}cm_attendance a
            INNER JOIN {$wpdb->prefix}cm_sessions s ON a.session_id = s.id
            WHERE a.attended = 1 $where_course $where_date
            GROUP BY day_name, day_num
            ORDER BY day_num
        ");
        
        // Attendance by hour
        $analytics['by_hour'] = $wpdb->get_results("
            SELECT 
                HOUR(a.attended_at) as hour,
                COUNT(*) as count
            FROM {$wpdb->prefix}cm_attendance a
            INNER JOIN {$wpdb->prefix}cm_sessions s ON a.session_id = s.id
            WHERE a.attended = 1 $where_course $where_date
            GROUP BY hour
            ORDER BY hour
        ");
        
        // Attendance trend (daily)
        $analytics['trend'] = $wpdb->get_results("
            SELECT 
                DATE(a.attended_at) as date,
                COUNT(*) as count
            FROM {$wpdb->prefix}cm_attendance a
            INNER JOIN {$wpdb->prefix}cm_sessions s ON a.session_id = s.id
            WHERE a.attended = 1 $where_course $where_date
            GROUP BY DATE(a.attended_at)
            ORDER BY date
        ");
        
        // Top performers
        $analytics['top_students'] = $wpdb->get_results("
            SELECT 
                u.ID,
                u.display_name,
                COUNT(CASE WHEN a.attended = 1 THEN 1 END) as attended_count,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cm_user_courses WHERE user_id = u.ID AND status = 'active') as courses_count
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}cm_attendance a ON u.ID = a.user_id
            INNER JOIN {$wpdb->prefix}cm_sessions s ON a.session_id = s.id
            WHERE 1=1 $where_course $where_date
            GROUP BY u.ID
            ORDER BY attended_count DESC
            LIMIT 10
        ");
        
        // Attendance by course (if not filtered)
        if (!$course_id) {
            $analytics['by_course'] = $wpdb->get_results("
                SELECT 
                    p.ID as course_id,
                    p.post_title,
                    COUNT(CASE WHEN a.attended = 1 THEN 1 END) as attended,
                    COUNT(*) as total,
                    ROUND(COUNT(CASE WHEN a.attended = 1 THEN 1 END) / COUNT(*) * 100, 1) as rate
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->prefix}cm_sessions s ON p.ID = s.course_id
                INNER JOIN {$wpdb->prefix}cm_attendance a ON s.id = a.session_id
                WHERE p.post_type = 'cm_course' AND p.post_status = 'publish' $where_date
                GROUP BY p.ID
                ORDER BY rate DESC
                LIMIT 10
            ");
        }
        
        return $analytics;
    }
    
    // ========================================
    // SMART SUBSCRIPTION RULES
    // ========================================
    
    /**
     * Check Subscription Rules
     */
    public function ajax_check_rules() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        $user_id = intval($_POST['user_id'] ?? get_current_user_id());
        $course_id = intval($_POST['course_id'] ?? 0);
        
        $result = $this->check_subscription_rules($user_id, $course_id);
        
        wp_send_json_success($result);
    }
    
    /**
     * Check Subscription Rules for User
     */
    public function check_subscription_rules($user_id, $course_id = 0) {
        global $wpdb;
        
        $rules = get_option('cm_subscription_rules', $this->get_default_rules());
        $violations = [];
        $status = 'active';
        
        // Get user's courses
        $where_course = $course_id ? $wpdb->prepare("AND uc.course_id = %d", $course_id) : "";
        
        $user_courses = $wpdb->get_results($wpdb->prepare("
            SELECT 
                uc.*,
                p.post_title,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cm_sessions WHERE course_id = uc.course_id) as total_sessions,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cm_attendance 
                 WHERE user_id = %d AND course_id = uc.course_id AND attended = 1) as attended_sessions
            FROM {$wpdb->prefix}cm_user_courses uc
            INNER JOIN {$wpdb->posts} p ON uc.course_id = p.ID
            WHERE uc.user_id = %d AND uc.status = 'active' $where_course
        ", $user_id, $user_id));
        
        foreach ($user_courses as $course) {
            $attendance_rate = $course->total_sessions > 0 
                ? ($course->attended_sessions / $course->total_sessions) * 100 
                : 100;
            
            // Rule: Minimum attendance percentage
            if ($rules['min_attendance_enabled'] && $attendance_rate < $rules['min_attendance_percent']) {
                $violations[] = [
                    'rule'       => 'min_attendance',
                    'course_id'  => $course->course_id,
                    'course'     => $course->post_title,
                    'required'   => $rules['min_attendance_percent'] . '%',
                    'actual'     => round($attendance_rate) . '%',
                    'action'     => $rules['min_attendance_action'],
                    'message'    => sprintf(
                        __('Attendance is %d%%, minimum required is %d%%', 'courses-management'),
                        round($attendance_rate),
                        $rules['min_attendance_percent']
                    ),
                ];
                
                if ($rules['min_attendance_action'] === 'suspend') {
                    $status = 'suspended';
                }
            }
            
            // Rule: Maximum consecutive absences
            if ($rules['max_absences_enabled']) {
                $consecutive = $this->get_consecutive_absences($user_id, $course->course_id);
                
                if ($consecutive >= $rules['max_absences_count']) {
                    $violations[] = [
                        'rule'       => 'max_absences',
                        'course_id'  => $course->course_id,
                        'course'     => $course->post_title,
                        'max'        => $rules['max_absences_count'],
                        'actual'     => $consecutive,
                        'action'     => $rules['max_absences_action'],
                        'message'    => sprintf(
                            __('Missed %d consecutive sessions, maximum allowed is %d', 'courses-management'),
                            $consecutive,
                            $rules['max_absences_count']
                        ),
                    ];
                    
                    if ($rules['max_absences_action'] === 'suspend') {
                        $status = 'suspended';
                    }
                }
            }
        }
        
        return [
            'user_id'    => $user_id,
            'status'     => $status,
            'violations' => $violations,
            'rules'      => $rules,
        ];
    }
    
    /**
     * Get Default Subscription Rules
     */
    private function get_default_rules() {
        return [
            'min_attendance_enabled'  => false,
            'min_attendance_percent'  => 70,
            'min_attendance_action'   => 'warn', // warn, suspend, notify
            
            'max_absences_enabled'    => false,
            'max_absences_count'      => 3,
            'max_absences_action'     => 'warn',
            
            'auto_notify_instructor'  => true,
            'auto_notify_student'     => true,
            'grace_period_days'       => 7,
        ];
    }
    
    /**
     * Get Consecutive Absences
     */
    private function get_consecutive_absences($user_id, $course_id) {
        global $wpdb;
        
        // Get sessions in order
        $sessions = $wpdb->get_results($wpdb->prepare("
            SELECT 
                s.id,
                s.session_order,
                COALESCE(a.attended, 0) as attended
            FROM {$wpdb->prefix}cm_sessions s
            LEFT JOIN {$wpdb->prefix}cm_attendance a ON s.id = a.session_id AND a.user_id = %d
            WHERE s.course_id = %d
            ORDER BY s.session_order DESC
        ", $user_id, $course_id));
        
        $consecutive = 0;
        foreach ($sessions as $session) {
            if (!$session->attended) {
                $consecutive++;
            } else {
                break;
            }
        }
        
        return $consecutive;
    }
    
    // ========================================
    // INSTRUCTOR PRODUCTIVITY TOOLS
    // ========================================
    
    /**
     * Quick Attendance (Mark multiple students at once)
     */
    public function ajax_quick_attendance() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'courses-management')]);
        }
        
        $session_id = intval($_POST['session_id'] ?? 0);
        $present_ids = isset($_POST['present']) ? array_map('intval', $_POST['present']) : [];
        
        if (!$session_id) {
            wp_send_json_error(['message' => __('Invalid session', 'courses-management')]);
        }
        
        global $wpdb;
        
        // Get session info
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cm_sessions WHERE id = %d",
            $session_id
        ));
        
        if (!$session) {
            wp_send_json_error(['message' => __('Session not found', 'courses-management')]);
        }
        
        // Get all enrolled students
        $enrolled = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}cm_user_courses WHERE course_id = %d AND status = 'active'",
            $session->course_id
        ));
        
        $marked = 0;
        $table = $wpdb->prefix . 'cm_attendance';
        
        foreach ($enrolled as $user_id) {
            $attended = in_array($user_id, $present_ids) ? 1 : 0;
            
            $wpdb->replace($table, [
                'user_id'     => $user_id,
                'course_id'   => $session->course_id,
                'session_id'  => $session_id,
                'attended'    => $attended,
                'attended_at' => $attended ? current_time('mysql') : null,
            ]);
            
            $marked++;
        }
        
        wp_send_json_success([
            'message' => sprintf(__('Attendance marked for %d students', 'courses-management'), $marked),
            'present' => count($present_ids),
            'absent'  => $marked - count($present_ids),
        ]);
    }
    
    /**
     * Bulk Mark Attendance
     */
    public function ajax_bulk_mark() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'courses-management')]);
        }
        
        $session_id = intval($_POST['session_id'] ?? 0);
        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $user_ids = isset($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : [];
        
        if (!$session_id || !$action || empty($user_ids)) {
            wp_send_json_error(['message' => __('Invalid data', 'courses-management')]);
        }
        
        global $wpdb;
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cm_sessions WHERE id = %d",
            $session_id
        ));
        
        if (!$session) {
            wp_send_json_error(['message' => __('Session not found', 'courses-management')]);
        }
        
        $attended = ($action === 'present') ? 1 : 0;
        $table = $wpdb->prefix . 'cm_attendance';
        
        foreach ($user_ids as $user_id) {
            $wpdb->replace($table, [
                'user_id'     => $user_id,
                'course_id'   => $session->course_id,
                'session_id'  => $session_id,
                'attended'    => $attended,
                'attended_at' => $attended ? current_time('mysql') : null,
            ]);
        }
        
        wp_send_json_success([
            'message' => sprintf(
                __('%d students marked as %s', 'courses-management'),
                count($user_ids),
                $action
            ),
        ]);
    }
    
    /**
     * Get Live Attendance Status
     */
    public function ajax_live_attendance() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        $session_id = intval($_POST['session_id'] ?? 0);
        
        if (!$session_id) {
            wp_send_json_error(['message' => __('Invalid session', 'courses-management')]);
        }
        
        global $wpdb;
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, p.post_title as course_title
             FROM {$wpdb->prefix}cm_sessions s
             JOIN {$wpdb->posts} p ON s.course_id = p.ID
             WHERE s.id = %d",
            $session_id
        ));
        
        if (!$session) {
            wp_send_json_error(['message' => __('Session not found', 'courses-management')]);
        }
        
        // Get attendance status
        $students = $wpdb->get_results($wpdb->prepare("
            SELECT 
                u.ID,
                u.display_name,
                u.user_email,
                a.attended,
                a.attended_at
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}cm_user_courses uc ON u.ID = uc.user_id
            LEFT JOIN {$wpdb->prefix}cm_attendance a ON u.ID = a.user_id AND a.session_id = %d
            WHERE uc.course_id = %d AND uc.status = 'active'
            ORDER BY a.attended DESC, u.display_name ASC
        ", $session_id, $session->course_id));
        
        $present = 0;
        $absent = 0;
        
        foreach ($students as $student) {
            if ($student->attended) {
                $present++;
            } else {
                $absent++;
            }
        }
        
        wp_send_json_success([
            'session'  => $session,
            'students' => $students,
            'stats'    => [
                'total'   => count($students),
                'present' => $present,
                'absent'  => $absent,
                'rate'    => count($students) > 0 ? round(($present / count($students)) * 100) : 0,
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * Send Attendance Reminder
     */
    public function ajax_send_reminder() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'courses-management')]);
        }
        
        $session_id = intval($_POST['session_id'] ?? 0);
        $user_ids = isset($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : [];
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        
        if (!$session_id) {
            wp_send_json_error(['message' => __('Invalid session', 'courses-management')]);
        }
        
        global $wpdb;
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, p.post_title as course_title
             FROM {$wpdb->prefix}cm_sessions s
             JOIN {$wpdb->posts} p ON s.course_id = p.ID
             WHERE s.id = %d",
            $session_id
        ));
        
        // Get absent students if not specified
        if (empty($user_ids)) {
            $user_ids = $wpdb->get_col($wpdb->prepare("
                SELECT uc.user_id
                FROM {$wpdb->prefix}cm_user_courses uc
                LEFT JOIN {$wpdb->prefix}cm_attendance a ON uc.user_id = a.user_id AND a.session_id = %d
                WHERE uc.course_id = %d AND uc.status = 'active'
                AND (a.attended IS NULL OR a.attended = 0)
            ", $session_id, $session->course_id));
        }
        
        $sent = 0;
        
        foreach ($user_ids as $user_id) {
            $user = get_userdata($user_id);
            if (!$user) continue;
            
            $subject = sprintf(
                __('[%s] Attendance Reminder - %s', 'courses-management'),
                get_bloginfo('name'),
                $session->session_title
            );
            
            $body = $message ?: sprintf(
                __("Dear %s,\n\nThis is a reminder about the session \"%s\" in the course \"%s\".\n\nPlease make sure to attend and mark your attendance.\n\nBest regards,\n%s", 'courses-management'),
                $user->display_name,
                $session->session_title,
                $session->course_title,
                get_bloginfo('name')
            );
            
            if (wp_mail($user->user_email, $subject, $body)) {
                $sent++;
            }
        }
        
        wp_send_json_success([
            'message' => sprintf(__('Reminder sent to %d students', 'courses-management'), $sent),
            'sent'    => $sent,
        ]);
    }
    
    /**
     * Export Attendance
     */
    public function ajax_export_attendance() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'courses-management'));
        }
        
        $course_id = intval($_GET['course_id'] ?? 0);
        $session_id = intval($_GET['session_id'] ?? 0);
        $format = sanitize_text_field($_GET['format'] ?? 'csv');
        
        global $wpdb;
        
        // Build query
        $where = "WHERE 1=1";
        if ($course_id) {
            $where .= $wpdb->prepare(" AND s.course_id = %d", $course_id);
        }
        if ($session_id) {
            $where .= $wpdb->prepare(" AND a.session_id = %d", $session_id);
        }
        
        $data = $wpdb->get_results("
            SELECT 
                u.display_name as student_name,
                u.user_email as student_email,
                p.post_title as course_name,
                s.session_title,
                s.session_date,
                CASE WHEN a.attended = 1 THEN 'Present' ELSE 'Absent' END as status,
                a.attended_at
            FROM {$wpdb->prefix}cm_attendance a
            INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
            INNER JOIN {$wpdb->prefix}cm_sessions s ON a.session_id = s.id
            INNER JOIN {$wpdb->posts} p ON s.course_id = p.ID
            $where
            ORDER BY p.post_title, s.session_order, u.display_name
        ");
        
        // Output CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="attendance-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        fputcsv($output, ['Student', 'Email', 'Course', 'Session', 'Date', 'Status', 'Marked At']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row->student_name,
                $row->student_email,
                $row->course_name,
                $row->session_title,
                $row->session_date,
                $row->status,
                $row->attended_at,
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    // ========================================
    // SHORTCODES
    // ========================================
    
    /**
     * QR Scanner Shortcode
     */
    public function shortcode_qr_scanner($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please login to scan QR codes.', 'courses-management') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="cm-qr-scanner-wrap" id="cm-qr-scanner">
            <div class="cm-scanner-status">
                <p><?php _e('Click the button below to scan a QR code', 'courses-management'); ?></p>
            </div>
            
            <div class="cm-scanner-video-wrap" style="display: none;">
                <video id="cm-scanner-video" playsinline></video>
                <div class="cm-scanner-overlay"></div>
            </div>
            
            <div class="cm-scanner-result" style="display: none;">
                <div class="cm-result-success">
                    <span class="cm-result-icon">✓</span>
                    <h3><?php _e('Attendance Marked!', 'courses-management'); ?></h3>
                    <p class="cm-result-session"></p>
                </div>
            </div>
            
            <div class="cm-scanner-buttons">
                <button type="button" id="cm-start-scan" class="cm-btn cm-btn-primary">
                    <span class="dashicons dashicons-camera"></span>
                    <?php _e('Scan QR Code', 'courses-management'); ?>
                </button>
                <button type="button" id="cm-stop-scan" class="cm-btn cm-btn-secondary" style="display: none;">
                    <?php _e('Stop Scanning', 'courses-management'); ?>
                </button>
            </div>
            
            <div class="cm-manual-entry">
                <p><?php _e('Or enter code manually:', 'courses-management'); ?></p>
                <input type="text" id="cm-manual-code" placeholder="<?php _e('Enter code...', 'courses-management'); ?>">
                <button type="button" id="cm-submit-code" class="cm-btn cm-btn-secondary">
                    <?php _e('Submit', 'courses-management'); ?>
                </button>
            </div>
        </div>
        
        <style>
        .cm-qr-scanner-wrap { max-width: 400px; margin: 0 auto; text-align: center; }
        .cm-scanner-video-wrap { position: relative; margin: 20px 0; border-radius: 12px; overflow: hidden; }
        #cm-scanner-video { width: 100%; height: auto; }
        .cm-scanner-overlay { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 200px; height: 200px; border: 3px solid #fff; border-radius: 12px; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5); }
        .cm-result-success { padding: 30px; background: #d1fae5; border-radius: 12px; margin: 20px 0; }
        .cm-result-icon { font-size: 48px; color: #065f46; }
        .cm-scanner-buttons { margin: 20px 0; }
        .cm-btn { padding: 12px 24px; border-radius: 8px; border: none; cursor: pointer; font-size: 16px; display: inline-flex; align-items: center; gap: 8px; }
        .cm-btn-primary { background: #2563eb; color: #fff; }
        .cm-btn-secondary { background: #e5e7eb; color: #333; }
        .cm-manual-entry { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .cm-manual-entry input { padding: 10px; border: 1px solid #ddd; border-radius: 6px; width: 200px; margin-right: 10px; }
        </style>
        
        <script src="https://unpkg.com/html5-qrcode@2.3.4/html5-qrcode.min.js"></script>
        <script>
        jQuery(document).ready(function($) {
            let scanner = null;
            
            $('#cm-start-scan').on('click', function() {
                $('.cm-scanner-video-wrap').show();
                $(this).hide();
                $('#cm-stop-scan').show();
                
                scanner = new Html5Qrcode("cm-scanner-video");
                
                scanner.start(
                    { facingMode: "environment" },
                    { fps: 10, qrbox: 200 },
                    onScanSuccess,
                    onScanError
                );
            });
            
            $('#cm-stop-scan').on('click', function() {
                if (scanner) {
                    scanner.stop();
                }
                $('.cm-scanner-video-wrap').hide();
                $(this).hide();
                $('#cm-start-scan').show();
            });
            
            function onScanSuccess(decodedText) {
                if (scanner) {
                    scanner.stop();
                }
                
                // Extract token from URL
                const url = new URL(decodedText);
                const token = url.searchParams.get('cm_qr');
                
                if (token) {
                    submitCode(token);
                }
            }
            
            function onScanError(error) {
                // Ignore errors during scanning
            }
            
            $('#cm-submit-code').on('click', function() {
                const code = $('#cm-manual-code').val().trim();
                if (code) {
                    submitCode(code);
                }
            });
            
            function submitCode(token) {
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_scan_qr',
                        nonce: CM.nonce,
                        token: token
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.cm-scanner-status, .cm-scanner-video-wrap, .cm-scanner-buttons, .cm-manual-entry').hide();
                            $('.cm-result-session').text(response.data.session);
                            $('.cm-scanner-result').show();
                        } else {
                            alert(response.data.message);
                        }
                    }
                });
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * QR Code Display Shortcode (for instructors)
     */
    public function shortcode_qr_code($atts) {
        if (!current_user_can('edit_posts')) {
            return '';
        }
        
        $atts = shortcode_atts([
            'session_id' => 0,
            'expiry'     => 30,
        ], $atts);
        
        $session_id = intval($atts['session_id']);
        
        if (!$session_id) {
            return '<p>' . __('Please specify a session ID.', 'courses-management') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="cm-qr-display" id="cm-qr-display-<?php echo $session_id; ?>" data-session="<?php echo $session_id; ?>">
            <div class="cm-qr-loading">
                <p><?php _e('Generating QR Code...', 'courses-management'); ?></p>
            </div>
            <div class="cm-qr-content" style="display: none;">
                <img class="cm-qr-image" src="" alt="QR Code">
                <p class="cm-qr-expiry"></p>
                <button type="button" class="cm-btn cm-regenerate-qr"><?php _e('Regenerate', 'courses-management'); ?></button>
            </div>
        </div>
        
        <style>
        .cm-qr-display { text-align: center; padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .cm-qr-image { max-width: 300px; margin: 0 auto 15px; }
        .cm-qr-expiry { color: #666; margin-bottom: 15px; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            const $wrap = $('#cm-qr-display-<?php echo $session_id; ?>');
            const sessionId = $wrap.data('session');
            
            function generateQR() {
                $wrap.find('.cm-qr-loading').show();
                $wrap.find('.cm-qr-content').hide();
                
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_generate_qr',
                        nonce: CM.nonce,
                        session_id: sessionId,
                        expiry: <?php echo intval($atts['expiry']); ?>
                    },
                    success: function(response) {
                        if (response.success) {
                            $wrap.find('.cm-qr-image').attr('src', response.data.qr_image);
                            $wrap.find('.cm-qr-expiry').text('Expires: ' + response.data.expiry);
                            $wrap.find('.cm-qr-loading').hide();
                            $wrap.find('.cm-qr-content').show();
                        }
                    }
                });
            }
            
            generateQR();
            
            $wrap.find('.cm-regenerate-qr').on('click', generateQR);
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Attendance Stats Shortcode
     */
    public function shortcode_attendance_stats($atts) {
        $atts = shortcode_atts([
            'course_id' => 0,
            'user_id'   => 0,
        ], $atts);
        
        $course_id = intval($atts['course_id']) ?: get_the_ID();
        $user_id = intval($atts['user_id']) ?: get_current_user_id();
        
        if (!$user_id) return '';
        
        $progress = cm_get_progress($user_id, $course_id);
        $color = $progress['percent'] >= 80 ? '#0C9D61' : ($progress['percent'] >= 50 ? '#F1840D' : '#dc3545');
        
        ob_start();
        ?>
        <div class="cm-attendance-stats">
            <div class="cm-stat-circle" style="--progress: <?php echo $progress['percent']; ?>; --color: <?php echo $color; ?>;">
                <span class="cm-stat-percent"><?php echo $progress['percent']; ?>%</span>
            </div>
            <div class="cm-stat-details">
                <p><strong><?php echo $progress['attended']; ?></strong> / <?php echo $progress['total']; ?> <?php _e('sessions attended', 'courses-management'); ?></p>
            </div>
        </div>
        
        <style>
        .cm-attendance-stats { display: flex; align-items: center; gap: 20px; padding: 20px; background: #fff; border-radius: 12px; }
        .cm-stat-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: conic-gradient(var(--color) calc(var(--progress) * 1%), #e5e7eb 0);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .cm-stat-circle::before {
            content: '';
            position: absolute;
            width: 80px;
            height: 80px;
            background: #fff;
            border-radius: 50%;
        }
        .cm-stat-percent {
            position: relative;
            font-size: 20px;
            font-weight: 700;
            color: var(--color);
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Live Attendance Shortcode (for instructors)
     */
    public function shortcode_live_attendance($atts) {
        if (!current_user_can('edit_posts')) {
            return '';
        }
        
        $atts = shortcode_atts([
            'session_id' => 0,
        ], $atts);
        
        $session_id = intval($atts['session_id']);
        
        if (!$session_id) {
            return '<p>' . __('Please specify a session ID.', 'courses-management') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="cm-live-attendance" id="cm-live-<?php echo $session_id; ?>" data-session="<?php echo $session_id; ?>">
            <div class="cm-live-header">
                <h3><?php _e('Live Attendance', 'courses-management'); ?></h3>
                <span class="cm-live-indicator">● <?php _e('Live', 'courses-management'); ?></span>
            </div>
            
            <div class="cm-live-stats">
                <div class="cm-live-stat">
                    <span class="cm-live-num cm-present-count">0</span>
                    <span class="cm-live-label"><?php _e('Present', 'courses-management'); ?></span>
                </div>
                <div class="cm-live-stat">
                    <span class="cm-live-num cm-absent-count">0</span>
                    <span class="cm-live-label"><?php _e('Absent', 'courses-management'); ?></span>
                </div>
                <div class="cm-live-stat">
                    <span class="cm-live-num cm-rate">0%</span>
                    <span class="cm-live-label"><?php _e('Rate', 'courses-management'); ?></span>
                </div>
            </div>
            
            <div class="cm-live-list">
                <!-- Loaded via AJAX -->
            </div>
            
            <div class="cm-live-footer">
                <span class="cm-last-update"></span>
            </div>
        </div>
        
        <style>
        .cm-live-attendance { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .cm-live-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .cm-live-header h3 { margin: 0; }
        .cm-live-indicator { color: #0C9D61; font-weight: 600; animation: cm-pulse 2s infinite; }
        @keyframes cm-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .cm-live-stats { display: flex; gap: 20px; margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 8px; }
        .cm-live-stat { text-align: center; flex: 1; }
        .cm-live-num { display: block; font-size: 28px; font-weight: 700; }
        .cm-live-stat:first-child .cm-live-num { color: #0C9D61; }
        .cm-live-stat:nth-child(2) .cm-live-num { color: #dc3545; }
        .cm-live-label { font-size: 12px; color: #666; }
        .cm-live-list { max-height: 300px; overflow-y: auto; }
        .cm-live-student { display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid #f0f0f0; }
        .cm-live-student.present { background: #f0fdf4; }
        .cm-live-student.absent { background: #fef2f2; }
        .cm-live-avatar { width: 36px; height: 36px; border-radius: 50%; }
        .cm-live-name { flex: 1; }
        .cm-live-status { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .cm-live-status.present { background: #d1fae5; color: #065f46; }
        .cm-live-status.absent { background: #fee2e2; color: #991b1b; }
        .cm-live-footer { margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f0; font-size: 12px; color: #666; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            const $wrap = $('#cm-live-<?php echo $session_id; ?>');
            const sessionId = $wrap.data('session');
            
            function refreshData() {
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_get_live_attendance',
                        nonce: CM.nonce,
                        session_id: sessionId
                    },
                    success: function(response) {
                        if (response.success) {
                            const data = response.data;
                            
                            $wrap.find('.cm-present-count').text(data.stats.present);
                            $wrap.find('.cm-absent-count').text(data.stats.absent);
                            $wrap.find('.cm-rate').text(data.stats.rate + '%');
                            
                            let html = '';
                            data.students.forEach(function(student) {
                                const status = student.attended ? 'present' : 'absent';
                                html += `
                                    <div class="cm-live-student ${status}">
                                        <img class="cm-live-avatar" src="https://www.gravatar.com/avatar/?d=mp&s=36" alt="">
                                        <span class="cm-live-name">${student.display_name}</span>
                                        <span class="cm-live-status ${status}">${status === 'present' ? 'Present' : 'Absent'}</span>
                                    </div>
                                `;
                            });
                            
                            $wrap.find('.cm-live-list').html(html);
                            $wrap.find('.cm-last-update').text('Last updated: ' + data.timestamp);
                        }
                    }
                });
            }
            
            refreshData();
            setInterval(refreshData, 10000); // Refresh every 10 seconds
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    // ========================================
    // EXISTING METHODS (Updated)
    // ========================================
    
    /**
     * Mark Session as Attended
     */
    public function ajax_mark_attended() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Please login first', 'courses-management')]);
        }
        
        $session_id = intval($_POST['session_id'] ?? 0);
        $user_id = get_current_user_id();
        
        if (!$session_id) {
            wp_send_json_error(['message' => __('Invalid session', 'courses-management')]);
        }
        
        global $wpdb;
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cm_sessions WHERE id = %d",
            $session_id
        ));
        
        if (!$session) {
            wp_send_json_error(['message' => __('Session not found', 'courses-management')]);
        }
        
        if (!cm_is_enrolled($user_id, $session->course_id)) {
            wp_send_json_error(['message' => __('You are not enrolled in this course', 'courses-management')]);
        }
        
        // Check drip content
        if (!$this->is_session_available($session_id, $user_id)) {
            wp_send_json_error(['message' => __('This session is not available yet', 'courses-management')]);
        }
        
        $wpdb->replace($wpdb->prefix . 'cm_attendance', [
            'user_id'     => $user_id,
            'course_id'   => $session->course_id,
            'session_id'  => $session_id,
            'attended'    => 1,
            'attended_at' => current_time('mysql'),
        ]);
        
        // Check subscription rules after marking attendance
        $rules_check = $this->check_subscription_rules($user_id, $session->course_id);
        
        wp_send_json_success([
            'message'  => __('Attendance marked', 'courses-management'),
            'progress' => cm_get_progress($user_id, $session->course_id),
            'rules'    => $rules_check,
        ]);
    }
    
    /**
     * Check if Session is Available (Drip Content)
     */
    private function is_session_available($session_id, $user_id) {
        global $wpdb;
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, 
                    (SELECT drip_days FROM {$wpdb->prefix}cm_session_meta WHERE session_id = s.id) as drip_days
             FROM {$wpdb->prefix}cm_sessions s 
             WHERE s.id = %d",
            $session_id
        ));
        
        if (!$session) return false;
        
        // Check if drip is enabled
        $drip_enabled = get_post_meta($session->course_id, '_cm_drip_enabled', true);
        
        if (!$drip_enabled) return true;
        
        // Get enrollment date
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cm_user_courses WHERE user_id = %d AND course_id = %d",
            $user_id, $session->course_id
        ));
        
        if (!$enrollment) return false;
        
        $drip_days = get_post_meta($session->course_id, '_cm_session_drip_days_' . $session_id, true);
        
        if (!$drip_days) return true;
        
        $available_date = date('Y-m-d', strtotime($enrollment->enrolled_at . ' + ' . $drip_days . ' days'));
        
        return date('Y-m-d') >= $available_date;
    }
    
    /**
     * Get User Progress
     */
    public function ajax_get_progress() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        $course_id = intval($_POST['course_id'] ?? 0);
        $user_id = get_current_user_id();
        
        if (!$course_id || !$user_id) {
            wp_send_json_error(['message' => 'Invalid data']);
        }
        
        wp_send_json_success(cm_get_progress($user_id, $course_id));
    }
    
    /**
     * Get Course Sessions
     */
    public function ajax_get_sessions() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        $course_id = intval($_POST['course_id'] ?? 0);
        $user_id = get_current_user_id();
        
        if (!$course_id) {
            wp_send_json_error(['message' => 'Invalid course']);
        }
        
        global $wpdb;
        
        $sessions = $wpdb->get_results($wpdb->prepare("
            SELECT s.*, COALESCE(a.attended, 0) as attended
            FROM {$wpdb->prefix}cm_sessions s
            LEFT JOIN {$wpdb->prefix}cm_attendance a ON s.id = a.session_id AND a.user_id = %d
            WHERE s.course_id = %d
            ORDER BY s.session_order ASC
        ", $user_id, $course_id));
        
        // Add availability info
        foreach ($sessions as &$session) {
            $session->is_available = $this->is_session_available($session->id, $user_id);
            $session->is_free = get_post_meta($course_id, '_cm_session_free_' . $session->id, true);
            $session->is_preview = get_post_meta($course_id, '_cm_session_preview_' . $session->id, true);
        }
        
        wp_send_json_success([
            'sessions' => $sessions,
            'progress' => cm_get_progress($user_id, $course_id),
        ]);
    }
    
    /**
     * Process Attendance Alerts (Cron)
     */
    public function process_attendance_alerts() {
        $alerts = $this->get_attendance_alerts();
        
        if (empty($alerts)) return;
        
        $rules = get_option('cm_subscription_rules', $this->get_default_rules());
        
        foreach ($alerts as $alert) {
            // Notify instructor
            if ($rules['auto_notify_instructor']) {
                $this->notify_instructor($alert);
            }
            
            // Notify student
            if ($rules['auto_notify_student']) {
                $this->notify_student($alert);
            }
        }
    }
    
    /**
     * Notify Instructor
     */
    private function notify_instructor($alert) {
        $admin_email = get_option('admin_email');
        
        $subject = sprintf(
            __('[%s] Attendance Alert - %s', 'courses-management'),
            get_bloginfo('name'),
            $alert['course_title']
        );
        
        $message = $alert['message'];
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Notify Student
     */
    private function notify_student($alert) {
        $subject = sprintf(
            __('[%s] Attendance Notice - %s', 'courses-management'),
            get_bloginfo('name'),
            $alert['course_title']
        );
        
        $message = sprintf(
            __("Dear %s,\n\nYour attendance in \"%s\" needs attention.\n\n%s\n\nPlease make sure to attend upcoming sessions.\n\nBest regards,\n%s", 'courses-management'),
            $alert['user_name'],
            $alert['course_title'],
            $alert['message'],
            get_bloginfo('name')
        );
        
        wp_mail($alert['user_email'], $subject, $message);
    }
}