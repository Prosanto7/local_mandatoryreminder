<?php
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
 * Dashboard page showing queue status and summary
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/mandatoryreminder:viewdashboard', context_system::instance());

$PAGE->set_url(new moodle_url('/local/mandatoryreminder/dashboard.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('dashboard', 'local_mandatoryreminder'));
$PAGE->set_heading(get_string('dashboard', 'local_mandatoryreminder'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

// Get statistics.
$stats = local_mandatoryreminder_get_stats();

// Display summary.
echo html_writer::start_tag('div', ['class' => 'dashboard-summary']);

echo html_writer::tag('h3', get_string('summary', 'local_mandatoryreminder'));

$summarytable = new html_table();
$summarytable->attributes['class'] = 'generaltable';
$summarytable->data = [
    [get_string('total_mandatory_courses', 'local_mandatoryreminder'), $stats['total_courses']],
    [get_string('total_enrolled_users', 'local_mandatoryreminder'), $stats['total_users']],
    [get_string('total_incomplete', 'local_mandatoryreminder'), $stats['total_incomplete']],
    [get_string('emails_pending', 'local_mandatoryreminder'), $stats['emails_pending']],
    [get_string('emails_sent_today', 'local_mandatoryreminder'), $stats['emails_sent_today']],
    [get_string('emails_failed', 'local_mandatoryreminder'), $stats['emails_failed']],
];

echo html_writer::table($summarytable);

echo html_writer::end_tag('div');

// Display queue table.
echo html_writer::tag('h3', get_string('email_queue', 'local_mandatoryreminder'));

$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$queueitems = local_mandatoryreminder_get_queue($page, $perpage);
$totalcount = local_mandatoryreminder_count_queue();

if (empty($queueitems)) {
    echo $OUTPUT->notification(get_string('no_queue_items', 'local_mandatoryreminder'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('user'),
        get_string('course'),
        get_string('level', 'local_mandatoryreminder'),
        get_string('recipient_type', 'local_mandatoryreminder'),
        get_string('email'),
        get_string('status', 'local_mandatoryreminder'),
        get_string('attempts', 'local_mandatoryreminder'),
        get_string('created', 'local_mandatoryreminder'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($queueitems as $item) {
        $user = $DB->get_record('user', ['id' => $item->userid]);
        $course = get_course($item->courseid);
        
        $username = fullname($user);
        $coursename = format_string($course->fullname);
        
        $statusclass = 'badge ';
        switch ($item->status) {
            case 'sent':
                $statusclass .= 'badge-success';
                break;
            case 'failed':
                $statusclass .= 'badge-danger';
                break;
            case 'processing':
                $statusclass .= 'badge-warning';
                break;
            default:
                $statusclass .= 'badge-secondary';
        }
        
        $statusbadge = html_writer::tag('span', get_string('status_' . $item->status, 'local_mandatoryreminder'), 
            ['class' => $statusclass]);
        
        $table->data[] = [
            $username,
            $coursename,
            $item->level,
            get_string('recipient_' . $item->recipient_type, 'local_mandatoryreminder'),
            $item->recipient_email,
            $statusbadge,
            $item->attempts,
            userdate($item->timecreated, get_string('strftimedatetime')),
        ];
    }

    echo html_writer::table($table);

    // Pagination.
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
}

echo $OUTPUT->footer();

/**
 * Get statistics
 *
 * @return array Statistics
 */
function local_mandatoryreminder_get_stats() {
    global $DB;

    $stats = [];

    // Total mandatory courses.
    $stats['total_courses'] = count(local_mandatoryreminder_get_mandatory_courses());

    // Total enrolled users in mandatory courses.
    $mandatorycourses = local_mandatoryreminder_get_mandatory_courses();
    $totalusers = 0;
    $totalincomplete = 0;

    foreach ($mandatorycourses as $courseid) {
        $users = local_mandatoryreminder_get_incomplete_users($courseid);
        $totalincomplete += count($users);
        
        // Get all enrolled users.
        $context = context_course::instance($courseid);
        $enrolled = get_enrolled_users($context);
        $totalusers += count($enrolled);
    }

    $stats['total_users'] = $totalusers;
    $stats['total_incomplete'] = $totalincomplete;

    // Queue statistics.
    $stats['emails_pending'] = $DB->count_records('local_mandatoryreminder_queue', ['status' => 'pending']);
    
    $todaystart = strtotime('today');
    $stats['emails_sent_today'] = $DB->count_records_select('local_mandatoryreminder_queue', 
        'status = ? AND timesent >= ?', ['sent', $todaystart]);
    
    $stats['emails_failed'] = $DB->count_records('local_mandatoryreminder_queue', ['status' => 'failed']);

    return $stats;
}

/**
 * Get queue items
 *
 * @param int $page Page number
 * @param int $perpage Items per page
 * @return array Queue items
 */
function local_mandatoryreminder_get_queue($page, $perpage) {
    global $DB;

    return $DB->get_records('local_mandatoryreminder_queue', null, 'timecreated DESC', '*', 
        $page * $perpage, $perpage);
}

/**
 * Count queue items
 *
 * @return int Total count
 */
function local_mandatoryreminder_count_queue() {
    global $DB;

    return $DB->count_records('local_mandatoryreminder_queue');
}
