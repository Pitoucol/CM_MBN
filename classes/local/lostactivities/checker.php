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

namespace report_coursemanager\local\lostactivities;

/**
 * Plugins missing on the destination site (setting dest_pf_plugins_specs) and their use in courses.
 *
 * The setting has one plugin per line : "frankenstyle_name | Name shown to teachers", for example
 * "block_sharing_cart | Sharing cart". Supported plugin types :
 *  - mod : activities of the course ;
 *  - block : blocks of the course and of its activities ;
 *  - qtype : questions of the question banks of the course and of its activities ;
 *  - format : course format (counted once when the course uses it).
 *
 * The use in courses is counted with one query per plugin type for all the courses, whatever their number.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checker {

    /** @var string[] Supported plugin types. */
    const TYPES = ['mod', 'block', 'qtype', 'format'];

    /** Separator between the frankenstyle name and the name shown to teachers. */
    const SEPARATOR = '|';

    /**
     * Reads the setting.
     *
     * @param string $value setting value
     * @param array $errors filled with one [line number, line] pair per invalid line
     * @return string[] names shown to teachers, indexed by frankenstyle name, in the order of the setting
     */
    public static function parse(string $value, array &$errors = []): array {
        $plugins = [];
        $errors = [];
        foreach (preg_split('/\R/', $value) as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(self::SEPARATOR, $line, 2);
            $component = \core_text::strtolower(trim($parts[0]));
            $label = trim($parts[1] ?? '');
            if (!preg_match('/^(' . implode('|', self::TYPES) . ')_[a-z][a-z0-9_]*$/', $component)) {
                $errors[] = [$index + 1, $line];
                continue;
            }
            $plugins[$component] = $label !== '' ? $label : $component;
        }
        return $plugins;
    }

    /**
     * Plugins declared by the administrator.
     *
     * @return string[] names shown to teachers, indexed by frankenstyle name
     */
    public static function get_plugins(): array {
        return self::parse((string)get_config('report_coursemanager', 'dest_pf_plugins_specs'));
    }

    /**
     * Counts the use of the declared plugins in courses.
     *
     * @param int[] $courseids
     * @param string[] $plugins names indexed by frankenstyle name, from get_plugins()
     * @return array[] for each course id, number of uses indexed by frankenstyle name (only plugins used)
     */
    public static function count_in_courses(array $courseids, array $plugins): array {
        global $DB;
        $counts = [];
        if (!$courseids || !$plugins) {
            return $counts;
        }
        $names = array_fill_keys(self::TYPES, []);
        foreach (array_keys($plugins) as $component) {
            [$type, $name] = explode('_', $component, 2);
            $names[$type][] = $name;
        }
        [$incourses, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');

        // Course contexts of the courses, and contexts below them (activities, blocks).
        $below = "(ctx.id = cc.id OR " . $DB->sql_like('ctx.path', $DB->sql_concat('cc.path', "'/%'")) . ")";

        // Activities.
        if ($names['mod']) {
            [$inmods, $params] = $DB->get_in_or_equal($names['mod'], SQL_PARAMS_NAMED, 'mod');
            $sql = "SELECT cm.course, m.name, COUNT(cm.id) AS uses
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                     WHERE cm.course $incourses AND m.name $inmods AND cm.deletioninprogress = 0
                  GROUP BY cm.course, m.name";
            self::add($counts, $DB->get_recordset_sql($sql, $courseparams + $params), 'mod');
        }

        // Blocks of the course and of its activities.
        if ($names['block']) {
            [$inblocks, $params] = $DB->get_in_or_equal($names['block'], SQL_PARAMS_NAMED, 'block');
            $sql = "SELECT cc.instanceid AS course, bi.blockname AS name, COUNT(bi.id) AS uses
                      FROM {block_instances} bi
                      JOIN {context} ctx ON ctx.id = bi.parentcontextid
                      JOIN {context} cc ON cc.contextlevel = :courselevel AND $below
                     WHERE cc.instanceid $incourses AND bi.blockname $inblocks
                  GROUP BY cc.instanceid, bi.blockname";
            $params['courselevel'] = CONTEXT_COURSE;
            self::add($counts, $DB->get_recordset_sql($sql, $courseparams + $params), 'block');
        }

        // Questions of the question banks of the course and of its activities (each question once, whatever
        // its number of versions).
        if ($names['qtype']) {
            [$inqtypes, $params] = $DB->get_in_or_equal($names['qtype'], SQL_PARAMS_NAMED, 'qtype');
            $sql = "SELECT cc.instanceid AS course, q.qtype AS name, COUNT(DISTINCT qbe.id) AS uses
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                      JOIN {question_categories} qcat ON qcat.id = qbe.questioncategoryid
                      JOIN {context} ctx ON ctx.id = qcat.contextid
                      JOIN {context} cc ON cc.contextlevel = :courselevel AND $below
                     WHERE cc.instanceid $incourses AND q.qtype $inqtypes
                  GROUP BY cc.instanceid, q.qtype";
            $params['courselevel'] = CONTEXT_COURSE;
            self::add($counts, $DB->get_recordset_sql($sql, $courseparams + $params), 'qtype');
        }

        // Course format.
        if ($names['format']) {
            [$informats, $params] = $DB->get_in_or_equal($names['format'], SQL_PARAMS_NAMED, 'format');
            $sql = "SELECT c.id AS course, c.format AS name, 1 AS uses
                      FROM {course} c
                     WHERE c.id $incourses AND c.format $informats";
            self::add($counts, $DB->get_recordset_sql($sql, $courseparams + $params), 'format');
        }
        return $counts;
    }

    /**
     * Adds query results to the counts.
     *
     * @param array $counts
     * @param \moodle_recordset $records course, name, uses
     * @param string $type plugin type
     */
    protected static function add(array &$counts, \moodle_recordset $records, string $type): void {
        foreach ($records as $record) {
            $counts[(int)$record->course][$type . '_' . $record->name] = (int)$record->uses;
        }
        $records->close();
    }
}
