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
 * Knows where rich text lives in the database for a course (activities, questions, blocks...).
 *
 * A "target" is an array:
 *  - table: table name (without prefix), aliased as t
 *  - fields: text columns to process
 *  - join: extra SQL joins
 *  - where: SQL condition, params: its parameters
 *  - select: extra columns (aliases starting with w2t) used to describe the record
 *  - kind: course|section|module|question|block
 *  - modname: module name (kind = module)
 *  - questionids / keyfield: for question tables, the record must have keyfield IN questionids
 *  - encoding: 'blockconfig' for block_instances.configdata (base64 serialized object)
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class locator {

    /** @var array Extra rich text fields of activity tables (besides intro). */
    const MODULE_FIELDS = [
        'page' => ['content'],
        'assign' => ['activity'],
        'workshop' => ['instructauthors', 'instructreviewers', 'conclusion'],
        'feedback' => ['page_after_submit'],
    ];

    /** @var array Rich text fields in question type tables. */
    const QUESTION_FIELDS = ['correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback', 'graderinfo',
        'responsetemplate', 'questiontext', 'answertext', 'specificfeedback', 'prtcorrect', 'prtpartiallycorrect',
        'prtincorrect', 'truefeedback', 'falsefeedback'];

    /**
     * All targets of a course: course summary, sections, activities, question banks, HTML blocks.
     *
     * @param int $courseid
     * @return array
     */
    public static function course_targets(int $courseid): array {
        global $DB;
        $dbman = $DB->get_manager();
        $context = \context_course::instance($courseid);
        $targets = [];
        $p = ['courseid' => $courseid];

        $targets[] = ['table' => 'course', 'fields' => ['summary'], 'where' => 't.id = :courseid', 'params' => $p,
            'select' => 't.fullname AS w2tname', 'kind' => 'course'];
        $targets[] = ['table' => 'course_sections', 'fields' => ['summary'], 'where' => 't.course = :courseid',
            'params' => $p, 'select' => 't.name AS w2tname, t.section AS w2tsection, t.course AS w2tcourse',
            'kind' => 'section'];

        // Activity descriptions and main contents.
        foreach ($DB->get_records('modules', null, 'name', 'id, name') as $module) {
            if (!$dbman->table_exists($module->name)) {
                continue;
            }
            $columns = $DB->get_columns($module->name);
            if (!isset($columns['course']) || !isset($columns['name'])) {
                continue;
            }
            $fields = array_values(array_intersect(array_merge(['intro'], self::MODULE_FIELDS[$module->name] ?? []),
                array_keys($columns)));
            if ($fields) {
                $targets[] = ['table' => $module->name, 'fields' => $fields, 'where' => 't.course = :courseid',
                    'params' => $p, 'select' => 't.name AS w2tname, t.id AS w2tinstance', 'kind' => 'module',
                    'modname' => $module->name];
            }
        }

        // Sub-tables of activities.
        $sub = [
            ['book_chapters', ['content'], 'book', 'bookid', 't.title'],
            ['lesson_pages', ['contents'], 'lesson', 'lessonid', 't.title'],
            ['lesson_answers', ['answer', 'response'], 'lesson', 'lessonid', 'p.name'],
            ['glossary_entries', ['definition'], 'glossary', 'glossaryid', 't.concept'],
            ['quiz_feedback', ['feedbacktext'], 'quiz', 'quizid', 'p.name'],
            ['assignfeedback_comments', ['commenttext'], 'assign', 'assignment', 'p.name'],
            ['assignsubmission_onlinetext', ['onlinetext'], 'assign', 'assignment', 'p.name'],
        ];
        foreach ($sub as [$table, $fields, $parent, $fk, $label]) {
            if (!$dbman->table_exists($table) || !$dbman->table_exists($parent)) {
                continue;
            }
            $targets[] = ['table' => $table, 'fields' => $fields, 'join' => "JOIN {{$parent}} p ON p.id = t.$fk",
                'where' => 'p.course = :courseid', 'params' => $p,
                'select' => "$label AS w2tname, p.id AS w2tinstance", 'kind' => 'module', 'modname' => $parent];
        }
        if ($dbman->table_exists('forum_posts')) {
            $targets[] = ['table' => 'forum_posts', 'fields' => ['message'],
                'join' => 'JOIN {forum_discussions} d ON d.id = t.discussion', 'where' => 'd.course = :courseid',
                'params' => $p, 'select' => 't.subject AS w2tname, d.forum AS w2tinstance, d.id AS w2tdiscussion',
                'kind' => 'module', 'modname' => 'forum'];
        }

        // HTML blocks of the course and of its activities.
        $blockparams = ['ctxid' => $context->id, 'ctxpath' => $DB->sql_like_escape($context->path) . '/%'];
        $targets[] = ['table' => 'block_instances', 'fields' => ['configdata'], 'encoding' => 'blockconfig',
            'join' => 'JOIN {context} bctx ON bctx.id = t.parentcontextid',
            'where' => "t.blockname = 'html' AND (bctx.id = :ctxid OR " . $DB->sql_like('bctx.path', ':ctxpath') . ')',
            'params' => $blockparams, 'select' => 't.blockname AS w2tname', 'kind' => 'block'];

        // Question banks of the course and of its activities.
        $contextids = $DB->get_fieldset_sql("SELECT id FROM {context} WHERE id = :ctxid OR " .
            $DB->sql_like('path', ':ctxpath'), $blockparams);
        return array_merge($targets, self::question_targets(self::question_ids($contextids)));
    }

    /**
     * Ids of the questions (and cloze sub-questions) in question categories of the given contexts.
     *
     * @param int[] $contextids
     * @return int[]
     */
    public static function question_ids(array $contextids): array {
        global $DB;
        if (!$contextids) {
            return [];
        }
        $ids = [];
        foreach (array_chunk($contextids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $sql = "SELECT q.id
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                      JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                     WHERE qc.contextid $insql";
            $ids = array_merge($ids, $DB->get_fieldset_sql($sql, $params));
        }
        // Sub-questions of cloze (multianswer) questions are not in the bank.
        $children = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $children = array_merge($children, $DB->get_fieldset_select('question', 'id', "parent $insql", $params));
        }
        $all = array_unique(array_map('intval', array_merge($ids, $children)));
        sort($all);
        return $all;
    }

    /**
     * Targets for the given questions: question texts, answers, hints, and question type specific feedbacks.
     *
     * @param int[] $questionids
     * @return array
     */
    public static function question_targets(array $questionids): array {
        global $DB;
        if (!$questionids) {
            return [];
        }
        $base = ['kind' => 'question', 'questionids' => $questionids, 'where' => '1 = 1', 'params' => []];
        $targets = [];
        $targets[] = $base + ['table' => 'question', 'fields' => ['questiontext', 'generalfeedback'],
            'keyfield' => 'id', 'select' => 't.name AS w2tname, t.id AS w2tquestion, t.parent AS w2tparent'];
        $targets[] = $base + ['table' => 'question_answers', 'fields' => ['answer', 'feedback'],
            'keyfield' => 'question', 'select' => 't.question AS w2tquestion'];
        $targets[] = $base + ['table' => 'question_hints', 'fields' => ['hint'], 'keyfield' => 'questionid',
            'select' => 't.questionid AS w2tquestion'];

        // Question type tables, discovered from the database structure.
        $skip = '/(attempt|usage|statistic|response|dataset|reference|bank_entries|versions|categories|_answers$|'
            . '_hints$|^question$)/';
        foreach ($DB->get_tables() as $table) {
            if (!preg_match('/^(qtype_|question_)/', $table) || preg_match($skip, $table)) {
                continue;
            }
            $columns = $DB->get_columns($table);
            $key = isset($columns['questionid']) ? 'questionid' : (isset($columns['question']) ? 'question' : null);
            if (!$key) {
                continue;
            }
            $fields = array_values(array_intersect(self::QUESTION_FIELDS, array_keys($columns)));
            if (!$fields) {
                continue;
            }
            $targets[] = $base + ['table' => $table, 'fields' => $fields, 'keyfield' => $key,
                'select' => "t.$key AS w2tquestion"];
        }
        return $targets;
    }

    /**
     * Question ids of question categories outside courses (system and course category contexts).
     *
     * @return int[]
     */
    public static function non_course_question_ids(): array {
        global $DB;
        $contextids = $DB->get_fieldset_select('context', 'id', 'contextlevel IN (:sys, :cat)',
            ['sys' => CONTEXT_SYSTEM, 'cat' => CONTEXT_COURSECAT]);
        return self::question_ids($contextids);
    }
}
