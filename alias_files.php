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
 * Course page: detect file aliases (images, H5P packages, other files), convert them into real files, undo.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/report/coursemanager/lib.php');

use report_coursemanager\local\alias\manager;
use report_coursemanager\local\alias\report;
use report_coursemanager\local\alias\scanner;

$courseid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$scope = optional_param('scope', scanner::SCOPE_LOST, PARAM_ALPHA);
if (!in_array($scope, [scanner::SCOPE_LOST, scanner::SCOPE_ALL], true)) {
    $scope = scanner::SCOPE_LOST;
}

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('report/coursemanager:convertaliases', $context);

$url = new moodle_url('/report/coursemanager/alias_files.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('alias_menuentry', 'report_coursemanager'));
$PAGE->set_heading(format_string($course->fullname));

$manager = new manager();

if ($action === 'convert') {
    require_sesskey();
    $aliases = scanner::filter(scanner::scan_course($course->id), $scope);
    $run = $manager->convert($aliases, $course->id, $USER->id, 'web', $scope);
    // The "Downloads and exports" tab shows the new state at once, without waiting for the nightly task.
    scanner::save_report($course->id);
    $message = get_string('alias_converted', 'report_coursemanager', $run);
    $level = \core\output\notification::NOTIFY_SUCCESS;
    if ($run->failed) {
        $details = array_map(function(array $failure): string {
            return s($failure[0]->filename) . ' (' . get_string($failure[1], 'report_coursemanager') . ')';
        }, array_slice($run->failed, 0, 10));
        $message .= ' ' . implode(' ; ', $details);
        $level = \core\output\notification::NOTIFY_WARNING;
    }
    redirect($url, $message, null, $level);
}
if ($action === 'undo') {
    require_sesskey();
    $runid = required_param('runid', PARAM_INT);
    $DB->get_record('report_coursemanager_arun', ['id' => $runid, 'courseid' => $course->id], 'id', MUST_EXIST);
    $result = $manager->undo($runid);
    scanner::save_report($course->id);
    redirect($url, get_string('alias_undone', 'report_coursemanager', (object)$result), null,
        $result['conflicts'] ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS);
}

core_php_time_limit::raise();
$aliases = scanner::scan_course($course->id);
$summary = scanner::summarise($aliases);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('alias_menuentry', 'report_coursemanager'));
echo html_writer::tag('p', get_string('alias_intro', 'report_coursemanager'));

