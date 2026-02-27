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
 * Dashboard page showing queue status and summary
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
require_capability('local/mandatoryreminder:viewdashboard', context_system::instance());

// ---------------------------------------------------------------
// URL parameters.
// ---------------------------------------------------------------
$page         = optional_param('page', 0, PARAM_INT);
$perpage      = optional_param('perpage', 50, PARAM_INT);
$search       = optional_param('search', '', PARAM_TEXT);
$filterstatus = optional_param('filterstatus', '', PARAM_ALPHA);
$filterlevel  = optional_param('filterlevel', 0, PARAM_INT);
$filtertype   = optional_param('filtertype', '', PARAM_ALPHA);
$sort         = optional_param('tsort', 'timecreated', PARAM_ALPHANUMEXT);
$dir          = optional_param('tdir', SORT_DESC, PARAM_INT);
$download     = optional_param('download', '', PARAM_ALPHA);

// Base URL carries all filter/paging params so flexible_table sort/initial links preserve them.
$baseurl = new moodle_url('/local/mandatoryreminder/dashboard.php', [
    'perpage'      => $perpage,
    'search'       => $search,
    'filterstatus' => $filterstatus,
    'filterlevel'  => $filterlevel,
    'filtertype'   => $filtertype,
]);

// PAGE setup — must be done before any output, including downloads.
$PAGE->set_url($baseurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('dashboard', 'local_mandatoryreminder'));
$PAGE->set_heading(get_string('dashboard', 'local_mandatoryreminder'));
$PAGE->set_pagelayout('admin');

// ================================================================
// Configure flexible_table BEFORE echoing the page header.
// is_downloading() must be called early so the table can emit the
// correct Content-Disposition / Content-Type HTTP headers instead
// of the normal HTML page headers when a download is requested.
// ================================================================
$table = new flexible_table('local_mandatoryreminder_queue');

$table->define_columns([
    'picture', 'fullname', 'coursefullname', 'level', 'recipient_type',
    'recipient_email', 'status', 'attempts', 'timecreated', 'timesent',
]);
$table->define_headers([
    get_string('picture', 'local_mandatoryreminder'),
    get_string('fullname'),
    get_string('course'),
    get_string('level',          'local_mandatoryreminder'),
    get_string('recipient_type', 'local_mandatoryreminder'),
    get_string('email'),
    get_string('status',         'local_mandatoryreminder'),
    get_string('attempts',       'local_mandatoryreminder'),
    get_string('created',        'local_mandatoryreminder'),
    get_string('timesent',       'local_mandatoryreminder'),
]);

$table->define_baseurl($baseurl);
$table->set_attribute('id', 'local_mandatoryreminder_queue_table');

// ---- Sorting ----
// sortable() enables column-header sort links.
// text_sorting() switches Oracle to use text-safe comparison for string columns.
$table->sortable(true, 'timecreated', SORT_DESC);
$table->no_sorting('recipient_email');
$table->text_sorting('fullname');
$table->text_sorting('coursefullname');
$table->text_sorting('status');
$table->text_sorting('recipient_type');

// ---- Column presentation ----
// define_header_column() marks the fullname cell as a <th> in each row (accessibility).
$table->define_header_column('fullname');

// column_sticky() keeps the fullname column visible when the table scrolls horizontally.
$table->column_sticky('fullname');

// column_suppress() hides repeated values in consecutive rows for the course column.
// Most useful when the table is sorted by course (reduces visual noise).
$table->column_suppress('coursefullname');

// column_class() adds utility CSS classes to individual columns.
$table->column_class('level',          'text-center');
$table->column_class('status',         'text-center');
$table->column_class('attempts',       'text-center');
$table->column_class('recipient_type', 'text-nowrap');
$table->column_class('timecreated',    'text-nowrap');
$table->column_class('timesent',       'text-nowrap');

// ---- User-experience ----
// collapsible() adds show/hide toggle icons in each column header.
$table->collapsible(true);

