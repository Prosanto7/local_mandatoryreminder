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
 * Library functions for local_mandatoryreminder
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/completionlib.php');

/**
 * Get course deadline configuration
 *
 * @param int $courseid Course ID
 * @return int Deadline in days
 */
function local_mandatoryreminder_get_course_deadline($courseid) {
    global $DB;

    $config = $DB->get_record('local_mandatoryreminder_config', ['courseid' => $courseid]);
    
    if ($config) {
        return $config->deadline_days;
    }

    return get_config('local_mandatoryreminder', 'default_deadline') ?: 14;
}

/**
 * Set course deadline configuration
 *
 * @param int $courseid Course ID
 * @param int $deadline Deadline in days
 * @return bool Success
 */
function local_mandatoryreminder_set_course_deadline($courseid, $deadline) {
    global $DB;

    $now = time();
    $config = $DB->get_record('local_mandatoryreminder_config', ['courseid' => $courseid]);

    if ($config) {
        $config->deadline_days = $deadline;
        $config->timemodified = $now;
        return $DB->update_record('local_mandatoryreminder_config', $config);
    } else {
        $config = new stdClass();
        $config->courseid = $courseid;
        $config->deadline_days = $deadline;
        $config->timecreated = $now;
        $config->timemodified = $now;
        return $DB->insert_record('local_mandatoryreminder_config', $config) > 0;
    }
}

/**
 * Get the customfield_field record and the stored value that represents 'Mandatory'.
 *
 * Course custom fields are stored in {customfield_field} (definition) and
 * {customfield_data} (per-course values).  For a 'select' type field the value
 * column in {customfield_data} holds the 1-based option index as a string,
 * NOT the display label.  This helper resolves the correct stored value once
 * and caches it for the request lifetime to avoid repeated queries.
 *
 * @return array|false  ['fieldid' => int, 'mandatoryvalue' => string] or false
 */
function local_mandatoryreminder_get_mandatory_fieldinfo() {
    global $DB;
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    // {customfield_category} links field to component/area (core_course / course).
    $sql = "SELECT cf.id, cf.type, cf.configdata
              FROM {customfield_field} cf
              JOIN {customfield_category} cc ON cc.id = cf.categoryid
             WHERE cf.shortname  = :shortname
               AND cc.component  = 'core_course'
               AND cc.area       = 'course'";

    $field = $DB->get_record_sql($sql, ['shortname' => 'mandatory_status']);

    if (!$field) {
        $cache = false;
        return false;
    }

    // Determine what raw value is stored when 'Mandatory' is selected.
    if ($field->type === 'select') {
        // configdata JSON: {"options":"Mandatory\r\nOptional", ...}
        // {customfield_data}.value stores the 1-based option index as a string.
        $config  = json_decode($field->configdata, true);
        $optstr  = $config['options'] ?? '';
        $options = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $optstr))));

        $mandatoryvalue = null;
        foreach ($options as $idx => $label) {
            if (strtolower($label) === 'mandatory') {
                $mandatoryvalue = (string)($idx + 1); // 1-based index stored as text.
                break;
            }
        }

        if ($mandatoryvalue === null) {
            $cache = false;
            return false;
        }
    } else {
        // text / textarea fields store the literal value.
        $mandatoryvalue = 'Mandatory';
    }

    $cache = ['fieldid' => (int)$field->id, 'mandatoryvalue' => $mandatoryvalue];
    return $cache;
}

/**
 * Check if a course is mandatory
 *
 * Queries {customfield_data} and {customfield_field} directly instead of
 * going through the handler API, which avoids loading all custom-field
 * objects for every course.
 *
 * @param int $courseid Course ID
 * @return bool True if course is mandatory
 */
function local_mandatoryreminder_is_mandatory($courseid) {
    global $DB;

    $info = local_mandatoryreminder_get_mandatory_fieldinfo();
    if (!$info) {
        return false;
    }

    // {customfield_data}.instanceid = course id for core_course/course fields.
    $data = $DB->get_record('customfield_data', [
        'fieldid'    => $info['fieldid'],
        'instanceid' => $courseid,
    ], 'value');

    if (!$data || strval($data->value) === '') {
        return false;
    }

    return $data->value === $info['mandatoryvalue'];
}

/**
 * Get user custom field value
 *
 * @param int $userid User ID
 * @param string $shortname Custom field shortname
 * @return string|null Field value
 */
function local_mandatoryreminder_get_user_custom_field($userid, $shortname) {
    global $DB;

    $sql = "SELECT d.data
              FROM {user_info_data} d
              JOIN {user_info_field} f ON d.fieldid = f.id
             WHERE d.userid = :userid AND f.shortname = :shortname";

    $record = $DB->get_record_sql($sql, ['userid' => $userid, 'shortname' => $shortname]);
    
    return $record ? $record->data : null;
}

