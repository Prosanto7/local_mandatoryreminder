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
 * Ad-hoc task to process email queue (batch or targeted send).
 *
 * Custom data keys (all optional):
 *   item_ids       (array of int) - process only these specific queue IDs
 *   recipient_type (string)       - process all pending items of this type
 *                                   (e.g. 'employee' for "Send All Students")
 * When neither is set the task processes a regular pending-item batch.
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_mandatoryreminder\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/mandatoryreminder/lib.php');
require_once($CFG->dirroot . '/local/mandatoryreminder/classes/email_sender.php');

use local_mandatoryreminder\email_sender;

/**
 * Process queue ad-hoc task.
 */
class process_queue extends \core\task\adhoc_task {

    public function execute() {
        global $DB;

        mtrace('Processing mandatory reminder email queue...');

        // Recover stuck items.
        $stuckthreshold = time() - (30 * 60);
        $stuckcount = $DB->count_records_select(
            'local_mandatoryreminder_queue',
            "status = 'processing' AND timemodified < :threshold",
            ['threshold' => $stuckthreshold]
        );
        if ($stuckcount > 0) {
            mtrace("Recovering {$stuckcount} stuck item(s) (stale >30 min)...");
            $DB->execute(
                "UPDATE {local_mandatoryreminder_queue}
                    SET status = 'pending', timemodified = :now
                  WHERE status = 'processing' AND timemodified < :threshold",
                ['now' => time(), 'threshold' => $stuckthreshold]
            );
        }

        // Determine which items to process (targeted vs. batch).
        $customdata = $this->get_custom_data();

        if ($customdata && !empty($customdata->item_ids)) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', $customdata->item_ids),
                SQL_PARAMS_NAMED
            );
            $queueitems = $DB->get_records_select(
                'local_mandatoryreminder_queue',
                "status = 'pending' AND id {$insql}",
                $inparams,
                'timecreated ASC'
            );
            $targeted = true;
            mtrace('Targeted run: ' . count($queueitems) . ' specific item(s)');

        } else if ($customdata && !empty($customdata->recipient_type)) {
            $rtype     = clean_param($customdata->recipient_type, PARAM_ALPHA);
            $batchsize = (int)(get_config('local_mandatoryreminder', 'email_batch_size') ?: 50);
            $queueitems = $DB->get_records(
                'local_mandatoryreminder_queue',
                ['status' => 'pending', 'recipient_type' => $rtype],
                'timecreated ASC', '*', 0, $batchsize
            );
            $targeted = false;
            mtrace("Type-filtered run: type={$rtype}, found=" . count($queueitems));

        } else {
            $batchsize  = (int)(get_config('local_mandatoryreminder', 'email_batch_size') ?: 50);
            $queueitems = $DB->get_records(
                'local_mandatoryreminder_queue',
                ['status' => 'pending'],
                'timecreated ASC', '*', 0, $batchsize
            );
            $targeted = false;
            mtrace("Batch run: size={$batchsize}, found=" . count($queueitems));
        }

        if (count($queueitems) === 0) {
            mtrace('Queue is empty — nothing to do');
            return;
        }

        $successcount = 0;
        $failcount    = 0;

        foreach ($queueitems as $item) {
            // Re-read: sibling dedup may have already marked this row sent.
            $freshstatus = $DB->get_field('local_mandatoryreminder_queue', 'status', ['id' => $item->id]);
            if ($freshstatus !== 'pending') {
                mtrace("  Item ID {$item->id}: now '{$freshstatus}' — skipping");
                continue;
            }

            $item->status       = 'processing';
            $item->timemodified = time();
            $DB->update_record('local_mandatoryreminder_queue', $item);

            mtrace("  Item ID {$item->id}: user={$item->userid}, course={$item->courseid}," .
                " level={$item->level}, type={$item->recipient_type}, to={$item->recipient_email}");

            try {
                $success = email_sender::send_item($item);

                if ($success) {
                    $item->status   = 'sent';
                    $item->timesent = time();
                    $successcount++;
                    mtrace('    -> Sent successfully');

                    email_sender::mark_siblings_sent($item);

                    $notified = email_sender::send_notification($item);
                    if ($item->recipient_type === 'employee') {
                        mtrace('    -> Notification: ' . ($notified ? 'sent' : 'skipped'));
                    }

                    $enrol = email_sender::get_enrolment($item->userid, $item->courseid);
                    if ($enrol) {
                        $deadline     = local_mandatoryreminder_get_course_deadline($item->courseid);
                        $deadlinedate = $enrol->timecreated + ($deadline * 86400);
                        local_mandatoryreminder_log_sent(
                            $item->userid, $item->courseid, $item->level,
                            $enrol->timecreated, $deadlinedate
                        );
                    }

                } else {
                    $item->status        = 'failed';
                    $item->attempts++;
                    $item->error_message = 'email_to_user() returned false';
                    $failcount++;
                    mtrace("    -> Failed (attempt {$item->attempts})");
                }

            } catch (\Exception $e) {
                $item->status        = 'failed';
                $item->attempts++;
                $item->error_message = substr($e->getMessage(), 0, 255);
                $failcount++;
                mtrace('    -> Exception: ' . $e->getMessage());
            }

            $item->timemodified = time();
            $DB->update_record('local_mandatoryreminder_queue', $item);
        }

        mtrace("Batch complete — sent: {$successcount}, failed: {$failcount}");

        // Chain next batch for untargeted runs only.
        if (!$targeted) {
            $remaining = $DB->count_records('local_mandatoryreminder_queue', ['status' => 'pending']);
            if ($remaining > 0) {
                mtrace("Still {$remaining} pending — chaining next batch...");
                $nexttask = new \local_mandatoryreminder\task\process_queue();
                if ($customdata && !empty($customdata->recipient_type)) {
                    $nexttask->set_custom_data(['recipient_type' => $customdata->recipient_type]);
                }
                \core\task\manager::queue_adhoc_task($nexttask);
            } else {
                mtrace('All emails processed — queue is now empty');
            }
        }

        mtrace('Email queue processing completed');
    }
}
