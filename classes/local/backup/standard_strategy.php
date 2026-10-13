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
use backup_controller;

/**
 * Standard course backup, as done by Moodle when the user clicks "Jump to final step" on the backup page.
 *
 * The site default backup settings are used, with their locks and the user capabilities
 * (for example, user data are not included if the user cannot back them up).
 * When asynchronous backups are enabled on the site (Moodle default), the backup is queued
 * as an adhoc task, as Moodle does. Else, it is done immediately.
 * The backup file is stored in the native backup areas, as any manual backup.
 *
 * @package    report_coursemanager
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class standard_strategy implements strategy {
    /**
     * Short name of the strategy, used in logs.
     *
     * @return string
     */
    public function get_name(): string {
        return 'standard';
    }

    /**
     * Launch the backup of a course, without asking anything to the user.
     *
     * @param int $courseid Course ID.
     * @param int $userid User who launches the backup.
     * @return bool True if the backup is running in background, false if it is already finished.
     */
    public function launch(int $courseid, int $userid): bool {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $async = \async_helper::is_async_enabled();
        $mode = $async ? backup::MODE_ASYNC : backup::MODE_GENERAL;

        // Non interactive controller : site defaults are applied, and the controller
        // is saved in database with the "awaiting" status, ready to be executed.
        $bc = new backup_controller(backup::TYPE_1COURSE, $courseid, backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO, $mode, $userid, backup::RELEASESESSION_YES);

        if ($async) {
            // Same adhoc task as the Moodle backup page.
            $asynctask = new \core\task\asynchronous_backup_task();
            $asynctask->set_custom_data(['backupid' => $bc->get_backupid()]);
            $asynctask->set_userid($userid);
            \core\task\manager::queue_adhoc_task($asynctask);
        } else {
            $bc->execute_plan();
        }
        $bc->destroy();

        return $async;
    }
}