// initialbars() renders A–Z bars above the table for filtering rows by the user's name.
// The selected initial is read back with get_initial_first() / get_initial_last() after setup().
$table->initialbars(true);

// is_persistent() stores the user's preferred sort column/direction/hidden-columns
// in mdl_user_preferences so the choice survives page reloads.
$table->is_persistent(true);

// ---- Caption (accessibility) ----
$table->set_caption(
    get_string('email_queue', 'local_mandatoryreminder'),
    ['class' => 'sr-only']
);

// ---- Download support ----
// is_downloading() must be called before setup() so the table knows the output mode.
// When $download is non-empty the table emits HTTP download headers and writes
// CSV/ODS/Excel directly — no HTML page wrapper is needed.
$table->is_downloading(
    $download,
    'mandatory_reminder_queue_' . date('Y-m-d'),
    get_string('email_queue', 'local_mandatoryreminder')
);
$table->is_downloadable(true);
$table->show_download_buttons_at([TABLE_P_TOP, TABLE_P_BOTTOM]);

// ---- Finalise table configuration ----
// setup() reads tsort, tdir, page, ifirst, ilast from the URL / session.
$table->setup();

// ================================================================
// Build SQL WHERE clause from filters + A–Z initial bars.
// get_initial_first() / get_initial_last() are only valid after setup().
// ================================================================
$params = [];
$where  = [];

if (!empty($search)) {
    $searchparam      = '%' . $DB->sql_like_escape($search) . '%';
    $where[]          = '(' .
        $DB->sql_like('u.firstname',       ':search1', false) . ' OR ' .
        $DB->sql_like('u.lastname',        ':search2', false) . ' OR ' .
        $DB->sql_like('q.recipient_email', ':search3', false) . ' OR ' .
        $DB->sql_like('c.fullname',        ':search4', false) . ')';
    $params['search1'] = $searchparam;
    $params['search2'] = $searchparam;
    $params['search3'] = $searchparam;
    $params['search4'] = $searchparam;
}

// A–Z initials bars (integrated with the table).
$ifirst = $table->get_initial_first();
$ilast  = $table->get_initial_last();
if ($ifirst !== null && $ifirst !== '') {
    $where[]          = $DB->sql_like('u.firstname', ':ifirst', false);
    $params['ifirst'] = $DB->sql_like_escape($ifirst) . '%';
}
if ($ilast !== null && $ilast !== '') {
    $where[]         = $DB->sql_like('u.lastname', ':ilast', false);
    $params['ilast'] = $DB->sql_like_escape($ilast) . '%';
}

if (!empty($filterstatus)) {
    $where[]                = 'q.status = :filterstatus';
    $params['filterstatus'] = $filterstatus;
}
if (!empty($filterlevel)) {
    $where[]               = 'q.level = :filterlevel';
    $params['filterlevel'] = (int)$filterlevel;
}
if (!empty($filtertype)) {
    $where[]              = 'q.recipient_type = :filtertype';
    $params['filtertype'] = $filtertype;
}

$wheresql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ----------------------------------------------------------------
// Validate sort column and map to actual SQL expression.
// ----------------------------------------------------------------
$allowedsort = [
    'fullname', 'coursefullname', 'level', 'recipient_type',
    'recipient_email', 'status', 'attempts', 'timecreated', 'timesent',
];
if (!in_array($sort, $allowedsort)) {
    $sort = 'timecreated';
}
$sortmap = [
    'fullname'        => 'u.lastname, u.firstname',
    'coursefullname'  => 'c.fullname',
    'level'           => 'q.level',
    'recipient_type'  => 'q.recipient_type',
    'recipient_email' => 'q.recipient_email',
    'status'          => 'q.status',
    'attempts'        => 'q.attempts',
    'timecreated'     => 'q.timecreated',
    'timesent'        => 'q.timesent',
];
$direction = ($dir == SORT_ASC) ? 'ASC' : 'DESC';
$ordersql  = $sortmap[$sort] . ' ' . $direction;

