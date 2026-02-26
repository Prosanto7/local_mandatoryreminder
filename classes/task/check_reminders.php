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
 * Scheduled task to check for reminders
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_mandatoryreminder\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/mandatoryreminder/lib.php');

/**
 * Check reminders task
 */
class check_reminders extends \core\task\scheduled_task {

    /**
     * Get task name
     *
     * @return string
     */
    public function get_name() {
        return get_string('check_reminders_task', 'local_mandatoryreminder');
    }

    /**
     * Execute task
     */
    public function execute() {
        global $DB;

        mtrace('Starting mandatory course reminder check...');

        $now = time();
        $mandatorycourses = local_mandatoryreminder_get_mandatory_courses();

        mtrace('Found ' . count($mandatorycourses) . ' mandatory courses');

        $totalqueued = 0;

        foreach ($mandatorycourses as $courseid) {
            $course = get_course($courseid);
            $deadline = local_mandatoryreminder_get_course_deadline($courseid);
            $deadlineseconds = $deadline * 24 * 60 * 60;

            mtrace("Processing course: {$course->fullname} (ID: {$courseid}, Deadline: {$deadline} days)");

            $incompleteusers = local_mandatoryreminder_get_incomplete_users($courseid);
            
            mtrace("  Found " . count($incompleteusers) . " incomplete users");

            foreach ($incompleteusers as $user) {
                $enroldate = $user->enroldate;
                $deadlinedate = $enroldate + $deadlineseconds;
                $daysdiff = ($now - $deadlinedate) / (24 * 60 * 60);

                // Determine which level(s) to send.
                $levels = $this->determine_reminder_levels($daysdiff, $deadline);

                foreach ($levels as $level) {
                    // Check if already sent.
                    if (local_mandatoryreminder_is_sent($user->id, $courseid, $level)) {
                        continue;
                    }

                    // Queue the reminders.
                    $queued = $this->queue_reminders($user, $course, $level, $enroldate, $deadlinedate, $daysdiff);
                    $totalqueued += $queued;

                    // Log as sent.
                    local_mandatoryreminder_log_sent($user->id, $courseid, $level, $enroldate, $deadlinedate);
                }
            }
        }

        mtrace("Queued {$totalqueued} reminder emails");

        // Queue ad-hoc task to process the queue.
        $task = new \local_mandatoryreminder\task\process_queue();
        \core\task\manager::queue_adhoc_task($task);

        mtrace('Mandatory course reminder check completed');
    }

    /**
     * Determine which reminder levels should be sent
     *
     * @param float $daysdiff Days difference (negative before deadline, positive after)
     * @param int $deadline Total deadline in days
     * @return array Array of levels to send
     */
    private function determine_reminder_levels($daysdiff, $deadline) {
        $levels = [];
        
        // Level 1: 3 days before deadline.
        if ($daysdiff >= -3 && $daysdiff < -1) {
            $levels[] = 1;
        }
        
        // Level 2: 1 day before deadline.
        if ($daysdiff >= -1 && $daysdiff < 0) {
            $levels[] = 2;
        }
        
        // Level 3: 1 week (7 days) after deadline.
        if ($daysdiff >= 7 && $daysdiff < 14) {
            $levels[] = 3;
        }
        
        // Level 4: 2 weeks (14 days) after deadline.
        if ($daysdiff >= 14) {
            $levels[] = 4;
        }

        return $levels;
    }

    /**
     * Queue reminder emails for a user
     *
     * @param stdClass $user User object
     * @param stdClass $course Course object
     * @param int $level Reminder level
     * @param int $enroldate Enrolment date
     * @param int $deadlinedate Deadline date
     * @param float $daysdiff Days difference
     * @return int Number of emails queued
     */
    private function queue_reminders($user, $course, $level, $enroldate, $deadlinedate, $daysdiff) {
        global $DB;

        $now = time();
        $queued = 0;

        // Always queue for employee.
        $queue = new \stdClass();
        $queue->userid = $user->id;
        $queue->courseid = $course->id;
        $queue->level = $level;
        $queue->recipient_type = 'employee';
        $queue->recipient_email = $user->email;
        $queue->status = 'pending';
        $queue->attempts = 0;
        $queue->timecreated = $now;
        $queue->timemodified = $now;
        
        $DB->insert_record('local_mandatoryreminder_queue', $queue);
        $queued++;

        // Level 3 and 4: Also send to supervisor.
        if ($level >= 3) {
            $supervisoremail = local_mandatoryreminder_get_user_custom_field($user->id, 'SupervisorEmail');
            
            if ($supervisoremail && validate_email($supervisoremail)) {
                $queue = new \stdClass();
                $queue->userid = $user->id;
                $queue->courseid = $course->id;
                $queue->level = $level;
                $queue->recipient_type = 'supervisor';
                $queue->recipient_email = $supervisoremail;
                $queue->status = 'pending';
                $queue->attempts = 0;
                $queue->timecreated = $now;
                $queue->timemodified = $now;
                
                $DB->insert_record('local_mandatoryreminder_queue', $queue);
                $queued++;
            }
        }

        // Level 4: Also send to SBU Head.
        if ($level == 4) {
            $sbuheademail = local_mandatoryreminder_get_user_custom_field($user->id, 'sbuheademail');
            
            if ($sbuheademail && validate_email($sbuheademail)) {
                $queue = new \stdClass();
                $queue->userid = $user->id;
                $queue->courseid = $course->id;
                $queue->level = $level;
                $queue->recipient_type = 'sbuhead';
                $queue->recipient_email = $sbuheademail;
                $queue->status = 'pending';
                $queue->attempts = 0;
                $queue->timecreated = $now;
                $queue->timemodified = $now;
                
                $DB->insert_record('local_mandatoryreminder_queue', $queue);
                $queued++;
            }
        }

        return $queued;
    }
}
