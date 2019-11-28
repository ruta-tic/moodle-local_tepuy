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

namespace Tepuy;
use Tepuy\GameAngi;
use Tepuy\SocketSessions;

class Action {

    // The only valid actions.
    const AVAILABLES = array('chatmsg', 'chathistory', 'gamestate', 'playerconnected', 'playerdisconnected',
                                // Actions to GameAngi.
                                'playcard', 'unplaycard', 'endcase',
                                // Actions to Games SmartCity and Pandemia.
                                'sc_gamestart', 'sc_changetimeframe', 'sc_playaction',
                                'sc_playtechnology', 'sc_stopaction', 'sc_stoptechnology', 'sc_actioncompleted',
                                'sc_technologycompleted', 'sc_healthupdate', 'sc_gameover');

    public $action;

    public $request;

    public $from;

    public $session;

    public $user;

    public static $chats = array();

    public function __construct($from, $request) {
        global $DB;

        if (!in_array($request->action, self::AVAILABLES)) {
            Messages::error('invalidaction', $request->action, $from);
        }

        $gameactions = SocketSessions::getGameActions($from->resourceId);
        if (!in_array($request->action, $gameactions)) {
            Messages::error('invalidaction', $request->action, $from);
        }

        $this->from = $from;
        $this->action = $request->action;
        $this->request = $request;
        $this->session = SocketSessions::getSSById($from->resourceId);
        $this->user = $DB->get_record('user', array('id' => $this->session->userid));
    }

    public function run() {
        $method = 'action_' . $this->action;

        return $this->$method();

    }

    // General actions.
    private function action_chatmsg() {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/chat/lib.php');

        $chatuser = $this->getChatUser();

        if (!property_exists($this->request, 'issystem')) {
            $this->request->issystem = false;
        }

        if (!property_exists($this->request, 'tosender')) {
            $this->request->tosender = false;
        }

        //A Moodle action to save a chat message.
        $msgid = chat_send_chatmessage($chatuser, $this->request->data, $this->request->issystem);

        $data = new \stdClass();
        $data->id = $msgid;
        $data->user = new \stdClass();
        $data->user->id = $this->user->id;
        $data->user->name = $this->user->firstname;
        $data->timestamp = time();
        $data->issystem = $this->request->issystem ? 1 : 0;

        if ($this->request->issystem) {
            if (strpos($this->request->data, 'action') === 0) {
                $data->msg = get_string('message' . $this->request->data, 'local_tepuy', $this->user->firstname) . '';
            }
            else {
                $msg->msg = $this->request->data;
            }
        } else {
            $data->msg = $this->request->data;
        }

        $msg = $this->getResponse($data);
        $msg = json_encode($msg);

        $clients = SocketSessions::getClientsById($this->from->resourceId);
        foreach ($clients as $client) {
            if (($client !== $this->from || $this->request->tosender) &&
                    SocketSessions::getSSById($client->resourceId)->groupid == $chatuser->groupid) {
                // The sender is not the receiver, send to each client connected into same group.
                $client->send($msg);
            }
        }
        Logging::trace(Logging::LVL_DETAIL, 'Chat message sended.');

        return true;
    }

