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
 * Centralised email-sending logic for mandatory reminders.
 *
 * All email building and dispatch previously scattered across process_queue.php
 * is consolidated here so it can be called from:
 *  - process_queue (batch cron/adhoc)
 *  - external web services (human-triggered single / bulk sends)
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_mandatoryreminder;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/mandatoryreminder/lib.php');

/**
 * Static helper that encapsulates email rendering, preview, and dispatch.
 */
class email_sender {

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Return the configured email template for a given level + recipient type.
     *
     * @param int    $level         Reminder level (1-4)
     * @param string $recipienttype 'employee' | 'supervisor' | 'sbuhead'
     * @return string Raw HTML template string
     */
    public static function get_template(int $level, string $recipienttype): string {
        // For consolidated emails, use new consolidated template keys.
        $key = 'consolidated_' . $recipienttype . '_template';

        $template = get_config('local_mandatoryreminder', $key);

        return (!empty($template))
            ? $template
            : get_string($key . '_default', 'local_mandatoryreminder');
    }

    /**
     * Return the email subject for consolidated emails.
     *
     * @param string $recipienttype 'employee' | 'supervisor' | 'sbuhead'
     * @param int $coursecount Number of courses in the email
     * @return string
     */
    public static function get_subject(string $recipienttype, int $coursecount = 1): string {
        return get_string(
            'email_subject_consolidated_' . $recipienttype,
            'local_mandatoryreminder',
            $coursecount
        );
    }

