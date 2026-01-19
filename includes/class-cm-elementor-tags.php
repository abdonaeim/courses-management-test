<?php
/**
 * Elementor Dynamic Tags
 */

if (!defined('ABSPATH')) {
    exit;
}

// Base Tag Class
abstract class CM_Elementor_Tag_Base extends \Elementor\Core\DynamicTags\Tag {
    
    public function get_group() {
        return 'cm-course-fields';
    }
    
    protected function get_course_id() {
        $post_id = get_the_ID();
        
        if (get_post_type($post_id) === 'cm_course') {
            return $post_id;
        }
        
        // Try settings
        $settings = $this->get_settings();
        if (!empty($settings['course_id'])) {
            return $settings['course_id'];
        }
        
        return null;
    }
    
    protected function register_controls() {
        $this->add_control(
            'course_id',
            [
                'label'   => __('Course ID (optional)', 'courses-management'),
                'type'    => \Elementor\Controls_Manager::NUMBER,
                'default' => '',
            ]
        );
    }
}

// ========================================
// COURSE TAGS
// ========================================

class CM_Elementor_Tag_Course_Title extends CM_Elementor_Tag_Base {
    
    public function get_name() {
        return 'cm-course-title';
    }
    
    public function get_title() {
        return __('Course Title', 'courses-management');
    }
    
    public function get_categories() {
        return ['text'];
    }
    
    public function render() {
        $course_id = $this->get_course_id();
        echo $course_id ? esc_html(get_the_title($course_id)) : '';
    }
}

class CM_Elementor_Tag_Course_Price extends CM_Elementor_Tag_Base {
    
    public function get_name() {
        return 'cm-course-price';
    }
    
    public function get_title() {
        return __('Course Price', 'courses-management');
    }
    
    public function get_categories() {
        return ['text', 'number'];
    }
    
    public function render() {
        $course_id = $this->get_course_id();
        echo $course_id ? esc_html(get_post_meta($course_id, '_cm_price', true)) : '';
    }
}

class CM_Elementor_Tag_Course_Duration extends CM_Elementor_Tag_Base {
    
    public function get_name() {
        return 'cm-course-duration';
    }
    
    public function get_title() {
        return __('Course Duration', 'courses-management');
    }
    
    public function get_categories() {
        return ['text'];
    }
    
    public function render() {
        $course_id = $this->get_course_id();
        echo $course_id ? esc_html(get_post_meta($course_id, '_cm_duration', true)) : '';
    }
}

class CM_Elementor_Tag_Course_Age_Range extends CM_Elementor_Tag_Base {
    
    public function get_name() {
        return 'cm-course-age-range';
    }
    
    public function get_title() {
        return __('Age Range', 'courses-management');
    }
    
    public function get_categories() {
        return ['text'];
    }
    
    public function render() {
        $course_id = $this->get_course_id();
        if (!$course_id) return;
        
        $from = get_post_meta($course_id, '_cm_age_from', true);
        $to = get_post_meta($course_id, '_cm_age_to', true);
        
        if ($from && $to) {
            echo esc_html($from . ' - ' . $to);
        } elseif ($from) {
            echo esc_html($from . '+');
        } elseif ($to) {
            echo esc_html(__('Up to', 'courses-management') . ' ' . $to);
        }
    }
}

class CM_Elementor_Tag_Course_Sessions_Count extends CM_Elementor_Tag_Base {
    
    public function get_name() {
        return 'cm-course-sessions-count';
    }
    
    public function get_title() {
        return __('Sessions Count', 'courses-management');
    }
    
    public function get_categories() {
        return ['text', 'number'];
    }
    
    public function render() {
        $course_id = $this->get_course_id();
        echo $course_id ? count(cm_get_sessions($course_id)) : 0;
    }
}

class CM_Elementor_Tag_Course_Students_Count extends CM_Elementor_Tag_Base {
    
    public function get_name() {
        return 'cm-course-students-count';
    }
    
    public function get_title() {
        return __('Students Count', 'courses-management');
    }
    
    public function get_categories() {
        return ['text', 'number'];
    }
    
    public function render() {
        $course_id = $this->get_course_id();
        if (!$course_id) {
            echo 0;
            return;
        }
        
        global $wpdb;
        echo (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cm_user_courses WHERE course_id = %d AND status = 'active'",
            $course_id
        ));
    }
}

class CM_Elementor_Tag_Course_Thumbnail extends \Elementor\Core\DynamicTags\Data_Tag {
    
    public function get_name() {
        return 'cm-course-thumbnail';
    }
    