/**
 * Check if reminder has already been sent
 *
 * @param int $userid User ID
 * @param int $courseid Course ID
 * @param int $level Reminder level
 * @return bool True if reminder already sent
 */
function local_mandatoryreminder_is_sent($userid, $courseid, $level) {
    global $DB;

    return $DB->record_exists('local_mandatoryreminder_log', [
        'userid' => $userid,
        'courseid' => $courseid,
        'level' => $level
    ]);
}

/**
 * Log a sent reminder
 *
 * @param int $userid User ID
 * @param int $courseid Course ID
 * @param int $level Reminder level
 * @param int $enroldate Enrolment date timestamp
 * @param int $deadline Deadline timestamp
 * @return bool Success
 */
function local_mandatoryreminder_log_sent($userid, $courseid, $level, $enroldate, $deadline) {
    global $DB;

    $log = new stdClass();
    $log->userid = $userid;
    $log->courseid = $courseid;
    $log->level = $level;
    $log->enrolment_date = $enroldate;
    $log->deadline_date = $deadline;
    $log->sent_date = time();
    $log->timecreated = time();

    return $DB->insert_record('local_mandatoryreminder_log', $log) > 0;
}

/**
 * Get all mandatory courses
 *
 * Uses a single SQL JOIN on {customfield_data} and {customfield_field} instead
 * of iterating every course and calling is_mandatory() individually (N+1 queries).
 *
 * @return array Array of course IDs
 */
function local_mandatoryreminder_get_mandatory_courses() {
    global $DB;

    $info = local_mandatoryreminder_get_mandatory_fieldinfo();
    if (!$info) {
        return [];
    }

    // Single query: join course → customfield_data filtered by field + mandatory value.
    // {customfield_data}.value is LONGTEXT; use sql_compare_text() for cross-DB safety.
    $sql = "SELECT c.id
              FROM {course} c
              JOIN {customfield_data} cd
                ON cd.instanceid = c.id
               AND cd.fieldid    = :fieldid
             WHERE c.visible = 1
               AND c.id     != :siteid
               AND " . $DB->sql_compare_text('cd.value') . " = :mandatoryvalue";

    $courses = $DB->get_records_sql($sql, [
        'fieldid'       => $info['fieldid'],
        'siteid'        => SITEID,
        'mandatoryvalue' => $info['mandatoryvalue'],
    ]);

    return array_keys($courses);
}

/**
 * Get enrolled users who haven't completed a course
 *
 * @param int $courseid Course ID
 * @return array Array of user objects
 */
function local_mandatoryreminder_get_incomplete_users($courseid) {
    global $DB;

    $sql = "SELECT DISTINCT u.id, u.email, u.firstname, u.lastname, ue.timecreated as enroldate
              FROM {user} u
              JOIN {user_enrolments} ue ON ue.userid = u.id
              JOIN {enrol} e ON e.id = ue.enrolid
             WHERE e.courseid = :courseid
               AND u.deleted = 0
               AND u.suspended = 0
               AND e.status = 0
               AND ue.status = 0";

    $users = $DB->get_records_sql($sql, ['courseid' => $courseid]);

    if (empty($users)) {
        return [];
    }

    // Instantiate completion_info once outside the loop — it only depends on the course.
    $course = get_course($courseid);
    $completion = new completion_info($course);
    $completionenabled = $completion->is_enabled();

    // Filter out users who have already completed the course.
    $incompleteusers = [];
    foreach ($users as $user) {
        if ($completionenabled) {
            if (!$completion->is_course_complete($user->id)) {
                $incompleteusers[] = $user;
            }
        } else {
            // Completion tracking not enabled — treat all enrolled users as incomplete.
            $incompleteusers[] = $user;
        }
    }

    return $incompleteusers;
}

/**
 * Replace template placeholders with actual values
 *
 * @param string $template Email template
 * @param stdClass $user User object
 * @param stdClass $course Course object
 * @param int $daysoverdue Days overdue (negative if before deadline)
 * @return string Processed template
 */
function local_mandatoryreminder_process_template($template, $user, $course, $daysoverdue) {
    global $CFG;

    $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

    $replacements = [
        '{firstname}' => $user->firstname,
        '{lastname}' => $user->lastname,
        '{fullname}' => fullname($user),
        '{coursename}' => format_string($course->fullname),
        '{courseurl}' => $courseurl->out(false),
        '{courselink}' => html_writer::link($courseurl, format_string($course->fullname)),
        '{daysoverdue}' => abs($daysoverdue),
        '{sitename}' => format_string($CFG->fullname),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}
