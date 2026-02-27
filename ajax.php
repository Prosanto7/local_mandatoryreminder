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
 * AJAX endpoint for human-triggered email send actions.
 *
 * All actions require the 'local/mandatoryreminder:sendemails' capability and a
 * valid sesskey.  Every response is JSON.
 *
 * Actions
 * -------
 * preview          GET  id=<int>          → {success, subject, body}
 * send             POST id=<int>          → {success, status, timesent_formatted, error?}
 * send_selected    POST ids[]=<int>,...   → {success, sent, failed, message}
 * send_all         POST (no extra params) → queues adhoc for ALL pending employees
 *                                           → {success, count, message}
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/mandatoryreminder/lib.php');
require_once($CFG->dirroot . '/local/mandatoryreminder/classes/email_sender.php');

use local_mandatoryreminder\email_sender;

require_login();

// Every action needs this capability and a valid sesskey.
require_capability('local/mandatoryreminder:sendemails', context_system::instance());
require_sesskey();

header('Content-Type: application/json');

$action = required_param('action', PARAM_ALPHA);

// ============================================================
// Helper: finalise a successful send for one item.
// Updates DB and logs; does NOT call $DB->update_record yet —
// that is the caller's responsibility after calling this.
// ============================================================
function mandatoryreminder_complete_send(\stdClass &$item): void {
    global $DB;

    $item->status   = 'sent';
    $item->timesent = time();

    // Dedup sibling management rows.
    email_sender::mark_siblings_sent($item);

    // In-app notification.
    email_sender::send_notification($item);

    // Audit log.
    $enrol = email_sender::get_enrolment($item->userid, $item->courseid);
    if ($enrol) {
        $deadline     = local_mandatoryreminder_get_course_deadline($item->courseid);
        $deadlinedate = $enrol->timecreated + ($deadline * 86400);
        local_mandatoryreminder_log_sent(
            $item->userid, $item->courseid, $item->level,
            $enrol->timecreated, $deadlinedate
        );
    }
}

// ============================================================
// PREVIEW — returns pre-rendered or dynamically computed email
// ============================================================
if ($action === 'preview') {
    $id   = required_param('id', PARAM_INT);
    $item = $DB->get_record('local_mandatoryreminder_queue', ['id' => $id], '*', MUST_EXIST);

    $preview = email_sender::get_preview($item);

    echo json_encode([
        'success' => true,
        'subject' => $preview['subject'],
        'body'    => $preview['body'],
    ]);
    exit;
}

// ============================================================
// SEND — single item, direct synchronous dispatch
// ============================================================
if ($action === 'send') {
    $id   = required_param('id', PARAM_INT);
    $item = $DB->get_record('local_mandatoryreminder_queue', ['id' => $id], '*', MUST_EXIST);

    // Reject if not still pending.
    $freshstatus = $DB->get_field('local_mandatoryreminder_queue', 'status', ['id' => $id]);
    if ($freshstatus !== 'pending') {
        echo json_encode(['success' => false, 'error' => "Item is already '{$freshstatus}'"]);
        exit;
    }

    // Mark processing.
    $item->status       = 'processing';
    $item->timemodified = time();
    $DB->update_record('local_mandatoryreminder_queue', $item);

    try {
        $ok = email_sender::send_item($item);
        if ($ok) {
            mandatoryreminder_complete_send($item);
        } else {
            $item->status        = 'failed';
            $item->attempts++;
            $item->error_message = 'email_to_user() returned false';
        }
    } catch (\Exception $e) {
        $item->status        = 'failed';
        $item->attempts++;
        $item->error_message = substr($e->getMessage(), 0, 255);
    }

    $item->timemodified = time();
    $DB->update_record('local_mandatoryreminder_queue', $item);

    $datetimefmt = get_string('strftimedatetime', 'langconfig');
    echo json_encode([
        'success'           => ($item->status === 'sent'),
        'status'            => $item->status,
        'timesent_formatted' => $item->timesent ? userdate($item->timesent, $datetimefmt) : '',
        'error'             => ($item->status !== 'sent') ? ($item->error_message ?? '') : null,
    ]);
    exit;
}

// ============================================================
// SEND_SELECTED — send a specific set of item IDs directly.
// For small batches (≤ 25) items are sent synchronously.
// For larger batches an adhoc task is queued instead.
// ============================================================
if ($action === 'send_selected') {
    $ids = required_param_array('ids', PARAM_INT);
    $ids = array_filter(array_map('intval', $ids));

    if (empty($ids)) {
        echo json_encode(['success' => false, 'error' => 'No IDs provided']);
        exit;
    }

    $threshold = 25; // Synchronous limit.

    if (count($ids) <= $threshold) {
        // Direct synchronous send.
        $sent   = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $freshstatus = $DB->get_field('local_mandatoryreminder_queue', 'status', ['id' => $id]);
            if ($freshstatus !== 'pending') {
                continue; // Already processed (sibling dedup).
            }

            $item               = $DB->get_record('local_mandatoryreminder_queue', ['id' => $id], '*', MUST_EXIST);
            $item->status       = 'processing';
            $item->timemodified = time();
            $DB->update_record('local_mandatoryreminder_queue', $item);

            try {
                $ok = email_sender::send_item($item);
                if ($ok) {
                    mandatoryreminder_complete_send($item);
                    $sent++;
                } else {
                    $item->status        = 'failed';
                    $item->attempts++;
                    $item->error_message = 'email_to_user() returned false';
                    $failed++;
                }
            } catch (\Exception $e) {
                $item->status        = 'failed';
                $item->attempts++;
                $item->error_message = substr($e->getMessage(), 0, 255);
                $failed++;
            }

            $item->timemodified = time();
            $DB->update_record('local_mandatoryreminder_queue', $item);
        }

        echo json_encode([
            'success' => true,
            'sent'    => $sent,
            'failed'  => $failed,
            'message' => get_string('send_result', 'local_mandatoryreminder', ['sent' => $sent, 'failed' => $failed]),
        ]);

    } else {
        // Queue adhoc task for large batches.
        $task = new \local_mandatoryreminder\task\process_queue();
        $task->set_custom_data(['item_ids' => array_values($ids)]);
        \core\task\manager::queue_adhoc_task($task);

        echo json_encode([
            'success' => true,
            'queued'  => count($ids),
            'message' => get_string('send_queued', 'local_mandatoryreminder', count($ids)),
        ]);
    }
    exit;
}

// ============================================================
// SEND_ALL — queue adhoc task for ALL pending employee items.
// Always uses the task queue (could be hundreds of emails).
// ============================================================
if ($action === 'send_all') {
    $count = $DB->count_records(
        'local_mandatoryreminder_queue',
        ['status' => 'pending', 'recipient_type' => 'employee']
    );

    if ($count === 0) {
        echo json_encode(['success' => false, 'error' => get_string('no_pending_items', 'local_mandatoryreminder')]);
        exit;
    }

    $task = new \local_mandatoryreminder\task\process_queue();
    $task->set_custom_data(['recipient_type' => 'employee']);
    \core\task\manager::queue_adhoc_task($task);

    echo json_encode([
        'success' => true,
        'count'   => $count,
        'message' => get_string('send_all_queued', 'local_mandatoryreminder', $count),
    ]);
    exit;
}

// Unknown action.
echo json_encode(['success' => false, 'error' => 'Unknown action: ' . s($action)]);
