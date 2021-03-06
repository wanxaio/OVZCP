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

namespace RNTForest\OVZCP\controllers;

use \RNTForest\core\models\Customers;
use \RNTForest\core\models\CustomersPartners;
use \RNTForest\core\models\Logins;
use \RNTForest\core\models\Groups;
use \RNTForest\ovz\models\Colocations;
use \RNTForest\ovz\models\PhysicalServers;
use \RNTForest\ovz\models\VirtualServers;
use \RNTForest\ovz\models\IpObjects;
use \RNTForest\core\libraries\Helpers;

class AdministrationController extends \RNTForest\core\controllers\AdministrationControllerBase
{
    /**
    * Testing area
    *     
    */
    public function scanAllVSAction(){
        // push service
        $push = $this->getPushService();

        // get all PhysicalServer
        $physicalServers = PhysicalServers::find(["ovz=1"]);
        if (!$physicalServers) {
            $this->flashSession->error("No OVZ enabled Physical Servers found");
            return $this->redirectTo("virtual_servers/slidedata");
        }

        // scan each
        foreach($physicalServers as $physicalServer){

            // execute ovz_list_vs job        
            $params = array();
            $job = $push->executeJob($physicalServer,'ovz_list_vs',$params);
            if(!$job || $job->getDone()==2){
                $this->flashSession->error("Job (ovz_list_vs) executions failed");
                return $this->redirectTo("administration/index");
            }

            // scan VS
            $vsList = $job->getRetval(true);
            foreach($vsList as $vs){
                // fetch settins
                $params = array('UUID'=>$vs['uuid']);
                $job = $push->executeJob($physicalServer,'ovz_list_info',$params);
                if(!$job || $job->getDone()==2){
                    $this->flashSession->error("Job (ovz_list_info) executions failed!");
                    continue;
                }
                $settings = $job->getRetval(true);

                // compare settings
                $virtualServer = VirtualServers::findFirst("ovz_uuid = '".$vs['uuid']."'");
                if(!$virtualServer){
                    // Insert new server
                    $virtualServer = new VirtualServers();
                    $virtualServer->setOvzUuid($vs['uuid']);
                }

                // update fields
                $virtualServer->setName(substr($settings['Name'],0,40));
                $virtualServer->setDescription($settings['Description']);
                $virtualServer->setCustomersId($physicalServer->getCustomersId());
                $virtualServer->setPhysicalServersId($physicalServer->getId());
                $virtualServer->setOvz(1);
                $virtualServer->setOvzSettings($job->getRetval());
                $virtualServer->setOvzVstype($settings['Type']);
                $virtualServer->setCore(intval($settings['Hardware']['cpu']['cpus']));
                $virtualServer->setMemory(intval(Helpers::convertToBytes($settings['Hardware']['memory']['size'])/1024/1024));
                $virtualServer->setSpace(intval(Helpers::convertToBytes($settings['Hardware']['hdd0']['size'])/1024/1024/1024));
                $virtualServer->setActivationDate(date("Y-m-d"));

                // save virtual server
                if ($virtualServer->save() === false) {
                    $this->flashSession->error("Virtual Server (".$virtualServer->getName().") save failed.");
                    $messages = $virtualServer->getMessages();
                    foreach ($messages as $message) {
                        $this->flashSession->warning($message);
                    }
                    return $this->redirectTo("administration/index");
                } else {
                    $this->flashSession->success("Virtual (".$virtualServer->getName().") Server sucessfully saved/updated.");
                }
                
                // save IPs
                if(isset($settings['Hardware']['venet0']['ips'])){
                    
                    $this->session->set("IpObjectsForm", array(
                        "op" => "new",
                        "server_class" => '\RNTForest\ovz\models\VirtualServers',
                        "server_id" => intval($virtualServer->getId()),
                    ));
                    
                    $ips = explode(" ",$settings['Hardware']['venet0']['ips']);
                    foreach($ips as $ipAddress){
                        $parts = explode("/",$ipAddress);
                        $ip = new IpObjects();
                        $ip->setValue1($parts[0]);
                        if(!empty($parts[0])) $ip->setValue2($parts[1]);
                        $ip->checkVersion();
                        if($ip->isValidIP($parts[0])){
                            $found = IpObjects::findFirst("value1 = '".$parts[0]."'");
                            if($found) continue;
                            $ip->setType(IpObjects::TYPE_IPADDRESS);
                            $ip->setAllocated(IpObjects::ALLOC_AUTOASSIGNED);
                            $ip->setDCObject($virtualServer);
                            if($ip->save() === false){
                                $this->flashSession->error("IP Address (".$ipAddress.") save failed.");
                                $messages = $ip->getMessages();
                                foreach ($messages as $message) {
                                    $this->flashSession->warning($message);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $this->redirectTo("administration/index");
    }
    
    public function deployRootKeysAction(){
        $physicalServers = \RNTForest\ovz\models\PhysicalServers::find(["ovz=1"]);
        $push = $this->getPushService();
        
        $jobFailures = array();
        $keys = array();
        
        foreach($physicalServers as $physicalServer){
            $job = $push->executeJob($physicalServer,'ovz_get_rootpublickey',null);
            if($job->getDone()==1){
                $physicalServer->setRootPublicKey(json_decode($job->getRetval()));
                $physicalServer->save();
                $keys[] = $physicalServer->getRootPublicKey();
            }else{
                $jobFailures[] = "Job Id: ".$job->getId()." with error: ".$job->getError();
            }
        }
        
        foreach($physicalServers as $physicalServer){
            $job = $push->executeJob($physicalServer,'ovz_update_authorizedkeys',["ROOTKEYS"=>$keys]);
            if($job->getDone()==2){
                $jobFailures[] = "Job Id: ".$job->getId()." with error: ".$job->getError();
            }
        }
        
        if(!empty($jobFailures)){
            $this->flashSession->error(json_encode($jobFailures));
        }else{
            $this->flashSession->success("Successfully deployed all Root Keys to all PhysicalServers.");
        }
          
        $this->redirectTo("administration/index");
    }
    
    /**
    * helper method only for IDE auto completion purpose
    * 
    * @return \RNTForest\core\services\Push
    */
    protected function getPushService(){
        return $this->di['push'];
    }
    
    public function fakerAction(){
        $faker = \Faker\Factory::create("de_CH");
        $count = 10;

        if(!Customers::findFirstByid(2)){
            // customer
            $customer = new Customers();
            $customer->setId(2);
            $customer->setLastname("Forest");
            $customer->setFirstname("RNT");
            $customer->setCompany("RNT-Forest");
            $customer->setStreet("Foreststreet");
            $customer->setZip(1234);
            $customer->setCity("Forestcity");
            $customer->setPhone("+41 61 984 56 78");
            $customer->setEmail("rnt@forest.ch");
            $customer->setWebsite("www.rnt-forest.ch");
            $customer->setActive(1);
            if (!$customer->save()) {
                foreach ($customer->getMessages() as $message) {
                    $this->flashSession->error("Customers: ".$message);
                }
                return $this->redirectTo("administration/index");
            }

            // login
            $login = new Logins();
            $login->setLoginname("rntforest");
            $login->setPassword(hash('sha256', $this->config->application['securitySalt']."1234.abcd"));
            $login->setCustomersId(2);
            $login->setAdmin(0);
            $login->setMain(1);
            $login->setTitle("Mr");
            $login->setFirstname("Rnt");
            $login->setLastname("Forest");
            $login->setEmail("rnt@forest.ch");
            $login->setGroups(1);
            $login->setLocale("en_US.utf8");
            $login->setActive(1);
            $login->setNewsletter(0);
            if (!$login->save()) {
                foreach ($login->getMessages() as $message) {
                    $this->flashSession->error("Logins: ".$message);
                }
                return $this->redirectTo("administration/index");
            }

            // colocation + IP
            $colocation = new Colocations();
            $colocation->setName("RNT Colo");
            $colocation->setCustomersId(2);
            $colocation->setDescription("Colocation of RNT-Forest");
            $colocation->setLocation("Switzerland");
            $colocation->setActivationDate(date("Y-m-d"));
            if (!$colocation->save()) {
                foreach ($colocation->getMessages() as $message) {
                    $this->flashSession->error("Colocations: ".$message);
                }
                return $this->redirectTo("administration/index");
            }
            // IP Net
            $colocations = Colocations::find();
            $netId = count($colocations)+1;
            
            $dcoipobject = new IpObjects();
            $dcoipobject->setVersion(4);
            $dcoipobject->setType(2);
            $dcoipobject->setValue1("192.168.".$netId.".0");
            $dcoipobject->setValue2("24");
            $dcoipobject->setAllocated(1);
            $dcoipobject->setMain(0);
            $dcoipobject->setColocationsId($colocation->getId());
            if (!$dcoipobject->save()) {
                foreach ($dcoipobject->getMessages() as $message) {
                    $this->flashSession->error("Colo: ".$colocation->getId().", ".$message);
                }
                return $this->redirectTo("administration/index");
            }
            // Assigned IP
            $dcoipobject = new IpObjects();
            $dcoipobject->setVersion(4);
            $dcoipobject->setType(1);
            $dcoipobject->setValue1("192.168.".$netId.".".rand(1,10));
            $dcoipobject->setValue2("255.255.255.0");
            $dcoipobject->setAllocated(3);
            $dcoipobject->setMain(1);
            $dcoipobject->setColocationsId($colocation->getId());
            $dcoipobject->setComment("Firewall");
            if (!$dcoipobject->save()) {
                foreach ($dcoipobject->getMessages() as $message) {
                    $this->flashSession->error("Colo: ".$colocation->getId().", ".$message);
                }
                return $this->redirectTo("administration/index");
            }

            for($i=1;$i<=($count/2);$i++){
                // physical server + IP
                $physicalServer = new PhysicalServers();
                $physicalServer->setName("Phys ".key($faker->canton)." ".$faker->buildingNumber);
                $physicalServer->setFqdn($faker->username.".".$faker->domainName);
                $physicalServer->setCustomersId(2);
                $physicalServer->setColocationsId($colocation->getId());
                $physicalServer->setJobPublicKey($faker->sha256);
                $physicalServer->setOvz(0);
                $physicalServer->setCore(rand(1,16));
                $physicalServer->setMemory(rand(1,1024*1024));
                $physicalServer->setSpace(rand(1,1024*1024));
                $physicalServer->setActivationDate(date("Y-m-d"));
                $physicalServer->setDescription($faker->sentence);
                if (!$physicalServer->save()) {
                    foreach ($physicalServer->getMessages() as $message) {
                        $this->flashSession->error("Physical server: ".$message);
                    }
                    return $this->redirectTo("administration/index");
                }
                // IP range
                $dcoipobject = new IpObjects();
                $dcoipobject->setVersion(4);
                $dcoipobject->setType(2);
                $dcoipobject->setValue1("192.168.".$netId.".".$i."1");
                $dcoipobject->setValue2("192.168.".$netId.".".($i+1)."0");
                $dcoipobject->setAllocated(1);
                $dcoipobject->setMain(0);
                $dcoipobject->setPhysicalServersId($physicalServer->getId());
                if (!$dcoipobject->save()) {
                    foreach ($dcoipobject->getMessages() as $message) {
                        $this->flashSession->error("Physical server: ".$physicalServer->getId().", ".$message);
                    }
                    return $this->redirectTo("administration/index");
                }
                // Assigned IP
                $dcoipobject = new IpObjects();
                $dcoipobject->setVersion(4);
                $dcoipobject->setType(1);
                $dcoipobject->setValue1("192.168.".$netId.".".$i."1");
                $dcoipobject->setValue2("255.255.255.0");
                $dcoipobject->setAllocated(3);
                $dcoipobject->setMain(1);
                $dcoipobject->setPhysicalServersId($physicalServer->getId());
                if (!$dcoipobject->save()) {
                    foreach ($dcoipobject->getMessages() as $message) {
                        $this->flashSession->error("Physical server: ".$physicalServer->getId().", ".$message);
                    }
                    return $this->redirectTo("administration/index");
                }

                // virtual server + IP
                $virtualServer = new VirtualServers();
                $virtualServer->setName("Virt ".key($faker->canton)." ".$faker->buildingNumber);
                $virtualServer->setCustomersId(2);
                $virtualServer->setPhysicalServersId($physicalServer->getId());
                $virtualServer->setJobPublicKey($faker->sha256);
                $virtualServer->setOvz(0);
                $virtualServer->setCore(rand(1,16));
                $virtualServer->setMemory(rand(1,1024*1024));
                $virtualServer->setSpace(rand(1,1024*1024));
                $virtualServer->setActivationDate(date("Y-m-d"));
                $virtualServer->setDescription($faker->sentence);
                if (!$virtualServer->save()) {
                    foreach ($virtualServer->getMessages() as $message) {
                        $this->flashSession->error("Virtual server: ".$message);
                    }
                    return $this->redirectTo("administration/index");
                }
                // Assigned IP
                $dcoipobject = new IpObjects();
                $dcoipobject->setVersion(4);
                $dcoipobject->setType(1);
                $dcoipobject->setValue1("192.168.".$netId.".".$i."2");
                $dcoipobject->setValue2("255.255.255.0");
                $dcoipobject->setAllocated(3);
                $dcoipobject->setMain(1);
                $dcoipobject->setVirtualServersId($virtualServer->getId());
                if (!$dcoipobject->save()) {
                    foreach ($dcoipobject->getMessages() as $message) {
                        $this->flashSession->error("Virtual server: ".$virtualServer->getId().", ".$message);
                    }
                    return $this->redirectTo("administration/index");
                }
            }
            $this->flashSession->success("Successfully created fake entries for RNT-Forest");
        } else {
            $this->flashSession->warning("Customer with ID=2 already exists.");
        }
        return $this->redirectTo("administration/index");
    }
        

    /* random entries */
    // random customers
    public function fakeCustomersAction(){
        $faker = \Faker\Factory::create("de_CH");
        
        for($i=1;$i<=10;$i++){
            $customer = new Customers;
            $customer->setLastname($faker->lastName);
            $customer->setFirstname($faker->firstName);
            $customer->setCompany($faker->company);
            $customer->setCompanyAdd($faker->companySuffix);
            $customer->setStreet($faker->streetName);
            $customer->setZip($faker->postcode);
            $customer->setCity($faker->city);
            $customer->setPhone($faker->phoneNumber);
            $customer->setEmail($faker->email);
            $customer->setWebsite("www.".$faker->domainName);
            $customer->setActive(1);
            if (!$customer->save()) {
                foreach ($customer->getMessages() as $message) {
                    $this->flashSession->error("Customers: ".$message);
                }
                return $this->redirectTo("administration/index");;
            }
        }
        $this->flashSession->success("Customers created successfully");
        return $this->redirectTo("administration/index");
    }
    
    public function fakePartnersAction(){
        $faker = \Faker\Factory::create("de_CH");
        
        // random partners
        for($i=1;$i<=10;$i++){
            $customerPartners = new CustomersPartners();
            
            // random customer
            $customer = $this->getRandomEntry('\RNTForest\core\models\Customers',3);
            $customerPartners->setCustomersId($customer->getId());
            
            // random partner
            $partner = $this->getRandomEntry('\RNTForest\core\models\Customers',3,$customer->getId());
            $customerPartners->setPartnersId($partner->getId());
            
            if (!$customerPartners->save()) {
                foreach ($customerPartners->getMessages() as $message) {
                    $this->flashSession->error("Partners: ".$message);
                }
                return $this->redirectTo("administration/index");
            }
        }
        $this->flashSession->success("Partners created successfully");
        return $this->redirectTo("administration/index");
    }
        
    public function fakeLoginsAction(){
        $faker = \Faker\Factory::create("de_CH");
        
        // random logins
        for($i=1;$i<=10;$i++){
            $login = new Logins();
            $login->setLoginname($faker->username(10));
            $login->setPassword(hash('sha256', $this->config->application['securitySalt']."1234.abcd"));

            $customer = $this->getRandomEntry('\RNTForest\core\models\Customers',3);
            $login->setCustomersId($customer->getId());

            $login->setAdmin(0);
            $login->setMain(1);
            $login->setTitle($faker->title);
            $login->setFirstname($faker->firstName);
            $login->setLastname($faker->lastName);
            $login->setEmail($faker->email);

            $group = $this->getRandomEntry('\RNTForest\core\models\Groups',1);
            $login->setGroups($group->getId());

            $login->setLocale("en_US.utf8");
            $login->setActive(1);
            $login->setNewsletter(rand(0,1));
            if (!$login->save()) {
                foreach ($login->getMessages() as $message) {
                    $this->flashSession->error("Logins: ".$message);
                }
                return $this->redirectTo("administration/index");
            }
        }
        $this->flashSession->success("Logins created successfully");
        return $this->redirectTo("administration/index");
    }
    
    public function fakeColocationsAction(){
        $faker = \Faker\Factory::create("de_CH");
        
        // random colocations
        for($i=0;$i<=5;$i++){
            $colocation = new Colocations();
            $city = $faker->city;
            $colocation->setName("Colo ".$city." ".$faker->postcode);

            $customer = $this->getRandomEntry('\RNTForest\core\models\Customers',3);
            $colocation->setCustomersId($customer->getId());

            $colocation->setDescription($faker->sentence);
            $colocation->setLocation($city);
            $colocation->setActivationDate($faker->date($format = 'Y-m-d', $max = 'now'));

            if (!$colocation->save()) {
                foreach ($colocation->getMessages() as $message) {
                    $this->flashSession->error("Colocations: ".$message);
                }
                return;
            }

            // IP Net
            $colocations = Colocations::find();
            $dcoipobject = new IpObjects();
            $dcoipobject->setVersion(IpObjects::VERSION_IPV4);
            $dcoipobject->setType(IpObjects::TYPE_IPNET);
            $dcoipobject->setValue1("192.168.".(count($colocations)+1).".0");
            $dcoipobject->setValue2("24");
            $dcoipobject->setAllocated(IpObjects::ALLOC_RESERVED);
            $dcoipobject->setMain(0);
            $dcoipobject->setColocationsId($colocation->getId());
            if (!$dcoipobject->save()) {
                foreach ($dcoipobject->getMessages() as $message) {
                    $this->flashSession->error("Colo: ".$colocation->getId().", ".$message);
                }
                return $this->redirectTo("administration/index");
            }
            // Assigned IP
            $dcoipobject = new IpObjects();
            $dcoipobject->setVersion(IpObjects::VERSION_IPV4);
            $dcoipobject->setType(IpObjects::TYPE_IPNET);
            $dcoipobject->setValue1("192.168.".(count($colocations)+1).".".rand(1,10));
            $dcoipobject->setValue2("255.255.255.0");
            $dcoipobject->setAllocated(IpObjects::ALLOC_ASSIGNED);
            $dcoipobject->setMain(1);
            $dcoipobject->setColocationsId($colocation->getId());
            $dcoipobject->setComment("Firewall");
            if (!$dcoipobject->save()) {
                foreach ($dcoipobject->getMessages() as $message) {
                    $this->flashSession->error("Colo: ".$colocation->getId().", ".$message);
                }
                return $this->redirectTo("administration/index");
            }
        }
        $this->flashSession->success("Colocations created successfully");
        return $this->redirectTo("administration/index");
    }
    
    public function fakePhysicalServersAction(){
        $faker = \Faker\Factory::create("de_CH");
        
        // random Physical Servers
        for($i=0;$i<=5;$i++){
            $physicalServer = new PhysicalServers();
            $physicalServer->setName("Phys ".key($faker->canton)." ".$faker->buildingNumber);
            $physicalServer->setDescription($faker->sentence);

            $customer = $this->getRandomEntry('\RNTForest\core\models\Customers',3);
            $physicalServer->setCustomersId($customer->getId());

            $colocation = $this->getRandomEntry('\RNTForest\ovz\models\Colocations',1);
            $physicalServer->setColocationsId($colocation->getId());

            $physicalServer->setJobPublicKey($faker->sha256);
            $physicalServer->setOvz(0);
            $physicalServer->setFqdn($faker->username.".".$faker->domainName);
            $physicalServer->setCore(rand(1,16));
            $physicalServer->setMemory(rand(1,1024*1024));
            $physicalServer->setSpace(rand(1,1024*1024));
            $physicalServer->setActivationDate($faker->date($format = 'Y-m-d', $max = 'now'));

            if (!$physicalServer->save()) {
                foreach ($physicalServer->getMessages() as $message) {
                    $this->flashSession->error("Physical server: ".$message);
                }
                return;
            }
            // Assigned IP
            $coloId = $colocation->getId();
            $coloIpRange = IpObjects::findFirst("colocations_id = ".$coloId);
            $value1 = $coloIpRange->getValue1();
            $ip = explode(".",$value1);
            $physicalServers = PhysicalServers::find("colocations_id = ".$coloId);

            $dcoipobject = new IpObjects();
            $dcoipobject->setVersion(4);
            $dcoipobject->setType(1);
            $dcoipobject->setValue1("192.168.".$ip[2].".".($ip[3]+count($physicalServers))."1");
            $dcoipobject->setValue2("255.255.255.0");
            $dcoipobject->setAllocated(3);
            $dcoipobject->setMain(1);
            $dcoipobject->setPhysicalServersId($physicalServer->getId());
            if (!$dcoipobject->save()) {
                foreach ($dcoipobject->getMessages() as $message) {
                    $this->flashSession->error("Physical server: ".$physicalServer->getId().", ".$message);
                }
                return $this->redirectTo("administration/index");
            }
        }
        
        $this->flashSession->success("Physical Servers created successfully");
        return $this->redirectTo("administration/index");
    }

    public function fakeVirtualServersAction(){
        $faker = \Faker\Factory::create("de_CH");
        
        // random Virtual Servers
        for($i=0;$i<=10;$i++){
            $virtualServer = new VirtualServers();
            $virtualServer->setName("Virt ".key($faker->canton)." ".$faker->buildingNumber);
            $virtualServer->setDescription($faker->sentence);

            $customer = $this->getRandomEntry('\RNTForest\core\models\Customers',3);
            $virtualServer->setCustomersId($customer->getId());

            $physicalServer = $this->getRandomEntry('\RNTForest\ovz\models\PhysicalServers',1);
            $virtualServer->setPhysicalServersId($physicalServer->getId());

            $virtualServer->setJobPublicKey($faker->sha256);
            $virtualServer->setOvz(0);
            $virtualServer->setFqdn($faker->username.".".$faker->domainName);
            $virtualServer->setCore(rand(1,16));
            $virtualServer->setMemory(rand(1,1024*1024));
            $virtualServer->setSpace(rand(1,1024*1024));
            $virtualServer->setActivationDate($faker->date($format = 'Y-m-d', $max = 'now'));

            if (!$virtualServer->save()) {
                foreach ($virtualServer->getMessages() as $message) {
                    $this->flashSession->error("Virtual server: ".$message);
                }
                return $this->redirectTo("administration/index");
            }
            // Assigned IP
            $physicalIp = IpObjects::findFirst("physical_servers_id = ".$physicalServer->getId()." AND type = 1");
            $value1 = $physicalIp->getValue1();
            $ip = explode(".",$value1);
            $virtualServers = VirtualServers::find("physical_servers_id = ".$physicalServer->getId());
            
            $dcoipobject = new IpObjects();
            $dcoipobject->setVersion(4);
            $dcoipobject->setType(1);
            $dcoipobject->setValue1($ip[0].".".$ip[1].".".$ip[2].".".($ip[3]+count($virtualServers)));
            $dcoipobject->setValue2("255.255.255.0");
            $dcoipobject->setAllocated(3);
            $dcoipobject->setMain(1);
            $dcoipobject->setVirtualServersId($virtualServer->getId());
            if (!$dcoipobject->save()) {
                foreach ($dcoipobject->getMessages() as $message) {
                    $this->flashSession->error("Virtual server: ".$virtualServer->getId().", ".$message);
                }
                return $this->redirectTo("administration/index");
            }
        }

        $this->flashSession->success("Virtual Servers created successfully");
        return $this->redirectTo("administration/index");
    }
    
    /**
    * returns an random entry of any model
    * 
    * @param mixed $model
    * @param mixed $min
    * @param mixed $exceptId
    */
    private function getRandomEntry($model,$min=1,$exceptId=0){
        $rand = rand($min,$model::count()-1);
        
        $entry = $model::findFirst(
            array(
                'offset'=>$rand,
                "conditions" => "id != ".$exceptId,
            )
        );
        if(!$entry){
            return $this->getRandomEntry($model,$min,$exceptId);
        }
            
        return $entry;
    }
}
