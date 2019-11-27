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

class SmartCity {

    const SOURCE_DATA = __DIR__ . "/assets/smartcity_data.json";

    const MAX_GAMES = 2;

    const STATE_LOCKED = 'locked';
    const STATE_PASSED = 'passed';
    const STATE_FAILED = 'failed';
    const STATE_ACTIVE = 'active';

    // Only for fullgame state.
    const STATE_ENDED = 'ended';

    private static $_loadeddata = false;

    private static $_data;

    public $groupid;

    public $summary;

    private $_level;

    private $_currentgame;

    private $_currentrunning;

    private $_currentlapse;

    private $_lapses = array();

    public function __construct($groupid) {
        global $DB;

        if (!self::$_loadeddata) {
            self::$_loadeddata = self::loadData();
        }

        if (count(SmartCityLevels::$LEVELS) == 0) {
            SmartCityLevels::initLevels();
        }

        $this->groupid = $groupid;

        $this->summary = $DB->get_record('local_tepuy_gamesmartcity', array('groupid' => $groupid));

        if (!$this->summary) {
            $this->summary = $this->init();
        }

        $this->summary->team = json_decode($this->summary->team);
        $this->summary->games = json_decode($this->summary->games);

        $this->_currentgame = $this->currentGame();

        if ($this->_currentgame) {
            $this->_currentrunning = $this->currentRunning();
            $this->_level = SmartCityLevels::$LEVELS[$this->_currentgame->level];

            $params = array('parentid' => $this->summary->id, 'game' => $this->_currentgame->id);
            $this->_lapses = $DB->get_records('local_tepuy_gamesmartcity_lapses', $params, 'lapse DESC');

            $this->_currentlapse = current($this->_lapses);

            foreach($this->_lapses as $key => $lapse) {
                $this->_lapses[$key]->zones = json_decode($lapse->zones);
            }

        } else {
            $this->_level = SmartCityLevels::$LEVELS[SmartCityLevels::DEFAULT];
        }
    }

    public function currentGame() {

        if ($this->summary->games && is_array($this->summary->games)) {
            foreach($this->summary->games as $game) {
                if ($game->state == self::STATE_ACTIVE) {
                    return $game;
                }
            }
        }

        return null;
    }

    public function currentRunning() {
        global $DB;

        $params = array("parentid" => $this->summary->id,
                        "game" => $this->_currentgame->id);

        $running = $DB->get_record('local_tepuy_gamesmartcity_running', $params);

        if ($running) {
            $running->actions = json_decode($running->actions);
            $running->technologies = json_decode($running->technologies);
            $running->availablefiles = json_decode($running->availablefiles);
        }

        return $running;
    }

    public function endCurrentGame() {
        global $DB;

        $game = $this->currentGame();

        $score = false; //ToDo: calcular y definir estado del juego

        $game->state = $score > 80 ? self::STATE_PASSED : self::STATE_FAILED;

        if ($game->state != self::STATE_ACTIVE) {
            $oneactive = false;

            //Choose the next case.
            foreach($this->summary->games as $localcase) {
                if ($localcase->state == self::STATE_LOCKED) {
                    $localcase->state = self::STATE_ACTIVE;
                    $oneactive = true;
                    break;
                }
            }

            if (!$oneactive) {
                $this->summary->state = self::STATE_ENDED;
            }
        }

        $data = new \stdClass();
        $data->id = $this->summary->id;
        $data->state = $this->summary->state;
        $data->team = json_encode($this->summary->team);
        $data->games = json_encode($this->summary->games);

        $DB->update_record('local_tepuy_gamesmartcity', $data);
    }

