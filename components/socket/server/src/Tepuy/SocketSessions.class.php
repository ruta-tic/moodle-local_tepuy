<?php
namespace Tepuy;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Tepuy\Messages;
use Tepuy\Logging;
use Tepuy\ByCodeException;
use Tepuy\AppCodeException;


class SocketSessions {
    private static $_sessions = array();
    private static $_resources = array();
    private static $_settings = array();
    private static $_settingsbyuid = array();

    public static function addConnection($conn, $sess, $iscron = false) {

        if (!isset(self::$_sessions[$sess->uid])) {
            self::$_sessions[$sess->uid] = new \stdClass();
            self::$_sessions[$sess->uid]->clients = new \SplObjectStorage;
            self::$_sessions[$sess->uid]->skeys = array();
            self::$_sessions[$sess->uid]->crons = array();
        }

        self::$_resources[$conn->resourceId] = $sess->uid;

        // Store the new connection to send messages to later
        self::$_sessions[$sess->uid]->clients->attach($conn);
        self::$_sessions[$sess->uid]->skeys[$conn->resourceId] = $sess;

        if ($iscron) {
            self::$_sessions[$sess->uid]->crons[$conn->resourceId] = true;
        }
    }

    public static function rmConnection($conn) {
        $session = self::getByResourceId($conn->resourceId);
        if ($session) {
            $session->clients->detach($conn);
        }

        if (isset(self::$_resources[$conn->resourceId])) {
            unset(self::$_resources[$conn->resourceId]);
        }

        if (isset($session->crons[$conn->resourceId])) {
            unset($session->crons[$conn->resourceId]);
        }


    }

    public static function isActiveSessKey($id) {
        $session = self::getByResourceId($id);
        if (!isset($session)) {
            return false;
        }

        return isset($session->skeys[$id]);
    }

    public static function isCron($id) {
        $session = self::getByResourceId($id);
        return isset($session->crons[$id]);
    }

    public static function countClients($id) {
        $session = self::getByResourceId($id);
        return !isset($session) ? 0 : count($session->clients);
    }

    public static function getSSById($id) {
        $session = self::getByResourceId($id);
        return !isset($session) ? null : $session->skeys[$id];
    }

    public static function getSSs($id) {
        $session = self::getByResourceId($id);
        return !isset($session) ? null : $session->skeys;
    }

    public static function getClientsById($id) {
        $session = self::getByResourceId($id);
        return !isset($session) ? null : $session->clients;
    }

    public static function getUIDById($id) {
        if (!isset(self::$_resources[$id])) {
            return null;
        }

        return self::$_resources[$id];
    }

    public static function setSettings($settings) {

        self::$_settings = $settings;

        self::$_settingsbyuid = array();
        foreach($settings as $setting) {
            $settingsdata = json_decode($setting->param1);
            self::$_settingsbyuid[$setting->uid] = $settingsdata;
        }
    }

    public static function getGameActions($id) {

        $actions = self::getGameProperty($id, 'actions');

        return !$actions ? array() : $actions;
    }

    public static function getGameKey($id) {
        return self::getGameProperty($id, 'game');
    }

    private static function getGameProperty($id, $prop) {

        if (!isset(self::$_resources[$id])) {
            return null;
        }

        $resourceid = self::$_resources[$id];

        if (isset(self::$_settingsbyuid[$resourceid])) {
            $params = self::$_settingsbyuid[$resourceid];
            if (property_exists($params, $prop)) {
                return $params->$prop;
            }
        }

        return null;
    }

    private static function getByResourceId($id) {
        if (!isset(self::$_resources[$id])) {
            return null;
        }

        if (!isset(self::$_sessions[self::$_resources[$id]])) {
            return false;
        }

        return self::$_sessions[self::$_resources[$id]];
    }

}
