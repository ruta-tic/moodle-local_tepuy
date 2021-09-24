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

define('AJAX_SCRIPT', true);

require_once('../../../../config.php');
require_once($CFG->dirroot . '/lib/outputcomponents.php');
require_once($CFG->dirroot . '/local/tepuy/components/socket/locallib.php');
require_once($CFG->dirroot . '/mod/chat/lib.php');

$courseid = required_param('id', PARAM_INT);

$groupid = 0;
$groupname = '';
$cm = new stdClass();
$cm->id = 0;

$url = new moodle_url('/local/tepuy/components/socket/index.php', array('course' => $courseid));

$PAGE->set_url($url);

if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    print_error('invalidcourseid');
}

require_login($course, false);

if (!$sess = local_tepuy_component_socket_getskey($cm, 0, $course, $groupid)) {
    print_error('cantlogin');
}

if (!$chat = $DB->get_record('chat', array('course' => $courseid, 'name' => 'envivo'))) {
    print_error('invalidmodule');
}

if (!$chatsid = chat_login_user($chat->id, 'tepuy', $groupid, $course)) {
    print_error('cantlogin');
}

if (!$socketchat = $DB->get_record('local_tepuy_socket_chat', array('sid' => $sess->id))) {

    $socketchat = new stdClass();
    $socketchat->sid = $sess->id;
    $socketchat->chatsid = $chatsid;
    $DB->insert_record('local_tepuy_socket_chat', $socketchat);
} else if ($socketchat->chatsid != $chatsid) {
    $socketchat->chatsid = $chatsid;
    $DB->update_record('local_tepuy_socket_chat', $socketchat);
}

try {
    $userpicture = new user_picture(core_user::get_user($USER->id));
    $pictureurl = $userpicture->get_url($PAGE);
    $pictureurl = $pictureurl->out();
} catch (Exception $e) {
    $userpicture = '';
}

$res = new stdClass();
$res->skey = $sess->skey;
$res->userid = $USER->id;
$res->userpicture = $pictureurl;
$res->courseid = $course->id;
$res->courseshortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));
$res->groupid = $groupid;
$res->groupname = $groupname;
$res->secure = false;

if (isset($_SERVER['HTTP_HOST'])) {
    $res->serverurl = $_SERVER['HTTP_HOST'] . ":8080/wss2/";
} else {
    $res->serverurl = "localhost:8080";
}

echo json_encode($res);
exit;
