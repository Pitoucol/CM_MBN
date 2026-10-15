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
 * Command line detection and conversion of file aliases, for administrators.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use report_coursemanager\local\alias\manager;
use report_coursemanager\local\alias\scanner;

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'course' => '',
    'all' => false,
    'include-internal' => false,
    'execute' => false,
    'verbose' => false,
    'undo' => 0,
    'list-runs' => false,
], ['h' => 'help', 'c' => 'course', 'a' => 'all', 'e' => 'execute', 'v' => 'verbose']);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

$help = "Finds the file aliases (references to a file stored elsewhere) in courses and turns them into real files,
at the same place and with the same name, so that they survive a backup (mbz) restored on another site.
Every type of file is considered (images, H5P packages, other files), and by default only aliases that would NOT be
restored (source outside the course, private files, external repositories).

Without --execute, nothing is written: the script only reports what would be converted (dry run).

Options:
  -c, --course=ID[,ID...]   Courses to process.
  -a, --all                 All courses.
      --include-internal    Also convert the aliases whose source is in the same course.
  -e, --execute             Really convert (the former aliases are kept for undo).
  -v, --verbose             Print every alias (location, source).
      --undo=RUNID          Turn the files of a conversion run back into aliases.
      --list-runs           List the conversion runs.
  -h, --help                Print this help.

Examples:
  php report/coursemanager/cli/alias_files.php --all                      (dry run)
  php report/coursemanager/cli/alias_files.php --course=12 --verbose
  php report/coursemanager/cli/alias_files.php --all --execute
  php report/coursemanager/cli/alias_files.php --undo=5
";

if ($options['help']) {
    echo $help;
    exit(0);
}

\core\session\manager::set_user(get_admin());
$manager = new manager();

if ($options['list-runs']) {
    foreach ($DB->get_records('report_coursemanager_arun', null, 'id') as $run) {
        printf("run %d  course %d  %s  %s  scope %s  converted %d  failures %d  %s\n", $run->id, $run->courseid,
            userdate($run->timecreated, '%Y-%m-%d %H:%M'), $run->origin, $run->scope, $run->aliases, $run->failures,
            $run->timereverted ? 'UNDONE' : '');
    }
    exit(0);
}

if ($options['undo']) {
    $result = $manager->undo((int)$options['undo']);
    // State shown in the "Downloads and exports" tab.
    $courseid = $DB->get_field('report_coursemanager_arun', 'courseid', ['id' => (int)$options['undo']]);
    if ($courseid && $DB->record_exists('course', ['id' => $courseid])) {
        scanner::save_report((int)$courseid);
    }
    cli_writeln("Restored as aliases: {$result['restored']}  Modified since conversion (left untouched): {$result['conflicts']}");
    exit(0);
}

if ($options['all']) {
    $courseids = $DB->get_fieldset_select('course', 'id', 'id <> :site ORDER BY id', ['site' => SITEID]);
} else if ($options['course'] !== '') {
    $courseids = array_filter(array_map('intval', explode(',', $options['course'])));
} else {
    echo $help;
    exit(1);
}

$execute = (bool)$options['execute'];
$scope = $options['include-internal'] ? scanner::SCOPE_ALL : scanner::SCOPE_LOST;
cli_writeln($execute ? '== CONVERSION ==' : '== DRY RUN (nothing written, use --execute) ==');
$totals = ['found' => 0, 'selected' => 0, 'converted' => 0, 'failures' => 0, 'missingsource' => 0];

foreach ($courseids as $courseid) {
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        cli_problem("Course $courseid not found");
        continue;
    }
    $aliases = scanner::scan_course($courseid);
    if (!$aliases) {
        continue;
    }
    $summary = scanner::summarise($aliases);
    $selected = scanner::filter($aliases, $scope);
    $totals['found'] += $summary['total'];
    $totals['selected'] += count($selected);
    $totals['missingsource'] += $summary['missingsource'];
    cli_writeln(sprintf('course %-6d aliases %4d  would be lost %4d  source in course %4d  to convert %4d',
        $courseid, $summary['total'], $summary['lost'], $summary['internal'], count($selected)));

    if ($options['verbose']) {
        foreach ($aliases as $alias) {
            $source = $alias->source ? ($alias->source['contextid'] . ':' . $alias->source['component'] . ':'
                . $alias->source['filearea'] . ':' . $alias->source['filepath'] . $alias->source['filename']) : '-';
            cli_writeln(sprintf('    %-8s %-5s file %d ctx %d %s/%s %s%s  <- %s [%s]%s', $alias->kind, $alias->type, $alias->id,
                $alias->contextid, $alias->component, $alias->filearea, $alias->filepath, $alias->filename, $source,
                $alias->repotype ?? '', $alias->sourceexists === false ? ' SOURCE DELETED' : ''));
        }
    }
    if ($execute && $selected) {
        $run = $manager->convert($selected, $courseid, get_admin()->id, 'cli', $scope);
        $totals['converted'] += $run->aliases;
        $totals['failures'] += $run->failures;
        scanner::save_report($courseid);
        cli_writeln("    -> run id {$run->id}: {$run->aliases} converted, {$run->failures} failure(s)");
        foreach ($run->failed as [$alias, $key]) {
            cli_problem("    FAIL file {$alias->id} {$alias->filename}: " . get_string($key, 'report_coursemanager'));
        }
    }
}
cli_writeln(sprintf('TOTAL: %d aliases found, %d selected, %d converted, %d failure(s), %d with a deleted source',
    $totals['found'], $totals['selected'], $totals['converted'], $totals['failures'], $totals['missingsource']));
