/**
 * TCGiant Sync - Admin Dashboard JS
 *
 * Handles live status polling, log refresh, store category dropdown, and license management.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initStatusPolling();
        initLogPolling();
        initCategorySelector();
        initLicenseManager();
    });

    /* ─── Status Polling ─── */
    function initStatusPolling() {
        fetchSyncStatus();
        setInterval(fetchSyncStatus, 5000);
    }

    function fetchSyncStatus() {
        $.post(tcgiantSync.ajaxUrl, {
            action: 'tcgiant_sync_status',
            _ajax_nonce: tcgiantSync.nonce
        }, function(res) {
            if (!res.success) return;

            var s = res.data.state;
            var st = res.data.stats;
            var lic = res.data.license;

            // Dot class.
            $('.tc-sync-dot')
                .removeClass('idle scanning importing complete stopped error limit_reached')
                .addClass(s.status);

            // Status label.
            var label = 'Idle';
            var detail = '';
            switch (s.status) {
                case 'scanning':
                    label = 'Scanning eBay…';
                    detail = 'Page ' + s.current_page + (s.total_pages ? '/' + s.total_pages : '') + ' - ' + s.filter_name;
                    break;
                case 'importing':
                    label = 'Importing…';
                    detail = s.total_processed + '/' + s.total_queued + ' items';
                    break;
                case 'complete':
                    label = 'Complete';
                    detail = s.total_processed + ' imported, ' + s.total_errors + ' errors';
                    break;
                case 'stopped':
                    label = 'Stopped';
                    detail = 'Sync was manually stopped.';
                    break;
                case 'error':
                    label = 'Error';
                    detail = 'Check logs for details.';
                    break;
                case 'limit_reached':
                    label = 'Import Limit Reached';
                    detail = lic.active_count + '/' + lic.free_limit + ' products - Upgrade to Pro for unlimited';
                    break;
                default:
                    label = 'Idle';
                    detail = s.last_completed ? 'Last: ' + s.last_completed : 'No sync has run yet.';
            }
            $('#tc-hero-status').text(label);
            $('#tc-hero-detail').text(detail);

            // Stats.
            $('#tc-stat-synced').text(st.synced_products);
            $('#tc-stat-pending').text(st.pending_jobs);
            $('#tc-stat-queued').text(s.total_queued || '0');

            // Last item.
            if (s.last_item_title && (s.status === 'importing' || s.status === 'complete')) {
                var $last = $('.tc-last-item');
                if ($last.length) {
                    $('#tc-last-item-title').text(s.last_item_title);
                    $last.show();
                }
            }

            // Progress bar.
            var $prog = $('#tc-progress');
            if (s.status === 'importing' && s.total_queued > 0) {
                var pct = Math.round(((s.total_processed + s.total_errors) / s.total_queued) * 100);
                $prog.show().find('.tc-progress-fill').css('width', pct + '%');
                $prog.find('.tc-progress-text').text(pct + '%');
            } else {
                $prog.hide();
            }

            // Update usage meter live.
            if (lic) {
                updateUsageMeter(lic);
            }

            // Update fetch button state based on license.
            var $fetchBtn = $('#tc-fetch-btn');
            if ($fetchBtn.length) {
                if (lic && !lic.can_import) {
                    $fetchBtn.prop('disabled', true).css({opacity: 0.5, cursor: 'not-allowed'});
                } else {
                    $fetchBtn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'});
                }
            }
        });
    }

    /**
     * Update the usage meter UI with live license data.
     */
    function updateUsageMeter(lic) {
        if (lic.is_pro) return; // Pro users see static "Unlimited" text.

        var $count = $('#tc-usage-count');
        var $remaining = $('#tc-usage-remaining');
        var $fill = $('#tc-usage-fill');

        if ($count.length) {
            $count.text(lic.active_count);
        }
        if ($remaining.length) {
            var rem = lic.remaining;
            $remaining.text(rem + ' remaining');
        }
        if ($fill.length) {
            $fill.css('width', lic.usage_pct + '%');
            $fill.removeClass('tc-usage-warning tc-usage-critical');
            if (lic.usage_pct >= 90) {
                $fill.addClass('tc-usage-critical');
            } else if (lic.usage_pct >= 70) {
                $fill.addClass('tc-usage-warning');
            }
        }
    }

    /* ─── Log Polling ─── */
    function initLogPolling() {
        fetchLogs();
        setInterval(fetchLogs, 8000);
    }

    function fetchLogs() {
        $.post(tcgiantSync.ajaxUrl, {
            action: 'tcgiant_get_logs',
            _ajax_nonce: tcgiantSync.nonce
        }, function(res) {
            if (!res.success) return;
            $('#tc-log-content').html(res.data.html);
        });
    }

    /* ─── License Manager ─── */
    function initLicenseManager() {
        // Activate license.
        $(document).on('click', '#tc-activate-license', function() {
            var $btn = $(this);
            var $input = $('#tc-license-key');
            var key = $input.val().trim();
            var $msg = $('#tc-license-message');

            if (!key) {
                showLicenseMsg($msg, 'Please enter a license key.', 'error');
                return;
            }

            $btn.prop('disabled', true).text('Activating…');
            $msg.hide();

            $.post(tcgiantSync.ajaxUrl, {
                action: 'tcgiant_activate_license',
                _ajax_nonce: tcgiantSync.nonce,
                license_key: key
            }, function(res) {
                $btn.prop('disabled', false).text('Activate');

                if (res.success) {
                    showLicenseMsg($msg, res.data.message, 'success');
                    // Reload the page after a short delay to show the Pro state.
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showLicenseMsg($msg, res.data.message || 'Activation failed.', 'error');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Activate');
                showLicenseMsg($msg, 'Network error. Please try again.', 'error');
            });
        });

        // Handle Enter key in license input.
        $(document).on('keypress', '#tc-license-key', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#tc-activate-license').click();
            }
        });

        // Deactivate license.
        $(document).on('click', '#tc-deactivate-license', function() {
            if (!confirm('Are you sure you want to deactivate your Pro license? You will be limited to 50 active products.')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Deactivating…');

            $.post(tcgiantSync.ajaxUrl, {
                action: 'tcgiant_deactivate_license',
                _ajax_nonce: tcgiantSync.nonce
            }, function(res) {
                if (res.success) {
                    // Reload to show free state.
                    location.reload();
                } else {
                    $btn.prop('disabled', false).text('Deactivate');
                    alert(res.data.message || 'Deactivation failed.');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Deactivate');
                alert('Network error. Please try again.');
            });
        });
    }

    function showLicenseMsg($el, msg, type) {
        $el.text(msg)
           .removeClass('tc-msg-error tc-msg-success')
           .addClass(type === 'error' ? 'tc-msg-error' : 'tc-msg-success')
           .slideDown(200);
    }

    /* ─── Category Selector ─── */
    function initCategorySelector() {
        var $container = $('#tc-category-selector');
        if (!$container.length) return;

        var $hidden   = $('#tc-category-hidden');
        var $tags     = $('#tc-category-tags');
        var $dropdown = $('#tc-category-dropdown');
        var $loadBtn  = $('#tc-load-categories');

        var current = ($hidden.val() || '').split(',').map(function(s) { return s.trim(); }).filter(Boolean);
        renderTags(current);

        $loadBtn.on('click', function(e) {
            e.preventDefault();
            $(this).text('Loading…').prop('disabled', true);

            $.post(tcgiantSync.ajaxUrl, {
                action: 'tcgiant_get_store_categories',
                _ajax_nonce: tcgiantSync.nonce
            }, function(res) {
                $loadBtn.text('Load eBay Categories').prop('disabled', false);
                if (!res.success) {
                    alert(res.data.message || 'Failed to load categories.');
                    return;
                }
                $dropdown.empty().append('<option value="">- Select a category -</option>');
                res.data.categories.forEach(function(cat) {
                    $dropdown.append('<option value="' + esc(cat.raw) + '">' + esc(cat.name) + '</option>');
                });
                $dropdown.show().focus();
            });
        });

        $dropdown.on('change', function() {
            var val = $(this).val();
            if (!val) return;
            var curr = ($hidden.val() || '').split(',').map(function(s) { return s.trim(); }).filter(Boolean);
            if (curr.indexOf(val) === -1) {
                curr.push(val);
                $hidden.val(curr.join(', '));
                renderTags(curr);
            }
            $(this).val('');
        });

        $tags.on('click', '.remove', function() {
            var name = $(this).data('name');
            var curr = ($hidden.val() || '').split(',').map(function(s) { return s.trim(); }).filter(Boolean);
            curr = curr.filter(function(s) { return s !== name; });
            $hidden.val(curr.join(', '));
            renderTags(curr);
        });

        function renderTags(list) {
            $tags.empty();
            list.forEach(function(name) {
                if (!name) return;
                $tags.append(
                    '<span class="tc-category-tag">' + esc(name) +
                    ' <span class="remove" data-name="' + esc(name) + '">×</span></span>'
                );
            });
        }

        function esc(str) { return $('<span>').text(str).html(); }
    }

})(jQuery);
