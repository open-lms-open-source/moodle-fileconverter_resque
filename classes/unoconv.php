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
 * Unoconv class.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2017 Open LMS
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace fileconverter_resque;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/filelib.php');

use \core_files\conversion;

/**
 * Class for interfacing with unoconv.
 *
 * @package    fileconverter_resque
 * @copyright Copyright (c) 2017 Open LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unoconv {
    /** @var Object with the plugin config. */
    protected $config = null;

    /** @var converter Reference to the parent converter. */
    protected $converter = null;

    /** @var array of supported formats. */
    protected $formats = null;

    /**
     * Constructor.
     *
     * @param object $config Config object for this plugin
     * @param converter $converter Converter object (parent) of this unoconv instance
     */
    public function __construct($config, converter $converter) {
        $this->config = $config;
        $this->converter = $converter;
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
        global $CFG;

        // Doing a blanket catch to try and prevent any lost conversions due to unknown exceptions.
        try {
            if (!converter::are_requirements_met() || !$this->check_unoconv_path()) {
                // Unoconv is bad, fail or resubmit as needed.
                $this->converter->fail_or_resubmit($conversion, 'Unoconv not available on this system');
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
                    $this->converter->debug_info('Conversion record disappeared while we were preparing.');
                    return false;
                }
            }

            if ($conversion->get('status') === conversion::STATUS_COMPLETE) {
                // The conversion is aready complete.
                $this->converter->debug_info('Conversion already complete');
                return true;
            }

            $file = $conversion->get_sourcefile();
            if (!empty($CFG->fileconverter_force_utf) && $file->get_mimetype() == 'text/plain') {
                $file = $this->force_utf8_encoding($file);
            }

            if (empty($file)) {
                // This is most likely to happen when the source was modified before conversion, so the file is missing.
                $this->converter->fail_or_resubmit($conversion, 'Source file not found', true);
                return false;
            }

            // Sanity check that the conversion is supported.
            $fromformat = pathinfo($file->get_filename(), PATHINFO_EXTENSION);
            $format = $conversion->get('targetformat');
            if (!converter::supports($fromformat, $format)) {
                // We know this won't self correct, so we should just fail now.
                $this->converter->fail_or_resubmit($conversion, 'File format not supported', true);
                return false;
            }

            // We want to set the internal 'started' state ASAP, as it works an a marker to let other attempts know it is running.
            $data = $conversion->get('data');
            $data->status = converter::STATUS_STARTED;
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
                    $this->converter->fail_or_resubmit($conversion, 'Could not copy file contents to temp file.');
                    return false;
                }
            } catch (\file_exception $fe) {
                $this->converter->fail_or_resubmit($conversion, 'Could not copy file contents to temp file.');
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

            $this->converter->debug_info('Starting unoconv');

            // Dispatch the command with a timeout.
            list($exitcode, $stdout, $stderr) = $this->run_command_timeout($cmd, $timeout);

            if ($exitcode == -1) {
                // Seems that the conversion didn't go too well.
                $this->converter->fail_or_resubmit($conversion, 'Unoconv exited with code -1.');
                return false;
            }

            chdir($currentdir);
            touch($newtmpfile);

            if (filesize($newtmpfile) === 0) {
                $this->converter->fail_or_resubmit($conversion, 'Unoconv result was empty');
                return false;
            }

            // This may have taken a while, so we re-fetch the record to make sure nothing has changed.
            try {
                $conversion->read();
            } catch (\dml_missing_record_exception $e) {
                $this->converter->debug_info('Conversion record disappeared while we were converting.');
                return false;
            }

            if ($conversion->get('status') === conversion::STATUS_COMPLETE) {
                // The record is missing, or was already completed. So we should just end now.
                $this->converter->debug_info('Conversion was completed elsewhere while running');
                return true;
            }

            // If we got this far, then consider it a success.
            $conversion->store_destfile_from_path($newtmpfile);
            $conversion->set('status', conversion::STATUS_COMPLETE);
            $conversion->update();

            return true;
        } catch (\Exception $e) {
            $this->converter->fail_or_resubmit($conversion, "Unhandled exception during conversion: ".$e);
            return false;
        }
    }

    /**
     * Runs a specified shell command, while attempting to enforce a timeout.
     *
     * @param string $cmd  Fully escaped command string, with arguments.
     * @param int $timeout Number of seconds to allow the script to run before attempting to kill it.
     * @return array() Consists of 3 components in this order: exitcode, stdout, stderr.
     * @throws \moodle_exception
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
                $this->converter->debug_info('Issuing soft kill of unoconv');
                $termed = true;
                proc_terminate($proc);
            }
            if (!$killed && ($runtime > ($timeout + 15))) {
                // Now we are going to try and hard kill.
                $this->converter->debug_info('Issuing hard kill of unoconv');
                $killed = true;
                proc_terminate($proc, 9);
            }
            if ($runtime > ($timeout + 30)) {
                // If we've gotten this far, we don't know how to kill the child process. Throw hands in the air.
                $this->converter->debug_info('Unable to kill unoconv. Throwing exception.');
                throw new \moodle_exception('Could not kill conversion with PID '.$status['pid']);
            }
        } while (true);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitcode = $status['exitcode'];

        $this->converter->debug_info('unoconv stdout: '.$stdout);
        $this->converter->debug_info('unoconv stderr: '.$stderr);
        $this->converter->debug_info('unoconv exitcode: '.$exitcode);

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

        $formats = array_map('strtolower', $formats);

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
    public function get_supported_formats() {
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
     * Force file encoding to UTF-8.
     *
     * @param $file
     * @return false|mixed
     * @throws \file_exception
     * @throws \stored_file_creation_exception
     * @throws \dml_exception
     */
    protected function force_utf8_encoding($file = false) {
        if (empty($file)) {
            return false;
        }

        $newfile = false;
        $content = $file->get_content();
        if (!empty($content)) {
            $formats = ['ASCII', 'JIS', 'UTF-8', 'EUCJP-WIN', 'EUC-JP', 'SJIS-WIN', 'SJIS'];
            $fs = get_file_storage();
            $enc = mb_detect_encoding($content, $formats);
            $textcontent = mb_convert_encoding($content, 'UTF-8', $enc);

            $filerecord = [
                'contextid' => \context_system::instance()->id,
                'component' => $file->get_component(),
                'filearea' => $file->get_filearea(),
                'itemid' => 0,
                'filepath' => $file->get_filepath(),
                'filename' => (string) time()
            ];

            $newfile = $fs->create_file_from_string($filerecord, $textcontent);
        }
        if (!empty($newfile)) {
            $file->replace_file_with($newfile);
        }
        return $file;
    }
}
