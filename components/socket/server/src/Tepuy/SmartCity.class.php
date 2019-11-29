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
        $this->summary->timecontrol = json_decode($this->summary->timecontrol);

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
            $newgame->actions = $actions;
            $newgame->technologies = $techs;
            $games[] = $newgame;
        }

        $timecontrol = new SmartCityTimecontrol();

        $data = new \stdClass();
        $data->groupid = $this->groupid;
        $data->team = json_encode($members);
        $data->games = json_encode($games);
        $data->timecontrol = json_encode($timecontrol);

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

        $this->summary->timecontrol = new SmartCityTimecontrol();
        $this->summary->timecontrol->starttime = time();

        foreach($this->summary->games as $game) {
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

        $DB->update_record('local_tepuy_gamesmartcity', $data);


        // Insert the first lapse with default values.
        $data = new \stdClass();
        $data->parentid = $this->summary->id;
        $data->game = $game->id;
        $data->lapse = 0;

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

        $duration = $this->_level->lapses * $this->_level->timelapse;

        $duein1x = $duration - $this->getTimeelapsed();

        $due = SmartCityTimecontrol::time1xToX($duein1x, $this->summary->timecontrol->timeframe);

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
        $res->lastmeasured = round($this->_current->timecontrol->lastmeasured * 100 / $this->_level->lapses);
        $res->lifetime = time() + $this->estimateEndScore();

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
        foreach($this->_lapses as $lapse) {
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
        $params['timecontrol'] = $this->summary->timecontrol;

        return $DB->update_record('local_tepuy_gamesmartcity', $params);
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
        foreach($useracts->running as $runningact) {
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
        $runningact->starttime = time();
        $this->_currentrunning->actions[] = $runningact;

        $params = array();
        $params['id'] = $this->_currentrunning->id;
        $params['actions'] = json_encode($this->_currentrunning->actions);

        $DB->update_record('local_tepuy_gamesmartcity_running', $params);

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
        foreach($this->_currentrunning->actions as $key => $runningact) {
            if ($actid == $runningact->id) {
                unset($this->_currentrunning->actions[$key]);
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

        $DB->update_record('local_tepuy_gamesmartcity_running', $params);

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
        foreach($usertechs->running as $runningtech) {
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
        $runningtech->starttime = time();
        $this->_currentrunning->technologies[] = $runningtech;

        $params = array();
        $params['id'] = $this->_currentrunning->id;
        $params['technologies'] = json_encode($this->_currentrunning->technologies);

        $DB->update_record('local_tepuy_gamesmartcity_running', $params);

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
        foreach($this->_currentrunning->technologies as $key => $runningtech) {
            if ($techid == $runningtech->id) {
                unset($this->_currentrunning->technologies[$key]);
                $running = true;
                break;
            }
        }

        if (!$running) {
            Logging::trace(Logging::LVL_DEBUG, 'Technology not running: ' . $techid);
            throw new ByCodeException('notrunningtech');
            return false;
        }

        $params = array();
        $params['id'] = $this->_currentrunning->id;
        $params['technologies'] = json_encode($this->_currentrunning->technologies);

        $DB->update_record('local_tepuy_gamesmartcity_running', $params);

        return true;
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

        foreach($tech->resources as $required) {

            foreach($resources as $key => $resource) {
                if ($key == $required->type) {
                    if ($resource < $required->value) {
                        return false;
                    }
                    break;
                }
            }
        }

        return true;
    }

    public function hasResourcesToAction($actid) {
        $act = self::$_data->actionsbyid[$actid];
        $resources = $this->availableResources();

        foreach($act->resources as $required) {

            foreach($resources as $key => $resource) {
                if ($key == $required->type) {
                    if ($resource < $required->value) {
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
        $files = $this->getFiles();

        foreach($tech->files->in as $file) {
            if (!in_array($file, $files)) {
                return false;
            }
        }

        return true;
    }

    public function checkInFilesAction($actid, $params) {

        // $params is not used into server side validation. The current validation is with existing files.

        $act = self::$_data->actionsbyid[$actid];
        $currentfiles = $this->getFiles();

        foreach($act->files as $file) {
            if (!in_array($file, $currentfiles)) {
                return false;
            }
        }

        return true;
    }

    public function checkTechnologiesToAction($actid) {

        $act = self::$_data->actionsbyid[$actid];
        $runningtechs = $this->_currentrunning->technologies;

        foreach($act->technologies as $tech) {
            $available = false;
            foreach($runningtechs as $running) {
                if ($running->id == $tech) {
                    $available = true;
                    break;
                }
            }

            if (!$available) {
                return false;
            }
        }

        return true;
    }

    public function cron($actionparent) {

        $estimatelapse = $this->estimateCurrentLapse();

        while ($this->_currentlapse->lapse < $estimatelapse) {

            // The actions are processed and the new lapse is created.
            $newlapse = new \stdClass();
            $newlapse->parentid = $this->_currentlapse->parentid;
            $newlapse->game = $this->_currentlapse->game;
            $newlapse->lapse = $this->_currentlapse->lapse + 1;

            // Initialize lapse profit array.
            $profit = array();
            foreach ($this->_level->zones as $key => $zone) {
                $profit[$key] = 0;
            }

            $newresources = 0;
            $numberactions = 0;
            foreach ($this->_currentrunning->actions as $key => $action) {
                $ending = SmartCityData::isExpiredAction($action);
                $numberactions++;

                // Nothing to do.
                if (!$ending) {
                    continue;
                }

                foreach ($action->zones as $m => $value) {
                    $this->_currentlapse->zones->$m += $value;
                    $profit[$m] += $value;
                }

                $newresources += $action->newresources;

                if ($action->endmode == 'auto') {
                    unset($this->_currentrunning->actions[$key]);

                    $requestdata = array(
                            'id' => $action->id,
                            'resources' => $this->availableResources(),
                            'groupid' => $this->groupid;
                            'name' => $action->name;
                        );
                    $cronparent->sc_actioncompleted((object)$requestdata);
                }
            }
            $newlapse->zones = $this->_currentlapse->zones;
            $newlapse->newresources = $newresources;

            $newlapse->score = $this->calcResources($newlapse->newresources,
                                                    $newlapse->lapse,
                                                    $profit,
                                                    $this->_currentlapse->reducer,
                                                    $numberactions);

            $newlapse->reducer = $this->calcDepreciation($this->_currentlapse->reducer, $numberactions);
            $newlapse->timemodify = time();

            $data = clone($newlapse);
            $data->zones = json_encode($data->zones);

            // Insert the calculated lapse.
            $newlapse->id = $DB->insert_record('local_tepuy_gamesmartcity_lapses', $data, true);
            $this->_currentlapse = $newlapse;


            // The technologies are processed.
            foreach ($this->_currentrunning->technologies as $key => $tech) {
                $ending = SmartCityData::isExpiredTechnology($tech);

                // Nothing to do.
                if (!$ending) {
                    continue;
                }

                foreach ($tech->files->out as $fileid) {
                    $newfile = new \stdClass();
                    $newfile->id = $fileid;
                    $newfile->creationtime = time();
                    $this->_currentrunning->availablefiles[] = $file;
                }

                if ($tech->endmode == 'auto') {
                    unset($this->_currentrunning->technologies[$key]);

                    $requestdata = array(
                            'id' => $tech->id,
                            'resources' => $this->availableTechResources(),
                            'files' => $this->_currentrunning->availablefiles,
                            'groupid' => $this->groupid;
                            'name' => $tech->name;
                        );
                    $cronparent->sc_technologycompleted((object)$requestdata);
                }
            }
        }

        $params = array();
        $params['id'] = $this->_currentrunning->id;
        $params['actions'] = json_encode($this->_currentrunning->actions);
        $params['technologies'] = json_encode($this->_currentrunning->technologies);
        $params['availablefiles'] = json_encode($this->_currentrunning->availablefiles);

        $DB->update_record('local_tepuy_gamesmartcity_running', $params);

    }

    public static function getMatches() {
        global $DB;

        return $DB->get_records('local_tepuy_gamesmartcity', null, '', 'id, groupid, state');
    }

    private function refreshTimeelapsed() {

        $calctime = time();

        // Only recalculate after one minute.
        if ($calctime - $this->summary->timecontrol->lastcalc > 60) {
            return true;
        }

        $new = ($calctime - $this->summary->timecontrol->lastcalc);
        $new = $this->summary->timecontrol->timeelapsed +
                    SmartCityTimecontrol::timeXTo1x($new, $this->summary->timecontrol->timeframe);

        $this->summary->timecontrol->timeelapsed = $new;
        $this->summary->timecontrol->lastcalc = $calctime;

        $params = array();
        $params['id'] = $this->summary->id;
        $params['timecontrol'] = $this->summary->timecontrol;

        return $DB->update_record('local_tepuy_gamesmartcity', $params);
    }

    /*
     * Return the seconds remaining to ending the global resources.
     * The value is in timeframe rate.
     *
     */
    private function estimateEndScore() {

        $R = $this->_currentlapse->score;

        // ($D * $Fc) is the current monthly consum.
        $end = $R / ($D * $Fc);

        return SmartCityTimecontrol::time1xToX($end, $this->summary->timecontrol->timeframe);
    }

    /**
     * Calculation of resources for the specific lapse.
     *
     * @param int $NR new resources.
     * @param int $l the lapse to calculate resources.
     * @param float $DFp depreciation factor on previous lapse
     * @param array $P profit by actions on current lapse.
     * @param int $NA number of played actions.
     * @return int resources for the lapse $l.
     *
     */
    private function calcResources($NR, $l, $P, $DFp, $NA) {

        // Resources in previous period.
        $prevlapse = $this->getLapse($l - 1);

        $D = 100 / $this->_level->lapses;

        $Ex = 0;

        foreach($P as $key => $value) {

            $improvement = $this->_level->zones[$key] + $value/100;
            $maximprovement = ($improvement > 1 ? 1 : $improvement);

            $DF = $this->calcDepreciation($DFp, $NA);
            $Bx = ($maximprovement < 0 ? 0 : $maximprovement) - $DF;
            $Ex += 1 - $Bx;
        }

        $Nc = count($P);
        $Fc = ($Ex * $this->_level->cr) / $Nc;

        if ($prevlapse) {
            $R0 = $prevlapse->resources;
        } else {
            $R0 = $this->_level->score;
        }

        $R = $R0 - $D * $Fc + $NR;

        return $R;

    }

    /**
     * Calculation of next depreciation.
     *
     * @param int $NR new resources.
     * @param int $l the lapse to calculate resources.
     * @param float $DFp depreciation factor on previous lapse
     * @param array $P profit by actions on current lapse.
     * @param int $NA number of played actions.
     * @return int resources for the lapse $l.
     *
     */
    private function calcDepreciation($DFp, $NA) {

        $minactions = 1 - $NA / $this->_level->ea;
        $minactions = $minactions < 0 ? 0 : $minactions / $this->_level->ds;

        return $DFp + $minactions;
    }

    public function getTimeelapsed() {
        $this->refreshTimeelapsed();
        return $this->summary->timecontrol->timeelapsed;
    }

    public function getGameDuration() {

        return $this->_level->lapses * $this->_level->timelapse;
    }

    public function estimateCurrentLapse() {
        $this->refreshTimeelapsed();

        return Floor($this->summary->timecontrol->timeelapsed / $this->_lapses->timelapse());
    }

    private static function loadData() {
        $json = file_get_contents(self::SOURCE_DATA);
        $data = json_decode($json);

        self::$_data = new SmartCityData($data->actions, $data->technologies, $data->files);
        Logging::trace(Logging::LVL_DETAIL, 'Data for SmartCity game loaded.');

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
        $level->cr = 3;
        $level->ea = 10;
        self::$LEVELS[1] = $level;

        $level = new SmartCityLevel();
        $level->level = 2;
        $level->cr = 4;
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

    public $timelapse = 3600;

    public $resources = array("human" => 100, "physical" => 100, "energy" => 100);

    public $techresources = array("capacity" => 100);

    // Initial value for zones.
    // Is required really or with $zones is sufficient?
    public $ivz = 50;

    public $zones = array(50, 50, 50, 50, 50, 50);

    // Constant of reality.
    // [2, 3, 4] = [easy, medium, hard]
    public $cr = 2;

    // Minimum expected actions.
    public $ea = 7;

    // Depreciation softener
    public $ds = 10;

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
            $this->actionsbyid[$action->id] = $action;
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

        // Assign free actions when randomly was not assigned in minimus interactions.
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

    public function calculateResources($actions, $resourcesbylevel) {

        foreach($actions as $action) {
            $actiondata = $this->actionsbyid[$action->id];

            foreach($actiondata->resources as $key => $resource) {
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

    public function isValidAction($actid) {
        return isset($this->actionsbyid[$actid]);
    }

    public function isValidTech($techid) {
        return isset($this->technologiesbyid[$techid]);
    }

}

class SmartCityTimecontrol {

    public $timeframe = 1;

    public $starttime = 0;

    public $timeelapsed = 0;

    public $lastcalc = 0;

    public $lastmeasured = 0;

    public function __construct($jsondata = '') {

        if (!empty($jsondata)) {
            $data = json_decode($jsondata);

            foreach($data as $field => $value) {
                $this->$field = $value;
            }
        }
    }

    public static function time1xToX($x, $time) {
        return $time / pow(2, ($x - 1));
    }

    public static function timeXTo1x($x, $time) {
        return $time * pow(2, ($x - 1));
    }

}