    private function init() {
        global $DB, $CFG;

        require_once($CFG->libdir . '/grouplib.php');

        $members = groups_get_members($this->groupid, 'u.id, u.firstname AS name', 'u.id');

        if (!$members || count($members) == 0) {
            throw new AppException(get_string('notmembersingroup', 'local_tepuy', $this->groupid));
        }

        $members = array_values($members);

        $actions = self::$_data->getAssignableActions(count($members));
        $techs = self::$_data->getAssignableTechnologies(count($members));

        $games = array();
        for($i = 1; $i <= self::MAX_GAMES; $i++) {
            $newgame = new \stdClass();
            $newgame->id = $i;
            $newgame->state = self::STATE_LOCKED;
            $newgame->score = 0;
            $newgame->level = SmartCityLevels::DEFAULT;
            $newgame->activities = $actions;
            $newgame->technologies = $techs;
            $games[] = $newgame;
        }

        $data = new \stdClass();
        $data->groupid = $this->groupid;
        $data->team = json_encode($members);
        $data->games = json_encode($games);
        $data->timeframe = 1;

        $data->id = $DB->insert_record('local_tepuy_gamesmartcity', $data, true);

        return $data;
    }

    public function start($level) {
        global $DB, $CFG;

        $current = $this->currentGame();

        if ($current) {
            throw new ByCodeException('errorgamestart');
        }

        if (!SmartCityLevels::isValid($level)) {
            $level = SmartCityLevels::DEFAULT;
        }

        foreach($this->summary->games as $game) {
            if ($game->state == self::STATE_LOCKED) {
                $game->state = self::STATE_ACTIVE;
                $game->level = $level;
                $game->starttime = time();
                break;
            }
        }

        $data = new \stdClass();
        $data->id = $this->summary->id;
        $data->games = json_encode($this->summary->games);

        $DB->update_record('local_tepuy_gamesmartcity', $data);


        // Insert the first lapse with default values.
        $data = new \stdClass();
        $data->parentid = $this->summary->id;
        $data->game = $game->id;
        $data->lapse = 1;

        $data->zones = [];
        $k = 0;
        foreach($this->_level->zones as $value) {
            $k++;
            $zone = new \stdClass();
            $zone->zone = $k;
            $zone->value = $value;
            $data->zones[] = $zone;
        }
        $data->zones = json_encode($data->zones);

        $data->lastmeasured = 0;
        $data->score = $this->_level->score;
        $data->newresources = 0;
        $data->reducer = 0;
        $data->timemodify = time();

        $DB->insert_record('local_tepuy_gamesmartcity_lapses', $data);


        // Insert an empty running record.
        $data = new \stdClass();
        $data->parentid = $this->summary->id;
        $data->game = $game->id;
        $data->actions = "[]";
        $data->technologies = "[]";
        $data->availablefiles = "[]";

        $DB->insert_record('local_tepuy_gamesmartcity_running', $data);


        return true;
    }

    public function getDuedate() {
        // lapses hours * 60min * 60sec / time frame
        $due = ($this->_level->lapses * 60 * 60) / pow(2, ($this->summary->timeframe - 1));
        return time() + $due;
    }

    /**
     *
     * general: [0, 100] Estimated percentage value for game end. When value reach 0 the game ends.
     * details: [ { zone: 1-n, value: 0-100 }]
     * lastmeasured: timestamp.
     * lifetime: timestamp  //Time remaining for the resources or population to end.
     *
     */
    public function getHealth() {

        $res = new \stdClass();

        if(!$this->_currentlapse) {
            return $res;
        }

        $res->general = (int)$this->_currentlapse->score;
        $res->details = $this->_currentlapse->zones;
        $res->lastmeasured = round($this->_currentlapse->lastmeasured * 100 / $this->_level->lapses);

        $remaininglapses = $this->_currentlapse->score * $this->_level->lapses / 100;

        $remainingtime =  ($remaininglapses * 60 * 60) / pow(2, ($this->summary->timeframe - 1));
        $res->lifetime = $this->_currentgame->starttime + $remainingtime;

        return $res;
    }

