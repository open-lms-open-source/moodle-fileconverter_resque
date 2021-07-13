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

/**
 * Test for the unoconv class.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2017 Open LMS
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use \fileconverter_resque\converter;
use \core_files\conversion;

defined('MOODLE_INTERNAL') || die();

require_once('fixtures/resque_testcase.php');
require_once('fixtures/mock_resque_worker.php');
require_once('fixtures/testable_converter.php');

class fileconverter_resque_unoconv_testcase extends resque_testcase {
    public function test_check_unoconv_path() {
        // Try the existing setting, which is hopefully working.
        $converter = new fileconverter_resque_testable_converter();
        $unoconv = $converter->unoconv;
        $this->assertTrue($unoconv->check_unoconv_path());

        // Try some various things that should for sure not work.
        $converter->set_config('pathtounoconv', null);
        $this->assertFalse($unoconv->check_unoconv_path());

        $converter->set_config('pathtounoconv', '/path/to/not/existant/file');
        $this->assertFalse($unoconv->check_unoconv_path());

        $converter->set_config('pathtounoconv', __DIR__);
        $this->assertFalse($unoconv->check_unoconv_path());

        $converter->set_config('pathtounoconv', __FILE__);
        $this->assertFalse($unoconv->check_unoconv_path());
    }

    public function test_run_unoconv_conversion() {
        set_config('retries', 0, 'fileconverter_resque');

        $path = get_config('fileconverter_resque', 'pathtounoconv');
        unset_config('pathtounoconv', 'fileconverter_resque');

        $converter = new fileconverter_resque_testable_converter();
        $conversion = $this->create_test_conversion();

        $this->assertFalse($converter->run_unoconv_conversion($conversion));

        $this->assertEquals(conversion::STATUS_FAILED, $conversion->get('status'));
        $this->assertEquals('Unoconv not available on this system', $conversion->get('statusmessage'));

        // Put the setting back.
        set_config('pathtounoconv', $path, 'fileconverter_resque');
        $converter->set_config('pathtounoconv', $path);

        // Make sure that if we try to run a completed conversion, nothing happens.
        $conversion = $this->create_test_conversion();
        $conversion->set('status', conversion::STATUS_COMPLETE);

        $this->assertTrue($converter->run_unoconv_conversion($conversion));

        // There shouldn't be a complete file.
        $file = $conversion->get_destfile();
        $this->assertFalse($file);

        // Now we test a source file type that is not supported.
        $conversion = $this->create_test_conversion('rtf', true, 'testformat');
        $this->assertFalse($converter->run_unoconv_conversion($conversion));

        $this->assertEquals(conversion::STATUS_FAILED, $conversion->get('status'));
        $this->assertEquals('File format not supported', $conversion->get('statusmessage'));

        // Now we test a destincation file type that is not supported.
        $conversion = $this->create_test_conversion('testformat');
        $this->assertFalse($converter->run_unoconv_conversion($conversion));

        $this->assertEquals(conversion::STATUS_FAILED, $conversion->get('status'));
        $this->assertEquals('File format not supported', $conversion->get('statusmessage'));

        // Now start a normal conversion.
        $conversion = $this->create_test_conversion();
        $this->assertTrue($converter->run_unoconv_conversion($conversion));

        $this->assertEquals(conversion::STATUS_COMPLETE, $conversion->get('status'));
    }

    public function test_update_supported_formats() {
        $this->assertFalse(get_config('fileconverter_resque', 'fileformats'));

        $converter = new fileconverter_resque_testable_converter();
        $unoconv = $converter->unoconv;

        $unoconv->update_supported_formats();

        $formatconfig = get_config('fileconverter_resque', 'fileformats');
        $this->assertNotEmpty($formatconfig);

        // Decode the stored value.
        $formats = json_decode($formatconfig);
        $formatcount = count($formats);
        $this->assertIsArray($formats);

        // Now make sure it gets overwritten.
        set_config('fileformats', json_encode(['a']), 'fileconverter_resque');
        $unoconv->update_supported_formats();

        // Make sure the count it updated.
        $formatconfig = get_config('fileconverter_resque', 'fileformats');
        $this->assertNotEmpty($formatconfig);
        $formats = json_decode($formatconfig);
        $this->assertCount($formatcount, $formats);
    }

    public function test_get_supported_formats() {
        $converter = new fileconverter_resque_testable_converter();

        // First, get the default set.
        $formatsdefault = $converter->unoconv->get_supported_formats();

        $this->assertNotEmpty($formatsdefault);
        // Test some things we know should be in the default set.
        $this->assertContains('xml', $formatsdefault);
        $this->assertContains('doc', $formatsdefault);
        $this->assertContains('jpg', $formatsdefault);

        // Now a little trick to make sure we get the cached one if we ask again.
        $converter->set_config('fileformats', json_encode(['a', 'b', 'c']));
        $formats = $converter->unoconv->get_supported_formats();

        $this->assertSame($formatsdefault, $formats);
        $this->assertContains('xml', $formats);
        $this->assertContains('doc', $formats);
        $this->assertContains('jpg', $formats);
        $this->assertNotContains('a', $formats);
        $this->assertNotContains('b', $formats);
        $this->assertNotContains('c', $formats);

        // Now we need a new converter. Going to make sure we can get it from settings as well.
        set_config('fileformats', json_encode(['a', 'b', 'c']), 'fileconverter_resque');
        $converter = new fileconverter_resque_testable_converter();
        $formats = $converter->unoconv->get_supported_formats();
        $this->assertCount(3, $formats);
        $this->assertContains('a', $formats);
        $this->assertContains('b', $formats);
        $this->assertContains('c', $formats);

        // And now going the same thing with some bad JSONs. Should give default values.
        set_config('fileformats', json_encode([]), 'fileconverter_resque');
        $converter = new fileconverter_resque_testable_converter();
        $formats = $converter->unoconv->get_supported_formats();
        $this->assertSame($formatsdefault, $formats);

        set_config('fileformats', '[}', 'fileconverter_resque');
        $converter = new fileconverter_resque_testable_converter();
        $formats = $converter->unoconv->get_supported_formats();
        $this->assertSame($formatsdefault, $formats);

        set_config('fileformats', null, 'fileconverter_resque');
        $converter = new fileconverter_resque_testable_converter();
        $formats = $converter->unoconv->get_supported_formats();
        $this->assertSame($formatsdefault, $formats);
    }

}

