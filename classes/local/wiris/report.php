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
 * Organises the scanned formulas of a course for display, in three parts:
 *  1. course content, by section and activity;
 *  2. questions used in the quizzes, by section, quiz and question;
 *  3. questions of the question banks, by category (with the quizzes using them).
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {

    /** Pseudo section number: course presentation (course summary). */
    const SECTION_COURSE = -1;
    /** Pseudo section number: HTML blocks. */
    const SECTION_BLOCKS = -2;
    /** Pseudo section number: contents whose activity was not found. */
    const SECTION_OTHER = -3;

    /** @var array Part 1: sectionnum => ['name', 'count', 'groups' => [key => ['label', 'url', 'rows']]]. */
    public $content = [];

    /** @var array Part 2: sectionnum => ['name', 'count', 'quizzes' => [cmid => ['label', 'url', 'count',
     *      'questions' => [qid => ['name', 'url', 'rows']]]]]. */
    public $quizzes = [];

    /** @var array Part 3: categoryid => ['name', 'questions' => [qid => ['name', 'url', 'rows', 'used', 'failures']]]. */
    public $bank = [];

    /** @var array Number of formulas (converted or not) per part, and questions in the bank. */
    public $counts = ['content' => 0, 'quizzes' => 0, 'bank' => 0, 'bankquestions' => 0, 'failures' => 0];

    /** @var \stdClass */
    protected $course;

    /** @var \course_modinfo */
    protected $modinfo;

    /**
     * Builds the report.
     *
     * @param array $items items from manager::scan()
     * @param \stdClass $course
     */
    public function __construct(array $items, \stdClass $course) {
        $this->course = $course;
        $this->modinfo = get_fast_modinfo($course);
        $questionitems = [];
        foreach ($items as $item) {
            foreach ($item['formulas'] as $formula) {
                if (!$formula['ok']) {
                    $this->counts['failures']++;
                }
            }
            if (($item['target']['kind'] ?? '') === 'question') {
                $questionitems[] = $item;
            } else {
                $this->add_content_item($item);
            }
        }
        $this->add_question_items($questionitems);
        $this->sort();
    }

    /**
     * Part 1: a course content item.
     *
     * @param array $item
     */
    protected function add_content_item(array $item): void {
        $t = $item['target'];
        $r = $item['record'];
        $section = self::SECTION_OTHER;
        $groupkey = 'other';
        $grouplabel = get_string('wiris_section_other', 'report_coursemanager');
        $groupurl = null;

        switch ($t['kind']) {
            case 'course':
                $section = self::SECTION_COURSE;
                $groupkey = 'course';
                $grouplabel = get_string('wiris_where_course', 'report_coursemanager');
                $groupurl = new \moodle_url('/course/edit.php', ['id' => $this->course->id]);
                break;
            case 'section':
                $section = (int)$r->w2tsection;
                $groupkey = 'sectionsummary';
                $grouplabel = get_string('wiris_f_course_sections_summary', 'report_coursemanager');
                $groupurl = new \moodle_url('/course/editsection.php', ['id' => $r->id]);
                break;
            case 'block':
                $section = self::SECTION_BLOCKS;
                $groupkey = 'block' . $r->id;
                $grouplabel = get_string('wiris_where_block', 'report_coursemanager');
                break;
            case 'module':
                $instance = (int)($r->w2tinstance ?? 0);
                $cm = $this->modinfo->instances[$t['modname']][$instance] ?? null;
                if ($cm) {
                    $section = (int)$cm->sectionnum;
                    $groupkey = 'cm' . $cm->id;
                    $grouplabel = get_string('modulename', $t['modname']) . ' : ' . manager::short_name($cm->name);
                    $groupurl = $cm->url;
                } else {
                    $groupkey = $t['modname'] . $instance;
                    $grouplabel = get_string('modulename', $t['modname']) . ' : '
                        . manager::short_name((string)($r->w2tname ?? ''));
                }
                break;
        }

        if (!isset($this->content[$section])) {
            $this->content[$section] = ['name' => $this->section_name($section), 'count' => 0, 'groups' => []];
        }
        if (!isset($this->content[$section]['groups'][$groupkey])) {
            $this->content[$section]['groups'][$groupkey] = ['label' => $grouplabel, 'url' => $groupurl, 'rows' => []];
        }
        $field = self::field_label($item);
        foreach ($item['formulas'] as $formula) {
            $this->content[$section]['groups'][$groupkey]['rows'][] = ['field' => $field, 'formula' => $formula];
            $this->content[$section]['count']++;
            $this->counts['content']++;
        }
    }

    /**
     * Parts 2 and 3: question items.
     *
     * @param array $items
     */
    protected function add_question_items(array $items): void {
        global $DB;
        if (!$items) {
            return;
        }
        $qids = array_values(array_unique(array_map(function($i) {
            return (int)$i['questionid'];
        }, $items)));

        // Question names, versions and categories.
        $questions = [];
        foreach (array_chunk($qids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $sql = "SELECT q.id, q.name, qv.version, qv.questionbankentryid AS entryid,
                           qbe.questioncategoryid AS categoryid
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                     WHERE q.id $insql";
            $questions += $DB->get_records_sql($sql, $params);
        }
        $categories = [];
        $catids = array_unique(array_map(function($q) {
            return $q->categoryid;
        }, $questions));
        if ($catids) {
            $categories = $DB->get_records_list('question_categories', 'id', $catids, '', 'id, name, contextid');
        }

        // Quizzes of the course using each question bank entry.
        $usage = [];
        $entryids = array_unique(array_map(function($q) {
            return $q->entryid;
        }, $questions));
        foreach (array_chunk($entryids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $sql = "SELECT qr.id, qr.questionbankentryid AS entryid, qs.quizid
                      FROM {question_references} qr
                      JOIN {quiz_slots} qs ON qs.id = qr.itemid
                     WHERE qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                           AND qr.questionbankentryid $insql";
            foreach ($DB->get_records_sql($sql, $params) as $ref) {
                $cm = $this->modinfo->instances['quiz'][$ref->quizid] ?? null;
                if ($cm) {
                    $usage[$ref->entryid][$cm->id] = $cm;
                }
            }
        }

        foreach ($items as $item) {
            $qid = (int)$item['questionid'];
            $q = $questions[$qid] ?? null;
            $name = $q ? manager::short_name($q->name) : get_string('wiris_where_question', 'report_coursemanager', $qid);
            if ($q && $q->version > 1) {
                $name .= ' (' . get_string('wiris_version_n', 'report_coursemanager', $q->version) . ')';
            }
            $qurl = new \moodle_url('/question/bank/previewquestion/preview.php', ['id' => $qid]);
            $field = self::field_label($item);
            $rows = [];
            foreach ($item['formulas'] as $formula) {
                $rows[] = ['field' => $field, 'formula' => $formula];
            }
            $used = $q ? ($usage[$q->entryid] ?? []) : [];

            // Part 2: under each quiz using the question.
            foreach ($used as $cm) {
                $section = (int)$cm->sectionnum;
                if (!isset($this->quizzes[$section])) {
                    $this->quizzes[$section] = ['name' => $this->section_name($section), 'count' => 0, 'quizzes' => []];
                }
                if (!isset($this->quizzes[$section]['quizzes'][$cm->id])) {
                    $this->quizzes[$section]['quizzes'][$cm->id] = ['label' => manager::short_name($cm->name),
                        'url' => $cm->url, 'count' => 0, 'questions' => []];
                }
                $quiz = &$this->quizzes[$section]['quizzes'][$cm->id];
                if (!isset($quiz['questions'][$qid])) {
                    $quiz['questions'][$qid] = ['name' => $name, 'url' => $qurl, 'rows' => []];
                }
                $quiz['questions'][$qid]['rows'] = array_merge($quiz['questions'][$qid]['rows'], $rows);
                $quiz['count'] += count($rows);
                $this->quizzes[$section]['count'] += count($rows);
                $this->counts['quizzes'] += count($rows);
                unset($quiz);
            }

            // Part 3: in its category.
            $catid = $q ? (int)$q->categoryid : 0;
            if (!isset($this->bank[$catid])) {
                $catname = isset($categories[$catid]) ? format_string($categories[$catid]->name)
                    : get_string('wiris_category_unknown', 'report_coursemanager');
                $this->bank[$catid] = ['name' => $catname, 'questions' => []];
            }
            if (!isset($this->bank[$catid]['questions'][$qid])) {
                $this->bank[$catid]['questions'][$qid] = ['name' => $name, 'url' => $qurl, 'rows' => [],
                    'used' => $used, 'failures' => 0];
                $this->counts['bankquestions']++;
            }
            $this->bank[$catid]['questions'][$qid]['rows'] = array_merge($this->bank[$catid]['questions'][$qid]['rows'],
                $rows);
            foreach ($rows as $row) {
                if (!$row['formula']['ok']) {
                    $this->bank[$catid]['questions'][$qid]['failures']++;
                }
            }
            $this->counts['bank'] += count($rows);
        }
    }

    /**
     * Name of a section (or pseudo section).
     *
     * @param int $section
     * @return string
     */
    protected function section_name(int $section): string {
        switch ($section) {
            case self::SECTION_COURSE:
                return get_string('wiris_section_course', 'report_coursemanager');
            case self::SECTION_BLOCKS:
                return get_string('wiris_section_blocks', 'report_coursemanager');
            case self::SECTION_OTHER:
                return get_string('wiris_section_other', 'report_coursemanager');
        }
        return get_section_name($this->course, $section);
    }

    /**
     * Sorts sections in course order: course presentation, sections 0..n, blocks, others.
     */
    protected function sort(): void {
        $order = function($a, $b) {
            $rank = function($s) {
                if ($s === self::SECTION_COURSE) {
                    return -1000000;
                }
                if ($s < 0) {
                    return 1000000 - $s;
                }
                return $s;
            };
            return $rank($a) <=> $rank($b);
        };
        uksort($this->content, $order);
        uksort($this->quizzes, $order);
        // Activities in the order of the course page.
        $cmorder = array_flip(array_keys($this->modinfo->get_cms()));
        foreach ($this->content as &$section) {
            uksort($section['groups'], function($a, $b) use ($cmorder) {
                $ra = (strpos($a, 'cm') === 0) ? ($cmorder[(int)substr($a, 2)] ?? PHP_INT_MAX) : -1;
                $rb = (strpos($b, 'cm') === 0) ? ($cmorder[(int)substr($b, 2)] ?? PHP_INT_MAX) : -1;
                return $ra <=> $rb;
            });
        }
        unset($section);
        foreach ($this->quizzes as &$section) {
            uksort($section['quizzes'], function($a, $b) use ($cmorder) {
                return ($cmorder[$a] ?? PHP_INT_MAX) <=> ($cmorder[$b] ?? PHP_INT_MAX);
            });
        }
        unset($section);
        uasort($this->bank, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
    }

    /**
     * Human readable name of the field holding a formula (statement, answer, page content...).
     *
     * @param array $item
     * @return string
     */
    public static function field_label(array $item): string {
        $t = $item['target'];
        $r = $item['record'];
        $table = $t['table'];
        $field = $item['field'];
        $sm = get_string_manager();
        if ($table === 'question' && $field === 'questiontext' && !empty($r->w2tparent)) {
            $label = get_string('wiris_f_question_sub', 'report_coursemanager');
        } else if ($sm->string_exists("wiris_f_{$table}_{$field}", 'report_coursemanager')) {
            $label = get_string("wiris_f_{$table}_{$field}", 'report_coursemanager');
        } else if ($sm->string_exists("wiris_f_{$field}", 'report_coursemanager')) {
            $label = get_string("wiris_f_{$field}", 'report_coursemanager');
        } else {
            $label = "$table.$field";
        }
        // Sub-records with their own title (chapter, page, glossary entry, forum post).
        if (in_array($table, ['book_chapters', 'lesson_pages', 'glossary_entries', 'forum_posts'])
                && !empty($r->w2tname)) {
            $label .= ' : ' . manager::short_name((string)$r->w2tname);
        }
        return $label;
    }
}
