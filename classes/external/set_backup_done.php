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

namespace report_coursemanager\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use report_coursemanager\local\backup\tracking;

/**
 * Web service : marks the backup process of a course as done or not done ("Downloads and exports" tab).
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_backup_done extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'done' => new external_value(PARAM_BOOL, 'True when the backup process of the course is done'),
        ]);
    }

    /**
     * Sets the state of the course.
     *
     * @param int $courseid
     * @param bool $done
     * @return array done, info (date and user, empty when not done)
     */
    public static function execute(int $courseid, bool $done): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'done' => $done]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('report/coursemanager:markexportdone', $context);

        tracking::set_done($params['courseid'], $params['done'], $USER->id);
        $states = tracking::get_states([$params['courseid']]);
        return [
            'done' => $params['done'],
            'info' => tracking::get_info($states[$params['courseid']] ?? null),
        ];
    }

    /**
     * Returned values.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'done' => new external_value(PARAM_BOOL, 'New state'),
            'info' => new external_value(PARAM_TEXT, 'Date and user of the state, empty when not done'),
        ]);
    }
}
