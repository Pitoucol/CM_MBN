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

namespace report_coursemanager\local\wiris;

/**
 * Scans, converts and restores Wiris formulas stored in the database.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /** Name of the report storing the result of the last check of a course (report_coursemanager_reports). */
    const REPORT = 'wiris';

    /** @var text_converter */
    protected $converter;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->converter = new text_converter(true);
    }

    /**
     * Finds every field containing Wiris formulas among the targets, and computes its converted value.
     *
     * @param array $targets from locator
     * @return array list of items: ['target', 'recordid', 'field', 'record', 'old', 'new', 'formulas', 'questionid']
     */
    public function scan(array $targets): array {
        $items = [];
        foreach ($targets as $target) {
            foreach ($this->fetch($target) as $record) {
                foreach ($target['fields'] as $field) {
                    $item = $this->process_value($target, $record, $field, $record->$field);
                    if ($item) {
                        $items[] = $item;
                    }
                }
            }
        }
        return $items;
    }

    /**
     * Records of a target that may contain formulas.
     *
     * @param array $target
     * @return \Generator of records
     */
    protected function fetch(array $target): \Generator {
        global $DB;
        $columns = 't.id, ' . implode(', ', array_map(function($f) {
            return "t.$f";
        }, $target['fields']));
        if (!empty($target['select'])) {
            $columns .= ', ' . $target['select'];
        }
        $join = $target['join'] ?? '';
        $params = $target['params'];
        $likes = [];
        if (empty($target['encoding'])) {
            foreach ($target['fields'] as $i => $f) {
                $likes[] = $DB->sql_like("t.$f", ":w2tlike$i", false);
                $params["w2tlike$i"] = '%math%';
            }
        }
        $where = '(' . $target['where'] . ')' . ($likes ? ' AND (' . implode(' OR ', $likes) . ')' : '');
        $chunks = [null];
        if (!empty($target['questionids'])) {
            $chunks = array_chunk($target['questionids'], 1000);
        }
        foreach ($chunks as $chunk) {
            $sql = "SELECT $columns FROM {{$target['table']}} t $join WHERE $where";
            $chunkparams = $params;
            if ($chunk !== null) {
                [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'w2tq');
                $sql .= " AND t.{$target['keyfield']} $insql";
                $chunkparams += $inparams;
            }
            $rs = $DB->get_recordset_sql($sql . ' ORDER BY t.id', $chunkparams);
            foreach ($rs as $record) {
                yield $record;
            }
            $rs->close();
        }
    }

    /**
     * Converts one stored value.
     *
     * @param array $target
     * @param \stdClass $record
     * @param string $field
     * @param string|null $value stored value
     * @return array|null item, or null if there is no formula
     */
    protected function process_value(array $target, \stdClass $record, string $field, ?string $value): ?array {
        if ($value === null || $value === '') {
            return null;
        }
        $isblock = ($target['encoding'] ?? '') === 'blockconfig';
        if ($isblock) {
            $config = self::decode_block_config($value);
            if (!$config || !isset($config->text) || !is_string($config->text)) {
                return null;
            }
            $text = $config->text;
        } else {
            $text = $value;
        }
        if (!text_converter::might_contain_formula($text)) {
            return null;
        }
        $result = $this->converter->convert($text);
        if (!$result['formulas']) {
            return null;
        }
        if ($isblock) {
            $config->text = $result['text'];
            $new = base64_encode(serialize($config));
        } else {
            $new = $result['text'];
        }
        $questionid = 0;
        if (($target['kind'] ?? '') === 'question') {
            $questionid = !empty($record->w2tparent) ? (int)$record->w2tparent : (int)$record->w2tquestion;
        }
        return [
            'target' => $target,
            'recordid' => (int)$record->id,
            'field' => $field,
            'record' => $record,
            'old' => $value,
            'new' => $new,
            'changed' => $new !== $value,
            'formulas' => $result['formulas'],
            'questionid' => $questionid,
        ];
    }

    /**
     * Decodes block_instances.configdata.
     *
     * @param string $configdata
     * @return \stdClass|null
     */
    protected static function decode_block_config(string $configdata): ?\stdClass {
        $raw = base64_decode($configdata, true);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            return unserialize_object($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Counts formulas in scanned items.
     *
     * @param array $items
     * @return array ['fields' => int, 'formulas' => int, 'failures' => int, 'annotations' => int]
     */
    public static function summarise(array $items): array {
        $s = ['fields' => 0, 'formulas' => 0, 'failures' => 0, 'annotations' => 0];
        foreach ($items as $item) {
            if ($item['changed']) {
                $s['fields']++;
            }
            foreach ($item['formulas'] as $f) {
                if ($f['ok']) {
                    $s['formulas']++;
                    if ($f['fromannotation']) {
                        $s['annotations']++;
                    }
                } else {
                    $s['failures']++;
                }
            }
        }
        return $s;
    }

    /**
     * Stores the result of a check of a course, done when the Wiris page of the course is displayed :
     * number of formulas left to convert and date of the check, for the "Downloads and exports" tab.
     *
     * @param int $courseid
     * @param int $formulas number of formulas found (converted or not convertible)
     */
    public static function save_check(int $courseid, int $formulas): void {
        global $DB;
        $detail = json_encode(['formulas' => $formulas, 'time' => time()]);
        $existing = $DB->get_record('report_coursemanager_reports', ['course' => $courseid, 'report' => self::REPORT],
            'id', IGNORE_MULTIPLE);
        if ($existing) {
            $DB->update_record('report_coursemanager_reports', (object)['id' => $existing->id, 'detail' => $detail]);
        } else {
            $DB->insert_record('report_coursemanager_reports',
                (object)['course' => $courseid, 'report' => self::REPORT, 'detail' => $detail]);
        }
    }

    /**
     * Forgets the result of the last check of a course, which is no longer valid (conversion or undo done
     * outside the Wiris page of the course, for example with the command line). The exports tab then shows the
     * "Check" link again. Nothing is checked here.
     *
     * @param int $courseid
     */
    public static function clear_check(int $courseid): void {
        global $DB;
        $DB->delete_records('report_coursemanager_reports', ['course' => $courseid, 'report' => self::REPORT]);
    }

    /**
     * Results of the last checks of several courses.
     *
     * @param int[] $courseids
     * @return \stdClass[] formulas, time ; indexed by course id, courses never checked are missing
     */
    public static function get_checks(array $courseids): array {
        global $DB;
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['report'] = self::REPORT;
        $checks = [];
        $records = $DB->get_recordset_select('report_coursemanager_reports', "course $insql AND report = :report",
            $params, '', 'id, course, detail');
        foreach ($records as $record) {
            $detail = json_decode((string)$record->detail);
            if (isset($detail->formulas, $detail->time)) {
                $checks[$record->course] = (object)['formulas' => (int)$detail->formulas, 'time' => (int)$detail->time];
            }
        }
        $records->close();
        return $checks;
    }

    /**
     * Converts: scans the targets again and writes the converted values, keeping the originals for undo.
     *
     * @param array $targets
     * @param int $courseid course id (0 for question banks outside courses)
     * @param int $userid
     * @param string $origin web|cli
     * @return \stdClass the run record
     */
    public function convert(array $targets, int $courseid, int $userid, string $origin = 'web'): \stdClass {
        global $DB;
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);

        $items = $this->scan($targets);
        $summary = self::summarise($items);

        $run = (object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'origin' => $origin,
            'fields' => $summary['fields'],
            'formulas' => $summary['formulas'],
            'failures' => $summary['failures'],
            'timecreated' => time(),
            'timereverted' => 0,
        ];
        $questionids = [];
        $transaction = $DB->start_delegated_transaction();
        $run->id = $DB->insert_record('report_coursemanager_wrun', $run);
        foreach ($items as $item) {
            if (!$item['changed']) {
                continue;
            }
            $table = $item['target']['table'];
            $DB->set_field($table, $item['field'], $item['new'], ['id' => $item['recordid']]);
            $DB->insert_record('report_coursemanager_wlog', (object)[
                'runid' => $run->id,
                'tablename' => $table,
                'recordid' => $item['recordid'],
                'fieldname' => $item['field'],
                'questionid' => $item['questionid'],
                'oldvalue' => $item['old'],
                'newvalue' => $item['new'],
                'formulas' => count(array_filter($item['formulas'], function($f) {
                    return $f['ok'];
                })),
                'reverted' => 0,
            ]);
            if ($item['questionid']) {
                $questionids[$item['questionid']] = true;
            }
            // The name of a "Text and media area" is computed from its text: compute it again.
            if ($table === 'label' && $item['field'] === 'intro') {
                $this->rename_label($run->id, $item['recordid']);
            }
        }
        $transaction->allow_commit();
        self::purge_caches($courseid, array_keys($questionids));

        // Event, from the web page and from the command line alike. Course 0 : question banks outside courses.
        \report_coursemanager\event\wiris_converted::create([
            'context' => $courseid ? \context_course::instance($courseid) : \context_system::instance(),
            'courseid' => $courseid,
            'userid' => $userid,
            'other' => ['runid' => (int)$run->id, 'fields' => (int)$run->fields, 'formulas' => (int)$run->formulas,
                'failures' => (int)$run->failures, 'origin' => $origin],
        ])->trigger();
        return $run;
    }

    /**
     * Recomputes the name of a label from its converted text, logging the change for undo.
     *
     * @param int $runid
     * @param int $labelid
     */
    protected function rename_label(int $runid, int $labelid): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/label/lib.php');
        $label = $DB->get_record('label', ['id' => $labelid]);
        if (!$label || !function_exists('get_label_name')) {
            return;
        }
        // Only names generated from a text containing a formula are recomputed.
        if (!preg_match('/' . text_converter::LAQUO . 'math\b|<math\b/iu', (string)$label->name)) {
            return;
        }
        $cm = get_coursemodule_from_instance('label', $labelid, $label->course, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $copy = clone($label);
        $copy->name = '';
        $copy->coursemodule = $cm->id;
        $newname = get_label_name($copy);
        if ($newname === $label->name) {
            return;
        }
        $DB->set_field('label', 'name', $newname, ['id' => $labelid]);
        $DB->insert_record('report_coursemanager_wlog', (object)['runid' => $runid, 'tablename' => 'label',
            'recordid' => $labelid, 'fieldname' => 'name', 'questionid' => 0, 'oldvalue' => $label->name,
            'newvalue' => $newname, 'formulas' => 0, 'reverted' => 0]);
    }

    /**
     * Restores the original values of a run. Fields modified since the conversion are left untouched.
     *
     * @param int $runid
     * @return array ['restored' => int, 'conflicts' => int]
     */
    public function undo(int $runid): array {
        global $DB;
        \core_php_time_limit::raise();
        $run = $DB->get_record('report_coursemanager_wrun', ['id' => $runid], '*', MUST_EXIST);
        $restored = 0;
        $conflicts = 0;
        $questionids = [];
        $transaction = $DB->start_delegated_transaction();
        $rs = $DB->get_recordset('report_coursemanager_wlog', ['runid' => $runid, 'reverted' => 0], 'id DESC');
        foreach ($rs as $log) {
            $current = $DB->get_field($log->tablename, $log->fieldname, ['id' => $log->recordid]);
            if ($current === false || $current !== $log->newvalue) {
                $conflicts++;
                continue;
            }
            $DB->set_field($log->tablename, $log->fieldname, $log->oldvalue, ['id' => $log->recordid]);
            $DB->set_field('report_coursemanager_wlog', 'reverted', 1, ['id' => $log->id]);
            if ($log->questionid) {
                $questionids[$log->questionid] = true;
            }
            $restored++;
        }
        $rs->close();
        if ($conflicts === 0) {
            $DB->set_field('report_coursemanager_wrun', 'timereverted', time(), ['id' => $runid]);
        }
        $transaction->allow_commit();
        self::purge_caches((int)$run->courseid, array_keys($questionids));

        \report_coursemanager\event\wiris_reverted::create([
            'context' => $run->courseid ? \context_course::instance($run->courseid) : \context_system::instance(),
            'courseid' => (int)$run->courseid,
            'other' => ['runid' => $runid, 'restored' => $restored, 'conflicts' => $conflicts],
        ])->trigger();
        return ['restored' => $restored, 'conflicts' => $conflicts];
    }

    /**
     * Purges the caches holding converted texts.
     *
     * @param int $courseid
     * @param int[] $questionids
     */
    protected static function purge_caches(int $courseid, array $questionids): void {
        global $CFG;
        if ($courseid) {
            rebuild_course_cache($courseid, true);
        }
        if ($questionids) {
            require_once($CFG->dirroot . '/question/engine/bank.php');
            foreach ($questionids as $questionid) {
                \question_bank::notify_question_edited($questionid);
            }
        }
    }

    /**
     * Plain, short version of a name that may contain Wiris formulas.
     *
     * @param string $name
     * @return string
     */
    public static function short_name(string $name): string {
        $name = preg_replace('/' . text_converter::LAQUO . 'math\b.*?(' . text_converter::LAQUO . '\/math'
            . text_converter::RAQUO . '|$)/su', '[…]', $name);
        $name = format_string($name);
        return \core_text::strlen($name) > 80 ? \core_text::substr($name, 0, 77) . '…' : $name;
    }

    /**
     * Human readable location of an item, with a link when possible.
     *
     * @param array $item
     * @param \course_modinfo|null $modinfo
     * @return array [string label, \moodle_url|null]
     */
    public static function describe(array $item, ?\course_modinfo $modinfo): array {
        $t = $item['target'];
        $r = $item['record'];
        $name = isset($r->w2tname) ? self::short_name((string)$r->w2tname) : '';
        switch ($t['kind']) {
            case 'course':
                return [get_string('wiris_where_course', 'report_coursemanager'),
                    new \moodle_url('/course/edit.php', ['id' => $r->id])];
            case 'section':
                $label = $name !== '' ? $name : get_section_name($r->w2tcourse, $r->w2tsection);
                return [get_string('wiris_where_section', 'report_coursemanager', $label),
                    new \moodle_url('/course/section.php', ['id' => $r->id])];
            case 'module':
                $modname = get_string('modulename', $t['modname']);
                $url = null;
                $activity = $name;
                if ($modinfo && isset($modinfo->instances[$t['modname']][$r->w2tinstance])) {
                    $cm = $modinfo->instances[$t['modname']][$r->w2tinstance];
                    $url = $cm->url;
                    $activity = self::short_name($cm->name);
                }
                if (!empty($r->w2tdiscussion)) {
                    $url = new \moodle_url('/mod/forum/discuss.php', ['d' => $r->w2tdiscussion], 'p' . $r->id);
                    $activity .= ' › ' . $name;
                } else if ($t['table'] !== $t['modname'] && $name !== '' && $name !== $activity) {
                    $activity .= ' › ' . $name;
                }
                return [$modname . ' : ' . $activity, $url];
            case 'block':
                return [get_string('wiris_where_block', 'report_coursemanager'), null];
            case 'question':
                $qid = !empty($r->w2tparent) ? $r->w2tparent : $r->w2tquestion;
                $label = get_string('wiris_where_question', 'report_coursemanager', $qid);
                if ($t['table'] === 'question' && $name !== '') {
                    $label .= ' : ' . $name;
                }
                return [$label, new \moodle_url('/question/bank/previewquestion/preview.php', ['id' => $qid])];
        }
        return [$t['table'], null];
    }
}
