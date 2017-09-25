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
 * Test helper that does the job of a Resque worker.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// use \local_file_convert\manager;
use \core_files\conversion;
use \fileconverter_resque\converter;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../../vendor/autoload.php');

/**
 * Do stuff a Resque worker would.
 *
 * @package    fileconverter_resque
 * @copyright  Copyright (c) 2017 Blackboard Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fileconverter_resque_mock_resque_worker {
    /**
     * @var Holds the worker for unoconv.
     */
    static protected $mainworker = null;

    /**
     * @var Holds the worker for unoconv_batch.
     */
    static protected $batchworker = null;

    /**
     * Setup this worker.
     */
    public function __construct() {
        if (is_null(self::$mainworker)) {
            self::$mainworker = new Resque_Worker(array('unoconv'));
            self::$batchworker = new Resque_Worker(array('unoconv_batch'));
        }
    }

    /**
     * Removes all jobs from the queues.
     *
     * @return int Count of jobs removed
     */
    public function clear_jobs() {
        $count = Resque::dequeue('unoconv');
        $count += Resque::dequeue('unoconv_batch');

        return $count;
    }

    /**
     * Returns the lenght of the passed queue.
     *
     * @param string $queue The queue name.
     * @return
     */
    public function get_queue_length($queue) {
        return Resque::size($queue);
    }
}

