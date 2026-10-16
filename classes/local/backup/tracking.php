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

namespace report_coursemanager\local\backup;

/**
 * "Backup done" state of each course, set by the teachers in the "Downloads and exports" tab.
 *
 * One row per course in report_coursemanager_backup_done : the state is shared by all the teachers of the course.
 * It is cleared when a new backup of the course is launched from the tab.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tracking {

    /** Table of the states. */
    const TABLE = 'report_coursemanager_backup_done';

    /**
     * States of several courses, with the user who set them.
     *
     * @param int[] $courseids Course IDs.
     * @return \stdClass[] courseid, done, timemodified, userid and user name fields, indexed by course id ;
     *     courses never marked are missing
     */
    public static function get_states(array $courseids): array {
        global $DB;
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        return $DB->get_records_sql("SELECT d.courseid, d.done, d.timemodified, d.userid, $userfields
                                       FROM {" . self::TABLE . "} d
                                  LEFT JOIN {user} u ON u.id = d.userid
                                      WHERE d.courseid $insql", $params);
    }

    /**
     * Sets the state of a course.
     *
     * @param int $courseid Course ID.
     * @param bool $done True when the teacher considers the backup process of the course done.
     * @param int $userid User who sets the state.
     */
    public static function set_done(int $courseid, bool $done, int $userid): void {
        global $DB;
        $record = (object)['courseid' => $courseid, 'done' => (int)$done, 'userid' => $userid, 'timemodified' => time()];
        $existing = $DB->get_record(self::TABLE, ['courseid' => $courseid], 'id', IGNORE_MULTIPLE);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, $record);
        }
    }

    /**
     * Clears the state of a course when a new backup is launched : the backup process starts again.
     *
     * @param int $courseid Course ID.
     * @param int $userid User who launched the backup.
     */
    public static function reset(int $courseid, int $userid): void {
        global $DB;
        if ($DB->record_exists(self::TABLE, ['courseid' => $courseid, 'done' => 1])) {
            self::set_done($courseid, false, $userid);
        }
    }

    /**
     * Lines shown under the checkbox : date and user of the state, when the backup is marked done.
     *
     * @param \stdClass|null $state Record from get_states().
     * @return array date, user ; empty strings when the backup is not marked done
     */
    public static function get_info(?\stdClass $state): array {
        if (!$state || empty($state->done)) {
            return ['date' => '', 'user' => ''];
        }
        return [
            'date' => userdate($state->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
            'user' => fullname($state),
        ];
    }
}
