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
 * Ad-hoc task to process email queue
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_mandatoryreminder\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/mandatoryreminder/lib.php');

/**
 * Process queue task
 */
class process_queue extends \core\task\adhoc_task {

    /**
     * Execute task
     */
    public function execute() {
        global $DB;

        mtrace('Processing mandatory reminder email queue...');

        $batchsize = get_config('local_mandatoryreminder', 'email_batch_size') ?: 50;

        // Get pending emails.
        $queueitems = $DB->get_records('local_mandatoryreminder_queue', 
            ['status' => 'pending'], 
            'timecreated ASC', 
            '*', 
            0, 
            $batchsize
        );

        mtrace('Processing ' . count($queueitems) . ' queued emails');

        foreach ($queueitems as $item) {
            // Mark as processing.
            $item->status = 'processing';
            $item->timemodified = time();
            $DB->update_record('local_mandatoryreminder_queue', $item);

            try {
                $success = $this->send_reminder_email($item);

                if ($success) {
                    $item->status = 'sent';
                    $item->timesent = time();
                    
                    // Also send notification.
                    $this->send_notification($item);
                } else {
                    $item->status = 'failed';
                    $item->attempts++;
                    $item->error_message = 'Failed to send email';
                }
            } catch (\Exception $e) {
                $item->status = 'failed';
                $item->attempts++;
                $item->error_message = $e->getMessage();
                mtrace('Error sending email: ' . $e->getMessage());
            }

            $item->timemodified = time();
            $DB->update_record('local_mandatoryreminder_queue', $item);
        }

        // If there are more pending emails, queue another task.
        $remaining = $DB->count_records('local_mandatoryreminder_queue', ['status' => 'pending']);
        
        if ($remaining > 0) {
            mtrace("Still {$remaining} emails pending, queuing next batch...");
            $task = new \local_mandatoryreminder\task\process_queue();
            \core\task\manager::queue_adhoc_task($task);
        }

        mtrace('Email queue processing completed');
    }

