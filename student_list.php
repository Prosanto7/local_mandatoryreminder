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
 * Student reminder list — human-triggered email send page.
 *
 * Shows every employee-type queue item.  Admins can:
 *   - Preview the email before sending
 *   - Send individual items via the per-row button
 *   - Select multiple rows and Send Selected
 *   - Send All Pending (queues an adhoc task)
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
$page         = optional_param('page',         0,  PARAM_INT);
$perpage      = optional_param('perpage',      50, PARAM_INT);
$search       = optional_param('search',       '', PARAM_TEXT);
$filterstatus = optional_param('filterstatus', '', PARAM_ALPHA);
$filterlevel  = optional_param('filterlevel',  0,  PARAM_INT);
$sort         = optional_param('tsort',        'timecreated', PARAM_ALPHANUMEXT);
$dir          = optional_param('tdir',         SORT_DESC,     PARAM_INT);
$download     = optional_param('download',     '', PARAM_ALPHA);

$baseurl = new moodle_url('/local/mandatoryreminder/student_list.php', [
    'perpage'      => $perpage,
    'search'       => $search,
    'filterstatus' => $filterstatus,
    'filterlevel'  => $filterlevel,
]);

$PAGE->set_url($baseurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('student_list', 'local_mandatoryreminder'));
$PAGE->set_heading(get_string('student_list', 'local_mandatoryreminder'));
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Configure flexible_table
// ----------------------------------------------------------------
$table = new flexible_table('local_mandatoryreminder_students');

$table->define_columns([
    'checkbox', 'picture', 'fullname', 'course_count', 'highest_level',
    'recipient_email', 'status', 'attempts', 'timecreated', 'timesent', 'actions',
]);
$table->define_headers([
    '',  // checkbox column — header built manually below
    get_string('picture', 'local_mandatoryreminder'),
    get_string('fullname'),
    get_string('course_count', 'local_mandatoryreminder'),
    get_string('highest_level', 'local_mandatoryreminder'),
    get_string('email'),
    get_string('status',         'local_mandatoryreminder'),
    get_string('attempts',       'local_mandatoryreminder'),
    get_string('created',        'local_mandatoryreminder'),
    get_string('timesent',       'local_mandatoryreminder'),
    get_string('actions',        'local_mandatoryreminder'),
]);

$table->define_baseurl($baseurl);
$table->set_attribute('class', 'generaltable');
$table->set_attribute('id', 'mr-student-table');

$table->sortable(true, 'timecreated', SORT_DESC);
$table->no_sorting('checkbox');
$table->no_sorting('recipient_email');
$table->no_sorting('actions');
$table->no_sorting('course_count');
$table->no_sorting('highest_level');
$table->text_sorting('fullname');

$table->define_header_column('fullname');
$table->column_class('course_count',   'text-center');
$table->column_class('highest_level',  'text-center');
$table->column_class('status',         'text-center');
$table->column_class('attempts',       'text-center');
$table->column_class('actions',        'text-nowrap text-right');
$table->column_class('checkbox',       'text-center');
$table->column_class('timecreated',    'text-nowrap');
$table->column_class('timesent',       'text-nowrap');

$table->collapsible(true);
$table->initialbars(true);
$table->is_persistent(true);
$table->set_caption(
    get_string('student_list', 'local_mandatoryreminder'),
    ['class' => 'sr-only']
);

$table->is_downloading($download, 'student_reminders_' . date('Y-m-d'),
    get_string('student_list', 'local_mandatoryreminder'));
$table->is_downloadable(true);
$table->show_download_buttons_at([TABLE_P_TOP, TABLE_P_BOTTOM]);

$table->setup();

// ----------------------------------------------------------------
// Build SQL
// ----------------------------------------------------------------
$params = [];
$where  = ['q.recipient_type = \'employee\''];

if (!empty($search)) {
    $sp = '%' . $DB->sql_like_escape($search) . '%';
    $where[]          = '(' .
        $DB->sql_like('u.firstname',       ':s1', false) . ' OR ' .
        $DB->sql_like('u.lastname',        ':s2', false) . ' OR ' .
        $DB->sql_like('q.recipient_email', ':s3', false) . ')';
    $params['s1'] = $sp; $params['s2'] = $sp;
    $params['s3'] = $sp;
}

$ifirst = $table->get_initial_first();
$ilast  = $table->get_initial_last();
if ($ifirst !== null && $ifirst !== '') {
    $where[]           = $DB->sql_like('u.firstname', ':ifirst', false);
    $params['ifirst']  = $DB->sql_like_escape($ifirst) . '%';
}
if ($ilast !== null && $ilast !== '') {
    $where[]          = $DB->sql_like('u.lastname', ':ilast', false);
    $params['ilast']  = $DB->sql_like_escape($ilast) . '%';
}

if (!empty($filterstatus)) {
    $where[]                = 'q.status = :filterstatus';
    $params['filterstatus'] = $filterstatus;
}
// Note: filterlevel removed as level is now in courses_data JSON

$wheresql = 'WHERE ' . implode(' AND ', $where);

