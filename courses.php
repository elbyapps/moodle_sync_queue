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
 * Push courses to schools (Central mode).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_syncqueue_courses');

$action = optional_param('action', '', PARAM_ALPHA);
$courseids = optional_param_array('courses', [], PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

$pageurl = new moodle_url('/local/syncqueue/courses.php');
if ($search !== '') {
    $pageurl->param('search', $search);
}
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('pushcourses', 'local_syncqueue'));
$PAGE->set_heading(get_string('pushcourses', 'local_syncqueue'));

$mode = get_config('local_syncqueue', 'mode');

// Redirect if not in central mode.
if ($mode !== 'central') {
    redirect(
        new moodle_url('/local/syncqueue/dashboard.php'),
        get_string('centralmodeonlypage', 'local_syncqueue'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Handle push action.
if ($action === 'push' && confirm_sesskey() && !empty($courseids)) {
    $updatemanager = new \local_syncqueue\update_manager();
    $backupmanager = new \local_syncqueue\backup_manager();
    $count = 0;
    $failed = 0;

    foreach ($courseids as $courseid) {
        $course = $DB->get_record('course', ['id' => $courseid]);
        if ($course && $course->id != SITEID) {
            // Create backup of the course.
            $backupfile = $backupmanager->create_course_backup($courseid, $USER->id);

            if ($backupfile) {
                $updatemanager->queue_course_update_with_backup($course, $backupfile, 'create');
                $count++;
            } else {
                // Queue without backup (metadata only).
                $updatemanager->queue_course_update($course, 'create');
                $failed++;
            }
        }
    }

    if ($count > 0) {
        \core\notification::success(get_string('coursespushed', 'local_syncqueue', $count));
    }
    if ($failed > 0) {
        \core\notification::warning(get_string('coursespushednobackup', 'local_syncqueue', $failed));
    }
    redirect($PAGE->url);
}

echo $OUTPUT->header();
echo local_syncqueue_get_navigation('courses');
echo $OUTPUT->heading(get_string('pushcourses', 'local_syncqueue'));

// Build search query.
$params = ['siteid' => SITEID];
$searchsql = '';
if ($search !== '') {
    $searchsql = ' AND (' . $DB->sql_like('c.fullname', ':search1', false) .
                 ' OR ' . $DB->sql_like('c.shortname', ':search2', false) .
                 ' OR ' . $DB->sql_like('cc.name', ':search3', false) . ')';
    $params['search1'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['search2'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['search3'] = '%' . $DB->sql_like_escape($search) . '%';
}

$countsql = "SELECT COUNT(*)
               FROM {course} c
          LEFT JOIN {course_categories} cc ON cc.id = c.category
              WHERE c.id != :siteid" . $searchsql;
$totalcount = $DB->count_records_sql($countsql, $params);

$sql = "SELECT c.id, c.shortname, c.fullname, c.visible, c.category, cc.name AS categoryname
          FROM {course} c
     LEFT JOIN {course_categories} cc ON cc.id = c.category
         WHERE c.id != :siteid" . $searchsql . "
      ORDER BY c.fullname ASC";
$courses = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

// Build full category path map (e.g. "Level 3 / Building Construction").
$categorylist = \core_course_category::make_categories_list('', 0, ' / ');

// Search form.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/syncqueue/courses.php'))->out_omit_querystring(),
    'class' => 'mb-3',
]);
echo html_writer::start_div('input-group', ['style' => 'max-width: 400px;']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'search',
    'value' => $search,
    'placeholder' => get_string('searchcourses', 'local_syncqueue'),
    'class' => 'form-control',
]);
echo html_writer::start_div('input-group-append');
echo html_writer::tag('button', get_string('search'), [
    'type' => 'submit',
    'class' => 'btn btn-outline-secondary',
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');

if ($totalcount === 0) {
    if ($search !== '') {
        echo $OUTPUT->notification(get_string('nocoursesmatchsearch', 'local_syncqueue'), 'info');
    } else {
        echo $OUTPUT->notification(get_string('nocourses', 'local_syncqueue'), 'info');
    }
} else {
    echo html_writer::tag('p', get_string('selectcourses', 'local_syncqueue'));

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $PAGE->url->out_omit_querystring(),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'push']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    $table = new html_table();
    $table->head = [
        html_writer::checkbox('selectall', 1, false, '', ['id' => 'selectall']),
        get_string('course'),
        get_string('shortname'),
        get_string('category'),
        get_string('visible'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($courses as $course) {
        $categoryname = $categorylist[$course->category] ?? ($course->categoryname ?: '-');

        $checkbox = html_writer::checkbox('courses[]', $course->id, false, '', ['class' => 'course-checkbox']);
        $visible = $course->visible ? get_string('yes') : get_string('no');

        $table->data[] = [
            $checkbox,
            s($course->fullname),
            s($course->shortname),
            s($categoryname),
            $visible,
        ];
    }

    echo html_writer::table($table);

    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);

    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('pushtoschools', 'local_syncqueue'),
        'class' => 'btn btn-primary',
    ]);

    echo html_writer::end_tag('form');

    // JavaScript for select all.
    $js = <<<JS
document.getElementById('selectall').addEventListener('change', function() {
    var checkboxes = document.querySelectorAll('.course-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = this.checked;
    }
});
JS;
    echo html_writer::script($js);
}

echo $OUTPUT->footer();
