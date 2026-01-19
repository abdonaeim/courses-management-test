<?php
/**
 * Shortcodes
 */

if (!defined('ABSPATH')) {
    exit;
}

class CM_Shortcodes {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_shortcode('cm_courses', [$this, 'courses_list']);
        add_shortcode('cm_course', [$this, 'course_field']);
        add_shortcode('cm_sessions', [$this, 'sessions_list']);
        add_shortcode('cm_progress', [$this, 'progress_bar']);
        add_shortcode('cm_user_courses', [$this, 'user_courses']);
    }
    
    /**
     * [cm_courses] - Display courses list
     */
    public function courses_list($atts) {
        $atts = shortcode_atts([
            'limit'   => -1,
            'columns' => 3,
        ], $atts);
        
        $courses = get_posts([
            'post_type'      => 'cm_course',
            'posts_per_page' => intval($atts['limit']),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        
        if (empty($courses)) {
            return '<p>No courses found.</p>';
        }
        
        $output = '<div class="cm-courses-grid" style="display:grid;grid-template-columns:repeat(' . intval($atts['columns']) . ',1fr);gap:20px;">';
        
        foreach ($courses as $course) {
            $output .= '<div class="cm-course-card" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
            
            if (has_post_thumbnail($course->ID)) {
                $output .= '<div class="cm-course-image">' . get_the_post_thumbnail($course->ID, 'medium', ['style' => 'width:100%;height:auto;']) . '</div>';
            }
            
            $output .= '<div style="padding:20px;">';
            $output .= '<h3 style="margin:0 0 10px;font-size:18px;"><a href="' . get_permalink($course->ID) . '" style="text-decoration:none;color:#1e1e1e;">' . esc_html($course->post_title) . '</a></h3>';
            
            if ($course->post_excerpt) {
                $output .= '<p style="color:#666;margin:0 0 15px;font-size:14px;">' . wp_trim_words($course->post_excerpt, 15) . '</p>';
            }
            
            $output .= '<a href="' . get_permalink($course->ID) . '" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-weight:500;">View Course</a>';
            $output .= '</div></div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * [cm_course field="title"] - Display course field
     */
    public function course_field($atts) {
        $atts = shortcode_atts(['id' => 0, 'field' => ''], $atts);
        
        $course_id = $atts['id'] ?: get_the_ID();
        if (!$course_id || !$atts['field']) return '';
        
        switch ($atts['field']) {
            case 'title':
                return get_the_title($course_id);
            case 'sessions_count':
                return count(cm_get_sessions($course_id));
            case 'age_from':
                return get_post_meta($course_id, '_cm_age_from', true);
            case 'age_to':
                return get_post_meta($course_id, '_cm_age_to', true);
            case 'age_range':
                $from = get_post_meta($course_id, '_cm_age_from', true);
                $to = get_post_meta($course_id, '_cm_age_to', true);
                if ($from && $to) return $from . ' - ' . $to;
                return $from ?: $to;
            case 'price':
                return get_post_meta($course_id, '_cm_price', true);
            case 'duration':
                return get_post_meta($course_id, '_cm_duration', true);
            default:
                return get_post_meta($course_id, "_cm_{$atts['field']}", true);
        }
    }
    
    /**
     * [cm_sessions] - Display sessions list
     */
    public function sessions_list($atts) {
        $atts = shortcode_atts(['course_id' => 0, 'dynamic' => 'no'], $atts);
        
        $course_id = $atts['course_id'] ?: get_the_ID();
        if (!$course_id) return '';
        
        // Dynamic loading via AJAX
        if ($atts['dynamic'] === 'yes') {
            return '<div class="cm-sessions-dynamic" data-course-id="' . esc_attr($course_id) . '"><div style="padding:40px;text-align:center;color:#666;">Loading...</div></div>';
        }
        
        $sessions = cm_get_sessions($course_id);
        if (empty($sessions)) return '<p>No sessions found.</p>';
        
        $user_id = get_current_user_id();
        $is_enrolled = $user_id && cm_is_enrolled($user_id, $course_id);
        
        $output = '<div class="cm-sessions-wrap" data-course-id="' . esc_attr($course_id) . '">';
        
        // Progress bar
        if ($user_id && $is_enrolled) {
            $progress = cm_get_progress($user_id, $course_id);
            $color = $progress['percent'] == 100 ? '#0C9D61' : '#F1840D';
            
            $output .= '<div class="cm-progress-wrap" style="margin-bottom:25px;padding:20px;background:#f9f9f9;border-radius:10px;">';
            $output .= '<div style="display:flex;justify-content:space-between;margin-bottom:12px;">';
            $output .= '<span style="font-weight:600;font-size:14px;">Your Progress</span>';
            $output .= '<span class="cm-progress-count" style="font-weight:600;color:' . $color . ';">' . $progress['attended'] . '/' . $progress['total'] . '</span>';
            $output .= '</div>';
            $output .= '<div class="cm-progress-bar" style="height:10px;background:#e5e7eb;border-radius:10px;overflow:hidden;">';
            $output .= '<div class="cm-progress-fill" style="height:100%;width:' . $progress['percent'] . '%;background:' . $color . ';border-radius:10px;transition:width 0.5s;"></div>';
            $output .= '</div></div>';
        }
        
        $output .= '<div class="cm-sessions-list">';
        
        foreach ($sessions as $i => $session) {
            $attended = $user_id && cm_is_attended($user_id, $session->id);
            $bg = $attended ? '#f0fdf4' : '#f9fafb';
            $border = $attended ? '#bbf7d0' : '#e5e7eb';
            $num_bg = $attended ? '#0C9D61' : '#2563eb';
            
            $output .= '<div class="cm-session' . ($attended ? ' cm-session-attended' : '') . '" data-session-id="' . $session->id . '" style="display:flex;align-items:center;gap:15px;padding:18px 20px;background:' . $bg . ';border:1px solid ' . $border . ';border-radius:10px;margin-bottom:12px;">';
            
            $output .= '<div class="cm-session-num" style="width:36px;height:36px;border-radius:50%;background:' . $num_bg . ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;flex-shrink:0;">' . ($i + 1) . '</div>';
            
            $output .= '<div class="cm-session-info" style="flex:1;">';
            $output .= '<div class="cm-session-title" style="font-weight:600;color:#1e1e1e;">' . esc_html($session->session_title ?: 'Session ' . ($i + 1)) . '</div>';
            if ($session->session_date) {
                $date_display = date_i18n(get_option('date_format'), strtotime($session->session_date));
                if ($session->session_time) {
                    $date_display .= ' - ' . date_i18n(get_option('time_format'), strtotime($session->session_time));
                }
                $output .= '<div class="cm-session-date" style="font-size:13px;color:#666;margin-top:4px;">' . esc_html($date_display) . '</div>';
            }
            $output .= '</div>';
            
            $output .= '<div class="cm-session-action">';
            if ($attended) {
                $output .= '<span class="cm-badge cm-badge-success" style="display:inline-flex;align-items:center;gap:5px;padding:8px 14px;background:#d1fae5;color:#065f46;border-radius:20px;font-size:13px;font-weight:500;">✓ تم الحضور</span>';
            } elseif ($is_enrolled && $session->session_link) {
                $output .= '<a href="' . esc_url($session->session_link) . '" class="cm-btn cm-mark-attended" data-session-id="' . $session->id . '" target="_blank" style="display:inline-block;padding:10px 20px;background:#F1840D;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;transition:background 0.2s;">انضم الان</a>';
            } elseif (!$user_id) {
                $output .= '<a href="' . wp_login_url(get_permalink()) . '" style="display:inline-block;padding:10px 20px;background:#e5e7eb;color:#666;border-radius:8px;text-decoration:none;font-weight:500;">Login to Join</a>';
            }
            $output .= '</div>';
            
            $output .= '</div>';
        }
        
        $output .= '</div></div>';
        
        return $output;
    }
    
    /**
     * [cm_progress] - Display progress bar
     */
    public function progress_bar($atts) {
        if (!is_user_logged_in()) return '';
        
        $atts = shortcode_atts(['course_id' => 0, 'style' => 'bar'], $atts);
        
        $course_id = $atts['course_id'] ?: get_the_ID();
        if (!$course_id) return '';
        
        $progress = cm_get_progress(get_current_user_id(), $course_id);
        if ($progress['total'] == 0) return '';
        
        $color = $progress['percent'] == 100 ? '#0C9D61' : '#F1840D';
        
        if ($atts['style'] === 'text') {
            return '<span class="cm-progress-text" style="color:' . $color . ';font-weight:600;">' . $progress['attended'] . '/' . $progress['total'] . ' (' . $progress['percent'] . '%)</span>';
        }
        
        if ($atts['style'] === 'circle') {
            return '<div class="cm-progress-circle" style="position:relative;width:80px;height:80px;">
                <svg viewBox="0 0 36 36" style="transform:rotate(-90deg);">
                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e5e7eb" stroke-width="3"/>
                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="' . $color . '" stroke-width="3" stroke-dasharray="' . $progress['percent'] . ', 100" stroke-linecap="round"/>
                </svg>
                <span style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:16px;font-weight:700;">' . $progress['percent'] . '%</span>
            </div>';
        }
        
        // Default: bar
        $output = '<div class="cm-progress-wrap" style="padding:15px;background:#f9f9f9;border-radius:10px;">';
        $output .= '<div style="display:flex;justify-content:space-between;margin-bottom:10px;">';
        $output .= '<span style="font-weight:600;font-size:14px;">Progress</span>';
        $output .= '<span class="cm-progress-count" style="font-weight:600;color:' . $color . ';">' . $progress['attended'] . '/' . $progress['total'] . '</span>';
        $output .= '</div>';
        $output .= '<div class="cm-progress-bar" style="height:10px;background:#e5e7eb;border-radius:10px;overflow:hidden;">';
        $output .= '<div class="cm-progress-fill" style="height:100%;width:' . $progress['percent'] . '%;background:' . $color . ';border-radius:10px;transition:width 0.5s;"></div>';
        $output .= '</div></div>';
        
        return $output;
    }
    
    /**
     * [cm_user_courses] - Display user's enrolled courses
     */
    public function user_courses($atts) {
        if (!is_user_logged_in()) {
            return '<p style="padding:20px;background:#f9f9f9;border-radius:10px;text-align:center;">Please login to view your courses.</p>';
        }
        
        $atts = shortcode_atts(['columns' => 1], $atts);
        
        $courses = cm_get_user_courses();
        
        if (empty($courses)) {
            return '<p style="padding:20px;background:#f9f9f9;border-radius:10px;text-align:center;">You are not enrolled in any courses.</p>';
        }
        
        $user_id = get_current_user_id();
        $cols = intval($atts['columns']);
        
        $output = '<div class="cm-user-courses" style="display:grid;grid-template-columns:repeat(' . $cols . ',1fr);gap:20px;">';
        
        foreach ($courses as $course) {
            $progress = cm_get_progress($user_id, $course->ID);
            $color = $progress['percent'] == 100 ? '#0C9D61' : '#F1840D';
            
            $output .= '<div class="cm-user-course-card" data-course-id="' . $course->ID . '" style="display:flex;align-items:center;gap:20px;padding:20px;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
            
            if (has_post_thumbnail($course->ID)) {
                $output .= '<div class="cm-course-thumb" style="width:80px;height:80px;border-radius:10px;overflow:hidden;flex-shrink:0;">';
                $output .= get_the_post_thumbnail($course->ID, 'thumbnail', ['style' => 'width:100%;height:100%;object-fit:cover;']);
                $output .= '</div>';
            }
            
            $output .= '<div class="cm-course-info" style="flex:1;min-width:0;">';
            $output .= '<h4 style="margin:0 0 8px;font-size:16px;"><a href="' . get_permalink($course->ID) . '" style="text-decoration:none;color:#1e1e1e;">' . esc_html($course->post_title) . '</a></h4>';
            
            // Mini progress bar
            $output .= '<div class="cm-mini-progress" style="display:flex;align-items:center;gap:10px;">';
            $output .= '<div class="cm-mini-bar" style="flex:1;height:6px;background:#e5e7eb;border-radius:6px;overflow:hidden;">';
            $output .= '<div class="cm-mini-fill" style="height:100%;width:' . $progress['percent'] . '%;background:' . $color . ';border-radius:6px;"></div>';
            $output .= '</div>';
            $output .= '<span style="font-size:13px;font-weight:600;color:' . $color . ';">' . $progress['percent'] . '%</span>';
            $output .= '</div>';
            
            $output .= '<div class="cm-course-stats" style="font-size:13px;color:#666;margin-top:5px;">' . $progress['attended'] . '/' . $progress['total'] . ' sessions</div>';
            $output .= '</div>';
            
            $output .= '<a href="' . get_permalink($course->ID) . '" style="padding:10px 16px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;font-weight:500;flex-shrink:0;">Continue</a>';
            
            $output .= '</div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
}