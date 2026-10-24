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
    /** @var float Archives stay strictly under this ratio of the threshold (2 % margin for the XML of the backup). */
    const ARCHIVE_MARGIN = 0.98;

    /** @var stored_file[] Backup files of the user private backup area, loaded once. */
    protected $userbackupfiles = null;

    /** @var int User ID. */
    protected $userid;

    /** @var array|null Backup controllers of the user (ready to run or more), by course id, most recent first,
     * loaded once by preload_controllers() for the courses of the exports tab. */
    protected $controllers = null;

    /**
     * Constructor.
     *
     * @param int $userid User ID.
     */
    public function __construct(int $userid) {
        $this->userid = $userid;
    }

    /**
     * Loads in one query the backups launched by the user for several courses, for the exports tab :
     * is_backup_failed(), get_pending_backups() and is_backup_pending() then read them instead of querying
     * the database for each course. Courses not preloaded are still read from the database.
     *
     * @param int[] $courseids Course IDs.
     */
    public function preload_controllers(array $courseids): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $this->controllers = array_fill_keys(array_map('intval', $courseids), []);
        if (!$courseids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');
        $params += [
            'userid' => $this->userid,
            'type' => backup::TYPE_1COURSE,
            'operation' => backup::OPERATION_BACKUP,
            'statusmin' => backup::STATUS_AWAITING,
        ];
        $records = $DB->get_recordset_select('backup_controllers', "userid = :userid AND itemid $insql
            AND type = :type AND operation = :operation AND status >= :statusmin", $params,
            'itemid, timecreated DESC, id DESC', 'id, itemid, backupid, status, progress, timecreated');
        foreach ($records as $record) {
            $this->controllers[(int)$record->itemid][] = $record;
        }
        $records->close();
    }

    /**
     * Preloaded backup controllers of a course, most recent first.
     *
     * @param int $courseid Course ID.
     * @return \stdClass[]|null Records, or null if the course has not been preloaded.
     */
    protected function get_preloaded_controllers(int $courseid): ?array {
        if ($this->controllers === null || !array_key_exists($courseid, $this->controllers)) {
            return null;
        }
        return $this->controllers[$courseid];
    }

    /**
     * Strategy used to back up a course, according to its estimated backup size.
     *
     * Sizes are estimated when the user launches the backup (see size_estimator), and compared to the archive
     * limit (see get_archive_limit()) :
     * - strictly under the limit (or no threshold) : one archive (standard strategy) ;
     * - equal to or over the limit : several archives strictly under the limit (split strategy).
     * Other strategies (deferred backup for heavy courses...) can be chosen here later.
     *
     * @param int $courseid Course ID.
     * @return strategy
     */
    public function get_strategy(int $courseid): strategy {
        $sizes = size_estimator::get_sizes($courseid);
        $limit = self::get_archive_limit();
        if ($limit > 0 && $sizes->total >= $limit) {
            return new split_strategy($sizes, $limit);
        }
        return new standard_strategy($sizes, $limit);
    }

    /**
     * Size that an archive must stay strictly under : the threshold of the settings, less a margin of 2 %.
     *
     * The estimation only counts files : the real archive also contains the XML files of the backup, which weigh
     * about 0.05 to 0.1 MB for a part of 250 MB (measured on a test server). The margin keeps the real archives
     * under the threshold chosen by the administrator. 0 : no threshold.
     *
     * @return int Size in bytes.
     */
    public static function get_archive_limit(): int {
        return (int)floor(self::get_split_threshold() * self::ARCHIVE_MARGIN);
    }

    /**
     * Size threshold of a backup archive, from plugin settings (250 MB by default, 0 : no split).
     *
     * @return int Size in bytes.
     */
    public static function get_split_threshold(): int {
        $threshold = get_config('report_coursemanager', 'backup_split_threshold');
        if ($threshold === false || $threshold === '') {
            $threshold = 250;
        }
        return max(0, (int)$threshold) * 1024 * 1024;
    }

    /**
     * Check if a backup of the course launched by the user is still waiting or running.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public function is_backup_pending(int $courseid): bool {
        return !empty($this->get_pending_backups($courseid));
    }

    /**
     * Backups of the course launched by the user and still waiting or running.
     *
     * @param int $courseid Course ID.
     * @return \stdClass[] Records with backupid and progress (from 0 to 1).
     */
    public function get_pending_backups(int $courseid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $preloaded = $this->get_preloaded_controllers($courseid);
        if ($preloaded !== null) {
            // Waiting or running, oldest first.
            return array_values(array_reverse(array_filter($preloaded, function($record) {
                return $record->status > backup::STATUS_NEED_PRECHECK && $record->status < backup::STATUS_FINISHED_ERR;
            })));
        }

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
        return array_values($DB->get_records_select('backup_controllers', $select, $params, 'timecreated ASC, id ASC',
            'id, backupid, progress'));
    }

    /**
     * Check if the last backup of the course launched by the user has potentially failed.
     *
     * The last launch may contain several backups (one per part of a split backup). It is considered
     * as failed when one of its backups (Moodle backup controller, ready to run or more) :
     * - has been marked as failed by Moodle ;
     * - or is still waiting or running after the delay defined in plugin settings
     *   (blocked task, cron not running, or very long backup).
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public function is_backup_failed(int $courseid): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $select = 'userid = :userid AND itemid = :itemid AND type = :type AND operation = :operation
            AND status >= :statusmin';
        $params = [
            'userid' => $this->userid,
            'itemid' => $courseid,
            'type' => backup::TYPE_1COURSE,
            'operation' => backup::OPERATION_BACKUP,
            'statusmin' => backup::STATUS_AWAITING,
        ];
        $preloaded = $this->get_preloaded_controllers($courseid);
        $records = $preloaded !== null ? array_slice($preloaded, 0, 50)
            : $DB->get_records_select('backup_controllers', $select, $params, 'timecreated DESC, id DESC',
                'id, status, timecreated', 0, 50);
        if (empty($records)) {
            return false;
        }

        $delay = (int)get_config('report_coursemanager', 'backup_error_delay');
        if ($delay <= 0) {
            $delay = 60;
        }

        // Backups of the last launch : created within a few minutes before the most recent one.
        $lastlaunch = reset($records)->timecreated;
        foreach ($records as $record) {
            if ($record->timecreated < $lastlaunch - 5 * MINSECS) {
                break;
            }
            if ($record->status == backup::STATUS_FINISHED_ERR) {
                return true;
            }
            if ($record->status < backup::STATUS_FINISHED_ERR && time() - $record->timecreated > $delay * MINSECS) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mark the backups of the course launched by the user and still waiting or running as failed.
     *
     * Moodle refuses a new backup of a course (from this plugin or from the Moodle backup page)
     * while a backup of this course by the same user is waiting or running. This is used, after
     * the delay defined in plugin settings, to release a blocked backup before a new one.
     * If the blocked backup task runs later, Moodle stops it as its status is not "awaiting" anymore.
     *
     * @param int $courseid Course ID.
     * @return void
     */
    public function release_blocked_backups(int $courseid): void {
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
        $DB->set_field_select('backup_controllers', 'status', backup::STATUS_FINISHED_ERR, $select, $params);
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
     * Files of the most recent backup of the course : one file, or all the parts of a split backup.
     *
     * Parts of a split backup share the same Moodle file name, with a "part<number>_" prefix
     * (translated in the site language).
     *
     * @param int $courseid Course ID.
     * @return stored_file[] Files, in part order. Empty if no backup.
     */
    public function get_last_backup_files(int $courseid): array {
        $last = $this->get_last_backup_file($courseid);
        if ($last === null) {
            return [];
        }
        if (!preg_match('/^([^\W\d_]+)(\d+)_(.+)$/u', $last->get_filename(), $matches)) {
            return [$last];
        }
        $basename = $matches[3];

        $fs = get_file_storage();
        $candidates = array_merge($this->userbackupfiles,
            $fs->get_area_files(context_course::instance($courseid)->id, 'backup', 'course', 0, 'timecreated DESC', false));
        $parts = [];
        foreach ($candidates as $file) {
            if (preg_match('/^([^\W\d_]+)(\d+)_(.+)$/u', $file->get_filename(), $partmatches)
                    && $partmatches[3] === $basename && $file->get_contextid() == $last->get_contextid()) {
                $parts[(int)$partmatches[2]] = $file;
            }
        }
        ksort($parts);
        return array_values($parts);
    }

    /**
     * Download URL of a backup file : the download page of the plugin, which records the date of the download
     * then sends the file.
     *
     * @param stored_file $file Backup file.
     * @return moodle_url
     */
    public static function get_download_url(stored_file $file): moodle_url {
        return new moodle_url('/report/coursemanager/download_backup.php',
            ['fileid' => $file->get_id(), 'sesskey' => sesskey()]);
    }

    /**
     * Records the last download of a backup of a course (one row per course).
     *
     * @param int $courseid Course ID.
     * @param int $userid User who downloaded the backup.
     */
    public static function record_download(int $courseid, int $userid): void {
        global $DB;
        $record = (object)['courseid' => $courseid, 'userid' => $userid, 'timedownloaded' => time()];
        $existing = $DB->get_record('report_coursemanager_backup_download', ['courseid' => $courseid], 'id',
            IGNORE_MULTIPLE);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('report_coursemanager_backup_download', $record);
        } else {
            $DB->insert_record('report_coursemanager_backup_download', $record);
        }
    }

    /**
     * Last download of a backup of several courses, with the user who downloaded it.
     *
     * @param int[] $courseids Course IDs.
     * @return \stdClass[] courseid, timedownloaded and user name fields, indexed by course id ; courses whose
     *     backup has never been downloaded are missing
     */
    public static function get_last_downloads(array $courseids): array {
        global $DB;
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $records = $DB->get_records_sql("SELECT d.courseid, d.timedownloaded, d.userid, $userfields
                                           FROM {report_coursemanager_backup_download} d
                                      LEFT JOIN {user} u ON u.id = d.userid
                                          WHERE d.courseid $insql", $params);
        return $records;
    }
}
