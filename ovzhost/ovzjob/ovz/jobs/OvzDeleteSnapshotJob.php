<?php
/**
* @copyright Copyright (c) ARONET GmbH (https://aronet.swiss)
* @license AGPL-3.0
*
* This code is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License, version 3,
* along with this program.  If not, see <http://www.gnu.org/licenses/>
*
*/

namespace RNTFOREST\OVZJOB\ovz\jobs;

/**
* @jobname ovz_delete_snapshot
* 
* @jobparam UUID, SNAPSHOTID
* @jobreturn JSON Array with infos
*/

class OvzDeleteSnapshotJob extends AbstractOvzJob {

    public function run() {
        $this->Context->getLogger()->debug("Delete snapshot!");

        if(!$this->vsExists($this->Params['UUID'])) return 9;
        
        $exitstatus = $this->PrlctlCommands->deleteSnapshot($this->Params['UUID'],$this->Params['SNAPSHOTID']);
        if($exitstatus > 0) return $this->commandFailed("delete snapshot failed",$exitstatus);

        $exitstatus = $this->PrlctlCommands->listSnapshots($this->Params['UUID']);
        if($exitstatus > 0) return $this->commandFailed("Getting snapshots failed",$exitstatus);
        
        $json = $this->PrlctlCommands->getJson();
        if(!empty($json)){
            $this->Done = 1;    
            $this->Retval = $json;
            $this->Context->getLogger()->debug("delete snapshot success.");
        }else{
            $this->Done = 2;
            $this->Error = "Convert info to JSON failed!";
            $this->Context->getLogger()->debug($this->Error);
        }
    }
}
