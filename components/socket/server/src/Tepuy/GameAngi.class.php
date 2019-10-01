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

class GameAngi {

    public $groupid;

    public const CASES = array('john', 'natalia', 'hermes', 'santiago', 'nairobi');
    public const ROLES = array('planner', 'tech', 'media', 'red', 'master');

    public const STATE_LOCKED = 'locked';
    public const STATE_PASSED = 'passed';
    public const STATE_FAILED = 'failed';
    public const STATE_ACTIVE = 'active';

    public $summary;

    public function __construct($groupid) {
        global $DB;

        $this->groupid = $groupid;

        $this->summary = $DB->get_record('local_tepuy_gameangi', array('groupid' => $groupid));

        if (!$this->summary) {
            $this->summary = $this->start();
        }

        $this->summary->team = json_decode($this->summary->team);
        $this->summary->cases = json_decode($this->summary->cases);
    }

    public function casesState() {

        $res = array();
        foreach($this->summary->cases as $case) {
            $one = new \stdClass();
            $one->id = $case->id;
            $one->state = $case->state;
            $one->attempt = $case->attempt;
            $one->lastattempt = $case->lastattempt;
            $res[] = $one;
        }
        return $res;
    }

    public function getCurrentCase() {

        foreach($this->summary->cases as $case) {
            if ($case->state == self::STATE_ACTIVE) {
                $case->playedcards = array();

                $played = $this->playedCards($case);
                if (is_array($played)) {
                    foreach($played as $play) {
                        $card = new \stdClass();
                        $card->userid = $play->userid;
                        $card->cardtype = $play->cardtype;
                        $card->cardcode = $play->cardcode;
                        $card->timemodify = $play->timemodify;
                        $case->playedcards[] = $card;
                    }
                }

                foreach($case->team as $one) {
                    foreach($this->summary->team as $member) {
                        if ($one->id == $member->id) {
                            $one->name = $member->name;
                            break;
                        }
                    }
                }

                return $case;
            }
        }

        return null;
    }

    public function playedCards($case) {
        global $DB;

        $params = array();
        $params['groupid'] = $this->groupid;
        $params['caseid'] = $case->id;
        $params['attempt'] = $case->attempt;

        $cards = $DB->get_records('local_tepuy_gameangi_cards', $params);
    }

    private function start() {
        global $DB, $CFG;

        require_once($CFG->libdir . '/grouplib.php');

        $members = groups_get_members($this->groupid, 'u.id, u.firstname AS name', 'u.id');

        //Set the role into each case.
        $teams = array();
        $i = 0;

        // Master is the last role, it is excluded when the team has not 5 members.
        $mod = count($members) < 5 ? 4 : 5;
        foreach(self::CASES as $case) {
            $teams[$case] = array();
            $j = $i;
            foreach($members as $key => $member) {
                $j = $j % $mod;
                $one = new \stdClass();
                $one->id = $key;
                $one->role = self::ROLES[$j];
                $teams[$case][] = $one;
                $j++;
            }
            $i++;
        }

        $cases = self::CASES;
        shuffle($cases);

        $orderedcases = array();
        $state = self::STATE_ACTIVE;
        foreach($cases as $case) {
            $newcase = new \stdClass();
            $newcase->id = $case;
            $newcase->state = $state;
            $newcase->attempt = 1;
            $newcase->lastattempt = 0;
            $newcase->team = $teams[$case];
            $orderedcases[] = $newcase;
            $state = self::STATE_LOCKED;
        }

        $data = new \stdClass();
        $data->groupid = $this->groupid;
        $data->team = json_encode(array_values($members));
        $data->cases = json_encode($orderedcases);

        $data->id = $DB->insert_record('local_tepuy_gameangi', $data, true);

        return $data;
    }

}
