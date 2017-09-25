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
 * Version file.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Resque/Unoconv Converter';
$string['batchwaittimeout'] = 'Batch priority queue timeout';
$string['batchwaittimeout_help'] = 'How long until a message send to batch queue is considered timed out, and will either be failed or resubmitted.';
$string['highwaittimeout'] = 'High priority queue timeout';
$string['highwaittimeout_help'] = 'How long until a message send to high priority queue is considered timed out, and will either be failed or resubmitted.';
$string['messagepath'] = 'Message path';
$string['messagepath_help'] = 'The callback path to include in the messages, either the full path from system root, or the path from Moodle root.';
$string['othersettings'] = 'Other Settings';
$string['othersettings_help'] = '';
$string['pathfull'] = 'Full path';
$string['pathtounoconv'] = 'Path to unoconv';
$string['pathtounoconv_help'] = 'Path to the unoconv executable';
$string['pathrelative'] = 'Relative path';
$string['queuebatch'] = 'Batch resque queue';
$string['queuebatch_help'] = 'This is the resque queue used for conversions requested via the command line (cron).';
$string['queuehigh'] = 'High priority resque queue';
$string['queuehigh_help'] = 'This is the resque queue used for conversions requested via the web, most typically by a teacher';
$string['resquedb'] = 'Redis DB';
$string['resquedb_help'] = 'The Redis DB number to use';
$string['resquejobclass'] = 'Resque job class';
$string['resquejobclass_help'] = 'A job class to add to the resque request, which is used for processing by a worker.';
$string['resqueserver'] = 'Redis server';
$string['resqueserver_help'] = 'Redis server and optionally port, in address:port format.';
$string['resquesettings'] = 'Resque Settings';
$string['resquesettings_help'] = 'Settings related to resque and its use';
$string['retries'] = 'Conversion retries';
$string['retries_help'] = 'The number of times this plugin will re-attempt to run a conversion before failing/handing it off to the next converter plugin.';
$string['sitename'] = 'Site name';
$string['sitename_help'] = 'This is information that is added to the resque queue, and can be helpful for multi-tenancy installs';
$string['unoconvsettings'] = 'Unoconverter Settings';
$string['unoconvsettings_help'] = 'Setting related to unoconverter and its usage.';
$string['unoconvtimeout'] = 'Unoconv timeout';
$string['unoconvtimeout_help'] = 'Timeout after the actual unoconv has started';