    private function action_chathistory() {
        global $DB;

        $n = 10;
        $s = 0;
        if (property_exists($this->request, 'data')) {
            if (property_exists($this->request->data, 'n')) {
                $n = $this->request->data->n;
            }

            if (property_exists($this->request->data, 's')) {
                $s = $this->request->data->s;
            }
        }

        $chatuser = $this->getChatUser();

        $params = array('groupid' => $chatuser->groupid, 'chatid' => $chatuser->chatid);

        $scondition = '';
        if ($s) {
            $params['s'] = $s;
            $scondition = " AND m.id < :s";
        }

        $groupselect = $chatuser->groupid ? " AND (groupid=" . $chatuser->groupid . " OR groupid=0) " : "";

        $sql = "SELECT m.id, m.message, m.timestamp, m.issystem, u.id AS userid, u.firstname
                    FROM {chat_messages_current} AS m
                    INNER JOIN {user} AS u ON m.userid = u.id
                    WHERE chatid = :chatid " . $scondition . $groupselect . ' ORDER BY timestamp DESC';

        $data = array();
        if ($msgs = $DB->get_records_sql($sql, $params, 0, $n)) {
            foreach($msgs as $one) {
                $msg = new \stdClass();
                $msg->id = $one->id;
                $msg->user = new \stdClass();
                $msg->user->id = $one->userid;
                $msg->user->name = $one->firstname;
                $msg->issystem = $one->issystem;

                if ($msg->issystem) {
                    if (strpos($one->message, 'action') === 0) {
                        $msg->msg = get_string('message' . $one->message, 'local_tepuy', $one->firstname) . '';
                    } else if (in_array($one->message, array('beepseveryone', 'beepsyou', 'enter', 'exit', 'youbeep'))) {
                        $msg->msg = get_string('message' . $one->message, 'mod_chat', $one->firstname);
                    }
                    else {
                        $msg->msg = $one->message;
                    }
                } else {
                    $msg->msg = $one->message;
                }

                $msg->timestamp = $one->timestamp;
                $data[] = $msg;
            }
        }

        $msg = $this->getResponse($data);
        $msg = json_encode($msg);

        $this->from->send($msg);

        Logging::trace(Logging::LVL_DETAIL, 'Chat history message sended.');

        return true;
    }

