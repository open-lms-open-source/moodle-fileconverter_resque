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
 * Test for the converter class.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use \fileconverter_resque\converter;
use \core_files\conversion;

defined('MOODLE_INTERNAL') || die();

require_once('fixtures/resque_testcase.php');
require_once('fixtures/mock_resque_worker.php');
require_once('fixtures/testable_converter.php');

class fileconverter_resque_converter_testcase extends resque_testcase {


    public function test_start_document_conversion() {
        $converter = new fileconverter_resque_testable_converter();
        $conversion = $this->create_test_conversion('rtf', false);

        // Now attempt to submit the conversion.
        $converter->start_document_conversion($conversion);

        // Check the counts of the queues.
        $this->assertEquals(1, $this->worker->get_queue_length('unoconv'));
        $this->assertEquals(0, $this->worker->get_queue_length('unoconv_batch'));

        $data = $conversion->get('data');
        $this->assertInstanceOf('stdClass', $data);
        $this->assertNotEmpty($data);

        $this->assertNotEmpty($conversion->get('id'));

        $this->assertEquals(0, $data->attempt);
        $this->assertNotEmpty($data->statustime);
        $this->assertEquals(converter::STATUS_WAITING, $data->status);
        $this->assertEquals(1, $data->priority);
        $this->assertEquals('unoconv', $data->queue);

        // Submit it again.
        $converter->start_document_conversion($conversion);

        // There should now be 2 jobs enqueued.
        $this->assertEquals(2, $this->worker->get_queue_length('unoconv'));
        $this->assertEquals(0, $this->worker->get_queue_length('unoconv_batch'));

        // Make sure the attempt count went up one.
        $data = $conversion->get('data');
        $this->assertEquals(1, $data->attempt);

        // Now again with another file.
        $conversion = $this->create_test_conversion();

        // Set us to low priority mode.
        $converter->ishighpriority = false;

        $converter->start_document_conversion($conversion);

        // Check the counts of the queues.
        $this->assertEquals(2, $this->worker->get_queue_length('unoconv'));
        $this->assertEquals(1, $this->worker->get_queue_length('unoconv_batch'));

        $data = $conversion->get('data');
        $this->assertEquals(0, $data->priority);
        $this->assertEquals('unoconv_batch', $data->queue);
    }

    public function test_poll_conversion_status() {
        $converter = new fileconverter_resque_testable_converter();
        $converter->ishighpriority = true;
        $conversion = $this->create_test_conversion();
        $converter->start_document_conversion($conversion);

        $data = $conversion->get('data');
        $this->assertEquals(0, $data->attempt);

        // Make sure the queues are correct, we will be using them.
        $this->assertEquals(1, $this->worker->get_queue_length('unoconv'));
        $this->assertEquals(0, $this->worker->get_queue_length('unoconv_batch'));

        // First, we want to cause a timeout to force a resubmission.
        $converter->set_config('retries', 1);

        $timeout = $converter->get_config('highwaittimeout') + 10;
        $data->statustime -= $timeout;
        $conversion->set('data', $data);

        // Submit with the timeout, and it should cause a resubmission to trigger.
        $converter->poll_conversion_status($conversion);

        $this->assertEquals(conversion::STATUS_IN_PROGRESS, $conversion->get('status'));
        $this->assertEmpty($conversion->get('statusmessage'));
        $data = $conversion->get('data');
        $this->assertEquals(1, $data->attempt);
        // Make sure the status time got reset to nowish.
        $this->assertGreaterThan(time() - 5, $data->statustime);

        $this->assertEquals(2, $this->worker->get_queue_length('unoconv'));
        $this->assertEquals(0, $this->worker->get_queue_length('unoconv_batch'));

        // Try again, but now we should it the reattempt limit.
        $data->statustime -= $timeout;
        $conversion->set('data', $data);
        $converter->poll_conversion_status($conversion);

        $this->assertEquals(conversion::STATUS_FAILED, $conversion->get('status'));
        $this->assertNotEmpty($conversion->get('statusmessage'));
        $data = $conversion->get('data');
        $this->assertEquals(1, $data->attempt);

        $this->assertEquals(2, $this->worker->get_queue_length('unoconv'));
        $this->assertEquals(0, $this->worker->get_queue_length('unoconv_batch'));

        $this->worker->clear_jobs();

        // Now we are going to move onto queue bump up testing.
        // The thought here is that a conversion request may be made by cron (low priority) and then
        // the user resquests it, we place it in the high priority queue as well.
        $converter->ishighpriority = false;
        $conversion = $this->create_test_conversion();
        $converter->start_document_conversion($conversion);

        $this->assertEquals(0, $this->worker->get_queue_length('unoconv'));
        $this->assertEquals(1, $this->worker->get_queue_length('unoconv_batch'));

        // Just make sure nothing happens when we do this.
        $converter->poll_conversion_status($conversion);

        $this->assertEquals(0, $this->worker->get_queue_length('unoconv'));
        $this->assertEquals(1, $this->worker->get_queue_length('unoconv_batch'));
        $data = $conversion->get('data');
        $this->assertEquals(0, $data->attempt);
        $this->assertEquals(0, $data->priority);

        // Now change priority and try again.
        $converter->ishighpriority = true;
        $converter->poll_conversion_status($conversion);

        $this->assertEquals(1, $this->worker->get_queue_length('unoconv'));
        $this->assertEquals(1, $this->worker->get_queue_length('unoconv_batch'));
        $data = $conversion->get('data');
        $this->assertEquals(0, $data->attempt);
        $this->assertEquals(1, $data->priority);

        // Just that no errors happen in this state.
        unset($data->priority);
        unset($data->attempt);
        unset($data->status);
        $conversion->set('data', $data);

        $converter->poll_conversion_status($conversion);
    }

