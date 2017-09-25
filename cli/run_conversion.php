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
 * Command line script to execute a conversion.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use \fileconverter_resque\converter;
use \core_files\conversion;

define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(dirname(dirname(__FILE__))))).'/config.php');

require_once($CFG->libdir.'/clilib.php');

list($options, $unrecognized) = cli_get_params(array('help' => false, 'id' => false, 'verbose' => false, 'pathtounoconv' => false),
                                               array('h' => 'help', 'i' => 'id', 'v' => 'verbose'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help'] || !$options['id']) {
    $help = "Executes a specific conversion id with unoconv.

Options:
-h, --help              Print out this help
-i, --id                The conversion ID.
-v, --verboase          Enable verbose output
--pathtounoconv <path>  Override the path to unoconv with this path when doing the callback.

Exit codes:
 0      Conversion completed successfully.
 1      Generic error preventing conversion.
 2      Conversion ID could not be found. May mean conversion was already completed.
 3      The file is already marked as conversion in progress.

Example:
\$sudo -u www-data /usr/bin/php files/converter/resque/cli/run_conversion.php
";

    echo $help;
    die;
}

$converter = new converter();

if (!empty($options['verbose'])) {
    $converter->set_verbose('PID:'.getmypid().' ID:'.$options['id']);
    $converter->debug_info('Starting conversion processing');
}

if (!empty($options['pathtounoconv'])) {
    $converter->debug_info('Updating path to unoconv to '.$options['pathtounoconv']);
    $converter->set_temp_path_to_unoconv($options['pathtounoconv']);
}

try {
    $conversion = new conversion($options['id']);
} catch (\dml_missing_record_exception $e) {
    $conversion = false;
}

if (empty($conversion)) {
    // Conversion can't be found.
    $converter->debug_info('Conversion could not be found');
    exit(2);
}

if ($conversion->get('status') === conversion::STATUS_COMPLETE) {
    // The conversion is already complete.
    $converter->debug_info('Conversion already complete');
    exit(0);
}

if ($conversion->get('status') === conversion::STATUS_IN_PROGRESS) {
    $data = $conversion->get('data');
    if ($data->status == converter::STATUS_STARTED) {
        $converter = $conversion->get_converter_instance();
        if ($converter) {
            // If we are running, and have a converter, check the timeout status.
            if (!$converter->check_conversion_is_timed_out($conversion)) {
                // Conversion is in progress already, and hasn't timed out.
                $converter->debug_info('Conversion is already running');
                exit(3);
            }
        }
    }
}

$converter->run_unoconv_conversion($conversion);
$conversion->update();

if ($conversion->get('status') != conversion::STATUS_COMPLETE) {
    // The conversion couldn't be completed for some reason.
    $converter->debug_info('Conversion could not be completed for some reason');
    exit(1);
}

// Conversion completed.
$converter->debug_info('Conversion complete');
exit(0);