    /**
     *
     *  available: [id1, id2, xx ],
     *  running: [{id: '', starttime: timestamp }],
     *  resources: [{
     *      type: '(human|physical|energy)'
     *      value: 0-100
     *  }]
     */
    public function getActions($userid) {
        $res = new \stdClass();
        $res->available = array();
        $res->running = array();
        $res->resources = array();

        if(!$this->_currentgame) {
            return $res;
        }

        $pos = 0;
        foreach ($this->summary->team as $member) {
            if ($userid == $member->id) {
                break;
            }
            $pos++;
        }

        $res->available = $this->_currentgame->activities[$pos];

        if ($this->_currentrunning) {
            $res->running = $this->_currentrunning->actions;
            $res->resources = self::$_data->calculateResources($this->_currentrunning->actions, $this->_level->resources);
        }

        return $res;
    }

    public function getTechnologies($userid) {
        $res = new \stdClass();
        $res->available = array();
        $res->running = array();
        $res->resources = array();

        if(!$this->_currentgame) {
            return $res;
        }

        $pos = 0;
        foreach ($this->summary->team as $member) {
            if ($userid == $member->id) {
                break;
            }
            $pos++;
        }

        $res->available = $this->_currentgame->technologies[$pos];

        if ($this->_currentrunning) {
            $res->running = $this->_currentrunning->technologies;
            $res->resources = self::$_data->calculateTechResources($this->_currentrunning->technologies,
                                                                    $this->_level->techresources);
        }

        return $res;
    }

    public function getFiles() {

        if(!$this->_currentrunning) {
            return array();
        }

        return $this->_currentrunning->availablefiles;
    }

    public function getGames() {

        $res = array();
        foreach ($this->summary->games as $game) {
            $one = new \stdClass();
            $one->id = $game->id;
            $one->state = $game->state;
            $one->score = $game->score;
            $one->level = $game->level;
            $res[] = $one;
        }

        return $res;
    }

    private function calcHealth() {

        //ToDo: revisar qué sirve para el cálculo automático.
        $res = new \stdClass();

        $D = 100 / $this->_level->lapses;

        $Ex = 0;
        $zones = $this->_currentlapse->zones;
        foreach($zones as $value) {
            $Ex += $value;
        }

        $RN = $this->_currentlapse->newresources;

        $Nc = count($zones);
        $Fc = ($Ex * 2 * $this->_level->cr) / $Nc;

        // Resources in previous period.
        $prevlapse = $this->getLapse($this->_currentlapse->lapse - 1);
        if ($prevlapse) {
            $R0 = $prevlapse->resources;
        } else {
            $R0 = 0;
        }

        $R = $R0 - $D * $Fc + $RN;

        $measuredlapse = $DB->get_record('local_tepuy_gamesmartcity_lapses', array('lapse' => $lapse->lastmeasured));

        $res->details = json_decode($measuredlapse->zones);
        $res->lastmeasured = round($lapse->lastmeasured * 100 / $this->_level->lapses);

        // Default.
        $remaininglapses = $R / ($D * $Fc);

        $remainingtime =  ($remaininglapses * 60 * 60) / pow(2, ($this->summary->timeframe - 1));
        $res->lifetime = $this->_currentgame->starttime + $remainingtime;

        $res->general = $R;
    }

    private function getLapse($n) {
        foreach($this->_lapses as $lapse) {
            if ($lapse->lapse == $n) {
                return $lapse;
            }
        }

        return null;
    }

    private static function loadData() {
        $json = file_get_contents(self::SOURCE_DATA);
        $data = json_decode($json);

        self::$_data = new SmartCityData($data->actions, $data->technologies, $data->files);

        return true;
    }
}


class SmartCityLevels {

    public static $LEVELS = array();
    public const DEFAULT = 1;

    // Levels data.
    public static function initLevels() {

        self::$LEVELS[0] = new SmartCityLevel();

        $level = new SmartCityLevel();
        $level->level = 1;
        $level->cr = 1.5;
        $level->ea = 10;
        self::$LEVELS[1] = $level;

        $level = new SmartCityLevel();
        $level->level = 2;
        $level->cr = 2;
        $level->ea = 12;
        self::$LEVELS[2] = $level;
    }

    public static function isValid($level) {
        return ($level >= 0 && $level <= 2);
    }
}

