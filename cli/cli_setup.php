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
 * Start of the command line scripts of the plugin, used instead of requiring config.php.
 *
 * A Moodle command line script must run as the owner of the Moodle data directory (www-data on Debian and
 * Ubuntu, apache or nginx on other systems): run with another user, for example root, the Moodle bootstrap
 * creates cache files that the web server cannot read any more, and the whole site fails with errors 500.
 *
 * Only the configuration is loaded first (ABORT_AFTER_CONFIG, nothing is written at this stage). The script
 * stops if the user is not the owner of the data directory, otherwise the normal Moodle setup goes on.
 * The message is in English, as the language strings are not available before the setup.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('CLI_SCRIPT') || !CLI_SCRIPT) {
    die();
}

define('ABORT_AFTER_CONFIG', true);
require(__DIR__ . '/../../../config.php');

// Not available on Windows : the check is skipped.
if (function_exists('posix_geteuid') && !empty($CFG->dataroot) && is_dir($CFG->dataroot)) {
    $dataowner = fileowner($CFG->dataroot);
    if ($dataowner !== false && posix_geteuid() !== $dataowner) {
        $ownerinfo = function_exists('posix_getpwuid') ? posix_getpwuid($dataowner) : false;
        $ownername = $ownerinfo ? $ownerinfo['name'] : 'uid ' . $dataowner;
        fwrite(STDERR, "This script must be run as the owner of the Moodle data directory ({$ownername}), "
            . "otherwise it creates cache files that the web server cannot read. Example:\n"
            . "  sudo -u {$ownername} php " . implode(' ', $_SERVER['argv'] ?? []) . "\n");
        exit(1);
    }
}

define('ABORT_AFTER_CONFIG_CANCEL', true);
require($CFG->dirroot . '/lib/setup.php');
