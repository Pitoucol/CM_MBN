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

use context_course;

/**
 * Estimates the size of a course backup without user data, split by question bank, common content and sections.
 *
 * Calculated when the user launches a backup (one query on the files of the course), nothing is stored.
 * Sizes are file sizes before compression, each file content counted once in each part :
 * - question bank : question files (component "question" or "qtype_*") and, in Moodle 5.x,
 *   files of the question bank activities ;
 * - common content : files included in every backup of the course (course, blocks...) ;
 * - sections : files of the activities of each section. In Moodle 5.x, a delegated section
 *   (subsection) is counted in the section of its parent activity.
 *
 * @package    report_coursemanager
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class size_estimator {
    /**
     * Estimate the sizes of a course backup.
     *
     * @param int $courseid Course ID.
     * @return \stdClass With common, questionbank and total sizes in bytes, and sections : list of objects
     *         (id, number, name, size, hasqbank, delegated : ids of delegated sections included), in course order.
     */
    public static function get_sizes(int $courseid): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/report/coursemanager/lib.php');

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);

        // Sections in course order. Delegated sections (Moodle 5.x subsections) are attached to the section
        // of their parent activity, as they are backed up with it.
        $cms = $modinfo->get_cms();
        $sections = [];
        $parentsection = [];
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            if (isset($sectioninfo->component) && $sectioninfo->component !== '') {
                foreach ($cms as $cm) {
                    if ($cm->modname === $sectioninfo->component && $cm->instance == $sectioninfo->itemid) {
                        $parentsection[$sectioninfo->section] = $cm->sectionnum;
                        break;
                    }
                }
                continue;
            }
            $sections[$sectioninfo->section] = (object) [
                'id' => $sectioninfo->id,
                'number' => $sectioninfo->section,
                'name' => get_section_name($course, $sectioninfo),
                'size' => 0,
                'hasqbank' => false,
                'delegated' => [],
            ];
        }
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            if (isset($parentsection[$sectioninfo->section]) && isset($sections[$parentsection[$sectioninfo->section]])) {
                $sections[$parentsection[$sectioninfo->section]]->delegated[] = $sectioninfo->id;
            }
        }
        // Main section of a section number (itself, or its parent for a delegated section).
        $mainsection = function(int $sectionnum) use ($parentsection) {
            return $parentsection[$sectionnum] ?? $sectionnum;
        };

        // Files of a backup without user data, each content counted once in each part.
        $common = [];
        $questionbank = [];
        $sectionfiles = [];

        [$where, $params] = report_coursemanager_get_backup_files_where(context_course::instance($courseid));
        $sql = 'SELECT f.id, f.contenthash, f.filesize, f.component, ctx.contextlevel, ctx.instanceid
                  FROM {files} f
                  JOIN {context} ctx ON ctx.id = f.contextid
                 WHERE ' . $where;
        $files = $DB->get_recordset_sql($sql, $params);
        foreach ($files as $file) {
            $cm = null;
            if ($file->contextlevel == CONTEXT_MODULE && isset($cms[$file->instanceid])) {
                $cm = $cms[$file->instanceid];
            }

            if ($file->component === 'question' || strpos($file->component, 'qtype_') === 0
                    || ($cm && $cm->modname === 'qbank')) {
                $questionbank[$file->contenthash] = $file->filesize;
            } else if ($cm && isset($sections[$mainsection($cm->sectionnum)])) {
                $sectionfiles[$mainsection($cm->sectionnum)][$file->contenthash] = $file->filesize;
            } else {
                $common[$file->contenthash] = $file->filesize;
            }
        }
        $files->close();

        // Moodle 5.x question banks are activities : their section must be in the first part, with the question bank.
        foreach ($cms as $cm) {
            if ($cm->modname === 'qbank' && isset($sections[$mainsection($cm->sectionnum)])) {
                $sections[$mainsection($cm->sectionnum)]->hasqbank = true;
            }
        }

        $result = (object) [
            'common' => array_sum($common),
            'questionbank' => array_sum($questionbank),
            'sections' => [],
            'total' => 0,
        ];
        $result->total = $result->common + $result->questionbank;
        foreach ($sections as $number => $section) {
            $section->size = isset($sectionfiles[$number]) ? array_sum($sectionfiles[$number]) : 0;
            $result->total += $section->size;
            $result->sections[] = $section;
        }
        return $result;
    }
}
