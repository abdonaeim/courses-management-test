<?php
/**
 * Admin Pages with Advanced Reports
 */

if (!defined('ABSPATH')) {
    exit;
}

class CM_Admin_Pages {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_menus']);
        
        // AJAX handlers
        add_action('wp_ajax_cm_get_user_courses_admin', [$this, 'ajax_get_user_courses']);
        add_action('wp_ajax_cm_save_enrollments', [$this, 'ajax_save_enrollments']);
        add_action('wp_ajax_cm_get_course_students', [$this, 'ajax_get_course_students']);
        add_action('wp_ajax_cm_get_attendance_data', [$this, 'ajax_get_attendance_data']);
        add_action('wp_ajax_cm_save_attendance_admin', [$this, 'ajax_save_attendance']);
        add_action('wp_ajax_cm_get_dashboard_stats', [$this, 'ajax_get_dashboard_stats']);
        add_action('wp_ajax_cm_export_report', [$this, 'ajax_export_report']);
    }
    
    /**
     * Add Admin Menus
     */
    public function add_menus() {
        add_menu_page(
            __('Courses', 'courses-management'),
            __('Courses', 'courses-management'),
            'manage_options',
            'cm-dashboard',
            [$this, 'page_dashboard'],
            'dashicons-welcome-learn-more',
            30
        );
        
        add_submenu_page('cm-dashboard', __('Dashboard', 'courses-management'), __('Dashboard', 'courses-management'), 'manage_options', 'cm-dashboard');
        add_submenu_page('cm-dashboard', __('All Courses', 'courses-management'), __('All Courses', 'courses-management'), 'manage_options', 'edit.php?post_type=cm_course');
        add_submenu_page('cm-dashboard', __('Add course', 'courses-management'), __('Add course', 'courses-management'), 'manage_options', 'post-new.php?post_type=cm_course');
        add_submenu_page('cm-dashboard', __('Categories', 'courses-management'), __('Categories', 'courses-management'), 'manage_options', 'edit-tags.php?taxonomy=cm_category&post_type=cm_course');
        add_submenu_page('cm-dashboard', __('Enrollments', 'courses-management'), __('Enrollments', 'courses-management'), 'manage_options', 'cm-enrollments', [$this, 'page_enrollments']);
        add_submenu_page('cm-dashboard', __('Attendance', 'courses-management'), __('Attendance', 'courses-management'), 'manage_options', 'cm-attendance', [$this, 'page_attendance']);
        add_submenu_page('cm-dashboard', __('Students', 'courses-management'), __('Students', 'courses-management'), 'manage_options', 'cm-students', [$this, 'page_students']);
    }
    
    /**
     * Get Dashboard Statistics
     */
    private function get_dashboard_stats() {
        global $wpdb;
        
        $stats = [];
        
        // Basic counts
        $stats['courses_count'] = wp_count_posts('cm_course')->publish;
        $stats['sessions_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cm_sessions");
        $stats['enrollments_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cm_user_courses WHERE status = 'active'");
        $stats['students_count'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}cm_user_courses");
        
        // Attendance stats
        $stats['total_attendance'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cm_attendance WHERE attended = 1");
        $stats['attendance_rate'] = 0;
        
        $total_possible = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}cm_user_courses uc
            INNER JOIN {$wpdb->prefix}cm_sessions s ON uc.course_id = s.course_id
            WHERE uc.status = 'active'
        ");
        
        if ($total_possible > 0) {
            $stats['attendance_rate'] = round(($stats['total_attendance'] / $total_possible) * 100);
        }
        
        // Enrollments by month (last 6 months)
        $stats['enrollments_chart'] = $wpdb->get_results("
            SELECT DATE_FORMAT(enrolled_at, '%Y-%m') as month, COUNT(*) as count
            FROM {$wpdb->prefix}cm_user_courses
            WHERE enrolled_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY month
            ORDER BY month ASC
        ");
        
        // Attendance by month (last 6 months)
        $stats['attendance_chart'] = $wpdb->get_results("
            SELECT DATE_FORMAT(attended_at, '%Y-%m') as month, COUNT(*) as count
            FROM {$wpdb->prefix}cm_attendance
            WHERE attended = 1 AND attended_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY month
            ORDER BY month ASC
        ");
        

                // Top students by attendance
        $stats['top_students_attendance'] = $wpdb->get_results("
            SELECT u.ID, u.display_name, u.user_email, COUNT(a.id) as attendance_count,
                   (SELECT COUNT(*) FROM {$wpdb->prefix}cm_user_courses WHERE user_id = u.ID AND status = 'active') as courses_count
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}cm_attendance a ON u.ID = a.user_id
            WHERE a.attended = 1
            GROUP BY u.ID
            ORDER BY attendance_count DESC
            LIMIT 10
        ");
        
        // Top students by enrollments
        $stats['top_students_enrollments'] = $wpdb->get_results("
            SELECT u.ID, u.display_name, u.user_email, COUNT(uc.id) as courses_count
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}cm_user_courses uc ON u.ID = uc.user_id
            WHERE uc.status = 'active'
            GROUP BY u.ID
            ORDER BY courses_count DESC
            LIMIT 10
        ");
        
        // Popular courses
        $stats['popular_courses'] = $wpdb->get_results("
            SELECT p.ID, p.post_title, COUNT(uc.id) as students_count,
                   (SELECT COUNT(*) FROM {$wpdb->prefix}cm_sessions WHERE course_id = p.ID) as sessions_count
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}cm_user_courses uc ON p.ID = uc.course_id
            WHERE p.post_status = 'publish' AND uc.status = 'active'
            GROUP BY p.ID
            ORDER BY students_count DESC
            LIMIT 10
        ");
        
        // Recent enrollments
        $stats['recent_enrollments'] = $wpdb->get_results("
            SELECT uc.*, u.display_name, u.user_email, p.post_title
            FROM {$wpdb->prefix}cm_user_courses uc
            INNER JOIN {$wpdb->users} u ON uc.user_id = u.ID
            INNER JOIN {$wpdb->posts} p ON uc.course_id = p.ID
            WHERE p.post_status = 'publish'
            ORDER BY uc.enrolled_at DESC
            LIMIT 10
        ");

        return $stats;
    }
    
    /**
     * Dashboard Page
     */
    public function page_dashboard() {
        $stats = $this->get_dashboard_stats();
        ?>
        <div class="wrap cm-dashboard-wrap">            
            <!-- Stats Cards -->
            <div class="cm-stats-grid">
                <div class="cm-stat-card cm-stat-primary">
                    <div class="cm-stat-icon"><span class="dashicons dashicons-welcome-learn-more"></span></div>
                    <div class="cm-stat-content">
                        <div class="cm-stat-value"><?php echo $stats['courses_count']; ?></div>
                        <div class="cm-stat-label"><?php _e('Courses', 'courses-management'); ?></div>
                    </div>
                </div>
                <div class="cm-stat-card cm-stat-success">
                    <div class="cm-stat-icon"><span class="dashicons dashicons-list-view"></span></div>
                    <div class="cm-stat-content">
                        <div class="cm-stat-value"><?php echo $stats['sessions_count']; ?></div>
                        <div class="cm-stat-label"><?php _e('Sessions', 'courses-management'); ?></div>
                    </div>
                </div>
                <div class="cm-stat-card cm-stat-info">
                    <div class="cm-stat-icon"><span class="dashicons dashicons-groups"></span></div>
                    <div class="cm-stat-content">
                        <div class="cm-stat-value"><?php echo $stats['students_count']; ?></div>
                        <div class="cm-stat-label"><?php _e('Students', 'courses-management'); ?></div>
                    </div>
                </div>
                <div class="cm-stat-card cm-stat-warning">
                    <div class="cm-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div class="cm-stat-content">
                        <div class="cm-stat-value"><?php echo $stats['attendance_rate']; ?>%</div>
                        <div class="cm-stat-label"><?php _e('Attendance Rate', 'courses-management'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="cm-quick-actions">
                <a href="<?php echo admin_url('post-new.php?post_type=cm_course'); ?>" class="button button-primary button-hero">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php _e('Add New Course', 'courses-management'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=cm-enrollments'); ?>" class="button button-secondary button-hero">
                    <span class="dashicons dashicons-groups"></span>
                    <?php _e('Manage Enrollments', 'courses-management'); ?>
                </a>
            </div>
            
            
            <!-- Tables Row -->
            <div class="cm-tables-row">
                <!-- Top Students by Attendance -->
                <div class="cm-table-card">
                    <h3>
                        <span class="dashicons dashicons-awards"></span>
                        <?php _e('Top Students by Attendance', 'courses-management'); ?>
                    </h3>
                    <table class="cm-data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?php _e('Student', 'courses-management'); ?></th>
                                <th><?php _e('Sessions Attended', 'courses-management'); ?></th>
                                <th><?php _e('Courses', 'courses-management'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stats['top_students_attendance'])): ?>
                                <tr><td colspan="4" class="cm-no-data"><?php _e('No data available', 'courses-management'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($stats['top_students_attendance'] as $i => $student): ?>
                                    <tr>
                                        <td>
                                            <span class="cm-rank cm-rank-<?php echo $i + 1; ?>"><?php echo $i + 1; ?></span>
                                        </td>
                                        <td>
                                            <div class="cm-user-cell">
                                                <?php echo get_avatar($student->ID, 32); ?>
                                                <div>
                                                    <strong><?php echo esc_html($student->display_name); ?></strong>
                                                    <small><?php echo esc_html($student->user_email); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="cm-badge cm-badge-success"><?php echo $student->attendance_count; ?></span></td>
                                        <td><?php echo $student->courses_count; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Top Students by Enrollments -->
                <div class="cm-table-card">
                    <h3>
                        <span class="dashicons dashicons-star-filled"></span>
                        <?php _e('Most Active Students', 'courses-management'); ?>
                    </h3>
                    <table class="cm-data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?php _e('Student', 'courses-management'); ?></th>
                                <th><?php _e('Courses Enrolled', 'courses-management'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stats['top_students_enrollments'])): ?>
                                <tr><td colspan="3" class="cm-no-data"><?php _e('No data available', 'courses-management'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($stats['top_students_enrollments'] as $i => $student): ?>
                                    <tr>
                                        <td><span class="cm-rank cm-rank-<?php echo $i + 1; ?>"><?php echo $i + 1; ?></span></td>
                                        <td>
                                            <div class="cm-user-cell">
                                                <?php echo get_avatar($student->ID, 32); ?>
                                                <div>
                                                    <strong><?php echo esc_html($student->display_name); ?></strong>
                                                    <small><?php echo esc_html($student->user_email); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="cm-badge cm-badge-primary"><?php echo $student->courses_count; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- More Tables Row -->
            <div class="cm-tables-row">
                <!-- Popular Courses -->
                <div class="cm-table-card">
                    <h3>
                        <span class="dashicons dashicons-chart-line"></span>
                        <?php _e('Popular Courses', 'courses-management'); ?>
                    </h3>
                    <table class="cm-data-table">
                        <thead>
                            <tr>
                                <th><?php _e('Course', 'courses-management'); ?></th>
                                <th><?php _e('Students', 'courses-management'); ?></th>
                                <th><?php _e('Sessions', 'courses-management'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stats['popular_courses'])): ?>
                                <tr><td colspan="3" class="cm-no-data"><?php _e('No data available', 'courses-management'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($stats['popular_courses'] as $course): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo get_edit_post_link($course->ID); ?>">
                                                <?php echo esc_html($course->post_title); ?>
                                            </a>
                                        </td>
                                        <td><span class="cm-badge cm-badge-info"><?php echo $course->students_count; ?></span></td>
                                        <td><?php echo $course->sessions_count; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Recent Enrollments -->
                <div class="cm-table-card">
                    <h3>
                        <span class="dashicons dashicons-clock"></span>
                        <?php _e('Recent Enrollments', 'courses-management'); ?>
                    </h3>
                    <table class="cm-data-table">
                        <thead>
                            <tr>
                                <th><?php _e('Student', 'courses-management'); ?></th>
                                <th><?php _e('Course', 'courses-management'); ?></th>
                                <th><?php _e('Date', 'courses-management'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stats['recent_enrollments'])): ?>
                                <tr><td colspan="3" class="cm-no-data"><?php _e('No data available', 'courses-management'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($stats['recent_enrollments'] as $enrollment): ?>
                                    <tr>
                                        <td>
                                            <div class="cm-user-cell">
                                                <?php echo get_avatar($enrollment->user_id, 32); ?>
                                                <span><?php echo esc_html($enrollment->display_name); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo esc_html($enrollment->post_title); ?></td>
                                        <td><?php echo date_i18n(get_option('date_format'), strtotime($enrollment->enrolled_at)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
        
        <!-- Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        jQuery(document).ready(function($) {
            // Enrollments Chart
            const enrollmentsData = <?php echo json_encode($stats['enrollments_chart']); ?>;
            const enrollmentsCtx = document.getElementById('cm-enrollments-chart');
            
            if (enrollmentsCtx) {
                new Chart(enrollmentsCtx, {
                    type: 'line',
                    data: {
                        labels: enrollmentsData.map(d => d.month),
                        datasets: [{
                            label: 'Enrollments',
                            data: enrollmentsData.map(d => d.count),
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }
            
            // Attendance Chart
            const attendanceData = <?php echo json_encode($stats['attendance_chart']); ?>;
            const attendanceCtx = document.getElementById('cm-attendance-chart');
            
            if (attendanceCtx) {
                new Chart(attendanceCtx, {
                    type: 'bar',
                    data: {
                        labels: attendanceData.map(d => d.month),
                        datasets: [{
                            label: 'Attendance',
                            data: attendanceData.map(d => d.count),
                            backgroundColor: '#0C9D61',
                            borderRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }
        });
        </script>
        
        <?php $this->dashboard_styles(); ?>
        <?php
    }
    
    /**
     * Dashboard Styles
     */
    private function dashboard_styles() {
        ?>
        <style>
        .cm-dashboard-wrap { padding: 0px; max-width: 1600px; }
        
        .cm-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .cm-stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .cm-stat-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .cm-stat-icon .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
            color: #fff;
        }
        
        .cm-stat-primary .cm-stat-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .cm-stat-success .cm-stat-icon { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .cm-stat-info .cm-stat-icon { background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%); }
        .cm-stat-warning .cm-stat-icon { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        
        .cm-stat-value { font-size: 36px; font-weight: 700; color: #1e1e1e; line-height: 1; }
        .cm-stat-label { font-size: 14px; color: #666; margin-top: 5px; }
        
        .cm-quick-actions { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 30px; }
        .cm-quick-actions .button-hero { display: inline-flex !important; align-items: center; gap: 8px; }
        
        
        .cm-tables-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .cm-table-card {
            background: #fff;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .cm-table-card h3 {
            margin: 0 0 20px;
            font-size: 16px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .cm-table-card h3 .dashicons { color: #667eea; }
        
        .cm-data-table { width: 100%; border-collapse: collapse; }
        .cm-data-table th, .cm-data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        .cm-data-table th {
            font-weight: 600;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            background: #f9f9f9;
        }
        .cm-data-table tr:hover { background: #f9f9f9; }
        .cm-data-table a { color: #2563eb; text-decoration: none; }
        .cm-data-table a:hover { text-decoration: underline; }
        .cm-no-data { text-align: center; color: #999; padding: 30px !important; }
        
        .cm-user-cell { display: flex; align-items: center; gap: 10px; }
        .cm-user-cell img { border-radius: 50%; }
        .cm-user-cell div { display: flex; flex-direction: column; }
        .cm-user-cell small { color: #999; font-size: 12px; }
        
        .cm-rank {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            font-size: 12px;
            font-weight: 600;
            color: #fff;
            background: #ccc;
        }
        .cm-rank-1 { background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%); }
        .cm-rank-2 { background: linear-gradient(135deg, #C0C0C0 0%, #A8A8A8 100%); }
        .cm-rank-3 { background: linear-gradient(135deg, #CD7F32 0%, #B87333 100%); }
        
        .cm-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .cm-badge-primary { background: #e0e7ff; color: #4338ca; }
        .cm-badge-success { background: #d1fae5; color: #065f46; }
        .cm-badge-info { background: #dbeafe; color: #1e40af; }
        .cm-badge-warning { background: #fef3c7; color: #92400e; }
        .cm-badge-danger { background: #fee2e2; color: #991b1b; }
        
        @media (max-width: 782px) {
            .cm-stats-grid { grid-template-columns: repeat(2, 1fr); }
            .cm-charts-row, .cm-tables-row { grid-template-columns: 1fr; }
        }
        </style>
        <?php
    }
        
    /**
     * Students Page
     */
    public function page_students() {
        global $wpdb;
        
        $students = $wpdb->get_results("
            SELECT 
                u.ID,
                u.display_name,
                u.user_email,
                u.user_registered,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cm_user_courses WHERE user_id = u.ID AND status = 'active') as courses_count,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cm_attendance WHERE user_id = u.ID AND attended = 1) as attendance_count,
                (SELECT MAX(attended_at) FROM {$wpdb->prefix}cm_attendance WHERE user_id = u.ID AND attended = 1) as last_attendance
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}cm_user_courses uc ON u.ID = uc.user_id
            GROUP BY u.ID
            ORDER BY courses_count DESC, attendance_count DESC
        ");
        ?>
        <div class="wrap cm-students-wrap">
            <h1><?php _e('Students Management', 'courses-management'); ?></h1>
            
            <!-- Filters -->
            <div class="cm-filters-bar">
                <div class="cm-filter-group">
                    <label><?php _e('Search', 'courses-management'); ?></label>
                    <input type="text" id="cm-student-search" class="cm-input" placeholder="<?php _e('Search by name or email...', 'courses-management'); ?>">
                </div>
                <div class="cm-filter-group">
                    <label><?php _e('Sort By', 'courses-management'); ?></label>
                    <select id="cm-student-sort" class="cm-select">
                        <option value="courses"><?php _e('Most Courses', 'courses-management'); ?></option>
                        <option value="attendance"><?php _e('Most Attendance', 'courses-management'); ?></option>
                        <option value="name"><?php _e('Name', 'courses-management'); ?></option>
                    </select>
                </div>
            </div>
            
            <!-- Students Grid -->
            <div class="cm-students-grid" id="cm-students-list">
                <?php foreach ($students as $student): 
                    $student_courses = $wpdb->get_results($wpdb->prepare("
                        SELECT p.post_title FROM {$wpdb->prefix}cm_user_courses uc
                        INNER JOIN {$wpdb->posts} p ON uc.course_id = p.ID
                        WHERE uc.user_id = %d AND uc.status = 'active'
                        ORDER BY uc.enrolled_at DESC
                    ", $student->ID));
                ?>
                    <div class="cm-student-card" 
                         data-name="<?php echo esc_attr(strtolower($student->display_name)); ?>" 
                         data-email="<?php echo esc_attr(strtolower($student->user_email)); ?>" 
                         data-courses="<?php echo $student->courses_count; ?>" 
                         data-attendance="<?php echo $student->attendance_count; ?>">
                        
                        <div class="cm-student-header">
                            <?php echo get_avatar($student->ID, 60); ?>
                            <div class="cm-student-info">
                                <h3><?php echo esc_html($student->display_name); ?></h3>
                                <p><?php echo esc_html($student->user_email); ?></p>
                            </div>
                            <div class="cm-student-actions">
                                <a href="<?php echo admin_url('admin.php?page=cm-enrollments&user_id=' . $student->ID); ?>" class="button button-small">
                                    <?php _e('Manage', 'courses-management'); ?>
                                </a>
                            </div>
                        </div>
                        
                        <div class="cm-student-stats">
                            <div class="cm-student-stat">
                                <span class="cm-stat-num"><?php echo $student->courses_count; ?></span>
                                <span class="cm-stat-text"><?php _e('Courses', 'courses-management'); ?></span>
                            </div>
                            <div class="cm-student-stat">
                                <span class="cm-stat-num"><?php echo $student->attendance_count; ?></span>
                                <span class="cm-stat-text"><?php _e('Sessions', 'courses-management'); ?></span>
                            </div>
                            <div class="cm-student-stat">
                                <span class="cm-stat-num"><?php echo $student->last_attendance ? human_time_diff(strtotime($student->last_attendance)) : '-'; ?></span>
                                <span class="cm-stat-text"><?php _e('Last Active', 'courses-management'); ?></span>
                            </div>
                        </div>
                        
                        <?php if ($student_courses): ?>
                            <div class="cm-student-courses">
                                <h4><?php _e('Enrolled Courses', 'courses-management'); ?></h4>
                                <ul>
                                    <?php foreach (array_slice($student_courses, 0, 3) as $course): ?>
                                        <li><?php echo esc_html($course->post_title); ?></li>
                                    <?php endforeach; ?>
                                    <?php if (count($student_courses) > 3): ?>
                                        <li class="cm-more">+<?php echo count($student_courses) - 3; ?> <?php _e('more', 'courses-management'); ?></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (empty($students)): ?>
                <div class="cm-no-students">
                    <span class="dashicons dashicons-groups"></span>
                    <p><?php _e('No students found.', 'courses-management'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#cm-student-search').on('input', function() {
                const search = $(this).val().toLowerCase();
                $('.cm-student-card').each(function() {
                    const name = $(this).data('name');
                    const email = $(this).data('email');
                    $(this).toggle(name.includes(search) || email.includes(search));
                });
            });
            
            $('#cm-student-sort').on('change', function() {
                const sort = $(this).val();
                const $list = $('#cm-students-list');
                const $cards = $list.children('.cm-student-card').get();
                
                $cards.sort(function(a, b) {
                    switch(sort) {
                        case 'courses': return $(b).data('courses') - $(a).data('courses');
                        case 'attendance': return $(b).data('attendance') - $(a).data('attendance');
                        case 'name': return $(a).data('name').localeCompare($(b).data('name'));
                        default: return 0;
                    }
                });
                
                $.each($cards, function(i, card) { $list.append(card); });
            });
        });
        </script>
        
        <?php $this->students_styles(); ?>
        <?php
    }
    
    /**
     * Students Styles
     */
    private function students_styles() {
        ?>
        <style>
        .cm-students-wrap { padding: 20px; max-width: 1600px; }
        
        .cm-filters-bar {
            display: flex;
            gap: 20px;
            align-items: flex-end;
            margin-bottom: 30px;
            padding: 20px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .cm-filter-group { display: flex; flex-direction: column; gap: 5px; }
        .cm-filter-group label { font-weight: 600; font-size: 13px; color: #666; }
        .cm-input { padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; min-width: 300px; }
        .cm-select { padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; min-width: 200px; }
        
        .cm-students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .cm-student-card {
            background: #fff;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: box-shadow 0.2s;
        }
        
        .cm-student-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        
        .cm-student-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .cm-student-header img { border-radius: 50%; }
        .cm-student-info { flex: 1; }
        .cm-student-info h3 { margin: 0 0 5px; font-size: 16px; }
        .cm-student-info p { margin: 0; color: #666; font-size: 13px; }
        
        .cm-student-stats {
            display: flex;
            gap: 20px;
            padding: 15px 0;
            border-top: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .cm-student-stat { text-align: center; flex: 1; }
        .cm-stat-num { display: block; font-size: 24px; font-weight: 700; color: #2563eb; }
        .cm-stat-text { font-size: 12px; color: #666; }
        
        .cm-student-courses { margin-top: 15px; }
        .cm-student-courses h4 { margin: 0 0 10px; font-size: 13px; color: #666; }
        .cm-student-courses ul { margin: 0; padding: 0; list-style: none; }
        .cm-student-courses li { padding: 5px 0; font-size: 13px; border-bottom: 1px solid #f0f0f0; }
        .cm-student-courses li:last-child { border-bottom: none; }
        .cm-student-courses .cm-more { color: #2563eb; font-weight: 500; }
        
        .cm-no-students { text-align: center; padding: 60px; background: #fff; border-radius: 16px; }
        .cm-no-students .dashicons { font-size: 48px; width: 48px; height: 48px; color: #ddd; }
        </style>
        <?php
    }
    
    /**
     * Enrollments Page
     */
    public function page_enrollments() {
        $users = get_users(['role__in' => ['subscriber', 'student', 'customer', 'administrator']]);
        $courses = get_posts(['post_type' => 'cm_course', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $selected_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        ?>
        <div class="wrap cm-enrollments-wrap">
            <h1><?php _e('Manage Enrollments', 'courses-management'); ?></h1>
            
            <div id="cm-enrollments-app">
                <div class="cm-grid">
                    <div class="cm-card">
                        <h3><?php _e('Select User', 'courses-management'); ?></h3>
                        <select id="cm-user-select" class="cm-select">
                            <option value=""><?php _e('-- Choose User --', 'courses-management'); ?></option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user->ID; ?>" <?php selected($selected_user, $user->ID); ?>>
                                    <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="cm-card cm-card-wide" id="cm-courses-panel" style="display:<?php echo $selected_user ? 'block' : 'none'; ?>; margin-top: 20px;">
                    <h3><?php _e('Available Courses', 'courses-management'); ?></h3>
                    <div class="cm-courses-grid">
                        <?php foreach ($courses as $course): ?>
                            <label class="cm-course-checkbox" data-course-id="<?php echo $course->ID; ?>">
                                <input type="checkbox" name="courses[]" value="<?php echo $course->ID; ?>">
                                <span><?php echo esc_html($course->post_title); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="cm-actions">
                        <button type="button" id="cm-save-enrollments" class="button button-primary">
                            <?php _e('Save Enrollments', 'courses-management'); ?>
                        </button>
                        <span id="cm-enrollment-status" class="cm-status"></span>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($selected_user): ?>
        <script>
        jQuery(document).ready(function($) { $('#cm-user-select').trigger('change'); });
        </script>
        <?php endif; ?>
        
        <?php $this->enrollments_styles(); ?>
        <?php
    }
    
    /**
     * Enrollments Styles
     */
    private function enrollments_styles() {
        ?>
        <style>
        .cm-enrollments-wrap { padding: 20px; max-width: 1200px; }
        .cm-grid { display: flex; gap: 20px; flex-wrap: wrap; }
        .cm-card { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); min-width: 300px; }
        .cm-card h3 { margin: 0 0 20px; }
        .cm-card-wide { flex: 1; min-width: 500px; }
        .cm-select { width: 100%; padding: 12px 15px; border: 1px solid #ddd; border-radius: 8px; }
        .cm-courses-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; max-height: 400px; overflow-y: auto; padding: 5px; }
        .cm-course-checkbox { display: flex; align-items: center; gap: 12px; padding: 12px 15px; background: #f9f9f9; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .cm-course-checkbox:hover { border-color: #2271b1; }
        .cm-course-checkbox.enrolled { border-color: #00a32a; background: #f0fdf4; }
        .cm-course-checkbox input { width: 18px; height: 18px; }
        .cm-actions { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; display: flex; gap: 15px; align-items: center; }
        .cm-status.success { color: #00a32a; }
        .cm-status.error { color: #dc3545; }
        </style>
        <?php
    }
    
    /**
     * Attendance Page
     */
    public function page_attendance() {
        $courses = get_posts(['post_type' => 'cm_course', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        ?>
        <div class="wrap cm-attendance-wrap">
            <h1><?php _e('Session Attendance', 'courses-management'); ?></h1>
            
            <div id="cm-attendance-app">
                <div class="cm-grid">
                    <div class="cm-card">
                        <h3><?php _e('Select Course', 'courses-management'); ?></h3>
                        <select id="cm-course-select" class="cm-select">
                            <option value=""><?php _e('-- Choose Course --', 'courses-management'); ?></option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo $course->ID; ?>"><?php echo esc_html($course->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="cm-card" id="cm-session-panel" style="display:none;">
                        <h3><?php _e('Select Session', 'courses-management'); ?></h3>
                        <select id="cm-session-select" class="cm-select">
                            <option value=""><?php _e('-- Choose Session --', 'courses-management'); ?></option>
                        </select>
                    </div>
                </div>
                
                <div class="cm-card cm-card-wide" id="cm-attendance-panel" style="display:none; margin-top: 20px;">
                    <h3><?php _e('Attendance', 'courses-management'); ?></h3>
                    <div class="cm-bulk-actions">
                        <button type="button" class="button" id="cm-mark-all-present"><?php _e('Mark All Present', 'courses-management'); ?></button>
                        <button type="button" class="button" id="cm-mark-all-absent"><?php _e('Mark All Absent', 'courses-management'); ?></button>
                    </div>
                    <div id="cm-attendance-table-wrap"></div>
                    <div class="cm-actions">
                        <button type="button" id="cm-save-attendance" class="button button-primary"><?php _e('Save Attendance', 'courses-management'); ?></button>
                        <span id="cm-attendance-status" class="cm-status"></span>
                    </div>
                </div>
            </div>
        </div>
        
        <?php $this->attendance_styles(); ?>
        <?php
    }
    
    /**
     * Attendance Styles
     */
    private function attendance_styles() {
        ?>
        <style>
        .cm-attendance-wrap { padding: 20px; max-width: 1200px; }
        .cm-grid { display: flex; gap: 20px; flex-wrap: wrap; }
        .cm-card { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); min-width: 300px; }
        .cm-card h3 { margin: 0 0 20px; }
        .cm-card-wide { flex: 1; min-width: 100%; }
        .cm-select { width: 100%; padding: 12px 15px; border: 1px solid #ddd; border-radius: 8px; }
        .cm-bulk-actions { display: flex; gap: 10px; margin-bottom: 20px; }
        .cm-actions { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; display: flex; gap: 15px; align-items: center; }
        
        .cm-attendance-stats { display: flex; gap: 20px; margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 8px; }
        .cm-att-stat { text-align: center; }
        .cm-att-stat-value { font-size: 24px; font-weight: 700; }
        .cm-att-stat-label { font-size: 12px; color: #666; }
        .cm-att-stat.present .cm-att-stat-value { color: #00a32a; }
        .cm-att-stat.absent .cm-att-stat-value { color: #dc3545; }
        
        .cm-attendance-table { width: 100%; border-collapse: collapse; }
        .cm-attendance-table th, .cm-attendance-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        .cm-attendance-table th { background: #f9f9f9; font-weight: 600; }
        
        .cm-attendance-toggle { position: relative; width: 50px; height: 26px; display: inline-block; }
        .cm-attendance-toggle input { opacity: 0; width: 0; height: 0; }
        .cm-attendance-toggle .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #dc3545; transition: 0.3s; border-radius: 26px; }
        .cm-attendance-toggle .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: 0.3s; border-radius: 50%; }
        .cm-attendance-toggle input:checked + .slider { background-color: #00a32a; }
        .cm-attendance-toggle input:checked + .slider:before { transform: translateX(24px); }
        
        .cm-status.success { color: #00a32a; }
        .cm-status.error { color: #dc3545; }
        .cm-loading { padding: 40px; text-align: center; color: #666; }
        </style>
        <?php
    }
    
    // ========================================
    // AJAX HANDLERS
    // ========================================
    
    public function ajax_get_user_courses() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        $user_id = intval($_POST['user_id'] ?? 0);
        if (!$user_id) wp_send_json_error(['message' => 'Invalid user']);
        
        global $wpdb;
        $enrolled = $wpdb->get_col($wpdb->prepare(
            "SELECT course_id FROM {$wpdb->prefix}cm_user_courses WHERE user_id = %d",
            $user_id
        ));
        
        wp_send_json_success(['enrolled' => array_map('intval', $enrolled)]);
    }
    
    public function ajax_save_enrollments() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied']);
        
        $user_id = intval($_POST['user_id'] ?? 0);
        $courses = isset($_POST['courses']) ? array_map('intval', $_POST['courses']) : [];
        
        if (!$user_id) wp_send_json_error(['message' => 'Invalid user']);
        
        global $wpdb;
        $table = $wpdb->prefix . 'cm_user_courses';
        
        $wpdb->delete($table, ['user_id' => $user_id]);
        
        foreach ($courses as $course_id) {
            $wpdb->insert($table, ['user_id' => $user_id, 'course_id' => $course_id, 'status' => 'active']);
        }
        
        wp_send_json_success(['message' => __('Enrollments saved successfully', 'courses-management'), 'count' => count($courses)]);
    }
    
    public function ajax_get_course_students() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        $course_id = intval($_POST['course_id'] ?? 0);
        if (!$course_id) wp_send_json_error(['message' => 'Invalid course']);
        
        wp_send_json_success(['sessions' => cm_get_sessions($course_id)]);
    }
    
    public function ajax_get_attendance_data() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        $course_id = intval($_POST['course_id'] ?? 0);
        $session_id = intval($_POST['session_id'] ?? 0);
        
        if (!$course_id || !$session_id) wp_send_json_error(['message' => 'Invalid data']);
        
        global $wpdb;
        
        $students = $wpdb->get_results($wpdb->prepare("
            SELECT u.ID as user_id, u.display_name, u.user_email, COALESCE(a.attended, 0) as attended
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}cm_user_courses uc ON u.ID = uc.user_id
            LEFT JOIN {$wpdb->prefix}cm_attendance a ON u.ID = a.user_id AND a.session_id = %d
            WHERE uc.course_id = %d AND uc.status = 'active'
            ORDER BY u.display_name ASC
        ", $session_id, $course_id));
        
        $total = count($students);
        $present = 0;
        foreach ($students as $s) { if ($s->attended) $present++; }
        
        wp_send_json_success([
            'students' => $students,
            'stats' => [
                'total' => $total,
                'present' => $present,
                'absent' => $total - $present,
                'percent' => $total > 0 ? round(($present / $total) * 100) : 0,
            ]
        ]);
    }
    
    public function ajax_save_attendance() {
        check_ajax_referer('cm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied']);
        
        $course_id = intval($_POST['course_id'] ?? 0);
        $session_id = intval($_POST['session_id'] ?? 0);
        $attendance = isset($_POST['attendance']) ? $_POST['attendance'] : [];
        
        if (!$course_id || !$session_id) wp_send_json_error(['message' => 'Invalid data']);
        
        global $wpdb;
        $table = $wpdb->prefix . 'cm_attendance';
        
        foreach ($attendance as $user_id => $attended) {
            $wpdb->replace($table, [
                'user_id'     => intval($user_id),
                'course_id'   => $course_id,
                'session_id'  => $session_id,
                'attended'    => $attended ? 1 : 0,
                'attended_at' => $attended ? current_time('mysql') : null,
            ]);
        }
        
        wp_send_json_success(['message' => __('Attendance saved', 'courses-management')]);
    }
    
    public function ajax_get_dashboard_stats() {
        check_ajax_referer('cm_nonce', 'nonce');
        wp_send_json_success($this->get_dashboard_stats());
    }
    
    public function ajax_export_report() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'cm_nonce')) die('Invalid nonce');
        if (!current_user_can('manage_options')) die('Permission denied');
        
        global $wpdb;
        
        $course_id = intval($_GET['course'] ?? 0);
        
        $where = "WHERE p.post_type = 'cm_course' AND p.post_status = 'publish'";
        if ($course_id) $where .= $wpdb->prepare(" AND p.ID = %d", $course_id);
        
        $data = $wpdb->get_results("
            SELECT 
                p.post_title as course_name,
                u.display_name as student_name,
                u.user_email as student_email,
                uc.enrolled_at,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cm_sessions WHERE course_id = p.ID) as total_sessions,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cm_attendance a 
                 INNER JOIN {$wpdb->prefix}cm_sessions s ON a.session_id = s.id 
                 WHERE a.user_id = u.ID AND s.course_id = p.ID AND a.attended = 1) as attended_sessions
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}cm_user_courses uc ON p.ID = uc.course_id
            INNER JOIN {$wpdb->users} u ON uc.user_id = u.ID
            $where
            ORDER BY p.post_title, u.display_name
        ");
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="courses-report-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        fputcsv($output, ['Course', 'Student', 'Email', 'Enrolled Date', 'Total Sessions', 'Attended', 'Attendance Rate']);
        
        foreach ($data as $row) {
            $rate = $row->total_sessions > 0 ? round(($row->attended_sessions / $row->total_sessions) * 100) . '%' : '0%';
            fputcsv($output, [
                $row->course_name,
                $row->student_name,
                $row->student_email,
                $row->enrolled_at,
                $row->total_sessions,
                $row->attended_sessions,
                $rate
            ]);
        }
        
        fclose($output);
        exit;
    }
}