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

use moodle_url;

/**
 * Course backup split in several archives under the size threshold, by sections.
 *
 * Used when the estimated size of the course backup is over the size threshold :
 * - part 1 : common content, question bank (and, in Moodle 5.x, the sections of the question
 *   bank activities), then following sections, in course order, up to the threshold ;
 * - parts 2, 3... : common content and following sections up to the threshold, without question bank.
 * Each part is a native Moodle course backup with only its sections, named "part1_<Moodle file name>"...
 * Restore : part 1 in a new course, then other parts in this course with "Merge the backup into this course".
 *
 * If the question bank or a section alone exceeds the threshold, no backup is created :
 * the user is invited to make a manual backup of the whole course.
 *
 * @package    report_coursemanager
 * @copyright  2022 Olivier VALENTIN
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class split_strategy extends base_strategy {
    /** @var float Parts are filled up to this ratio of the threshold, as the estimation only counts files. */
    const MARGIN = 0.9;

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
        $target = (int)($this->threshold * self::MARGIN);
        $common = $this->sizes->common;
        $manuallink = (new moodle_url('/backup/backup.php', ['id' => $courseid]))->out();

        // Question bank (with common content, and Moodle 5.x question bank sections) must fit in part 1.
        $part1size = $common + $this->sizes->questionbank;
        foreach ($this->sizes->sections as $section) {
            if ($section->hasqbank) {
                $part1size += $section->size;
            }
        }
        if ($part1size > $target) {
            $result->add_step(get_string('backup_error_questionbank', 'report_coursemanager', (object) [
                'size' => display_size($part1size),
                'threshold' => display_size($this->threshold),
                'link' => $manuallink,
            ]));
            return null;
        }

        // Each section, with common content, must fit in a part.
        foreach ($this->sizes->sections as $section) {
            if ($common + $section->size > $target) {
                $result->add_step(get_string('backup_error_section', 'report_coursemanager', (object) [
                    'name' => s($section->name),
                    'size' => display_size($section->size),
                    'threshold' => display_size($this->threshold),
                    'link' => $manuallink,
                ]));
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
            if ($current->size + $section->size > $target) {
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
        global $CFG;

        $result = new launch_result();
        $result->add_step($this->get_size_step());

        $parts = $this->plan_parts($result, $courseid);
        if ($parts === null) {
            $result->error = true;
            return $result;
        }
        $result->add_step(get_string('backup_step_parts', 'report_coursemanager', count($parts)));

        // Content of each part.
        foreach ($parts as $part) {
            $content = [];
            if ($part->questionbank && $this->sizes->questionbank > 0) {
                $content[] = get_string('backup_step_questionbank', 'report_coursemanager',
                    display_size($this->sizes->questionbank));
            }
            foreach ($part->sections as $section) {
                $content[] = get_string('backup_step_section', 'report_coursemanager',
                    (object) ['name' => s($section->name), 'size' => display_size($section->size)]);
            }
            $result->add_step(get_string('backup_step_part', 'report_coursemanager',
                (object) ['number' => $part->number, 'size' => display_size($part->size)])
                . \html_writer::alist($content));
        }

        // One native backup per part, with only its sections. All parts share the same Moodle file name,
        // with a "part<number>_" prefix in the site language.
        $basename = null;
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
            $this->set_setting($bc, 'filename', $prefix . $basename);
            $result->inbackground = $this->launch_controller($bc, $userid);
            $result->launched = true;
            $result->add_step(get_string('backup_step_file', 'report_coursemanager', s($prefix . $basename)));
        }

        $result->add_step(get_string($result->inbackground ? 'backup_step_background' : 'backup_step_done',
            'report_coursemanager'));
        $result->add_step(get_string('backup_step_restore', 'report_coursemanager'));
        return $result;
    }
}