    public function test_check_conversion_is_timed_out() {
        $converter = new fileconverter_resque_testable_converter();
        $converter->ishighpriority = true;
        $conversion = $this->create_test_conversion();
        $converter->start_document_conversion($conversion);

        $data = $conversion->get('data');

        // Mark it as started first.
        $data->status = fileconverter_resque_testable_converter::STATUS_STARTED;
        $conversion->set('data', $data);

        // First, check to make sure it passes as expected when no time has passed.
        $this->assertFalse($converter->check_conversion_is_timed_out($conversion));

        // Now, we are going to update the status time so it should be too far in the past.
        $timeout = $converter->get_config('unoconvtimeout') + 60;
        $data->statustime -= $timeout;
        $conversion->set('data', $data);

        $this->assertTrue($converter->check_conversion_is_timed_out($conversion));

        // Now we are going to reset a few things in data.
        $data->status = fileconverter_resque_testable_converter::STATUS_WAITING;
        $data->statustime += $timeout;
        $conversion->set('data', $data);

        // Now since this is a high priority, we are going to check that timeout state.
        $this->assertFalse($converter->check_conversion_is_timed_out($conversion));

        $timeout = $converter->get_config('highwaittimeout') + 10;
        $data->statustime -= $timeout;
        $conversion->set('data', $data);

        $this->assertTrue($converter->check_conversion_is_timed_out($conversion));

        // Now convert it to a low priority conversion.
        $data->priority = 0;
        $conversion->set('data', $data);
        // It shouldn't timeout anymore.
        $this->assertFalse($converter->check_conversion_is_timed_out($conversion));

        // But now we are going to move the time again.
        $data->statustime += $timeout;
        $timeout = $converter->get_config('batchwaittimeout') + 10;
        $data->statustime -= $timeout;
        $conversion->set('data', $data);

        $this->assertTrue($converter->check_conversion_is_timed_out($conversion));

        // Just that no errors happen in this state.
        unset($data->status);
        $conversion->set('data', $data);
        $this->assertFalse($converter->check_conversion_is_timed_out($conversion));
    }

    public function test_are_requirements_met() {
        $this->assertTrue(converter::are_requirements_met());

        $path = get_config('fileconverter_resque', 'pathtounoconv');
        unset_config('pathtounoconv', 'fileconverter_resque');

        $this->assertFalse(converter::are_requirements_met());

        set_config('pathtounoconv', $path, 'fileconverter_resque');
        unset_config('resqueserver', 'fileconverter_resque');

        $this->assertFalse(converter::are_requirements_met());
    }

    public function test_supports() {
        set_config('fileformats', json_encode(['a', 'b', 'c']), 'fileconverter_resque');

        $this->assertTrue(converter::supports('a', 'b'));
        $this->assertTrue(converter::supports('a', 'a'));
        $this->assertTrue(converter::supports('a', 'c'));
        $this->assertTrue(converter::supports('b', 'a'));
        $this->assertTrue(converter::supports('b', 'c'));

        $this->assertFalse(converter::supports('a', 'd'));
        $this->assertFalse(converter::supports('d', 'd'));
        $this->assertFalse(converter::supports('d', 'a'));
        $this->assertFalse(converter::supports('b', 'd'));
        $this->assertFalse(converter::supports('d', 'e'));

        // Make sure capitals work as expected.
        $this->assertTrue(converter::supports('A', 'a'));
        $this->assertTrue(converter::supports('a', 'C'));
        $this->assertTrue(converter::supports('B', 'a'));
        $this->assertTrue(converter::supports('B', 'C'));
    }

    public function test_get_supported_conversions() {
        set_config('fileformats', json_encode(['a', 'b', 'c']), 'fileconverter_resque');

        $converter = new fileconverter_resque_testable_converter();
        $supported = $converter->get_supported_conversions();
        $this->assertInternalType('string', $supported);
        $this->assertEquals('a, b, c', $supported);
    }

    public function test_get_message_arguments() {
        global $CFG;

        $converter = new fileconverter_resque_testable_converter();
        $conversion = $this->create_test_conversion();

        $converter->set_config('messagepath', converter::PATH_RELATIVE);
        $results = $converter->get_message_arguments($conversion);

        $this->assertEquals('/files/converter/resque/cli/run_conversion.php', $results['path']);
        $this->assertEquals($conversion->get('id'), $results['id']);
        $this->assertEquals('undefined', $results['site'][0]);

        $converter->set_config('messagepath', converter::PATH_FULL);
        $converter->set_config('sitename', 'testsite');
        $results = $converter->get_message_arguments($conversion);

        $this->assertEquals(false, $results['verbose']);
        $this->assertEquals($CFG->dirroot.'/files/converter/resque/cli/run_conversion.php', $results['path']);
        $this->assertEquals('testsite', $results['site'][0]);

        $converter->set_config('verbose', true);
        $results = $converter->get_message_arguments($conversion);
        $this->assertEquals(true, $results['verbose']);
    }
}

