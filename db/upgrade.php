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
 * Upgrade file for Course manager.
 *
 * @package     report_coursemanager
 * @copyright   2022 Olivier VALENTIN
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute report_coursemanager upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_report_coursemanager_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2023110307) {
        // Delete unused setting.
        unset_config('teachertools', 'report_coursemanager');
        upgrade_plugin_savepoint(true, 2023110307, 'report', 'coursemanager');
    }
    if ($oldversion < 2023110308) {
        upgrade_plugin_savepoint(true, 2023110308, 'report', 'coursemanager');
    }
    if ($oldversion < 2024021601) {
        upgrade_plugin_savepoint(true, 2024021601, 'report', 'coursemanager');
    }
    if ($oldversion < 2024021606) {
        upgrade_plugin_savepoint(true, 2024021606, 'report', 'coursemanager');
    }
    if ($oldversion < 2024021608) {
        $table = new xmldb_table('coursemanager');
        if ($dbman->table_exists($table)) {
            $dbman->rename_table($table, 'report_coursemanager_reports');
        }
        upgrade_plugin_savepoint(true, 2024021608, 'report', 'coursemanager');
    }
    if ($oldversion < 2024021609) {
        // Moodle first release in plugins directory - V3.1.0.
        upgrade_plugin_savepoint(true, 2024021609, 'report', 'coursemanager');
    }
    if ($oldversion < 2024040801) {
        // Hotfixes after Moodle release - V3.1.1.
        upgrade_plugin_savepoint(true, 2024040801, 'report', 'coursemanager');
    }
    if ($oldversion < 2024040803) {
        // Rename table if not done before.
        $table = new xmldb_table('coursemanager');
        if ($dbman->table_exists($table)) {
            $dbman->rename_table($table, 'report_coursemanager_reports');
        }
        // CSS and orphan submissions task - V3.1.1.
        $table = new xmldb_table('report_coursemanager_orphans');

        // Adding fields to table report_coursemanager_orphans.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('weight', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('files', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table report_coursemanager_orphans.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for report_coursemanager_orphans.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $reporttodelete = 'orphan_submissions';
        $DB->delete_records_select('report_coursemanager_reports', "report = ?", [$reporttodelete]);

        upgrade_plugin_savepoint(true, 2024040803, 'report', 'coursemanager');
    }
    if ($oldversion < 2024050301) {
        // Finalized new task for orphan submissions reports - V3.2.0.
        upgrade_plugin_savepoint(true, 2024050301, 'report', 'coursemanager');
    }
    if ($oldversion < 2024050302) {
        // Separating each report in a different task.
        upgrade_plugin_savepoint(true, 2024050302, 'report', 'coursemanager');
    }
    if ($oldversion < 2024050304) {
        // Redesign orphan submissions admin page needs to rename report_coursemanager_orphans field in database.
        $table = new xmldb_table('report_coursemanager_orphans');
        $field = new xmldb_field('contextid');
        $field->set_attributes(XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null, 'course');

        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'cmid', $continue = true, $feedback = true);
        }
        // Must empty report_coursemanager_orphans table and rerun task for recording new values.
        $DB->delete_records('report_coursemanager_orphans');
        upgrade_plugin_savepoint(true, 2024050304, 'report', 'coursemanager');
    }
    if ($oldversion < 2024050305) {
        // HOTFIX : error in courses without teachers admin page.
        upgrade_plugin_savepoint(true, 2024050305, 'report', 'coursemanager');
    }
    if ($oldversion < 2024060301) {
        // Version 3.2.2.
        // Add cleaning task and fixes several bugs.
        upgrade_plugin_savepoint(true, 2024060301, 'report', 'coursemanager');
    }
    if ($oldversion < 2026101305) {
        // Conversion of file aliases into real files : history of conversions and former aliases, for undo.
        $table = new xmldb_table('report_coursemanager_arun');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('origin', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'web');
        $table->add_field('scope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'lost');
        $table->add_field('aliases', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failures', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timereverted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('report_coursemanager_alog');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('runid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('filearea', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('filepath', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('repositoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('reference', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('referencelastsync', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('oldstatus', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('mode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'api');
        $table->add_field('reverted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('runid', XMLDB_KEY_FOREIGN, ['runid'], 'report_coursemanager_arun', ['id']);
        $table->add_index('fileid', XMLDB_INDEX_NOTUNIQUE, ['fileid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026101305, 'report', 'coursemanager');
    }
    if ($oldversion < 2026101314) {
        // Conversion of Wiris formulas into LaTeX : history of conversions and original texts, for undo.
        $table = new xmldb_table('report_coursemanager_wrun');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('origin', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'web');
        $table->add_field('fields', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('formulas', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failures', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timereverted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('report_coursemanager_wlog');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('runid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tablename', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('recordid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fieldname', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('oldvalue', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('newvalue', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('formulas', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('reverted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('runid', XMLDB_KEY_FOREIGN, ['runid'], 'report_coursemanager_wrun', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026101314, 'report', 'coursemanager');
    }
    if ($oldversion < 2026101319) {
        // Last download of a backup of each course from the "Downloads and exports" tab.
        $table = new xmldb_table('report_coursemanager_backup_download');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timedownloaded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026101319, 'report', 'coursemanager');
    }
    return true;
}
