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
 * Launch a course backup (.mbz) from the "Downloads and exports" tab, without asking anything to the user.
 *
 * @package     report_coursemanager
 * @copyright   2022 Olivier VALENTIN
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
require_login();
require_sesskey();

$context = context_course::instance($course->id);
require_capability('moodle/backup:backupcourse', $context);

$returnurl = new moodle_url('/report/coursemanager/view.php', ['tab' => 'exports']);

$manager = new \report_coursemanager\local\backup\manager($USER->id);

// Only one backup at a time for a course and a user, as on the Moodle backup page.
if ($manager->is_backup_pending($course->id)) {
    redirect($returnurl, get_string('backup_already_pending', 'report_coursemanager', format_string($course->fullname)),
        null, \core\output\notification::NOTIFY_WARNING);
}

$strategy = $manager->get_strategy($course->id);
$inbackground = $strategy->launch($course->id, $USER->id);

$event = \report_coursemanager\event\course_backup_launched::create([
    'context' => $context,
    'courseid' => $course->id,
    'other' => ['strategy' => $strategy->get_name()],
]);
$event->trigger();

$message = $inbackground ? 'backup_launched' : 'backup_done';
redirect($returnurl, get_string($message, 'report_coursemanager', format_string($course->fullname)),
    null, \core\output\notification::NOTIFY_SUCCESS);
