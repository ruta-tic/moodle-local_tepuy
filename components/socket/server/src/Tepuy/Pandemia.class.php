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
 * Main class to game Pandemia
 *
 * @package   local_tepuy
 * @copyright 2019 David Herney - cirano
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Tepuy;

class Pandemia {

    const SOURCE_DATA = __DIR__ . "/assets/pandemia_data.json";

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

    private $_currentrunning;

    private $_currentlapse;

    private $_lapses = array();

    public function __construct($groupid) {
        global $DB;

        if (!self::$_loadeddata) {
            self::$_loadeddata = self::loadData();
        }

        if (count(PandemiaLevels::$LEVELS) == 0) {
            PandemiaLevels::initLevels();
        }

        $this->groupid = $groupid;

        $this->summary = $DB->get_record('local_tepuy_gamepandemia', array('groupid' => $groupid));

        if (!$this->summary) {
            $this->summary = $this->init();
        }

        $this->summary->team = json_decode($this->summary->team);
        $this->summary->games = json_decode($this->summary->games);
        $this->summary->timecontrol = new PandemiaTimecontrol($this->summary->timecontrol);

        $this->_currentgame = $this->currentGame();

        if ($this->_currentgame) {
            $this->_currentrunning = $this->currentRunning();
            $this->_level = PandemiaLevels::$LEVELS[$this->_currentgame->level];

            $params = array('parentid' => $this->summary->id, 'game' => $this->_currentgame->id);
            $this->_lapses = $DB->get_records('local_tepuy_gamepandemia_lapses', $params, 'lapse DESC');

            $this->_currentlapse = current($this->_lapses);

            foreach ($this->_lapses as $key => $lapse) {
                $this->_lapses[$key]->zones = json_decode($lapse->zones);

                // In first lapse the reducer does not exist.
                if ($this->_lapses[$key]->lapse == 0) {
                    $this->_lapses[$key]->reducer = null;
                }
            }

        } else {
            $this->_level = PandemiaLevels::$LEVELS[PandemiaLevels::DEFAULTLEVEL];
        }
    }

