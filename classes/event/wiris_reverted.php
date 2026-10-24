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

namespace report_coursemanager\event;

/**
 * Event triggered when a conversion of Wiris formulas is undone.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int runid: id of the conversion undone (report_coursemanager_wrun).
 *      - int restored: number of texts restored.
 *      - int conflicts: number of texts modified since the conversion, left untouched.
 * }
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiris_reverted extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Description of event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' has undone the Wiris conversion with id '{$this->other['runid']}' "
            . "in the course with id '$this->courseid' ({$this->other['restored']} restored, {$this->other['conflicts']} left).";
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_wiris_reverted', 'report_coursemanager');
    }

    /**
     * Validates the custom data.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['runid'])) {
            throw new \coding_exception('The \'runid\' value must be set in other.');
        }
        if (!isset($this->other['restored'])) {
            throw new \coding_exception('The \'restored\' value must be set in other.');
        }
        if (!isset($this->other['conflicts'])) {
            throw new \coding_exception('The \'conflicts\' value must be set in other.');
        }
    }
}