// ----------------------------------------------------------------
// Run count + data queries.
// Downloads fetch ALL rows (no LIMIT); browser view is paginated.
// ----------------------------------------------------------------
$basefrom = "FROM {local_mandatoryreminder_queue} q
              JOIN {user}   u ON u.id = q.userid
              JOIN {course} c ON c.id = q.courseid
            {$wheresql}";

$countsql  = "SELECT COUNT(q.id) {$basefrom}";
$selectsql = "SELECT q.id, q.userid, q.courseid, q.level, q.recipient_type, q.recipient_email,
                     q.status, q.attempts, q.timecreated, q.timesent, q.error_message,
                     u.firstname, u.lastname, u.picture, u.imagealt,
                     u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                     c.fullname AS coursefullname
              {$basefrom}
              ORDER BY {$ordersql}";

$totalcount = $DB->count_records_sql($countsql, $params);

if ($table->is_downloading()) {
    // No pagination when exporting — stream all matching rows.
    $queueitems = $DB->get_records_sql($selectsql, $params);
} else {
    $queueitems = $DB->get_records_sql($selectsql, $params, $page * $perpage, $perpage);
}

// ================================================================
// HTML page output — skipped entirely when a download is in progress.
// ================================================================
if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('dashboard', 'local_mandatoryreminder'));

    // ============================================================
    // SECTION 1 — Summary statistics card
    // ============================================================
    $stats = local_mandatoryreminder_get_dashboard_stats();

    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h4',
        get_string('summary', 'local_mandatoryreminder'),
        ['class' => 'card-title']
    );

    $summarytable                    = new html_table();
    $summarytable->data = [
        [
            get_string('total_mandatory_courses', 'local_mandatoryreminder'),
            html_writer::tag('strong', $stats['total_courses']),
        ],
        [
            get_string('total_enrolled_students', 'local_mandatoryreminder'),
            html_writer::tag('strong', $stats['total_students']),
        ],
        [
            get_string('total_incomplete', 'local_mandatoryreminder'),
            html_writer::tag('strong', $stats['total_incomplete']),
        ],
        [
            get_string('emails_pending', 'local_mandatoryreminder'),
            html_writer::tag('span', $stats['emails_pending'],
                ['class' => 'badge badge-warning']),
        ],
        [
            get_string('emails_sent_today', 'local_mandatoryreminder'),
            html_writer::tag('span', $stats['emails_sent_today'],
                ['class' => 'badge badge-success']),
        ],
        [
            get_string('emails_failed', 'local_mandatoryreminder'),
            html_writer::tag('span', $stats['emails_failed'],
                ['class' => 'badge badge-danger']),
        ],
    ];

    echo html_writer::table($summarytable);
    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card

    // ============================================================
    // SECTION 2 — Queue heading + filter form
    // ============================================================
    echo html_writer::tag('h3', get_string('email_queue', 'local_mandatoryreminder'));

    $reseturl = new moodle_url('/local/mandatoryreminder/dashboard.php');

    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => (new moodle_url('/local/mandatoryreminder/dashboard.php'))->out(false),
        'class'  => 'd-flex align-items-end justify-content-between mb-3',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'perpage', 'value' => $perpage,
    ]);

    // Search box.
    echo html_writer::start_div('input-group input-group-sm mr-3', ['style' => 'max-width:260px']);
    echo html_writer::empty_tag('input', [
        'type'        => 'text',
        'name'        => 'search',
        'value'       => s($search),
        'placeholder' => get_string('search'),
        'class'       => 'form-control',
        'aria-label'  => get_string('search'),
    ]);
    echo html_writer::end_div();

    // Status filter.
    $statusoptions = [
        ''           => get_string('allstatuses',   'local_mandatoryreminder'),
        'pending'    => get_string('status_pending',    'local_mandatoryreminder'),
        'processing' => get_string('status_processing', 'local_mandatoryreminder'),
        'sent'       => get_string('status_sent',       'local_mandatoryreminder'),
        'failed'     => get_string('status_failed',     'local_mandatoryreminder'),
    ];
    echo html_writer::start_div('form-group form-group-sm mb-0 mr-2');
    echo html_writer::label(
        get_string('status', 'local_mandatoryreminder') . ':',
        'filterstatus', true, ['class' => 'mr-1 mb-0']
    );
    echo html_writer::select(
        $statusoptions, 'filterstatus', $filterstatus, false,
        ['id' => 'filterstatus', 'class' => 'custom-select custom-select-sm']
    );
    echo html_writer::end_div();

    // Level filter.
    $selectedlevel = $filterlevel ? (string)$filterlevel : '';
    $leveloptions  = [
        ''  => get_string('allevels', 'local_mandatoryreminder'),
        '1' => get_string('level', 'local_mandatoryreminder') . ' 1',
        '2' => get_string('level', 'local_mandatoryreminder') . ' 2',
        '3' => get_string('level', 'local_mandatoryreminder') . ' 3',
        '4' => get_string('level', 'local_mandatoryreminder') . ' 4',
    ];
    echo html_writer::start_div('form-group form-group-sm mb-0 mr-2');
    echo html_writer::label(
        get_string('level', 'local_mandatoryreminder') . ':',
        'filterlevel', true, ['class' => 'mr-1 mb-0']
    );
    echo html_writer::select(
        $leveloptions, 'filterlevel', $selectedlevel, false,
        ['id' => 'filterlevel', 'class' => 'custom-select custom-select-sm']
    );
    echo html_writer::end_div();

    // Recipient type filter.
    $typeoptions = [
        ''           => get_string('alltypes', 'local_mandatoryreminder'),
        'employee'   => get_string('recipient_employee',  'local_mandatoryreminder'),
        'supervisor' => get_string('recipient_supervisor', 'local_mandatoryreminder'),
        'sbuhead'    => get_string('recipient_sbuhead',    'local_mandatoryreminder'),
    ];
    echo html_writer::start_div('form-group form-group-sm mb-0 mr-2');
    echo html_writer::label(
        get_string('recipient_type', 'local_mandatoryreminder') . ':',
        'filtertype', true, ['class' => 'mr-1 mb-0']
    );
    echo html_writer::select(
        $typeoptions, 'filtertype', $filtertype, false,
        ['id' => 'filtertype', 'class' => 'custom-select custom-select-sm']
    );
    echo html_writer::end_div();

    // Submit + reset buttons.
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => get_string('filter', 'local_mandatoryreminder'),
        'class' => 'btn btn-primary btn-sm mr-1',
    ]);
    echo html_writer::link(
        $reseturl,
        get_string('resetfilters', 'local_mandatoryreminder'),
        ['class' => 'btn btn-secondary btn-sm']
    );

    echo html_writer::end_tag('form');
}

