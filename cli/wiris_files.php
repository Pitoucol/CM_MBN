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
 * Command line conversion of Wiris formulas, for administrators.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Configuration, check of the system user, then Moodle setup.
require(__DIR__ . '/cli_setup.php');
require_once($CFG->libdir . '/clilib.php');

use report_coursemanager\local\wiris\locator;
use report_coursemanager\local\wiris\manager;

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'course' => '',
    'all' => false,
    'questions-outside-courses' => false,
    'execute' => false,
    'verbose' => false,
    'undo' => 0,
    'list-runs' => false,
], ['h' => 'help', 'c' => 'course', 'a' => 'all', 'e' => 'execute', 'v' => 'verbose']);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

$help = "Converts Wiris/MathType formulas (SafeXML, Wiris images, MathML) into LaTeX for MathJax.

Without --execute, nothing is written: the script only reports what would be converted (dry run).

Options:
  -c, --course=ID[,ID...]         Courses to process.
  -a, --all                       All courses (including the front page).
      --questions-outside-courses Also the question banks of the system and course category contexts.
  -e, --execute                   Really write the converted texts (originals are kept for undo).
  -v, --verbose                   Print every formula (location, LaTeX, errors).
      --undo=RUNID                Restore the original texts of a conversion run.
      --list-runs                 List the conversion runs.
  -h, --help                      Print this help.

Run it as the user of the web server (www-data on Debian and Ubuntu), from the Moodle directory
(the public directory since Moodle 5.1).

Exit codes: 0 success, 1 at least one formula could not be converted or one text could not be restored.

Examples:
  sudo -u www-data php report/coursemanager/cli/wiris_files.php --all --questions-outside-courses            (dry run)
  sudo -u www-data php report/coursemanager/cli/wiris_files.php --all --questions-outside-courses --execute
  sudo -u www-data php report/coursemanager/cli/wiris_files.php --course=12 --execute --verbose
  sudo -u www-data php report/coursemanager/cli/wiris_files.php --undo=5
";

if ($options['help']) {
    echo $help;
    exit(0);
}

\core\session\manager::set_user(get_admin());
$manager = new manager();

if ($options['list-runs']) {
    foreach ($DB->get_records('report_coursemanager_wrun', null, 'id') as $run) {
        printf("run %d  course %d  %s  %s  formulas %d  failures %d  %s\n", $run->id, $run->courseid,
            userdate($run->timecreated, '%Y-%m-%d %H:%M'), $run->origin, $run->formulas, $run->failures,
            $run->timereverted ? 'UNDONE' : '');
    }
    exit(0);
}

if ($options['undo']) {
    $result = $manager->undo((int)$options['undo']);
    cli_writeln("Restored: {$result['restored']}  Modified since conversion (left untouched): {$result['conflicts']}");
    exit($result['conflicts'] ? 1 : 0);
}

if ($options['all']) {
    $courseids = $DB->get_fieldset_select('course', 'id', '1 = 1 ORDER BY id');
} else if ($options['course'] !== '') {
    $courseids = array_filter(array_map('intval', explode(',', $options['course'])));
} else {
    $courseids = [];
}
if (!$courseids && !$options['questions-outside-courses']) {
    echo $help;
    exit(1);
}

$execute = (bool)$options['execute'];
cli_writeln($execute ? '== CONVERSION ==' : '== DRY RUN (nothing written, use --execute) ==');
$totals = ['fields' => 0, 'formulas' => 0, 'failures' => 0, 'annotations' => 0];

$jobs = [];
foreach ($courseids as $courseid) {
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        cli_problem("Course $courseid not found");
        continue;
    }
    $jobs[] = [$courseid, function() use ($courseid) {
        return locator::course_targets($courseid);
    }];
}
if ($options['questions-outside-courses']) {
    $jobs[] = [0, function() {
        return locator::question_targets(locator::non_course_question_ids());
    }];
}

foreach ($jobs as [$courseid, $gettargets]) {
    $targets = $gettargets();
    $items = $manager->scan($targets);
    if (!$items) {
        continue;
    }
    $s = manager::summarise($items);
    foreach ($s as $k => $v) {
        $totals[$k] += $v;
    }
    $label = $courseid ? "course $courseid" : 'questions outside courses';
    cli_writeln(sprintf('%-28s formulas %5d  texts %5d  failures %4d', $label, $s['formulas'], $s['fields'],
        $s['failures']));
    if ($options['verbose'] || $s['failures']) {
        $modinfo = $courseid ? get_fast_modinfo($courseid) : null;
        foreach ($items as $item) {
            [$where] = manager::describe($item, $modinfo);
            foreach ($item['formulas'] as $f) {
                if ($f['ok'] && !$options['verbose']) {
                    continue;
                }
                $loc = "{$item['target']['table']}.{$item['field']}#{$item['recordid']} ($where)";
                if ($f['ok']) {
                    cli_writeln("    OK   $loc: {$f['latex']}");
                } else {
                    cli_writeln("    FAIL $loc: {$f['error']}");
                }
            }
        }
    }
    if ($execute) {
        $run = $manager->convert($targets, $courseid, get_admin()->id, 'cli');
        cli_writeln("    -> written, run id {$run->id}");
    }
}
cli_writeln(sprintf('TOTAL: %d formulas in %d texts, %d failures (%d from LaTeX annotations)',
    $totals['formulas'], $totals['fields'], $totals['failures'], $totals['annotations']));
// Exit code for scripts : 1 when at least one formula could not be converted.
exit($totals['failures'] ? 1 : 0);