if (!$aliases) {
    echo $OUTPUT->notification(get_string('alias_noalias', 'report_coursemanager'), 'success');
} else {
    $notice = get_string('alias_summary', 'report_coursemanager', (object)$summary)
        . ' ' . get_string('alias_summary_types', 'report_coursemanager', (object)$summary);
    echo $OUTPUT->notification($notice, $summary['lost'] ? 'warning' : 'info');
    if ($summary['missingsource']) {
        echo $OUTPUT->notification(get_string('alias_missingsource_help', 'report_coursemanager', $summary['missingsource']),
            'info');
    }

    $buttons = '';
    if ($summary['lost'] > 0) {
        $button = new single_button(new moodle_url($url, ['action' => 'convert', 'scope' => scanner::SCOPE_LOST]),
            get_string('alias_convert_lost', 'report_coursemanager', $summary['lost']), 'post', single_button::BUTTON_PRIMARY);
        $button->add_confirm_action(get_string('alias_confirmconvert', 'report_coursemanager', $summary['lost']));
        $buttons .= html_writer::div($OUTPUT->render($button), 'd-inline-block mr-2 me-2');
    }
    if ($summary['internal'] > 0) {
        $button = new single_button(new moodle_url($url, ['action' => 'convert', 'scope' => scanner::SCOPE_ALL]),
            get_string('alias_convert_all', 'report_coursemanager', $summary['total']), 'post',
            single_button::BUTTON_SECONDARY);
        $button->add_confirm_action(get_string('alias_confirmconvertall', 'report_coursemanager', $summary['total']));
        $buttons .= html_writer::div($OUTPUT->render($button), 'd-inline-block');
        $buttons .= html_writer::div(get_string('alias_convert_all_help', 'report_coursemanager'), 'small text-muted mt-1');
    }
    if ($buttons !== '') {
        echo html_writer::div($buttons, 'mb-4');
    }

    $report = new report($aliases, $course);
    // Maximum number of rows displayed (plugin setting, every alias is converted anyway).
    $maxrows = (int)get_config('report_coursemanager', 'alias_max_rows');
    if ($maxrows <= 0) {
        $maxrows = 300;
    }
    $shown = 0;
    $hidden = 0;
    foreach ($report->groups as $group) {
        $table = new html_table();
        $table->head = [get_string('alias_col_preview', 'report_coursemanager'),
            get_string('alias_col_file', 'report_coursemanager'), get_string('alias_col_where', 'report_coursemanager'),
            get_string('alias_col_source', 'report_coursemanager'), get_string('alias_col_state', 'report_coursemanager')];
        $table->attributes['class'] = 'generaltable table-sm mb-2';
        $table->attributes['style'] = 'table-layout: fixed; width: 100%;';
        $table->size = ['10%', '20%', '20%', '35%', '15%'];
        $lostingroup = 0;
        foreach ($group['rows'] as $row) {
            $alias = $row['alias'];
            if ($alias->lost) {
                $lostingroup++;
            }
            if ($shown >= $maxrows) {
                $hidden++;
                continue;
            }
            $shown++;

            $preview = '';
            $isquestion = $alias->component === 'question' || strpos($alias->component, 'qtype_') === 0;
            if ($alias->isimage && !$isquestion) {
                $src = moodle_url::make_pluginfile_url($alias->contextid, $alias->component, $alias->filearea,
                    $alias->itemid, $alias->filepath, $alias->filename);
                $preview = html_writer::empty_tag('img', ['src' => $src->out(false), 'alt' => s($alias->filename),
                    'loading' => 'lazy', 'style' => 'max-width: 100%; max-height: 48px;']);
            }
            // Type of file (image, H5P package or other file), then name and path.
            $filename = html_writer::span(get_string('alias_type_' . $alias->type, 'report_coursemanager'),
                'badge badge-secondary bg-secondary text-white mr-1 me-1');
            $filename .= html_writer::tag('span', s($alias->filename), ['style' => 'word-break: break-all;']);
            if ($alias->filepath !== '/') {
                $filename .= html_writer::div(s($alias->filepath), 'small text-muted');
            }
            $where = $row['whereurl'] ? html_writer::link($row['whereurl'], $row['where']) : $row['where'];
            $state = $alias->lost
                ? html_writer::span(get_string('alias_state_lost', 'report_coursemanager'),
                    'badge badge-danger bg-danger text-white')
                : html_writer::span(get_string('alias_state_ok', 'report_coursemanager'),
                    'badge badge-success bg-success text-white');
            $tr = new html_table_row([$preview, $filename, $where, $row['source'], $state]);
            if ($alias->lost) {
                $tr->attributes['class'] = 'table-warning';
            }
            $table->data[] = $tr;
        }
        $title = ($group['url'] ? html_writer::link($group['url'], $group['label']) : $group['label'])
            . ' ' . html_writer::span(get_string('alias_n_aliases', 'report_coursemanager', count($group['rows'])),
                'badge badge-secondary bg-secondary text-white');
        if ($lostingroup) {
            $title .= ' ' . html_writer::span(get_string('alias_n_lost', 'report_coursemanager', $lostingroup),
                'badge badge-danger bg-danger text-white');
        }
        $content = $table->data ? html_writer::table($table) : '';
        echo html_writer::tag('details',
            html_writer::tag('summary', $title, ['class' => 'font-weight-bold fw-bold mb-2']) . $content,
            ['class' => 'border rounded p-2 mb-2'] + ($lostingroup ? ['open' => 'open'] : []));
    }
    if ($hidden) {
        echo $OUTPUT->notification(get_string('alias_morerows', 'report_coursemanager', $hidden), 'info');
    }
}

// History.
echo $OUTPUT->heading(get_string('alias_history', 'report_coursemanager'), 3);
$runs = $DB->get_records('report_coursemanager_arun', ['courseid' => $course->id], 'timecreated DESC');
if (!$runs) {
    echo html_writer::tag('p', get_string('alias_nohistory', 'report_coursemanager'));
} else {
    $htable = new html_table();
    $htable->head = [get_string('date'), get_string('alias_col_user', 'report_coursemanager'),
        get_string('alias_col_origin', 'report_coursemanager'), get_string('alias_col_scope', 'report_coursemanager'),
        get_string('alias_col_aliases', 'report_coursemanager'), get_string('alias_col_status', 'report_coursemanager'), ''];
    foreach ($runs as $run) {
        $user = $run->userid ? core_user::get_user($run->userid) : null;
        $undo = '';
        if ($run->timereverted) {
            $status = get_string('alias_reverted', 'report_coursemanager') . ' (' . userdate($run->timereverted) . ')';
        } else {
            $status = get_string('alias_status_done', 'report_coursemanager');
            $button = new single_button(new moodle_url($url, ['action' => 'undo', 'runid' => $run->id]),
                get_string('alias_undo', 'report_coursemanager'), 'post');
            $button->add_confirm_action(get_string('alias_confirmundo', 'report_coursemanager'));
            $undo = $OUTPUT->render($button);
        }
        $htable->data[] = [userdate($run->timecreated), $user ? fullname($user) : '-',
            get_string('alias_origin_' . $run->origin, 'report_coursemanager'),
            get_string('alias_scope_' . $run->scope, 'report_coursemanager'), $run->aliases, $status, $undo];
    }
    echo html_writer::table($htable);
}

echo $OUTPUT->footer();