// ================================================================
// pagesize() sets the total row count so the table can render its
// own pagination bar (replaces the manual $OUTPUT->paging_bar() call).
// When downloading, this is effectively a no-op for HTML output.
// ================================================================
$table->pagesize($perpage, $totalcount);

// ================================================================
// Feed rows.  HTML output uses rich markup; downloads use plain text.
// ================================================================
$statusclasses = [
    'sent'       => 'badge badge-success',
    'failed'     => 'badge badge-danger',
    'processing' => 'badge badge-warning',
    'pending'    => 'badge badge-secondary',
];
$datetimefmt = '%d-%m-%Y, %I:%M %p';

foreach ($queueitems as $item) {
    if ($table->is_downloading()) {
        // Plain text — no HTML tags allowed in exported files.
        $fullname    = fullname($item);
        $coursename  = $item->coursefullname;
        $statusbadge = get_string('status_' . $item->status, 'local_mandatoryreminder');
        $timecreated = userdate($item->timecreated, $datetimefmt);
        $timesent    = $item->timesent ? userdate($item->timesent, $datetimefmt) : '';
    } else {
        // Rich HTML for browser view.
        $profileurl = new moodle_url('/user/profile.php', ['id' => $item->userid]);

        // Build a lightweight user object with the fields user_picture() requires.
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
        $userpic  = $OUTPUT->user_picture($userobj, ['size' => 35, 'link' => false, 'class' => 'rounded-circle mr-2']);
        $fullname = html_writer::link($profileurl, fullname($item));

        $courseurl   = new moodle_url('/course/view.php', ['id' => $item->courseid]);
        $coursename  = html_writer::link($courseurl, format_string($item->coursefullname));

        $statusclass = $statusclasses[$item->status] ?? 'badge badge-secondary';
        $statuslabel = get_string('status_' . $item->status, 'local_mandatoryreminder');
        $statusbadge = html_writer::tag('span', $statuslabel, ['class' => $statusclass]);

        // Tooltip shows the error message on failed items.
        if ($item->status === 'failed' && !empty($item->error_message)) {
            $statusbadge .= ' ' . html_writer::tag('small',
                html_writer::tag('abbr', '(?)',
                    ['title' => s($item->error_message), 'class' => 'initialism text-muted']
                )
            );
        }

        $timecreated = userdate($item->timecreated, $datetimefmt);
        $timesent    = $item->timesent
            ? userdate($item->timesent, $datetimefmt)
            : get_string('never');
    }

    $table->add_data([
        $userpic,
        $fullname,
        $coursename,
        $item->level,
        get_string('recipient_' . $item->recipient_type, 'local_mandatoryreminder'),
        $item->recipient_email,
        $statusbadge,
        $item->attempts,
        $timecreated,
        $timesent,
    ]);
}

