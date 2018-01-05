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
 * Converter class.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace fileconverter_resque;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/filelib.php');

use \core_files\conversion;

/**
 * Class for converting files between different formats using unoconv over resque.
 *
 * @package    fileconverter_resque
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class converter implements \core_files\converter_interface {
    /**
     * Indicates to use the full path for message paths.
     */
    const PATH_FULL = 1;

    /**
     * Indicates to just use the Moodle relative path.
     */
    const PATH_RELATIVE = 2;

    /** @var Object with the plugin config. */
    protected $config = null;


    /** @var string Debug prefix used for messages. */
    protected $debugprefix = null;

    protected $unoconv = null;

    /**
     * Internal status meaning that the conversion has been enqueued, but not picked up by a worker.
     */
    const STATUS_WAITING = 0;

    /**
     * Internal status meaning that a worker has started working on this job.
     */
    const STATUS_STARTED = 1;

    public function __construct() {
        require_once(__DIR__.'/../vendor/autoload.php');

        $this->config = get_config('fileconverter_resque');

        if (!empty($this->config->resqueserver)) {
            $db = $this->config->resquedb;
            if (empty($db) || !is_numeric($db)) {
                $db = 0;
            } else {
                $db = (int)$db;
            }

            \Resque::setBackend($this->config->resqueserver, $db);
        }

        $this->unoconv = new unoconv($this->config, $this);
    }

    public static function are_requirements_met() {
        // Servers other than the host may not have unoconv installed, so we can't check if it works or not here.
        // Just see if it is set.
        if (empty(get_config('fileconverter_resque', 'pathtounoconv'))) {
            return false;
        }

        if (empty(get_config('fileconverter_resque', 'resqueserver'))) {
            return false;
        }

        return true;
    }

    /**
     * Allows the path to unoconv to be overridden for this specific instance of the converter.
     * May be helpful for complicated sites with multiple workers.
     *
     * @param string $path
     */
    public function set_temp_path_to_unoconv($path) {
        $this->config->pathtounoconv = $path;
    }

    /**
     * This function "starts" the document conversion process by adding it to the Resque queue appropriate for this task.
     *
     * @param   conversion $conversion The file to be converted
     * @return  $this
     */
    public function start_document_conversion(conversion $conversion) {
        // We save because we need a DB id before we can proceed.
        if (empty($conversion->get('id'))) {
            $conversion->save();
        }

        // Setup a set of arguments.
        $args = $this->get_message_arguments($conversion);

        // Check the priority and send to the appropriate queue.
        $priority = $this->is_high_priority();
        if ($priority) {
            $queue = $this->config->queuehigh;
        } else {
            $queue = $this->config->queuebatch;
        }

        $jobclass = $this->config->resquejobclass;

        // Add a conversion request into resque.
        $queueid = \Resque::enqueue($queue, $jobclass, $args);

        // Get or update data.
        $data = $conversion->get('data');
        $data->statustime = time();
        $data->queue = $queue;
        $data->priority = $priority ? 1 : 0;
        $data->status = self::STATUS_WAITING;

        // Set the attempt number.
        if (!isset($data->attempt)) {
            $data->attempt = 0;
        } else {
            $data->attempt++;
        }
        $conversion->set('data', $data);

        $conversion->set('status', conversion::STATUS_IN_PROGRESS);
        $conversion->update();

        return $this;
    }

    /**
     * Returns an array of arguments to use as a payload for the Resque message.
     *
     * @param conversion $conversion The conversion object to work with.
     * @return array
     */
    protected function get_message_arguments(conversion $conversion) {
        global $CFG;

        $path = '/files/converter/resque/cli/run_conversion.php';
        if (!empty($this->config->messagepath) && $this->config->messagepath == self::PATH_FULL) {
            $path = $CFG->dirroot.$path;
        }

        // Provides some additional information for large systems.
        $sitename = empty($this->config->sitename) ? 'undefined' : $this->config->sitename;
        $args = array('site' => array($sitename),
                      'name' => $sitename.'_unoconv',
                      'path' => $path,
                      'id'   => $conversion->get('id')
                      );

        return $args;
    }

    /**
     * Returned true if the current processes should be considered high priority, false if not.
     *
     * @return bool
     */
    protected function is_high_priority() {
        // If this is executed from a command line script, that should mean that nobody is actively waiting for it.
        if (CLI_SCRIPT) {
            return false;
        }

        return true;
    }

    public function poll_conversion_status(conversion $conversion) {
        // We can't/don't want to get status information from resque due to performance considerations.
        // So instead we infer status from the information we have.
        $data = $conversion->get('data');

        if (!isset($data->priority) || !isset($data->attempt) || !isset($data->status)) {
            $this->debug_info("Conversion data missing required values");
            if (!isset($data->priority)) {
                $data->priority = 0;
            }
            if (!isset($data->attempt)) {
                $data->attempt = 0;
            }
            if (!isset($data->status)) {
                $data->status = self::STATUS_WAITING;
            }
        }

        if ($conversion->get('status') == conversion::STATUS_IN_PROGRESS) {
            // If the docuemnt was low priority, but now it is high, and it is still waiting to be picked up by a worker,
            // we resubmit it to the high priority queue. Whichever queue gets to it first will process it.
            if (empty($data->priority) && $this->is_high_priority() && $data->status == self::STATUS_WAITING) {
                // We subtract one from the attempt count, because we aren't going to consider this a new attempt.
                $data->attempt--;
                $conversion->set('data', $data);
                $conversion->update();
                $this->start_document_conversion($conversion);
                return $this;
            }
        }

        if ($this->check_conversion_is_timed_out($conversion)) {
            // If it is timed out, we will either resubmit or fail the conversion, depending on if more retries are allowed.
            $this->fail_or_resubmit($conversion, 'Conversion timed out');
        }

        return $this;
    }

    /**
     * Check if a conversion should be timed out.
     *
     * @param conversion $conversion The conversion to check
     * @return bool True if timed out
     */
    public function check_conversion_is_timed_out(conversion $conversion) {
        $data = $conversion->get('data');

        if (!isset($data->priority) || !isset($data->status) || !isset($data->statustime)) {
            $this->debug_info("Conversion data missing required values");

            return false;
        }

        if ($data->status == self::STATUS_STARTED) {
            // We add additional timeout, because we allow some additional time for attempted kills.
            $timeout = (int)$this->config->unoconvtimeout + 40;
            $message = 'Timeout during unoconv conversion.';
        } else {
            if (empty($data->priority)) {
                // Low priority (batch) timeout.
                $timeout = (int)$this->config->batchwaittimeout;
                $message = 'Timeout while waiting for conversion in batch queue.';
            } else {
                // High priority (on demand) timeout.
                $timeout = (int)$this->config->highwaittimeout;
                $message = 'Timeout while waiting for conversion in high priority queue.';
            }
        }

        $diff = time() - $data->statustime;
        if ($diff > $timeout) {
            return true;
        }

        return false;
    }

    public static function supports($from, $to) {
        $converter = new self();
        $formats = $converter->unoconv->get_supported_formats();

        $from = strtolower($from);
        $to = strtolower($to);

        // It's assumed that unoconv supports all to->from combos for supported file extensions.
        if (in_array($from, $formats) && in_array($to, $formats)) {
            return true;
        }

        return false;
    }

    public function get_supported_conversions() {
        return implode(', ', $this->unoconv->get_supported_formats());
    }

    /**
     * Takes a conversion file and either resubmits it for conversion, or marks it as failed.
     *
     * @param conversion $conversion
     * @param string     $failmessage The message to set on the conversion when failed.
     * @param bool       $forcefail   Fail the conversion, even if there are more attempts allowed.
     */
    public function fail_or_resubmit(conversion $conversion, $failmessage = '', $forcefail = false) {
        $data = $conversion->get('data');

        $this->debug_info('Failure: '.$failmessage);

        if (!$forcefail && isset($data->attempt) && $data->attempt < $this->config->retries) {
            $this->start_document_conversion($conversion);
            $this->debug_info('Resubmitting');
        } else {
            $conversion->set('status', conversion::STATUS_FAILED);
            $conversion->set('statusmessage', $failmessage);
            $conversion->update();
        }
    }

    /**
     * We only expect this to be called from a "worker" that has unoconv installed, otherwise the conversion will fail.
     *
     * When we get to this point, the conversion will have been removed from the queue by the worker, meaning that we must
     * requeue it if we want it to be attempted again.
     *
     * @param conversion $conversion
     * @return bool True if successful, false if not.
     */
    public function run_unoconv_conversion(conversion $conversion) {
        return $this->unoconv->run_unoconv_conversion($conversion);
    }

    /**
     * Call to turn on verbose output.
     *
     * @param string $prefix A prefix to prepend to each line
     */
    public function set_verbose($prefix = '') {
        $this->debugprefix = $prefix;
    }

    /**
     * Output a debug message if enabled.
     *
     * @param string $message The message to output
     */
    public function debug_info($message) {
        if (is_null($this->debugprefix)) {
            return;
        }

        if (!empty($this->debugprefix)) {
            $message = date('c').' '.$this->debugprefix.': '.$message;
        } else {
            $message = date('c').': '.$message;
        }

        mtrace($message);
    }
}
