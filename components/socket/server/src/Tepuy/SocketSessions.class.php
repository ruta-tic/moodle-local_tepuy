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

    public static function addConnection($conn, $sess) {

        if (!isset(self::$_sessions[$sess->uid])) {
            self::$_sessions[$sess->uid] = new \stdClass();
            self::$_sessions[$sess->uid]->clients = new \SplObjectStorage;
            self::$_sessions[$sess->uid]->skeys = array();
        }

        self::$_resources[$conn->resourceId] = $sess->uid;

        // Store the new connection to send messages to later
        self::$_sessions[$sess->uid]->clients->attach($conn);
        self::$_sessions[$sess->uid]->skeys[$conn->resourceId] = $sess;
    }

    public static function rmConnection($conn) {
        $session = self::getByResourceId($conn->resourceId);
        $session->clients->detach($conn);
        unset(self::$_resources[$conn->resourceId]);
    }

    public static function isActiveSessKey($id) {
        $session = self::getByResourceId($id);
        if (!isset($session)) {
            return false;
        }

        return isset($session->skeys[$id]);
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
