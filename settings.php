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
 * Settings for local_tepuy.
 *
 * @package   local_tepuy
 * @copyright 2019 David Herney - cirano
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_tepuy', get_string('pluginname', 'local_tepuy'));

    $name = 'components_socket_cronuri';
    $title = get_string($name, 'local_tepuy');
    $description = get_string($name.'_desc', 'local_tepuy');
    $setting = new admin_setting_configtext('local_tepuy/'.$name, $title, $description, 'ws://localhost:1234/skey=');
    $settings->add($setting);

    $ADMIN->add('localplugins', $settings);
}
