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

// This file isn't apart of Moodle, so it fails in weird ways.
// @codingStandardsIgnoreFile

/**
 * Class that implements a Resque_JobInterface for execution.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2016 Open LMS
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unoconv_job implements Resque_JobInterface {

    /** @var string Override path to unoconv if needed. */
    static public $pathtounoconv = null;

    /** @var bool Set verbose output for call to Moodle script. */
    static public $verbose = false;

    /**
     * Executes the job action.
     *
     * @return bool
     * @throws Resque_Exception
     */
    public function perform() {
        if (empty($this->args['id']) || empty($this->args['path']) || !file_exists($this->args['path'])) {
            throw new Resque_Exception('Invalid arguments: '.json_encode($this->args));
        }

        // Only run if it is the run_conversion.php file.
        if (!preg_match('/run_conversion.php$/i', $this->args['path'])) {
            throw new Resque_Exception('Invalid script, must end in run_conversion.php: '.json_encode($this->args['path']));
        }

        // Execute the command.
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' .
               escapeshellarg($this->args['path']) . ' ' .
               escapeshellarg('--id') . '=' .
               escapeshellarg($this->args['id']);

        if (!empty(self::$pathtounoconv)) {
            $cmd .= ' ' . escapeshellarg('--pathtounoconv') . '=' .
                    escapeshellarg(self::$pathtounoconv);
        }

        if (!empty(self::$verbose)) {
            $cmd .= ' ' . escapeshellarg('-v');
        }

        $output = null;
        $result = exec($cmd, $output);

        // Log the results as a debugging output.
        $string = var_export($result, true);
        $this->job->worker->logger->log(Psr\Log\LogLevel::DEBUG, '$result: {log}', array('log' => $string));

        $string = var_export($output, true);
        $this->job->worker->logger->log(Psr\Log\LogLevel::DEBUG, '$output: {log}', array('log' => $string));

        // Check if there was a Moodle fatal error, like upgrade in progress...
        // We check for the !!! markers around fatal errors, because the error could be in other langs.
        $fatals = preg_match("/!!! (.*) !!!/", $result);
        if ($fatals) {
            // If there was a fatal error, wait a short while then throw an exception to mark conversion status failed.
            // The delay keeps us from plowing through the queue if a short upgrade is happening.
            // Moodle will re-enqueu if the document is called for again.
            // Cannot use built in recreate() function, because a new tracking id is created, and Moodle cannot find it.
            sleep(3);
            throw new Resque_Exception('Moodle fatal error: '.$result);
        }

        return true;
    }
}
