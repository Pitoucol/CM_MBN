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
 * Privacy provider: the plugin records which user last downloaded the backup of a course from the
 * "Downloads and exports" tab.
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
        $collection->add_database_table('report_coursemanager_backup_download', [
            'userid' => 'privacy:metadata:backupdownload:userid',
            'courseid' => 'privacy:metadata:backupdownload:courseid',
            'timedownloaded' => 'privacy:metadata:backupdownload:timedownloaded',
        ], 'privacy:metadata:backupdownload');
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
                                      JOIN {report_coursemanager_backup_download} d ON d.courseid = ctx.instanceid
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
            $userlist->add_from_sql('userid',
                'SELECT userid FROM {report_coursemanager_backup_download} WHERE courseid = :courseid',
                ['courseid' => $context->instanceid]);
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
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $download = $DB->get_record('report_coursemanager_backup_download',
                ['userid' => $userid, 'courseid' => $context->instanceid]);
            if (!$download) {
                continue;
            }
            writer::with_context($context)->export_data([get_string('backup_download_privacy_path', 'report_coursemanager')],
                (object)['timedownloaded' => \core_privacy\local\request\transform::datetime($download->timedownloaded)]);
        }
    }

    /**
     * Deletes (anonymises) the data of all users in a context. The date of the last download is kept.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_COURSE) {
            $DB->set_field('report_coursemanager_backup_download', 'userid', 0, ['courseid' => $context->instanceid]);
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
                $DB->set_field('report_coursemanager_backup_download', 'userid', 0,
                    ['courseid' => $context->instanceid, 'userid' => $userid]);
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
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            $DB->set_field('report_coursemanager_backup_download', 'userid', 0,
                ['courseid' => $context->instanceid, 'userid' => $userid]);
        }
    }
}
