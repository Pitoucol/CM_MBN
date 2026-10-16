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
 * Course page: preview, convert, undo.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filterlib.php');

use report_coursemanager\local\wiris\locator;
use report_coursemanager\local\wiris\manager;

$courseid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('report/coursemanager:convertwiris', $context);

$url = new moodle_url('/report/coursemanager/wiris_files.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('wiris_menuentry', 'report_coursemanager'));
$PAGE->set_heading(format_string($course->fullname));

$manager = new manager();

if ($action === 'convert') {
    require_sesskey();
    $run = $manager->convert(locator::course_targets($course->id), $course->id, $USER->id, 'web');
    redirect($url, get_string('wiris_converted', 'report_coursemanager', $run), null, \core\output\notification::NOTIFY_SUCCESS);
}
if ($action === 'undo') {
    require_sesskey();
    $runid = required_param('runid', PARAM_INT);
    $DB->get_record('report_coursemanager_wrun', ['id' => $runid, 'courseid' => $course->id], 'id', MUST_EXIST);
    $result = $manager->undo($runid);
    redirect($url, get_string('wiris_undone', 'report_coursemanager', (object)$result), null,
        $result['conflicts'] ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS);
}

core_php_time_limit::raise();
$items = $manager->scan(locator::course_targets($course->id));
$summary = manager::summarise($items);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('wiris_menuentry', 'report_coursemanager'));
echo html_writer::tag('p', get_string('wiris_intro', 'report_coursemanager'));

if (!filter_is_enabled('mathjaxloader')) {
    echo $OUTPUT->notification(get_string('wiris_mathjaxdisabled', 'report_coursemanager'), 'warning');
}

