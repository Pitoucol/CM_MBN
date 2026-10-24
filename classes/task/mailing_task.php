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
 * Calls Course Manager cron task for reports mailing.
 *
 * @package     report_coursemanager
 * @copyright   2022 Olivier VALENTIN
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_coursemanager\task;

/**
 * Class for mailing task.
 *
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mailing_task extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        // Shown in admin screens.
        return get_string('mailingtask', 'report_coursemanager');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        mtrace("... Start coursemanager teachers mailing for reports.");

        // If mailing is enabled in settings, start process.
        if (get_config('report_coursemanager', 'enable_mailing') == 1) {
            global $CFG, $DB, $USER;

            // Define $from for mailing.
            $from = new \stdClass;
            // Id of the main administrator of the site (get_admin() : the first site administrator, usually the
            // account created when Moodle was installed). Moodle only uses it when a mail cannot be sent, to record
            // the user of the event \core\event\email_failed ; the sender address shown to teachers does not change.
            $from->id = get_admin()->id;
            $from->firstname = '';
            $from->lastname = $CFG->supportname;
            $from->email = $CFG->supportname;
            $from->maildisplay = false;
            $from->mailformat = 1;
            $from->firstnamephonetic = '';
            $from->lastnamephonetic = '';
            $from->middlename = '';
            $from->alternatename = '';

            $table = 'coursemanager';
            $teacherrole = get_config('report_coursemanager', 'teacher_role_dashboard');

            // List all users that have a teacher role (as defined in params) in at least one course.
            // Only course contexts are used : a role given in another context (category, activity...)
            // with the same instance id as a course must not be taken into account.
            $sqllistteacher = 'SELECT DISTINCT ra.userid AS idteacher
                FROM {role_assignments} ra
                JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :courselevel
                JOIN {course} c ON c.id = ctx.instanceid
                WHERE ra.roleid = :roleid';

            $paramsdblistteacher = ['courselevel' => CONTEXT_COURSE, 'roleid' => $teacherrole];
            $dbresultlistteacher = $DB->get_records_sql($sqllistteacher, $paramsdblistteacher);

            foreach ($dbresultlistteacher as $teacher) {
                // An error for one teacher (deleted course, invalid data...) must not stop the mails of the
                // following teachers : it is logged, and the task goes on.
                try {
                    // We have list of teacher. Now retrieve all courses for each one.
                    // Courses of the trash category are left out, as in the teachers dashboard : they are waiting
                    // for deletion, and the nightly tasks only keep the orphan submissions report for them.
                    $sqllistcoursesforteacher = 'SELECT DISTINCT c.id AS courseid, c.fullname AS coursename
                    FROM {course} c
                    JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :courselevel
                    JOIN {role_assignments} ra ON ra.contextid = ctx.id
                    WHERE ra.roleid = :roleid
                    AND ra.userid = :userid
                    AND c.category <> :bin';
                    $paramsdblistcoursesforteacher = [
                        'courselevel' => CONTEXT_COURSE,
                        'roleid' => $teacherrole,
                        'userid' => $teacher->idteacher,
                        'bin' => (int)get_config('report_coursemanager', 'category_bin'),
                    ];
                    $dbresultlistcoursesforteacher = $DB->get_records_sql($sqllistcoursesforteacher,
                        $paramsdblistcoursesforteacher);

                    $mailcontent = '';

                    // Define list of reports and their titles in an object for loop.
                    $allreports = new \stdClass();
                    $allreports = [
                        ['report' => 'empty',
                            'string' => get_string('no_content', 'report_coursemanager'),
                            'desc' => get_string('mailingddescreportempty', 'report_coursemanager'),
                        ],
                        ['report' => 'no_visit_student',
                            'string' => get_string('no_visit_student', 'report_coursemanager'),
                            'desc' => get_string('mailingddescreportnovisitstudent', 'report_coursemanager'),
                        ],
                        ['report' => 'no_student',
                            'string' => get_string('no_student', 'report_coursemanager'),
                            'desc' => get_string('mailingddescreportnostudent', 'report_coursemanager'),
                        ],
                        ['report' => 'no_visit_teacher',
                            'string' => get_string('no_visit_teacher', 'report_coursemanager'),
                            'desc' => get_string('mailingddescreportnovisitteacher', 'report_coursemanager'),
                        ],
                        ['report' => 'heavy',
                            'string' => get_string('heavy_course', 'report_coursemanager'),
                            'desc' => get_string('mailingddescreportheavy', 'report_coursemanager'),
                        ],
                        ['report' => 'orphan_submissions',
                            'string' => get_string('orphan_submissions_button', 'report_coursemanager'),
                            'desc' => get_string('mailingddescreportorphansubmissions', 'report_coursemanager'),
                        ],
                    ];
                    foreach ($allreports as $report) {
                        // Initiallize report content.
                        $reportresult = '';
                        // For each report, we test each course for a teacher.
                        foreach ($dbresultlistcoursesforteacher as $listcourse) {
                            // If a report exists for a course, add course name to the list with direct link.
                            if ($report['report'] == 'orphan_submissions') {
                                $checkreport = $DB->get_records('report_coursemanager_orphans',
                                ['course' => $listcourse->courseid]);
                            } else if ($report['report'] == 'heavy') {
                                // Heavy course : same rule as the teachers dashboard, with the course weight
                                // and the current threshold, instead of the report calculated with the threshold
                                // of the last task run.
                                $weight = $DB->get_field('report_coursemanager_reports', 'detail',
                                ['course' => $listcourse->courseid, 'report' => 'weight']);
                                $threshold = get_config('report_coursemanager', 'total_filesize_threshold') * 1024 * 1024;
                                $checkreport = (!empty($weight) && $weight > $threshold);
                            } else {
                                $checkreport = $DB->get_record('report_coursemanager_reports',
                                ['course' => $listcourse->courseid, 'report' => $report['report']]);
                            }

                            if (!empty($checkreport)) {
                                // Heavy report leads to the specific page about course files.
                                if ($report['report'] == 'heavy') {
                                    $reportresult .= '- <a href="'.$CFG->wwwroot
                                    .'/report/coursemanager/course_files.php?courseid='
                                    .$listcourse->courseid.'">'.format_string($listcourse->coursename, true,
                                        ['context' => \context_course::instance($listcourse->courseid)]).'</a><br />';
                                } else {
                                    $reportresult .= '- <a href="'.$CFG->wwwroot.'/course/view.php?id='
                                    .$listcourse->courseid.'">'.format_string($listcourse->coursename, true,
                                        ['context' => \context_course::instance($listcourse->courseid)]).'</a><br />';
                                }
                            }
                        }
                        // If courses are concerned by this report, add report as title and concat course list.
                        if (!empty($reportresult)) {
                            // For each final report, add title, description .
                            $mailcontent .= '<h3>'.$report['string'].' </h3>';
                            $mailcontent .= '<p>'.$report['desc'].'</p>';
                            $mailcontent .= $reportresult;
                            unset($reportresult);
                        }
                    }
                    // End loop.

                    // Mail is sent only if there are reports for a teacher.
                    if (!empty($mailcontent)) {
                        // Initialize final content.
                        $finalcontent = '';
                        $teacheruserinfo = \core_user::get_user($teacher->idteacher);
                        $a = new \stdClass;
                        $a->no_student_time = get_config('report_coursemanager', 'last_access_student');
                        $a->no_teacher_time = get_config('report_coursemanager', 'last_access_teacher');
                        $mailingintroduction = str_replace(
                            ['%coursemanagerlink%', '%userfirstname%', '%userlastname%'],
                            [$CFG->wwwroot.'/report/coursemanager/view.php', s($teacheruserinfo->firstname),
                                s($teacheruserinfo->lastname)],
                            get_config('report_coursemanager', 'mailing_introduction')
                        );

                        $finalcontent .= $mailingintroduction;
                        $finalcontent .= $mailcontent;
                        $finalcontent .= '<br />';
                        $finalcontent .= get_config('report_coursemanager', 'mailing_outro');

                        // Content is HTML : it is given as HTML message, with a plain text version
                        // for users who chose plain text mails in their profile.
                        $send = email_to_user($teacheruserinfo, $from,
                        get_config('report_coursemanager', 'mailing_title'), html_to_text($finalcontent), $finalcontent);

                        // Log the real result : email_to_user() returns false when mail is not sent
                        // (invalid address, user over bounce threshold, deleted user, mail error...).
                        if ($send) {
                            mtrace('Mail sent to user '.$teacher->idteacher);
                        } else {
                            mtrace('ERROR - Mail not sent to user '.$teacher->idteacher);
                        }
                    }
                    // Unset mail content for next teacher.
                    unset($finalcontent);
                } catch (\Throwable $e) {
                    mtrace('ERROR - Mailing failed for user '.$teacher->idteacher.' : '.$e->getMessage());
                }
            }
            // End of courses list.
        } else {
            // Mailing is not enabled in plugin settings, nothing happens.
            mtrace("...... Coursemanager mailing for teacher is not enabled !");
        }
        mtrace("... End coursemanager mailing.");
    }
}
