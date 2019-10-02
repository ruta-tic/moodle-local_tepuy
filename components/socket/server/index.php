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
 * Library of internal classes and functions for component socket into local Tepuy plugin
 *
 * @package   local_tepuy
 * @copyright 2019 David Herney - cirano
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Tepuy\SocketController;

// Moodle controller code.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
// End Moodle controller code.

define("APP_SOURCE_PATH", __DIR__."/src");

require __DIR__ . '/vendor/autoload.php';
require 'autoload.php';

$port = 8080;
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new SocketController()
        )
    ),
    $port
);

echo "Server running on port $port" . PHP_EOL;
$server->run();
