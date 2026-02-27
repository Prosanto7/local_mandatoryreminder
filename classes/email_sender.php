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
 *  - ajax.php (human-triggered single / bulk sends)
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
        // Levels 1 & 2 only target employees; their config keys carry no type suffix.
        $key = ($level <= 2)
            ? 'level' . $level . '_template'
            : 'level' . $level . '_' . $recipienttype . '_template';

        $template = get_config('local_mandatoryreminder', $key);

        return (!empty($template))
            ? $template
            : get_string($key . '_default', 'local_mandatoryreminder');
    }

    /**
     * Return the email subject for a given level and course.
     *
     * @param int       $level  Reminder level
     * @param \stdClass $course Course object (needs ->fullname)
     * @return string
     */
    public static function get_subject(int $level, \stdClass $course): string {
        return get_string(
            'email_subject_level' . $level,
            'local_mandatoryreminder',
            format_string($course->fullname)
        );
    }

    /**
     * Pre-render employee email at queue time and return subject + body.
     *
     * This result is stored in the queue row so preview and dispatch can use the
     * static DB value instead of re-running template processing on every request.
     *
     * @param \stdClass $user        Employee user object
     * @param \stdClass $course      Course object
     * @param int       $level       Reminder level
     * @param float     $daysoverdue Days diff (negative = before deadline)
     * @return array ['subject' => string, 'body' => string]
     */
    public static function prerender_employee(
        \stdClass $user,
        \stdClass $course,
        int $level,
        float $daysoverdue
    ): array {
        $template = self::get_template($level, 'employee');
        $body     = local_mandatoryreminder_process_template($template, $user, $course, $daysoverdue);
        $subject  = self::get_subject($level, $course);
        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Build preview data (subject + body) for any queue item.
     *
     * Employee items: returns the pre-stored DB body if available; otherwise
     * re-computes it.  Supervisor / SBU-head items always compute dynamically
     * from the current set of pending employees so the preview stays accurate.
     *
     * @param \stdClass $item Queue row (all columns)
     * @return array ['subject' => string, 'body' => string]
     */
    public static function get_preview(\stdClass $item): array {
        global $DB, $CFG;

        $course = get_course($item->courseid);

        // --- Employee ---
        if ($item->recipient_type === 'employee') {
            if (!empty($item->email_subject) && !empty($item->email_body)) {
                return ['subject' => $item->email_subject, 'body' => $item->email_body];
            }
            // Fallback: recompute (covers rows created before the schema upgrade).
            $user = $DB->get_record('user', ['id' => $item->userid]);
            if (!$user) {
                return ['subject' => '', 'body' => ''];
            }
            $enrol       = self::get_enrolment($item->userid, $item->courseid);
            $deadline    = local_mandatoryreminder_get_course_deadline($item->courseid);
            $ddatestamp  = $enrol ? ($enrol->timecreated + $deadline * 86400) : time();
            $daysoverdue = (time() - $ddatestamp) / 86400;
            return self::prerender_employee($user, $course, $item->level, $daysoverdue);
        }

        // --- Supervisor ---
        if ($item->recipient_type === 'supervisor') {
            $employees = self::get_employees_for_supervisor(
                $item->recipient_email, $item->courseid, $item->level
            );
            $table    = self::build_employee_table($employees, $course);
            $template = self::get_template($item->level, 'supervisor');
            $body     = str_replace(
                ['{employee_table}', '{sitename}'],
                [$table, format_string($CFG->fullname)],
                $template
            );
            return ['subject' => self::get_subject($item->level, $course), 'body' => $body];
        }

        // --- SBU Head ---
        if ($item->recipient_type === 'sbuhead') {
            $managers = self::get_managers_for_sbuhead(
                $item->recipient_email, $item->courseid, $item->level
            );
            $table    = self::build_manager_table($managers, $course);
            $template = self::get_template($item->level, 'sbuhead');
            $body     = str_replace(
                ['{manager_table}', '{sitename}'],
                [$table, format_string($CFG->fullname)],
                $template
            );
            return ['subject' => self::get_subject($item->level, $course), 'body' => $body];
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

        $user   = $DB->get_record('user', ['id' => $item->userid]);
        $course = get_course($item->courseid);

        if (!$user || !$course) {
            return false;
        }

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
     * all sibling queue rows (same recipient+course+level+type) as 'sent'.
     *
     * This prevents multiple emails going out to the same supervisor when the
     * batch contains several employee rows pointing to the same supervisor.
     *
     * @param \stdClass $item The item that was just successfully sent
     */
    public static function mark_siblings_sent(\stdClass $item): void {
        global $DB;

        if ($item->recipient_type === 'employee') {
            return; // No siblings for employee items.
        }

        $now = time();
        $DB->execute(
            "UPDATE {local_mandatoryreminder_queue}
                SET status = 'sent', timesent = :timesent, timemodified = :timemod
              WHERE recipient_type  = :rtype
                AND recipient_email = :remail
                AND courseid        = :courseid
                AND level           = :level
                AND status         IN ('pending', 'processing')
                AND id             != :id",
            [
                'timesent' => $now,
                'timemod'  => $now,
                'rtype'    => $item->recipient_type,
                'remail'   => $item->recipient_email,
                'courseid' => $item->courseid,
                'level'    => $item->level,
                'id'       => $item->id,
            ]
        );
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

        $user   = $DB->get_record('user', ['id' => $item->userid]);
        $course = get_course($item->courseid);

        if (!$user || !$course) {
            return false;
        }

        $message                   = new \core\message\message();
        $message->component        = 'local_mandatoryreminder';
        $message->name             = 'coursereminder';
        $message->userfrom         = \core_user::get_noreply_user();
        $message->userto           = $user;
        $message->subject          = get_string(
            'notification_subject_level' . $item->level,
            'local_mandatoryreminder',
            format_string($course->fullname)
        );
        $message->fullmessage      = get_string(
            'notification_message_level' . $item->level,
            'local_mandatoryreminder',
            format_string($course->fullname)
        );
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml  = '';
        $message->smallmessage     = get_string(
            'notification_small_level' . $item->level,
            'local_mandatoryreminder'
        );
        $message->notification     = 1;
        $message->contexturl       = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $message->contexturlname   = format_string($course->fullname);

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
     * Send an aggregated email to a supervisor, CC-ing each employee.
     */
    private static function mail_supervisor(\stdClass $item, string $subject, string $body): bool {
        $noreply = \core_user::get_noreply_user();

        $recipient             = clone $noreply;
        $recipient->email      = $item->recipient_email;
        $recipient->firstname  = 'Supervisor';
        $recipient->lastname   = '';
        $recipient->mailformat = 1;

        $success = email_to_user($recipient, $noreply, $subject, html_to_text($body), $body);

        // CC each employee.
        $employees = self::get_employees_for_supervisor(
            $item->recipient_email, $item->courseid, $item->level
        );
        foreach ($employees as $emp) {
            $cc             = clone $noreply;
            $cc->email      = $emp->email;
            $cc->firstname  = '';
            $cc->lastname   = '';
            $cc->mailformat = 1;
            email_to_user($cc, $noreply, '[CC] ' . $subject, html_to_text($body), $body);
        }

        return $success;
    }

    /**
     * Send an aggregated email to an SBU head, CC-ing each supervisor (manager).
     */
    private static function mail_sbuhead(\stdClass $item, string $subject, string $body): bool {
        $noreply = \core_user::get_noreply_user();

        $recipient             = clone $noreply;
        $recipient->email      = $item->recipient_email;
        $recipient->firstname  = 'SBU Head';
        $recipient->lastname   = '';
        $recipient->mailformat = 1;

        $success = email_to_user($recipient, $noreply, $subject, html_to_text($body), $body);

        // CC each unique supervisor (manager).
        $managers  = self::get_managers_for_sbuhead($item->recipient_email, $item->courseid, $item->level);
        $ccemails  = [];
        foreach ($managers as $mgr) {
            if (!empty($mgr['supervisor_email']) && !in_array($mgr['supervisor_email'], $ccemails)) {
                $ccemails[] = $mgr['supervisor_email'];
            }
        }
        foreach ($ccemails as $ccemail) {
            $cc             = clone $noreply;
            $cc->email      = $ccemail;
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
     * Get all employees under a supervisor who have pending/processing queue rows
     * for the given course+level (used for aggregated supervisor email body).
     *
     * @param string $supervisoremail
     * @param int    $courseid
     * @param int    $level
     * @return array Keyed by user ID
     */
    public static function get_employees_for_supervisor(
        string $supervisoremail,
        int $courseid,
        int $level
    ): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.email, u.firstname, u.lastname,
                             u.firstnamephonetic, u.lastnamephonetic,
                             u.middlename, u.alternatename
               FROM {local_mandatoryreminder_queue} q
               JOIN {user} u ON u.id = q.userid
              WHERE q.courseid        = :courseid
                AND q.level           = :level
                AND q.recipient_type  = 'supervisor'
                AND q.recipient_email = :email
                AND q.status         IN ('pending', 'processing')",
            ['courseid' => $courseid, 'level' => $level, 'email' => $supervisoremail]
        );
    }

    /**
     * Get all employees under an SBU head (grouped by their supervisor) who have
     * pending/processing queue rows for the given course+level.
     *
     * @param string $sbuheademail
     * @param int    $courseid
     * @param int    $level
     * @return array Keyed by supervisor_email; each value: ['supervisor_email', 'employees' => []]
     */
    public static function get_managers_for_sbuhead(
        string $sbuheademail,
        int $courseid,
        int $level
    ): array {
        global $DB;

        $employees = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.email, u.firstname, u.lastname,
                             u.firstnamephonetic, u.lastnamephonetic,
                             u.middlename, u.alternatename,
                    (SELECT d.data
                       FROM {user_info_data}  d
                       JOIN {user_info_field} f ON d.fieldid = f.id
                      WHERE d.userid = u.id AND f.shortname = 'SupervisorEmail'
                    ) AS supervisor_email
               FROM {local_mandatoryreminder_queue} q
               JOIN {user} u ON u.id = q.userid
              WHERE q.courseid        = :courseid
                AND q.level           = :level
                AND q.recipient_type  = 'sbuhead'
                AND q.recipient_email = :email
                AND q.status         IN ('pending', 'processing')",
            ['courseid' => $courseid, 'level' => $level, 'email' => $sbuheademail]
        );

        $managers = [];
        foreach ($employees as $emp) {
            $key = $emp->supervisor_email ?: '';
            if (!isset($managers[$key])) {
                $managers[$key] = ['supervisor_email' => $key, 'employees' => []];
            }
            $managers[$key]['employees'][] = $emp;
        }

        return $managers;
    }

    /**
     * Build the HTML employee list inserted into supervisor templates via {employee_table}.
     */
    public static function build_employee_table(array $employees, \stdClass $course): string {
        $html  = '<p><strong>' . format_string($course->fullname) . '</strong></p>';
        $html .= '<ul>';
        foreach ($employees as $emp) {
            $html .= '<li>' . fullname($emp) . ' (' . $emp->email . ')</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Build the HTML manager+employee table inserted into SBU head templates via {manager_table}.
     */
    public static function build_manager_table(array $managers, \stdClass $course): string {
        $html = '<p><strong>' . format_string($course->fullname) . '</strong></p>';
        foreach ($managers as $mgr) {
            $html .= '<p><strong>Manager: ' . s($mgr['supervisor_email']) . '</strong></p><ul>';
            foreach ($mgr['employees'] as $emp) {
                $html .= '<li>' . fullname($emp) . ' (' . $emp->email . ')</li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }
}
