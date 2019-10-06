<?php
namespace Tepuy;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Tepuy\Messages;
use Tepuy\Logging;
use Tepuy\Action;
use Tepuy\ByCodeException;
use Tepuy\AppCodeException;


class SocketController implements MessageComponentInterface {
    public $clients;
    public $skeys;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->skeys = array();
    }

    public function onOpen(ConnectionInterface $conn) {
        global $DB;

        $query = $conn->httpRequest->getUri()->getQuery();
        parse_str($query, $params);

        if (empty($params['skey'])) {
            Messages::error('skeyrequired', null, $conn, true);
        }

        if (!$sess = $DB->get_record('local_tepuy_socket_sessions', array('skey' => $params['skey']))) {
            Messages::error('invalidkey', null, $conn, true);
        }

        $sess->lastping = time();
        $DB->update_record('local_tepuy_socket_sessions', $sess);

        // Store the new connection to send messages to later
        $this->clients->attach($conn);
        $this->skeys[$conn->resourceId] = $sess;

        Logging::trace(Logging::LVL_ALL, "New connection! ({$conn->resourceId})");

        $data = new \stdClass();
        $data->action = 'playerconnected';

        $action = new Action($this, $conn, $data);
        $action->run();
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        global $DB, $CFG;

        Logging::trace(Logging::LVL_DETAIL, 'Message received from: ' . $from->resourceId);
        Logging::trace(Logging::LVL_DEBUG, 'Message: ', $msg);
        Logging::trace(Logging::LVL_DEBUG, 'Clients: ' . count($this->clients));

        $json = @json_decode($msg);

        if (!$json) {
            Messages::error('invalidjson', null, $from);
        }

        if (empty($json->action)) {
            Messages::error('actionrequired', null, $from);
        }

        $json->issystem = false;
        $action = new Action($this, $from, $json);
        $action->run();
    }

    public function onClose(ConnectionInterface $conn) {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        Action::customUnset($conn);

        Logging::trace(Logging::LVL_ALL, "Connection {$conn->resourceId} has disconnected");

        $data = new \stdClass();
        $data->action = 'playerdisconnected';

        if (isset($this->skeys[$conn->resourceId])) {
            $action = new Action($this, $conn, $data);
            $action->run();
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {

        Logging::trace(Logging::LVL_ALL, "An error has occurred: {$e->getMessage()}");

        if (!($e instanceof AppException)) {
            $conn->close();
        }
    }
}
