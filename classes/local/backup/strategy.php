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
 * Way of creating a course backup (.mbz) from the teachers dashboard.
 *
 * Only the standard strategy exists for now. Other strategies can be added later
 * (for example a backup deferred to the night cron for heavy courses, or a backup
 * split by sections) and chosen by manager::get_strategy().
 *
 * @package    report_coursemanager
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface strategy {
    /**
     * Short name of the strategy, used in logs.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Launch the backup of a course, without asking anything to the user.
     *
     * @param int $courseid Course ID.
     * @param int $userid User who launches the backup.
     * @return bool True if the backup is running in background, false if it is already finished.
     */
    public function launch(int $courseid, int $userid): bool;
}
