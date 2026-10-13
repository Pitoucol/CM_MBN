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
 * Standard course backup : one archive, as done by Moodle when the user clicks "Jump to final step".
 *
 * Used when the estimated size of the course backup is under the size threshold.
 *
 * @package    report_coursemanager
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class standard_strategy extends base_strategy {
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
     * @return launch_result
     */
    public function launch(int $courseid, int $userid): launch_result {
        $result = new launch_result();
        $result->add_step($this->get_size_step());
        $result->add_step(get_string('backup_step_onearchive', 'report_coursemanager'));

        $bc = $this->create_controller($courseid, $userid);
        $filename = $this->get_default_filename($bc);
        $this->set_setting($bc, 'filename', $filename);
        $result->inbackground = $this->launch_controller($bc, $userid);
        $result->launched = true;

        $result->add_step(get_string('backup_step_file', 'report_coursemanager', s($filename)));
        $result->add_step(get_string($result->inbackground ? 'backup_step_background' : 'backup_step_done',
            'report_coursemanager'));
        return $result;
    }
}
