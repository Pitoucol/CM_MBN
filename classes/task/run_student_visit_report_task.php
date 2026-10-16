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
 * Class for students reports calculation.
 *
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_student_visit_report_task extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        // Shown in admin screens.
        return get_string('runstudentvisitreporttask', 'report_coursemanager');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        mtrace("... Start coursemanager report for courses without students visits or without students enrolled.");

        // If students report is enabled in settings, start process.
        if (get_config('report_coursemanager', 'enable_students_task') == 1) {
            global $CFG, $DB, $USER;
            $table = 'report_coursemanager_reports';

            // Check if params are set. If not, stop reports calculation.
            if (!get_config('report_coursemanager', 'student_role_report') || get_config('report_coursemanager', 'student_role_report') == '')
            {
                mtrace("... ERROR - Role for students is not set in params.");
                return;
            }
            if (!get_config('report_coursemanager', 'category_bin') || get_config('report_coursemanager', 'category_bin') == '')
                {
                    mtrace("... ERROR - Category for deleted courses is not set in params.");
                    return;
                }
            if (!get_config('report_coursemanager', 'last_access_student') || get_config('report_coursemanager', 'last_access_student') == '')
                {
                    mtrace("... ERROR - Number of days for last student access is not set in params.");
                    return;
                }

            require_once($CFG->dirroot . '/report/coursemanager/lib.php');
            $now = time();
            $roleids = [(int)get_config('report_coursemanager', 'student_role_report')];
            $days = (int)get_config('report_coursemanager', 'last_access_student');
            $listcourses = get_courses();
            foreach ($listcourses as $course) {
                if ($course->id > 1) {
                    // If course is in trash category, delete all reports.
                    if ($course->category == get_config('report_coursemanager', 'category_bin')) {
                        $exists = $DB->get_records('report_coursemanager_reports', ['course' => $course->id]);
                        if (!empty($exists)) {
                            $res = $DB->delete_records($table, ['course' => $course->id]);
                        }
                    } else {
                        // Start reports calculation.
                        // REPORTS FOR STUDENTS - NO VISITS OR COURSES WITHOUT STUDENTS.
                        // Students of the course and those who visited it recently, in one query.
                        $counts = report_coursemanager_count_visitors($course->id, $roleids, $days, $now);

                        // CASE 1 : at least one student enrolled ; report when none visited the course.
                        // CASE 2 : no student enrolled in course.
                        $this->set_report($course->id, 'no_student', $counts->users == 0);
                        $this->set_report($course->id, 'no_visit_student', $counts->users > 0 && $counts->visitors == 0);
                    }
                    // Tests end.
                }
            }
        } else {
            // Students visits and enrolments report is not enabled in plugin settings, nothing happens.
            mtrace("...... Reports for students is not enabled !");
        }
        mtrace("... End coursemanager students report.");
    }

    /**
     * Creates or deletes a report of a course, only when its state changes.
     *
     * @param int $courseid Course ID.
     * @param string $report Report name.
     * @param bool $active True when the report must exist.
     */
    protected function set_report($courseid, $report, $active) {
        global $DB;
        $table = 'report_coursemanager_reports';
        $exists = $DB->record_exists($table, ['course' => $courseid, 'report' => $report]);
        if ($active && !$exists) {
            $DB->insert_record($table, (object)['course' => $courseid, 'report' => $report]);
        } else if (!$active && $exists) {
            $DB->delete_records($table, ['course' => $courseid, 'report' => $report]);
        }
    }
}
