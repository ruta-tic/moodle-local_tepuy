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

$string['pluginname'] = 'Tepuy enhancer';

$string['invalidjson'] = 'Invalid JSON string';
$string['actionrequired'] = 'An action is required';
$string['skeyrequired'] = 'A session key is required';
$string['invalidaction'] = 'Invalid action: {$a}';
$string['invalidkey'] = 'Invalid key';
$string['generalexception'] = 'Exception: {$a}';
$string['newchatconnectionerror'] = 'New chat connection error';
$string['settingsnotfound'] = 'Settings not found';
$string['userchatnotfound'] = 'User chat not found';
$string['chatnotavailable'] = 'Chat not available';
$string['notgroupnotteam'] = 'Not exists a related group';
$string['cardcodeandtyperequired'] = 'Card code and type are required';
$string['invalidcardcode'] = 'Invalid card code';
$string['invalidcardtype'] = 'Invalid card type';
$string['typenotallowed'] = 'Current user can\'t play this card type';
$string['carddontplayed'] = 'Card don\'t played';
$string['notmembersingroup'] = 'Not members in group {$a}';

// Original chat system messages
$string['messagebeepseveryone'] = '{$a} beeps everyone!';
$string['messagebeepsyou'] = '{$a} has just beeped you!';
$string['messageenter'] = '{$a} has just entered this chat';
$string['messageexit'] = '{$a} has left this chat';
$string['messageyoubeep'] = 'You beeped {$a}';

// Local chat messages
$string['messageactionplaycard'] = '{$a} has play a card';
$string['messageactionunplaycard'] = '{$a} has unplay a card';
$string['messageactionendcase'] = '{$a} has finished the attempt';
$string['messageactionplayerconnected'] = '{$a} connected';
$string['messageactionplayerdisconnected'] = '{$a} disconnected';
$string['messageactioncasefailed'] = 'Case failed';
$string['messageactioncasepassed'] = 'Case passed';
$string['messageactionattemptfailed'] = 'Attempt failed';
$string['messageactionattemptpassed'] = 'Attempt passed';
