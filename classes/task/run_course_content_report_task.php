<?php
// This file is part of mod_offlinequiz for Moodle - http://moodle.org/
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
 * Calls Course Manager cron task for calculating reports.
 *
 * @package     report_coursemanager
 * @copyright   2022 Olivier VALENTIN
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_coursemanager\task;

/**
 * Class for reports cleaning.
 *
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_course_content_report_task extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        // Shown in admin screens.
        return get_string('runcoursecontentreporttask', 'report_coursemanager');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        mtrace("... Start coursemanager report for empty and heavy courses.");

        // If empty and heavy courses report is enabled in settings, start process.
        if (get_config('report_coursemanager', 'enable_course_content_task') == 1) {
            global $CFG, $DB, $USER;
            require_once($CFG->dirroot . '/report/coursemanager/lib.php');
            $table = 'report_coursemanager_reports';
            $now = time();

            // Check if params are set. If not, stop reports calculation.
            if (!get_config('report_coursemanager', 'total_filesize_threshold') || get_config('report_coursemanager', 'total_filesize_threshold') == '')
                {
                    mtrace("... ERROR - Filesize threshold is not set in params.");
                    return;
                }
            if (!get_config('report_coursemanager', 'category_bin') || get_config('report_coursemanager', 'category_bin') == '')
                {
                    mtrace("... ERROR - Category for deleted courses is not set in params.");
                    return;
                }

            $listcourses = get_courses();
            foreach ($listcourses as $course) {
                if ($course->id > 1) {
                    $coursecontext = \context_course::instance($course->id);

                    // If course is in trash category, delete all reports.
                    if ($course->category == get_config('report_coursemanager', 'category_bin')) {
                        $exists = $DB->get_records('report_coursemanager_reports', ['course' => $course->id]);
                        if (!empty($exists)) {
                            $res = $DB->delete_records($table, ['course' => $course->id]);
                        }
                    } else {
                        // Start reports calculation.

                        // 0 CALCULATE TOTAL COURSE SIZE.
                        // The three sizes of the course, in one query : size on disk (user data included), course size
                        // (without the course backups) and size of a backup without user data (.mbz).
                        // Courses without files keep an empty weight, excluded from median and average.
                        $sizes = report_coursemanager_get_course_sizes($course->id);
                        $coursesize = $sizes['course'] ?: null;
                        // Each value is written only when it has changed.
                        $this->save_detail($course->id, 'weight', $sizes['disk'] ?: null);
                        $this->save_detail($course->id, 'course_weight', $coursesize);
                        $this->save_detail($course->id, 'mbz_weight', $sizes['mbz'] ?: null);

                        // 1- TEST FOR TOTAL COURSE SIZE.
                        // If the course size (disk size without the course backups) is bigger than
                        // the limit defined in parameters, create alert.

                        $existsheavy = $DB->get_record('report_coursemanager_reports',
                        ['course' => $course->id, 'report' => 'heavy']);

                        if ($coursesize >= get_config('report_coursemanager', 'total_filesize_threshold') * 1024 * 1024) {
                            $data = new \stdClass();
                            $data->course = $course->id;
                            $data->report = 'heavy';

                            // If size alert doesn't exist for this course, create it in DB (an existing alert
                            // has nothing to update).
                            if (empty($existsheavy)) {
                                $res = $DB->insert_record($table, $data);
                            }
                            unset($data);
                        } else if (!empty($existsheavy)) {
                            // In this case, filesize doesn't reach limit. If alert exists, delete it.
                            $res = $DB->delete_records($table, ['id' => $existsheavy->id]);
                            unset($data);
                        }
                        unset($existsheavy);

                        // 2- TEST FOR EMPTY COURSE.
                        // Check if course entry exists in database.
                        $existsempty = $DB->get_record('report_coursemanager_reports',
                        ['course' => $course->id, 'report' => 'empty']);

                        // Query to count number of activities in course.
                        $sqlemptycourse = 'SELECT COUNT(mcm.id) AS count_modules
                        FROM {course} mc
                        INNER JOIN {course_modules} mcm ON (mc.id = mcm.course)
                        INNER JOIN {modules} mm ON (mcm.module = mm.id)
                        WHERE mc.id = ?
                        AND mm.name NOT LIKE \'forum\'
                        ';
                        $paramsemptycourse = [$course->id];
                        $dbresultemptycourse = $DB->count_records_sql($sqlemptycourse, $paramsemptycourse);

                        // If no result, course only contains announcment forum.
                        if ($dbresultemptycourse < 1) {
                            $data = new \stdClass();
                            $data->course = $course->id;
                            $data->report = 'empty';

                            // If empty course alert doesn't exist for this course, create it in DB.
                            if (empty($existsempty)) {
                                $res = $DB->insert_record($table, $data);
                            }
                            unset($data);
                        } else if (!empty($existsempty)) {
                            // In this case, course is not empty. If alert exists, delete it.
                            $res = $DB->delete_records($table, ['id' => $existsempty->id]);
                            unset($data);
                        }
                        unset($existsempty);
                    }
                }
            }
        } else {
            // Empty and heavy courses report is not enabled in plugin settings, nothing happens.
            mtrace("...... Reports for empty and heavy courses is not enabled !");
        }
        mtrace("... End coursemanager report for empty and heavy courses.");
    }

    /**
     * Creates or updates the detail of a report of a course, only when its value has changed.
     *
     * @param int $courseid Course ID.
     * @param string $report Report name.
     * @param mixed $detail New value (null when empty).
     */
    protected function save_detail($courseid, $report, $detail) {
        global $DB;
        $table = 'report_coursemanager_reports';
        $existing = $DB->get_record($table, ['course' => $courseid, 'report' => $report], 'id, detail', IGNORE_MULTIPLE);
        if (!$existing) {
            $DB->insert_record($table, (object)['course' => $courseid, 'report' => $report, 'detail' => $detail]);
        } else if ((string)$existing->detail !== (string)$detail) {
            $DB->update_record($table, (object)['id' => $existing->id, 'detail' => $detail]);
        }
    }
}
