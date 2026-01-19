/**
 * Courses Management - Admin JavaScript
 */
(function($) {
    'use strict';

    // Check if CM is defined
    if (typeof CM === 'undefined') {
        console.error('CM is not defined');
        return;
    }

    const CM_Admin = {
        
        init: function() {
            this.initSessions();
            this.initEnrollments();
            this.initAttendance();
        },

        // ========================================
        // SESSIONS
        // ========================================
        
        initSessions: function() {
            const self = this;
            const $wrapper = $('#cm-sessions-app');
            
            if (!$wrapper.length) return;
            
            const courseId = $wrapper.data('course-id');
            let sessionIndex = $wrapper.find('.cm-session-item').length;
            let autoSaveTimer = null;
            
            // Make sortable
            if ($.fn.sortable) {
                $('#cm-sessions-list').sortable({
                    handle: '.cm-session-drag',
                    placeholder: 'cm-session-placeholder',
                    update: function() {
                        self.updateSessionNumbers();
                        self.triggerAutoSave(courseId);
                    }
                });
            }
            
            // Add Session
            $('#cm-add-session').on('click', function() {
                sessionIndex++;
                let template = $('#cm-session-template').html();
                template = template.replace(/\{\{INDEX\}\}/g, sessionIndex);
                $('#cm-sessions-list').append(template);
                self.updateSessionNumbers();
                $('#cm-sessions-list .cm-session-item:last-child .cm-session-title-input').focus();
            });
            
            // Delete Session
            $(document).on('click', '.cm-session-delete', function(e) {
                e.stopPropagation();
                
                if (!confirm(CM.strings.confirm_delete || 'Are you sure?')) return;
                
                const $item = $(this).closest('.cm-session-item');
                const sessionId = $item.data('id');
                
                if (String(sessionId).indexOf('new_') === 0) {
                    $item.slideUp(200, function() {
                        $(this).remove();
                        self.updateSessionNumbers();
                    });
                    return;
                }
                
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_delete_session',
                        nonce: CM.nonce,
                        session_id: sessionId
                    },
                    success: function(response) {
                        if (response.success) {
                            $item.slideUp(200, function() {
                                $(this).remove();
                                self.updateSessionNumbers();
                            });
                        } else {
                            alert(response.data?.message || 'Error');
                        }
                    }
                });
            });
            
            // Toggle Session
            $(document).on('click', '.cm-session-header', function(e) {
                if ($(e.target).is('input, button, .cm-session-delete')) return;
                $(this).closest('.cm-session-item').toggleClass('collapsed');
            });
            
            // Expand/Collapse All
            $('#cm-expand-all').on('click', function() {
                $('.cm-session-item').removeClass('collapsed');
            });
            
            $('#cm-collapse-all').on('click', function() {
                $('.cm-session-item').addClass('collapsed');
            });
            
            // Auto-save
            $(document).on('change input', '#cm-sessions-list input, #cm-sessions-list textarea', function() {
                self.triggerAutoSave(courseId);
            });
            
            // Trigger auto-save
            this.triggerAutoSave = function(courseId) {
                clearTimeout(autoSaveTimer);
                self.setStatus('saving');
                
                autoSaveTimer = setTimeout(function() {
                    self.saveSessions(courseId);
                }, 1500);
            };
            
            // Save sessions
            this.saveSessions = function(courseId) {
                const sessions = {};
                
                $('#cm-sessions-list .cm-session-item').each(function(index) {
                    const $item = $(this);
                    const id = $item.data('id');
                    
                    sessions[id] = {
                        title: $item.find('input[name*="[title]"]').val() || '',
                        date: $item.find('input[name*="[date]"]').val() || '',
                        time: $item.find('input[name*="[time]"]').val() || '',
                        link: $item.find('input[name*="[link]"]').val() || '',
                        description: $item.find('textarea[name*="[description]"]').val() || ''
                    };
                });
                
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_save_sessions',
                        nonce: CM.nonce,
                        course_id: courseId,
                        sessions: sessions
                    },
                    success: function(response) {
                        if (response.success) {
                            self.setStatus('saved');
                            
                            // Update IDs
                            if (response.data.sessions) {
                                response.data.sessions.forEach(function(session, index) {
                                    $('#cm-sessions-list .cm-session-item').eq(index).attr('data-id', session.id);
                                });
                            }
                            
                            $('.cm-sessions-count').text(response.data.count + ' Sessions');
                        } else {
                            self.setStatus('error');
                        }
                    },
                    error: function() {
                        self.setStatus('error');
                    }
                });
            };
            
            // Update numbers
            this.updateSessionNumbers = function() {
                $('.cm-session-item').each(function(i) {
                    $(this).find('.cm-session-number').text(i + 1);
                });
            };
            
            // Set status
            this.setStatus = function(status) {
                const $status = $('#cm-save-status');
                $status.removeClass('saving saved error');
                
                switch (status) {
                    case 'saving':
                        $status.addClass('saving').text(CM.strings.saving || 'Saving...');
                        break;
                    case 'saved':
                        $status.addClass('saved').text(CM.strings.saved || 'Saved!');
                        setTimeout(function() { $status.text(''); }, 2000);
                        break;
                    case 'error':
                        $status.addClass('error').text(CM.strings.error || 'Error');
                        break;
                }
            };
        },

        // ========================================
        // ENROLLMENTS
        // ========================================
        
        initEnrollments: function() {
            const self = this;
            const $app = $('#cm-enrollments-app');
            
            if (!$app.length) return;
            
            let currentUserId = null;
            
            // User selection
            $('#cm-user-select').on('change', function() {
                currentUserId = $(this).val();
                
                if (!currentUserId) {
                    $('#cm-courses-panel').hide();
                    return;
                }
                
                $('#cm-courses-panel').show();
                
                // Reset
                $('.cm-course-checkbox').removeClass('enrolled');
                $('.cm-course-checkbox input').prop('checked', false);
                
                // Load user courses
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_get_user_courses_admin',
                        nonce: CM.nonce,
                        user_id: currentUserId
                    },
                    success: function(response) {
                        if (response.success && response.data.enrolled) {
                            response.data.enrolled.forEach(function(courseId) {
                                const $checkbox = $('.cm-course-checkbox[data-course-id="' + courseId + '"]');
                                $checkbox.addClass('enrolled');
                                $checkbox.find('input').prop('checked', true);
                            });
                        }
                    }
                });
            });
            
            // Toggle on change
            $(document).on('change', '.cm-course-checkbox input', function() {
                $(this).closest('.cm-course-checkbox').toggleClass('enrolled', this.checked);
            });
            
            // Save
            $('#cm-save-enrollments').on('click', function() {
                const $btn = $(this);
                const courses = [];
                
                $('.cm-course-checkbox input:checked').each(function() {
                    courses.push($(this).val());
                });
                
                $btn.prop('disabled', true).text(CM.strings.saving || 'Saving...');
                
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_save_enrollments',
                        nonce: CM.nonce,
                        user_id: currentUserId,
                        courses: courses
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#cm-enrollment-status').removeClass('error').addClass('success').text(response.data.message);
                        } else {
                            $('#cm-enrollment-status').removeClass('success').addClass('error').text(response.data?.message || 'Error');
                        }
                    },
                    error: function() {
                        $('#cm-enrollment-status').removeClass('success').addClass('error').text('Connection error');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('Save Enrollments');
                    }
                });
            });
        },

        // ========================================
        // ATTENDANCE
        // ========================================
        
        initAttendance: function() {
            const self = this;
            const $app = $('#cm-attendance-app');
            
            if (!$app.length) return;
            
            let currentCourseId = null;
            let currentSessionId = null;
            
            // Course selection
            $('#cm-course-select').on('change', function() {
                currentCourseId = $(this).val();
                currentSessionId = null;
                
                if (!currentCourseId) {
                    $('#cm-session-panel, #cm-attendance-panel').hide();
                    return;
                }
                
                // Load sessions
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_get_course_students',
                        nonce: CM.nonce,
                        course_id: currentCourseId
                    },
                    success: function(response) {
                        if (response.success) {
                            let options = '<option value="">-- Choose Session --</option>';
                            response.data.sessions.forEach(function(session, i) {
                                const title = session.session_title || ('Session ' + (i + 1));
                                const date = session.session_date || '';
                                options += '<option value="' + session.id + '">' + title + (date ? ' (' + date + ')' : '') + '</option>';
                            });
                            
                            $('#cm-session-select').html(options);
                            $('#cm-session-panel').show();
                            $('#cm-attendance-panel').hide();
                        }
                    }
                });
            });
            
            // Session selection
            $('#cm-session-select').on('change', function() {
                currentSessionId = $(this).val();
                
                if (!currentSessionId) {
                    $('#cm-attendance-panel').hide();
                    return;
                }
                
                $('#cm-attendance-table-wrap').html('<div class="cm-loading">Loading...</div>');
                $('#cm-attendance-panel').show();
                
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_get_attendance_data',
                        nonce: CM.nonce,
                        course_id: currentCourseId,
                        session_id: currentSessionId
                    },
                    success: function(response) {
                        if (response.success) {
                            self.renderAttendanceTable(response.data);
                        } else {
                            $('#cm-attendance-table-wrap').html('<p style="color:red;">' + (response.data?.message || 'Error') + '</p>');
                        }
                    }
                });
            });
            
            // Mark all present
            $('#cm-mark-all-present').on('click', function() {
                $('.cm-attendance-toggle input').prop('checked', true).trigger('change');
            });
            
            // Mark all absent
            $('#cm-mark-all-absent').on('click', function() {
                $('.cm-attendance-toggle input').prop('checked', false).trigger('change');
            });
            
            // Save
            $('#cm-save-attendance').on('click', function() {
                const $btn = $(this);
                const attendance = {};
                
                $('.cm-attendance-toggle input').each(function() {
                    attendance[$(this).data('user-id')] = this.checked ? 1 : 0;
                });
                
                $btn.prop('disabled', true).text(CM.strings.saving || 'Saving...');
                
                $.ajax({
                    url: CM.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cm_save_attendance_admin',
                        nonce: CM.nonce,
                        course_id: currentCourseId,
                        session_id: currentSessionId,
                        attendance: attendance
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#cm-attendance-status').removeClass('error').addClass('success').text(response.data.message);
                        } else {
                            $('#cm-attendance-status').removeClass('success').addClass('error').text(response.data?.message || 'Error');
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('Save Attendance');
                    }
                });
            });
            
            // Render table
            this.renderAttendanceTable = function(data) {
                let html = '';
                
                // Stats
                html += '<div class="cm-attendance-stats">';
                html += '<div class="cm-att-stat"><div class="cm-att-stat-value">' + data.stats.total + '</div><div class="cm-att-stat-label">Total</div></div>';
                html += '<div class="cm-att-stat present"><div class="cm-att-stat-value">' + data.stats.present + '</div><div class="cm-att-stat-label">Present</div></div>';
                html += '<div class="cm-att-stat absent"><div class="cm-att-stat-value">' + data.stats.absent + '</div><div class="cm-att-stat-label">Absent</div></div>';
                html += '<div class="cm-att-stat"><div class="cm-att-stat-value">' + data.stats.percent + '%</div><div class="cm-att-stat-label">Rate</div></div>';
                html += '</div>';
                
                // Table
                html += '<table class="cm-attendance-table">';
                html += '<thead><tr><th>Student</th><th>Email</th><th>Attended</th></tr></thead>';
                html += '<tbody>';
                
                if (data.students.length === 0) {
                    html += '<tr><td colspan="3" style="text-align:center;padding:30px;color:#666;">No students enrolled</td></tr>';
                } else {
                    data.students.forEach(function(student) {
                        const checked = student.attended == 1 ? 'checked' : '';
                        html += '<tr>';
                        html += '<td><strong>' + student.display_name + '</strong></td>';
                        html += '<td>' + student.user_email + '</td>';
                        html += '<td>';
                        html += '<label class="cm-attendance-toggle">';
                        html += '<input type="checkbox" data-user-id="' + student.user_id + '" ' + checked + '>';
                        html += '<span class="slider"></span>';
                        html += '</label>';
                        html += '</td>';
                        html += '</tr>';
                    });
                }
                
                html += '</tbody></table>';
                
                $('#cm-attendance-table-wrap').html(html);
            };
            
            // Update stats on toggle
            $(document).on('change', '.cm-attendance-toggle input', function() {
                const total = $('.cm-attendance-toggle input').length;
                const present = $('.cm-attendance-toggle input:checked').length;
                const absent = total - present;
                const percent = total > 0 ? Math.round((present / total) * 100) : 0;
                
                $('.cm-att-stat:eq(1) .cm-att-stat-value').text(present);
                $('.cm-att-stat:eq(2) .cm-att-stat-value').text(absent);
                $('.cm-att-stat:eq(3) .cm-att-stat-value').text(percent + '%');
            });
        }
    };

    $(document).ready(function() {
        CM_Admin.init();
    });

})(jQuery);