    public function currentGame() {

        if ($this->summary->games && is_array($this->summary->games)) {
            foreach ($this->summary->games as $game) {
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

        $running = $DB->get_record('local_tepuy_gamepandemia_running', $params);

        if ($running) {
            $running->actions = json_decode($running->actions);
            $running->technologies = json_decode($running->technologies);
            $running->availablefiles = json_decode($running->availablefiles);
        }

        return $running;
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
            $newgame->level = PandemiaLevels::DEFAULTLEVEL;
            $newgame->actions = $actions;
            $newgame->technologies = $techs;
            $games[] = $newgame;
        }

        $timecontrol = new PandemiaTimecontrol();

        $data = new \stdClass();
        $data->groupid = $this->groupid;
        $data->team = json_encode($members);
        $data->games = json_encode($games);
        $data->timecontrol = json_encode($timecontrol);

        $data->id = $DB->insert_record('local_tepuy_gamepandemia', $data, true);

        return $data;
    }

    public function start($level) {
        global $DB, $CFG;

        $current = $this->currentGame();

        if ($current) {
            throw new ByCodeException('errorgamestart');
        }

        if (!PandemiaLevels::isValid($level)) {
            $level = PandemiaLevels::DEFAULTLEVEL;
        }

        $this->summary->timecontrol = new PandemiaTimecontrol();
        $this->summary->timecontrol->starttime = time();
        $this->summary->timecontrol->lastmeasured = 0;
        $this->summary->timecontrol->lastcalc = $this->summary->timecontrol->starttime;

        foreach ($this->summary->games as $game) {
            if ($game->state == self::STATE_LOCKED) {
                $game->state = self::STATE_ACTIVE;
                $game->level = $level;
                $game->starttime = $this->summary->timecontrol->starttime;
                break;
            }
        }

        $data = new \stdClass();
        $data->id = $this->summary->id;
        $data->games = json_encode($this->summary->games);
        $data->timecontrol = json_encode($this->summary->timecontrol);

        $DB->update_record('local_tepuy_gamepandemia', $data);


        // Insert the first lapse with default values.
        $data = new \stdClass();
        $data->parentid = $this->summary->id;
        $data->game = $game->id;
        $data->lapse = 0;

        $data->zones = [];

        foreach ($this->_level->zones as $key => $value) {
            $zone = new \stdClass();
            $zone->zone = $key;
            $zone->value = $value;
            $data->zones[$key] = $zone;
        }
        $data->zones = json_encode($data->zones);

        $data->score = $this->_level->score;
        $data->newresources = 0;
        $data->reducer = 0;
        $data->timemodify = time();

        $DB->insert_record('local_tepuy_gamepandemia_lapses', $data);


        // Insert an empty running record.
        $data = new \stdClass();
        $data->parentid = $this->summary->id;
        $data->game = $game->id;
        $data->actions = "[]";
        $data->technologies = "[]";
        $data->availablefiles = "[]";

        $DB->insert_record('local_tepuy_gamepandemia_running', $data);


        return true;
    }

    public function getDuedate() {

        $duration = $this->_level->lapses * $this->_level->timelapse;

        $duein1x = $duration - $this->getTimeelapsed();

        $due = PandemiaTimecontrol::time1xToX($duein1x, $this->summary->timecontrol->timeframe);

        return time() + round($due);
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

        $res->general = 100 - (int)$this->_currentlapse->score;

        $res->details = array();

        $lapse = $this->getLapse($this->summary->timecontrol->lastmeasured);
        if (!$lapse) {
            $lapse = $this->_currentlapse;
        }

        foreach ($lapse->zones as $zone) {
            $one = new \stdClass();
            $one->zone = $zone->zone;
            $one->value = 100 - round($zone->value * 100);
            $res->details[] = $one;
        }

        $res->lastmeasured = $this->summary->timecontrol->lastmeasured;

        $lifetime = PandemiaTimecontrol::time1xToX($this->estimateEndScore(), $this->summary->timecontrol->timeframe);

        $res->lifetime = $this->getLifetime();

        return $res;
    }

    public function getLifetime() {

        $lifetime = $this->estimateEndScore() - ($this->getTimeelapsed() % $this->_level->timelapse);

        $lifetime = PandemiaTimecontrol::time1xToX($lifetime, $this->summary->timecontrol->timeframe);

        return round($lifetime);
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
        $userfound = false;
        foreach ($this->summary->team as $member) {
            if ($userid == $member->id) {
                $userfound = true;
                break;
            }
            $pos++;
        }

        if (!$userfound) {
            throw new ByCodeException('usernotintogroup');
        }

        $res->available = $this->_currentgame->actions[$pos];

        if ($this->_currentrunning) {
            $res->running = $this->_currentrunning->actions;
            $res->resources = $this->availableResources();
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
            $res->resources = $this->availableTechResources();
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

    private function getLapse($n) {
        foreach ($this->_lapses as $lapse) {
            if ($lapse->lapse == $n) {
                return $lapse;
            }
        }

        return null;
    }

    public function changeTimeframe($timeframe) {
        global $DB;

        // Currently, only 1x and 5x are supported.
        if ($this->summary->timecontrol->timeframe == $timeframe || ($timeframe != 1 && $timeframe != 5)) {
            return false;
        }

        // Refresh the current time elapsed before change the time frame.
        $this->refreshTimeelapsed();

        $this->summary->timecontrol->timeframe = $timeframe;

        $params = array();
        $params['id'] = $this->summary->id;
        $params['timecontrol'] = json_encode($this->summary->timecontrol);

        return $DB->update_record('local_tepuy_gamepandemia', $params);
    }

    public function playAction($userid, $actid, $parameters) {
        global $DB;

        if (!self::$_data->isValidAction($actid)) {
            Logging::trace(Logging::LVL_DEBUG, 'Invalid action: ' . $actid);
            return false;
        }

        $useracts = $this->getActions($userid);
        if (!in_array($actid, $useracts->available)) {
            Logging::trace(Logging::LVL_DEBUG, 'Not user assigned action: ' . $actid);
            return false;
        }

        // Check if act is running.
        foreach ($useracts->running as $runningact) {
            if ($actid == $runningact->id) {
                Logging::trace(Logging::LVL_DEBUG, 'Action is running: ' . $actid);
                return false;
            }
        }

        if (!$this->checkInFilesAction($actid, $parameters)) {
            Logging::trace(Logging::LVL_DEBUG, 'It does not have the required files to action: ' . $actid);
            throw new ByCodeException('notrequiredfiles');
            return false;
        }

        if (!$this->checkTechnologiesToAction($actid)) {
            Logging::trace(Logging::LVL_DEBUG, 'It does not have the required technologies to action: ' . $actid);
            throw new ByCodeException('notrequiredtechnologies');
            return false;
        }

        if (!$this->hasResourcesToAction($actid)) {
            Logging::trace(Logging::LVL_DEBUG, 'It does not have resources to technology: ' . $actid);
            throw new ByCodeException('notresources');
            return false;
        }

        $runningact = new \stdClass();
        $runningact->id = $actid;
        $runningact->starttime = $this->getTimeelapsed();
        $this->_currentrunning->actions[] = $runningact;

        $params = array();
        $params['id'] = $this->_currentrunning->id;
        $params['actions'] = json_encode($this->_currentrunning->actions);

        $DB->update_record('local_tepuy_gamepandemia_running', $params);

        return $runningact;
    }

    public function stopAction($userid, $actid) {
        global $DB;

        if (!self::$_data->isValidAction($actid)) {
            Logging::trace(Logging::LVL_DEBUG, 'Invalid action: ' . $actid);
            return false;
        }

        $useracts = $this->getActions($userid);
        if (!in_array($actid, $useracts->available)) {
            Logging::trace(Logging::LVL_DEBUG, 'Not user assigned action: ' . $actid);
            return false;
        }

        // Check if act is running.
        $running = false;
        foreach ($this->_currentrunning->actions as $key => $runningact) {
            if ($actid == $runningact->id) {
                unset($this->_currentrunning->actions[$key]);
                $this->_currentrunning->actions = array_values($this->_currentrunning->actions);
                $running = true;
                break;
            }
        }

        if (!$running) {
            Logging::trace(Logging::LVL_DEBUG, 'Action not running: ' . $actid);
            throw new ByCodeException('notrunningaction');
            return false;
        }

        $params = array();
        $params['id'] = $this->_currentrunning->id;
        $params['actions'] = json_encode($this->_currentrunning->actions);

        $DB->update_record('local_tepuy_gamepandemia_running', $params);

        return true;
    }

    public function playTechnology($userid, $techid, $parameters) {
        global $DB;

        if (!self::$_data->isValidTech($techid)) {
            Logging::trace(Logging::LVL_DEBUG, 'Invalid technology: ' . $techid);
            return false;
        }

        $usertechs = $this->getTechnologies($userid);
        if (!in_array($techid, $usertechs->available)) {
            Logging::trace(Logging::LVL_DEBUG, 'Not user assigned technology: ' . $techid);
            return false;
        }

        // Check if the technology is running.
        foreach ($usertechs->running as $runningtech) {
            if ($techid == $runningtech->id) {
                Logging::trace(Logging::LVL_DEBUG, 'Technology is running: ' . $techid);
                return false;
            }
        }

        if (!$this->checkInFilesTech($techid, $parameters)) {
            throw new ByCodeException('notrequiredfiles');
            Logging::trace(Logging::LVL_DEBUG, 'It does not have the required files to technology: ' . $techid);
            return false;
        }

        if (!$this->hasResourcesToTech($techid)) {
            throw new ByCodeException('notresources');
            Logging::trace(Logging::LVL_DEBUG, 'It does not have resources to technology: ' . $techid);
            return false;
        }

        $runningtech = new \stdClass();
        $runningtech->id = $techid;
        $runningtech->starttime = $this->getTimeelapsed();
        $this->_currentrunning->technologies[] = $runningtech;

        $params = array();
        $params['id'] = $this->_currentrunning->id;
        $params['technologies'] = json_encode($this->_currentrunning->technologies);

        $DB->update_record('local_tepuy_gamepandemia_running', $params);

        return $runningtech;
    }

    public function stopTechnology($userid, $techid) {
        global $DB;

        if (!self::$_data->isValidTech($techid)) {
            Logging::trace(Logging::LVL_DEBUG, 'Invalid technology: ' . $techid);
            return false;
        }

        $usertechs = $this->getTechnologies($userid);
        if (!in_array($techid, $usertechs->available)) {
            Logging::trace(Logging::LVL_DEBUG, 'Not user assigned technology: ' . $techid);
            return false;
        }

        // Check if technology is running.
        $running = false;
        foreach ($this->_currentrunning->technologies as $key => $runningtech) {
            if ($techid == $runningtech->id) {
                unset($this->_currentrunning->technologies[$key]);
                $this->_currentrunning->technologies = array_values($this->_currentrunning->technologies);
                $running = true;
                break;
            }
        }

        if (!$running) {
            Logging::trace(Logging::LVL_DEBUG, 'Technology not running: ' . $techid);
            throw new ByCodeException('notrunningtech');
            return false;
        }

        if ($this->checkTechnologyToRunningAction($techid)) {
            Logging::trace(Logging::LVL_DEBUG, 'Technology is required by a running action: ' . $techid);
            throw new ByCodeException('techrunningrequired');
            return false;
        }

        $params = array();
        $params['id'] = $this->_currentrunning->id;
        $params['technologies'] = json_encode($this->_currentrunning->technologies);

        $DB->update_record('local_tepuy_gamepandemia_running', $params);

        return true;
    }

    public function gameover() {

        $goodend = $this->_level->timelapse * $this->_level->lapses;

        $end = $this->getTimeelapsed() + $this->getLifetime();

        $result = $end < $goodend ? self::STATE_FAILED : self::STATE_PASSED;

        $this->closeGame($result);

        $endlapse = floor($end / $this->_level->timelapse);

        $res = new \stdclass();
        $res->reason = $result;
        $res->endlapse = $endlapse;

        return $res;
    }

    public function closeGame($result) {
        global $DB;

        $current = $this->currentGame();

        if (!$current) {
            return false;
        }

        $current->state = $result;

        $available = false;
        foreach($this->summary->games as $game) {
            if ($game->state == self::STATE_LOCKED) {
                $available = true;
                break;
            }
        }

        $params = array();
        $params['id'] = $this->summary->id;

        // If not more games available.
        if (!$available) {
            $params['state'] = self::STATE_ENDED;
        }

        $params['games'] = json_encode($this->summary->games);

        return $DB->update_record('local_tepuy_gamepandemia', $params);

    }

    public function availableResources() {
        return self::$_data->calculateResources($this->_currentrunning->actions, $this->_level->resources);
    }

    public function availableTechResources() {
        return self::$_data->calculateTechResources($this->_currentrunning->technologies,
                                                                    $this->_level->techresources);
    }

    public function hasResourcesToTech($techid) {
        $tech = self::$_data->technologiesbyid[$techid];
        $resources = $this->availableResources();

        foreach ($tech->resources as $required) {

            foreach ($resources as $key => $resource) {
                if ($resource->type == $required->type) {
                    if ($resource->value < $required->value) {
                        return false;
                    }
                    break;
                }
            }
        }

        return true;
    }

    public function hasResourcesToAction($actid) {
        $actdata = self::$_data->actionsbyid[$actid];
        $resources = $this->availableResources();

        foreach ($actdata->resources as $required) {

            foreach ($resources as $key => $resource) {
                if ($resource->type == $required->type) {
                    if ($resource->value < $required->value) {
                        return false;
                    }
                    break;
                }
            }
        }

        return true;
    }

    public function checkInFilesTech($techid, $params) {

        // $params is not used into server side validation. The validation is with existing files.

        $tech = self::$_data->technologiesbyid[$techid];
        $currentfiles = $this->getFiles();

        $arrayfiles = array();
        foreach($currentfiles as $file) {
            $arrayfiles[] = $file->id;
        }

        foreach ($tech->files->in as $file) {
            if (!in_array($file, $arrayfiles)) {
                return false;
            }
        }

        return true;
    }

    public function checkInFilesAction($actid, $params) {

        // $params is not used into server side validation. The current validation is with existing files.

        $act = self::$_data->actionsbyid[$actid];
        $currentfiles = $this->getFiles();

        $arrayfiles = array();
        foreach($currentfiles as $file) {
            $arrayfiles[] = $file->id;
        }

        foreach ($act->files as $file) {
            if (!in_array($file, $arrayfiles)) {
                return false;
            }
        }

        return true;
    }

    public function checkTechnologiesToAction($actid) {

        $act = self::$_data->actionsbyid[$actid];
        $runningtechs = $this->_currentrunning->technologies;

        if (count($act->technologies) == 0) {
            return true;
        }

        $available = false;
        foreach ($act->technologies as $tech) {
            foreach ($runningtechs as $running) {
                if ($running->id == $tech) {
                    $available = true;
                    break;
                }
            }
        }

        if (!$available) {
            return false;
        }

        return true;
    }

    /**
     *
     * Check if the technology is required by some running action.
     *
     * @param $techid string the technology to check
     * @return bool true if is required, false in another case.
     */
    public function checkTechnologyToRunningAction($techid) {

        $runningacts = $this->_currentrunning->actions;

        foreach ($runningacts as $runningact) {
            $act = self::$_data->actionsbyid[$runningact->id];

            foreach ($act->technologies as $tech) {
                if ($tech->id == $techid) {
                    return true;
                }
            }
        }

        return false;
    }

    public function cron($cronparent) {
        global $DB;

        if ($this->summary->timecontrol->starttime == 0 || !$this->_currentlapse) {
            return;
        }

        $estimatelapse = $this->estimateCurrentLapse();

        $changehealth = false;
        while ($this->_currentlapse->lapse < $estimatelapse && $this->_currentlapse->lapse < $this->_level->lapses) {

            // The actions are processed and the new lapse is created.
            $newlapse = new \stdClass();
            $newlapse->parentid = $this->_currentlapse->parentid;
            $newlapse->game = $this->_currentlapse->game;
            $newlapse->lapse = $this->_currentlapse->lapse + 1;

            // Initialize lapse profit array.
            $profit = array();
            foreach ($this->_level->zones as $key => $value) {
                $profit[$key] = 0;
            }

            $newresources = 0;
            $numberactions = 0;
            foreach ($this->_currentrunning->actions as $key => $action) {
                $ending = self::$_data->isExpiredAction($action, $this->getTimeelapsed(), $this->_level->timelapse);
                $numberactions++;

                // Nothing to do.
                if (!$ending) {
                    continue;
                }

                $changehealth = true;

                $actdata = self::$_data->actionsbyid[$action->id];
                foreach ($actdata->zones as $m => $value) {
                    $profit[$m] += ($value / 100);
                }

                $newresources += $actdata->newresources;

                if ($actdata->endmode == 'auto') {
                    unset($this->_currentrunning->actions[$key]);

                    $requestdata = array(
                            'id' => $action->id,
                            'resources' => $this->availableResources(),
                            'groupid' => $this->groupid,
                            'name' => $actdata->name
                        );
                    $cronparent->cron_actioncompleted((object)$requestdata);
                }
            }

            $newlapse->newresources = $newresources;

            $newlapse->score = $this->calcResources($newlapse->newresources,
                                                    $newlapse->lapse,
                                                    $profit,
                                                    $this->_currentlapse->reducer,
                                                    $numberactions);

            $newlapse->reducer = $this->calcDiscontent($this->_currentlapse->reducer, $numberactions);

            foreach ($this->_currentlapse->zones as $zone) {
                $value = $profit[$zone->zone];
                $value = $zone->value + $value - ($newlapse->reducer - $this->_currentlapse->reducer);
                $value = $value < 0 ? 0 : ($value > 1 ? 1 : $value);
                $zone->value = round($value, 2);
            }

            $newlapse->zones = $this->_currentlapse->zones;
            $newlapse->timemodify = time();

            $data = new \stdClass();
            $data->parentid = $newlapse->parentid;
            $data->game = $newlapse->game;
            $data->lapse = $newlapse->lapse;
            $data->zones = json_encode($newlapse->zones);
            $data->score = $newlapse->score;
            $data->newresources = $newlapse->newresources;
            $data->reducer = $newlapse->reducer;
            $data->timemodify = $newlapse->timemodify;

            // Insert the calculated lapse.
            $newlapse->id = $DB->insert_record('local_tepuy_gamepandemia_lapses', $data, true);
            $this->_currentlapse = $newlapse;
            $this->_lapses[] = $newlapse;


            // The technologies are processed.
            foreach ($this->_currentrunning->technologies as $key => $tech) {
                $ending = self::$_data->isExpiredTechnology($tech, $this->getTimeelapsed(), $this->_level->timelapse);

                // Nothing to do.
                if (!$ending) {
                    continue;
                }

                if ($tech->id == 't24') {
                    $this->summary->timecontrol->lastmeasured = $newlapse->lapse;

                    $params = array();
                    $params['id'] = $this->_currentrunning->id;
                    $params['timecontrol'] = json_encode($this->summary->timecontrol);
                    $DB->update_record('local_tepuy_gamepandemia', $params);

                    $requestdata = $this->getHealth();
                    $requestdata->groupid = $this->groupid;
                    $cronparent->cron_healthupdate($requestdata);
                }

                $techdata = self::$_data->technologiesbyid[$tech->id];
                foreach ($techdata->files->out as $fileid) {
                    $newfile = new \stdClass();
                    $newfile->id = $fileid;
                    $newfile->creationtime = time();
                    $this->_currentrunning->availablefiles[] = $newfile;
                }

                if ($techdata->endmode == 'auto') {
                    unset($this->_currentrunning->technologies[$key]);

                    $requestdata = array(
                            'id' => $tech->id,
                            'resources' => $this->availableTechResources(),
                            'files' => $this->_currentrunning->availablefiles,
                            'groupid' => $this->groupid,
                            'name' => $techdata->name
                        );
                    $cronparent->cron_technologycompleted((object)$requestdata);
                }
            }


            $requestdata = array(
                    'score' => round($newlapse->score),
                    'lapse' => $newlapse->lapse,
                    'lifetime' => $this->getLifetime(),
                    'groupid' => $this->groupid
                );
            $cronparent->cron_lapsechanged((object)$requestdata);

            // Check ending reasons.
            if ($newlapse->score <= 0) {
                $this->closeGame(self::STATE_FAILED);

                $requestdata = array(
                        'endlapse' => $newlapse->lapse,
                        'reason' => self::STATE_FAILED,
                        'groupid' => $this->groupid
                    );
                $cronparent->cron_autogameover((object)$requestdata);

                // Gameover - End the cicle.
                break;

            } else if ($this->_currentlapse->lapse == $this->_level->lapses) {
                $this->closeGame(self::STATE_PASSED);

                $requestdata = array(
                        'reason' => self::STATE_PASSED,
                        'groupid' => $this->groupid
                    );
                $cronparent->cron_autogameover((object)$requestdata);

                // Gameover - End the cicle.
                break;
            }

        }

        $this->_currentrunning->actions = array_values($this->_currentrunning->actions);
        $this->_currentrunning->technologies = array_values($this->_currentrunning->technologies);

        $params = array();
        $params['id'] = $this->_currentrunning->id;
        $params['actions'] = json_encode($this->_currentrunning->actions);
        $params['technologies'] = json_encode($this->_currentrunning->technologies);
        $params['availablefiles'] = json_encode($this->_currentrunning->availablefiles);

        $DB->update_record('local_tepuy_gamepandemia_running', $params);

    }

    public static function getMatches() {
        global $DB;

        return $DB->get_records('local_tepuy_gamepandemia', null, '', 'id, groupid, state');
    }

    private function refreshTimeelapsed() {
        global $DB;

        if ($this->summary->timecontrol->starttime == 0) {
            return true;
        }

        $calctime = time();

        //ToDo: validar el período en que se requiere recalcular
        // Only recalculate after 5 seconds.
         if (($calctime - $this->summary->timecontrol->lastcalc) < 5) {
             return true;
         }

        $new = ($calctime - $this->summary->timecontrol->lastcalc);
        $new = $this->summary->timecontrol->timeelapsed +
                    PandemiaTimecontrol::timeXTo1x($new, $this->summary->timecontrol->timeframe);

        $this->summary->timecontrol->timeelapsed = $new;
        $this->summary->timecontrol->lastcalc = $calctime;

        $params = array();
        $params['id'] = $this->summary->id;
        $params['timecontrol'] = json_encode($this->summary->timecontrol);

        return $DB->update_record('local_tepuy_gamepandemia', $params);
    }

    public function getTimeelapsed() {
        $this->refreshTimeelapsed();
        return $this->summary->timecontrol->timeelapsed;
    }

    /*
     * Return the seconds remaining to ending the global resources.
     * The value is in timeframe rate.
     *
     */
    private function estimateEndScore() {

        if (!$this->_currentrunning) {
            return 0;
        }

        $R = $this->_currentlapse->score;

        // $D is the depreciation value.
        $D = 100 / $this->_level->lapses;

        $profit = array();
        foreach ($this->_level->zones as $key => $value) {
            $profit[$key] = 0;
        }

        $numberactions = 0;
        foreach ($this->_currentrunning->actions as $key => $action) {
            $ending = self::$_data->isExpiredAction($action, $this->getTimeelapsed(), $this->_level->timelapse);
            $numberactions++;

            // Nothing to do.
            if (!$ending) {
                continue;
            }

            $actdata = self::$_data->actionsbyid[$action->id];
            foreach ($actdata->zones as $m => $value) {
                $profit[$m] += $value;
            }
        }

        $end = 0;
        $DF = $this->_currentlapse->reducer;

        while ($R > 0 && $end < ($this->_level->lapses * 2)) {
            // $FC is the consumption factor.
            $FC = $this->getConsumptionFactor($profit, $DF, $numberactions);

            $R -= ($D * $FC);

            $DF = $this->calcDiscontent($DF, $numberactions);
            $end++;
        }

        // ($D * $FC) is the current monthly consum.
        $end = $end * $this->_level->timelapse;

        return $end;
    }

    /**
     * Calculation of resources for the specific lapse.
     *
     * @param int $NR new resources.
     * @param int $l the lapse to calculate resources.
     * @param float $DFp discontent factor on previous lapse
     * @param array $P profit by actions on current lapse.
     * @param int $NA number of played actions.
     * @return int resources for the lapse $l.
     *
     */
    private function calcResources($NR, $l, $P, $DFp, $NA) {
// //          echo "P: ";
// //          var_dump($P);
// //          echo "\n";

        // Resources in previous period.
        $prevlapse = $this->getLapse($l - 1);

        // $D is the depreciation value.
        $D = 100 / $this->_level->lapses;
// //          echo "D: ";
// //          var_dump($D);
// //          echo "\n";
// //
// //          echo "DFp: ";
// //          var_dump($DFp);
// //          echo "\n";

        $FC = $this->getConsumptionFactor($P, $DFp, $NA);
// //          echo "FC: ";
// //          var_dump($FC);
// //          echo "\n";


        if ($prevlapse) {
            $R0 = $prevlapse->score;
        } else {
            $R0 = $this->_level->score;
        }

        $R = $R0 - $D * $FC + $NR;
// //          echo "R: ";
// //          var_dump($R);
// //          echo "\n";

        return $R;

    }

    private function getConsumptionFactor($P, $DFp, $NA) {
        $Ex = 0;

        $DFp = $DFp === null ? 0.1 : $DFp;
// //          echo "DF: ";
// //          var_dump($DFp);
// //          echo "\n";

        foreach ($P as $key => $value) {

            $improvement = $this->_level->zones[$key] + $value;
            $maximprovement = ($improvement > 1 ? 1 : $improvement);

            $Bx = ($maximprovement < 0 ? 0 : $maximprovement) - $DFp;
// //             echo $Bx . '*';
            $Ex += 1 - $Bx;
        }
// //          echo "Ex: ";
// //          var_dump($Ex);
// //          echo "\n";

        $NC = count($P);
        return ($Ex * $this->_level->cr) / $NC;
    }

    /**
     * Calculation of next discontent factor.
     *
     * @param int $NR new resources.
     * @param int $l the lapse to calculate resources.
     * @param float $DFp discontent factor on previous lapse
     * @param array $P profit by actions on current lapse.
     * @param int $NA number of played actions.
     * @return int resources for the lapse $l.
     *
     */
    private function calcDiscontent($DFp, $NA) {

        $minactions = 1 - ($NA / $this->_level->ea);
        $minactions = $minactions < 0 ? 0 : $minactions / $this->_level->ds;

// //         echo "Descontento: ";
// //         var_dump($minactions);
// //          echo "\n";
        return $DFp + $minactions;
    }

    public function getGameDuration() {

        return $this->_level->lapses * $this->_level->timelapse;
    }

    public function estimateCurrentLapse() {
        return Floor($this->getTimeelapsed() / $this->_level->timelapse);
    }

    public function getCurrentLapse() {
        return (int)$this->_currentlapse->lapse;
    }

    public function getTimelapse() {
        if (!$this->_level) {
            return 0;
        }

        return $this->_level->timelapse;
    }

    public function getLapses() {
        if (!$this->_level) {
            return 0;
        }

        return $this->_level->lapses;
    }


    private static function loadData() {
        $json = file_get_contents(self::SOURCE_DATA);
        $data = json_decode($json);

        self::$_data = new PandemiaData($data->actions, $data->technologies, $data->files);
        Logging::trace(Logging::LVL_DETAIL, 'Data for Pandemia game loaded.');

        return true;
    }
}


class PandemiaLevels {

    public static $LEVELS = array();
    const DEFAULTLEVEL = 1;

    // Levels data.
    public static function initLevels() {

        self::$LEVELS[0] = new PandemiaLevel();

        $level = new PandemiaLevel();
        $level->level = 1;
        $level->cr = 4;
        $level->ea = 6;
        self::$LEVELS[1] = $level;

        $level = new PandemiaLevel();
        $level->level = 2;
        $level->cr = 5;
        $level->ea = 7;
        self::$LEVELS[2] = $level;
    }

    public static function isValid($level) {
        return ($level >= 0 && $level <= 2);
    }
}

class PandemiaLevel {

    public $level = 0;

    public $score = 89;

    public $lapses = 48;

    public $timelapse = 3600;

    public $resources = array("human" => 100, "physical" => 100, "energy" => 100);

    public $techresources = array("capacity" => 100);

    public $zones = array(0.74, 0.9, 0.8, 0.8, 0.9, 0.77);

    // Constant of reality.
    // [3, 4, 5] = [easy, medium, hard]
    public $cr = 3;

    // Minimum expected actions.
    public $ea = 4;

    // Depreciation softener.
    public $ds = 10;

}

class PandemiaData {

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
        foreach ($this->actions as $action) {
            $this->actionsbyid[$action->id] = $action;
        }

        $this->technologiesbyid = array();
        foreach ($this->technologies as $tech) {
            $this->technologiesbyid[$tech->id] = $tech;
        }
    }

    public function getAssignableActions($slices) {
        $fullsize = count($this->actions);

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
            $res[$into][] = $this->actions[$pos]->id;
            $free[$pos] = 0;
            $assignedcount++;

        } while($assignedcount < $fullsize && $iteractions < 1000);

        // Assign free actions when randomly was not assigned in minimus interactions.
        foreach ($free as $key => $one) {
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
        foreach ($free as $key => $one) {
            if (!$one) {
                continue;
            }

            $into = $assignedcount % $slices;
            $res[$into][] = $this->technologies[$key]->id;
            $assignedcount++;
        }

        return $res;
    }

    public function calculateResources($actions, $resourcesbylevel) {

        foreach ($actions as $action) {
            $actiondata = $this->actionsbyid[$action->id];

            foreach ($actiondata->resources as $key => $resource) {
                $resourcesbylevel[$resource->type] -= $resource->value;
            }
        }

        $res = array();
        foreach ($resourcesbylevel as $key => $value) {
            $one = new \stdClass();
            $one->type = $key;
            $one->value = $value;
            $res[] = $one;
        }

        return $res;
    }

    public function calculateTechResources($techs, $resourcesbylevel) {

        foreach ($techs as $tech) {
            $techdata = $this->technologiesbyid[$tech->id];

            foreach ($techdata->resources as $key => $resource) {
                $resourcesbylevel[$resource->type] -= $resource->value;
            }
        }

        $res = array();
        foreach ($resourcesbylevel as $key => $value) {
            $one = new \stdClass();
            $one->type = $key;
            $one->value = $value;
            $res[] = $one;
        }

        return $res;
    }

    public function isValidAction($actid) {
        return isset($this->actionsbyid[$actid]);
    }

    public function isValidTech($techid) {
        return isset($this->technologiesbyid[$techid]);
    }

    public function isExpiredAction($action, $timeelapsed, $timelapse) {
        $actdata = $this->actionsbyid[$action->id];

        $endtime = $action->starttime + $actdata->endtime * $timelapse;

        return $endtime < $timeelapsed;
    }

    public function isExpiredTechnology($tech, $timeelapsed, $timelapse) {
        $techdata = $this->technologiesbyid[$tech->id];

        $endtime = $tech->starttime + $techdata->endtime * $timelapse;

        return $endtime < $timeelapsed;
    }
}

class PandemiaTimecontrol {

    public $timeframe = 1;

    public $starttime = 0;

    public $timeelapsed = 0;

    public $lastmeasured = 0;

    // Last time when the elapsed was saved.
    public $lastcalc = 0;

    public function __construct($jsondata = '') {

        if (!empty($jsondata)) {
            $data = json_decode($jsondata);

            foreach ($data as $field => $value) {
                $this->$field = $value;
            }
        }
    }

    public static function time1xToX($time, $x) {
        return $time / pow(2, ($x - 1));
    }

    public static function timeXTo1x($time, $x) {
        return $time * pow(2, ($x - 1));
    }

}
