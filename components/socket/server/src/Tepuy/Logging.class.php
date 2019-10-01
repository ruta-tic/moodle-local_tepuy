<?php
namespace Tepuy;

class Logging {

    public const LVL_ALL = 10;
    public const LVL_DETAIL = 20;
    public const LVL_DEBUG = 30;

    public static function trace($level, $text, $o = null) {
        global $CFG;

        if ($level <= self::LVL_ALL) {
            self::print($text, $o);
        } else {

            if ($CFG->debugdisplay) {

                if ($level <= self::LVL_DETAIL && $CFG->debug >= 15) {
                    self::print($text, $o);
                } else if ($CFG->debug >= 32767) {
                    self::print($text, $o);
                }
            }
        }

    }

    private static function print($text, $o) {
        echo "\n" . $text;

        if ($o) {
            echo "\n-----------------------------\n";
            var_dump($o);
            echo "-----------------------------";
        }
    }

}
