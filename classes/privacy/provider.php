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
 * Privacy provider for local_mandatoryreminder
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_mandatoryreminder\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider class
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Get metadata
     *
     * @param collection $collection The metadata collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_mandatoryreminder_log',
            [
                'userid' => 'privacy:metadata:local_mandatoryreminder_log:userid',
                'courseid' => 'privacy:metadata:local_mandatoryreminder_log:courseid',
                'level' => 'privacy:metadata:local_mandatoryreminder_log:level',
                'sent_date' => 'privacy:metadata:local_mandatoryreminder_log:sent_date',
            ],
            'privacy:metadata:local_mandatoryreminder_log'
        );

        $collection->add_database_table(
            'local_mandatoryreminder_queue',
            [
                'userid' => 'privacy:metadata:local_mandatoryreminder_queue:userid',
                'courseid' => 'privacy:metadata:local_mandatoryreminder_queue:courseid',
                'recipient_email' => 'privacy:metadata:local_mandatoryreminder_queue:recipient_email',
            ],
            'privacy:metadata:local_mandatoryreminder_queue'
        );

        return $collection;
    }

    /**
     * Get contexts for user
     *
     * @param int $userid User ID
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {local_mandatoryreminder_log} l ON l.courseid = c.id
                 WHERE l.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid
        ]);

        return $contextlist;
    }

    /**
     * Get users in context
     *
     * @param userlist $userlist The userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT userid
                  FROM {local_mandatoryreminder_log}
                 WHERE courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export user data
     *
     * @param approved_contextlist $contextlist The approved contexts
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $logs = $DB->get_records('local_mandatoryreminder_log', [
                'userid' => $userid,
                'courseid' => $context->instanceid
            ]);

            $data = [];
            foreach ($logs as $log) {
                $data[] = (object)[
                    'level' => $log->level,
                    'sent_date' => \core_privacy\local\request\transform::datetime($log->sent_date),
                ];
            }

            if (!empty($data)) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_mandatoryreminder')],
                    (object)['reminders' => $data]
                );
            }
        }
    }

    /**
     * Delete data for all users in context
     *
     * @param \context $context The context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $DB->delete_records('local_mandatoryreminder_log', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_mandatoryreminder_queue', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete data for user
     *
     * @param approved_contextlist $contextlist The approved contexts
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $DB->delete_records('local_mandatoryreminder_log', [
                'userid' => $userid,
                'courseid' => $context->instanceid
            ]);

            $DB->delete_records('local_mandatoryreminder_queue', [
                'userid' => $userid,
                'courseid' => $context->instanceid
            ]);
        }
    }

    /**
     * Delete data for users
     *
     * @param approved_userlist $userlist The approved users
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();

        foreach ($userids as $userid) {
            $DB->delete_records('local_mandatoryreminder_log', [
                'userid' => $userid,
                'courseid' => $context->instanceid
            ]);

            $DB->delete_records('local_mandatoryreminder_queue', [
                'userid' => $userid,
                'courseid' => $context->instanceid
            ]);
        }
    }
}