// finish_output() renders the table HTML (including pagination bar and download
// buttons) or finalises the download stream.
$table->finish_output();

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}

// ================================================================
// Page-local helper function
// ================================================================

/**
 * Get summary statistics for the dashboard.
 *
 * Only users holding a student-archetype role in the course context are counted,
 * consistent with how the reminder tasks identify targets.
 *
 * @return array Associative array of statistics
 */
function local_mandatoryreminder_get_dashboard_stats() {
    global $DB;

    $mandatorycourses = local_mandatoryreminder_get_mandatory_courses();
    $coursecount      = count($mandatorycourses);
    $totalstudents    = 0;
    $totalincomplete  = 0;

    foreach ($mandatorycourses as $courseid) {
        $studentsql = "SELECT COUNT(DISTINCT ra.userid)
                         FROM {role_assignments} ra
                         JOIN {role}    r   ON r.id            = ra.roleid
                                          AND r.archetype      = 'student'
                         JOIN {context} ctx ON ctx.id          = ra.contextid
                                          AND ctx.contextlevel = :ctxlevel
                                          AND ctx.instanceid   = :courseid";

        $totalstudents  += (int) $DB->count_records_sql($studentsql, [
            'ctxlevel' => CONTEXT_COURSE,
            'courseid' => $courseid,
        ]);
        $totalincomplete += count(local_mandatoryreminder_get_incomplete_users($courseid));
    }

    $todaystart = mktime(0, 0, 0);

    return [
        'total_courses'     => $coursecount,
        'total_students'    => $totalstudents,
        'total_incomplete'  => $totalincomplete,
        'emails_pending'    => $DB->count_records('local_mandatoryreminder_queue', ['status' => 'pending']),
        'emails_sent_today' => $DB->count_records_select(
            'local_mandatoryreminder_queue',
            "status = 'sent' AND timesent >= :todaystart",
            ['todaystart' => $todaystart]
        ),
        'emails_failed'     => $DB->count_records('local_mandatoryreminder_queue', ['status' => 'failed']),
    ];
}
