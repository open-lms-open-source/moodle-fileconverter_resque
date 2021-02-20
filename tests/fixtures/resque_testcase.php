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
 * A base testcase.
 *
 * @package   local_file_convert
 * @copyright Copyright (c) 2016 Blackboard Inc.
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * A base testcase.
 *
 * @package    local_file_convert
 * @copyright  Copyright (c) 2016 Blackboard Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class resque_testcase extends advanced_testcase {
    protected $worker = null;

    protected $testfilecounter = 0;

    protected $generator = null;

    public function setUp(): void {
        global $CFG;

        if (empty($CFG->phpunit_fileconverter_resque_server)) {
            if (empty($CFG->phpunit_local_file_convert_resque_server)) {
                $this->markTestSkipped('Resque conversion test skipped. '.'
                        $CFG->phpunit_fileconverter_resque_server must be set.');
            }

            $CFG->phpunit_fileconverter_resque_server = $CFG->phpunit_local_file_convert_resque_server;
        }

        if (isset($CFG->phpunit_fileconverter_resque_unoconv_path)) {
            set_config('pathtounoconv', $CFG->phpunit_fileconverter_resque_unoconv_path, 'fileconverter_resque');
        }

        $path = get_config('fileconverter_resque', 'pathtounoconv');
        if (empty($path) || !file_exists($path) || !\file_is_executable($path)) {
            $this->markTestSkipped('Resque conversion test skipped. Path to unoconv is not valid. '.
                    'Update $CFG->phpunit_fileconverter_resque_unoconv_path.');
        }

        set_config('resqueserver', $CFG->phpunit_fileconverter_resque_server, 'fileconverter_resque');
        set_config('queuebatch', 'unoconv_batch', 'fileconverter_resque');
        set_config('queuehigh', 'unoconv', 'fileconverter_resque');

        if (is_null($this->worker)) {
            $this->worker = new fileconverter_resque_mock_resque_worker();
        }

        $this->worker->clear_jobs();

        $this->resetAfterTest();

        $this->generator = self::getDataGenerator()->get_plugin_generator('core_search');

        parent::setUp();
    }

    protected function create_test_conversion($target = 'rtf', $save = true, $source = 'txt') {
        $counter = $this->testfilecounter;
        $options = new stdClass();
        $options->content = "File contents ".$counter;
        $options->filename = "testfile{$counter}.{$source}";
        $storedfile = $this->generator->create_file($options);
        $conversion = new \core_files\conversion(0, (object) [
            'targetformat' => $target,
        ]);
        $conversion->set_sourcefile($storedfile);
        if ($save) {
            $conversion->create();
        }

        $this->testfilecounter++;

        return $conversion;
    }
}