$sortmap = [
    'fullname'        => 'u.lastname, u.firstname',
    'recipient_email' => 'q.recipient_email',
    'status'          => 'q.status',
    'attempts'        => 'q.attempts',
    'timecreated'     => 'q.timecreated',
    'timesent'        => 'q.timesent',
];
if (!array_key_exists($sort, $sortmap)) {
    $sort = 'timecreated';
}
$ordersql = $sortmap[$sort] . ' ' . ($dir == SORT_ASC ? 'ASC' : 'DESC');

$basefrom = "FROM {local_mandatoryreminder_queue} q
              JOIN {user} u ON u.id = q.userid
            {$wheresql}";

$totalcount = $DB->count_records_sql("SELECT COUNT(q.id) {$basefrom}", $params);

$selectsql = "SELECT q.id, q.userid, q.recipient_email, q.courses_data,
                     q.status, q.attempts, q.timecreated, q.timesent, q.error_message,
                     u.firstname, u.lastname, u.picture, u.imagealt,
                     u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
              {$basefrom}
              ORDER BY {$ordersql}";

if ($table->is_downloading()) {
    $items = $DB->get_records_sql($selectsql, $params);
} else {
    $items = $DB->get_records_sql($selectsql, $params, $page * $perpage, $perpage);
}

// Filter by highest level if filterlevel is set (must be done after fetch since level is in JSON).
if (!empty($filterlevel) && !empty($items)) {
    $filteredItems = [];
    foreach ($items as $item) {
        $coursesdata = !empty($item->courses_data) ? json_decode($item->courses_data, true) : [];
        if (!empty($coursesdata)) {
            $highestlevel = max(array_column($coursesdata, 'level'));
            if ($highestlevel == $filterlevel) {
                $filteredItems[] = $item;
            }
        }
    }
    $items = $filteredItems;
    // Note: This filtering happens after pagination, so counts may be off.
    // For production, consider filtering before pagination or adjusting approach.
}

