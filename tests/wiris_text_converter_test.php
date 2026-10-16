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
 * Tests of the Wiris to LaTeX conversion.
 *
 * @package    report_coursemanager
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_coursemanager\local\wiris\text_converter
 * @covers     \report_coursemanager\local\wiris\mathml_to_latex
 */
final class text_converter_test extends \advanced_testcase {

    /**
     * Wraps MathML content in a SafeXML math element.
     *
     * @param string $inner
     * @return string
     */
    private static function safe(string $inner): string {
        return '«math xmlns=¨http://www.w3.org/1998/Math/MathML¨»' . $inner . '«/math»';
    }

    /**
     * Formulas and their expected LaTeX.
     *
     * @return array
     */
    public static function formula_provider(): array {
        return [
            'fraction' => [self::safe('«mfrac»«mn»1«/mn»«mn»2«/mn»«/mfrac»'), '\frac{1}{2}'],
            'power' => [self::safe('«msup»«mi»x«/mi»«mn»2«/mn»«/msup»«mo»-«/mo»«mn»1«/mn»'), 'x^{2}-1'],
            'root' => [self::safe('«mroot»«mi»x«/mi»«mn»3«/mn»«/mroot»'), '\sqrt[3]{x}'],
            'entity' => [self::safe('«mi»α«/mi»«mo»§#8712;«/mo»«mi mathvariant=¨normal¨»§#8477;«/mi»'),
                '\alpha\in\mathbb{R}'],
            'integral' => [self::safe('«msubsup»«mo»§#8747;«/mo»«mn»0«/mn»«mn»1«/mn»«/msubsup»«mi»f«/mi»'),
                '\int_{0}^{1}f'],
            'sum' => [self::safe('«munderover»«mo»§#8721;«/mo»«mrow»«mi»k«/mi»«mo»=«/mo»«mn»1«/mn»«/mrow»'
                . '«mi»n«/mi»«/munderover»«mi»k«/mi»'), '\sum_{k=1}^{n}k'],
            'vector' => [self::safe('«mover»«mi»u«/mi»«mo»§#8594;«/mo»«/mover»'), '\vec{u}'],
            'wide vector' => [self::safe('«mover»«mrow»«mi»A«/mi»«mi»B«/mi»«/mrow»«mo»§#8594;«/mo»«/mover»'),
                '\overrightarrow{AB}'],
            'tall parentheses' => [self::safe('«mfenced»«mfrac»«mn»1«/mn»«mn»2«/mn»«/mfrac»«/mfenced»'),
                '\left( \frac{1}{2} \right)'],
            'system' => [self::safe('«mfenced open=¨{¨ close=¨¨»«mtable columnalign=¨left¨»«mtr»«mtd»«mi»x«/mi»'
                . '«/mtd»«/mtr»«mtr»«mtd»«mi»y«/mi»«/mtd»«/mtr»«/mtable»«/mfenced»'),
                '\left\{ \begin{array}{l} x \\\\ y \end{array} \right.'],
            'decimal comma' => [self::safe('«mn»3,14«/mn»'), '3{,}14'],
            'text' => [self::safe('«mtext»l`aire«/mtext»'), '\text{l\'aire}'],
            'chemistry' => [self::safe('«msub»«mi mathvariant=¨normal¨»H«/mi»«mn»2«/mn»«/msub»«mi mathvariant=¨normal¨»O«/mi»'),
                '\mathrm{H}_{2}\mathrm{O}'],
            'prime' => [self::safe('«msup»«mi»f«/mi»«mo»§#8242;«/mo»«/msup»'), "f'"],
            'annotation' => [self::safe('«semantics»«mi»x«/mi»«annotation encoding=¨LaTeX¨»\sqrt{x}«/annotation»'
                . '«/semantics»'), '\sqrt{x}'],
            'escape' => [self::safe('«mn»50«/mn»«mo»%«/mo»'), '50\%'],
        ];
    }

    /**
     * Converts single formulas.
     *
     * @dataProvider formula_provider
     * @param string $safexml
     * @param string $expected
     */
    public function test_formulas(string $safexml, string $expected): void {
        $converter = new text_converter();
        $result = $converter->convert('<p>' . $safexml . '</p>');
        $this->assertCount(1, $result['formulas']);
        $this->assertTrue($result['formulas'][0]['ok'], (string)$result['formulas'][0]['error']);
        $this->assertSame($expected, $result['formulas'][0]['latex']);
        $this->assertSame('<p>' . text_converter::wrap($expected) . '</p>', $result['text']);
    }

    /**
     * Wiris images, HTML-encoded SafeXML, plain MathML and broken formulas.
     */
    public function test_storage_forms(): void {
        $converter = new text_converter();
        $frac = self::safe('«mfrac»«mn»1«/mn»«mn»2«/mn»«/mfrac»');

        $img = '<img class="Wirisformula" src="https://www.wiris.net/x" data-mathml=\'' . $frac . '\' alt="a">';
        $this->assertSame('<p>\(\frac{1}{2}\)</p>', $converter->convert('<p>' . $img . '</p>')['text']);

        $encoded = '&laquo;math xmlns=&uml;http://www.w3.org/1998/Math/MathML&uml;&raquo;&laquo;mi&raquo;x'
            . '&laquo;/mi&raquo;&laquo;/math&raquo;';
        $this->assertSame('\(x\)', $converter->convert($encoded)['text']);

        $plain = '<math xmlns="http://www.w3.org/1998/Math/MathML"><mi>x</mi><mo>&lt;</mo><mn>1</mn></math>';
        $this->assertSame('\(x&lt;1\)', $converter->convert($plain)['text']);

        $broken = self::safe('«mfrac»«mn»1«/mn»');
        $result = $converter->convert('<p>' . $broken . '</p>');
        $this->assertFalse($result['formulas'][0]['ok']);
        $this->assertSame('<p>' . $broken . '</p>', $result['text']);

        $this->assertSame([], $converter->convert('<p>No formula here</p>')['formulas']);
    }

    /**
     * Course conversion and undo.
     */
    public function test_convert_and_undo(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $frac = self::safe('«mfrac»«mn»1«/mn»«mn»2«/mn»«/mfrac»');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id,
            'content' => '<p>A ' . $frac . '</p>']);

        $manager = new manager();
        $targets = locator::course_targets($course->id);
        $summary = manager::summarise($manager->scan($targets));
        $this->assertSame(1, $summary['formulas']);

        $run = $manager->convert($targets, $course->id, 2);
        $this->assertSame('<p>A \(\frac{1}{2}\)</p>', $DB->get_field('page', 'content', ['id' => $page->id]));
        $this->assertSame([], $manager->scan(locator::course_targets($course->id)));

        $result = $manager->undo($run->id);
        $this->assertSame(1, $result['restored']);
        $this->assertSame('<p>A ' . $frac . '</p>', $DB->get_field('page', 'content', ['id' => $page->id]));
    }
}
