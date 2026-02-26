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
 * CLI script to test mandatory reminder functionality
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/mandatoryreminder/lib.php');

// Get cli options.
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'action' => '',
    ],
    [
        'h' => 'help',
        'a' => 'action',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

$help = "Test mandatory reminder functionality.

Options:
--action=<action>     Action to perform:
                      - stats: Show statistics
                      - mandatory: List mandatory courses
                      - queue: Show queue status
                      - test: Run a test check (dry run)
                      - run: Execute scheduled task
                      - process: Process email queue
-h, --help            Print out this help

Examples:
    # Show statistics
    \$ php test_reminder.php --action=stats
    
    # List mandatory courses
    \$ php test_reminder.php --action=mandatory
    
    # Show queue status
    \$ php test_reminder.php --action=queue
    
    # Run scheduled task
    \$ php test_reminder.php --action=run
    
    # Process email queue
    \$ php test_reminder.php --action=process
";

if ($options['help'] || empty($options['action'])) {
    echo $help;
    exit(0);
}

// Execute action.
switch ($options['action']) {
    case 'stats':
        show_statistics();
        break;
        
    case 'mandatory':
        list_mandatory_courses();
        break;
        
    case 'queue':
        show_queue_status();
        break;
        
    case 'test':
        test_check();
        break;
        
    case 'run':
        run_scheduled_task();
        break;
        
    case 'process':
        process_queue();
        break;
        
    default:
        cli_error('Invalid action. Use --help for usage information.');
}

/**
 * Show statistics
 */
function show_statistics() {
    global $DB;
    
    cli_heading('Mandatory Course Reminder Statistics');
    
    $mandatory = local_mandatoryreminder_get_mandatory_courses();
    cli_writeln('Total mandatory courses: ' . count($mandatory));
    
    $totalusers = 0;
    $totalincomplete = 0;
    
    foreach ($mandatory as $courseid) {
        $users = local_mandatoryreminder_get_incomplete_users($courseid);
        $totalincomplete += count($users);
        
        $context = context_course::instance($courseid);
        $enrolled = get_enrolled_users($context);
        $totalusers += count($enrolled);
    }
    
    cli_writeln('Total enrolled users: ' . $totalusers);
    cli_writeln('Total incomplete users: ' . $totalincomplete);
    
    $pending = $DB->count_records('local_mandatoryreminder_queue', ['status' => 'pending']);
    $processing = $DB->count_records('local_mandatoryreminder_queue', ['status' => 'processing']);
    $sent = $DB->count_records('local_mandatoryreminder_queue', ['status' => 'sent']);
    $failed = $DB->count_records('local_mandatoryreminder_queue', ['status' => 'failed']);
    
    cli_writeln('');
    cli_writeln('Email Queue Status:');
    cli_writeln('  Pending: ' . $pending);
    cli_writeln('  Processing: ' . $processing);
    cli_writeln('  Sent: ' . $sent);
    cli_writeln('  Failed: ' . $failed);
    
    $todaystart = strtotime('today');
    $senttoday = $DB->count_records_select('local_mandatoryreminder_queue', 
        'status = ? AND timesent >= ?', ['sent', $todaystart]);
    
    cli_writeln('');
    cli_writeln('Sent today: ' . $senttoday);
}

/**
 * List mandatory courses
 */
function list_mandatory_courses() {
    cli_heading('Mandatory Courses');
    
    $mandatory = local_mandatoryreminder_get_mandatory_courses();
    
    if (empty($mandatory)) {
        cli_writeln('No mandatory courses found.');
        return;
    }
    
    foreach ($mandatory as $courseid) {
        $course = get_course($courseid);
        $deadline = local_mandatoryreminder_get_course_deadline($courseid);
        $incomplete = local_mandatoryreminder_get_incomplete_users($courseid);
        
        cli_writeln('');
        cli_writeln('Course: ' . $course->fullname);
        cli_writeln('  ID: ' . $courseid);
        cli_writeln('  Deadline: ' . $deadline . ' days');
        cli_writeln('  Incomplete users: ' . count($incomplete));
    }
}

/**
 * Show queue status
 */
function show_queue_status() {
    global $DB;
    
    cli_heading('Email Queue Status');
    
    $pending = $DB->get_records('local_mandatoryreminder_queue', 
        ['status' => 'pending'], 
        'timecreated ASC', 
        '*', 
        0, 
        10
    );
    
    if (empty($pending)) {
        cli_writeln('No pending emails in queue.');
        return;
    }
    
    cli_writeln('Next 10 pending emails:');
    cli_writeln('');
    
    foreach ($pending as $item) {
        $user = $DB->get_record('user', ['id' => $item->userid]);
        $course = get_course($item->courseid);
        
        cli_writeln('User: ' . fullname($user) . ' (' . $user->email . ')');
        cli_writeln('  Course: ' . $course->fullname);
        cli_writeln('  Level: ' . $item->level);
        cli_writeln('  Recipient: ' . $item->recipient_type . ' (' . $item->recipient_email . ')');
        cli_writeln('  Created: ' . userdate($item->timecreated));
        cli_writeln('');
    }
}

/**
 * Test check (dry run)
 */
function test_check() {
    cli_heading('Test Check (Dry Run)');
    
    $mandatory = local_mandatoryreminder_get_mandatory_courses();
    
    cli_writeln('Found ' . count($mandatory) . ' mandatory courses');
    cli_writeln('');
    
    $now = time();
    
    foreach ($mandatory as $courseid) {
        $course = get_course($courseid);
        $deadline = local_mandatoryreminder_get_course_deadline($courseid);
        
        cli_writeln('Processing: ' . $course->fullname);
        
        $incomplete = local_mandatoryreminder_get_incomplete_users($courseid);
        
        cli_writeln('  Incomplete users: ' . count($incomplete));
        
        $reminders = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        
        foreach ($incomplete as $user) {
            $deadlinedate = $user->enroldate + ($deadline * 24 * 60 * 60);
            $daysdiff = ($now - $deadlinedate) / (24 * 60 * 60);
            
            // Check which levels would be sent.
            if ($daysdiff >= -3 && $daysdiff < -1) {
                if (!local_mandatoryreminder_is_sent($user->id, $courseid, 1)) {
                    $reminders[1]++;
                }
            }
            if ($daysdiff >= -1 && $daysdiff < 0) {
                if (!local_mandatoryreminder_is_sent($user->id, $courseid, 2)) {
                    $reminders[2]++;
                }
            }
            if ($daysdiff >= 7 && $daysdiff < 14) {
                if (!local_mandatoryreminder_is_sent($user->id, $courseid, 3)) {
                    $reminders[3]++;
                }
            }
            if ($daysdiff >= 14) {
                if (!local_mandatoryreminder_is_sent($user->id, $courseid, 4)) {
                    $reminders[4]++;
                }
            }
        }
        
        cli_writeln('  Would send reminders:');
        cli_writeln('    Level 1: ' . $reminders[1]);
        cli_writeln('    Level 2: ' . $reminders[2]);
        cli_writeln('    Level 3: ' . $reminders[3]);
        cli_writeln('    Level 4: ' . $reminders[4]);
        cli_writeln('');
    }
}

/**
 * Run scheduled task
 */
function run_scheduled_task() {
    cli_heading('Running Scheduled Task');
    
    $task = new \local_mandatoryreminder\task\check_reminders();
    $task->execute();
    
    cli_writeln('');
    cli_writeln('Task completed successfully.');
}

/**
 * Process email queue
 */
function process_queue() {
    cli_heading('Processing Email Queue');
    
    $task = new \local_mandatoryreminder\task\process_queue();
    $task->execute();
    
    cli_writeln('');
    cli_writeln('Queue processing completed successfully.');
}
