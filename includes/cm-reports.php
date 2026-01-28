<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function() {
    add_submenu_page(
        'courses-management',   // slug المينيو الرئيسي للبلاجن
        'Course Reports',       // عنوان الصفحة
        'Reports',              // اسم المينيو الفرعي
        'manage_options',       // صلاحيات
        'cm_course_reports',    // slug الصفحة
        'cm_render_reports'     // function تعرض المحتوى
    );
});

// دالة عرض التقارير
function cm_render_reports() {
    global $wpdb;

    // عدد الكورسات
    $course_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cm_courses");

    // متوسط تقييم لكل كورس
    $ratings = $wpdb->get_results("
        SELECT course_id, AVG(rating) as avg_rating 
        FROM {$wpdb->prefix}cm_ratings 
        GROUP BY course_id
    ");

    // عدد الطلاب المسجلين لكل كورس
    $students = $wpdb->get_results("
        SELECT course_id, COUNT(user_id) as total_students 
        FROM {$wpdb->prefix}cm_enrollments 
        GROUP BY course_id
    ");

    // ربط الطلاب بالتقييمات
    $report_data = [];
    foreach ($ratings as $r) {
        $student_count = 0;
        foreach($students as $s) {
            if($s->course_id == $r->course_id) {
                $student_count = $s->total_students;
                break;
            }
        }
        $report_data[] = [
            'course_id' => $r->course_id,
            'avg_rating' => round($r->avg_rating, 2),
            'students' => $student_count
        ];
    }
    ?>
    <div class="wrap">
        <h1>Course Reports</h1>
        <p>Total Courses: <?php echo $course_count; ?></p>

        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th>Course ID</th>
                    <th>Avg Rating</th>
                    <th>Students</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($report_data as $d): ?>
                <tr>
                    <td><?php echo $d['course_id']; ?></td>
                    <td><?php echo $d['avg_rating']; ?></td>
                    <td><?php echo $d['students']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Average Ratings Chart</h2>
        <canvas id="courseChart" width="600" height="300"></canvas>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const chartData = <?php echo json_encode($report_data); ?>;
        const ctx = document.getElementById('courseChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.map(d => 'Course ' + d.course_id),
                datasets: [{
                    label: 'Avg Rating',
                    data: chartData.map(d => d.avg_rating),
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: { beginAtZero: true, max: 5 }
                }
            }
        });
    </script>
    <?php
}
?>