    private function action_gamestate() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }


        $data = new \stdClass();
        $data->currenttime = time();

        $gamekey = SocketSessions::getGameKey($this->from->resourceId);

        if ($gamekey == 'GameAngi') {
            $game = new GameAngi($this->session->groupid);

            $current = $game->currentCase();

            if ($current) {
                $data->team = $current->team;
                $data->playedcards = $current->playedcards;
            } else {
                $data->team = $game->summary->team;
                $data->playedcards = array();

            }

            $data->cases = $game->casesState();
            $data->points = $game->points();

        } else if ($gamekey == 'SmartCity') {
            $game = new SmartCity($this->session->groupid);

            $data->team = $game->summary->team;
            $data->games = $game->getGames();
            $data->timeframe = (int)$game->summary->timeframe;
            $data->health = $game->getHealth();
            $data->actions = $game->getActions($this->user->id);
            $data->technologies = $game->getTechnologies($this->user->id);
            $data->files = $game->getFiles();

            $current = $game->currentGame();
            if($current) {
                $data->starttime = $current->starttime;
                $data->duedate = $game->getDuedate();
            } else {
                $data->starttime = 0;
                $data->duedate = 0;
            }

        }


        if (property_exists($data, 'team')) {
            // Load connected state of members.
            foreach($data->team as $member) {
                $member->connected = false;
                $sesslist = SocketSessions::getSSs($this->from->resourceId);
                foreach($sesslist as $sess) {
                    if ($sess->userid == $member->id) {
                        $member->connected = true;
                        break;
                    }
                }
            }
        }

        $msg = $this->getResponse($data);
        $msg = json_encode($msg);

        $this->from->send($msg);

        Logging::trace(Logging::LVL_DETAIL, 'Game state sended.');

        return true;
    }

    private function action_playerconnected() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        $data = new \stdClass();
        $data->id = $this->user->id;
        $data->name = $this->user->firstname;

        $msg = $this->getResponse($data);
        $msg = json_encode($msg);

        $clients = SocketSessions::getClientsById($this->from->resourceId);
        foreach ($clients as $client) {
            if ($client !== $this->from &&
                    SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                // The sender is not the receiver, send to each client connected into same group.
                $client->send($msg);
            }
        }

        $this->notifyActionToAll();

        return true;
    }

    private function action_playerdisconnected() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        $data = new \stdClass();
        $data->id = $this->user->id;
        $data->name = $this->user->firstname;

        $msg = $this->getResponse($data);
        $msg = json_encode($msg);

        $clients = SocketSessions::getClientsById($this->from->resourceId);
        foreach ($clients as $client) {
            if ($client !== $this->from &&
                    SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                // The sender is not the receiver, send to each client connected into same group.
                $client->send($msg);
            }
        }

        $this->notifyActionToAll();

        return true;
    }

    // Specific actions to GameAngi.
    private function action_playcard() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        if (!property_exists($this->request, 'data') ||
                !property_exists($this->request->data, 'cardcode') ||
                !property_exists($this->request->data, 'cardtype')
            ) {

            Messages::error('cardcodeandtyperequired', null, $this->from);
        }

        $game = new GameAngi($this->session->groupid);

        try {
            $game->playCard($this->request->data->cardcode, $this->request->data->cardtype, $this->user->id);
        } catch (ByCodeException $ce) {
            Messages::error($ce->getMessage(), null, $this->from);
        }

        $data = new \stdClass();
        $data->timestamp = time();
        $data->userid = $this->user->id;
        $data->cardtype = $this->request->data->cardtype;
        $data->cardcode = $this->request->data->cardcode;

        $msg = $this->getResponse($data);
        $msg = json_encode($msg);

        $clients = SocketSessions::getClientsById($this->from->resourceId);
        foreach ($clients as $client) {
            if ($client !== $this->from &&
                    SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                // The sender is not the receiver, send to each client connected into same group.
                $client->send($msg);
            }
        }

        Logging::trace(Logging::LVL_DETAIL, 'Card played.');
        $this->notifyActionToAll();

        return true;
    }

    private function action_unplaycard() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        if (!property_exists($this->request, 'data') ||
                !property_exists($this->request->data, 'cardcode') ||
                !property_exists($this->request->data, 'cardtype')
            ) {

            Messages::error('cardcodeandtyperequired', null, $this->from);
        }

        $game = new GameAngi($this->session->groupid);

        try {
            $game->unplayCard($this->request->data->cardcode, $this->request->data->cardtype, $this->user->id);
        } catch (ByCodeException $ce) {
            Messages::error($ce->getMessage(), null, $this->from);
        }

        $data = new \stdClass();
        $data->timestamp = time();
        $data->userid = $this->user->id;
        $data->cardtype = $this->request->data->cardtype;
        $data->cardcode = $this->request->data->cardcode;

        $msg = $this->getResponse($data);
        $msg = json_encode($msg);

        $clients = SocketSessions::getClientsById($this->from->resourceId);
        foreach ($clients as $client) {
            if ($client !== $this->from &&
                    SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                // The sender is not the receiver, send to each client connected into same group.
                $client->send($msg);
            }
        }

        Logging::trace(Logging::LVL_DETAIL, 'Card unplayed.');
        $this->notifyActionToAll();

        return true;
    }

    private function action_endcase() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        $game = new GameAngi($this->session->groupid);

        $originalcase = $game->currentCase();
        $game->endCurrentCase();

        $msg = $this->getResponse(null);
        $msg = json_encode($msg);

        $clients = SocketSessions::getClientsById($this->from->resourceId);
        foreach ($clients as $client) {
            if (SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                // Send to each client connected into same group, including the sender.
                $client->send($msg);
            }
        }

        Logging::trace(Logging::LVL_DETAIL, 'Case ended.');
        $this->notifyActionToAll();

        $keyresponse = 'action';
        if ($originalcase->state != GameAngi::STATE_ACTIVE) {
            $keyresponse .= 'case' . $originalcase->state;
        } else {
            $keyresponse .= 'attemptfailed';
        }

        $this->notifyActionToAll($keyresponse, true);

        return true;
    }

    // Specific SmartCity actions.
    private function action_sc_gamestart() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        if (!property_exists($this->request, 'data') ||
                !property_exists($this->request->data, 'level')
            ) {

            Messages::error('fieldrequired', 'level', $this->from);
        }

        $game = new SmartCity($this->session->groupid);

        try {
            $game->start($this->request->data->level);
        } catch (ByCodeException $ce) {
            Messages::error($ce->getMessage(), null, $this->from);
        }

        $data = new \stdClass();
        $data->level = $this->request->data->level;

        $msg = $this->getResponse($data);
        $msg = json_encode($msg);

        $clients = SocketSessions::getClientsById($this->from->resourceId);
        foreach ($clients as $client) {
            if (SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                // Send to each client connected into same group, including the sender.
                $client->send($msg);
            }
        }

        Logging::trace(Logging::LVL_DETAIL, 'Game start with level: ' . $this->request->data->level);
        $this->notifyActionToAll();

        return true;
    }

    private function action_sc_changetimeframe() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        if (!property_exists($this->request, 'data') ||
                !property_exists($this->request->data, 'timeframe')
            ) {

            Messages::error('fieldrequired', 'timeframe', $this->from);
        }

        $game = new SmartCity($this->session->groupid);

        $changed = false;
        try {
            $changed = $game->changeTimeframe($this->request->data->timeframe);
        } catch (ByCodeException $ce) {
            Messages::error($ce->getMessage(), null, $this->from);
        }

        if ($changed) {
            $data = new \stdClass();
            $data->timeframe = $this->request->data->timeframe;
            $data->duedate = $game->getDuedate();

            $msg = $this->getResponse($data);
            $msg = json_encode($msg);

            $clients = SocketSessions::getClientsById($this->from->resourceId);
            foreach ($clients as $client) {
                if (SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                    // Send to each client connected into same group, including the sender.
                    $client->send($msg);
                }
            }

            Logging::trace(Logging::LVL_DETAIL, 'Change the timeframe: ' . $this->request->data->timeframe);
            $this->notifyActionToAll();
        }

        return true;
    }

    private function action_sc_playaction() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        if (!property_exists($this->request, 'data') ||
                !property_exists($this->request->data, 'id')
            ) {

            Messages::error('fieldrequired', 'id', $this->from);
        }

        if (property_exists($this->request->data, 'parameters')) {
            $parameters = $this->request->data->parameters;
        } else {
            $parameters = array();
        }

        $game = new SmartCity($this->session->groupid);

        $runningact = null;
        try {
            $runningact = $game->playAction($this->user->id, $this->request->data->id, $parameters);
        } catch (ByCodeException $ce) {
            Messages::error($ce->getMessage(), null, $this->from);
        }

        if ($runningact) {
            $data = new \stdClass();
            $data->id = $runningact->id;
            $data->starttime = $runningact->starttime;
            $data->resources = $game->availableResources();

            $msg = $this->getResponse($data);
            $msg = json_encode($msg);

            $clients = SocketSessions::getClientsById($this->from->resourceId);
            foreach ($clients as $client) {
                if (SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                    // Send to each client connected into same group, including the sender.
                    $client->send($msg);
                }
            }

            Logging::trace(Logging::LVL_DETAIL, 'Play activity: ' . $this->request->data->id);
            $this->notifyActionToAll();
        }

        return true;
    }

    private function action_sc_stopaction() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        if (!property_exists($this->request, 'data') ||
                !property_exists($this->request->data, 'id')
            ) {

            Messages::error('fieldrequired', 'id', $this->from);
        }

        $game = new SmartCity($this->session->groupid);

        $runningact = null;
        try {
            $stoped = $game->stopAction($this->user->id, $this->request->data->id);
        } catch (ByCodeException $ce) {
            Messages::error($ce->getMessage(), null, $this->from);
        }

        if ($stoped) {
            $data = new \stdClass();
            $data->id = $this->request->data->id;
            $data->resources = $game->availableResources();

            $msg = $this->getResponse($data);
            $msg = json_encode($msg);

            $clients = SocketSessions::getClientsById($this->from->resourceId);
            foreach ($clients as $client) {
                if (SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                    // Send to each client connected into same group, including the sender.
                    $client->send($msg);
                }
            }

            Logging::trace(Logging::LVL_DETAIL, 'Stop activity: ' . $this->request->data->id);
            $this->notifyActionToAll();
        }

        return true;
    }

    private function action_sc_playtechnology() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        if (!property_exists($this->request, 'data') ||
                !property_exists($this->request->data, 'id')
            ) {

            Messages::error('fieldrequired', 'id', $this->from);
        }

        if (property_exists($this->request->data, 'parameters')) {
            $parameters = $this->request->data->parameters;
        } else {
            $parameters = array();
        }

        $game = new SmartCity($this->session->groupid);

        $runningtech = null;
        try {
            $runningtech = $game->playTechnology($this->user->id, $this->request->data->id, $parameters);
        } catch (ByCodeException $ce) {
            Messages::error($ce->getMessage(), null, $this->from);
        }

        if ($runningtech) {
            $data = new \stdClass();
            $data->id = $runningtech->id;
            $data->starttime = $runningtech->starttime;
            $data->resources = $game->availableTechResources();

            $msg = $this->getResponse($data);
            $msg = json_encode($msg);

            $clients = SocketSessions::getClientsById($this->from->resourceId);
            foreach ($clients as $client) {
                if (SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                    // Send to each client connected into same group, including the sender.
                    $client->send($msg);
                }
            }

            Logging::trace(Logging::LVL_DETAIL, 'Play technology: ' . $this->request->data->id);
            $this->notifyActionToAll();
        }

        return true;
    }

    private function action_sc_stoptechnology() {

        if (!$this->session->groupid) {
            Messages::error('notgroupnotteam', null, $this->from);
        }

        if (!property_exists($this->request, 'data') ||
                !property_exists($this->request->data, 'id')
            ) {

            Messages::error('fieldrequired', 'id', $this->from);
        }

        $game = new SmartCity($this->session->groupid);

        $runningtech = null;
        try {
            $stoped = $game->stopTechnology($this->user->id, $this->request->data->id);
        } catch (ByCodeException $ce) {
            Messages::error($ce->getMessage(), null, $this->from);
        }

        if ($stoped) {
            $data = new \stdClass();
            $data->id = $this->request->data->id;
            $data->resources = $game->availableTechResources();

            $msg = $this->getResponse($data);
            $msg = json_encode($msg);

            $clients = SocketSessions::getClientsById($this->from->resourceId);
            foreach ($clients as $client) {
                if (SocketSessions::getSSById($client->resourceId)->groupid == $this->session->groupid) {
                    // Send to each client connected into same group, including the sender.
                    $client->send($msg);
                }
            }

            Logging::trace(Logging::LVL_DETAIL, 'Stop technology: ' . $this->request->data->id);
            $this->notifyActionToAll();
        }

        return true;
    }


    // Internal methods.
    private function getChatUser() {
        global $DB;

        if (!isset(self::$chats[$this->from->resourceId])) {
            if (!$socketchat = $DB->get_record('local_tepuy_socket_chat', array('sid' => $this->session->id))) {
                Messages::error('chatnotavailable', null, $this->from);
            }

            $chatuser = $DB->get_record('chat_users', array('sid' => $socketchat->chatsid));
            if ($chatuser === false) {
                Messages::error('userchatnotfound', null, $this->from);
            }

            self::$chats[$this->from->resourceId] = $chatuser;
        } else {
            $chatuser = self::$chats[$this->from->resourceId];
        }

        return $chatuser;
    }

    public static function customUnset($conn) {
        unset(self::$chats[$conn->resourceId]);
    }

    private function getResponse($data) {
        $msg = new \stdClass();

        $msg->action = $this->action;
        $msg->data = $data;

        return $msg;
    }

    private function notifyActionToAll($msg = null, $tosender = false) {

        try {
            $data = new \stdClass();
            $data->action = 'chatmsg';
            $data->issystem = true;
            $data->tosender = $tosender;

            if ($msg) {
                $data->data = $msg;
            } else {
                $data->data = 'action' . $this->action;
            }

            $action = new Action($this->from, $data);
            $action->run();

            Logging::trace(Logging::LVL_DETAIL, 'Chat system message: ' . $data->data);

            return true;

        } catch(\Exception $e) {
            return false;
        }
    }
}
