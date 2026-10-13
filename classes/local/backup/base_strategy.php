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
use backup_plan_dbops;

/**
 * Common code of backup strategies : creation and launch of native Moodle course backups.
 *
 * A backup is created as the Moodle backup page does when the user clicks "Jump to final step" :
 * site default backup settings, with their locks and the user capabilities. When asynchronous
 * backups are enabled on the site (Moodle default), the backup is queued as an adhoc task,
 * else it is done immediately. The file is stored in the native backup areas.
 *
 * @package    report_coursemanager
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_strategy implements strategy {
    /** @var \stdClass Estimated sizes of the course backup (see size_estimator). */
    protected $sizes;

    /** @var int Size threshold of an archive, in bytes (0 : no threshold). */
    protected $threshold;

    /**
     * Constructor.
     *
     * @param \stdClass $sizes Estimated sizes of the course backup (see size_estimator).
     * @param int $threshold Size threshold of an archive, in bytes (0 : no threshold).
     */
    public function __construct(\stdClass $sizes, int $threshold) {
        $this->sizes = $sizes;
        $this->threshold = $threshold;
    }

    /**
     * Create a course backup controller with the site default settings, ready to be configured.
     *
     * @param int $courseid Course ID.
     * @param int $userid User who launches the backup.
     * @return backup_controller
     */
    protected function create_controller(int $courseid, int $userid): backup_controller {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $mode = \async_helper::is_async_enabled() ? backup::MODE_ASYNC : backup::MODE_GENERAL;
        // Interactive controller, as on the Moodle backup page and in admin/cli/backup.php :
        // settings can be changed until finish_ui().
        return new backup_controller(backup::TYPE_1COURSE, $courseid, backup::FORMAT_MOODLE,
            backup::INTERACTIVE_YES, $mode, $userid, backup::RELEASESESSION_YES);
    }

    /**
     * Default Moodle backup file name for this controller, as on the Moodle backup page.
     *
     * Without it, a backup launched without the backup page is named "backup.mbz".
     *
     * @param backup_controller $bc Backup controller.
     * @return string
     */
    protected function get_default_filename(backup_controller $bc): string {
        $plan = $bc->get_plan();
        $files = $plan->setting_exists('files') ? (bool)$plan->get_setting('files')->get_value() : true;
        return backup_plan_dbops::get_default_backup_filename(backup::FORMAT_MOODLE, backup::TYPE_1COURSE,
            $bc->get_id(), $plan->get_setting('users')->get_value(), $plan->get_setting('anonymize')->get_value(),
            false, $files);
    }

    /**
     * Set a backup setting, if it exists and is not locked (by the site settings or the user capabilities).
     *
     * @param backup_controller $bc Backup controller.
     * @param string $name Setting name.
     * @param mixed $value Setting value.
     * @return bool True if the setting has the requested value.
     */
    protected function set_setting(backup_controller $bc, string $name, $value): bool {
        $plan = $bc->get_plan();
        if (!$plan->setting_exists($name)) {
            return false;
        }
        $setting = $plan->get_setting($name);
        if ($setting->get_value() == $value) {
            return true;
        }
        if ($setting->get_status() != \base_setting::NOT_LOCKED) {
            return false;
        }
        $setting->set_value($value);
        return true;
    }

    /**
     * Launch a configured backup : adhoc task (asynchronous backups) or immediate execution.
     *
     * @param backup_controller $bc Backup controller.
     * @param int $userid User who launches the backup.
     * @return bool True if the backup runs in background, false if it is already done.
     */
    protected function launch_controller(backup_controller $bc, int $userid): bool {
        $async = ($bc->get_mode() == backup::MODE_ASYNC);
        $bc->finish_ui();
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

    /**
     * First step of the backup popup : estimated size and threshold.
     *
     * @param launch_result $result Result, to add the step.
     * @return void
     */
    protected function add_size_step(launch_result $result): void {
        $a = (object) [
            'size' => display_size($this->sizes->total),
            'threshold' => $this->threshold ? display_size($this->threshold) : '-',
        ];
        $result->add_step(get_string('backup_title_estimate', 'report_coursemanager'),
            \html_writer::tag('p', get_string('backup_step_size', 'report_coursemanager', $a)));
    }

    /**
     * Last steps of the backup popup : launch and, for split backups, restore.
     *
     * @param launch_result $result Result, to add the steps.
     * @param bool $split True for a split backup.
     * @return void
     */
    protected function add_launch_steps(launch_result $result, bool $split): void {
        $result->add_step(get_string('backup_title_launch', 'report_coursemanager'),
            \html_writer::tag('p', get_string($result->inbackground ? 'backup_step_background' : 'backup_step_done',
                'report_coursemanager')));
        if ($split) {
            $result->add_step(get_string('backup_title_restore', 'report_coursemanager'),
                \html_writer::tag('p', get_string('backup_step_restore', 'report_coursemanager')));
        }
    }

    /**
     * Line of the backup popup : name (dark blue) and size (light orange), with the Moodle theme colours.
     *
     * @param string $name Name (question bank, section...).
     * @param int $size Size in bytes.
     * @return string HTML.
     */
    protected function format_content_line(string $name, int $size): string {
        return \html_writer::div(
            \html_writer::span(s($name), 'text-primary font-weight-bold fw-bold') . ' '
            . \html_writer::span('(' . display_size($size) . ')', 'text-warning'),
            'coursemanager-step-line');
    }
}
