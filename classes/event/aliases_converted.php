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
 * Event triggered when file aliases of a course are converted into real files (web page or command line).
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int runid: id of the conversion in the history (report_coursemanager_arun).
 *      - int aliases: number of aliases converted.
 *      - int failures: number of aliases that could not be converted.
 *      - string origin: web or cli.
 * }
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aliases_converted extends \core\event\base {
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
        return "The user with id '$this->userid' has converted {$this->other['aliases']} file alias(es) into real files "
            . "in the course with id '$this->courseid' ({$this->other['failures']} failure(s), {$this->other['origin']}).";
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_aliases_converted', 'report_coursemanager');
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
        if (!isset($this->other['aliases'])) {
            throw new \coding_exception('The \'aliases\' value must be set in other.');
        }
        if (!isset($this->other['failures'])) {
            throw new \coding_exception('The \'failures\' value must be set in other.');
        }
        if (!isset($this->other['origin'])) {
            throw new \coding_exception('The \'origin\' value must be set in other.');
        }
    }
}