if (!$items) {
    echo $OUTPUT->notification(get_string('wiris_nochange', 'report_coursemanager'), 'info');
} else {
    echo $OUTPUT->notification(get_string('wiris_summary', 'report_coursemanager', (object)$summary), 'info');
    if ($summary['fields'] > 0) {
        $button = new single_button(new moodle_url($url, ['action' => 'convert']),
            get_string('wiris_convert', 'report_coursemanager'), 'post', single_button::BUTTON_PRIMARY);
        $button->add_confirm_action(get_string('wiris_confirmconvert', 'report_coursemanager', $summary['formulas']));
        echo html_writer::div($OUTPUT->render($button), 'mb-4');
    }

    $report = new \report_coursemanager\local\wiris\report($items, $course);

    // Overview of the three parts.
    $overview = html_writer::start_tag('ul', ['class' => 'mb-2']);
    $overview .= html_writer::tag('li', html_writer::link('#w2t-content',
        get_string('wiris_part_content', 'report_coursemanager')) . ' : ' .
        get_string('wiris_n_formulas', 'report_coursemanager', $report->counts['content']));
    $overview .= html_writer::tag('li', html_writer::link('#w2t-quizzes',
        get_string('wiris_part_quizzes', 'report_coursemanager')) . ' : ' .
        get_string('wiris_n_formulas', 'report_coursemanager', $report->counts['quizzes']));
    $overview .= html_writer::tag('li', html_writer::link('#w2t-bank',
        get_string('wiris_part_bank', 'report_coursemanager')) . ' : ' .
        get_string('wiris_n_questions_formulas', 'report_coursemanager',
            (object)['questions' => $report->counts['bankquestions'], 'formulas' => $report->counts['bank']]));
    $overview .= html_writer::end_tag('ul');
    echo $overview;
    if ($report->counts['failures']) {
        echo $OUTPUT->notification(get_string('wiris_failures_help', 'report_coursemanager'), 'warning');
    }

    // The original formula is rendered only while the Wiris filter is active; otherwise its MathML is shown.
    $wirisactive = filter_is_enabled('wiris');
    $renderbefore = function(array $formula) use ($wirisactive, $context): string {
        if ($wirisactive) {
            return format_text($formula['original'], FORMAT_HTML, ['context' => $context]);
        }
        $mathml = preg_replace('/\s*xmlns="[^"]*"/', '', $formula['mathml']);
        $mathml = core_text::strlen($mathml) > 160 ? core_text::substr($mathml, 0, 157) . '…' : $mathml;
        return html_writer::tag('code', s($mathml), ['class' => 'small', 'style' => 'word-break: break-all;']);
    };

    // Formula table, with a global limit on the number of rows rendered.
    // Maximum number of formulas displayed (plugin setting, every formula is converted anyway).
    $maxrows = (int)get_config('report_coursemanager', 'wiris_max_rows');
    if ($maxrows <= 0) {
        $maxrows = 300;
    }
    $shown = 0;
    $hidden = 0;
    $rendertable = function(array $rows) use (&$shown, &$hidden, $maxrows, $renderbefore, $context): string {
        $table = new html_table();
        $table->head = [get_string('wiris_field', 'report_coursemanager'), get_string('wiris_before', 'report_coursemanager'),
            get_string('wiris_after', 'report_coursemanager'), 'LaTeX'];
        $table->attributes['class'] = 'generaltable table-sm mb-2';
        $table->attributes['style'] = 'table-layout: fixed; width: 100%;';
        $table->size = ['18%', '28%', '27%', '27%'];
        $skipped = 0;
        foreach ($rows as $row) {
            if ($shown >= $maxrows) {
                $skipped++;
                $hidden++;
                continue;
            }
            $shown++;
            $formula = $row['formula'];
            if ($formula['ok']) {
                $after = $formula['latex'] === '' ? '' : format_text(
                    \report_coursemanager\local\wiris\text_converter::wrap($formula['latex']), FORMAT_HTML, ['context' => $context]);
                $code = html_writer::tag('code', s($formula['latex']), ['style' => 'word-break: break-all;']);
                if ($formula['fromannotation']) {
                    $code .= html_writer::div(get_string('wiris_fromannotation', 'report_coursemanager'), 'small text-muted');
                }
            } else {
                $after = html_writer::span(get_string('wiris_toretype', 'report_coursemanager'), 'badge badge-danger bg-danger text-white');
                $code = html_writer::tag('code', s($formula['error']), ['class' => 'text-danger',
                    'style' => 'word-break: break-all;']);
            }
            $cells = [s($row['field']), $renderbefore($formula), $after, $code];
            $tr = new html_table_row($cells);
            if (!$formula['ok']) {
                $tr->attributes['class'] = 'table-danger';
            }
            $table->data[] = $tr;
        }
        $out = $table->data ? html_writer::table($table) : '';
        if ($skipped) {
            $out .= html_writer::tag('p', get_string('wiris_moreformulas', 'report_coursemanager', $skipped),
                ['class' => 'small text-muted']);
        }
        return $out;
    };

    // Collapsible block.
    $details = function(string $summary, string $content, bool $open = true): string {
        return html_writer::tag('details',
            html_writer::tag('summary', $summary, ['class' => 'font-weight-bold fw-bold mb-2']) . $content,
            ['class' => 'border rounded p-2 mb-2'] + ($open ? ['open' => 'open'] : []));
    };
    $badge = function(int $n, string $key = 'n_formulas'): string {
        return ' ' . html_writer::span(get_string('wiris_' . $key, 'report_coursemanager', $n), 'badge badge-secondary bg-secondary text-white');
    };
    $link = function(string $label, ?moodle_url $link): string {
        return $link ? html_writer::link($link, s($label)) : s($label);
    };

    // Part 1: course content, by section and activity.
    echo html_writer::tag('h3', '1. ' . get_string('wiris_part_content', 'report_coursemanager'), ['id' => 'w2t-content',
        'class' => 'mt-4']);
    echo html_writer::tag('p', get_string('wiris_part_content_help', 'report_coursemanager'), ['class' => 'text-muted']);
    if (!$report->content) {
        echo html_writer::tag('p', get_string('wiris_none', 'report_coursemanager'));
    }
    foreach ($report->content as $section) {
        echo html_writer::tag('h4', s($section['name']) . $badge($section['count']), ['class' => 'h5 mt-3']);
        foreach ($section['groups'] as $group) {
            echo $details($link($group['label'], $group['url']) . $badge(count($group['rows'])),
                $rendertable($group['rows']));
        }
    }

    // Part 2: questions of the quizzes, by section, quiz and question.
    echo html_writer::tag('h3', '2. ' . get_string('wiris_part_quizzes', 'report_coursemanager'), ['id' => 'w2t-quizzes',
        'class' => 'mt-4']);
    echo html_writer::tag('p', get_string('wiris_part_quizzes_help', 'report_coursemanager'), ['class' => 'text-muted']);
    if (!$report->quizzes) {
        echo html_writer::tag('p', get_string('wiris_none', 'report_coursemanager'));
    }
    foreach ($report->quizzes as $section) {
        echo html_writer::tag('h4', s($section['name']) . $badge($section['count']), ['class' => 'h5 mt-3']);
        foreach ($section['quizzes'] as $quiz) {
            $content = '';
            foreach ($quiz['questions'] as $question) {
                $content .= html_writer::tag('h5', $link($question['name'], $question['url'])
                    . $badge(count($question['rows'])), ['class' => 'h6 mt-2']);
                $content .= $rendertable($question['rows']);
            }
            echo $details(get_string('modulename', 'quiz') . ' : ' . $link($quiz['label'], $quiz['url'])
                . $badge(count($quiz['questions']), 'n_questions') . $badge($quiz['count']), $content);
        }
    }

    // Part 3: question banks, by category.
    echo html_writer::tag('h3', '3. ' . get_string('wiris_part_bank', 'report_coursemanager'), ['id' => 'w2t-bank',
        'class' => 'mt-4']);
    echo html_writer::tag('p', get_string('wiris_part_bank_help', 'report_coursemanager'), ['class' => 'text-muted']);
    if (!$report->bank) {
        echo html_writer::tag('p', get_string('wiris_none', 'report_coursemanager'));
    }
    foreach ($report->bank as $category) {
        $table = new html_table();
        $table->head = [get_string('question'), get_string('wiris_col_formulas', 'report_coursemanager'),
            get_string('wiris_usedin', 'report_coursemanager')];
        $table->attributes['class'] = 'generaltable table-sm mb-2';
        $table->attributes['style'] = 'table-layout: fixed; width: 100%;';
        $table->size = ['45%', '15%', '40%'];
        $unused = '';
        foreach ($category['questions'] as $question) {
            $count = count($question['rows']);
            if ($question['failures']) {
                $count .= ' ' . html_writer::span(get_string('wiris_n_toretype', 'report_coursemanager', $question['failures']),
                    'badge badge-danger bg-danger text-white');
            }
            if ($question['used']) {
                $quizlinks = array_map(function($cm) {
                    return html_writer::link($cm->url, s(\report_coursemanager\local\wiris\manager::short_name($cm->name)));
                }, $question['used']);
                $usedin = implode(', ', $quizlinks);
            } else {
                $usedin = html_writer::span(get_string('wiris_notused', 'report_coursemanager'), 'text-muted');
                // Questions not shown in part 2 are detailed here.
                $unused .= html_writer::tag('h5', $link($question['name'], $question['url'])
                    . $badge(count($question['rows'])), ['class' => 'h6 mt-2']);
                $unused .= $rendertable($question['rows']);
            }
            $table->data[] = [$link($question['name'], $question['url']), $count, $usedin];
        }
        $content = html_writer::table($table);
        if ($unused !== '') {
            $content .= html_writer::tag('p', get_string('wiris_notused_details', 'report_coursemanager'),
                ['class' => 'small text-muted mt-2']) . $unused;
        }
        echo $details(s($category['name']) . $badge(count($category['questions']), 'n_questions'), $content);
    }

    if ($hidden) {
        echo $OUTPUT->notification(get_string('wiris_moreformulas', 'report_coursemanager', $hidden), 'info');
    }
}

