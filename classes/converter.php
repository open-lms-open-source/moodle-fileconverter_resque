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

require_once($CFG->libdir . '/filelib.php');

use stored_file;
use \core_files\conversion;

require_once(__DIR__.'/../vendor/autoload.php');

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

    /** @var Array of supported formats. */
    protected $formats = null;

    /** @var Debug prefix used for messages. */
    protected $debugprefix = null;

    /**
     * Internal status meaning that the conversion has been enqueued, but not picked up by a worker.
     */
    const STATUS_WAITING = 0;

    /**
     * Internal status meaning that a worker has started working on this job.
     */
    const STATUS_STARTED = 1;

    public function __construct() {
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
        $data->priority = (int)$priority;
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
            debugging("Conversion data missing required values");
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
                // We subtract one from the attempt acount, ebcause we aren't going to consider this a new attempt.
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
            debugging("Conversion data missing required values");

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
        $formats = $converter->get_supported_formats();

        // It's assumed that unoconv supports all to->from combos for supported file extensions.
        if (in_array($from, $formats) && in_array($to, $formats)) {
            return true;
        }

        return false;
    }

    public function get_supported_conversions() {
        return implode(', ', $this->get_supported_formats());
    }

    /**
     * Takes a conversion file and either resubmits it for conversion, or marks it as failed.
     *
     * @param conversion $conversion
     * @param string     $failmessage The message to set on the conversion when failed.
     */
    protected function fail_or_resubmit(conversion $conversion, $failmessage = '') {
        $data = $conversion->get('data');

        $this->debug_info('Failure: '.$failmessage);

        if (isset($data->attempt) && $data->attempt < $this->config->retries) {
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
        // Doing a blanket catch to try and prevent any lost conversions due to unknown exceptions.
        try {
            if (!self::are_requirements_met() || !$this->check_unoconv_path()) {
                // Unoconv is bad, fail or resubmit as needed.
                $this->fail_or_resubmit($conversion, 'Unoconv not available on this system');
                return false;
            }

            // When we are on an unoconv worker, and we don't have a valid extension list, or randomly, we want to update it.
            if (empty($this->config->fileformats) || (mt_rand(1, 20) === 1)) {
                // Save any changes to the record.
                $conversion->update();

                $this->update_supported_formats();

                // If we did that, it may have taken some time, so we should re-fetch the conversion record.
                try {
                    $conversion->read();
                } catch (\dml_missing_record_exception $e) {
                    $this->debug_info('Conversion record disappeared while we were preparing.');
                    return false;
                }
            }

            if ($conversion->get('status') === conversion::STATUS_COMPLETE) {
                // The conversion is aready complete.
                $this->debug_info('Conversion already complete');
                return true;
            }

            $file = $conversion->get_sourcefile();

            // Sanity check that the conversion is supported.
            $fromformat = pathinfo($file->get_filename(), PATHINFO_EXTENSION);
            $format = $conversion->get('targetformat');
            if (!self::supports($fromformat, $format)) {
                $this->fail_or_resubmit($conversion, 'File format not supported');
                return false;
            }

            // We want to set the internal 'started' state ASAP, as it works an a marker to let other attempts know it is running.
            $data = $conversion->get('data');
            $data->status = self::STATUS_STARTED;
            $data->statustime = time();
            $conversion->set('data', $data);
            $conversion->update();

            // Copy the file to the tmp dir.
            $uniqdir = make_unique_writable_directory(make_temp_directory('core_file/conversions'));
            \core_shutdown_manager::register_function('remove_dir', array($uniqdir));
            $localfilename = $file->get_id() . '.' . $fromformat;

            $filename = $uniqdir . '/' . $localfilename;

            try {
                // This function can either return false, or throw an exception so we need to handle both.
                if ($file->copy_content_to($filename) === false) {
                    $this->fail_or_resubmit($conversion, 'Could not copy file contents to temp file.');
                    return false;
                }
            } catch (\file_exception $fe) {
                $this->fail_or_resubmit($conversion, 'Could not copy file contents to temp file.');
                return false;
            }

            // The temporary file to copy into.
            $newtmpfile = pathinfo($filename, PATHINFO_FILENAME) . '.' . $format;
            $newtmpfile = $uniqdir . '/' . clean_param($newtmpfile, PARAM_FILE);

            // We add exec because it corrects for PHP bug https://bugs.php.net/bug.php?id=39992.
            $cmd = escapeshellcmd('exec') . ' ' .
                   escapeshellcmd(trim($this->config->pathtounoconv)) . ' ' .
                   escapeshellarg('-T') . ' ' .
                   escapeshellarg(15) . ' ' .
                   escapeshellarg('-f') . ' ' .
                   escapeshellarg($format) . ' ' .
                   escapeshellarg('-o') . ' ' .
                   escapeshellarg($newtmpfile) . ' ' .
                   escapeshellarg($filename);

            $output = null;
            $currentdir = getcwd();
            chdir($uniqdir);

            // Get the timeout.
            $timeout = (int)$this->config->unoconvtimeout;

            $this->debug_info('Starting unoconv');

            // Dispatch the command with a timeout.
            list($exitcode, $stdout, $stderr) = $this->run_command_timeout($cmd, $timeout);

            if ($exitcode == -1) {
                // Seems that the conversion didn't go too well.
                $this->fail_or_resubmit($conversion, 'Unoconv exited with code -1.');
                return false;
            }

            chdir($currentdir);
            touch($newtmpfile);

            if (filesize($newtmpfile) === 0) {
                $this->fail_or_resubmit($conversion, 'Unoconv result was empty');
                return false;
            }

            // This may have taken a while, so we re-fetch the record to make sure nothing has changed.
            try {
                $conversion->read();
            } catch (\dml_missing_record_exception $e) {
                $this->debug_info('Conversion record disappeared while we were converting.');
                return false;
            }

            if ($conversion->get('status') === conversion::STATUS_COMPLETE) {
                // The record is missing, or was already completed. So we should just end now.
                $this->debug_info('Conversion was completed elsewhere while running');
                return true;
            }

            // If we got this far, then consider it a success.
            $conversion->store_destfile_from_path($newtmpfile);
            $conversion->set('status', conversion::STATUS_COMPLETE);
            $conversion->update();

            return true;
        } catch (\Exception $e) {
            $this->fail_or_resubmit($conversion, "Unhandled exception during conversion: ".$e);
            return false;
        }
    }

    /**
     * Runs a specified shell command, while attempting to enforce a timeout.
     *
     * @param string $cmd  Fully escaped command string, with arguments.
     * @param int $timeout Number of seconds to allow the script to run before attempting to kill it.
     * @return array() Consists of 3 components in this order: exitcode, stdout, stderr.
     * @throws moodle_exception
     */
    protected function run_command_timeout($cmd, $timeout) {
        // Setup the command.
        $pipesspec = array(1 => array('pipe', 'w'),
                           2 => array('pipe', 'w'));
        $pipes = array();
        $proc = proc_open($cmd, $pipesspec, $pipes);
        $starttime = microtime(true);

        // Now we are going to check periodically to see if the process has finished.
        $termed = false;
        $killed = false;
        $status = false;
        do {
            // Sleep for 100ms.
            usleep(100000);

            $status = proc_get_status($proc);

            // If the process is no longer running, we can continue on.
            if ($status['running'] === false) {
                break;
            }

            // Now for time based checks.
            $runtime = microtime(true) - $starttime;
            if (!$termed && ($runtime > $timeout)) {
                // First do a soft kill attempt.
                $this->debug_info('Issuing soft kill of unoconv');
                $termed = true;
                proc_terminate($proc);
            }
            if (!$killed && ($runtime > ($timeout + 15))) {
                // Now we are going to try and hard kill.
                $this->debug_info('Issuing hard kill of unoconv');
                $killed = true;
                proc_terminate($proc, 9);
            }
            if ($runtime > ($timeout + 30)) {
                // If we've gotten this far, we don't know how to kill the child process. Throw hands in the air.
                $this->debug_info('Unable to kill unoconv. Throwing exception.');
                throw new \moodle_exception('Could not kill conversion with PID '.$status['pid']);
            }
        } while (true);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitcode = $status['exitcode'];

        $this->debug_info('unoconv stdout: '.$stdout);
        $this->debug_info('unoconv stderr: '.$stderr);
        $this->debug_info('unoconv exitcode: '.$exitcode);

        // Make sure we close all pipes before the process, or a hang may occur.
        array_map('fclose', $pipes);
        proc_close($proc);

        return array($exitcode, $stdout, $stderr);

    }

    /**
     * Whether unoconv is fully configured and usable.
     *
     * @return  bool
     */
    protected function check_unoconv_path() {
        global $CFG;

        $unoconvpath = $this->config->pathtounoconv;

        if (empty($unoconvpath)) {
            return false;
        }
        if (!file_exists($unoconvpath)) {
            return false;
        }
        if (is_dir($unoconvpath)) {
            return false;
        }
        if (!\file_is_executable($unoconvpath)) {
            return false;
        }
        if (!$this->is_minimum_version_met()) {
            return false;
        }

        return true;
    }

    /**
     * Whether the minimum version of unoconv has been met.
     *
     * @return bool
     */
    protected function is_minimum_version_met() {
        $currentversion = 0;
        $supportedversion = 0.7;
        $unoconvbin = \escapeshellarg($this->config->pathtounoconv);
        $command = "$unoconvbin --version";
        exec($command, $output);

        // If the command execution returned some output, then get the unoconv version.
        if ($output) {
            foreach ($output as $response) {
                if (preg_match('/unoconv (\\d+\\.\\d+)/', $response, $matches)) {
                    $currentversion = (float) $matches[1];
                }
            }
            if ($currentversion < $supportedversion) {
                return false;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Function that updates the supported format list from unoconv.
     */
    protected function update_supported_formats() {
        // Ask unoconv for it's list of supported document formats.
        $cmd = escapeshellcmd(trim($this->config->pathtounoconv)) . ' --show';
        $pipes = array();
        $pipesspec = array(2 => array('pipe', 'w'));
        $proc = proc_open($cmd, $pipesspec, $pipes);
        $programoutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);

        // Now split it into an array of extensions.
        $matches = array();
        preg_match_all('/\[\.(.*)\]/', $programoutput, $matches);

        $formats = $matches[1];
        $formats = array_values(array_unique($formats));

        if (empty($formats)) {
            return;
        }

        $this->formats = $formats;
        set_config('fileformats', json_encode($formats), 'fileconverter_resque');
    }

    /**
     * Returns an array of support file extensions.
     * It is assumed that that any format can convert to any other format in the list.
     *
     * @return array
     */
    protected function get_supported_formats() {
        if (!is_null($this->formats)) {
            return $this->formats;
        }

        $formats = [];

        if (!empty($this->config->fileformats)) {
            $formats = json_decode($this->config->fileformats);
        }

        if (empty($formats)) {
            // This is an array of standard formats the unoconv supports, if we haven't fetched it yet.
            $formats = array('bib', 'doc', 'xml', 'docx', 'fodt', 'html', 'ltx', 'txt', 'odt', 'ott',
                             'pdb', 'pdf', 'psw', 'rtf', 'sdw', 'stw', 'sxw', 'uot', 'vor', 'wps', 'bmp',
                             'emf', 'eps', 'fodg', 'gif', 'jpg', 'met', 'odd', 'otg', 'pbm', 'pct',
                             'pgm', 'png', 'ppm', 'ras', 'std', 'svg', 'svm', 'swf', 'sxd', 'tiff', 'wmf',
                             'xhtml', 'xpm', 'fodp', 'odg', 'odp', 'otp', 'potm', 'pot', 'pptx', 'pps',
                             'ppt', 'pwp', 'sda', 'sdd', 'sti', 'sxi', 'uop', 'csv', 'dbf', 'dif', 'fods',
                             'ods', 'ots', 'pxl', 'sdc', 'slk', 'stc', 'sxc', 'uos', 'xls', 'xlt', 'xlsx');
        }

        $this->formats = $formats;

        return $formats;
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
