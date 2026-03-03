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
require_once($CFG->dirroot . '/local/mandatoryreminder/classes/email_sender.php');

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

        if (empty($mandatorycourses)) {
            mtrace('No mandatory courses found. Ensure the "mandatory_status" custom course field is configured correctly.');
            mtrace('Mandatory course reminder check completed');
            return;
        }

        $coursecount = count($mandatorycourses);
        mtrace("Found {$coursecount} mandatory course(s)");

        // Build a structure: [userid][recipient_type] => array of course data.
        $userreminders = [];
        $totalusers   = 0;
        $processedusers = [];

        foreach ($mandatorycourses as $courseid) {
            $course          = get_course($courseid);
            $deadline        = local_mandatoryreminder_get_course_deadline($courseid);
            $deadlineseconds = $deadline * 24 * 60 * 60;

            mtrace("Processing course: {$course->fullname} (ID: {$courseid}, deadline: {$deadline} days)");

            $incompleteusers = local_mandatoryreminder_get_incomplete_users($courseid);
            $usercount       = count($incompleteusers);

            if ($usercount === 0) {
                mtrace('  No incomplete users found — skipping');
                continue;
            }

            mtrace("  Found {$usercount} incomplete user(s)");

            foreach ($incompleteusers as $user) {
                if (!isset($processedusers[$user->id])) {
                    $processedusers[$user->id] = true;
                    $totalusers++;
                }

                $enroldate    = $user->enroldate;
                $deadlinedate = $enroldate + $deadlineseconds;
                $daysdiff     = ($now - $deadlinedate) / (24 * 60 * 60);
                $daysenrolled = ($now - $enroldate) / (24 * 60 * 60);

                // Determine which reminder levels should be sent.
                $levels = $this->determine_reminder_levels($daysdiff, $deadline);

                if (empty($levels)) {
                    continue; // Not in any reminder window.
                }

                foreach ($levels as $level) {
                    // Check if already sent for this user+course+level.
                    if (local_mandatoryreminder_is_sent($user->id, $courseid, $level)) {
                        continue; // Already sent.
                    }

                    // Store course data for this user+level.
                    $coursedata = [
                        'courseid' => $courseid,
                        'coursename' => format_string($course->fullname),
                        'level' => $level,
                        'enroldate' => $enroldate,
                        'deadlinedate' => $deadlinedate,
                        'daysoverdue' => $daysdiff,
                        'deadline_days' => $deadline
                    ];

                    // Employee reminders.
                    if (!isset($userreminders[$user->id]['employee'])) {
                        $userreminders[$user->id]['employee'] = [
                            'user' => $user,
                            'courses' => []
                        ];
                    }
                    $userreminders[$user->id]['employee']['courses'][] = $coursedata;

                    // Level 3+ also notify supervisor.
                    if ($level >= 3) {
                        $supervisoremail = local_mandatoryreminder_get_user_custom_field($user->id, 'SupervisorEmail');
                        if ($supervisoremail && validate_email($supervisoremail)) {
                            $key = $supervisoremail;
                            if (!isset($userreminders[$user->id]['supervisor'])) {
                                $userreminders[$user->id]['supervisor'] = [];
                            }
                            if (!isset($userreminders[$user->id]['supervisor'][$key])) {
                                $userreminders[$user->id]['supervisor'][$key] = [
                                    'user' => $user,
                                    'email' => $supervisoremail,
                                    'courses' => []
                                ];
                            }
                            $userreminders[$user->id]['supervisor'][$key]['courses'][] = $coursedata;
                        }
                    }

                    // Level 4 also notify SBU head.
                    if ($level == 4) {
                        $sbuheademail = local_mandatoryreminder_get_user_custom_field($user->id, 'sbuheademail');
                        if ($sbuheademail && validate_email($sbuheademail)) {
                            $key = $sbuheademail;
                            if (!isset($userreminders[$user->id]['sbuhead'])) {
                                $userreminders[$user->id]['sbuhead'] = [];
                            }
                            if (!isset($userreminders[$user->id]['sbuhead'][$key])) {
                                $userreminders[$user->id]['sbuhead'][$key] = [
                                    'user' => $user,
                                    'email' => $sbuheademail,
                                    'courses' => []
                                ];
                            }
                            $userreminders[$user->id]['sbuhead'][$key]['courses'][] = $coursedata;
                        }
                    }
                }
            }
        }

        mtrace("Collected reminders for {$totalusers} user(s)");

        // Now queue consolidated emails (one per user per recipient_type).
        $totalqueued = $this->queue_consolidated_reminders($userreminders);

        mtrace("Queued {$totalqueued} consolidated email(s)");
        mtrace('Mandatory course reminder check completed');
    }

    /**
     * Determine which reminder levels should be sent
     *
     * Note: For users very overdue (e.g., 90+ days), this will only return Level 4.
     * The assumption is that Levels 1-3 would have been sent previously at their
     * appropriate times. If the cron is being run for the first time and there are
     * long-overdue users, only Level 4 will be queued for them.
     *
     * @param float $daysdiff Days difference (negative before deadline, positive after)
     * @param int $deadline Total deadline in days
     * @return array Array of levels to send
     */
    private function determine_reminder_levels($daysdiff, $deadline) {
        $levels = [];
        
        // Level 1: 3 days before deadline to 1 day before.
        if ($daysdiff >= -3 && $daysdiff < -1) {
            $levels[] = 1;
        }
        
        // Level 2: 1 day before deadline to deadline day.
        if ($daysdiff >= -1 && $daysdiff < 0) {
            $levels[] = 2;
        }
        
        // Level 3: 1 week (7 days) to 2 weeks (14 days) after deadline.
        if ($daysdiff >= 7 && $daysdiff < 14) {
            $levels[] = 3;
        }
        
        // Level 4: 2 weeks (14 days) or more after deadline.
        // This includes anyone 14, 30, 90, or even 180 days overdue.
        if ($daysdiff >= 14) {
            $levels[] = 4;
        }

        return $levels;
    }

    /**
     * Queue consolidated reminder emails (one email per user per recipient type).
     *
     * @param array $userreminders Structure: [userid][recipient_type] => data
     * @return int Number of emails queued
     */
    private function queue_consolidated_reminders($userreminders) {
        global $DB;

        $now = time();
        $queued = 0;

        foreach ($userreminders as $userid => $types) {
            // Queue employee email.
            if (isset($types['employee']) && !empty($types['employee']['courses'])) {
                $data = $types['employee'];
                $user = $data['user'];

                // Sort courses by level (Level 4 first).
                usort($data['courses'], function($a, $b) {
                    return $b['level'] - $a['level'];
                });

                // Remove duplicates (same course might appear multiple times with different levels).
                $uniquecourses = [];
                $seencourses = [];
                foreach ($data['courses'] as $coursedata) {
                    $key = $coursedata['courseid'] . '_' . $coursedata['level'];
                    if (!isset($seencourses[$key])) {
                        $seencourses[$key] = true;
                        $uniquecourses[] = $coursedata;
                    }
                }

                // Check if a queue item already exists for this user+recipient_type.
                $existing = $DB->get_record('local_mandatoryreminder_queue', [
                    'userid' => $userid,
                    'recipient_type' => 'employee',
                    'status' => 'pending'
                ]);

                if ($existing) {
                    // Update existing queue item with new courses data.
                    $existing->courses_data = json_encode($uniquecourses);
                    $existing->email_subject = null; // Will be regenerated.
                    $existing->email_body = null; // Will be regenerated.
                    $existing->timemodified = $now;
                    $DB->update_record('local_mandatoryreminder_queue', $existing);
                } else {
                    // Create new queue item.
                    $queue = new \stdClass();
                    $queue->userid = $userid;
                    $queue->courses_data = json_encode($uniquecourses);
                    $queue->recipient_type = 'employee';
                    $queue->recipient_email = $user->email;
                    $queue->status = 'pending';
                    $queue->attempts = 0;
                    $queue->timecreated = $now;
                    $queue->timemodified = $now;
                    $DB->insert_record('local_mandatoryreminder_queue', $queue);
                    $queued++;
                }

                // Log each course+level as sent.
                foreach ($uniquecourses as $coursedata) {
                    local_mandatoryreminder_log_sent(
                        $userid,
                        $coursedata['courseid'],
                        $coursedata['level'],
                        $coursedata['enroldate'],
                        $coursedata['deadlinedate']
                    );
                }

                mtrace("  Queued employee email for user {$userid} (" . fullname($user) . ") with " . 
                       count($uniquecourses) . " course(s)");
            }

            // Queue supervisor emails.
            if (isset($types['supervisor'])) {
                foreach ($types['supervisor'] as $supervisorkey => $data) {
                    $user = $data['user'];
                    $supervisoremail = $data['email'];

                    // Sort courses by level (Level 4 first).
                    usort($data['courses'], function($a, $b) {
                        return $b['level'] - $a['level'];
                    });

                    // Remove duplicates.
                    $uniquecourses = [];
                    $seencourses = [];
                    foreach ($data['courses'] as $coursedata) {
                        $key = $coursedata['courseid'] . '_' . $coursedata['level'];
                        if (!isset($seencourses[$key])) {
                            $seencourses[$key] = true;
                            $uniquecourses[] = $coursedata;
                        }
                    }

                    // Check if queue item exists.
                    $existing = $DB->get_record('local_mandatoryreminder_queue', [
                        'userid' => $userid,
                        'recipient_type' => 'supervisor',
                        'recipient_email' => $supervisoremail,
                        'status' => 'pending'
                    ]);

                    if ($existing) {
                        $existing->courses_data = json_encode($uniquecourses);
                        $existing->email_subject = null;
                        $existing->email_body = null;
                        $existing->timemodified = $now;
                        $DB->update_record('local_mandatoryreminder_queue', $existing);
                    } else {
                        $queue = new \stdClass();
                        $queue->userid = $userid;
                        $queue->courses_data = json_encode($uniquecourses);
                        $queue->recipient_type = 'supervisor';
                        $queue->recipient_email = $supervisoremail;
                        $queue->status = 'pending';
                        $queue->attempts = 0;
                        $queue->timecreated = $now;
                        $queue->timemodified = $now;
                        $DB->insert_record('local_mandatoryreminder_queue', $queue);
                        $queued++;
                    }

                    mtrace("  Queued supervisor email to {$supervisoremail} for user {$userid} with " . 
                           count($uniquecourses) . " course(s)");
                }
            }

            // Queue SBU head emails.
            if (isset($types['sbuhead'])) {
                foreach ($types['sbuhead'] as $sbuheadkey => $data) {
                    $user = $data['user'];
                    $sbuheademail = $data['email'];

                    // Sort courses by level (Level 4 first).
                    usort($data['courses'], function($a, $b) {
                        return $b['level'] - $a['level'];
                    });

                    // Remove duplicates.
                    $uniquecourses = [];
                    $seencourses = [];
                    foreach ($data['courses'] as $coursedata) {
                        $key = $coursedata['courseid'] . '_' . $coursedata['level'];
                        if (!isset($seencourses[$key])) {
                            $seencourses[$key] = true;
                            $uniquecourses[] = $coursedata;
                        }
                    }

                    // Check if queue item exists.
                    $existing = $DB->get_record('local_mandatoryreminder_queue', [
                        'userid' => $userid,
                        'recipient_type' => 'sbuhead',
                        'recipient_email' => $sbuheademail,
                        'status' => 'pending'
                    ]);

                    if ($existing) {
                        $existing->courses_data = json_encode($uniquecourses);
                        $existing->email_subject = null;
                        $existing->email_body = null;
                        $existing->timemodified = $now;
                        $DB->update_record('local_mandatoryreminder_queue', $existing);
                    } else {
                        $queue = new \stdClass();
                        $queue->userid = $userid;
                        $queue->courses_data = json_encode($uniquecourses);
                        $queue->recipient_type = 'sbuhead';
                        $queue->recipient_email = $sbuheademail;
                        $queue->status = 'pending';
                        $queue->attempts = 0;
                        $queue->timecreated = $now;
                        $queue->timemodified = $now;
                        $DB->insert_record('local_mandatoryreminder_queue', $queue);
                        $queued++;
                    }

                    mtrace("  Queued SBU head email to {$sbuheademail} for user {$userid} with " . 
                           count($uniquecourses) . " course(s)");
                }
            }
        }

        return $queued;
    }
}
