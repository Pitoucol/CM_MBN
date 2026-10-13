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
 * Result of a backup launch : what was done, and the steps to show to the user in the backup popup.
 *
 * @package    report_coursemanager
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class launch_result {
    /** @var bool True if at least one backup has been launched. */
    public $launched = false;

    /** @var bool True if backups run in background, false if they are already done. */
    public $inbackground = false;

    /** @var bool True if no backup was created because of an error (for example a section over the threshold). */
    public $error = false;

    /** @var array[] Steps shown in the backup popup : title and content (HTML). */
    public $steps = [];

    /**
     * Add a step to show in the backup popup.
     *
     * @param string $title Step title.
     * @param string $html Step content, as HTML.
     * @return void
     */
    public function add_step(string $title, string $html): void {
        $this->steps[] = ['title' => $title, 'html' => $html];
    }
}
