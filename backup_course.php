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
// Action : "backup" (start a backup), "relaunch" or "manual" (after a potential error).
$action = optional_param('action', 'backup', PARAM_ALPHA);

// Login first : the course is only read for a logged in user.
require_login();
require_sesskey();
$course = get_course($courseid);
// Launching or relaunching a backup changes data : only by POST (buttons of the exports tab).
if (!data_submitted()) {
    throw new moodle_exception('invalidrequest');
}

$context = context_course::instance($course->id);
// Page context and URL, needed by format_string() and by the redirect page.
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/report/coursemanager/backup_course.php', ['courseid' => $course->id]));
require_capability('moodle/backup:backupcourse', $context);

$returnurl = new moodle_url('/report/coursemanager/view.php', ['tab' => 'exports']);

$manager = new \report_coursemanager\local\backup\manager($USER->id);

// After a potential error : release the blocked backup, so that a new backup can be done.
if (($action === 'relaunch' || $action === 'manual')
        && $manager->is_backup_failed($course->id)) {
    $manager->release_blocked_backups($course->id);
}

// Manual backup : Moodle backup page, where the user chooses the backup settings.
if ($action === 'manual') {
    redirect(new moodle_url('/backup/backup.php', ['id' => $course->id]));
}

// Only one backup at a time for a course and a user, as on the Moodle backup page.
if ($manager->is_backup_pending($course->id)) {
    redirect($returnurl, get_string('backup_already_pending', 'report_coursemanager', format_string($course->fullname)),
        null, \core\output\notification::NOTIFY_WARNING);
}

$strategy = $manager->get_strategy($course->id);
$result = $strategy->launch($course->id, $USER->id);

// Steps of the backup, shown once in a popup of the "Downloads and exports" tab.
$SESSION->report_coursemanager_backupreport = [
    'title' => get_string('backup_popup_title', 'report_coursemanager', format_string($course->fullname)),
    'steps' => $result->steps,
];

if (!$result->launched) {
    redirect($returnurl, get_string('backup_not_created', 'report_coursemanager', format_string($course->fullname)),
        null, \core\output\notification::NOTIFY_ERROR);
}

$event = \report_coursemanager\event\course_backup_launched::create([
    'context' => $context,
    'courseid' => $course->id,
    'other' => ['strategy' => $strategy->get_name()],
]);
$event->trigger();

// A new backup starts the backup process again : the course is no longer marked "Backup done".
\report_coursemanager\local\backup\tracking::reset($course->id, $USER->id);

$message = $result->inbackground ? 'backup_launched' : 'backup_done';
redirect($returnurl, get_string($message, 'report_coursemanager', format_string($course->fullname)),
    null, \core\output\notification::NOTIFY_SUCCESS);
