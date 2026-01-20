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
 * A testable converter object to expose hidden things.
 *
 * @package   local_file_convert
 * @copyright Copyright (c) 2016 Open LMS
 * @author    Eric Merrill
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use \fileconverter_resque\converter;
use \core_files\conversion;

defined('MOODLE_INTERNAL') || die();

require_once('testable_unoconv.php');

/**
 * A testable converter object to expose hidden things.
 *
 * @package    local_file_convert
 * @copyright  Copyright (c) 2016 Open LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fileconverter_resque_testable_converter extends converter {

    public $ishighpriority = true;

    public $unoconv = null;

    public function __construct() {
        parent::__construct();

        $this->unoconv = new fileconverter_resque_testable_unoconv($this->config, $this);
    }

    public function is_high_priority() {
        return $this->ishighpriority;
    }

    public function get_config($key) {
        if (isset($this->config->$key)) {
            return $this->config->$key;
        }

        return null;
    }

    public function set_config($key, $value) {
        $this->config->$key = $value;
    }

    // @codingStandardsIgnoreStart
    public function get_message_arguments(conversion $conversion) {
        return parent::get_message_arguments($conversion);
    }
    // @codingStandardsIgnoreEnd
}