// ----------------------------------------------------------------
// Page header (skip when downloading)
// ----------------------------------------------------------------
if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('student_list', 'local_mandatoryreminder'));

    // Pending count badge.
    $pendingcount = $DB->count_records('local_mandatoryreminder_queue',
        ['status' => 'pending', 'recipient_type' => 'employee']);

    // Top action bar: Send All + Send Selected.
    echo html_writer::start_div('d-flex flex-wrap align-items-center mb-3');
    echo html_writer::tag('button',
        get_string('send_all_pending', 'local_mandatoryreminder') .
        ' ' . html_writer::tag('span', $pendingcount, ['class' => 'badge badge-light']),
        [
            'id'    => 'btn-send-all',
            'class' => 'btn btn-success mr-2',
            'data-count' => $pendingcount,
        ]
    );
    echo html_writer::tag('button',
        get_string('send_selected', 'local_mandatoryreminder'),
        [
            'id'    => 'btn-send-selected',
            'class' => 'btn btn-primary mr-2',
            'style' => 'display:none',
        ]
    );
    echo html_writer::end_div();

    // Filter form.
    $reseturl = new moodle_url('/local/mandatoryreminder/student_list.php');
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $reseturl->out(false),
        'class'  => 'd-flex flex-wrap align-items-center mb-3 gap-2',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'perpage', 'value' => $perpage]);

    // Search.
    echo html_writer::start_div('input-group input-group-sm mr-2', ['style' => 'max-width:260px']);
    echo html_writer::empty_tag('input', [
        'type'        => 'text',
        'name'        => 'search',
        'value'       => s($search),
        'placeholder' => get_string('search'),
        'class'       => 'form-control',
    ]);
    echo html_writer::end_div();

    // Status filter.
    echo html_writer::start_div('form-group form-group-sm mb-0 mr-2');
    echo html_writer::select([
        ''           => get_string('allstatuses',       'local_mandatoryreminder'),
        'pending'    => get_string('status_pending',    'local_mandatoryreminder'),
        'processing' => get_string('status_processing', 'local_mandatoryreminder'),
        'sent'       => get_string('status_sent',       'local_mandatoryreminder'),
        'failed'     => get_string('status_failed',     'local_mandatoryreminder'),
    ], 'filterstatus', $filterstatus, false,
        ['id' => 'filterstatus', 'class' => 'custom-select custom-select-sm']);
    echo html_writer::end_div();

    // Level filter.
    $selectedlevel = $filterlevel ? (string)$filterlevel : '';
    echo html_writer::start_div('form-group form-group-sm mb-0 mr-2');
    echo html_writer::select([
        ''  => get_string('allevels', 'local_mandatoryreminder'),
        '1' => get_string('level', 'local_mandatoryreminder') . ' 1',
        '2' => get_string('level', 'local_mandatoryreminder') . ' 2',
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

    // Results summary.
    echo html_writer::tag('p',
        get_string('showing_results', 'local_mandatoryreminder', $totalcount),
        ['class' => 'text-muted small mb-2']
    );
}

// ----------------------------------------------------------------
// Feed rows
// ----------------------------------------------------------------
$table->pagesize($perpage, $totalcount);

$statusclasses = [
    'sent'       => 'badge badge-success',
    'failed'     => 'badge badge-danger',
    'processing' => 'badge badge-warning',
    'pending'    => 'badge badge-secondary',
];
$datetimefmt = get_string('strftimedatetime', 'langconfig');

foreach ($items as $item) {
    // Parse courses_data to get course count and highest level.
    $coursesdata = !empty($item->courses_data) ? json_decode($item->courses_data, true) : [];
    $coursecount = count($coursesdata);
    $highestlevel = !empty($coursesdata) ? max(array_column($coursesdata, 'level')) : 0;
    
    // Get course names for display.
    $coursenames = [];
    if (!empty($coursesdata)) {
        foreach ($coursesdata as $cdata) {
            $coursenames[] = isset($cdata['coursename']) ? $cdata['coursename'] : 'Course ' . $cdata['courseid'];
        }
    }
    $courselist = !empty($coursenames) ? implode(', ', $coursenames) : get_string('none');

    if ($table->is_downloading()) {
        $row = [
            '',
            fullname($item),
            $coursecount,
            $highestlevel,
            $item->recipient_email,
            get_string('status_' . $item->status, 'local_mandatoryreminder'),
            $item->attempts,
            userdate($item->timecreated, $datetimefmt),
            $item->timesent ? userdate($item->timesent, $datetimefmt) : '',
            '',
        ];
    } else {
        // Checkbox.
        $checkbox = ($item->status === 'pending')
            ? html_writer::checkbox('rowids[]', $item->id, false, '',
                ['class' => 'rowcheckbox', 'data-id' => $item->id])
            : '';

        // Picture + name.
        $userobj = (object)[
            'id'                => $item->userid,
            'picture'           => $item->picture,
            'imagealt'          => $item->imagealt,
            'firstname'         => $item->firstname,
            'lastname'          => $item->lastname,
            'firstnamephonetic' => $item->firstnamephonetic,
            'lastnamephonetic'  => $item->lastnamephonetic,
            'middlename'        => $item->middlename,
            'alternatename'     => $item->alternatename,
        ];
        $pic      = $OUTPUT->user_picture($userobj, ['size' => 35, 'link' => false, 'class' => 'rounded-circle mr-2']);
        $namelink = html_writer::link(
            new moodle_url('/user/profile.php', ['id' => $item->userid]),
            fullname($item)
        );

        // Course count with tooltip showing course names.
        $coursecounthtml = html_writer::tag('span',
            $coursecount,
            ['title' => $courselist, 'data-toggle' => 'tooltip', 'class' => 'badge badge-info']
        );

        // Highest level with color coding.
        $levelclass = 'badge badge-secondary';
        if ($highestlevel == 4) {
            $levelclass = 'badge badge-danger';
        } else if ($highestlevel == 3) {
            $levelclass = 'badge badge-warning';
        } else if ($highestlevel >= 1) {
            $levelclass = 'badge badge-primary';
        }
        $levelhtml = html_writer::tag('span', $highestlevel, ['class' => $levelclass]);

        $statusclass = $statusclasses[$item->status] ?? 'badge badge-secondary';
        $statushtml  = html_writer::tag('span',
            get_string('status_' . $item->status, 'local_mandatoryreminder'),
            ['class' => $statusclass . ' status-badge']
        );
        if ($item->status === 'failed' && !empty($item->error_message)) {
            $statushtml .= ' ' . html_writer::tag('small',
                html_writer::tag('abbr', '(?)',
                    ['title' => s($item->error_message), 'class' => 'initialism text-muted']));
        }

        $timesent = $item->timesent
            ? html_writer::tag('span', userdate($item->timesent, $datetimefmt), ['class' => 'timesent-cell'])
            : html_writer::tag('span', get_string('never', 'local_mandatoryreminder'), ['class' => 'timesent-cell text-muted']);

        // Action buttons.
        $actions = html_writer::tag('button',
            get_string('preview_email', 'local_mandatoryreminder'),
            ['class' => 'btn btn-outline-secondary btn-sm mr-1 btn-preview', 'data-id' => $item->id]
        );
        if ($item->status === 'pending') {
            $actions .= html_writer::tag('button',
                get_string('send', 'local_mandatoryreminder'),
                ['class' => 'btn btn-primary btn-sm btn-send', 'data-id' => $item->id]
            );
        }

        $row = [
            $checkbox,
            $pic,
            $namelink,
            $coursecounthtml,
            $levelhtml,
            $item->recipient_email,
            $statushtml,
            $item->attempts,
            userdate($item->timecreated, $datetimefmt),
            $timesent,
            $actions,
        ];
    }
    $table->add_data($row);
}

$table->finish_output();

if (!$table->is_downloading()) {
    // Initialize AMD module for student list actions.
    $PAGE->requires->js_call_amd('local_mandatoryreminder/student_list', 'init', [
        '',
        sesskey()
    ]);

    echo $OUTPUT->footer();
}