// History.
echo $OUTPUT->heading(get_string('wiris_history', 'report_coursemanager'), 3);
$runs = $DB->get_records('report_coursemanager_wrun', ['courseid' => $course->id], 'timecreated DESC');
if (!$runs) {
    echo html_writer::tag('p', get_string('wiris_nohistory', 'report_coursemanager'));
} else {
    $htable = new html_table();
    $htable->head = [get_string('date'), get_string('wiris_col_user', 'report_coursemanager'),
        get_string('wiris_col_origin', 'report_coursemanager'), get_string('wiris_col_formulas', 'report_coursemanager'),
        get_string('wiris_col_status', 'report_coursemanager'), ''];
    foreach ($runs as $run) {
        $user = $run->userid ? core_user::get_user($run->userid) : null;
        $action = '';
        if ($run->timereverted) {
            $status = get_string('wiris_reverted', 'report_coursemanager') . ' (' . userdate($run->timereverted) . ')';
        } else {
            $status = get_string('wiris_status_done', 'report_coursemanager');
            $undo = new single_button(new moodle_url($url, ['action' => 'undo', 'runid' => $run->id]),
                get_string('wiris_undo', 'report_coursemanager'), 'post');
            $undo->add_confirm_action(get_string('wiris_confirmundo', 'report_coursemanager'));
            $action = $OUTPUT->render($undo);
        }
        $htable->data[] = [userdate($run->timecreated), $user ? fullname($user) : '-',
            get_string('wiris_origin_' . $run->origin, 'report_coursemanager'), $run->formulas, $status, $action];
    }
    echo html_writer::table($htable);
}

echo $OUTPUT->footer();
