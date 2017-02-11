<?PHP
// This is the rack communications, that is in a thread per server
class rack_command extends Thread {
    var $request_url="";
    var $rackservers;
    var $serverlist;
    var $command;
    var $data;
    var $tmpdata;
    var $myDomain;

    public function __construct(&$themap, $protocol, $ip, $username, $password) {
        //echo "Initializing Event object...\n";
        $this->themap = $themap;
        $this->rackservers = $themap->rackservers;
	$this->rackstack = $themap->rackstack;
        $this->serverlist = $themap->serverlist;
        $this->rackcommand = $themap->rackcommand;
        $this->protocol = $protocol;
        $this->ip = $ip;
        $this->username = $username;
        $this->password = $password;	    
        $this->name = "";
	$this->physdomainnamedn = "";
	$this->startupflag = false;	// set to signify new instance waiting to settle
        $this->url=$protocol."://".$ip."/nuova";
        //echo "Initializing Event object Done.\n";
    }

    public function run() {
	date_default_timezone_set('UTC');
        $this->rackcommand[$this->ip] = array("cmd"=>"discover");  // for the initial system discovery of items
	$threadRackStartTime=$this->themap->storage['physDomainStartupDelay'];  // number of seconds before we declare all things up, and ready to apply changes (instead of adds/removes as we come up)
	$desiredInterfaces=array();  //9-1-15
	$updateSecondThreshold=10;  // number of seconds before we poll (since we dont have event subscription on CIMC servers)
   	$updateSeconds = 0;
	
	while(true) {
	    sleep(1);
	    if ($this->startupflag === false) {
		// we are still just starting up, so dont do any polling just yet and let the discovery complete below
		//echo "@";
		$threadRackStartTime--;
		if ($threadRackStartTime < 1) {
		    $this->startupflag=true;
		    echo date("Y-m-d H:i:s")." -> Startup delay timer expired for rack server {$this->ip}\n";
		}
	    }
	    $updateSeconds++;
	    if ($updateSeconds > $updateSecondThreshold) {
		$updateSeconds = 0;
		if ($this->startupflag) {
		    // When I want to see what the threads are doing, I indicate with a large 'R' that we are polling the rack server
		    if($this->rackstack[$myDomain]['CIMC_MONITOR_POLLING'] === true) {
			echo "[R]";
		    }
		    echo date("Y-m-d H:i:s")." -> Polling the CIMC of rack server {$this->ip}\n";
		    $cookie = $this->rack_aaaLogin();
		    // First, we gather all the MACs and int names on the system, so that we can find out what is already there
		    $return = $this->rack_post($cookie, 'configResolveClass','classId="adaptorHostEthIf" inHierarchical="false"');
		    if (!$return) {
			$myLogMsg=date("Y-m-d H:i:s")." -> ".$this->ip." False return to adapter polling, will catch on next poll cycle\n";
			if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			continue;	// 9-2-15 we have a bad return, so just look to next poll
		    }
		    /*$myLogMsg="configresolve of host adaptors on physical system:\n";
		    ob_start();
		    var_dump($return);
		    $myLogMsg.=ob_get_clean();
		    if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);*/
		    unset($portId0macs);
		    unset($portId1macs);
		    unset($portId0names);
		    unset($portId1names);
		    $gotpcislot=false;
		    foreach($return['configResolveClass']['outConfigs']['adaptorHostEthIf'] as $key=>$value) {
		        if($return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["uplinkPort"] === "0") {
			    $portId0macs[$return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["mac"]] = true;
			    $portId0names[$return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["name"]] = true;
			}
			if($return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["uplinkPort"] === "1") {
			    $portId1macs[$return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["mac"]] = true;
			    $portId1names[$return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["name"]] = true;
			}
			if($gotpcislot === false) {
			    $pcieslottext=$return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["dn"];
			    $pcieslotarray=explode("/host-", $pcieslottext);
			    $pcieslotnum=$pcieslotarray[0];
			    //echo "10-29-14 TEST*****:  got a pcieslotnum=$pcieslotnum\n";
			    $gotpcislot=true;
			}
		    }
		    $myLogMsg="After polling host {$this->ip}: portId0 mac and name objects learned from physical system:\n";
		    ob_start();
		    var_dump($portId0macs);
		    var_dump($portId0names);
		    $myLogMsg.=ob_get_clean();
		    ob_start();
		    $myLogMsg.="And now the portId1 mac and name objects learned from physical system:\n";
		    var_dump($portId1macs);
		    var_dump($portId1names);
		    $myLogMsg.=ob_get_clean();
		    ob_start();
		    $myLogMsg.="Now the array for desired interfaces on CIMC {$this->ip} is:\n";
		    var_dump($desiredInterfaces);
		    $myLogMsg.=ob_get_clean();
		    if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
		    $vifname2use="";
		    foreach($desiredInterfaces as $ifkey=>$arrayitem) {
			$intPresent=false;
			//echo "TEST1...ifkey=$ifkey, arrayitem=$arrayitem\n";
			foreach($arrayitem as $arraykey=>$arrayvalue) {
			    // Set the vifname and vlan for this instance, in case they pass the below test and are needed
			    if (strstr($arraykey, "vifname") != false) $vifname2use = $arrayvalue;
			    if(strlen($vifname2use) > 28) {	// 28 since the real is 30, but we add a -0 or -1
			        $vifname2use=substr($vifname2use, strlen($vifname2use) - 28);
			    }	
			    if (strstr($arraykey, "vlan") != false) $vlan2use = $arrayvalue;
			    if (strstr($arraykey, "node") != false) $node2use = $arrayvalue;
			    if (strstr($arraykey, "chassisIdV") != false) {
				// now we calculate the right offsets, for the portIdV's that we are advertising within LLDP to the ACI
				// this is the key reason we need UCS Cseries CIMC code 2.0(4) or later - along with the CDN writability
				$chassisid = $arrayvalue;
				//echo "3-12-15 Need to add offsets for the right port entries.\n";
				$macBytesArray = explode(":", $chassisid);
				//echo "TEST!!!!  last byte is {$macBytesArray[5]}\n";
				sscanf($macBytesArray[5], "%02x", $macLSB);
				//echo "TEST2!!!!  macLSB is {$macLSB}\n";
				$macLSB += 0x04;	// offset for the port 0
				//echo "TEST3!!!!  macLSB is {$macLSB}\n";
				$macLSBstring='';
				$macLSBstring = sprintf("%02X", $macLSB);
				//echo "TEST4!!!!  macLSBstring is {$macLSBstring}\n";
				$port0LLDPMAC = $macBytesArray[0].":".$macBytesArray[1].":".$macBytesArray[2].":".$macBytesArray[3].":".$macBytesArray[4].":".$macLSBstring;		    
				$macLSB += 0x04;	// 4 more for offset for the port 1
				//echo "TEST5!!!!  macLSB is {$macLSB}\n";
				$macLSBstring='';
				$macLSBstring = sprintf("%02X", $macLSB);
				//echo "TEST6!!!!  macLSBstring is {$macLSBstring}\n";
				$port1LLDPMAC = $macBytesArray[0].":".$macBytesArray[1].":".$macBytesArray[2].":".$macBytesArray[3].":".$macBytesArray[4].":".$macLSBstring;		    
			    }
			    //echo "3-12-15 TEST:  port0-LLDP should reflect as: ".$port0LLDPMAC.", and port1-LLDP should reflect as: ".$port1LLDPMAC."\n";
			    if (strstr($arraykey, "portIdV") != false) {
				// first search port0 and port1 mac's to see if this should be there (only to find uplink ports here)
				$uplinkPort=-1;
			        if (strstr($port0LLDPMAC, $arrayvalue) != false) {
				    $uplinkPort=0;
			        }
				if (strstr($port1LLDPMAC, $arrayvalue) != false) {
				    $uplinkPort=1;
				}
			        //echo "3-12-15 TEST*****: arraykey = $arraykey, vifname=$vifname2use, resulting interface needed on uplink #$uplinkPort to node #$node2use.\n";
			        if ($uplinkPort === 0) {
				    // now compare the names on uplink set 0 to the names in memory to see if we need to write this adapter
				    foreach($portId0names as $ifname=>$value) {
					if (strstr($ifname, $vifname2use) != false) {
					    // This interface exists
					    $intPresent = true;
					    unset($portId0names[$ifname]);  // we clear this for later examination of remaining items - those must be removed
					    //echo "10-28-14 TEST*****: Interface $ifname already exists on uplink 0, so we set the intPresent, and unset portID0names[ifname]\n";
					    break;
					}
				    }
				} else {  // has to then be on uplinkport 1
				    // now compare the names on uplink set 1 to the names in memory to see if we need to write this adapter
				    foreach($portId1names as $ifname=>$value) {
					if (strstr($ifname, $vifname2use) != false) {   // This interface exists
					    $intPresent = true;
					    unset($portId1names[$ifname]);  // we clear this for later examination of remaining items - those must be removed
					    //echo "10-28-14 TEST*****: Interface $ifname already exists on uplink 1, so we set the intPresent, and unset portID1names[ifname]\n";
					    break;
					}
				    }
				}
			    }
			}
			//echo "3-12-15 TEST*****: For ifname=[$ifname], Interface: $vifname2use, behind port: $uplinkPort, to node: $node2use, shows intPresent=";
			if ($intPresent === true) {
			    //echo "True\n";
			    $desiredInterfaces[$ifkey]["Adminstate"]="";
			} else {
			    //echo "False\n";
			    $desiredInterfaces[$ifkey]["Adminstate"]="created";
			}
			if ($intPresent === false) {
			    $myLogMsg=date("Y-m-d H:i:s")." -> Creating Interface: $vifname2use-$uplinkPort VLAN: $vlan2use on host: ".$this->ip."\n";
			    if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
			    echo $myLogMsg;
			    // Write this adapter to the UCS C server
			    $xml="";
			    if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
			        echo "Creating Interface: $vifname2use-$uplinkPort VLAN: $vlan2use...";
			    }
			    $xml='dn="'.$pcieslotnum.'/host-eth-'.$vifname2use.'-'.$uplinkPort.'" ';
			    $xml.='inHierarchical="true">';
			    $xml.='<inConfig>';
			    $xml.='<adaptorHostEthIf name="'.$vifname2use.'-'.$uplinkPort.'" cdn="'.$vifname2use.'-'.$uplinkPort.'" dn="'.$pcieslotnum;
			    $xml.='/host-eth-'.$vifname2use.'-'.$uplinkPort.'" uplinkPort="'.$uplinkPort.'" status="created">';
			    $xml.='<adaptorEthGenProfile rn="general" vlan="'.$vlan2use.'" vlanMode="ACCESS" status="created"/>';
			    $xml.='</adaptorHostEthIf></inConfig></configConfMo>';
			    //echo "5-7-15 sending: {$xml}\n";
			    $return = $this->rack_post2($cookie, 'configConfMo', $xml);
			    //echo "5-7-15 response vardump:\n";
			    //var_dump($return);
			    if (!$return) {
				$myLogMsg=date("Y-m-d H:i:s")." -> ".$this->ip." False return to adapter creation, will catch on next poll cycle\n";
				if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
				echo $myLogMsg;
				continue;	// 9-2-15 just stop and wait for next cycle
			    } else {
				if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
				    echo "Complete.\n";
				}
			    }
			}
		    }
		    $myLogMsg="After writing on host {$this->ip}: portId0 mac and name objects left over:\n";
		    ob_start();
		    var_dump($portId0macs);
		    var_dump($portId0names);
		    $myLogMsg.=ob_get_clean();
		    ob_start();
		    $myLogMsg.="And now the portId1 mac and name objects left over:\n";
		    var_dump($portId1macs);
		    var_dump($portId1names);
		    $myLogMsg.=ob_get_clean();
		    if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
		    // now we remove the left overs that are not needed (except the eth0 and eth1)
		    foreach($portId0names as $deleteKey=>$deleteValue) {
		        if ($deleteKey === "eth0") {
			    // do not remove
		        } else {
			    $myLogMsg=date("Y-m-d H:i:s")." -> Now removing adapter $deleteKey from UCS C Server at $this->ip...\n";
			    if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
			    echo $myLogMsg;
			    // Erase this adapter from the UCS C server
			    if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
			        echo "Now removing adapter $deleteKey from UCS C Server at $this->ip...";
			    }
			    $xml="";
			    $xml='dn="'.$pcieslotnum.'/host-eth-'.$deleteKey.'" ';
			    $xml.='inHierarchical="false">';
			    $xml.='<inConfig>';
			    $xml.='<adaptorHostEthIf dn="'.$pcieslotnum.'/host-eth-'.$deleteKey.'" status="deleted">';
			    $xml.='</adaptorHostEthIf></inConfig></configConfMo>';
			    $result = $this->rack_post2($cookie, 'configConfMo', $xml);
			    if (!$return) {
				$myLogMsg=date("Y-m-d H:i:s")." -> ".$this->ip." False return to adapter deletion, will catch on next poll cycle\n";
				if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
				echo $myLogMsg;
				continue;	// 9-2-15 just stop and wait for next cycle
			    } else {
				if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
				    echo "Done.\n";
				}
			    }
			}
		    }
		    foreach($portId1names as $deleteKey=>$deleteValue) {
		        if ($deleteKey === "eth1") {
			    // do not remove
		        } else {
			    $myLogMsg=date("Y-m-d H:i:s")." -> Now removing adapter $deleteKey from UCS C Server at $this->ip...\n";
			    if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
			    echo $myLogMsg;
			    // Erase this adapter from the UCS C server
			    if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
			        echo "Now removing adapter $deleteKey from UCS C Server at $this->ip...";
			    }
			    $xml="";
			    $xml='dn="'.$pcieslotnum.'/host-eth-'.$deleteKey.'" ';
			    $xml.='inHierarchical="false">';
			    $xml.='<inConfig>';
			    $xml.='<adaptorHostEthIf dn="'.$pcieslotnum.'/host-eth-'.$deleteKey.'" status="deleted">';
			    $xml.='</adaptorHostEthIf></inConfig></configConfMo>';
			    $result = $this->rack_post2($cookie, 'configConfMo', $xml);
			    if (!$return) {
				$myLogMsg=date("Y-m-d H:i:s")." -> ".$this->ip." False return to adapter deletion, will catch on next poll cycle\n";
				if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
				echo $myLogMsg;
				continue;	// 9-2-15 just stop and wait for next cycle
			    } else {
				if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
				    echo "Done.\n";
				}
			    }
			}
		    }
		    $return = $this->rack_aaaLogout($cookie);
		}
	    }
	    $cmdask="";
	    if(isset($this->rackcommand[$this->ip]) === true) {
	        $cmdask = $this->rackcommand[$this->ip];
	        if($cmdask['cmd'] === 'discover') {
		    //echo "Command Execution: aaalogin\n"; 
		    $cookie = $this->rack_aaaLogin();
		    //Grab some metadata regading the session info  
		    $return = $this->rack_post($cookie, 'configResolveClass','classId="topSystem" inHierarchical="false"');
		    if (!$return) continue;  // 9-2-15 do nothing until next poll
		    $this->name = $return['configResolveClass']['outConfigs']['topSystem']['@attributes']['name'];
		    $return = $this->rack_post($cookie, 'configResolveClass','classId="adaptorExtEthIf" inHierarchical="false"');
		    if (!$return) continue;  // 9-2-15 do nothing until next poll
		    $chassisid = [$return['configResolveClass']['outConfigs']['adaptorExtEthIf'][0]["@attributes"]['mac']];
		    $return = $this->rack_post($cookie, 'configResolveClass','classId="computeRackUnit" inHierarchical="false"');
		    if (!$return) continue;  // 9-2-15 do nothing until next poll
		    unset($this->rackcommand[$this->ip]);
		    //var_dump($return);
		    $this->groupname = $return['configResolveClass']['outConfigs']['computeRackUnit']["@attributes"]['usrLbl'];
		    $physdomainname = strtoupper('UCS_RACKDOMAIN_'.$this->groupname);
		    if ((strstr($this->groupname, "VPC") != NULL) || (strstr($this->groupname, "vPC") != NULL)) {
		        // We have a request to build out the APIC policy for a vPC to this CIMC server. This is indicated by a user putting a "vPC in the group name"
		        // We dont do anything on the CIMC as the OS needs to do that
		        $connectPolicy = "VPC";
		    } else {
		        $connectPolicy = "NONVPC";
		    }
		    $physdomainnamedn = 'uni/phys-'.strtoupper('UCS_RACKDOMAIN_'.$this->groupname);
		    echo date("Y-m-d H:i:s")." -> Discovered Rack Server: {$this->name} ({$this->ip}) in group [$this->groupname]\n";
		    $this->tmpdata=array("ip"=>$this->ip, "username"=>$this->username, "password"=>$this->password,
					 "chassisid"=>$chassisid[0], "chassisGroupName"=>$this->groupname,
					 "physdomainname"=>$physdomainname, "physdomainnamedn"=>$physdomainnamedn);
		    $this->tmpdata = $this->rack_refreshinterfaces($cookie, $this->tmpdata);
		    $this->rackservers[$this->ip] = $this->tmpdata;
		    //echo "TEST*******: ";
		    //var_dump($this->rackservers);
		    
		    $minVLAN = $this->themap->storage['cimcVLANpoolmin'];
		    $maxVLAN = $this->themap->storage['cimcVLANpoolmax'];
		    $this->tmpdata = array("physdomainminvlan"=>$minVLAN, "physdomainmaxvlan"=>$maxVLAN, "connectPolicy"=>$connectPolicy,
					   'CIMC_MONITOR_UPDATE'=>false, 'CIMC_MONITOR_POLLING'=>false);
		    $this->rackstack[$physdomainnamedn] = $this->tmpdata;  
		    $myDomain = $physdomainnamedn;
		    $return = $this->rack_aaaLogout($cookie);
	        }
	        if($cmdask['cmd'] === 'update') {
		    // This is a case where user action is updating the items, so we clear the desired array and read from memory
		    unset($this->rackcommand[$this->ip]);
		    foreach($desiredInterfaces as $i => $itemval) {
			unset($desiredInterfaces[$i]);
		    }
		    foreach($this->themap->storage as $key => $value) {
		        //$mac=$this->themap->storage[$key]['portIdV'];
			//echo "10-28-14 TEST****: Want to add an interface with mac=$mac to memory.\n";
			//if(strpos($key, '<3>RACK_ROOT') !== false) {   // Just for my troubleshooting
			//    echo "10-27-14 TEST*****:  Key is {$key}\n ->vardump is:";
			//    var_dump($this->themap->storage[$key]);
			//}
			if(strpos($key,'<3>RACK_ROOT') !== false && $this->themap->storage[$key]['rackserverpeer'] === $this->ip) {
			    // We do not yet know which port (0 or 1) on the UCS C adapter our LLDP information was learned, so put in array here and search later
			    $desiredInterfaces[$key]=$this->themap->storage[$key];
			    $myLogMsg="9-1-15******  CIMC: {$this->ip} just added contents at storage {$key} to the desiredInterfaces array\nvar_dump of array is:\n";
			    ob_start();
			    var_dump($desiredInterfaces);
			    $myLogMsg.=ob_get_clean();
			    if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
		        }
		    }
	        }
	    }
        }
    }
	
    private function rack_http_post_flds($data, &$sendItem, &$returnItem) {
        $opts = array('http' => array('method' => 'POST', 'content' => $data));
	$sendItem = $data;
        $st = stream_context_create($opts);
        $fp = @fopen($this->url, 'rb', false, $st);
        $result="";
        if(!$fp) {
	    $result=false;
        } else {
	    $result = stream_get_contents($fp);
	    fclose($fp);
        }
	$returnItem = $result;
        return $result;
    }

    private function rack_https_post_flds($data, &$sendItem, &$returnItem) {
	date_default_timezone_set('UTC');
	$opts = array(CURLOPT_URL=>$this->url, CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$data, CURLOPT_SSL_VERIFYPEER=>false,
		      CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_TIMEOUT => 20);
	$sendItem = $data;
	$ch=curl_init();
	curl_setopt_array($ch, $opts);
        $result="";
	$result = curl_exec($ch);
	if (!$result) {
	    echo date("Y-m-d H:i:s")." -> ERROR on ".$this->ip.":  HTTPS write failed and we had a false return: [".curl_error($ch)."]\n";
	    $result=false;
	} else {
	    curl_close($ch);
	}
	$returnItem = $result;
        return $result;	
    }

    function rack_refreshinterfaces($cookie, $tmpdata) {
        $return = $this->rack_post($cookie, 'configResolveClass','classId="adaptorHostEthIf" inHierarchical="false"');
        foreach($return['configResolveClass']['outConfigs']['adaptorHostEthIf'] as $key=>$value) {
	    if($return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["uplinkPort"] === "0") {
	        $portId0[$return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["mac"]] = true;
	    }
	    if($return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["uplinkPort"] === "1") {
	        $portId1[$return['configResolveClass']['outConfigs']['adaptorHostEthIf'][$key]["@attributes"]["mac"]] = true;
	    }
        }
        $tmp = $tmpdata;
        $tmp["portId0"] = $portId0;
        $tmp["portId1"] = $portId1;
        $tmpdata = $tmp;
        return $tmpdata;
    }

    function rack_aaaLogin() {
	$send="";
	$reply="";
        $this->sessioninfo = $this->rack_https_post_flds('<aaaLogin inName="'.$this->username.'" inPassword="'.$this->password.'" cookie="" />', $send, $reply);
	$myTest = $this->rackservers[$this->ip];
	$myDomain=$myTest['physdomainnamedn'];
        if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
	    echo "[r]";
	    $this->themap->debugger->dwrite("\nCIMC UPDATE POST START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $this->themap->debugger->dwrite($send);
	    $this->themap->debugger->dwrite("CIMC UPDATE POST END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $this->themap->debugger->dwrite("\nCIMC UPDATE POST REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $this->themap->debugger->dwrite($reply);
	    $this->themap->debugger->dwrite("CIMC UPDATE POST REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        $data = xml_decode($this->sessioninfo);
        if(isset($data["aaaLogin"]["@attributes"]["outCookie"])) {
	    return $data["aaaLogin"]["@attributes"]["outCookie"];;
        }   
        return false;    
    }

    function rack_aaaLogout($cookie) {
	$send="";
	$reply="";
        $result = $this->rack_https_post_flds('<aaaLogout inCookie="'.$cookie.'" />', $send, $reply);
	$myTest = $this->rackservers[$this->ip];
	$myDomain=$myTest['physdomainnamedn'];
        if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
	    echo "r";
	    $this->themap->debugger->dwrite("\nCIMC UPDATE POST START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $this->themap->debugger->dwrite($send);
	    $this->themap->debugger->dwrite("CIMC UPDATE POST END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $this->themap->debugger->dwrite("\nCIMC UPDATE POST REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $this->themap->debugger->dwrite($reply);
	    $this->themap->debugger->dwrite("CIMC UPDATE POST REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        return $result;
    }

    // This adds the trailing /> to the xml sent in to function
    function rack_post($cookie, $method, $xml) {
	$send="";
	$reply="";
        $out='<'.$method.' cookie="'.$cookie.'" '.$xml.'/>';
        //echo "{$out}\n";
        $recv = $this->rack_https_post_flds($out, $send, $reply);
	$myTest = $this->rackservers[$this->ip];
	$myDomain=$myTest['physdomainnamedn'];
        if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
	    echo "r";
	    $this->themap->debugger->dwrite("\nCIMC UPDATE POST START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $this->themap->debugger->dwrite($out);
	    $this->themap->debugger->dwrite("CIMC UPDATE POST END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $this->themap->debugger->dwrite("\nCIMC UPDATE POST REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $this->themap->debugger->dwrite($recv);
	    $this->themap->debugger->dwrite("CIMC UPDATE POST REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
	if ($recv !== false) {
	    $result = xml_decode($recv);
	} else {
	    $result = false;
	}
        return $result;	
    }

    // The assumes the trailing /> is already within the xml sent in to function
    function rack_post2($cookie, $method, $xml) {
	$send="";
	$reply="";
        $out='<'.$method.' cookie="'.$cookie.'" '.$xml;
        //echo "{$out}\n";
        $recv = $this->rack_https_post_flds($out, $send, $reply);
	$myTest = $this->rackservers[$this->ip];
	$myDomain=$myTest['physdomainnamedn'];
        if($this->rackstack[$myDomain]['CIMC_MONITOR_UPDATE'] === true) {
	    echo "r";
	    $this->themap->debugger->dwrite("\nCIMC UPDATE POST START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $this->themap->debugger->dwrite($out);
	    $this->themap->debugger->dwrite("CIMC UPDATE POST END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $this->themap->debugger->dwrite("\nCIMC UPDATE POST REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $this->themap->debugger->dwrite($recv);
	    $this->themap->debugger->dwrite("CIMC UPDATE POST REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
   	if ($recv !== false) {
	    $result = xml_decode($recv);
	} else {
	    $result = false;
	}
        return $result;	
    }	
}

?>