class SmartCityLevel {

    public $level = 0;

    public $score = 100;

    public $lapses = 52;

    public $resources = array("human" => 100, "physical" => 100, "energy" => 100);

    public $techresources = array("capacity" => 100);

    // Initial value for zones.
    // Is required really or with $zones is sufficient?
    public $ivz = 50;

    public $zones = array(50, 50, 50, 50, 50, 50);

    // Constant of reality.
    // [1, 1.5, 2] = [easy, medium, hard]
    public $cr = 1;

    // Minimum expected actions.
    public $ea = 7;

}

class SmartCityData {

    public $actions;
    public $actionsbyid;
    public $technologies;
    public $technologiesbyid;
    public $files;

    public function __construct($actions, $technologies, $files) {
        $this->actions = $actions;
        $this->technologies = $technologies;
        $this->files = $files;

        $this->actionsbyid = array();
        foreach($this->actions as $action) {
            $this->actionsbyid[$action->id] = $actions;
        }

        $this->technologiesbyid = array();
        foreach($this->technologies as $tech) {
            $this->technologiesbyid[$tech->id] = $tech;
        }
    }

    public function getAssignableActions($slices) {
        $fullsize = count($this->actions);

        // // Create an array with $fullsize positions. All numbers are evaluated as true in a boolean condition.
        $free = range(1, $fullsize);
        $assignedcount = 0;

        $res = array();

        for ($i = 0; $i < $slices; $i++) {
            $res[$i] = array();
        }

        $iteractions = 0;
        do {
            $iteractions++;
            $pos = rand(0, $fullsize - 1);

            // The value in the random position is a number yet.
            if (!$free[$pos]) {
                continue;
            }

            $into = $assignedcount % $slices;
            $res[$into][] = $this->actions[$pos]->id;
            $free[$pos] = 0;
            $assignedcount++;

        } while($assignedcount < $fullsize && $iteractions < 1000);

        // Assign free activities when randomly was not assigned in minimus interactions.
        foreach($free as $key => $one) {
            if (!$one) {
                continue;
            }

            $into = $assignedcount % $slices;
            $res[$into][] = $this->actions[$key]->id;
            $assignedcount++;
        }

        return $res;
    }

    public function getAssignableTechnologies($slices) {
        $fullsize = count($this->technologies);
        $byone = floor($fullsize / $slices);

        // Create an array with $fullsize positions. All numbers are evaluated as true in a boolean condition.
        $free = range(1, $fullsize);
        $assignedcount = 0;

        $res = array();

        for ($i = 0; $i < $slices; $i++) {
            $res[$i] = array();
        }

        $iteractions = 0;
        do {
            $iteractions++;
            $pos = rand(0, $fullsize - 1);

            // The value in the random position is a number yet.
            if (!$free[$pos]) {
                continue;
            }

            $into = $assignedcount % $slices;
            $res[$into][] = $this->technologies[$pos]->id;
            $free[$pos] = 0;
            $assignedcount++;

        } while($assignedcount < $fullsize && $iteractions < 1000);

        // Assign free technologies when randomly was not assigned in minimus interactions.
        foreach($free as $key => $one) {
            if (!$one) {
                continue;
            }

            $into = $assignedcount % $slices;
            $res[$into][] = $this->technologies[$key]->id;
            $assignedcount++;
        }

        return $res;
    }

    public function calculateResources($activities, $resourcesbylevel) {

        foreach($activities as $activity) {
            $activitydata = $this->actionsbyid[$activity->id];

            foreach($activitydata->resources as $key => $resource) {
                $resourcesbylevel[$resource->type] -= $resource->value;
            }
        }

        return $resourcesbylevel;
    }

    public function calculateTechResources($techs, $resourcesbylevel) {

        foreach($techs as $tech) {
            $techdata = $this->technologiesbyid[$tech->id];

            foreach($techdata->resources as $key => $resource) {
                $resourcesbylevel[$resource->type] -= $resource->value;
            }
        }

        return $resourcesbylevel;
    }

}
