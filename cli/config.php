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
 * Exports or imports the settings of Course Manager in JSON, to copy a configuration to other sites or to fix a wrong
 * setting on many sites with a script.
 *
 * Exit codes : 0 success, 1 error (nothing written when importing), 2 wrong options.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use report_coursemanager\local\config\settings_json;

[$options, $unrecognised] = cli_get_params(
    ['export' => false, 'import' => '', 'file' => '', 'dry-run' => false, 'help' => false],
    ['e' => 'export', 'i' => 'import', 'f' => 'file', 'n' => 'dry-run', 'h' => 'help']
);

if ($options['help']) {
    cli_writeln(get_string('configjson_cli_help', 'report_coursemanager'));
    exit(0);
}
$import = is_string($options['import']) ? $options['import'] : '';
if ($unrecognised || ($options['export'] && $options['import'] !== '') || (!$options['export'] && $import === '')) {
    cli_problem(get_string('configjson_cli_wrongoptions', 'report_coursemanager'));
    cli_writeln(get_string('configjson_cli_help', 'report_coursemanager'));
    exit(2);
}

// The settings of the plugin are only in the admin tree of an administrator.
\core\session\manager::set_user(get_admin());

if ($options['export']) {
    try {
        $json = json_encode(settings_json::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (moodle_exception $e) {
        cli_problem($e->getMessage());
        exit(1);
    }
    if ($options['file'] === '') {
        echo $json . "\n";
    } else if (file_put_contents($options['file'], $json . "\n") === false) {
        cli_problem(get_string('configjson_cli_cannotwrite', 'report_coursemanager', $options['file']));
        exit(1);
    } else {
        cli_writeln(get_string('configjson_cli_exported', 'report_coursemanager', $options['file']));
    }
    exit(0);
}

// Import.
$content = is_readable($import) ? file_get_contents($import) : false;
if ($content === false) {
    cli_problem(get_string('configjson_cli_cannotread', 'report_coursemanager', $import));
    exit(1);
}
$data = json_decode($content, true);
if (!is_array($data)) {
    cli_problem(get_string('configjson_cli_invalidjson', 'report_coursemanager', json_last_error_msg()));
    exit(1);
}

$result = settings_json::import($data, (bool)$options['dry-run']);

foreach ($result['warnings'] as $warning) {
    cli_writeln(get_string('configjson_cli_warning', 'report_coursemanager', $warning));
}
foreach ($result['changes'] as [$name, $old, $new]) {
    cli_writeln(get_string('configjson_cli_change', 'report_coursemanager', (object)[
        'name' => $name,
        'old' => json_encode($old, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'new' => json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]));
}
if ($result['errors']) {
    foreach ($result['errors'] as $error) {
        cli_problem(get_string('configjson_cli_error', 'report_coursemanager', $error));
    }
    // Errors are found before writing, so nothing is written ; a write refused by Moodle is the only exception.
    cli_problem(get_string($result['written'] ? 'configjson_cli_partial' : 'configjson_cli_aborted', 'report_coursemanager',
        $result['written']));
    exit(1);
}
if (!$result['changes']) {
    cli_writeln(get_string('configjson_cli_nochange', 'report_coursemanager'));
} else if ($options['dry-run']) {
    cli_writeln(get_string('configjson_cli_dryrun', 'report_coursemanager', count($result['changes'])));
} else {
    cli_writeln(get_string('configjson_cli_applied', 'report_coursemanager', count($result['changes'])));
}
exit(0);
