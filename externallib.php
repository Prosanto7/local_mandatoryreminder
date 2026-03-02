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
 * External API functions for mandatory reminder.
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/mandatoryreminder/lib.php');
require_once($CFG->dirroot . '/local/mandatoryreminder/classes/email_sender.php');

use local_mandatoryreminder\email_sender;

/**
 * External API class for mandatory reminder web services.
 */
class local_mandatoryreminder_external extends external_api {

    /**
     * Returns description of preview_email parameters.
     *
     * @return external_function_parameters
     */
    public static function preview_email_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Queue item ID')
        ]);
    }

    /**
     * Preview email content.
     *
     * @param int $id Queue item ID
     * @return array
     */
    public static function preview_email($id) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::preview_email_parameters(), ['id' => $id]);

        // Check capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/mandatoryreminder:sendemails', $context);

        $item = $DB->get_record('local_mandatoryreminder_queue', ['id' => $params['id']], '*', MUST_EXIST);
        $preview = email_sender::get_preview($item);

        return [
            'success' => true,
            'subject' => $preview['subject'],
            'body'    => $preview['body'],
        ];
    }

    /**
     * Returns description of preview_email return value.
     *
     * @return external_single_structure
     */
    public static function preview_email_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'subject' => new external_value(PARAM_TEXT, 'Email subject'),
            'body'    => new external_value(PARAM_RAW, 'Email body HTML'),
        ]);
    }

    /**
     * Returns description of queue_single_email parameters.
     *
     * @return external_function_parameters
     */
    public static function queue_single_email_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Queue item ID')
        ]);
    }

    /**
     * Queue a single email for sending.
     *
     * @param int $id Queue item ID
     * @return array
     */
    public static function queue_single_email($id) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::queue_single_email_parameters(), ['id' => $id]);

        // Check capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/mandatoryreminder:sendemails', $context);

        $item = $DB->get_record('local_mandatoryreminder_queue', ['id' => $params['id']], '*', MUST_EXIST);

        // Check if already sent.
        $freshstatus = $DB->get_field('local_mandatoryreminder_queue', 'status', ['id' => $params['id']]);
        if ($freshstatus !== 'pending') {
            return [
                'success' => false,
                'error'   => "Item is already '{$freshstatus}'"
            ];
        }

        // Queue the item for processing.
        $task = new \local_mandatoryreminder\task\process_queue();
        $task->set_custom_data(['item_ids' => [$params['id']]]);
        \core\task\manager::queue_adhoc_task($task);

        return [
            'success' => true,
            'message' => get_string('email_queued', 'local_mandatoryreminder')
        ];
    }

    /**
     * Returns description of queue_single_email return value.
     *
     * @return external_single_structure
     */
    public static function queue_single_email_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Success message', VALUE_OPTIONAL),
            'error'   => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Returns description of queue_selected_emails parameters.
     *
     * @return external_function_parameters
     */
    public static function queue_selected_emails_parameters() {
        return new external_function_parameters([
            'ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Queue item ID')
            )
        ]);
    }

    /**
     * Queue selected emails for sending.
     *
     * @param array $ids Array of queue item IDs
     * @return array
     */
    public static function queue_selected_emails($ids) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::queue_selected_emails_parameters(), ['ids' => $ids]);

        // Check capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/mandatoryreminder:sendemails', $context);

        $ids = array_filter(array_map('intval', $params['ids']));

        if (empty($ids)) {
            return [
                'success' => false,
                'error'   => 'No IDs provided'
            ];
        }

        // Queue adhoc task with the selected IDs.
        $task = new \local_mandatoryreminder\task\process_queue();
        $task->set_custom_data(['item_ids' => array_values($ids)]);
        \core\task\manager::queue_adhoc_task($task);

        return [
            'success' => true,
            'count'   => count($ids),
            'message' => get_string('send_queued', 'local_mandatoryreminder', count($ids))
        ];
    }

    /**
     * Returns description of queue_selected_emails return value.
     *
     * @return external_single_structure
     */
    public static function queue_selected_emails_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'count'   => new external_value(PARAM_INT, 'Number of queued items', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_TEXT, 'Success message', VALUE_OPTIONAL),
            'error'   => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Returns description of queue_all_pending parameters.
     *
     * @return external_function_parameters
     */
    public static function queue_all_pending_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Queue all pending employee emails for sending.
     *
     * @return array
     */
    public static function queue_all_pending() {
        global $DB;

        // Check capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/mandatoryreminder:sendemails', $context);

        $count = $DB->count_records(
            'local_mandatoryreminder_queue',
            ['status' => 'pending', 'recipient_type' => 'employee']
        );

        if ($count === 0) {
            return [
                'success' => false,
                'error'   => get_string('no_pending_items', 'local_mandatoryreminder')
            ];
        }

        $task = new \local_mandatoryreminder\task\process_queue();
        $task->set_custom_data(['recipient_type' => 'employee']);
        \core\task\manager::queue_adhoc_task($task);

        return [
            'success' => true,
            'count'   => $count,
            'message' => get_string('send_all_queued', 'local_mandatoryreminder', $count)
        ];
    }

    /**
     * Returns description of queue_all_pending return value.
     *
     * @return external_single_structure
     */
    public static function queue_all_pending_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'count'   => new external_value(PARAM_INT, 'Number of queued items', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_TEXT, 'Success message', VALUE_OPTIONAL),
            'error'   => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
