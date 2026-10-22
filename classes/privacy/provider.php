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
 * Privacy Subsystem implementation for report_coursemanager.
 *
 * @package     report_coursemanager
 * @copyright   2022 Olivier VALENTIN
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_coursemanager\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider: the plugin records which user converted the file aliases or the Wiris formulas of a course
 * (Wiris conversions of question banks outside courses are recorded with course id 0, in the system context),
 * which user last downloaded the backup of a course and which user marked its backup process as done in the
 * "Downloads and exports" tab.
 *
 * The reports of the plugin (report_coursemanager_reports, report_coursemanager_orphans) store no personal data.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describes the stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('report_coursemanager_arun', [
            'userid' => 'privacy:metadata:arun:userid',
            'courseid' => 'privacy:metadata:arun:courseid',
            'timecreated' => 'privacy:metadata:arun:timecreated',
        ], 'privacy:metadata:arun');
        $collection->add_database_table('report_coursemanager_wrun', [
            'userid' => 'privacy:metadata:wrun:userid',
            'courseid' => 'privacy:metadata:wrun:courseid',
            'timecreated' => 'privacy:metadata:wrun:timecreated',
        ], 'privacy:metadata:wrun');
        $collection->add_database_table('report_coursemanager_backup_download', [
            'userid' => 'privacy:metadata:backupdownload:userid',
            'courseid' => 'privacy:metadata:backupdownload:courseid',
            'timedownloaded' => 'privacy:metadata:backupdownload:timedownloaded',
        ], 'privacy:metadata:backupdownload');
        $collection->add_database_table('report_coursemanager_backup_done', [
            'userid' => 'privacy:metadata:backupdone:userid',
            'courseid' => 'privacy:metadata:backupdone:courseid',
            'timemodified' => 'privacy:metadata:backupdone:timemodified',
        ], 'privacy:metadata:backupdone');
        // Copies of the converted texts, kept for undo. They may be texts written by students (forum posts,
        // online submissions, feedback comments, glossary entries).
        $collection->add_database_table('report_coursemanager_wlog', [
            'tablename' => 'privacy:metadata:wlog:tablename',
            'recordid' => 'privacy:metadata:wlog:recordid',
            'oldvalue' => 'privacy:metadata:wlog:oldvalue',
            'newvalue' => 'privacy:metadata:wlog:newvalue',
        ], 'privacy:metadata:wlog');
        return $collection;
    }

    /**
     * Contexts with data of a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql("SELECT ctx.id FROM {context} ctx
                                      JOIN {report_coursemanager_arun} r ON r.courseid = ctx.instanceid
                                     WHERE ctx.contextlevel = :level AND r.userid = :userid",
            ['level' => CONTEXT_COURSE, 'userid' => $userid]);
        $contextlist->add_from_sql("SELECT ctx.id FROM {context} ctx
                                      JOIN {report_coursemanager_wrun} r ON r.courseid = ctx.instanceid
                                     WHERE ctx.contextlevel = :level AND r.userid = :userid",
            ['level' => CONTEXT_COURSE, 'userid' => $userid]);
        $contextlist->add_from_sql("SELECT ctx.id FROM {context} ctx
                                     WHERE ctx.contextlevel = :level AND EXISTS (
                                           SELECT 1 FROM {report_coursemanager_wrun} r WHERE r.courseid = 0 AND r.userid = :userid)",
            ['level' => CONTEXT_SYSTEM, 'userid' => $userid]);
        $contextlist->add_from_sql("SELECT ctx.id FROM {context} ctx
                                      JOIN {report_coursemanager_backup_download} d ON d.courseid = ctx.instanceid
                                     WHERE ctx.contextlevel = :level AND d.userid = :userid",
            ['level' => CONTEXT_COURSE, 'userid' => $userid]);
        $contextlist->add_from_sql("SELECT ctx.id FROM {context} ctx
                                      JOIN {report_coursemanager_backup_done} d ON d.courseid = ctx.instanceid
                                     WHERE ctx.contextlevel = :level AND d.userid = :userid",
            ['level' => CONTEXT_COURSE, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {report_coursemanager_arun} WHERE courseid = :courseid',
                ['courseid' => $context->instanceid]);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {report_coursemanager_wrun} WHERE courseid = :courseid',
                ['courseid' => $context->instanceid]);
            $userlist->add_from_sql('userid',
                'SELECT userid FROM {report_coursemanager_backup_download} WHERE courseid = :courseid',
                ['courseid' => $context->instanceid]);
            $userlist->add_from_sql('userid',
                'SELECT userid FROM {report_coursemanager_backup_done} WHERE courseid = :courseid',
                ['courseid' => $context->instanceid]);
        } else if ($context->contextlevel == CONTEXT_SYSTEM) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {report_coursemanager_wrun} WHERE courseid = 0', []);
        }
    }

    /**
     * Exports the data of a user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $courseid = self::courseid($context);
            if ($courseid === null) {
                continue;
            }
            $runs = $context->contextlevel == CONTEXT_COURSE ? $DB->get_records('report_coursemanager_arun',
                ['userid' => $userid, 'courseid' => $courseid], 'id', 'id, timecreated, aliases, timereverted') : [];
            if ($runs) {
                $data = array_map(function($r) {
                    return [
                        'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
                        'aliases' => $r->aliases,
                        'reverted' => $r->timereverted ? \core_privacy\local\request\transform::datetime($r->timereverted) : '',
                    ];
                }, array_values($runs));
                writer::with_context($context)->export_data([get_string('alias_privacy_path', 'report_coursemanager')],
                    (object)['runs' => $data]);
            }
            $runs = $DB->get_records('report_coursemanager_wrun', ['userid' => $userid, 'courseid' => $courseid], 'id',
                'id, timecreated, formulas, timereverted');
            if ($runs) {
                $data = array_map(function($r) {
                    return [
                        'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
                        'formulas' => $r->formulas,
                        'reverted' => $r->timereverted ? \core_privacy\local\request\transform::datetime($r->timereverted) : '',
                    ];
                }, array_values($runs));
                writer::with_context($context)->export_data([get_string('wiris_privacy_path', 'report_coursemanager')],
                    (object)['runs' => $data]);
            }
            $record = $context->contextlevel == CONTEXT_COURSE ? $DB->get_record('report_coursemanager_backup_download',
                ['userid' => $userid, 'courseid' => $courseid]) : false;
            if ($record) {
                writer::with_context($context)->export_data([get_string('backup_download_privacy_path', 'report_coursemanager')],
                    (object)['timedownloaded' => \core_privacy\local\request\transform::datetime($record->timedownloaded)]);
            }
            $record = $context->contextlevel == CONTEXT_COURSE ? $DB->get_record('report_coursemanager_backup_done',
                ['userid' => $userid, 'courseid' => $courseid]) : false;
            if ($record) {
                writer::with_context($context)->export_data([get_string('backup_done_privacy_path', 'report_coursemanager')],
                    (object)[
                        'done' => \core_privacy\local\request\transform::yesno($record->done),
                        'timemodified' => \core_privacy\local\request\transform::datetime($record->timemodified),
                    ]);
            }
        }
    }

    /**
     * Deletes the data of all users in a context : the user of the conversions is anonymised, and the copies of
     * the converted texts are deleted (these conversions can then no longer be undone).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_COURSE) {
            $DB->set_field('report_coursemanager_arun', 'userid', 0, ['courseid' => $context->instanceid]);
            $DB->set_field('report_coursemanager_backup_download', 'userid', 0, ['courseid' => $context->instanceid]);
            $DB->set_field('report_coursemanager_backup_done', 'userid', 0, ['courseid' => $context->instanceid]);
        }
        $courseid = self::courseid($context);
        if ($courseid !== null) {
            $DB->set_field('report_coursemanager_wrun', 'userid', 0, ['courseid' => $courseid]);
            $DB->delete_records_select('report_coursemanager_wlog',
                'runid IN (SELECT id FROM {report_coursemanager_wrun} WHERE courseid = :courseid)', ['courseid' => $courseid]);
        }
    }

    /**
     * Deletes (anonymises) the data of a user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                $DB->set_field('report_coursemanager_arun', 'userid', 0, ['courseid' => $context->instanceid, 'userid' => $userid]);
                $DB->set_field('report_coursemanager_backup_download', 'userid', 0,
                    ['courseid' => $context->instanceid, 'userid' => $userid]);
                $DB->set_field('report_coursemanager_backup_done', 'userid', 0,
                    ['courseid' => $context->instanceid, 'userid' => $userid]);
            }
            $courseid = self::courseid($context);
            if ($courseid !== null) {
                $DB->set_field('report_coursemanager_wrun', 'userid', 0, ['courseid' => $courseid, 'userid' => $userid]);
            }
        }
    }

    /**
     * Deletes (anonymises) the data of several users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        $courseid = self::courseid($context);
        if ($courseid === null) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                $DB->set_field('report_coursemanager_arun', 'userid', 0, ['courseid' => $courseid, 'userid' => $userid]);
                $DB->set_field('report_coursemanager_backup_download', 'userid', 0,
                    ['courseid' => $courseid, 'userid' => $userid]);
                $DB->set_field('report_coursemanager_backup_done', 'userid', 0,
                    ['courseid' => $courseid, 'userid' => $userid]);
            }
            $DB->set_field('report_coursemanager_wrun', 'userid', 0, ['courseid' => $courseid, 'userid' => $userid]);
        }
    }

    /**
     * Course id matching a context : the course, or 0 for the system context (Wiris conversions of question
     * banks outside courses). Null for other contexts.
     *
     * @param \context $context
     * @return int|null
     */
    protected static function courseid(\context $context): ?int {
        if ($context->contextlevel == CONTEXT_COURSE) {
            return (int)$context->instanceid;
        }
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            return 0;
        }
        return null;
    }
}
