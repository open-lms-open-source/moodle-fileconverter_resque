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
 * A command line Resque worker for unoconv.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Don't let this be called by a web process.
if (isset($_SERVER['REMOTE_ADDR'])) {
    echo('Command line scripts can not be executed from the web interface');
    exit(1);
}

// Use the build in PHP options.
$options = getopt('vh:p:a:d:', array('help', 'pidfile:', 'hqueue:', 'bqueue:', 'pathtounoconv:'));

// Print help.
if (isset($options['help'])) {
    echo "A worker that dispatches Resque jobs back to a provided PHP script.

This worker runs in the foreground, and outputs logging to the console.
It monitors the Resque queues \"unoconv\" and \"unoconv_batch\" in that
priority order.

This script does NOT use any of the Moodle code base, and can be moved
independently, but it will need access to the composer autoload file.

Options:
    -v                      Verbose output. Includes debugging information.
    -a <path>               Path to the composer autoload that includes
                            \"chrisboulton/php-resque\".
                            Defaults to \"<pwd>/../vendor/autoload.php\"
    -h <hostname>           The Redis hostname/ip address to connect to.
                            Defaults to localhost.
    -d <db>                 The Redis database number to use. Defaults to 0.
    --hqueue <name>         The high priority Resque queue to monitor. This is
                            the queue that takes priority. Defaults to unoconv.
    --bqueue <name>         The low priority Resque queue to monitor. Jobs in
                            this queue are only processes after all jobs in the
                            high priority queue are completed.
                            Defaults to unconv_batch.
    -p <port>               The Redis port number to connect to. Defaults to 6379.
    --pidfile <path>        The path to put the pid file. No pit file if not set.
    --pathtounoconv <path>  Override the path to unoconv with this path when doing the callback.
";
    exit(0);
}

// Use the autoloader option.
if (empty($options['a'])) {
    require_once dirname(dirname(__FILE__)).'/vendor/autoload.php';
} else {
    // Check that the autoloader file exists.
    if (!is_readable($options['a'])) {
        echo "Fatal error: Invalid class autoloader path \"".$options['a']."\".\n";
        exit(1);
    }
    require_once $options['a'];
}
require_once 'unoconv_job.php';

// Get the first and second queues.
$queues = array();
if (!empty($options['hqueue'])) {
    $queues[] = $options['hqueue'];
} else {
    $queues[] = 'unoconv';
}

if (!empty($options['bqueue'])) {
    $queues[] = $options['bqueue'];
} else {
    $queues[] = 'unoconv_batch';
}

// Set the backend host name.
if (isset($options['h'])) {
    $backend = $options['h'];
} else {
    $backend = 'localhost';
}

// Set the backend port.
if (isset($options['p'])) {
    $backend .= ':' . $options['p'];
} else {
    $backend .= ':6379';
}

// Get the redis db.
if (isset($options['d']) && is_numeric($options['d'])) {
    $db = (int)$options['d'];
} else {
    $db = 0;
}

// Set the backend location.
Resque::setBackend($backend, $db);

// Setup the logger.
$loglevel = false;
if (isset($options['v'])) {
    $loglevel = true;
    unoconv_job::$verbose = true;
}
$logger = new Resque_Log($loglevel);

// Make a new worker.
$worker = new Resque_Worker($queues);
$worker->setLogger($logger);

// Place the pidfile if option is set.
if (isset($options['pidfile'])) {
    if (file_exists($options['pidfile'])) {
        die('PID file already exists: ' . $options['pidfile'] . ". Service may be already running. Remove file to start.\n");
    }

    file_put_contents($options['pidfile'], getmypid()) or
        die('Could not write PID information to ' . $options['pidfile']);

    // Make sure the pid file gets removed when we are done.
    register_shutdown_function('unlink', $options['pidfile']);
}

if (isset($options['pathtounoconv'])) {
    unoconv_job::$pathtounoconv = $options['pathtounoconv'];
}

// Start the worker. It blocks, looking for jobs, for 5 seconds and then breaks, and cycles again.
// This is because while blocked, commands like ctrl-c do not work - they will take effect when the block breaks.
// When verbose logging is on, this also allows a status message that makes it clear that the worker is still working every cycle.
// We use blocking because it lightens the load and speeds up response time. Redis holds the connection until timeout,
// or until a item is placed in the list.
$logger->log(Psr\Log\LogLevel::NOTICE, 'Starting worker {worker}', array('worker' => $worker));
$worker->work(5, true);
