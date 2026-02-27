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
 * English language strings for local_mandatoryreminder
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Mandatory Course Reminder';
$string['settings'] = 'Reminder Settings';

// Capabilities.
$string['mandatoryreminder:configure'] = 'Configure course deadlines';
$string['mandatoryreminder:viewdashboard'] = 'View reminder dashboard';

// Settings.
$string['default_deadline'] = 'Default Deadline (days)';
$string['default_deadline_desc'] = 'Default deadline in days for mandatory course completion';
$string['email_batch_size'] = 'Email Batch Size';
$string['email_batch_size_desc'] = 'Number of emails to process in each batch';

// Navigation.
$string['course_config'] = 'Course Deadlines Configuration';
$string['dashboard'] = 'Reminder Dashboard';

// Dashboard.
$string['summary'] = 'Summary';
$string['total_mandatory_courses'] = 'Total Mandatory Courses';
$string['total_enrolled_users'] = 'Total Enrolled Users';
$string['total_incomplete'] = 'Total Incomplete Users';
$string['emails_pending'] = 'Emails Pending';
$string['emails_sent_today'] = 'Emails Sent Today';
$string['emails_failed'] = 'Failed Emails';
$string['email_queue'] = 'Email Queue';
$string['no_queue_items'] = 'No items in queue';
$string['deadline_days'] = 'Deadline (Days)';
$string['level'] = 'Level';
$string['recipient_type'] = 'Recipient Type';
$string['status'] = 'Status';
$string['attempts'] = 'Attempts';
$string['created'] = 'Created';
$string['picture'] = 'Picture';

// Recipient types.
$string['recipient_employee'] = 'Employee';
$string['recipient_supervisor'] = 'Supervisor';
$string['recipient_sbuhead'] = 'SBU Head';

// Statuses.
$string['status_pending'] = 'Pending';
$string['status_processing'] = 'Processing';
$string['status_sent'] = 'Sent';
$string['status_failed'] = 'Failed';

// Messages.
$string['config_saved'] = 'Configuration saved successfully';
$string['config_error'] = 'Error saving configuration';
$string['no_mandatory_courses'] = 'No mandatory courses found. Please set the mandatory_status custom field for courses.';

// Tasks.
$string['check_reminders_task'] = 'Check mandatory course reminders';

// Email templates - Level 1.
$string['level1_template'] = 'Level 1 Email Template (Employee - 3 days before)';
$string['level1_template_desc'] = 'Email template for employees 3 days before deadline. Available placeholders: {firstname}, {lastname}, {fullname}, {coursename}, {courseurl}, {courselink}, {daysoverdue}, {sitename}';
$string['level1_template_default'] = '<p>Dear {fullname},</p>
<p>This is a reminder that you have a mandatory course that needs to be completed soon.</p>
<p><strong>Course:</strong> {courselink}</p>
<p><strong>Reminder:</strong> This course is due in approximately 3 days. Please complete it before the deadline.</p>
<p>Click here to access the course: {courseurl}</p>
<p>Thank you,<br>{sitename}</p>';

// Email templates - Level 2.
$string['level2_template'] = 'Level 2 Email Template (Employee - 1 day before)';
$string['level2_template_desc'] = 'Email template for employees 1 day before deadline. Available placeholders: {firstname}, {lastname}, {fullname}, {coursename}, {courseurl}, {courselink}, {daysoverdue}, {sitename}';
$string['level2_template_default'] = '<p>Dear {fullname},</p>
<p><strong>URGENT:</strong> This is a final reminder that you have a mandatory course due tomorrow.</p>
<p><strong>Course:</strong> {courselink}</p>
<p><strong>Deadline:</strong> Tomorrow</p>
<p>Please complete this course immediately to meet the deadline.</p>
<p>Click here to access the course: {courseurl}</p>
<p>Thank you,<br>{sitename}</p>';

// Email templates - Level 3 Employee.
$string['level3_employee_template'] = 'Level 3 Email Template (Employee - 1 week overdue)';
$string['level3_employee_template_desc'] = 'Email template for employees 1 week after deadline. Available placeholders: {firstname}, {lastname}, {fullname}, {coursename}, {courseurl}, {courselink}, {daysoverdue}, {sitename}';
$string['level3_employee_template_default'] = '<p>Dear {fullname},</p>
<p><strong>OVERDUE:</strong> You have not completed the following mandatory course.</p>
<p><strong>Course:</strong> {courselink}</p>
<p><strong>Days Overdue:</strong> {daysoverdue} days</p>
<p>This course is now overdue. Please complete it as soon as possible. Your supervisor has been notified.</p>
<p>Click here to access the course: {courseurl}</p>
<p>Thank you,<br>{sitename}</p>';

// Email templates - Level 3 Supervisor.
$string['level3_supervisor_template'] = 'Level 3 Email Template (Supervisor - 1 week overdue)';
$string['level3_supervisor_template_desc'] = 'Email template for supervisors 1 week after deadline. Available placeholders: {employee_table}, {sitename}. The {employee_table} will be replaced with a list of employees.';
$string['level3_supervisor_template_default'] = '<p>Dear Supervisor,</p>
<p>The following employees under your supervision have not completed their mandatory courses and are now 1 week overdue:</p>
{employee_table}
<p>Please follow up with these employees to ensure course completion.</p>
<p>Thank you,<br>{sitename}</p>';