    /**
     * Pre-render consolidated employee email at queue time and return subject + body.
     *
     * @param \stdClass $user User object
     * @param array $coursesdata Array of course data objects
     * @return array ['subject' => string, 'body' => string]
     */
    public static function prerender_employee(\stdClass $user, array $coursesdata): array {
        global $CFG;

        $template = self::get_template(0, 'employee'); // Level doesn't matter for consolidated.
        $subject  = self::get_subject('employee', count($coursesdata));

        // Build course table sorted by level (Level 4 first).
        usort($coursesdata, function($a, $b) {
            return $b['level'] - $a['level'];
        });

        $coursetable = self::build_employee_course_table($coursesdata);

        $body = str_replace(
            ['{fullname}', '{firstname}', '{lastname}', '{course_table}', '{sitename}'],
            [fullname($user), $user->firstname, $user->lastname, $coursetable, format_string($CFG->fullname)],
            $template
        );

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Build the HTML course table for employee emails.
     *
     * @param array $coursesdata Array of course data objects
     * @return string HTML table
     */
    private static function build_employee_course_table(array $coursesdata): string {
        $html = '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
        $html .= '<thead><tr style="background-color: #f0f0f0;">';
        $html .= '<th>Escalation Level</th><th>Course Name</th><th>Deadline</th><th>Status</th><th>Action</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($coursesdata as $data) {
            $courseid = $data['courseid'];
            $coursename = isset($data['coursename']) ? $data['coursename'] : format_string(get_course($courseid)->fullname);
            $level = $data['level'];
            $daysoverdue = $data['daysoverdue'];
            
            $deadline = userdate($data['deadlinedate'], '%d %b %Y');
            
            // Status string.
            if ($daysoverdue < 0) {
                $status = 'Due in ' . abs(round($daysoverdue)) . ' days';
            } else {
                $status = '<strong style="color: red;">Overdue by ' . round($daysoverdue) . ' days</strong>';
            }

            // Convert level to escalation label.
            $leveltext = self::get_escalation_label($level);

            $courseurl = new \moodle_url('/course/view.php', ['id' => $courseid]);
            $action = '<a href="' . $courseurl->out(false) . '">Go to Course</a>';

            $html .= "<tr>";
            $html .= "<td>{$leveltext}</td>";
            $html .= "<td>{$coursename}</td>";
            $html .= "<td>{$deadline}</td>";
            $html .= "<td>{$status}</td>";
            $html .= "<td>{$action}</td>";
            $html .= "</tr>";
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Get escalation label for a given level.
     * @param int $level Level number (1-4)
     * @return string HTML formatted escalation label
     */
    private static function get_escalation_label(int $level): string {
        switch ($level) {
            case 1:
                return '<span style="color: orange;">First Escalation</span>';
            case 2:
                return '<span style="color: orange;">Second Escalation</span>';
            case 3:
                return '<span style="color: red;">Third Escalation</span>';
            case 4:
                return '<span style="color: darkred; font-weight: bold;">Final Escalation</span>';
            default:
                return "Escalation Level {$level}";
        }
    }

    /**
     * Build preview data (subject + body) for any queue item.
     *
     * @param \stdClass $item Queue row (all columns)
     * @return array ['subject' => string, 'body' => string]
     */
    public static function get_preview(\stdClass $item): array {
        global $DB, $CFG;

        // Parse courses data.
        $coursesdata = !empty($item->courses_data) ? json_decode($item->courses_data, true) : [];

        if (empty($coursesdata)) {
            return ['subject' => '', 'body' => 'No course data available'];
        }

        // --- Employee ---
        if ($item->recipient_type === 'employee') {
            // Check if pre-rendered.
            if (!empty($item->email_subject) && !empty($item->email_body)) {
                return ['subject' => $item->email_subject, 'body' => $item->email_body];
            }

            // Re-render.
            $user = $DB->get_record('user', ['id' => $item->userid]);
            if (!$user) {
                return ['subject' => '', 'body' => 'User not found'];
            }
            return self::prerender_employee($user, $coursesdata);
        }

        // --- Supervisor ---
        if ($item->recipient_type === 'supervisor') {
            $user = $DB->get_record('user', ['id' => $item->userid]);
            if (!$user) {
                return ['subject' => '', 'body' => 'User not found'];
            }

            $template = self::get_template(0, 'supervisor');
            $subject  = self::get_subject('supervisor', count($coursesdata));

            // Build employee table for supervisor.
            $employeetable = self::build_supervisor_employee_table($user, $coursesdata);

            $body = str_replace(
                ['{employee_table}', '{sitename}'],
                [$employeetable, format_string($CFG->fullname)],
                $template
            );

            return ['subject' => $subject, 'body' => $body];
        }

        // --- SBU Head ---
        if ($item->recipient_type === 'sbuhead') {
            // Get all employees under this SBU head.
            $employees = self::get_employees_for_sbuhead($item->recipient_email);

            $template = self::get_template(0, 'sbuhead');
            $subject  = self::get_subject('sbuhead', count($employees));

            // Build manager table.
            $managertable = self::build_sbuhead_manager_table($employees, $coursesdata);

            $body = str_replace(
                ['{manager_table}', '{sitename}'],
                [$managertable, format_string($CFG->fullname)],
                $template
            );

            return ['subject' => $subject, 'body' => $body];
        }

        return ['subject' => '', 'body' => ''];
    }

    /**
     * Send the email for a queue item.  Does NOT update the queue row status —
     * the caller is responsible for that.
     *
     * @param \stdClass $item Queue row
     * @return bool True on success
     */
    public static function send_item(\stdClass $item): bool {
        global $DB;

        $user = $DB->get_record('user', ['id' => $item->userid]);

        if (!$user) {
            return false;
        }

        // Get preview which handles consolidated courses_data JSON.
        $preview = self::get_preview($item);
        $subject = $preview['subject'];
        $body    = $preview['body'];

        switch ($item->recipient_type) {
            case 'employee':
                return self::mail_user($user, $subject, $body);

            case 'supervisor':
                return self::mail_supervisor($item, $subject, $body);

            case 'sbuhead':
                return self::mail_sbuhead($item, $subject, $body);
        }

        return false;
    }

    /**
     * After a management (supervisor/sbuhead) item is successfully sent, mark
     * all sibling queue rows (same recipient+type) as 'sent'.
     *
     * For consolidated emails, this is simpler - we just mark this one item as sent.
     *
     * @param \stdClass $item The item that was just successfully sent
     */
    public static function mark_siblings_sent(\stdClass $item): void {
        // For consolidated approach, there are no siblings since one email = one user.
        // This method is kept for compatibility but does nothing now.
        return;
    }

    /**
     * Send an in-app Moodle notification for an employee queue item.
     * Silently skipped for supervisor / sbuhead items.
     *
     * @param \stdClass $item Queue row
     * @return bool True on success (or when skipped)
     */
    public static function send_notification(\stdClass $item): bool {
        global $DB;

        if ($item->recipient_type !== 'employee') {
            return true;
        }

        $user = $DB->get_record('user', ['id' => $item->userid]);
        if (!$user) {
            return false;
        }

        // Parse courses data to get count and highest level.
        $coursesdata = !empty($item->courses_data) ? json_decode($item->courses_data, true) : [];
        if (empty($coursesdata)) {
            return false;
        }

        $coursecount = count($coursesdata);
        $highestlevel = max(array_column($coursesdata, 'level'));

        $message                   = new \core\message\message();
        $message->component        = 'local_mandatoryreminder';
        $message->name             = 'coursereminder';
        $message->userfrom         = \core_user::get_noreply_user();
        $message->userto           = $user;
        $message->subject          = get_string(
            'notification_subject_consolidated',
            'local_mandatoryreminder',
            $coursecount
        );
        $message->fullmessage      = get_string(
            'notification_message_consolidated',
            'local_mandatoryreminder',
            ['count' => $coursecount, 'level' => $highestlevel]
        );
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml  = '';
        $message->smallmessage     = get_string(
            'notification_small_consolidated',
            'local_mandatoryreminder',
            $coursecount
        );
        $message->notification     = 1;
        $message->contexturl       = (new \moodle_url('/local/mandatoryreminder/student_list.php'))->out(false);
        $message->contexturlname   = get_string('dashboard', 'local_mandatoryreminder');

        return message_send($message) !== false;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Send email directly to an employee Moodle user.
     */
    private static function mail_user(\stdClass $user, string $subject, string $body): bool {
        $noreply = \core_user::get_noreply_user();
        return email_to_user($user, $noreply, $subject, html_to_text($body), $body);
    }

    /**
     * Send an aggregated email to a supervisor, CC-ing the employee.
     */
    private static function mail_supervisor(\stdClass $item, string $subject, string $body): bool {
        global $DB;

        $noreply = \core_user::get_noreply_user();

        $recipient             = clone $noreply;
        $recipient->email      = $item->recipient_email;
        $recipient->firstname  = 'Supervisor';
        $recipient->lastname   = '';
        $recipient->mailformat = 1;

        $success = email_to_user($recipient, $noreply, $subject, html_to_text($body), $body);

        // CC the employee.
        $employee = $DB->get_record('user', ['id' => $item->userid]);
        if ($employee) {
            $cc             = clone $noreply;
            $cc->email      = $employee->email;
            $cc->firstname  = '';
            $cc->lastname   = '';
            $cc->mailformat = 1;
            email_to_user($cc, $noreply, '[CC] ' . $subject, html_to_text($body), $body);
        }

        return $success;
    }

    /**
     * Send an aggregated email to an SBU head, CC-ing the supervisor.
     */
    private static function mail_sbuhead(\stdClass $item, string $subject, string $body): bool {
        global $DB;

        $noreply = \core_user::get_noreply_user();

        $recipient             = clone $noreply;
        $recipient->email      = $item->recipient_email;
        $recipient->firstname  = 'SBU Head';
        $recipient->lastname   = '';
        $recipient->mailformat = 1;

        $success = email_to_user($recipient, $noreply, $subject, html_to_text($body), $body);

        // CC the supervisor.
        $supervisoremail = local_mandatoryreminder_get_user_custom_field($item->userid, 'SupervisorEmail');
        if ($supervisoremail && validate_email($supervisoremail)) {
            $cc             = clone $noreply;
            $cc->email      = $supervisoremail;
            $cc->firstname  = '';
            $cc->lastname   = '';
            $cc->mailformat = 1;
            email_to_user($cc, $noreply, '[CC] ' . $subject, html_to_text($body), $body);
        }

        return $success;
    }

    /**
     * Return the first enrolment record for a user in a course.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function get_enrolment(int $userid, int $courseid): ?\stdClass {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT ue.timecreated
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :uid AND e.courseid = :cid
              ORDER BY ue.timecreated ASC",
            ['uid' => $userid, 'cid' => $courseid],
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * Build the HTML employee table for supervisor emails.
     *
     * @param \stdClass $employee Employee user object
     * @param array $coursesdata Array of course data
     * @return string HTML table
     */
    private static function build_supervisor_employee_table(\stdClass $employee, array $coursesdata): string {
        $html = '<h3>' . fullname($employee) . ' (' . $employee->email . ')</h3>';
        $html .= '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
        $html .= '<thead><tr style="background-color: #f0f0f0;">';
        $html .= '<th>Escalation Level</th><th>Course Name</th><th>Deadline</th><th>Status</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($coursesdata as $data) {
            $courseid = $data['courseid'];
            $coursename = isset($data['coursename']) ? $data['coursename'] : format_string(get_course($courseid)->fullname);
            $level = $data['level'];
            $daysoverdue = $data['daysoverdue'];
            
            $deadline = userdate($data['deadlinedate'], '%d %b %Y');
            
            if ($daysoverdue < 0) {
                $status = 'Due in ' . abs(round($daysoverdue)) . ' days';
            } else {
                $status = '<strong style="color: red;">Overdue by ' . round($daysoverdue) . ' days</strong>';
            }

            // Use escalation label.
            $leveltext = self::get_escalation_label($level);

            $html .= "<tr>";
            $html .= "<td>{$leveltext}</td>";
            $html .= "<td>{$coursename}</td>";
            $html .= "<td>{$deadline}</td>";
            $html .= "<td>{$status}</td>";
            $html .= "</tr>";
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Get all employees under an SBU head.
     *
     * @param string $sbuheademail
     * @return array Array of employee data with their supervisors and courses
     */
    public static function get_employees_for_sbuhead(string $sbuheademail): array {
        global $DB;

        // Get all pending queue items for this SBU head.
        $queueitems = $DB->get_records_sql(
            "SELECT q.*, u.email, u.firstname, u.lastname,
                    u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename,
                    (SELECT d.data
                       FROM {user_info_data}  d
                       JOIN {user_info_field} f ON d.fieldid = f.id
                      WHERE d.userid = u.id AND f.shortname = 'SupervisorEmail'
                    ) AS supervisor_email
               FROM {local_mandatoryreminder_queue} q
               JOIN {user} u ON u.id = q.userid
              WHERE q.recipient_type  = 'sbuhead'
                AND q.recipient_email = :email
                AND q.status         IN ('pending', 'processing')",
            ['email' => $sbuheademail]
        );

        $employees = [];
        foreach ($queueitems as $item) {
            $employees[] = [
                'user' => $item,
                'supervisor_email' => $item->supervisor_email,
                'courses_data' => !empty($item->courses_data) ? json_decode($item->courses_data, true) : []
            ];
        }

        return $employees;
    }

    /**
     * Build the HTML manager+employee table for SBU head emails.
     *
     * @param array $employees Array of employee data
     * @param array $coursesdata Array of course data (not used, kept for compatibility)
     * @return string HTML table
     */
    private static function build_sbuhead_manager_table(array $employees, array $coursesdata): string {
        // Group employees by supervisor.
        $grouped = [];
        foreach ($employees as $empdata) {
            $supervisoremail = $empdata['supervisor_email'] ?: 'Unknown Supervisor';
            if (!isset($grouped[$supervisoremail])) {
                $grouped[$supervisoremail] = [];
            }
            $grouped[$supervisoremail][] = $empdata;
        }

        $html = '';
        foreach ($grouped as $supervisoremail => $emps) {
            $html .= '<h3>Supervisor: ' . s($supervisoremail) . '</h3>';
            
            foreach ($emps as $empdata) {
                $user = $empdata['user'];
                $courses = $empdata['courses_data'];
                
                // Sort by level.
                usort($courses, function($a, $b) {
                    return $b['level'] - $a['level'];
                });

                $html .= '<h4>' . fullname($user) . ' (' . $user->email . ')</h4>';
                $html .= '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; margin-bottom: 20px;">';
                $html .= '<thead><tr style="background-color: #f0f0f0;">';
                $html .= '<th>Escalation Level</th><th>Course Name</th><th>Deadline</th><th>Status</th>';
                $html .= '</tr></thead><tbody>';

                foreach ($courses as $data) {
                    $courseid = $data['courseid'];
                    $coursename = isset($data['coursename']) ? $data['coursename'] : format_string(get_course($courseid)->fullname);
                    $level = $data['level'];
                    $daysoverdue = $data['daysoverdue'];
                    
                    $deadline = userdate($data['deadlinedate'], '%d %b %Y');
                    
                    if ($daysoverdue < 0) {
                        $status = 'Due in ' . abs(round($daysoverdue)) . ' days';
                    } else {
                        $status = '<strong style="color: red;">Overdue by ' . round($daysoverdue) . ' days</strong>';
                    }

                    // Use escalation label.
                    $leveltext = self::get_escalation_label($level);

                    $html .= "<tr>";
                    $html .= "<td>{$leveltext}</td>";
                    $html .= "<td>{$coursename}</td>";
                    $html .= "<td>{$deadline}</td>";
                    $html .= "<td>{$status}</td>";
                    $html .= "</tr>";
                }

                $html .= '</tbody></table>';
            }
        }

        return $html;
    }
}
