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
 * Management (Supervisor & SBU Head) reminder list.
 *
 * Each row represents ONE aggregated email to be sent to a recipient
 * (supervisor or SBU head).  A single supervisor may appear multiple times
 * if they manage employees in different courses or reminder levels.
 *
 * Admins can preview, send per-row, or select multiple rows and send them.
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/mandatoryreminder:sendemails', context_system::instance());

// ----------------------------------------------------------------
// Parameters
// ----------------------------------------------------------------
$page       = optional_param('page',       0,  PARAM_INT);
$perpage    = optional_param('perpage',    50, PARAM_INT);
$search     = optional_param('search',     '', PARAM_TEXT);
$filtertype = optional_param('filtertype', '', PARAM_ALPHA);
$filterlevel = optional_param('filterlevel', 0, PARAM_INT);
$sort       = optional_param('tsort',      'timecreated', PARAM_ALPHANUMEXT);
$dir        = optional_param('tdir',       SORT_DESC,     PARAM_INT);
$download   = optional_param('download',   '', PARAM_ALPHA);

$baseurl = new moodle_url('/local/mandatoryreminder/management_list.php', [
    'perpage'     => $perpage,
    'search'      => $search,
    'filtertype'  => $filtertype,
    'filterlevel' => $filterlevel,
]);

$PAGE->set_url($baseurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('management_list', 'local_mandatoryreminder'));
$PAGE->set_heading(get_string('management_list', 'local_mandatoryreminder'));
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Configure flexible_table
// ----------------------------------------------------------------
$table = new flexible_table('local_mandatoryreminder_management');

$table->define_columns([
    'checkbox', 'recipient', 'coursefullname',
    'recipient_type', 'level', 'employee_count',
    'status', 'timecreated', 'timesent', 'actions',
]);
$table->define_headers([
    '',
    get_string('recipient',      'local_mandatoryreminder'),
    get_string('course'),
    get_string('recipient_type', 'local_mandatoryreminder'),
    get_string('level',          'local_mandatoryreminder'),
    get_string('employees',      'local_mandatoryreminder'),
    get_string('status',         'local_mandatoryreminder'),
    get_string('created',        'local_mandatoryreminder'),
    get_string('timesent',       'local_mandatoryreminder'),
    get_string('actions',        'local_mandatoryreminder'),
]);

$table->define_baseurl($baseurl);
$table->set_attribute('class', 'generaltable');
$table->set_attribute('id', 'mr-management-table');

$table->sortable(true, 'timecreated', SORT_DESC);
$table->no_sorting('checkbox');
$table->no_sorting('recipient');
$table->no_sorting('status');
$table->no_sorting('actions');
$table->text_sorting('coursefullname');

$table->column_class('level',          'text-center');
$table->column_class('employee_count', 'text-center');
$table->column_class('status',         'text-center');
$table->column_class('checkbox',       'text-center');
$table->column_class('recipient_type', 'text-nowrap');
$table->column_class('timecreated',    'text-nowrap');
$table->column_class('timesent',       'text-nowrap');
$table->column_class('actions',        'text-nowrap text-right');

$table->collapsible(true);
$table->is_persistent(true);
$table->set_caption(
    get_string('management_list', 'local_mandatoryreminder'),
    ['class' => 'sr-only']
);

$table->is_downloading($download, 'management_reminders_' . date('Y-m-d'),
    get_string('management_list', 'local_mandatoryreminder'));
$table->is_downloadable(true);
$table->show_download_buttons_at([TABLE_P_TOP, TABLE_P_BOTTOM]);

$table->setup();

// ----------------------------------------------------------------
// Build GROUP BY SQL
// Each row = one (recipient_email, recipient_type, courseid, level) group.
// representative_id = MIN(id) — used as the "send handle" for AJAX actions.
// ----------------------------------------------------------------
$params = [];
$having = [];
$where  = ["q.recipient_type IN ('supervisor', 'sbuhead')"];

if (!empty($search)) {
    $sp = '%' . $DB->sql_like_escape($search) . '%';
    $where[]        = '(' .
        $DB->sql_like('q.recipient_email', ':s1', false) . ' OR ' .
        $DB->sql_like('c.fullname',        ':s2', false) . ')';
    $params['s1'] = $sp;
    $params['s2'] = $sp;
}
if (!empty($filtertype)) {
    $where[]              = 'q.recipient_type = :filtertype';
    $params['filtertype'] = $filtertype;
}
if (!empty($filterlevel)) {
    $where[]               = 'q.level = :filterlevel';
    $params['filterlevel'] = (int)$filterlevel;
}

$wheresql = 'WHERE ' . implode(' AND ', $where);

