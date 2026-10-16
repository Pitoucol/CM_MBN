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

namespace report_coursemanager\admin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

use report_coursemanager\local\lostactivities\checker;

/**
 * Text area of the plugins missing on the destination site : one "frankenstyle_name | Name shown" per line.
 *
 * Lines that are not a supported frankenstyle name are refused when the settings are saved.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_lost_activities extends \admin_setting_configtextarea {

    /**
     * Checks every line of the value.
     *
     * @param string $data
     * @return bool|string true, or the error message
     */
    public function validate($data) {
        $errors = [];
        checker::parse((string)$data, $errors);
        if (!$errors) {
            return true;
        }
        $lines = array_map(function(array $error): string {
            return get_string('lostactivities_errorline', 'report_coursemanager',
                (object)['line' => $error[0], 'text' => s($error[1])]);
        }, $errors);
        return get_string('lostactivities_error', 'report_coursemanager', implode(' ; ', $lines));
    }
}
