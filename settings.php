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
 * Settings file.
 *
 * @package   fileconverter_resque
 * @copyright Copyright (c) 2017 Open LMS
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use \fileconverter_resque\converter;

defined('MOODLE_INTERNAL') || die();

// Unoconv setting.

$settings->add(new admin_setting_heading('fileconverter_resque/unoconvsettings',
        new \core\lang_string('unoconvsettings', 'fileconverter_resque'),
        new \core\lang_string('unoconvsettings_help', 'fileconverter_resque'))
    );

$settings->add(new admin_setting_configexecutable('fileconverter_resque/pathtounoconv',
        new \core\lang_string('pathtounoconv', 'fileconverter_resque'),
        new \core\lang_string('pathtounoconv_help', 'fileconverter_resque'),
        '/usr/bin/unoconv')
    );

$settings->add(new admin_setting_configduration('fileconverter_resque/unoconvtimeout',
        new \core\lang_string('unoconvtimeout', 'fileconverter_resque'),
        new \core\lang_string('unoconvtimeout_help', 'fileconverter_resque'),
        360, 60)
    );

$settings->add(new admin_setting_heading('fileconverter_resque/resquesettings',
        new \core\lang_string('resquesettings', 'fileconverter_resque'),
        new \core\lang_string('resquesettings_help', 'fileconverter_resque'))
    );

$settings->add(new admin_setting_configtext('fileconverter_resque/resqueserver',
        new \core\lang_string('resqueserver', 'fileconverter_resque'),
        new \core\lang_string('resqueserver_help', 'fileconverter_resque'),
        'localhost:6379')
    );

$settings->add(new admin_setting_configtext('fileconverter_resque/resquedb',
        new \core\lang_string('resquedb', 'fileconverter_resque'),
        new \core\lang_string('resquedb_help', 'fileconverter_resque'),
        0, PARAM_INT)
    );

$settings->add(new admin_setting_configtext('fileconverter_resque/queuehigh',
        new \core\lang_string('queuehigh', 'fileconverter_resque'),
        new \core\lang_string('queuehigh_help', 'fileconverter_resque'),
        'unoconv')
    );

$settings->add(new admin_setting_configtext('fileconverter_resque/queuebatch',
        new \core\lang_string('queuebatch', 'fileconverter_resque'),
        new \core\lang_string('queuebatch_help', 'fileconverter_resque'),
        'unoconv_batch')
    );

$settings->add(new admin_setting_configduration('fileconverter_resque/highwaittimeout',
        new \core\lang_string('highwaittimeout', 'fileconverter_resque'),
        new \core\lang_string('highwaittimeout_help', 'fileconverter_resque'),
        3600, 3500)
    );

$settings->add(new admin_setting_configduration('fileconverter_resque/batchwaittimeout',
        new \core\lang_string('batchwaittimeout', 'fileconverter_resque'),
        new \core\lang_string('batchwaittimeout_help', 'fileconverter_resque'),
        7200, 3600)
    );


$settings->add(new admin_setting_configtext('fileconverter_resque/sitename',
        new \core\lang_string('sitename', 'fileconverter_resque'),
        new \core\lang_string('sitename_help', 'fileconverter_resque'),
        '', PARAM_ALPHANUMEXT)
    );


$settings->add(new admin_setting_configtext('fileconverter_resque/resquejobclass',
        new \core\lang_string('resquejobclass', 'fileconverter_resque'),
        new \core\lang_string('resquejobclass_help', 'fileconverter_resque'),
        'unoconv_job')
    );

$options = array(converter::PATH_FULL => new \core\lang_string('pathfull', 'fileconverter_resque'),
                 converter::PATH_RELATIVE => new \core\lang_string('pathrelative', 'fileconverter_resque'));
$settings->add(new admin_setting_configselect('fileconverter_resque/messagepath',
        new \core\lang_string('messagepath', 'fileconverter_resque'),
        new \core\lang_string('messagepath_help', 'fileconverter_resque'),
        converter::PATH_FULL, $options)
    );


$settings->add(new admin_setting_heading('fileconverter_resque/othersettings',
        new \core\lang_string('othersettings', 'fileconverter_resque'),
        new \core\lang_string('othersettings_help', 'fileconverter_resque'))
    );

$options = array('0', '1', '2', '3', '4', '5', '10');
$settings->add(new admin_setting_configselect('fileconverter_resque/retries',
        new \core\lang_string('retries', 'fileconverter_resque'),
        new \core\lang_string('retries_help', 'fileconverter_resque'),
        2, $options)
    );