// Allowed sort columns → SQL expressions on the grouped result.
$sortmap = [
    'coursefullname'  => 'c.fullname',
    'recipient_type'  => 'q.recipient_type',
    'level'           => 'q.level',
    'employee_count'  => 'employee_count',
    'timecreated'     => 'timecreated',
    'timesent'        => 'timesent',
];
if (!array_key_exists($sort, $sortmap)) {
    $sort = 'timecreated';
}
$ordersql = $sortmap[$sort] . ' ' . ($dir == SORT_ASC ? 'ASC' : 'DESC');

// The GROUP BY aggregation (MySQL / PostgreSQL / MSSQL compatible CASE WHEN).
$groupbysql = "
    SELECT
        MIN(q.id)           AS representative_id,
        q.recipient_email,
        q.recipient_type,
        q.courseid,
        q.level,
        c.fullname          AS coursefullname,
        COUNT(q.id)         AS employee_count,
        SUM(CASE WHEN q.status = 'pending'    THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN q.status = 'sent'       THEN 1 ELSE 0 END) AS sent_count,
        SUM(CASE WHEN q.status = 'failed'     THEN 1 ELSE 0 END) AS failed_count,
        SUM(CASE WHEN q.status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
        MIN(q.timecreated)  AS timecreated,
        MAX(q.timesent)     AS timesent
    FROM {local_mandatoryreminder_queue} q
    JOIN {course} c ON c.id = q.courseid
    {$wheresql}
    GROUP BY q.recipient_email, q.recipient_type, q.courseid, q.level, c.fullname
";

$countsql = "SELECT COUNT(*) FROM ({$groupbysql}) grouped_counts";
$selectsql = "SELECT * FROM ({$groupbysql}) grouped_data ORDER BY {$ordersql}";

$totalcount = $DB->count_records_sql($countsql, $params);

if ($table->is_downloading()) {
    $groups = $DB->get_records_sql($selectsql, $params);
} else {
    $groups = $DB->get_records_sql($selectsql, $params, $page * $perpage, $perpage);
}

// Pre-fetch Moodle users for all recipient emails in one query.
$userbyemail = [];
if (!empty($groups)) {
    $emails = array_unique(array_column((array)$groups, 'recipient_email'));
    if (!empty($emails)) {
        [$emailsql, $emailparams] = $DB->get_in_or_equal($emails, SQL_PARAMS_NAMED);
        $userrows = $DB->get_records_select(
            'user',
            "deleted = 0 AND email {$emailsql}",
            $emailparams,
            '',
            'id, email, firstname, lastname, picture, imagealt, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
        foreach ($userrows as $u) {
            if (!isset($userbyemail[$u->email])) {
                $userbyemail[$u->email] = $u;
            }
        }
    }
}

// ----------------------------------------------------------------
// Page header
// ----------------------------------------------------------------
if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('management_list', 'local_mandatoryreminder'));

    // Bulk action bar.
    echo html_writer::start_div('d-flex flex-wrap align-items-center mb-3');
    echo html_writer::tag('button',
        get_string('send_selected', 'local_mandatoryreminder'),
        ['id' => 'btn-send-selected', 'class' => 'btn btn-primary mr-2', 'style' => 'display:none']
    );
    echo html_writer::end_div();

    // Filter form.
    $reseturl = new moodle_url('/local/mandatoryreminder/management_list.php');
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $reseturl->out(false),
        'class'  => 'd-flex flex-wrap align-items-center mb-3',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'perpage', 'value' => $perpage]);

    // Search by email / course.
    echo html_writer::start_div('input-group input-group-sm mr-2', ['style' => 'max-width:260px']);
    echo html_writer::empty_tag('input', [
        'type'        => 'text',
        'name'        => 'search',
        'value'       => s($search),
        'placeholder' => get_string('search'),
        'class'       => 'form-control',
    ]);
    echo html_writer::end_div();

    // Recipient type filter.
    echo html_writer::start_div('form-group form-group-sm mb-0 mr-2');
    echo html_writer::select([
        ''           => get_string('alltypes',            'local_mandatoryreminder'),
        'supervisor' => get_string('recipient_supervisor', 'local_mandatoryreminder'),
        'sbuhead'    => get_string('recipient_sbuhead',    'local_mandatoryreminder'),
    ], 'filtertype', $filtertype, false,
        ['id' => 'filtertype', 'class' => 'custom-select custom-select-sm']);
    echo html_writer::end_div();

    // Level filter.
    $selectedlevel = $filterlevel ? (string)$filterlevel : '';
    echo html_writer::start_div('form-group form-group-sm mb-0 mr-2');
    echo html_writer::select([
        ''  => get_string('allevels', 'local_mandatoryreminder'),
        '3' => get_string('level', 'local_mandatoryreminder') . ' 3',
        '4' => get_string('level', 'local_mandatoryreminder') . ' 4',
    ], 'filterlevel', $selectedlevel, false,
        ['id' => 'filterlevel', 'class' => 'custom-select custom-select-sm']);
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => get_string('filter', 'local_mandatoryreminder'),
        'class' => 'btn btn-secondary btn-sm mr-1',
    ]);
    echo html_writer::link($reseturl, get_string('resetfilters', 'local_mandatoryreminder'),
        ['class' => 'btn btn-outline-secondary btn-sm']);
    echo html_writer::end_tag('form');

    echo html_writer::tag('p',
        get_string('showing_results', 'local_mandatoryreminder', $totalcount),
        ['class' => 'text-muted small mb-2']
    );
}

