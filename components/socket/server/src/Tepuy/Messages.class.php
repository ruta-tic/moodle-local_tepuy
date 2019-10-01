<?php
namespace Tepuy;

class Messages {

    public static function error($code, $vars = null, $client = null) {
        $o = new \stdClass();
        $o->errorcode = $code;

        try {
            $o->error = get_string($code, 'local_tepuy', $vars);
        } catch (\Exception $e) {
            $o->error = $code;
        }

        //ToDo: implement
        $o->stacktrace = '//ToDo:';

        $msg = json_encode($o);

        if ($client) {
            $client->send($msg);
            throw new AppException($code);
        } else {
            return $msg;
        }
    }

}

class AppException extends \Exception { }
