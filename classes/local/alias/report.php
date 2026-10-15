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

namespace report_coursemanager\local\alias;

/**
 * Organises the aliases of a course for display: by place in the course (course, section, activity),
 * with a readable location, the source of each alias and whether it will be restored.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {

    /** @var array[] Groups: label, url, sortkey, rows. A row has: alias, where (label), whereurl, source (HTML). */
    public $groups = [];

    /** @var \stdClass */
    protected $course;

    /** @var \course_modinfo */
    protected $modinfo;

    /** @var \stdClass[] questions found for the aliases, indexed by file id */
    protected $questions = [];

    /** @var \section_info[] sections of the course, indexed by id */
    protected $sections = [];

    /**
     * Constructor.
     *
     * @param \stdClass[] $aliases from scanner::scan_course()
     * @param \stdClass $course
     */
    public function __construct(array $aliases, \stdClass $course) {
        $this->course = $course;
        $this->modinfo = get_fast_modinfo($course);
        foreach ($this->modinfo->get_section_info_all() as $section) {
            $this->sections[$section->id] = $section;
        }
        $this->questions = self::resolve_questions($aliases);

        $groups = [];
        foreach ($aliases as $alias) {
            $ctxid = (int)$alias->contextid;
            if (!isset($groups[$ctxid])) {
                $groups[$ctxid] = $this->describe_context($ctxid) + ['rows' => []];
            }
            [$where, $whereurl] = $this->describe_area($alias);
            $groups[$ctxid]['rows'][] = ['alias' => $alias, 'where' => $where, 'whereurl' => $whereurl,
                'source' => $this->describe_source($alias)];
        }
        uasort($groups, function(array $a, array $b): int {
            return $a['sortkey'] <=> $b['sortkey'];
        });
        $this->groups = array_values($groups);
    }

    /**
     * Label, link and sort key of the context where aliases are stored.
     *
     * @param int $contextid
     * @return array label, url, sortkey
     */
    protected function describe_context(int $contextid): array {
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context) {
            return ['label' => get_string('alias_where_unknown', 'report_coursemanager'), 'url' => null, 'sortkey' => [3, 0, 0, $contextid]];
        }
        if ($context->contextlevel == CONTEXT_COURSE) {
            return ['label' => get_string('alias_where_course', 'report_coursemanager'), 'url' => null,
                'sortkey' => [0, 0, 0, $contextid]];
        }
        if ($context->contextlevel == CONTEXT_MODULE && isset($this->modinfo->cms[$context->instanceid])) {
            $cm = $this->modinfo->cms[$context->instanceid];
            $label = get_section_name($this->course, $cm->sectionnum) . ' › '
                . get_string('modulename', $cm->modname) . ' : ' . $this->short($cm->name);
            return ['label' => $label, 'url' => $cm->url, 'sortkey' => [1, (int)$cm->sectionnum, (int)$cm->id, $contextid]];
        }
        if ($context->contextlevel == CONTEXT_BLOCK) {
            return ['label' => get_string('alias_where_block', 'report_coursemanager'), 'url' => null,
                'sortkey' => [2, 0, 0, $contextid]];
        }
        return ['label' => s($context->get_context_name(true, false, false)), 'url' => null,
            'sortkey' => [2, 1, 0, $contextid]];
    }

    /**
     * Where, inside its context, the alias is used (description, section, question...).
     *
     * @param \stdClass $alias
     * @return array label, url or null
     */
    protected function describe_area(\stdClass $alias): array {
        $isquestion = $alias->component === 'question' || strpos($alias->component, 'qtype_') === 0;
        if ($isquestion) {
            if (isset($this->questions[$alias->id])) {
                $question = $this->questions[$alias->id];
                return [get_string('alias_where_question', 'report_coursemanager', $this->short($question->name)),
                    new \moodle_url('/question/bank/previewquestion/preview.php', ['id' => $question->id])];
            }
            return [get_string('alias_where_questionother', 'report_coursemanager', s($alias->component . '/' . $alias->filearea)), null];
        }
        if ($alias->component === 'course' && $alias->filearea === 'section' && isset($this->sections[$alias->itemid])) {
            $section = $this->sections[$alias->itemid];
            return [get_string('alias_where_section', 'report_coursemanager', get_section_name($this->course, $section)),
                new \moodle_url('/course/section.php', ['id' => $section->id])];
        }
        $key = 'alias_area_' . $alias->filearea;
        if (get_string_manager()->string_exists($key, 'report_coursemanager')) {
            return [get_string($key, 'report_coursemanager'), null];
        }
        return [s($alias->component . ' / ' . $alias->filearea), null];
    }

    /**
     * Describes the source of an alias, and what happens at the restore (HTML).
     *
     * @param \stdClass $alias
     * @return string
     */
    protected function describe_source(\stdClass $alias): string {
        switch ($alias->kind) {
            case scanner::KIND_INTERNAL:
            case scanner::KIND_OUTSIDE:
                $src = $alias->source;
                $context = \context::instance_by_id($src['contextid'], IGNORE_MISSING);
                $where = $context ? $context->get_context_name(true, false) : s(get_string('alias_contextdeleted', 'report_coursemanager'));
                $repo = get_string_manager()->string_exists('alias_repo_' . $alias->repotype, 'report_coursemanager')
                    ? get_string('alias_repo_' . $alias->repotype, 'report_coursemanager') : s($alias->repotype);
                $text = $repo . ' — ' . $where . ' — ' . s($src['filepath'] . $src['filename']);
                if ($alias->sourceexists === false) {
                    $text .= ' ' . \html_writer::span(get_string('alias_sourcemissing', 'report_coursemanager'), 'badge badge-warning bg-warning text-dark');
                }
                return $text;
            case scanner::KIND_PRIVATE:
                return get_string('alias_kind_private', 'report_coursemanager');
            case scanner::KIND_EXTERNAL:
                $name = trim((string)$alias->reponame) !== '' ? $alias->reponame : ((string)$alias->repotype !== ''
                    ? $alias->repotype : get_string('alias_repo_unknown', 'report_coursemanager'));
                return get_string('alias_kind_external', 'report_coursemanager', s($name));
        }
        return get_string('alias_kind_broken', 'report_coursemanager');
    }

    /**
     * Shortened, safe version of a name.
     *
     * @param string $name
     * @return string
     */
    protected function short(string $name): string {
        $name = format_string($name, true, ['context' => \context_course::instance($this->course->id)]);
        return \core_text::strlen($name) > 80 ? \core_text::substr($name, 0, 77) . '…' : $name;
    }

    /**
     * Finds the question that owns the files of the question areas.
     *
     * The item id of a question file is the question id, or the id of an answer or of a hint. A match is kept only when
     * the question is in the question category stored in the context of the file.
     *
     * @param \stdClass[] $aliases
     * @return \stdClass[] question records (id, name, contextid) indexed by file id
     */
    protected static function resolve_questions(array $aliases): array {
        global $DB;
        $direct = [];
        $viaanswer = [];
        $viahint = [];
        $byid = [];
        foreach ($aliases as $alias) {
            if ($alias->component !== 'question' && strpos($alias->component, 'qtype_') !== 0) {
                continue;
            }
            $byid[$alias->id] = $alias;
            if (in_array($alias->filearea, ['answerfeedback', 'answer'], true)) {
                $viaanswer[$alias->id] = (int)$alias->itemid;
            } else if ($alias->filearea === 'hint') {
                $viahint[$alias->id] = (int)$alias->itemid;
            } else {
                $direct[$alias->id] = (int)$alias->itemid;
            }
        }
        $candidate = $direct;
        if ($viaanswer) {
            $rows = $DB->get_records_list('question_answers', 'id', array_values(array_unique($viaanswer)), '', 'id, question');
            foreach ($viaanswer as $fileid => $answerid) {
                if (isset($rows[$answerid])) {
                    $candidate[$fileid] = (int)$rows[$answerid]->question;
                }
            }
        }
        if ($viahint) {
            $rows = $DB->get_records_list('question_hints', 'id', array_values(array_unique($viahint)), '', 'id, questionid');
            foreach ($viahint as $fileid => $hintid) {
                if (isset($rows[$hintid])) {
                    $candidate[$fileid] = (int)$rows[$hintid]->questionid;
                }
            }
        }
        if (!$candidate) {
            return [];
        }

        $questions = [];
        foreach (array_chunk(array_values(array_unique($candidate)), 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $questions += $DB->get_records_sql("SELECT q.id, q.name, qc.contextid
                                                  FROM {question} q
                                                  JOIN {question_versions} qv ON qv.questionid = q.id
                                                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                                                 WHERE q.id $insql", $params);
        }
        $found = [];
        foreach ($candidate as $fileid => $questionid) {
            if (isset($questions[$questionid]) && (int)$questions[$questionid]->contextid === (int)$byid[$fileid]->contextid) {
                $found[$fileid] = $questions[$questionid];
            }
        }
        return $found;
    }
}
