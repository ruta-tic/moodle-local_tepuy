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
 *
 * @package   local_tepuy
 * @copyright 2019 David Herney - cirano
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2019092601;        // The current plugin version (Date: YYYYMMDDXX).
$plugin->requires  = 2018051700;        // Requires this Moodle version.
$plugin->cron = 0;
$plugin->release = 'Roraima';           // A special and big Tepuy.
$plugin->component = 'local_tepuy';     // Full name of the plugin (used for diagnostics).
$plugin->maturity = MATURITY_BETA;