    /**
     * Send reminder email
     *
     * @param stdClass $item Queue item
     * @return bool Success
     */
    private function send_reminder_email($item) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $item->userid]);
        $course = get_course($item->courseid);

        if (!$user || !$course) {
            return false;
        }

        // Get enrolment date for calculating days overdue.
        // Use $limitnum=1 instead of a raw LIMIT clause for cross-database compatibility.
        $enrolrecords = $DB->get_records_sql(
            "SELECT ue.timecreated
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid AND e.courseid = :courseid
              ORDER BY ue.timecreated ASC",
            ['userid' => $user->id, 'courseid' => $course->id],
            0,
            1
        );
        $enrol = $enrolrecords ? reset($enrolrecords) : null;

        if (!$enrol) {
            // No enrolment record found; skip this item.
            return false;
        }

        $deadline = local_mandatoryreminder_get_course_deadline($course->id);
        $deadlinedate = $enrol->timecreated + ($deadline * 24 * 60 * 60);
        $daysoverdue = (time() - $deadlinedate) / (24 * 60 * 60);

        // Get appropriate template.
        $template = $this->get_email_template($item->level, $item->recipient_type);
        
        // Process template.
        $body = local_mandatoryreminder_process_template($template, $user, $course, $daysoverdue);

        // Get subject.
        $subject = $this->get_email_subject($item->level, $course);

        // Prepare email based on recipient type.
        $success = false;
        if ($item->recipient_type == 'employee') {
            $success = $this->send_email_to_user($user, $subject, $body);
        } else if ($item->recipient_type == 'supervisor') {
            $success = $this->send_email_to_supervisor($item, $user, $course, $subject, $body);
        } else if ($item->recipient_type == 'sbuhead') {
            $success = $this->send_email_to_sbuhead($item, $user, $course, $subject, $body);
        }

        return $success;
    }

    /**
     * Send email to user
     *
     * @param stdClass $user User object
     * @param string $subject Email subject
     * @param string $body Email body
     * @return bool Success
     */
    private function send_email_to_user($user, $subject, $body) {
        global $CFG;

        $noreplyuser = \core_user::get_noreply_user();
        
        return email_to_user($user, $noreplyuser, $subject, html_to_text($body), $body);
    }

    /**
     * Send email to supervisor (with grouped employees)
     *
     * @param stdClass $item Queue item
     * @param stdClass $user User object
     * @param stdClass $course Course object
     * @param string $subject Email subject
     * @param string $body Email body
     * @return bool Success
     */
    private function send_email_to_supervisor($item, $user, $course, $subject, $body) {
        global $DB, $CFG;

        // Get all employees under this supervisor for this course.
        $employees = $this->get_employees_by_supervisor($item->recipient_email, $course->id, $item->level);

        // Build employee table.
        $employeetable = $this->build_employee_table($employees, $course);
        
        // Replace placeholder in body.
        $body = str_replace('{employee_table}', $employeetable, $body);

        // Prepare CC list.
        $ccemails = [];
        foreach ($employees as $emp) {
            $ccemails[] = $emp->email;
        }

        // Create a temporary user object for supervisor email.
        $supervisor = new \stdClass();
        $supervisor->email = $item->recipient_email;
        $supervisor->firstname = '';
        $supervisor->lastname = '';
        $supervisor->id = -1;
        $supervisor->mailformat = 1;
        $supervisor->deleted = 0;
        $supervisor->suspended = 0;

        $noreplyuser = \core_user::get_noreply_user();

        // Send main email to supervisor.
        $success = email_to_user($supervisor, $noreplyuser, $subject, html_to_text($body), $body);

        // Send CC emails.
        foreach ($ccemails as $ccemail) {
            $ccuser = new \stdClass();
            $ccuser->email = $ccemail;
            $ccuser->firstname = '';
            $ccuser->lastname = '';
            $ccuser->id = -1;
            $ccuser->mailformat = 1;
            $ccuser->deleted = 0;
            $ccuser->suspended = 0;
            
            email_to_user($ccuser, $noreplyuser, "[CC] " . $subject, html_to_text($body), $body);
        }

        return $success;
    }

    /**
     * Send email to SBU head (with grouped managers and employees)
     *
     * @param stdClass $item Queue item
     * @param stdClass $user User object
     * @param stdClass $course Course object
     * @param string $subject Email subject
     * @param string $body Email body
     * @return bool Success
     */
    private function send_email_to_sbuhead($item, $user, $course, $subject, $body) {
        global $DB, $CFG;

        // Get all managers and their employees under this SBU head for this course.
        $managersdata = $this->get_managers_by_sbuhead($item->recipient_email, $course->id, $item->level);

        // Build manager-employee table.
        $managertable = $this->build_manager_table($managersdata, $course);
        
        // Replace placeholder in body.
        $body = str_replace('{manager_table}', $managertable, $body);

        // Prepare CC list (only managers).
        $ccemails = [];
        foreach ($managersdata as $manager) {
            if (!in_array($manager['supervisor_email'], $ccemails)) {
                $ccemails[] = $manager['supervisor_email'];
            }
        }

        // Create a temporary user object for SBU head email.
        $sbuhead = new \stdClass();
        $sbuhead->email = $item->recipient_email;
        $sbuhead->firstname = '';
        $sbuhead->lastname = '';
        $sbuhead->id = -1;
        $sbuhead->mailformat = 1;
        $sbuhead->deleted = 0;
        $sbuhead->suspended = 0;

        $noreplyuser = \core_user::get_noreply_user();

        // Send main email to SBU head.
        $success = email_to_user($sbuhead, $noreplyuser, $subject, html_to_text($body), $body);

        // Send CC emails to managers.
        foreach ($ccemails as $ccemail) {
            $ccuser = new \stdClass();
            $ccuser->email = $ccemail;
            $ccuser->firstname = '';
            $ccuser->lastname = '';
            $ccuser->id = -1;
            $ccuser->mailformat = 1;
            $ccuser->deleted = 0;
            $ccuser->suspended = 0;
            
            email_to_user($ccuser, $noreplyuser, "[CC] " . $subject, html_to_text($body), $body);
        }

        return $success;
    }

    /**
     * Get employees by supervisor
     *
     * @param string $supervisoremail Supervisor email
     * @param int $courseid Course ID
     * @param int $level Reminder level
     * @return array Array of user objects
     */
    private function get_employees_by_supervisor($supervisoremail, $courseid, $level) {
        global $DB;

        $sql = "SELECT DISTINCT u.id, u.email, u.firstname, u.lastname, q.userid, q.courseid
                  FROM {local_mandatoryreminder_queue} q
                  JOIN {user} u ON u.id = q.userid
                 WHERE q.courseid = :courseid
                   AND q.level = :level
                   AND q.recipient_type = 'supervisor'
                   AND q.recipient_email = :supervisoremail
                   AND q.status IN ('pending', 'processing')";

        return $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'level' => $level,
            'supervisoremail' => $supervisoremail
        ]);
    }

    /**
     * Get managers and their employees by SBU head
     *
     * @param string $sbuheademail SBU head email
     * @param int $courseid Course ID
     * @param int $level Reminder level
     * @return array Array of manager data
     */
    private function get_managers_by_sbuhead($sbuheademail, $courseid, $level) {
        global $DB;

        $sql = "SELECT DISTINCT u.id, u.email, u.firstname, u.lastname, q.userid, q.courseid,
                       (SELECT d.data
                          FROM {user_info_data} d
                          JOIN {user_info_field} f ON d.fieldid = f.id
                         WHERE d.userid = u.id AND f.shortname = 'SupervisorEmail') as supervisor_email
                  FROM {local_mandatoryreminder_queue} q
                  JOIN {user} u ON u.id = q.userid
                 WHERE q.courseid = :courseid
                   AND q.level = :level
                   AND q.recipient_type = 'sbuhead'
                   AND q.recipient_email = :sbuheademail
                   AND q.status IN ('pending', 'processing')";

        $employees = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'level' => $level,
            'sbuheademail' => $sbuheademail
        ]);

        // Group by supervisor.
        $managers = [];
        foreach ($employees as $emp) {
            if (!isset($managers[$emp->supervisor_email])) {
                $managers[$emp->supervisor_email] = [
                    'supervisor_email' => $emp->supervisor_email,
                    'employees' => []
                ];
            }
            $managers[$emp->supervisor_email]['employees'][] = $emp;
        }

        return $managers;
    }

    /**
     * Build employee table HTML
     *
     * @param array $employees Array of user objects
     * @param stdClass $course Course object
     * @return string HTML table
     */
    private function build_employee_table($employees, $course) {
        $html = '<h3>' . format_string($course->fullname) . '</h3>';
        $html .= '<ul>';
        
        foreach ($employees as $emp) {
            $html .= '<li>' . fullname($emp) . ' (' . $emp->email . ')</li>';
        }
        
        $html .= '</ul>';
        
        return $html;
    }

    /**
     * Build manager-employee table HTML
     *
     * @param array $managers Array of manager data
     * @param stdClass $course Course object
     * @return string HTML table
     */
    private function build_manager_table($managers, $course) {
        $html = '<h3>' . format_string($course->fullname) . '</h3>';
        
        foreach ($managers as $manager) {
            $html .= '<h4>Manager: ' . $manager['supervisor_email'] . '</h4>';
            $html .= '<ul>';
            
            foreach ($manager['employees'] as $emp) {
                $html .= '<li>' . fullname($emp) . ' (' . $emp->email . ')</li>';
            }
            
            $html .= '</ul>';
        }
        
        return $html;
    }

    /**
     * Send notification
     *
     * @param stdClass $item Queue item
     * @return bool Success
     */
    private function send_notification($item) {
        global $DB;

        // Only send notifications to employees.
        if ($item->recipient_type != 'employee') {
            return true;
        }

        $user = $DB->get_record('user', ['id' => $item->userid]);
        $course = get_course($item->courseid);

        if (!$user || !$course) {
            return false;
        }

        $message = new \core\message\message();
        $message->component = 'local_mandatoryreminder';
        $message->name = 'coursereminder';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('notification_subject_level' . $item->level, 'local_mandatoryreminder', 
            format_string($course->fullname));
        $message->fullmessage = get_string('notification_message_level' . $item->level, 'local_mandatoryreminder', 
            format_string($course->fullname));
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = get_string('notification_small_level' . $item->level, 'local_mandatoryreminder');
        $message->notification = 1;
        $message->contexturl = new \moodle_url('/course/view.php', ['id' => $course->id]);
        $message->contexturlname = format_string($course->fullname);

        return message_send($message) !== false;
    }

    /**
     * Get email template
     *
     * @param int $level Reminder level
     * @param string $recipienttype Recipient type
     * @return string Template
     */
    private function get_email_template($level, $recipienttype) {
        // Levels 1 and 2 only send to employees and their settings keys carry no
        // recipient-type suffix (e.g. 'level1_template', not 'level1_employee_template').
        if ($level <= 2) {
            $key = 'level' . $level . '_template';
        } else {
            $key = 'level' . $level . '_' . $recipienttype . '_template';
        }

        $template = get_config('local_mandatoryreminder', $key);

        if (empty($template)) {
            $template = get_string($key . '_default', 'local_mandatoryreminder');
        }

        return $template;
    }

    /**
     * Get email subject
     *
     * @param int $level Reminder level
     * @param stdClass $course Course object
     * @return string Subject
     */
    private function get_email_subject($level, $course) {
        return get_string('email_subject_level' . $level, 'local_mandatoryreminder', 
            format_string($course->fullname));
    }
}
