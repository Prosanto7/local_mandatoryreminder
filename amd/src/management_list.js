// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AMD module for management reminder list actions.
 *
 * @module     local_mandatoryreminder/management_list
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/modal_factory', 'core/modal_events', 'core/notification', 'core/str'],
function($, ModalFactory, ModalEvents, Notification, Str) {

    var ajaxUrl = '';
    var sesskey = '';

    /**
     * Initialize the module.
     * @param {string} ajax Ajax URL
     * @param {string} key Session key
     */
    var init = function(ajax, key) {
        ajaxUrl = ajax;
        sesskey = key;

        setupSelectAll();
        setupPreview();
        setupSendSingle();
        setupSendSelected();
    };

    /**
     * Setup select-all checkbox functionality.
     */
    var setupSelectAll = function() {
        // Inject select-all checkbox into header
        $('#mr-management-table thead tr th:first-child').html(
            '<input type="checkbox" id="cb-select-all">'
        );

        $(document).on('change', '#cb-select-all', function() {
            $('.rowcheckbox').prop('checked', $(this).is(':checked'));
            updateBulkBar();
        });

        $(document).on('change', '.rowcheckbox', function() {
            var total = $('.rowcheckbox').length;
            var n = $('.rowcheckbox:checked').length;
            $('#cb-select-all').prop('indeterminate', n > 0 && n < total)
                               .prop('checked', n === total);
            updateBulkBar();
        });
    };

    /**
     * Update the bulk action bar visibility.
     */
    var updateBulkBar = function() {
        var n = $('.rowcheckbox:checked').length;
        if (n > 0) {
            Str.get_string('send_selected', 'local_mandatoryreminder').done(function(str) {
                $('#btn-send-selected').show().text(str + ' (' + n + ')');
            });
        } else {
            $('#btn-send-selected').hide();
        }
    };

    /**
     * Setup preview button handler.
     */
    var setupPreview = function() {
        $(document).on('click', '.btn-preview', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var btn = $(this);
            
            btn.prop('disabled', true);
            var originalText = btn.text();
            btn.text('...');

            $.get(ajaxUrl, {action: 'preview', id: id, sesskey: sesskey})
             .done(function(data) {
                if (data.success) {
                    showPreviewModal(data.subject, data.body);
                } else {
                    Notification.alert('Error', data.error || 'Preview failed');
                }
             })
             .fail(function() {
                Notification.alert('Error', 'Network error occurred');
             })
             .always(function() {
                btn.prop('disabled', false).text(originalText);
             });
        });
    };

    /**
     * Show preview modal with email content.
     * @param {string} subject Email subject
     * @param {string} body Email body HTML
     */
    var showPreviewModal = function(subject, body) {
        Str.get_strings([
            {key: 'preview_email', component: 'local_mandatoryreminder'},
            {key: 'subject', component: 'local_mandatoryreminder'}
        ]).done(function(strings) {
            var modalBody = '<div class="preview-content">' +
                '<h5>' + strings[1] + ':</h5>' +
                '<p class="alert alert-info">' + $('<div>').text(subject).html() + '</p>' +
                '<div class="preview-body">' + body + '</div>' +
                '</div>';

            ModalFactory.create({
                type: ModalFactory.types.DEFAULT,
                title: strings[0],
                body: modalBody,
                large: true
            }).done(function(modal) {
                modal.show();
            });
        });
    };

    /**
     * Setup single send button handler.
     */
    var setupSendSingle = function() {
        $(document).on('click', '.btn-send', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var btn = $(this);
            var row = btn.closest('tr');
            
            btn.prop('disabled', true);
            var originalText = btn.text();
            btn.text('...');

            $.post(ajaxUrl, {action: 'send', id: id, sesskey: sesskey})
             .done(function(data) {
                if (data.success) {
                    // Update status badge
                    row.find('.group-status-badge[data-rep="' + id + '"]')
                        .removeClass('badge-secondary badge-warning badge-danger')
                        .addClass('badge-success')
                        .text(data.status);
                    
                    // Update timesent
                    row.find('.timesent-cell[data-rep="' + id + '"]')
                        .removeClass('text-muted')
                        .text(data.timesent_formatted);
                    
                    // Remove checkbox and send button
                    row.find('.rowcheckbox').remove();
                    btn.remove();
                    
                    updateBulkBar();
                    Notification.addNotification({
                        message: 'Email sent successfully',
                        type: 'success'
                    });
                } else {
                    btn.prop('disabled', false).text(originalText);
                    Notification.alert('Error', data.error || 'Send failed');
                }
             })
             .fail(function() {
                btn.prop('disabled', false).text(originalText);
                Notification.alert('Error', 'Network error occurred');
             });
        });
    };

    /**
     * Setup send selected button handler.
     */
    var setupSendSelected = function() {
        $('#btn-send-selected').on('click', function(e) {
            e.preventDefault();
            var ids = $('.rowcheckbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            if (!ids.length) {
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true);

            $.post(ajaxUrl, {
                action: 'send_selected',
                'ids[]': ids,
                sesskey: sesskey
            })
             .done(function(data) {
                if (data.success) {
                    Notification.addNotification({
                        message: data.message,
                        type: 'success'
                    });
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    btn.prop('disabled', false);
                    Notification.alert('Error', data.error || 'Send failed');
                }
             })
             .fail(function() {
                btn.prop('disabled', false);
                Notification.alert('Error', 'Network error occurred');
             });
        });
    };

    return {
        init: init
    };
});