// ----------------------------------------------------------------
// Feed rows
// ----------------------------------------------------------------
$table->pagesize($perpage, $totalcount);

$datetimefmt = get_string('strftimedatetime', 'langconfig');

foreach ($groups as $group) {
    // Derive group status.
    if ($group->pending_count > 0) {
        $groupstatus = 'pending';
    } else if ($group->processing_count > 0) {
        $groupstatus = 'processing';
    } else if ($group->failed_count > 0) {
        $groupstatus = 'failed';
    } else {
        $groupstatus = 'sent';
    }

    if ($table->is_downloading()) {
        $row = [
            '',
            $group->recipient_email,
            $group->coursefullname,
            get_string('recipient_' . $group->recipient_type, 'local_mandatoryreminder'),
            $group->level,
            $group->employee_count,
            get_string('status_' . $groupstatus, 'local_mandatoryreminder'),
            userdate($group->timecreated, $datetimefmt),
            $group->timesent ? userdate($group->timesent, $datetimefmt) : '',
            '',
        ];
    } else {
        // Checkbox (only for pending groups).
        $checkbox = ($groupstatus === 'pending')
            ? html_writer::checkbox('rowids[]', $group->representative_id, false, '',
                ['class' => 'rowcheckbox', 'data-id' => $group->representative_id])
            : '';

        // Recipient column: just email address.
        $recipientuser = $userbyemail[$group->recipient_email] ?? null;
        $displayname = $recipientuser ? fullname($recipientuser) : '';
        if ($displayname) {
            $recipienthtml = html_writer::tag('strong', $displayname) . '<br>' .
                html_writer::tag('small', $group->recipient_email, ['class' => 'text-muted']);
        } else {
            $recipienthtml = $group->recipient_email;
        }

        $courselink = html_writer::link(
            new moodle_url('/course/view.php', ['id' => $group->courseid]),
            format_string($group->coursefullname)
        );

        $typebadgeclass = ($group->recipient_type === 'sbuhead') ? 'badge-warning' : 'badge-info';
        $typehtml = html_writer::tag('span',
            get_string('recipient_' . $group->recipient_type, 'local_mandatoryreminder'),
            ['class' => 'badge ' . $typebadgeclass]
        );

        $statusbadgeclasses = [
            'sent'       => 'badge badge-success',
            'failed'     => 'badge badge-danger',
            'processing' => 'badge badge-warning',
            'pending'    => 'badge badge-secondary',
        ];
        $statushtml = html_writer::tag('span',
            get_string('status_' . $groupstatus, 'local_mandatoryreminder'),
            ['class' => ($statusbadgeclasses[$groupstatus] ?? 'badge badge-secondary') . ' group-status-badge',
             'data-rep' => $group->representative_id]
        );

        $timesent = $group->timesent
            ? html_writer::tag('span', userdate($group->timesent, $datetimefmt),
                ['class' => 'timesent-cell', 'data-rep' => $group->representative_id])
            : html_writer::tag('span', get_string('never'),
                ['class' => 'timesent-cell text-muted', 'data-rep' => $group->representative_id]);

        // Action buttons.
        $actions = html_writer::tag('button',
            get_string('preview_email', 'local_mandatoryreminder'),
            ['class' => 'btn btn-outline-secondary btn-sm mr-1 btn-preview',
             'data-id' => $group->representative_id]
        );
        if ($groupstatus === 'pending') {
            $actions .= html_writer::tag('button',
                get_string('send', 'local_mandatoryreminder'),
                ['class' => 'btn btn-primary btn-sm btn-send',
                 'data-id' => $group->representative_id,
                 'data-count' => $group->pending_count]
            );
        }

        $row = [
            $checkbox,
            $recipienthtml,
            $courselink,
            $typehtml,
            $group->level,
            html_writer::tag('span', $group->employee_count, ['class' => 'badge badge-light']),
            $statushtml,
            userdate($group->timecreated, $datetimefmt),
            $timesent,
            $actions,
        ];
    }
    $table->add_data($row);
}

$table->finish_output();

if (!$table->is_downloading()) {
    // Initialize AMD module for management list actions.
    $ajaxurl = (new moodle_url('/local/mandatoryreminder/ajax.php'))->out(false);
    $PAGE->requires->js_call_amd('local_mandatoryreminder/management_list', 'init', [
        $ajaxurl,
        sesskey()
    ]);

    echo $OUTPUT->footer();
}
