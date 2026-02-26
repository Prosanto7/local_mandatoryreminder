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
 * Course deadline configuration page
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/mandatoryreminder:configure', context_system::instance());

$PAGE->set_url(new moodle_url('/local/mandatoryreminder/course_config.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('course_config', 'local_mandatoryreminder'));
$PAGE->set_heading(get_string('course_config', 'local_mandatoryreminder'));
$PAGE->set_pagelayout('admin');

// Handle form submission.
if (optional_param('action', '', PARAM_ALPHA) == 'update') {
    require_sesskey();
    
    $courseid = required_param('courseid', PARAM_INT);
    $deadline = required_param('deadline', PARAM_INT);
    
    if (local_mandatoryreminder_set_course_deadline($courseid, $deadline)) {
        redirect($PAGE->url, get_string('config_saved', 'local_mandatoryreminder'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        redirect($PAGE->url, get_string('config_error', 'local_mandatoryreminder'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();

// Get all mandatory courses.
$mandatorycourses = local_mandatoryreminder_get_mandatory_courses();

if (empty($mandatorycourses)) {
    echo $OUTPUT->notification(get_string('no_mandatory_courses', 'local_mandatoryreminder'), 'info');
} else {
    // Display table.
    $table = new html_table();
    $table->head = [
        get_string('course'),
        get_string('deadline_days', 'local_mandatoryreminder')
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($mandatorycourses as $courseid) {
        $course = get_course($courseid);
        $deadline = local_mandatoryreminder_get_course_deadline($courseid);
        
        $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
        $courselink = html_writer::link($courseurl, format_string($course->fullname));
        
        // Edit form.
        $editform = '<form method="post" action="' . $PAGE->url->out() . '" style="display:inline-block;">';
        $editform .= '<input type="hidden" name="action" value="update">';
        $editform .= '<input type="hidden" name="courseid" value="' . $courseid . '">';
        $editform .= '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        $editform .= '<input type="number" name="deadline" value="' . $deadline . '" min="1" max="365" style="width:80px;">';
        $editform .= ' <button type="submit" class="btn btn-sm btn-primary">' . get_string('save') . '</button>';
        $editform .= '</form>';
        
        $table->data[] = [
            $courselink,
            $editform,
            ''
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
