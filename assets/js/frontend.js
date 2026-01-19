/**
 * Courses Management - Frontend JavaScript
 */
(function($) {
    'use strict';

    // Check if CM is defined
    if (typeof CM === 'undefined') {
        console.warn('CM is not defined');
        return;
    }

    const CM_Frontend = {
        
        init: function() {
            this.bindEvents();
            this.initDynamicSessions();
        },

        bindEvents: function() {
            const self = this;
            
            // Mark as attended
            $(document).on('click', '.cm-mark-attended', function(e) {
                e.preventDefault();
                self.markAttended($(this));
            });
        },

        initDynamicSessions: function() {
            const self = this;
            
            $('.cm-sessions-dynamic').each(function() {
                const $container = $(this);
                const courseId = $container.data('course-id');
                self.loadSessions($container, courseId);
            });
        },

        loadSessions: function($container, courseId) {
            const self = this;
            
            $.ajax({
                url: CM.ajax_url,
                type: 'POST',
                data: {
                    action: 'cm_get_course_sessions',
                    nonce: CM.nonce,
                    course_id: courseId
                },
                success: function(response) {
                    if (response.success) {
                        self.renderSessions($container, response.data, courseId);
                    } else {
                        $container.html('<p style="color:red;">Error loading sessions</p>');
                    }
                },
                error: function() {
                    $container.html('<p style="color:red;">Connection error</p>');
                }
            });
        },

        renderSessions: function($container, data, courseId) {
            const sessions = data.sessions;
            const progress = data.progress;
            const isLoggedIn = CM.is_logged_in;
            
            let html = '<div class="cm-sessions-wrap" data-course-id="' + courseId + '">';
            
            // Progress
            if (isLoggedIn && progress && progress.total > 0) {
                const color = progress.percent === 100 ? '#0C9D61' : '#F1840D';
                html += '<div class="cm-progress-wrap" style="margin-bottom:25px;padding:20px;background:#f9f9f9;border-radius:10px;">';
                html += '<div style="display:flex;justify-content:space-between;margin-bottom:12px;">';
                html += '<span style="font-weight:600;">Your Progress</span>';
                html += '<span class="cm-progress-count" style="font-weight:600;color:' + color + ';">' + progress.attended + '/' + progress.total + '</span>';
                html += '</div>';
                html += '<div class="cm-progress-bar" style="height:10px;background:#e5e7eb;border-radius:10px;overflow:hidden;">';
                html += '<div class="cm-progress-fill" style="height:100%;width:' + progress.percent + '%;background:' + color + ';border-radius:10px;"></div>';
                html += '</div></div>';
            }
            
            html += '<div class="cm-sessions-list">';
            
            sessions.forEach(function(session, i) {
                const attended = session.attended == 1;
                const bg = attended ? '#f0fdf4' : '#f9fafb';
                const border = attended ? '#bbf7d0' : '#e5e7eb';
                const numBg = attended ? '#0C9D61' : '#2563eb';
                const title = session.session_title || ('Session ' + (i + 1));
                
                html += '<div class="cm-session' + (attended ? ' cm-session-attended' : '') + '" data-session-id="' + session.id + '" style="display:flex;align-items:center;gap:15px;padding:18px 20px;background:' + bg + ';border:1px solid ' + border + ';border-radius:10px;margin-bottom:12px;">';
                
                html += '<div style="width:36px;height:36px;border-radius:50%;background:' + numBg + ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;">' + (i + 1) + '</div>';
                
                html += '<div style="flex:1;">';
                html += '<div style="font-weight:600;">' + title + '</div>';
                if (session.session_date) {
                    html += '<div style="font-size:13px;color:#666;">' + session.session_date + '</div>';
                }
                html += '</div>';
                
                html += '<div>';
                if (attended) {
                    html += '<span style="display:inline-flex;align-items:center;gap:5px;padding:8px 14px;background:#d1fae5;color:#065f46;border-radius:20px;font-size:13px;font-weight:500;">' + (CM.strings.attended || '✓ تم الحضور') + '</span>';
                } else if (isLoggedIn && session.session_link) {
                    html += '<a href="' + session.session_link + '" class="cm-btn cm-mark-attended" data-session-id="' + session.id + '" target="_blank" style="display:inline-block;padding:10px 20px;background:#F1840D;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">' + (CM.strings.join_now || 'انضم الان') + '</a>';
                } else if (!isLoggedIn) {
                    html += '<a href="/login" style="display:inline-block;padding:10px 20px;background:#e5e7eb;color:#666;border-radius:8px;text-decoration:none;">Login</a>';
                }
                html += '</div>';
                
                html += '</div>';
            });
            
            html += '</div></div>';
            
            $container.html(html);
        },

        markAttended: function($btn) {
            const self = this;
            const sessionId = $btn.data('session-id');
            const $session = $btn.closest('.cm-session');
            const $wrap = $btn.closest('.cm-sessions-wrap');
            const courseId = $wrap.data('course-id');
            
            if ($btn.hasClass('loading')) return;
            
            $btn.addClass('loading').css('opacity', '0.7');
            
            $.ajax({
                url: CM.ajax_url,
                type: 'POST',
                data: {
                    action: 'cm_mark_attended',
                    nonce: CM.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    if (response.success) {
                        // Update UI
                        $session.addClass('cm-session-attended')
                            .css({
                                'background': '#f0fdf4',
                                'border-color': '#bbf7d0'
                            });
                        
                        $session.find('.cm-session-num, div:first-child').css('background', '#0C9D61');
                        
                        $btn.replaceWith('<span style="display:inline-flex;align-items:center;gap:5px;padding:8px 14px;background:#d1fae5;color:#065f46;border-radius:20px;font-size:13px;font-weight:500;">' + (CM.strings.attended || '✓ تم الحضور') + '</span>');
                        
                        // Update progress
                        if (response.data.progress) {
                            self.updateProgress($wrap, response.data.progress);
                        }
                    } else {
                        alert(response.data?.message || CM.strings.error || 'Error');
                        $btn.removeClass('loading').css('opacity', '1');
                    }
                },
                error: function() {
                    alert(CM.strings.error || 'Connection error');
                    $btn.removeClass('loading').css('opacity', '1');
                }
            });
        },

        updateProgress: function($wrap, progress) {
            const color = progress.percent === 100 ? '#0C9D61' : '#F1840D';
            
            $wrap.find('.cm-progress-count').text(progress.attended + '/' + progress.total).css('color', color);
            $wrap.find('.cm-progress-fill').css({
                'width': progress.percent + '%',
                'background': color
            });
            
            // Update mini progress in user courses
            const courseId = $wrap.data('course-id');
            $('.cm-user-course-card[data-course-id="' + courseId + '"]').find('.cm-mini-fill').css({
                'width': progress.percent + '%',
                'background': color
            });
        }
    };

    $(document).ready(function() {
        CM_Frontend.init();
    });

    window.CM_Frontend = CM_Frontend;

})(jQuery);