// Email templates - Level 4 Employee.
$string['level4_employee_template'] = 'Level 4 Email Template (Employee - 2 weeks overdue)';
$string['level4_employee_template_desc'] = 'Email template for employees 2 weeks after deadline. Available placeholders: {firstname}, {lastname}, {fullname}, {coursename}, {courseurl}, {courselink}, {daysoverdue}, {sitename}';
$string['level4_employee_template_default'] = '<p>Dear {fullname},</p>
<p><strong>CRITICAL:</strong> You have not completed the following mandatory course.</p>
<p><strong>Course:</strong> {courselink}</p>
<p><strong>Days Overdue:</strong> {daysoverdue} days</p>
<p>This course is now 2 weeks overdue. Please complete it immediately. Your supervisor and SBU Head have been notified.</p>
<p>Click here to access the course: {courseurl}</p>
<p>Thank you,<br>{sitename}</p>';

// Email templates - Level 4 Supervisor.
$string['level4_supervisor_template'] = 'Level 4 Email Template (Supervisor - 2 weeks overdue)';
$string['level4_supervisor_template_desc'] = 'Email template for supervisors 2 weeks after deadline. Available placeholders: {employee_table}, {sitename}';
$string['level4_supervisor_template_default'] = '<p>Dear Supervisor,</p>
<p>The following employees under your supervision have not completed their mandatory courses and are now 2 weeks overdue:</p>
{employee_table}
<p>This is a critical escalation. Please take immediate action to ensure course completion.</p>
<p>Thank you,<br>{sitename}</p>';

// Email templates - Level 4 SBU Head.
$string['level4_sbuhead_template'] = 'Level 4 Email Template (SBU Head - 2 weeks overdue)';
$string['level4_sbuhead_template_desc'] = 'Email template for SBU Heads 2 weeks after deadline. Available placeholders: {manager_table}, {sitename}. The {manager_table} will be replaced with a list of managers and their employees.';
$string['level4_sbuhead_template_default'] = '<p>Dear SBU Head,</p>
<p>The following teams have employees who have not completed their mandatory courses and are now 2 weeks overdue:</p>
{manager_table}
<p>This is a critical escalation. Please coordinate with the respective managers to ensure immediate course completion.</p>
<p>Thank you,<br>{sitename}</p>';

// Email subjects.
$string['email_subject_level1'] = 'Reminder: Mandatory Course Due Soon - {$a}';
$string['email_subject_level2'] = 'URGENT: Mandatory Course Due Tomorrow - {$a}';
$string['email_subject_level3'] = 'OVERDUE: Mandatory Course Not Completed - {$a}';
$string['email_subject_level4'] = 'CRITICAL: Mandatory Course 2 Weeks Overdue - {$a}';

// Notification strings.
$string['messageprovider:coursereminder'] = 'Mandatory course reminder notifications';
$string['notification_subject_level1'] = 'Course due soon: {$a}';
$string['notification_subject_level2'] = 'URGENT: Course due tomorrow: {$a}';
$string['notification_subject_level3'] = 'OVERDUE: Course not completed: {$a}';
$string['notification_subject_level4'] = 'CRITICAL: Course 2 weeks overdue: {$a}';
$string['notification_message_level1'] = 'Your mandatory course "{$a}" is due in approximately 3 days. Please complete it before the deadline.';
$string['notification_message_level2'] = 'URGENT: Your mandatory course "{$a}" is due tomorrow. Please complete it immediately.';
$string['notification_message_level3'] = 'Your mandatory course "{$a}" is now overdue. Please complete it as soon as possible.';
$string['notification_message_level4'] = 'CRITICAL: Your mandatory course "{$a}" is now 2 weeks overdue. Please complete it immediately.';
$string['notification_small_level1'] = 'Course reminder: Due in 3 days';
$string['notification_small_level2'] = 'URGENT: Course due tomorrow';
$string['notification_small_level3'] = 'OVERDUE: Course not completed';
$string['notification_small_level4'] = 'CRITICAL: Course 2 weeks overdue';

// Dashboard filters.
$string['allstatuses'] = 'All Statuses';
$string['allevels'] = 'All Levels';
$string['alltypes'] = 'All Types';
$string['timesent'] = 'Sent At';
$string['filter'] = 'Apply Filter';
$string['resetfilters'] = 'Reset';
$string['total_enrolled_students'] = 'Total Enrolled Students';

// Privacy.
$string['privacy:metadata:local_mandatoryreminder_log'] = 'Log of sent reminders';
$string['privacy:metadata:local_mandatoryreminder_log:userid'] = 'The ID of the user';
$string['privacy:metadata:local_mandatoryreminder_log:courseid'] = 'The ID of the course';
$string['privacy:metadata:local_mandatoryreminder_log:level'] = 'The reminder level';
$string['privacy:metadata:local_mandatoryreminder_log:sent_date'] = 'The date the reminder was sent';
$string['privacy:metadata:local_mandatoryreminder_queue'] = 'Queue of emails to be sent';
$string['privacy:metadata:local_mandatoryreminder_queue:userid'] = 'The ID of the user';
$string['privacy:metadata:local_mandatoryreminder_queue:courseid'] = 'The ID of the course';
$string['privacy:metadata:local_mandatoryreminder_queue:recipient_email'] = 'The email address of the recipient';
