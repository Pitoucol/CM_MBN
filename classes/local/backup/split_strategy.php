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

use backup;
use moodle_url;

/**
 * Course backup split in several archives strictly under the size threshold, by sections.
 *
 * Used when the estimated size of the course backup reaches the size threshold :
 * - part 1 : common content, question bank (and, in Moodle 5.x, the sections of the question
 *   bank activities), then following sections, in course order, while the part stays under the threshold ;
 * - parts 2, 3... : common content and following sections under the threshold, without question bank.
 * Each part is a native Moodle course backup with only its sections, named "part1_<Moodle file name>"...
 * Restore : part 1 in a new course, then other parts in this course with "Merge the backup into this course".
 *
 * If the question bank or a section, with the common content, reaches the threshold, no backup is created :
 * the user is invited to make a manual backup of the whole course.
 *
 * @package    report_coursemanager
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class split_strategy extends base_strategy {
    /**
     * Short name of the strategy, used in logs.
     *
     * @return string
     */
    public function get_name(): string {
        return 'split';
    }

    /**
     * Split the course sections in parts under the threshold.
     *
     * @param launch_result $result Result, to add error steps.
     * @param int $courseid Course ID.
     * @return array|null Parts : list of objects (number, size, questionbank, sections), or null on error.
     */
    protected function plan_parts(launch_result $result, int $courseid): ?array {
        // Every archive must be strictly under the limit given by the manager (threshold less 2 %) : a part reaching
        // it is refused. $this->threshold holds this limit.
        $common = $this->sizes->common;
        $manuallink = (new moodle_url('/backup/backup.php', ['id' => $courseid]))->out();

        // Question bank (with common content, and Moodle 5.x question bank sections) must fit in part 1.
        $part1size = $common + $this->sizes->questionbank;
        foreach ($this->sizes->sections as $section) {
            if ($section->hasqbank) {
                $part1size += $section->size;
            }
        }
        if ($part1size >= $this->threshold) {
            $result->add_step(get_string('backup_title_error', 'report_coursemanager'),
                \html_writer::tag('p', get_string('backup_error_questionbank_limit', 'report_coursemanager', (object) [
                'size' => display_size($part1size),
                'threshold' => display_size($this->threshold),
                'link' => $manuallink,
            ]), ['class' => 'text-danger']));
            return null;
        }

        // Each section, with common content, must fit in a part.
        foreach ($this->sizes->sections as $section) {
            if ($common + $section->size >= $this->threshold) {
                $result->add_step(get_string('backup_title_error', 'report_coursemanager'),
                    \html_writer::tag('p', get_string('backup_error_section_limit', 'report_coursemanager', (object) [
                    'name' => s($section->name),
                    // Size compared to the threshold : the section with the content common to all parts.
                    'size' => display_size($common + $section->size),
                    'threshold' => display_size($this->threshold),
                    'link' => $manuallink,
                ]), ['class' => 'text-danger']));
                return null;
            }
        }

        // Part 1 : common content, question bank and its sections. Then sections in course order.
        $parts = [];
        $current = (object) ['number' => 1, 'size' => $part1size, 'questionbank' => true, 'sections' => []];
        foreach ($this->sizes->sections as $section) {
            if ($section->hasqbank) {
                $current->sections[] = $section;
            }
        }
        foreach ($this->sizes->sections as $section) {
            if ($section->hasqbank) {
                continue;
            }
            if ($current->size + $section->size >= $this->threshold) {
                $parts[] = $current;
                $current = (object) ['number' => count($parts) + 1, 'size' => $common, 'questionbank' => false,
                    'sections' => []];
            }
            $current->sections[] = $section;
            $current->size += $section->size;
        }
        $parts[] = $current;

        // Sections of each part, in course order.
        foreach ($parts as $part) {
            usort($part->sections, function($a, $b) {
                return $a->number <=> $b->number;
            });
        }
        return $parts;
    }

    /**
     * Launch the backup of a course, split in parts under the threshold, without asking anything to the user.
     *
     * @param int $courseid Course ID.
     * @param int $userid User who launches the backup.
     * @return launch_result
     */
    public function launch(int $courseid, int $userid): launch_result {
        global $CFG, $DB;

        $result = new launch_result();
        $this->add_size_step($result);

        $parts = $this->plan_parts($result, $courseid);
        if ($parts === null) {
            $result->error = true;
            return $result;
        }

        // One native backup per part, with only its sections. All parts share the same Moodle file name,
        // with a "part<number>_" prefix in the site language.
        $basename = null;
        // Backups of the parts already launched, marked as failed if a following part cannot be launched :
        // an incomplete set of archives must not look like a finished backup.
        $launched = [];
        $part = null;
        try {
            foreach ($parts as $part) {
                $bc = $this->create_controller($courseid, $userid);
                if ($basename === null) {
                    $basename = $this->get_default_filename($bc);
                }
                $insection = [];
                foreach ($part->sections as $section) {
                    $insection[$section->id] = true;
                    foreach ($section->delegated as $delegatedid) {
                        $insection[$delegatedid] = true;
                    }
                }
                foreach ($this->sizes->sections as $section) {
                    $ids = array_merge([$section->id], $section->delegated);
                    foreach ($ids as $sectionid) {
                        $this->set_setting($bc, 'section_' . $sectionid . '_included', !empty($insection[$sectionid]) ? 1 : 0);
                    }
                }
                // Moodle 4.x : whole question bank in part 1 only. Questions used by quizzes of other parts are still
                // included by Moodle, and found again when restoring in the same course.
                $this->set_setting($bc, 'questionbank', $part->questionbank ? 1 : 0);

                $prefix = get_string_manager()->get_string('backup_part_prefix', 'report_coursemanager', $part->number,
                    $CFG->lang);
                $part->filename = $prefix . $basename;
                $this->set_setting($bc, 'filename', $part->filename);
                $launched[] = $bc->get_backupid();
                $result->inbackground = $this->launch_controller($bc, $userid);
                $result->launched = true;
            }
        } catch (\Throwable $e) {
            if ($launched) {
                [$insql, $params] = $DB->get_in_or_equal($launched, SQL_PARAMS_NAMED);
                $params['finished'] = backup::STATUS_FINISHED_ERR;
                $DB->set_field_select('backup_controllers', 'status', backup::STATUS_FINISHED_ERR,
                    "backupid $insql AND status < :finished", $params);
            }
            $result->add_step(get_string('backup_title_error', 'report_coursemanager'),
                \html_writer::tag('p', get_string('backup_error_part', 'report_coursemanager', (object) [
                    'number' => $part ? $part->number : 1,
                    'message' => s($e->getMessage()),
                ])));
            $result->error = true;
            return $result;
        }

        // Parts : title with the file name, then question bank and sections, with their sizes.
        $archives = [];
        foreach ($parts as $part) {
            $html = \html_writer::tag('h4', get_string('backup_step_part', 'report_coursemanager', (object) [
                'number' => $part->number,
                'size' => display_size($part->size),
                'file' => s($part->filename),
            ]), ['class' => 'coursemanager-step-part']);
            // Question bank, in part 1 only : shown as soon as the course has questions, even without files
            // (its size is then 0 and not shown).
            if ($part->questionbank && ($this->sizes->questions > 0 || $this->sizes->questionbank > 0)) {
                $name = $this->sizes->questions > 0
                    ? get_string('backup_step_questionbank_detail', 'report_coursemanager', (object) [
                        'categories' => $this->sizes->categories,
                        'questions' => $this->sizes->questions,
                    ])
                    : get_string('backup_step_questionbank', 'report_coursemanager');
                $html .= \html_writer::div($this->format_content_line($name,
                    $this->sizes->questionbank > 0 ? $this->sizes->questionbank : null), 'coursemanager-step-group');
            }
            $lines = '';
            foreach ($part->sections as $section) {
                $lines .= $this->format_content_line($section->name, $section->size);
            }
            $html .= \html_writer::div($lines, 'coursemanager-step-group');
            $archives[] = \html_writer::div($html, 'coursemanager-step-archive');
        }
        // Archives separated by a horizontal line.
        $result->add_step(get_string('backup_title_split', 'report_coursemanager', count($parts)),
            implode(\html_writer::empty_tag('hr', ['class' => 'coursemanager-step-separator']), $archives));

        $this->add_launch_steps($result, true);
        return $result;
    }
}
