<?php
/**
 * Meta Boxes
 */

if (!defined('ABSPATH')) {
    exit;
}

class CM_Meta_Boxes {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_cm_course', [$this, 'save_meta']);
        
        // AJAX handlers
        add_action('wp_ajax_cm_save_sessions', [$this, 'ajax_save_sessions']);
        add_action('wp_ajax_cm_delete_session', [$this, 'ajax_delete_session']);
    }
    
    /**
     * Add Meta Boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'cm_course_settings',
            __('Course Settings', 'courses-management'),
            [$this, 'render_settings_metabox'],
            'cm_course',
            'normal',
            'high'
        );
        
        add_meta_box(
            'cm_course_sessions',
            __('Sessions', 'courses-management'),
            [$this, 'render_sessions_metabox'],
            'cm_course',
            'normal',
            'high'
        );
    }
    
    /**
     * Settings Metabox
     */
    public function render_settings_metabox($post) {
        wp_nonce_field('cm_save_course', 'cm_course_nonce');
        
        $age_from = get_post_meta($post->ID, '_cm_age_from', true);
        $age_to = get_post_meta($post->ID, '_cm_age_to', true);
        $duration = get_post_meta($post->ID, '_cm_duration', true);
        $price = get_post_meta($post->ID, '_cm_price', true);
        ?>
        <style>
            .cm-metabox { padding: 10px 0; }
            .cm-field-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; }
            .cm-field label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; }
            .cm-field input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; }
        </style>
        <div class="cm-metabox">
            <div class="cm-field-grid">
                <div class="cm-field">
                    <label for="cm_age_from"><?php _e('Age From', 'courses-management'); ?></label>
                    <input type="number" id="cm_age_from" name="cm_age_from" value="<?php echo esc_attr($age_from); ?>" min="1" max="100">
                </div>
                <div class="cm-field">
                    <label for="cm_age_to"><?php _e('Age To', 'courses-management'); ?></label>
                    <input type="number" id="cm_age_to" name="cm_age_to" value="<?php echo esc_attr($age_to); ?>" min="1" max="100">
                </div>
                <div class="cm-field">
                    <label for="cm_duration"><?php _e('Duration', 'courses-management'); ?></label>
                    <input type="text" id="cm_duration" name="cm_duration" value="<?php echo esc_attr($duration); ?>" placeholder="e.g. 8 weeks">
                </div>
                <div class="cm-field">
                    <label for="cm_price"><?php _e('Price', 'courses-management'); ?></label>
                    <input type="text" id="cm_price" name="cm_price" value="<?php echo esc_attr($price); ?>" placeholder="e.g. 500 EGP">
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Sessions Metabox
     */
    public function render_sessions_metabox($post) {
        $sessions = cm_get_sessions($post->ID);
        ?>
        <style>
            .cm-sessions-wrapper { padding: 10px 0; }
            .cm-sessions-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
            .cm-sessions-count { color: #666; }
            .cm-sessions-list { margin-bottom: 20px; }
            .cm-session-item { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 10px; margin-bottom: 12px; overflow: hidden; }
            .cm-session-header { display: flex; align-items: center; gap: 12px; padding: 12px 15px; background: #fff; cursor: pointer; }
            .cm-session-drag { cursor: grab; color: #999; }
            .cm-session-number { background: #667eea; color: #fff; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; }
            .cm-session-title-input { flex: 1; border: none !important; background: transparent !important; font-size: 14px !important; font-weight: 500; padding: 8px 0 !important; }
            .cm-session-title-input:focus { background: #f0f0f0 !important; border-radius: 4px; padding: 8px 10px !important; outline: none; }
            .cm-session-toggle { color: #999; cursor: pointer; transition: transform 0.2s; }
            .cm-session-item.collapsed .cm-session-toggle { transform: rotate(-90deg); }
            .cm-session-delete { background: none; border: none; color: #999; cursor: pointer; padding: 5px; border-radius: 4px; }
            .cm-session-delete:hover { background: #dc3545; color: #fff; }
            .cm-session-body { padding: 15px 20px 20px; border-top: 1px solid #eee; }
            .cm-session-item.collapsed .cm-session-body { display: none; }
            .cm-session-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
            .cm-session-fields .cm-field-wide { grid-column: span 2; }
            .cm-session-fields .cm-field-full { grid-column: 1 / -1; }
            .cm-session-fields label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 12px; color: #666; }
            .cm-session-fields input, .cm-session-fields textarea { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
            .cm-sessions-footer { display: flex; align-items: center; gap: 15px; padding-top: 15px; border-top: 1px solid #eee; }
            .cm-save-status { font-size: 13px; color: #666; }
            .cm-save-status.saving { color: #2271b1; }
            .cm-save-status.saved { color: #00a32a; }
            .cm-save-status.error { color: #dc3545; }
            .cm-session-placeholder { background: #e0e0e0; border: 2px dashed #ccc; border-radius: 10px; margin-bottom: 12px; height: 60px; }
        </style>
        
        <div class="cm-sessions-wrapper" id="cm-sessions-app" data-course-id="<?php echo $post->ID; ?>">
            
            <div class="cm-sessions-header">
                <span class="cm-sessions-count">
                    <?php printf(__('%d Sessions', 'courses-management'), count($sessions)); ?>
                </span>
                <div>
                    <button type="button" id="cm-expand-all" class="button button-small"><?php _e('Expand All', 'courses-management'); ?></button>
                    <button type="button" id="cm-collapse-all" class="button button-small"><?php _e('Collapse All', 'courses-management'); ?></button>
                </div>
            </div>
            
            <div id="cm-sessions-list" class="cm-sessions-list">
                <?php 
                if ($sessions) {
                    foreach ($sessions as $i => $session) {
                        $this->render_session_item($session, $i);
                    }
                }
                ?>
            </div>
            
            <div class="cm-sessions-footer">
                <button type="button" id="cm-add-session" class="button button-primary">
                    + <?php _e('Add Session', 'courses-management'); ?>
                </button>
                <span id="cm-save-status" class="cm-save-status"></span>
            </div>
            
            <!-- Session Template -->
            <script type="text/html" id="cm-session-template">
                <?php $this->render_session_item(null, '{{INDEX}}', true); ?>
            </script>
        </div>
        <?php
    }
    
    /**
     * Render Single Session Item
     */
    private function render_session_item($session, $index, $is_template = false) {
        $id = $session ? $session->id : 'new_{{INDEX}}';
        $title = $session ? esc_attr($session->session_title) : '';
        $date = $session ? esc_attr($session->session_date) : '';
        $time = $session ? esc_attr($session->session_time) : '';
        $link = $session ? esc_attr($session->session_link) : '';
        $desc = $session ? esc_textarea($session->session_description) : '';
        $num = is_numeric($index) ? $index + 1 : $index;
        ?>
        <div class="cm-session-item" data-id="<?php echo $id; ?>">
            <div class="cm-session-header">
                <span class="cm-session-drag" title="<?php _e('Drag to reorder', 'courses-management'); ?>">☰</span>
                <span class="cm-session-number"><?php echo $num; ?></span>
                <input type="text" class="cm-session-title-input" name="sessions[<?php echo $id; ?>][title]" value="<?php echo $title; ?>" placeholder="<?php _e('Session Title', 'courses-management'); ?>">
                <span class="cm-session-toggle dashicons dashicons-arrow-down-alt2"></span>
                <button type="button" class="cm-session-delete" title="<?php _e('Delete', 'courses-management'); ?>">✕</button>
            </div>
            <div class="cm-session-body">
                <div class="cm-session-fields">
                    <div class="cm-field">
                        <label><?php _e('Date', 'courses-management'); ?></label>
                        <input type="date" name="sessions[<?php echo $id; ?>][date]" value="<?php echo $date; ?>">
                    </div>
                    <div class="cm-field">
                        <label><?php _e('Time', 'courses-management'); ?></label>
                        <input type="time" name="sessions[<?php echo $id; ?>][time]" value="<?php echo $time; ?>">
                    </div>
                    <div class="cm-field cm-field-wide">
                        <label><?php _e('Meeting Link', 'courses-management'); ?></label>
                        <input type="url" name="sessions[<?php echo $id; ?>][link]" value="<?php echo $link; ?>" placeholder="https://zoom.us/...">
                    </div>
                    <div class="cm-field cm-field-full">
                        <label><?php _e('Description', 'courses-management'); ?></label>
                        <textarea name="sessions[<?php echo $id; ?>][description]" rows="2"><?php echo $desc; ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Save Course Meta
     */
    public function save_meta($post_id) {
        if (!isset($_POST['cm_course_nonce']) || !wp_verify_nonce($_POST['cm_course_nonce'], 'cm_save_course')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save course settings
        $fields = ['age_from', 'age_to', 'duration', 'price'];
        foreach ($fields as $field) {
            if (isset($_POST["cm_{$field}"])) {
                update_post_meta($post_id, "_cm_{$field}", sanitize_text_field($_POST["cm_{$field}"]));
            }
        }
        
        // Save sessions
        if (isset($_POST['sessions']) && is_array($_POST['sessions'])) {
            $this->save_sessions($post_id, $_POST['sessions']);
        }
    }
    
    /**
     * Save Sessions
     */
    private function save_sessions($course_id, $sessions_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'cm_sessions';
        
        // Get existing IDs
        $existing_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE course_id = %d",
            $course_id
        ));
        
        $updated_ids = [];
        $order = 0;
        
        foreach ($sessions_data as $id => $session) {
            $data = [
                'course_id'           => $course_id,
                'session_title'       => sanitize_text_field($session['title'] ?? ''),
                'session_description' => sanitize_textarea_field($session['description'] ?? ''),
                'session_date'        => !empty($session['date']) ? sanitize_text_field($session['date']) : null,
                'session_time'        => !empty($session['time']) ? sanitize_text_field($session['time']) : null,
                'session_link'        => esc_url_raw($session['link'] ?? ''),
                'session_order'       => $order,
            ];
            
            if (strpos($id, 'new_') === 0) {
                $wpdb->insert($table, $data);
                $updated_ids[] = $wpdb->insert_id;
            } else {
                $wpdb->update($table, $data, ['id' => intval($id)]);
                $updated_ids[] = intval($id);
            }
            
            $order++;
        }
        
        // Delete removed sessions
        $to_delete = array_diff($existing_ids, $updated_ids);
        if (!empty($to_delete)) {
            $ids_string = implode(',', array_map('intval', $to_delete));
            $wpdb->query("DELETE FROM $table WHERE id IN ($ids_string)");
            $wpdb->query("DELETE FROM {$wpdb->prefix}cm_attendance WHERE session_id IN ($ids_string)");
        }
    }
    
    /**
     * AJAX: Save Sessions
     */
    public function ajax_save_sessions() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'courses-management')]);
        }
        
        $course_id = intval($_POST['course_id'] ?? 0);
        $sessions = isset($_POST['sessions']) ? $_POST['sessions'] : [];
        
        if (!$course_id) {
            wp_send_json_error(['message' => __('Invalid course ID', 'courses-management')]);
        }
        
        $this->save_sessions($course_id, $sessions);
        
        $updated_sessions = cm_get_sessions($course_id);
        
        wp_send_json_success([
            'message'  => __('Sessions saved', 'courses-management'),
            'sessions' => $updated_sessions,
            'count'    => count($updated_sessions),
        ]);
    }
    
    /**
     * AJAX: Delete Session
     */
    public function ajax_delete_session() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'courses-management')]);
        }
        
        $session_id = intval($_POST['session_id'] ?? 0);
        
        if (!$session_id) {
            wp_send_json_error(['message' => __('Invalid session ID', 'courses-management')]);
        }
        
        global $wpdb;
        
        $wpdb->delete($wpdb->prefix . 'cm_sessions', ['id' => $session_id]);
        $wpdb->delete($wpdb->prefix . 'cm_attendance', ['session_id' => $session_id]);
        
        wp_send_json_success(['message' => __('Session deleted', 'courses-management')]);
    }
}