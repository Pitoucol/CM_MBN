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
 * Tests of the detection and of the conversion of aliases.
 *
 * The tests need the "Server files" repository (type "local"); they are skipped if the site has no instance of it.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_coursemanager\local\alias\scanner
 * @covers     \report_coursemanager\local\alias\manager
 */
final class manager_test extends \advanced_testcase {

    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass a page of the course */
    private $page;

    /** @var int id of the "Server files" repository instance */
    private $repoid;

    /**
     * Creates a course with a page and finds the "Server files" repository.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->repoid = (int)$DB->get_field_sql("SELECT MIN(ri.id)
                                                   FROM {repository_instances} ri
                                                   JOIN {repository} r ON r.id = ri.typeid
                                                  WHERE r.type = :type", ['type' => 'local']);
        if (!$this->repoid) {
            $this->markTestSkipped('No instance of the Server files repository.');
        }
    }

    /**
     * Creates a real image file in a context.
     *
     * @param int $contextid
     * @param string $name
     * @return \stored_file
     */
    private function create_source(int $contextid, string $name): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $contextid, 'component' => 'mod_page', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => $name,
        ], 'content of ' . $name);
    }

    /**
     * Creates an alias in the page of the course, pointing to a source file.
     *
     * @param \stored_file $source
     * @param string $name name of the alias
     * @return \stored_file
     */
    private function create_alias(\stored_file $source, string $name): \stored_file {
        $reference = \file_storage::pack_reference([
            'contextid' => $source->get_contextid(), 'component' => $source->get_component(),
            'filearea' => $source->get_filearea(), 'itemid' => $source->get_itemid(),
            'filepath' => $source->get_filepath(), 'filename' => $source->get_filename(),
        ]);
        $pagecontext = \context_module::instance($this->page->cmid);
        return get_file_storage()->create_file_from_reference([
            'contextid' => $pagecontext->id, 'component' => 'mod_page', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => $name,
        ], $this->repoid, $reference);
    }

    /**
     * An alias to a file outside of the course is detected as lost, one to a file of the course is not.
     */
    public function test_scan_classifies_aliases(): void {
        $other = $this->getDataGenerator()->create_course();
        $outside = $this->create_source(\context_course::instance($other->id)->id, 'outside.png');
        $inside = $this->create_source(\context_module::instance($this->page->cmid)->id, 'inside.png');
        $this->create_alias($outside, 'alias_outside.png');
        $this->create_alias($inside, 'alias_inside.png');

        $aliases = scanner::scan_course($this->course->id);
        $this->assertCount(2, $aliases);
        $bykind = array_column($aliases, null, 'filename');
        $this->assertSame(scanner::KIND_OUTSIDE, $bykind['alias_outside.png']->kind);
        $this->assertTrue($bykind['alias_outside.png']->lost);
        $this->assertTrue($bykind['alias_outside.png']->sourceexists);
        $this->assertSame(scanner::KIND_INTERNAL, $bykind['alias_inside.png']->kind);
        $this->assertFalse($bykind['alias_inside.png']->lost);

        $this->assertCount(1, scanner::filter($aliases, scanner::SCOPE_LOST));
        $this->assertCount(2, scanner::filter($aliases, scanner::SCOPE_ALL));
        $summary = scanner::summarise($aliases);
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['lost']);
    }

    /**
     * Every type of file is listed: images, H5P packages and other files.
     */
    public function test_scan_every_type(): void {
        $other = $this->getDataGenerator()->create_course();
        $othercontext = \context_course::instance($other->id)->id;
        $this->create_alias($this->create_source($othercontext, 'picture.png'), 'picture.png');
        $this->create_alias($this->create_source($othercontext, 'activity.h5p'), 'activity.h5p');
        $this->create_alias($this->create_source($othercontext, 'document.pdf'), 'document.pdf');

        $aliases = scanner::scan_course($this->course->id);
        $this->assertCount(3, $aliases);
        $bytype = array_column($aliases, 'type', 'filename');
        $this->assertSame(scanner::TYPE_IMAGE, $bytype['picture.png']);
        $this->assertSame(scanner::TYPE_H5P, $bytype['activity.h5p']);
        $this->assertSame(scanner::TYPE_FILE, $bytype['document.pdf']);
    }

    /**
     * The number of aliases lost by a backup is stored for the "Downloads and exports" tab.
     */
    public function test_save_report(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_course();
        $source = $this->create_source(\context_course::instance($other->id)->id, 'outside.png');
        $this->create_alias($source, 'alias.png');

        $this->assertSame(1, scanner::save_report($this->course->id));
        $this->assertEquals(1, $DB->get_field('report_coursemanager_reports', 'detail',
            ['course' => $this->course->id, 'report' => scanner::REPORT]));

        (new manager())->convert(scanner::scan_course($this->course->id), $this->course->id, get_admin()->id);
        $this->assertSame(0, scanner::save_report($this->course->id));
        $this->assertEquals(0, $DB->get_field('report_coursemanager_reports', 'detail',
            ['course' => $this->course->id, 'report' => scanner::REPORT]));
        $this->assertSame(1, $DB->count_records('report_coursemanager_reports',
            ['course' => $this->course->id, 'report' => scanner::REPORT]));
    }

    /**
     * Converting keeps the place, the name and the content, and removes the alias.
     */
    public function test_convert_makes_a_real_file(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_course();
        $source = $this->create_source(\context_course::instance($other->id)->id, 'outside.png');
        $alias = $this->create_alias($source, 'alias.png');
        $this->assertNotEmpty($DB->get_field('files', 'referencefileid', ['id' => $alias->get_id()]));

        $manager = new manager();
        $aliases = scanner::filter(scanner::scan_course($this->course->id), scanner::SCOPE_LOST);
        $run = $manager->convert($aliases, $this->course->id, get_admin()->id);
        $this->assertSame(1, $run->aliases);
        $this->assertSame(0, $run->failures);

        $file = get_file_storage()->get_file_by_id($alias->get_id());
        $this->assertFalse($file->is_external_file());
        $this->assertSame('alias.png', $file->get_filename());
        $this->assertEquals($alias->get_contextid(), $file->get_contextid());
        $this->assertSame('content of outside.png', $file->get_content());
        $this->assertCount(0, scanner::scan_course($this->course->id));
        // The source is untouched.
        $this->assertTrue(get_file_storage()->file_exists($source->get_contextid(), $source->get_component(),
            $source->get_filearea(), $source->get_itemid(), $source->get_filepath(), $source->get_filename()));
    }

    /**
     * A converted alias can be restored, unless the file was changed in the meantime.
     */
    public function test_undo(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_course();
        $source = $this->create_source(\context_course::instance($other->id)->id, 'outside.png');
        $alias = $this->create_alias($source, 'alias.png');

        $manager = new manager();
        $run = $manager->convert(scanner::scan_course($this->course->id), $this->course->id, get_admin()->id);
        $result = $manager->undo($run->id);
        $this->assertSame(['restored' => 1, 'conflicts' => 0], $result);
        $this->assertNotEmpty($DB->get_field('files', 'referencefileid', ['id' => $alias->get_id()]));
        $this->assertCount(1, scanner::scan_course($this->course->id));
        $this->assertGreaterThan(0, $DB->get_field('report_coursemanager_arun', 'timereverted', ['id' => $run->id]));

        // Convert again, then change the file: undo must leave it alone.
        $run = $manager->convert(scanner::scan_course($this->course->id), $this->course->id, get_admin()->id);
        $DB->set_field('files', 'contenthash', sha1('something else'), ['id' => $alias->get_id()]);
        $result = $manager->undo($run->id);
        $this->assertSame(['restored' => 0, 'conflicts' => 1], $result);
        $this->assertEmpty($DB->get_field('files', 'referencefileid', ['id' => $alias->get_id()]));
    }

    /**
     * An alias whose source was deleted is still converted, and is no longer flagged as missing.
     */
    public function test_convert_alias_with_deleted_source(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_course();
        $source = $this->create_source(\context_course::instance($other->id)->id, 'outside.png');
        $alias = $this->create_alias($source, 'alias.png');
        $source->delete();

        $aliases = scanner::scan_course($this->course->id);
        $this->assertFalse($aliases[0]->sourceexists);
        $run = (new manager())->convert($aliases, $this->course->id, get_admin()->id);
        $this->assertSame(1, $run->aliases);
        $this->assertEmpty($DB->get_field('files', 'referencefileid', ['id' => $alias->get_id()]));
        $this->assertEquals(0, $DB->get_field('files', 'status', ['id' => $alias->get_id()]));
    }
}
