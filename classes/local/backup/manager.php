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

use backup;
use context_course;
use context_user;
use moodle_url;
use stored_file;

/**
 * Course backups for the "Downloads and exports" tab of the teachers dashboard.
 *
 * Backups are stored by Moodle itself, in its native backup areas :
 * - the user private backup area for backups without user data (or anonymised),
 *   which is the case for teachers by default ;
 * - the course backup area for backups with user data.
 *
 * @package    report_coursemanager
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var stored_file[] Backup files of the user private backup area, loaded once. */
    protected $userbackupfiles = null;

    /** @var int User ID. */
    protected $userid;

    /**
     * Constructor.
     *
     * @param int $userid User ID.
     */
    public function __construct(int $userid) {
        $this->userid = $userid;
    }

    /**
     * Strategy used to back up a course.
     *
     * Only the standard strategy exists for now. Other strategies (deferred backup
     * for heavy courses, backup split by sections...) can be chosen here later,
     * for example according to the MBZ size of the course.
     *
     * @param int $courseid Course ID.
     * @return strategy
     */
    public function get_strategy(int $courseid): strategy {
        return new standard_strategy();
    }

    /**
     * Check if a backup of the course launched by the user is still waiting or running.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public function is_backup_pending(int $courseid): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $select = 'userid = :userid AND itemid = :itemid AND type = :type AND operation = :operation
            AND status > :statusmin AND status < :statusmax';
        $params = [
            'userid' => $this->userid,
            'itemid' => $courseid,
            'type' => backup::TYPE_1COURSE,
            'operation' => backup::OPERATION_BACKUP,
            'statusmin' => backup::STATUS_NEED_PRECHECK,
            'statusmax' => backup::STATUS_FINISHED_ERR,
        ];
        return $DB->record_exists_select('backup_controllers', $select, $params);
    }

    /**
     * Most recent backup file of the course, made by the user (private area) or stored in the course backup area.
     *
     * In the user private area, the course is found from the default backup file name
     * ("...-moodle2-course-<course id>-..."). A backup file renamed by the user is not found.
     *
     * @param int $courseid Course ID.
     * @return stored_file|null
     */
    public function get_last_backup_file(int $courseid): ?stored_file {
        $fs = get_file_storage();

        if ($this->userbackupfiles === null) {
            $this->userbackupfiles = $fs->get_area_files(context_user::instance($this->userid)->id,
                'user', 'backup', 0, 'timecreated DESC', false);
        }

        $candidates = [];
        foreach ($this->userbackupfiles as $file) {
            if (strpos($file->get_filename(), '-course-' . $courseid . '-') !== false) {
                $candidates[] = $file;
                break;
            }
        }
        $coursefiles = $fs->get_area_files(context_course::instance($courseid)->id,
            'backup', 'course', 0, 'timecreated DESC', false);
        if (!empty($coursefiles)) {
            $candidates[] = reset($coursefiles);
        }

        $last = null;
        foreach ($candidates as $file) {
            if ($last === null || $file->get_timecreated() > $last->get_timecreated()) {
                $last = $file;
            }
        }
        return $last;
    }

    /**
     * Download URL of a backup file.
     *
     * @param stored_file $file Backup file.
     * @return moodle_url
     */
    public static function get_download_url(stored_file $file): moodle_url {
        return moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(),
            $file->get_filearea(), null, $file->get_filepath(), $file->get_filename(), true);
    }
}
