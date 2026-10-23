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
 * Download of a course backup from the "Downloads and exports" tab : records the date of the last download of
 * the course, then sends the file as Moodle does.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$fileid = required_param('fileid', PARAM_INT);

require_login();
require_sesskey();

// A course backup area file, or a file of the private backup area of the current user (backups made with the
// Moodle backup page). The file must also be one of the last backups of the course, checked below.
$file = get_file_storage()->get_file_by_id($fileid);
$inuserarea = $file && $file->get_component() === 'user' && $file->get_filearea() === 'backup'
    && (int)$file->get_contextid() === (int)context_user::instance($USER->id)->id;
if (!$file || $file->is_directory() || ($file->get_component() !== 'backup' && !$inuserarea)) {
    throw new moodle_exception('filenotfound', 'error');
}

// Course of the backup : the course of the course backup area, or the course id in the name of a backup of
// the private backup area of the user.
$filecontext = context::instance_by_id($file->get_contextid());
if ($filecontext->contextlevel == CONTEXT_COURSE) {
    $courseid = (int)$filecontext->instanceid;
} else if (preg_match('/-course-(\d+)-/', $file->get_filename(), $matches)) {
    $courseid = (int)$matches[1];
} else {
    throw new moodle_exception('filenotfound', 'error');
}

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('moodle/backup:downloadfile', $context);

// Only the files of the most recent backup of the course that the user can see in the tab.
$manager = new \report_coursemanager\local\backup\manager($USER->id);
$allowed = false;
foreach ($manager->get_last_backup_files($course->id) as $backupfile) {
    if ((int)$backupfile->get_id() === (int)$file->get_id()) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    throw new moodle_exception('filenotfound', 'error');
}

// Date of the last download of the course, one row per course.
\report_coursemanager\local\backup\manager::record_download($course->id, $USER->id);
$event = \report_coursemanager\event\course_backup_downloaded::create([
    'context' => $context,
    'courseid' => $course->id,
    'other' => ['filename' => $file->get_filename()],
]);
$event->trigger();

send_stored_file($file, 0, 0, true);