    public function get_title() {
        return __('Course Thumbnail', 'courses-management');
    }
    
    public function get_group() {
        return 'cm-course-fields';
    }
    
    public function get_categories() {
        return ['image'];
    }
    
    public function get_value(array $options = []) {
        $course_id = get_the_ID();
        
        $settings = $this->get_settings();
        if (!empty($settings['course_id'])) {
            $course_id = $settings['course_id'];
        }
        
        $thumb_id = get_post_thumbnail_id($course_id);
        
        if (!$thumb_id) {
            return [];
        }
        
        return [
            'id'  => $thumb_id,
            'url' => wp_get_attachment_image_src($thumb_id, 'full')[0],
        ];
    }
    
    protected function register_controls() {
        $this->add_control(
            'course_id',
            [
                'label'   => __('Course ID (optional)', 'courses-management'),
                'type'    => \Elementor\Controls_Manager::NUMBER,
                'default' => '',
            ]
        );
    }
}

class CM_Elementor_Tag_Course_URL extends \Elementor\Core\DynamicTags\Data_Tag {
    
    public function get_name() {
        return 'cm-course-url';
    }
    
    public function get_title() {
        return __('Course URL', 'courses-management');
    }
    
    public function get_group() {
        return 'cm-course-fields';
    }
    
    public function get_categories() {
        return ['url'];
    }
    
    public function get_value(array $options = []) {
        $course_id = get_the_ID();
        
        $settings = $this->get_settings();
        if (!empty($settings['course_id'])) {
            $course_id = $settings['course_id'];
        }
        
        return get_permalink($course_id);
    }
    
    protected function register_controls() {
        $this->add_control(
            'course_id',
            [
                'label'   => __('Course ID (optional)', 'courses-management'),
                'type'    => \Elementor\Controls_Manager::NUMBER,
                'default' => '',
            ]
        );
    }
}

// ========================================
// USER/PROGRESS TAGS
// ========================================

class CM_Elementor_Tag_User_Progress extends CM_Elementor_Tag_Base {
    
    public function get_name() {
        return 'cm-user-progress';
    }
    
    public function get_title() {
        return __('User Progress', 'courses-management');
    }
    
    public function get_categories() {
        return ['text', 'number'];
    }
    
    protected function register_controls() {
        parent::register_controls();
        
        $this->add_control(
            'format',
            [
                'label'   => __('Format', 'courses-management'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'percent',
                'options' => [
                    'percent'  => __('Percentage (75%)', 'courses-management'),
                    'fraction' => __('Fraction (3/4)', 'courses-management'),
                    'attended' => __('Attended Only (3)', 'courses-management'),
                    'total'    => __('Total Only (4)', 'courses-management'),
                ],
            ]
        );
    }
    
    public function render() {
        $course_id = $this->get_course_id();
        $user_id = get_current_user_id();
        
        if (!$course_id || !$user_id) {
            echo '0';
            return;
        }
        
        $progress = cm_get_progress($user_id, $course_id);
        $settings = $this->get_settings();
        $format = $settings['format'] ?? 'percent';
        
        switch ($format) {
            case 'fraction':
                echo esc_html($progress['attended'] . '/' . $progress['total']);
                break;
            case 'attended':
                echo esc_html($progress['attended']);
                break;
            case 'total':
                echo esc_html($progress['total']);
                break;
            default:
                echo esc_html($progress['percent'] . '%');
        }
    }
}

class CM_Elementor_Tag_User_Is_Enrolled extends CM_Elementor_Tag_Base {
    
    public function get_name() {
        return 'cm-user-is-enrolled';
    }
    
    public function get_title() {
        return __('Is User Enrolled', 'courses-management');
    }
    
    public function get_categories() {
        return ['text'];
    }
    
    protected function register_controls() {
        parent::register_controls();
        
        $this->add_control(
            'yes_text',
            [
                'label'   => __('Yes Text', 'courses-management'),
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => __('Enrolled', 'courses-management'),
            ]
        );
        
        $this->add_control(
            'no_text',
            [
                'label'   => __('No Text', 'courses-management'),
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => __('Not Enrolled', 'courses-management'),
            ]
        );
    }
    
    public function render() {
        $course_id = $this->get_course_id();
        $user_id = get_current_user_id();
        
        $settings = $this->get_settings();
        
        if (!$user_id) {
            echo esc_html($settings['no_text']);
            return;
        }
        
        $is_enrolled = $course_id && cm_is_enrolled($user_id, $course_id);
        
        echo esc_html($is_enrolled ? $settings['yes_text'] : $settings['no_text']);
    }
}