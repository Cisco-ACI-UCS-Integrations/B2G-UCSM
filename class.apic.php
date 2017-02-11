<?PHP
//This class is responsible for the cookie refresh, should be under every 50 sec, 45 or so...
class apic_event_refresh extends Thread {
    private $APICeventids="";
    private $themap;

    public function __construct(&$themap, Array $properties=array(array())) {
        $this->themap = $themap;
        $this->APICeventids = $themap->APICeventids;
    }
    
    public function run() {
	date_default_timezone_set('UTC');
        //Refresh subscriptions every 30 seconds or so...
        $maxInterval = 30;
        $tick = 500;	// half a microsecond
        $subscriptions = count($this->themap->APICeventids);
        $aaarefresh = 200;	// do the main cookie refresh every 200 seconds
        $aaarefreshtime = microtime(true);
	$topinforefresh = 60;	// per minute topinfo check
	$topinforefreshtime = microtime(true);
	$loginRefreshTime = microtime(true);
        //Wait for events
        while($subscriptions === 0) {
	    usleep($tick);
	    $subscriptions = count($this->themap->APICeventids);
        }
        $eventInterval = $maxInterval / $subscriptions;
        while(true) {
	    $time_now = microtime(true);
	    $iterationTime = microtime(true);
	    foreach($this->themap->APICeventids as $key=>$value) {
		while (true) {
		    if ((microtime(true) - $iterationTime) >= $eventInterval) {
			$iterationTime = microtime(true);
			break;	// time to go on and refresh this one
		    } else {
		        $subscriptions = count($this->themap->APICeventids);
		        $eventInterval = $maxInterval / $subscriptions;
		        usleep($tick);
		    }
		}
		// 6-16-15
		if ($value == NULL) {
		    $testVal="-1";
		    $myLogMsg="********6-20-15************ TEST - event subscription-id is NULL for: ".$key."\n";
		    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		} elseif ($value == 1) {
		    // 8-1-15 Work for this case
		    $testVal="-1";
		    echo date("Y-m-d H:i:s")." -> We had the APIC event ID of 1 looking to refresh, waiting on this\n";
		} else {
		    $subscriptionReturn = $this->themap->apicsession->apic_get($this->themap,"subscriptionRefresh.json?id={$value}");
		    if (isset($subscriptionReturn["imdata"][0]["error"]["attributes"]["code"])) {
		        $testVal = $subscriptionReturn["imdata"][0]["error"]["attributes"]["code"];
		    } else {
		        $testVal = "1";
		    }
		}
		$redoSub=false;
		if ($testVal === "400") {
		    echo date("Y-m-d H:i:s")." -> APIC Event Channel Refresh ERROR ({$key}) refresh id={$value}, channel is no longer active. Code was {$testVal}\n";
		    $redoSub=true;
		} elseif ($testVal === "-1") {
		    echo date("Y-m-d H:i:s")." -> APIC Event Channel Subscription ID was not set for ({$key}), will signal to try subscription again\n";
		    $redoSub=true;
		}
		if ($redoSub) {
		    $newEventID = NULL;
		    $keyArray = explode("<->", $key);
		    $subscriptionClass = $keyArray[0];
		    $subscriptionScope = $keyArray[1];
		    $this->themap->APICeventids[$key] = -1;	// signal to subscribe routine that we just need to re-establish
		    //echo "_________________________6-22-15 from apic.php eventrefresh thread.\n";
		    $newEventID = $this->themap->apicsession->apic_subscribe($this->themap, $subscriptionScope, $subscriptionClass);
		    echo date("Y-m-d H:i:s")." -> Event re-subscription ";
		    if (isset($newEventID)) {
		        echo "successful, new eventID is {$newEventID}\n";
		    } else {
		        echo "failed\n";
		    }
		}
	    }
	    $timeittook = microtime(true)-$time_now;
	    $displayTime = number_format($timeittook, 3);
	    echo date("Y-m-d H:i:s")." -> APIC refresh interval over {$subscriptions} subscriptions is currently: {$displayTime} seconds\n";
	    if ($timeittook > 60) {
		echo date("Y-m-d H:i:s")." -> ERROR: Subscription Refresh took abnormally wrong time, the subscriptions may fail and be auto-refreshed.\n";
	    }
	    // 12-15-14  Now we check on the 24 hour timer for the APIC max session.  If we are getting close, logout and back in
	    $sessionMaxTimer = microtime(true)-$loginRefreshTime;
	    //if ($sessionMaxTimer > 900) {
	    if ($sessionMaxTimer > 86000) {
		//echo "12-15-14 TESTPOINT!!!!!!!  B2G has been running almost a day. Time to logout of APIC, and back in!!!\n";
		$logoutReturn = $this->themap->apicsession->apic_aaaLogout($this->themap);
		//echo "12-15-14 logout return is: {$logoutReturn}\n";
		$reloginReturn = $this->themap->apicsession->apic_aaaLogin($this->themap);
		$resultText = ($reloginReturn)?"TRUE":"FALSE";
		//echo "12-15-14 re-login return is: {$resultText}\n";
		$loginRefreshTime = microtime(true);
		sleep(20);  // 20 seconds to allow time for event subscription thread to update the socket
	    }
	    // 12-15-14 Done
        }
    }
}

function time_diff_conv($start, $s) {
    $t = array('d' => 86400, 'h' => 3600, 'm' => 60);
    $s = abs($s - $start);
    $string="";
    foreach($t as $key => &$val) {
        $$key = floor($s/$val);
        $s -= ($$key*$val);
        $string .= ($$key==0)?"":$$key."$key ";
    }
    // v1.0.1e
    $displaySeconds = number_format($s);
    return $string.$displaySeconds."s";
}

//This class is responsible for object creation and deletion
class apic_updater extends Thread {
    private $session="";
    private $themap;

    public function __construct(&$themap, Array $properties=array(array())) {
	date_default_timezone_set('UTC');
        echo date("Y-m-d H:i:s")." -> Initializing APIC Updater object\n";
        $this->themap = $themap;
        $this->session = $themap->apicsession;
        $this->storage = $themap->storage;
        $this->printevents=false;		// Set to true to view our sending of APIC modifications
        $this->flowmap=$themap->flowmap;
        $this->attributemap=$themap->attributemap;
    }

    public function run() {
	date_default_timezone_set('UTC');
        echo date("Y-m-d H:i:s")." -> Starting APIC Updater loop\n";
	$startupTime = microtime(true);
        $time_now = microtime(true);
	$top_time_now = microtime(true);
	$last_socket_rx_time = microtime(true);
        $return="";
	$haveInfraVLAN=false;
	//Right off, gather the infraVLAN ID
	while (!$haveInfraVLAN) {
	    $return = $this->session->apic_get($this->themap, "node/mo/topology/pod-1/node-1/sys.json?query-target=subtree&target-subtree-class=l3EncRtdIf");
	    if (isset($return['imdata'][0]['l3EncRtdIf']['attributes']['encap'])) {
	        $infraVLANstring = $return['imdata'][0]['l3EncRtdIf']['attributes']['encap'];
	        $infraArray=explode("-", $infraVLANstring);
	        $infraVLAN = $infraArray[1];
	        echo date("Y-m-d H:i:s")." -> Gathered the APIC infrastructure VLAN of [{$infraVLAN}]\n";
	        $this->themap->apicstack['infraVLAN'] = $infraVLAN;
		$haveInfraVLAN=true;
	    } else {
	        echo date("Y-m-d H:i:s")." -> ERROR:  Cannot gather the APIC infrastructure VLAN, retrying in 1 second\n";
		sleep(1);
	    }
	}
	//Initial gathering of the topology - which gets our IP for the controller on the infrastructure VLAN
	$return = $this->session->apic_get($this->themap, "node/mo/topology/pod-1/node-1/av.json?query-target=children&target-subtree-class=infraWiNode&subscription=yes");
	if (isset($return['imdata'][0]['infraWiNode']['attributes']['addr'])) {
	    $this->themap->apicstack['Controller1_IP'] = $return['imdata'][0]['infraWiNode']['attributes']['addr'];
	    echo date("Y-m-d H:i:s")." -> Gathered IP address of the first APIC infraVLAN as: ".$this->themap->apicstack['Controller1_IP']."\n";
	} else {
	    echo date("Y-m-d H:i:s")." -> ERROR:  Cannot gather IP of the APIC controller.\n";
	}
	while($this->themap->apicstack['EVENT_ACTIVE'] !== true || $this->themap->ucsstack['EVENT_ACTIVE'] !== true) {
	    if($this->themap->apicstack['EVENT_ACTIVE'] !== true && $this->themap->ucsstack['EVENT_ACTIVE'] !== true)
	        echo date("Y-m-d H:i:s")." -> APIC Updater: Waiting for APIC and UCS Eventsubscription to start (performing initial APIC and UCSM data gathering)...\n";
	    if($this->themap->apicstack['EVENT_ACTIVE'] === true && $this->themap->ucsstack['EVENT_ACTIVE'] !== true)
	        echo date("Y-m-d H:i:s")." -> APIC Updater: Waiting for UCS Eventsubscription to start (performing initial UCSM data gathering)...\n";
	    if($this->themap->apicstack['EVENT_ACTIVE'] !== true && $this->themap->ucsstack['EVENT_ACTIVE'] === true)
	        echo date("Y-m-d H:i:s")." -> APIC Updater: Waiting for APIC Eventsubscription to start (performing initial APIC data gathering)...\n";
	    sleep(5);
        }
        //Initial one time configurations for rack and bare metal cases
	$doingVXLAN=false;
        if (($this->storage['userstory'] == 1) || ($this->storage['userstory'] == 2) || ($this->storage['userstory'] == 3) || ($this->storage['userstory'] == 10)) {
	    // VMM with VLAN or VXLAN backing, and Bare Metal userstories
	    $AEPname=$this->storage['ucsDomainsAEP'];
	    $pinPolicyName=$this->storage['ucsvSwitchPol'];
            echo date("Y-m-d H:i:s")." -> Setting the startup APIC objects: Attachable Access Entity Profile [".$AEPname."] with Infra VLAN enabled and UCS MAC PIN policy [".$pinPolicyName."]\n";
	    $tempdn='uni/infra/lacplagp-'.$pinPolicyName;
            $tempKey=$this->session->ip."<1>".$this->themap->ucsstack['VIP']."<2>".$tempdn."<3>AEP_UCSVSW_PINPOLICY";
	    if (isset($this->storage[$tempKey]) == false) {
		$tmp=$this->storage[$tempKey];
		$tmp = array("CLASS"=>"lacpLagPol", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="APIC Startup (lacpLagPol): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->storage[$tempKey]=$tmp;
	    }
	    $tempdn='uni/infra/attentp-'.$AEPname;
            $tempKey=$this->session->ip."<1>".$this->themap->ucsstack['VIP']."<2>".$tempdn."<3>AEP_UCS_SYSTEMS";
	    if (isset($this->storage[$tempKey]) == false) {
		$tmp=$this->storage[$tempKey];
		$tmp = array("CLASS"=>"infraAttEntityP", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="APIC Startup (infraAttEntityP): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->storage[$tempKey]=$tmp;
	    }
	    $tempdn='uni/infra/vlanns-[UCSM_DOMAINS]-dynamic';
            $tempKey=$this->session->ip."<1>".$this->themap->ucsstack['VIP']."<2>".$tempdn."<3>UCSM_VLAN_POOL";
	    if (isset($this->storage[$tempKey]) == false) {
		$tmp=$this->storage[$tempKey];
		$tmp = array("CLASS"=>"fvnsVlanInstP", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="APIC Startup (fvnsVlanInstP): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->storage[$tempKey]=$tmp;
	    }
	    $tempdn=$this->themap->ucsstack['physdomainnamedn'];
            $tempKey=$this->session->ip."<1>".$this->themap->ucsstack['VIP']."<2>".$tempdn."<3>UCSM_PHYS_DOMAIN";
	    if (isset($this->storage[$tempKey]) == false) {
		$tmp=$this->storage[$tempKey];
		$tmp = array("CLASS"=>"physDomP", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="APIC Startup (physDomP): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->storage[$tempKey]=$tmp;
	    }
	    $doingVXLAN=true;
	}
	// now if we are to manage the DHCP for the VXLAN case, then configure the relay now here
	if ($this->themap->apicstack['manageAPICVTEP'] && $doingVXLAN) {
	    echo date("Y-m-d H:i:s")." -> Setting the startup APIC VTEP objects for the VXLAN case: DHCP Relay in infra tenant, access AP, default EPG\n";
	    $tempdn='uni/tn-infra/relayp-B2G-vShield-DHCP-relay';
            $tempKey=$this->session->ip."<1>".$this->themap->ucsstack['VIP']."<2>".$tempdn."<3>DHCP_RELAY_TO_APIC";
	    if (isset($this->storage[$tempKey]) == false) {
		$tmp=$this->storage[$tempKey];
		$tmp = array("CLASS"=>"dhcpRelayP", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="APIC Startup (dhcpRelayP): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	    $tempdn='uni/tn-infra/BD-default/dhcplbl-B2G-vShield-DHCP-relay';
            $tempKey=$this->session->ip."<1>".$this->themap->ucsstack['VIP']."<2>".$tempdn."<3>DHCP_RELAY_LBL_TO_APIC";
	    if (isset($this->storage[$tempKey]) == false) {
		$tmp=$this->storage[$tempKey];
		$tmp = array("CLASS"=>"dhcpLbl", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="APIC Startup (dhcpLbl): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	}
	if ($this->storage['userstory'] == 5) {
	    // Just the rack case with multiple domains
	    $AEPname=$this->storage['ucsDomainsAEP'];
	    $pinPolicyName=$this->storage['ucsvSwitchPol'];
            echo date("Y-m-d H:i:s")." -> Setting the startup APIC objects: Attachable Access Entity Profile [".$AEPname."] with Infra VLAN enabled and UCS MAC PIN policy [".$pinPolicyName."]\n";
	    $tempdn='uni/infra/lacplagp-'.$pinPolicyName;
            $tempKey=$this->session->ip."<1>RACKSERVERS<2>".$tempdn."<3>AEP_UCSVSW_PINPOLICY";
	    if (isset($this->storage[$tempKey]) == false) {
		$tmp=$this->storage[$tempKey];
		$tmp = array("CLASS"=>"lacpLagPol", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="APIC Startup (lacpLagPol): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	    $tempdn='uni/infra/attentp-'.$AEPname;
            $tempKey=$this->session->ip."<1>RACKSERVERS<2>".$tempdn."<3>AEP_UCS_SYSTEMS";
	    if (isset($this->storage[$tempKey]) == false) {
		$tmp=$this->storage[$tempKey];
		$tmp = array("CLASS"=>"infraAttEntityP", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="APIC Startup (infraAttEntityP): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	    $tempdn='uni/infra/vlanns-[UCS_RACK_DOMAINS]-dynamic';
            $tempKey=$this->session->ip."<1>RACKSERVERS<2>".$tempdn."<3>UCSC_VLAN_POOL";
	    if (isset($this->storage[$tempKey]) == false) {
		$tmp=$this->storage[$tempKey];
		$tmp = array("CLASS"=>"fvnsVlanInstP", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="APIC Startup (fvnsVlanInstP): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	    // now the oddity is we have many UCS C domains we can support
	    $lastdomain="";
	    foreach($this->themap->rackstack as $tempdn=>$value) {
		$tempKey=$this->session->ip."<1>RACKSERVERS<2>".$tempdn."<3>UCSC_PHYS_DOMAIN";
		if (isset($this->storage[$tempKey]) == false) {
		    $tmp=$this->storage[$tempKey];
		    $tmp = array("CLASS"=>"physDomP", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		    $myLogMsg="APIC Startup (physDomP): doing the initial memory object creation for {$tempKey}\n";
		    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		    $this->themap->storage[$tempKey]=$tmp;
		}
	    }
	}
	//Now the perpetual looping and handling of items with respect to APIC
        $monitorspeed_update=0;
	$MONITOR_LOOP=false;	// set to false for normal operation
	if ($MONITOR_LOOP) {
	    echo date("Y-m-d H:i:s")." -> ***********-----------------------  Starting infinite loop....\n";
	    $tryTime=microtime(true);
	}
	// 1.0.1e
	$keepalivePoolName="KEEPALIVEPOOL";
	$keepaliveMin="100";
	$keepaliveMax="102";
        while(true) {
	    if ($MONITOR_LOOP) {
		if(intval(microtime(true) - $tryTime) >= 1) {
		    echo "@";
		    $tryTime=microtime(true);
		}
	    }
	    //Start of json write and event testing v1.0.1e
	    if(intval(microtime(true) - $last_socket_rx_time) >= 300) {
		echo "\x07";  // this sends a beep
		$actionType="created";
	        $json = '{"fvnsVlanInstP":{"attributes":{"dn":"uni/infra/vlanns-['.$keepalivePoolName.']-dynamic","name":"'.$keepalivePoolName.'","rn":"vlanns-['.$keepalivePoolName.']-dynamic","status":"';
	        $json .= $actionType.'"},"children":[{"fvnsEncapBlk":{"attributes":{"dn":"uni/infra/vlanns-['.$keepalivePoolName.']-dynamic/from-[vlan-';
	        $json .= $keepaliveMin.']-to-[vlan-'.$keepaliveMax.']","from":"vlan-'.$keepaliveMin.'","to":"vlan-'.$keepaliveMax.'","rn":"from-[vlan-'.$keepaliveMin.']-to-[vlan-'.$keepaliveMax.']","status":"';
		$json .= $actionType.'"},"children":[]}}]}}';
	        echo date("Y-m-d H:i:s")." >>>>>Keepalive APIC JSON Send for Event: {$json}\n";
	        $this->themap->debugger->dwrite("JSON: {$json}\n");
	        $return = $this->session->apic_post($this->themap,'node/mo/uni/infra/vlanns-['.$keepalivePoolName.']-dynamic.json', $json );
		sleep(5);  // 5 seconds so we can see this
		echo "\x07";  // this sends a beep
		$actionType="deleted";
	        $json = '{"infraInfra":{"attributes":{"dn":"uni/infra","status":"modified"},"children":[{"fvnsVlanInstP":{"attributes":{"dn":"uni/infra/vlanns-['.$keepalivePoolName.']-dynamic","status":"';
		$json .= $actionType.'"},"children":[]}}]}}';
	        echo date("Y-m-d H:i:s")." >>>>>Keepalive APIC JSON Send for Event: {$json}\n";
	        $this->themap->debugger->dwrite("JSON: {$json}\n");
	        $return = $this->session->apic_post($this->themap,'node/mo/uni/infra.json', $json );
	    }
	    //11-7-14: End of json write and event testing
	    if($this->themap->apicstack['ACI_MONITOR_UPDATE'] === true) {
	        if($monitorspeed_update < 100) {
		    $monitorspeed_update++;
	        } else {
		    // When I want to see what the threads are doing, I indicate with a small 'a' that we need to write data to APIC
		    echo "[a]";
		    $monitorspeed_update=0;
	        }
	    }
	    //Refresh cookie every 200 seconds or so...
	    if(intval(microtime(true) - $time_now) >= 200) {
		$refreshOK=$this->themap->apicsession->apic_aaarefresh($this->themap);
		if ($refreshOK) {
		    $time_now = microtime(true);
		    echo date("Y-m-d H:i:s")." -> APIC Cookie refreshed\n";
		} else {
		    echo date("Y-m-d H:i:s")." -> APIC Cookie refresh FAILED\n";
		}
	    }
	    //Refresh top level information every 60 seconds or so...
	    if(intval(microtime(true) - $top_time_now) >= 60) {
		$topinfoOK = $this->themap->apicsession->apic_topinforefresh($this->themap);
		if ($topinfoOK) {
		    $top_time_now = microtime(true);
		    $runTime = time_diff_conv($startupTime, $top_time_now);
		    echo date("Y-m-d H:i:s")." -> APIC Top Info refreshed. B2G runtime is: ".$runTime."\n";
		} else {
		    echo date("Y-m-d H:i:s")." -> APIC Top Info refresh FAILED\n";
		}
	    }
	    usleep(500);
	    // Section to look at synthetic objects in memory, and take action depending on the adminstate
	    foreach($this->storage as $key => $value) {
		if(isset($this->storage[$key]['CLASS']) == false) continue;
	        if(strpos($key,'<3>VPC_ROOT') !== false && strpos($key,'<1>'.$this->session->ip.'<2>') !== false && $this->storage[$key]['CLASS'] === 'infraAccPortP') {
		    //Check if I'm a VPC infraAccPortP Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
		        $json  = '{"infraAccPortP":{"attributes":{"dn":"'.$this->storage[$key]['infraAccPortP_dn'].'","descr":"Auto Created by the B2G process","name":';
			$json .= '"'.$this->storage[$key]['infraAccPortP_name'].'","rn":"'.$this->storage[$key]['infraAccPortP_rn'].'", "status":"'.$Adminstate;
			$json .= '"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraAccPortP_url'].'.json', $json );
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"infraAccPortP":{"attributes":{"dn":"'.$this->storage[$key]['infraAccPortP_dn'].'", "status":"'.$Adminstate.'"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraAccPortP_url'].'.json', $json );
		        unset($this->storage[$key]);
		    }
	        } elseif(strpos($key,'<3>VPC_CHILD') !== false && strpos($key,'<1>'.$this->session->ip.'<2>') !== false && $this->storage[$key]['CLASS'] === 'fabricExplicitGEp') {
		    //Check if I'm a VPC Explicit Pair Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {			
		        $json  = '{"fabricExplicitGEp":{"attributes":{"dn":"'.$this->storage[$key]['fabricExplicitGEp_dn'].'","name":"';
			$json .= $this->storage[$key]['fabricExplicitGEp_name'].'","id":"'.$this->storage[$key]['fabricExplicitGEp_id'].'","rn":"';
			$json .= $this->storage[$key]['fabricExplicitGEp_rn'].'", "status":"'.$Adminstate.'"},"children":[';
		        $json .= '{"fabricNodePEp":{"attributes":{"dn":"'.$this->storage[$key]['fabricNodePEp_dn1'].'","id":"';
			$json .= $this->storage[$key]['fabricNodePEp_id1'].'","rn":"'.$this->storage[$key]['fabricNodePEp_rn1'].'","status":"';
			$json .= $Adminstate.'"},"children":[]}},{"fabricNodePEp":{"attributes":{"dn":"'.$this->storage[$key]['fabricNodePEp_dn2'].'","id":"';
			$json .= $this->storage[$key]['fabricNodePEp_id2'].'","rn":"'.$this->storage[$key]['fabricNodePEp_rn2'].'","status":"';
			$json .= $Adminstate.'"},"children":[]}}]}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
			$return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['fabricExplicitGEp_url'].'.json', $json );
			$tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
			$this->storage[$key]=$tmp;
			// 8-20-15 Sim work
			/*ob_start();
			var_dump($return);
			$myReplyMsg=ob_get_clean();
			$this->themap->debugger->dwrite("REPLY: {$myReplyMsg}\n");*/
			// 8-20-15 end
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"fabricExplicitGEp":{"attributes":{"dn":"'.$this->storage[$key]['fabricExplicitGEp_dn'].'", "status":"'.$Adminstate.'"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['fabricExplicitGEp_url'].'.json', $json );
		        unset($this->storage[$key]);
		    }
	        } elseif(strpos($key,'<3>VPC_CHILD') !== false && strpos($key,'<1>'.$this->session->ip.'<2>') !== false && $this->storage[$key]['CLASS'] === 'infraAccBndlGrp') {
		    //Check if I'm a VPC infraAccBndlGrp Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
		        $Adminstate="created,modified";
		        $json  = '{"infraAccBndlGrp":{"attributes":{"dn":"'.$this->storage[$key]['infraAccBndlGrp_dn'].'","lagT":"node","descr":"Auto Created by the B2G process","name":"';
			$json .= $this->storage[$key]['infraAccBndlGrp_name'].'","rn":"'.$this->storage[$key]['infraAccBndlGrp_rn'].'", "status":"';
			$json .= $Adminstate.'"},"children":[{"infraRsLacpPol":{"attributes":{"tnLacpLagPolName":"';
			$json .= $this->storage[$key]['infraAccBndlGrp_tnLacpLagPolName'].'","status":"'.$Adminstate.'"},"children":[]}}]}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraAccBndlGrp_url'].'.json', $json );
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
			// find if this is on fabric A or B to set the items to pull in
			$fabric='';
			if ($this->storage[$key]['infraAccBndlGrp_name'] === $this->themap->ucsstack['fabricAname']) {
			    $fabric='A';
			} elseif($this->storage[$key]['infraAccBndlGrp_name'] === $this->themap->ucsstack['fabricBname']) {
			    $fabric='B';
			}
			$AEPname=$this->storage['ucsDomainsAEP'];
			$fabricText='';
			$jsonObject='';
			if (isset($fabric)) {
			    $fabricText=$this->storage[$key]['infraAccBndlGrp_name'];
			    $jsonObject='uni/infra/funcprof/accbundle-'.$fabricText.'/rsattEntP';
			    $json = '{"infraRsAttEntP":{"attributes":{"tDn":"uni/infra/attentp-'.$AEPname.'","status":"created,modified"},"children":[]}}';
			    $this->themap->debugger->dwrite("JSON: {$json}\n");
			    $return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			    $myLogMsg="********6-18-15************ sending the json to APIC: {$json}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		        }
			$isVPCthere=false;
			$vpcNumber=$vpcNumber=$this->themap->ucsstack['fabric'.$fabric.'vpc'];
			if (isset($vpcNumber)) $isVPCthere=true;
			$isNPthere=isset($this->themap->ucsstack['fabric'.$fabric.'v'.$vpcNumber.'-nodes-ports']);
			//echo "6-18-15 TESTTEST in apic updater, writing the infraAccBndlGrp, isNPthere:{$isNPthere} and isVPCthere:{$isVPCthere} with value:{$vpcNumber}\n";
		        if ($isNPthere && $isVPCthere) {
			    $maxNode=0;
			    $maxPort=0;
			    $minNode=10000;
			    $minPort=10000;
			    foreach($this->themap->ucsstack['fabric'.$fabric.'v'.$vpcNumber.'-nodes-ports'] as $npIndex=>$npEntry) {
			        $npArray=explode('<=>',$npEntry);
			        $thisNode=$npArray[0];
			        $thisPort=$npArray[1];
				if ($thisNode > $maxNode) $maxNode = $thisNode;
				if ($thisNode < $minNode) $minNode = $thisNode;
				if ($thisPort > $maxPort) $maxPort = $thisPort;
				if ($thisPort < $minPort) $minPort = $thisPort;
			    }
			    $json = '{"infraRsAttEntP":{"attributes":{"status":"modified"},"children":[{"infraHConnPortS":{"attributes":{"status":"created","name":"selector';
			    $json .= $minNode.$maxNode.'LeafPorts","type":"range"},"children":[{"infraConnPortBlk":{"attributes":{"dn":"uni/infra/funcprof/accbundle-';
			    $json .= $fabricText.'/rsattEntP/hports-selector'.$minNode.$maxNode.'LeafPorts-typ-range/portblk-block1","status":"created","fromPort":"';
			    $json .= $minPort.'","toPort":"'.$maxPort.'","name":"block1","rn":"portblk-block1"},"children":[]}}]}},{"infraConnNodeS":{"attributes":';
			    $json .= '{"status":"created","name":"selector'.$minNode.$maxNode.'"},"children":[{"infraConnNodeBlk":{"attributes":{"dn":"uni/infra/funcprof/accbundle-';
			    $json .= $fabricText.'/rsattEntP/nodes-selector'.$minNode.$maxNode.'/nodeblk-block1","status":"created","from_":"'.$minNode.'","to_":"'.$maxNode;
			    $json .= '","name":"block1","rn":"nodeblk-block1"},"children":[]}},{"infraRsConnPortS":{"attributes":{"status":"created","tDn":"uni/infra/funcprof/accbundle-';
			    $json .= $fabricText.'/rsattEntP/hports-selector'.$minNode.$maxNode.'LeafPorts-typ-range"},"children":[]}}]}}]}}';
			    $this->themap->debugger->dwrite("JSON: {$json}\n");
			    $return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			    $myLogMsg="********6-18-15************ sending the json to APIC: {$json}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		        }
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"infraAccBndlGrp":{"attributes":{"dn":"'.$this->storage[$key]['infraAccBndlGrp_dn'].'", "status":"'.$Adminstate.'"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraAccBndlGrp_url'].'.json', $json );
		        unset($this->storage[$key]);
		    }
	        } elseif(strpos($key,'<3>VPC_CHILD') !== false && strpos($key,'<1>'.$this->session->ip.'<2>') !== false && $this->storage[$key]['CLASS'] === 'infraHPortS') {
		    //Check if I'm a VPC infraHPortS Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
		        $json  = '{"infraHPortS":{"attributes":{"dn":"'.$this->storage[$key]['infraHPortS_dn'].'","descr":"Auto Created by the B2G process","name":"';
			$json .= $this->storage[$key]['infraHPortS_name'].'","rn":"'.$this->storage[$key]['infraHPortS_rn'].'", "status":"'.$Adminstate.'"}';
		        $json .= ',"children":[{"infraRsAccBaseGrp":{"attributes":{"tDn":"'.$this->storage[$key]['infraRsAccBaseGrp_tDn'].'","status":"';
			$json .= $Adminstate.'"},"children":[]}}]}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraHPortS_url'].'.json', $json );
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"infraHPortS":{"attributes":{"dn":"'.$this->storage[$key]['infraHPortS_dn'].'", "status":"'.$Adminstate.'"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraHPortS_url'].'.json', $json );
		        unset($this->storage[$key]);			    
		    }
	        } elseif(strpos($key,'<3>VPC_CHILD') !== false && strpos($key,'<1>'.$this->session->ip.'<2>') !== false && $this->storage[$key]['CLASS'] === 'infraPortBlk') {
		    //Check if I'm a VPC infraPortBlk Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
		        $Adminstate="created,modified";
		        $json  = '{"infraPortBlk":{"attributes":{"dn":"'.$this->storage[$key]['infraPortBlk_dn'].'","fromPort":"';
			$json .= $this->storage[$key]['infraPortBlk_fromPort'].'","toPort":"'.$this->storage[$key]['infraPortBlk_toPort'].'","rn":"';
			$json .= $this->storage[$key]['infraPortBlk_rn'].'","status":"'.$Adminstate.'"},"children":[]}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraPortBlk_url'].'.json', $json );
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"infraPortBlk":{"attributes":{"dn":"'.$this->storage[$key]['infraPortBlk_dn'].'", "status":"'.$Adminstate.'"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraPortBlk_url'].'.json', $json );
		        unset($this->storage[$key]);
		    }
	        } elseif(strpos($key,'<3>VPC_CHILD') !== false && strpos($key,'<1>'.$this->session->ip.'<2>') !== false && $this->storage[$key]['CLASS'] === 'lacpLagPol') {
		    //Check if I'm a VPC lacpLagPol Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created' || $Adminstate == 'modified') {
		        $json  = '{"lacpLagPol":{"attributes":{"dn":"'.$this->storage[$key]['lacpLagPol_dn'].'","descr":"Auto Created by the B2G process","name":"';
			$json .= $this->storage[$key]['lacpLagPol_name'].'","mode":"'.$this->storage[$key]['lacpLagPol_mode'].'","ctrl":"';
			$json .= $this->storage[$key]['lacpLagPol_ctrl'].'","rn":"'.$this->storage[$key]['lacpLagPol_rn'].'", "status":"'.$Adminstate.'"},"children":[]}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['lacpLagPol_url'].'.json', $json );
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"lacpLagPol":{"attributes":{"dn":"'.$this->storage[$key]['lacpLagPol_dn'].'", "status":"'.$Adminstate.'"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['lacpLagPol_url'].'.json', $json );
		        unset($this->storage[$key]);
		    }
	        } elseif(strpos($key,'<3>VPC_CHILD') !== false && strpos($key,'<1>'.$this->session->ip.'<2>') !== false && $this->storage[$key]['CLASS'] === 'infraNodeP') {
		    //Check if I'm a VPC infraNodeP Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {		    
		        $Adminstate="created,modified";
		        $json  = '{"infraNodeP":{"attributes":{"dn":"'.$this->storage[$key]['infraNodeP_dn'].'","descr":"Auto Created by the B2G process","name":"';
			$json .= $this->storage[$key]['infraNodeP_name'].'","rn":"'.$this->storage[$key]['infraNodeP_rn'].'", "status":"'.$Adminstate.'"}';
		        $json .= ',"children":[{"infraLeafS":{"attributes":{"dn":"'.$this->storage[$key]['infraLeafS_dn'].'","name":"';
			$json .= $this->storage[$key]['infraLeafS_name'].'","type":"'.$this->storage[$key]['infraLeafS_type'].'","rn":"';
			$json .= $this->storage[$key]['infraLeafS_rn'].'","status":"'.$Adminstate.'"}}},{"infraRsAccPortP":{"attributes":';
		        $json .= '{"tDn":"'.$this->storage[$key]['infraRsAccPortP_tDn'].'", "status":"'.$Adminstate.'"},"children":[]}}]}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraNodeP_url'].'.json', $json );
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"infraNodeP":{"attributes":{"dn":"'.$this->storage[$key]['infraNodeP_dn'].'", "status":"'.$Adminstate.'"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraNodeP_url'].'.json', $json );
		        unset($this->storage[$key]);
		    }
	        } elseif(strpos($key,'<3>VPC_CHILD') !== false && strpos($key,'<1>'.$this->session->ip.'<2>') !== false && $this->storage[$key]['CLASS'] === 'infraNodeBlk') {
		    //Check if I'm a VPC infraNodeBlk Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {		    
			$Adminstate="created,modified";
		        $json  = '{"infraNodeBlk":{"attributes":{"dn":"'.$this->storage[$key]['infraNodeBlk_dn'].'","from_":"';
			$json .= $this->storage[$key]['infraNodeBlk_from_'].'","to_":"'.$this->storage[$key]['infraNodeBlk_to_'].'","rn":"';
			$json .= $this->storage[$key]['infraNodeBlk_rn'].'","status":"'.$Adminstate.'"},"children":[]}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraNodeBlk_url'].'.json', $json );
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"infraNodeBlk":{"attributes":{"dn":"'.$this->storage[$key]['infraNodeBlk_dn'].'", "status":"'.$Adminstate.'"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['infraNodeBlk_url'].'.json', $json );
		        unset($this->storage[$key]);
		    }
	        } elseif(strpos($key,$this->session->ip.'<1>') !== false && strpos($key,'<3>UCSM_VLAN_POOL') !== false) {
		    //Check if I'm a physical domain VLAN pool object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
			$start = strrpos($key,"<2>") + 3;
			$end = strrpos($key,"<3>") - $start;
			$dn = substr($key, $start, $end);
			$jsonObject=$dn;
			$minvl=$this->themap->ucsstack['physdomainminvlan'];
			$maxvl=$this->themap->ucsstack['physdomainmaxvlan'];
			$json = '{"fvnsVlanInstP":{"attributes":{"dn":"'.$jsonObject.'","name":"UCSM_DOMAINS","rn":';
			$json .= '"vlanns-[UCSM_DOMAINS]-dynamic","descr":"Auto Created by the B2G process","status":"created"},"children":[{"fvnsEncapBlk":{"attributes":{"dn":';
			$json .= '"uni/infra/vlanns-[UCSM_DOMAINS]-dynamic/from-[vlan-'.$minvl.']-to-[vlan-'.$maxvl.']","from":"vlan-';
			$json .= $minvl.'","to":"vlan-'.$maxvl.'","rn":"from-[vlan-'.$minvl.']-to-[vlan-'.$maxvl.']","status":"created"},"children":[]}}]}}';
			$this->themap->debugger->dwrite("JSON: {$json}\n");
			$return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			$myLogMsg="********6-11-15************ sending the json to APIC: {$json}\n";
			if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    }
		}
	    }
	    foreach($this->storage as $key => $value) {
	        if (strpos($key,$this->session->ip.'<1>') !== false && strpos($key,'<3>UCSC_VLAN_POOL') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
			foreach($this->themap->rackstack as $domainDn=>$domainValues) {
			    // only gather the first UCSC domain as all vlan pools for C are the same
			    $maxvl = $domainValues['physdomainmaxvlan'];
			    $minvl = $domainValues['physdomainminvlan'];
			    break;
			}
			preg_match("/<2>(.*?)<3>/", $key, $output_array);
			$jsonObject=$output_array[1];
			$json = '{"fvnsVlanInstP":{"attributes":{"dn":"'.$jsonObject.'","name":"UCS_RACK_DOMAINS","rn":"vlanns-[UCS_RACK_DOMAINS]-dynamic"';
			$json .= ',"descr":"Auto Created by the B2G process","status":"created"},"children":[{"fvnsEncapBlk":{"attributes":{"dn":"uni/infra/vlanns-[UCS_RACK_DOMAINS]-dynamic/from-[vlan-';
			$json .= $minvl.']-to-[vlan-'.$maxvl.']","from":"vlan-'.$minvl.'","to":"vlan-'.$maxvl.'","rn":"from-[vlan-'.$minvl;
			$json .= ']-to-[vlan-'.$maxvl.']","status":"created"},"children":[]}}]}}';
			$this->themap->debugger->dwrite("JSON: {$json}\n");
			$return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			$myLogMsg="********6-11-15************ sending the json to APIC: {$json}\n";
			if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    }
	        } elseif(strpos($key,$this->session->ip.'<1>') !== false && strpos($key,'<3>UCSM_PHYS_DOMAIN') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
			$start = strrpos($key,"<2>uni/phys-") + strlen("<2>uni/phys-");
			$end = strrpos($key,"<3>") - $start;
			$domainname = substr($key, $start, $end);
			$jsonObject='uni/phys-'.$domainname;
			$json = '{"physDomP":{"attributes":{"dn":"'.$jsonObject.'","name":"'.$domainname.'","rn":"phys-';
			$json .= $domainname.'","status":"created"},"children":[{"infraRsVlanNs":{"attributes":{"tDn":';
			$json .= '"uni/infra/vlanns-[UCSM_DOMAINS]-dynamic","status":"created"},"children":[]}}]}}';
			$this->themap->debugger->dwrite("JSON: {$json}\n");
			$return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			$myLogMsg="********6-11-15************ sending the json to APIC: {$json}\n";
			if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);

			// 6-22-15
			$AEPname=$this->storage['ucsDomainsAEP'];
			$UCSMdomainDn=$this->themap->ucsstack['physdomainnamedn'];
			if(isset($AEPname) && isset($UCSMdomainDn)) {
			    $jsonObject='uni/infra/attentp-'.$AEPname;
			    $json = '{"infraRsDomP":{"attributes":{"tDn":"'.$UCSMdomainDn.'","status":"created"},"children":[]}}';
			    $this->themap->debugger->dwrite("JSON: {$json}\n");
			    $return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			    $myLogMsg="********6-22-15************ sending the json to APIC: {$json}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			    //echo "1!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! UCSMdomainDn={$UCSMdomainDn}, AEPname={$AEPname}\n";
			    //var_dump($return);
			}  // 6-22-15 End

		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
	    	    }
	        } elseif (strpos($key,$this->session->ip.'<1>') !== false && strpos($key,'<3>UCSC_PHYS_DOMAIN') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
			$start = strrpos($key,"<2>uni/phys-") + strlen("<2>uni/phys-");
			$end = strrpos($key,"<3>") - $start;
			$domainname = substr($key, $start, $end);
			$jsonObject='uni/phys-'.$domainname;
			$json = '{"physDomP":{"attributes":{"dn":"'.$jsonObject.'","name":"'.$domainname.'","rn":"phys-'.$domainname;
			$json .= '","status":"created"},"children":[{"infraRsVlanNs":{"attributes":{"tDn":"uni/infra/vlanns-[UCS_RACK_DOMAINS]-dynamic"';
			$json .= ',"status":"created"},"children":[]}}]}}';
			$this->themap->debugger->dwrite("JSON: {$json}\n");
			$return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			$myLogMsg="********6-11-15************ sending the json to APIC: {$json}\n";
			if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);

			// 6-22-15
			$AEPname=$this->storage['ucsDomainsAEP'];
			if(isset($AEPname)) {
			    $start = strrpos($key,"<2>") + strlen("<2>");
			    $end = strrpos($key,"<3>") - $start;
			    $UCSCdomainDn = substr($key, $start, $end);
			    $jsonObject='uni/infra/attentp-'.$AEPname;
			    $json = '{"infraRsDomP":{"attributes":{"tDn":"'.$UCSCdomainDn.'","status":"created"},"children":[]}}';
			    $this->themap->debugger->dwrite("JSON: {$json}\n");
			    $return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			    $myLogMsg="********6-22-15************ sending the json to APIC: {$json}\n";
			    if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
			    //echo "2!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n";
			    //var_dump($return);
			}  // 6-22-15 End

		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    }
	        } elseif (strpos($key,$this->session->ip.'<1>') !== false && strpos($key,'<3>AEP_UCSVSW_PINPOLICY') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created' || $Adminstate == 'modified')  {
			$start = strrpos($key,"<2>uni/infra/lacplagp-") + strlen("<2>uni/infra/lacplagp-");
			$end = strrpos($key,"<3>") - $start;
			$pinPolicyName = substr($key, $start, $end);
			$jsonObject='uni/infra/lacplagp-'.$pinPolicyName;
			$json = '{"lacpLagPol":{"attributes":{"dn":"'.$jsonObject.'","ctrl":"graceful-conv,susp-individual,fast-sel-hot-stdby",';
			$json .= '"name":"'.$pinPolicyName.'","mode":"mac-pin","rn":"lacplagp-'.$pinPolicyName.'","descr":';
			$json .= '"Auto created by the B2G process for use in UCS domains with softswitching","status":"'.$Adminstate.'"},"children":[]}}';
			$this->themap->debugger->dwrite("JSON: {$json}\n");
			$return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			$myLogMsg="********6-11-15************ sending the json to APIC: {$json}\n";
			if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    }
	        } elseif (strpos($key,$this->session->ip.'<1>') !== false && strpos($key,'<3>AEP_UCS_SYSTEMS') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
			if (isset($pinPolicyName)) {	// dependancy here - we dont do this until it is set
			    $start = strrpos($key,"<2>uni/infra/attentp-") + strlen("<2>uni/infra/attentp-");
			    $end = strrpos($key,"<3>") - $start;
			    $AEPname = substr($key, $start, $end);
			    $AEPdn='uni/infra/attentp-'.$AEPname;
			    $jsonObject='uni/infra';
			    $json = '{"infraInfra":{"attributes":{"dn":"uni/infra","status":"modified"},"children":[{"infraAttEntityP":{"attributes":';
			    $json .= '{"dn":"'.$AEPdn.'","name":"'.$AEPname.'","rn":"attentp-'.$AEPname.'","descr":"Auto created by B2G","status":"created"}';
			    $json .= ',"children":[{"infraAttPolicyGroup":{"attributes":{"dn":"uni/infra/attentp-'.$AEPname.'/attpolgrp",';
			    $json .= '"rn":"attpolgrp","status":"created"},"children":[{"infraRsOverrideCdpIfPol":{"attributes":{"tnCdpIfPolName":"default"';
			    $json .= ',"status":"created,modified"},"children":[]}},{"infraRsOverrideLacpPol":{"attributes":{"tnLacpLagPolName":"'.$pinPolicyName;
			    $json .= '","status":"created,modified"},"children":[]}},{"infraRsOverrideLldpIfPol":{"attributes":{"tnLldpIfPolName":"default",';
			    $json .= '"status":"created,modified"},"children":[]}}]}},{"infraProvAcc":{"attributes":{"dn":"uni/infra/attentp-'.$AEPname.'/provacc"';
			    $json .= ',"status":"created"},"children":[]}}]}},{"infraFuncP":{"attributes":{"dn":"uni/infra/funcprof","status":"modified"},"children":[]}}]}}';
			    $this->themap->debugger->dwrite("JSON: {$json}\n");
			    $return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			    $myLogMsg="********6-11-15************ sending the json to APIC: {$json}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			    $UCSMdomainDn=$this->themap->ucsstack['physdomainnamedn'];
			    if(isset($AEPname) && isset($UCSMdomainDn)) {
			        $jsonObject='uni/infra/attentp-'.$AEPname;
			        $json = '{"infraRsDomP":{"attributes":{"tDn":"'.$UCSMdomainDn.'","status":"created"},"children":[]}}';
			        $this->themap->debugger->dwrite("JSON: {$json}\n");
			        $return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			        $myLogMsg="********6-22-15************ sending the json to APIC: {$json}\n";
			        if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			    }
			    foreach($this->themap->rackstack as $UCSCdomainDn=>$rackValue) {
			        $jsonObject='uni/infra/attentp-'.$AEPname;
			        $json = '{"infraRsDomP":{"attributes":{"tDn":"'.$UCSCdomainDn.'","status":"created"},"children":[]}}';
			        $this->themap->debugger->dwrite("JSON: {$json}\n");
			        $return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			        $myLogMsg="********6-22-15************ sending the json to APIC: {$json}\n";
			        if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			    }
			    $tmp=$this->storage[$key];
			    $tmp["Adminstate"] = "";
			    $this->storage[$key]=$tmp;
			} else {
			    echo date("Y-m-d H:i:s")." -> PAUSED....  Have a AEP input item, but the lacplagp was not set yet - doing nothing\n";
			}
		    }
	        } elseif (strpos($key,$this->session->ip.'<1>') !== false && strpos($key,'<3>DHCP_RELAY_TO_APIC') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
			$start = strrpos($key,"<2>uni/tn-infra/relayp-") + strlen("<2>uni/tn-infra/relayp-");
			$end = strrpos($key,"<3>") - $start;
			$relayPolicyName = substr($key, $start, $end);
			$jsonObject='uni/tn-infra/relayp-'.$relayPolicyName;
			$json = '{"dhcpRelayP":{"attributes":{"dn":"'.$jsonObject.'","name":"'.$relayPolicyName.'","rn":"relayp-'.$relayPolicyName.'",';
			$json .= '"descr":"Auto created by B2G for VTEP items","status":"created"},"children":[{"dhcpRsProv":{"attributes"';
			$json .= ':{"addr":"'.$this->themap->apicstack['Controller1_IP'].'","tDn":"uni/tn-infra/ap-access/epg-default","status":"created"},"children":[]}}]}}';
			$this->themap->debugger->dwrite("JSON: {$json}\n");
			$return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			$myLogMsg="********6-11-15************ sending the json to APIC: {$json}\n";
			if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    }
	        } elseif (strpos($key,$this->session->ip.'<1>') !== false && strpos($key,'<3>DHCP_RELAY_LBL_TO_APIC') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
			$start = strrpos($key,"<2>uni/tn-infra/BD-default/dhcplbl-") + strlen("<2>uni/tn-infra/BD-default/dhcplbl-");
			$end = strrpos($key,"<3>") - $start;
			$relayPolicyName = substr($key, $start, $end);
			$jsonObject='uni/tn-infra/BD-default/dhcplbl-'.$relayPolicyName;
			$json = '{"dhcpLbl":{"attributes":{"dn":"'.$jsonObject.'","owner":"tenant","name":"'.$relayPolicyName.'",';
			$json .= '"rn":"dhcplbl-'.$relayPolicyName.'","status":"created"},"children":[]}}';
			$this->themap->debugger->dwrite("JSON: {$json}\n");
			$return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			$myLogMsg="********6-11-15************ sending the json to APIC: {$json}\n";
			if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    }
		} elseif (strpos($key,$this->session->ip.'<1>') !== false && strpos($key,'<3>VMM_ASSOCIATIONS') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
			$AEPname=$this->storage['ucsDomainsAEP'];
			if(isset($AEPname)) {
			    $jsonObject='uni/infra/attentp-'.$AEPname;
			    $json = '{"infraRsDomP":{"attributes":{"tDn":"'.$this->storage[$key]['vmmDomP-AEP_dn'].'","status":"created"},"children":[]}}';
			    $this->themap->debugger->dwrite("JSON: {$json}\n");
			    $return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			    $myLogMsg="********7-2-15************ sending the json to APIC at: node/mo/{$jsonObject}.json and the json: {$json}\n";
			    if ($this->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
			    $tmp=$this->storage[$key];
			    $tmp["Adminstate"] = "";
			    $this->storage[$key]=$tmp;
			}
		    } elseif($Adminstate == 'deleted') {
			$AEPname=$this->storage['ucsDomainsAEP'];
			if(isset($AEPname)) {
			    $jsonObject='uni/infra/attentp-'.$AEPname.'/rsdomP-['.$this->storage[$key]['vmmDomP-AEP_dn'].']';
			    $json = '{"infraRsDomP":{"attributes":{"dn":"uni/infra/attentp-'.$AEPname.'/rsdomP-['.$this->storage[$key]['vmmDomP-AEP_dn'].']","status":"'.$Adminstate.'",';
			    $json .= '"tDn":"'.$this->storage[$key]['vmmDomP-AEP_dn'].'"},"children":[]}}';
			    $this->themap->debugger->dwrite("JSON: {$json}\n");
			    $return = $this->session->apic_post($this->themap,'node/mo/'.$jsonObject.'.json', $json );
			    $myLogMsg="********7-2-15************ sending the json to APIC: {$json}\n";
			    if ($this->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
			    $tmp=$this->storage[$key];
			    $tmp["Adminstate"] = "";
			    $this->storage[$key]=$tmp;
			}
		    }		    
		} elseif(strpos($key,'<1>'.$this->session->ip.'<2>') !== false && strpos($key,'<3>SPAN_ROOT') !== false) {
		    //Check if I'm a SPAN Root Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
		        $json  = '{"spanSrcGrp":{"attributes":{"dn":"'.$this->storage[$key]['spanSrcGrp_dn'].'","descr":"Auto Created by the B2G process","name":"';
			$json .= $this->storage[$key]['spanSrcGrp_name'].'","rn":"'.$this->storage[$key]['spanSrcGrp_rn'].'", "status":"'.$Adminstate.'"}}}';
			$this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['spanSrcGrp_url'].'.json', $json );
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";
		        $this->storage[$key]=$tmp;
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"spanSrcGrp":{"attributes":{"dn":"'.$this->storage[$key]['spanSrcGrp_dn'].'", "status":"'.$Adminstate.'"}}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['spanSrcGrp_url'].'.json', $json );
		        unset($this->storage[$key]);
		    }
	        } elseif(strpos($key,'<1>'.$this->session->ip.'<2>') !== false && strpos($key,'<3>SPAN_CHILD') !== false) {
		    //Check if I'm a SPAN Child Object
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($Adminstate == 'created') {
		        $json  = '{"spanSrc":{"attributes":{"dn":"'.$this->storage[$key]['spanSrc_dn'].'","descr":"Auto Created by the B2G process","name":"';
			$json .= $this->storage[$key]['spanSrc_name'].'","rn":"'.$this->storage[$key]['spanSrc_rn'].'", "status":"';
			$json .= $Adminstate.'"},"children":[{"spanRsSrcToPathEp":{"attributes":{"tDn":"';
			$json .= $this->storage[$key]['spanRsSrcToPathEp_tdn'].'","status":"'.$Adminstate.'"},"children":[]}}]}}';
		        if(is_null($this->storage[$this->storage[$key]['parent']]) === false) {
			    $this->themap->debugger->dwrite("JSON: {$json}\n");
			    $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['spanSrc_url'].'.json', $json );
			    $tmp=$this->storage[$key];
			    $tmp["Adminstate"] = "";
			    $this->storage[$key]=$tmp;
		        } else {
			    //var_dump($this->storage[$this->storage[$key]['parent']]);
		        }
		        $json  = '{"spanRsSrcToPathEp":{"attributes":{"tDn":"'.$this->storage[$key]['spanRsSrcToPathEp_tdn'].'","status":"created"},"children":[]}}';
		        if(is_null($this->storage[$this->storage[$key]['parent']]) === false) {
			    $this->themap->debugger->dwrite("JSON: {$json}\n");
			    $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['spanSrc_url'].'.json', $json );
			    $tmp=$this->storage[$key];
			    $tmp["Adminstate"] = "";
			    $this->storage[$key]=$tmp;
		        } else {
			    //var_dump($this->storage[$this->storage[$key]['parent']]);
			}
		    } elseif($Adminstate == 'deleted') {
		        $json  = '{"spanSrc":{"attributes":{"dn":"'.$this->storage[$key]['spanSrc_dn'].'","status":"'.$Adminstate.'"},"children":[';
		        $json .= '{"spanRsSrcToPathEp":{"attributes":{"tDn":"'.$this->storage[$key]['spanRsSrcToPathEp_tdn'].'", "status":"';
			$json .= $Adminstate.'"},"children":[]}}]}}';
		        $this->themap->debugger->dwrite("JSON: {$json}\n");
		        $return = $this->session->apic_post($this->themap, 'node/mo/'.$this->storage[$key]['spanSrc_url'].'.json', $json );
		        unset($this->storage[$key]);
		    }
	        }
	    }
	    //******Update the receive timer if data in v1.0.1e
	    foreach($this->themap->apiccallqueue as $key=>$value) {
		if (strstr($value,'APIC_RX_EVENT<->') != NULL) {
		    $last_socket_rx_time = microtime(true);    
		    echo date("Y-m-d H:i:s")." -> APIC Event received\n";
		    unset($this->themap->apiccallqueue[$key]);
		}
	    }
	    //******ACI EPG to UCS VLAN Static Path Bindings Periodic Update Section*****
	    foreach($this->themap->apiccallqueue as $key=>$value) {
		if (strstr($value,'UCSC-NONVPC<->') != NULL) {
		    $delayBind=false;
		    // need to loop through rack servers, looking the domain string is inside $key to find: $thisEPGdn, $vlanToUse, $nodeID, $nodePort
		    $bindArray = explode("<->", $value);
		    $bindCmd = $bindArray[1];
		    if ($bindCmd === 'BindIt') {
			$timeSinceStart = intval(microtime(true) - $startupTime);
			if($timeSinceStart < $this->storage['physDomainStartupDelay']) {
			    //$myLogMsg="**************5-18-15 ********WORKING in apic thread - startupdelay in effect ({$timeSinceStart} elapsed), so not handling this UCSC binding yet.\n";
			    //var_dump($this->themap->apiccallqueue[$key]);
			    //if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			    $delayBind=true;	// continue onto other apic calls in queue and will do nothing with this one
			}
			$createOrDelete = "created";
		    } else {
			$createOrDelete = "deleted";
		    }
		    if (!$delayBind) {
			$bindEPG = $bindArray[2];
			$bindDomain = $bindArray[3];
			$bindVlan = $bindArray[4];
			$myLogMsg="********APIC Inside RACK:  c/d:{$createOrDelete}, bindEPG:{$bindEPG}, bindDomain:{$bindDomain}, bindVlan:{$bindVlan}\n";
			if ($this->themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
			//echo "TEST1.3***  bindCmd={$bindCmd}, bindEPG={$bindEPG}, bindDomain={$bindDomain}, bindVlan={$bindVlan}\n";
			foreach($this->themap->rackstack as $domainnamedn => $value) {
			    if ($domainnamedn === $bindDomain) {
			        //echo "TEST1.5****:  key = {$key}, domainnamedn = {$domainnamedn}\n";
				// now we cycle through each rack server, to unbind the EPG from that port and VLAN
				foreach($this->themap->rackservers as $rackInstance=>$value) {
				    foreach($this->themap->rackservers[$rackInstance] as $bindKey=>$value){
				        //echo "TEST2****:  bindKey = {$bindKey}, value = {$value}\n";
				        if (strstr($bindKey, "paths-") != NULL) {
				            //echo "TEST3****:  found path substring match! Lets bind it.\n";
				            $nodeID = $bindKey;
				            $nodePort = $value;
				            //echo "TEST4****:  nodeID = ".$nodeID.", nodePort = ".$nodePort."\n";
				            // now clear out the pointer to the rackstack vlan to EPG mapping if we are removing
				            if ($createOrDelete === "deleted") {
				                //echo "3-12-15: We want to remove the memory instance for domain= '.$bindDomain.', EPG= '.$bindEPG.', VLAN= '.$bindVlan.', flag=$createOrDelete\n";
				                $tmp = $this->themap->rackstack[$bindDomain];
				                unset($tmp["vlan-$bindVlan"]);
				                $this->themap->rackstack[$bindDomain] = $tmp;
				            }
				            $json = '{"fvRsPathAtt":{"attributes":{"encap":"vlan-'.$bindVlan.'","tDn":"topology/pod-1/'.$nodeID;
				            $json .= '/pathep-'.$nodePort.'","status":"'.$createOrDelete.'"},"children":[]}}';
				            $this->themap->debugger->dwrite("JSON: {$json}\n");
				            $return = $this->session->apic_post($this->themap, 'node/mo/'.$bindEPG.'.json', $json );
				        }
				    }
				}
			    }
			}
			echo date("Y-m-d H:i:s")." -> In UCSC-NONVPC BindIt/UnBindIt - done.\n";
			unset($this->themap->apiccallqueue[$key]);
		    }
	        } elseif (($value === 'UCSC-VPC-BindIt') || ($value === 'UCSC-VPC-UnBindIt')) {
		    // For later implementation
		    echo date("Y-m-d H:i:s")." -> In UCSC-VPC-BindIt/UnBindIt - To be completed\n";
		    unset($this->themap->apiccallqueue[$key]);
		}
		if (($value === 'UCSM-BindIt') || ($value === 'UCSM-UnBindIt')) {
		    $delayBind=false;
		    //echo "TEST0*****: in UCSM-BindIt/UnBindIt\n";
		    if ($value === 'UCSM-BindIt') {
			$timeSinceStart = intval(microtime(true) - $startupTime);
			if($timeSinceStart < $this->storage['physDomainStartupDelay']) {
			    $myLogMsg="6-30-15: In apic thread - startupdelay in effect ({$timeSinceStart} elapsed), so not handling this UCSM binding yet.\n";
			    //var_dump($this->themap->apiccallqueue[$key]);
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			    $delayBind=true;	// continue onto other apic calls in queue and will do nothing with this one
			}
			$createOrDelete = "created";
		    } else {
			$createOrDelete = "deleted";
		    }
		    if (!$delayBind) {			
			//6-30-15 - first do A write, then B
			if (isset($this->themap->ucsstack['fabricAname']) && isset($this->themap->ucsstack['fabricAvpc'])) {
			    $VPCname['A']=$this->themap->ucsstack['fabricAname'];
			    $tmpPCnum=$this->themap->ucsstack['fabricAvpc'];
			    //echo "6-30-15 vardump of the nodes ports array:\n";
			    //var_dump($this->themap->ucsstack['fabricAv'.$tmpPCnum.'-nodes-ports']);
			    foreach($this->themap->ucsstack['fabricAv'.$tmpPCnum.'-nodes-ports'] as $leafKey=>$lineKey) {
				$vpcAarray=explode("<=>",$lineKey);
				$leafs['A'][$vpcAarray[0]]=true;
			    }
			} else {
			    echo date("Y-m-d H:i:s")." -> ERROR: The fabric A VPC items were not set!!!!\n";
			}
			//echo "6-30-15 vardump of the leafs array:\n";
			//var_dump($leafs);
			if (isset($this->themap->ucsstack['fabricBname']) && isset($this->themap->ucsstack['fabricBvpc'])) {
			    $VPCname['B']=$this->themap->ucsstack['fabricBname'];
			    $tmpPCnum=$this->themap->ucsstack['fabricBvpc'];
			    //echo "6-30-15 vardump of the nodes ports array:\n";
			    //var_dump($this->themap->ucsstack['fabricBv'.$tmpPCnum.'-nodes-ports']);
			    foreach($this->themap->ucsstack['fabricBv'.$tmpPCnum.'-nodes-ports'] as $leafKey=>$lineKey) {
				$vpcAarray=explode("<=>",$lineKey);
				$leafs['B'][$vpcAarray[0]]=true;
			    }
			} else {
			    echo date("Y-m-d H:i:s")." -> ERROR: The fabric B VPC items were not set!!!!\n";
			}
			//echo "6-30-15 vardump of the leafs array:\n";
			//var_dump($leafs);
			foreach($VPCname as $fabricKey=>$fabricValue) {
			    foreach($leafs[$fabricKey] as $leafnode=>$nodeval) {
				$theLeaf[]=$leafnode;
			    }	// now there should be only 2 leafs in a VPC
			    if ($theLeaf[0] > $theLeaf[1]) {
				$leaftmp=$theLeaf[0];
				$theLeaf[0]=$theLeaf[1];
				$theLeaf[1]=$leaftmp;
			    }
			    //echo "6-30-15 working, fabric={$fabricKey}, fabricvalue={$fabricValue}, lowerLeaf={$theLeaf[0]} and higher={$theLeaf[1]}\n";
			    foreach($this->themap->ucsstack as $vlanBinding => $value) {
				//echo "TEST1*****:  here vlanBinding is $vlanBinding, value is $value\n";
				if (strstr($vlanBinding, "vlan-") != NULL) {
				    //echo "TEST2*****:  found vlan substring match! Now checking for key=$key, and value=$value\n";
				    if(compare_dn($key,$value)) {
				        $vlanToUse = $vlanBinding;
				        $thisEPGdn = $value;
					if ($createOrDelete === "created") {
					    $myLogMsg='6-30-15:  We are creating a mapping of an EPG: '.$thisEPGdn.' on domainfabric: '.$fabricValue.' using leafs:'.$theLeaf[0].','.$theLeaf[1].' vlanToUse:'.$vlanToUse."\n";
					} elseif ($createOrDelete === "deleted") {
					    $myLogMsg='6-30-15:  We are deleting a mapping of an EPG: '.$thisEPGdn.' on domainfabric: '.$fabricValue.' using leafs:'.$theLeaf[0].','.$theLeaf[1].' vlanToUse:'.$vlanToUse."\n";
					}
					if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
					$json = '{"fvRsPathAtt":{"attributes":{"encap":"'.$vlanToUse.'","tDn":"topology/pod-1/protpaths-';
					$json .= $theLeaf[0].'-'.$theLeaf[1].'/pathep-['.$fabricValue.']","status":"'.$createOrDelete.'"},"children":[]}}';
					$this->themap->debugger->dwrite("JSON: {$json}\n");
					$return = $this->session->apic_post($this->themap, 'node/mo/'.$thisEPGdn.'.json', $json);
					$myLogMsg="6-30-15: Just posted json: [".$json."], to node/mo/".$thisEPGdn.".json\n";
					if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
					break;
				    }
				}
			    }
			    $myLogMsg="6-30-15:  Evaluating if there is another fabric to write to...\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			}
			// now clear this out if we are removing
			if ($createOrDelete === "deleted") {
			    $tmp = $this->themap->ucsstack;
			    unset($tmp[$vlanBinding]);
			    $this->themap->ucsstack = $tmp;
			}
			//End 6-30-15
			unset($this->themap->apiccallqueue[$key]);
		    }
		}
	    }
        }
    }
}

//This class is responsible for receiving the events and send them to the common doer class 
class apic_eventsubscription extends Thread {
    private $workerId;
    private $request_url="";
    private $cookie="";
    private $session="";
    private $status=false;
    private $APICeventids=array();

    private function apic_generateRandomString($length = 10, $addSpaces = true, $addNumbers = true) {  
	$characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"¤$%&/()=[]{}';
	$useChars = array();
	// select some random chars:    
	for($i = 0; $i < $length; $i++) {
	    $useChars[] = $characters[mt_rand(0, strlen($characters)-1)];
	}
	// add spaces and numbers:
	if($addSpaces === true) {
	    array_push($useChars, ' ', ' ', ' ', ' ', ' ', ' ');
	}
	if($addNumbers === true) {
	    array_push($useChars, rand(0,9), rand(0,9), rand(0,9));
	}
	shuffle($useChars);
	$randomString = trim(implode('', $useChars));
	$randomString = substr($randomString, 0, $length);
	return $randomString;
    }     

    public function __construct(&$themap, Array $properties=array(array())) {
	date_default_timezone_set('UTC');
        echo date("Y-m-d H:i:s")." -> Initializing APIC Listener object\n";
        $this->themap = $themap;
        $this->session = $themap->apicsession;
        $this->request_url="ws://".$this->session->ip."/socket";
	$this->storage = $themap->storage;
        $this->printevents=false;			// Set to true to view the APIC subscribed-to events coming in
        $this->flowmap=$themap->flowmap;
        $this->attributemap=$themap->attributemap;
        $this->APICeventids = $themap->APICeventids;
        $themap->apicstack['EVENT_ACTIVE'] = false;
	//echo "TEST***: in APIC event subscription construct function.  Just set EVENT_ACTIVE to false.\n";	
	//echo "Initializing Event object Done.\n";
    }
  
    public function run() {
	date_default_timezone_set('UTC');
	// 7-6-15 Working on SSL for the event subscriptions
	$useSSL=true;	// Set to false to use plain http (the early way this was implemented)
        echo date("Y-m-d H:i:s")." -> Starting APIC Event Listener\n";
        $url   = parse_url($this->request_url);
	$host = parse_url($this->request_url, PHP_URL_HOST);
	$path = parse_url($this->request_url, PHP_URL_PATH).$this->themap->apicstack['cookie'];
	$query = parse_url($this->request_url, PHP_URL_QUERY);
        $path .= $query ? '?'. $query : '';
	if ($useSSL) {
	    $protocol="https://";
	    $port="443";
	    $sockUpgrade = "/socket".$this->themap->apicstack['cookie'];
	    //echo "*****7-6-15***** sockUpgrade: {$sockUpgrade}\n";
	    $out="GET {$sockUpgrade} HTTP/1.1\r\n";
	} else {
	    $protocol="http://";
	    $port="80";
	    $out="GET {$path} HTTP/1.1\r\n";
	}
        $key = base64_encode($this->apic_generateRandomString(16, false, true));
        $out .= "Upgrade: websocket\r\n";
        $out .= "Connection: upgrade\r\n";
        $out .= "Host: {$host}\r\n";
        $out .= "Origin: {$protocol}{$host}\r\n";
        $out .= "Pragma: no-cache\r\n";
        $out .= "Cache-Control: no-cache\r\n";
	$out .= "Connection: keep-alive\r\n";
        $out .= "Sec-WebSocket-Key: '.$key.'\r\n";
        $out .= "Sec-WebSocket-Version: 13\r\n";
        $out .= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits, x-webkit-deflate-frame\r\n";
        $out .= "User-Agent: Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.153 Safari/537.36\r\n";
        $out .= $this->themap->apicstack['cookieheader']."\r\n\r\n";
	if (!$useSSL) {
	    if(!($sock = socket_create(AF_INET, SOCK_STREAM, 0))) {
	        echo date("Y-m-d H:i:s")." -> ERROR: Could not create socket for APIC event subscription.\n";
		return;
	    } 
	    //echo "Socket created_E\n";    
	    //Connect socket to remote server
	    if(!socket_connect($sock , $host , $port)) {
	        echo date("Y-m-d H:i:s")." -> ERROR: Could not connect to APIC instance on port {$port} for event subscription.\n";
		return;
	    }
	    echo date("Y-m-d H:i:s")." -> UCSM Event Subscription HTTP socket open and connected to: {$host}\n"; 
	    socket_set_nonblock($sock);
	    //Send the message to the server
	    if(!socket_send ($sock, $out, strlen($out), 0)) {
		$myErrorNum = socket_last_error($sock);
		$myErrorText = socket_strerror($myErrorNum);
		echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to APIC on current socket for new cookie update: val={$myErrorNum}: {$myErrorText}\n";
		@socket_clear_error($sock);
		return;
	    }
	    echo date("Y-m-d H:i:s")." -> APIC Event Subscription message sent successfully\n";  //{$out}\n";
	    while($returnraw=="") {
	        echo date("Y-m-d H:i:s")." -> INFORMATION: Waiting for WebSocket upgrade\n";
	        $returnraw = &socket_read($sock, 500000, PHP_BINARY_READ);
	        usleep(5000);
	    }
	    sleep(1);
	} else {
	    $fp = fsockopen("ssl://".$host, $port, $errno, $errstr, 10);
	    if (!$fp) {
	        echo date("Y-m-d H:i:s")." -> ERROR:  Could not open socket for APIC event subscriptions: $errstr ($errno)\n";
	        return;
	    } else {
	        echo date("Y-m-d H:i:s")." -> APIC Event Subscription SSL socket open and connected to: {$host}\n";
	    }
	    stream_set_blocking($fp, 0);
	    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
	    //Send the message to the server
	    if(!fwrite($fp, $out)) {
	        echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to APIC to perform event subscription.\n";
		return;		
	    }
	    echo date("Y-m-d H:i:s")." -> APIC Event Subscription message sent successfully\n";  //{$out}\n";
	    $returnraw="";
	    while($returnraw=="") {
	        echo date("Y-m-d H:i:s")." -> INFORMATION: Waiting for WebSocket upgrade\n";
	        $returnraw = fgets($fp, 8192);
	        usleep(5000);
	    }
	    sleep(1);
	}
        $this->status=true;
        $message = '';
        $return = '';
        $this->themap->apicstack['EVENT_ACTIVE']=true;
	//echo "TEST***: in APIC event subscription run function.  Just set EVENT_ACTIVE to true.\n";
        //echo "Calling RELOAD APIC Flowmap !!!!!!!!!!!!!!!!!!!!!!!\n";
        reload_flowmap_ACI($this->themap);
        $time_now = microtime(true);
        $return_tmp="";
        $returnraw_tmp="";
	$sessionIDofRecord = $this->themap->apicstack['sessionId'];	// cache this, as when we update later we will want to send on the socket again to update
        $monitorspeed_subscription=0;
        while(true) {
	    usleep(5000);
	    // If sessionId has been refreshed, re-associate this subscription socket to this process
	    if ($sessionIDofRecord !== $this->themap->apicstack['sessionId']) {
		if (!useSSL) {
		    echo date("Y-m-d H:i:s")." -> APIC Session ID has been changed, so need to open new socket for event subscriptions...";
		    $path  = $url['path'].$this->themap->apicstack['cookie'];
		    $path .= $query ? '?'. $query : '';
		    $key = base64_encode($this->apic_generateRandomString(16, false, true));
		    $out  = "GET {$path} HTTP/1.1\r\n";
		    $out .= "Upgrade: websocket\r\n";
		    $out .= "Connection: upgrade\r\n";
		    $out .= "Host: {$host}\r\n";
		    $out .= "Origin: {$protocol}{$host}\r\n";
		    $out .= "Pragma: no-cache\r\n";
		    $out .= "Cache-Control: no-cache\r\n";
		    $out .= "Sec-WebSocket-Key: '.$key.'\r\n";
		    $out .= "Sec-WebSocket-Version: 13\r\n";
		    $out .= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits, x-webkit-deflate-frame\r\n";
		    $out .= "User-Agent: Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.153 Safari/537.36\r\n";
		    $out .= $this->themap->apicstack['cookieheader']."\r\n\r\n";
		    if(!($sock = socket_create(AF_INET, SOCK_STREAM, 0))) {
		        echo date("Y-m-d H:i:s")." -> ERROR: Could not create socket for connection to APIC events.\n";
		    } 
		    if(!socket_connect($sock , $host , 80)) {
		        echo date("Y-m-d H:i:s")." -> ERROR: Could not connect to APIC on port 80 to subscribe to events.\n";
		    }
		    socket_set_nonblock($sock);
		    if(!socket_send($sock, $out, strlen($out), 0)) {
		        $myErrorNum = socket_last_error($sock);
		        $myErrorText = socket_strerror($myErrorNum);
		        echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to APIC on current socket for new cookie update: val={$myErrorNum}: {$myErrorText}\n";
		        @socket_clear_error($sock);
			return;
		    }
		    $sessionIDofRecord = $this->themap->apicstack['sessionId'];
		    echo date("Y-m-d H:i:s")." -> Sucessfully reconnected via HTTP to APIC for event subscriptions.\n";
		    $time_now = microtime(true);
		} else {
		    echo date("Y-m-d H:i:s")." -> APIC Session ID has been changed, so need to open new socket for event subscriptions...";
		    $path  = $url['path'].$this->themap->apicstack['cookie'];
		    $path .= $query ? '?'. $query : '';
		    $key = base64_encode($this->apic_generateRandomString(16, false, true));
		    $out  = "GET {$path} HTTP/1.1\r\n";
		    $out .= "Upgrade: websocket\r\n";
		    $out .= "Connection: upgrade\r\n";
		    $out .= "Host: {$host}\r\n";
		    $out .= "Origin: {$protocol}{$host}\r\n";
		    $out .= "Pragma: no-cache\r\n";
		    $out .= "Cache-Control: no-cache\r\n";
		    $out .= "Sec-WebSocket-Key: '.$key.'\r\n";
		    $out .= "Sec-WebSocket-Version: 13\r\n";
		    $out .= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits, x-webkit-deflate-frame\r\n";
		    $out .= "User-Agent: Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.153 Safari/537.36\r\n";
		    $out .= $this->themap->apicstack['cookieheader']."\r\n\r\n";
		    $fp = fsockopen("ssl://".$host, $port, $errno, $errstr, 10);
		    if (!$fp) {
			echo date("Y-m-d H:i:s")." -> ERROR:  Could not re-connect HTTPS socket on port {$port} for APIC event subscriptions: $errstr ($errno)\n";
			return;
		    }
		    stream_set_blocking($fp, 0);
		    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
		    //Send the message to the server
		    if(!fwrite($fp, $out)) {
			echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to APIC to re-start event subscription.\n";
			return;		
		    }
		    $sessionIDofRecord = $this->themap->apicstack['sessionId'];
		    //echo "12-6-14 TESTPOINT!!!!!!!\nJust Sent to {$host}: {$out}\n";
		    echo date("Y-m-d H:i:s")." -> Sucessfully reconnected via HTTPS to APIC for event subscriptions.\n";
		    $time_now = microtime(true);
		    sleep(1);
		}
		// 12-15-14 Now we need to resubscribe to all events, by getting new eventId's	    
		foreach($this->themap->APICeventids as $key=>$value) {
	            echo date("Y-m-d H:i:s")." -> APIC Event Channel Re-subcription for ({$key}) refresh id={$value}...";
	            $newEventID = NULL;
	            $keyArray = explode("<->", $key);
	            $subscriptionClass = $keyArray[0];
	            $subscriptionScope = $keyArray[1];
	            $this->themap->APICeventids[$key] = -1;	// signal to subscribe routine that we just need to re-establish
		    //echo "_________________________6-22-15 from apic.php sessionID changed area in event subscription.\n";
	            $newEventID = $this->themap->apicsession->apic_subscribe($this->themap, $subscriptionScope, $subscriptionClass);
	            if (isset($newEventID)) {
		    	echo "successful, new eventID is {$newEventID}\n";
		    } else {
		        echo "failed\n";
			$myLogMsg="********6-16-15************ TEST - event subscription to scope=".$subscriptionScope." class=".$subscriptionClass." FAILED.\n";
			if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		    }
	        }	    
	    }
	    $returnraw = NULL;
	    if (!$useSSL) {
		$returnraw = &socket_read($sock, 500000, PHP_BINARY_READ);
	    } else {
		$returnraw = fgets($fp, 8192);
	    }
	    /*
	    echo "12-15-14 TEST****** the return from last APIC subscription socket_read is: [{$returnraw}], which is: ";
	    if ($returnraw) {
		echo "True.\n";
	    } else {
		echo "False.\n";
	    }
	    */
	    if (!$returnraw) {
		if (!$useSSL) {
		    $myErrorNum = socket_last_error($sock);
		    $myErrorText = socket_strerror($myErrorNum);
		    if ($myErrorNum === 11) {
		        //echo "Nothing on APIC subscription socket waiting to be read - would block\n";
		        //if we are past a timer, then we are to assume lost
		        if(intval(microtime(true) - $time_now) >= 900) {	// 15 minutes with nothing, assume dead subscription as we write keepalive items every 5
			    echo date("Y-m-d H:i:s")." -> ERROR: No valid data seen on APIC event subscription socket in over 15 minutes, want to reset the subscriptions.\n";
			    socket_close($sock);
			    $sessionIDofRecord = -1;
			    sleep(1);
			}
			continue;
		    } elseif ($myErrorNum === 104) {	// connection reset by the APIC  - lets re-establish like at startup
		        echo date("Y-m-d H:i:s")." -> WARNING: APIC HTTP Event Subscription socket was closed by the APIC side, setting it back up.\n";
		        $path  = $url['path'].$this->themap->apicstack['cookie'];
		        $path .= $query ? '?'. $query : '';
		        $key = base64_encode($this->apic_generateRandomString(16, false, true));
		        $out  = "GET {$path} HTTP/1.1\r\n";
		        $out .= "Upgrade: websocket\r\n";
		        $out .= "Connection: upgrade\r\n";
		        $out .= "Host: {$host}\r\n";
		        $out .= "Origin: {$protocol}{$host}\r\n";
		        $out .= "Pragma: no-cache\r\n";
		        $out .= "Cache-Control: no-cache\r\n";
		        $out .= "Sec-WebSocket-Key: '.$key.'\r\n";
		        $out .= "Sec-WebSocket-Version: 13\r\n";
		        $out .= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits, x-webkit-deflate-frame\r\n";
		        $out .= "User-Agent: Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.153 Safari/537.36\r\n";
		        $out .= $this->themap->apicstack['cookieheader']."\r\n\r\n";
		        if(!($sock = socket_create(AF_INET, SOCK_STREAM, 0))) {
			    echo date("Y-m-d H:i:s")." -> ERROR: Could not create socket for connection to APIC events.\n";
			    return;
		        } 
			if(!socket_connect($sock, $host, $port)) {
			    echo date("Y-m-d H:i:s")." -> ERROR: Could not connect to APIC on port {$port} to subscribe to events.\n";
			    return;
			}
			socket_set_nonblock($sock);
			if(!socket_send($sock, $out, strlen($out), 0)) {
			    $myErrorNum = socket_last_error($sock);
			    $myErrorText = socket_strerror($myErrorNum);
			    echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to APIC on current socket for new cookie update: val={$myErrorNum}: {$myErrorText}\n";
			    @socket_clear_error($sock);
			    return;
			}		    
			$sessionIDofRecord = $this->themap->apicstack['sessionId'];
			echo date("Y-m-d H:i:s")." -> Sucessfully reconnected via HTTP to APIC for event subscriptions.\n";
			$time_now = microtime(true);
			sleep(1);
		    } else {
		        echo date("Y-m-d H:i:s")." -> APIC Event Subscripton socket read return was false with val={$myErrorNum}: {$myErrorText}\n";
		    }
		    @socket_clear_error($sock);
		} else {
		    if(feof($fp) === true) {
			echo date("Y-m-d H:i:s")." -> WARNING: APIC HTTPS Event Subscription socket was closed by the APIC side, setting it back up.\n";
		        $path  = $url['path'].$this->themap->apicstack['cookie'];
		        $path .= $query ? '?'. $query : '';
		        $key = base64_encode($this->apic_generateRandomString(16, false, true));
		        $out  = "GET {$sockUpgrade} HTTP/1.1\r\n";
		        $out .= "Upgrade: websocket\r\n";
		        $out .= "Connection: upgrade\r\n";
		        $out .= "Host: {$host}\r\n";
		        $out .= "Origin: {$protocol}{$host}\r\n";
		        $out .= "Pragma: no-cache\r\n";
		        $out .= "Cache-Control: no-cache\r\n";
		        $out .= "Sec-WebSocket-Key: '.$key.'\r\n";
		        $out .= "Sec-WebSocket-Version: 13\r\n";
		        $out .= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits, x-webkit-deflate-frame\r\n";
		        $out .= "User-Agent: Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.153 Safari/537.36\r\n";
		        $out .= $this->themap->apicstack['cookieheader']."\r\n\r\n";
			$fp = fsockopen("ssl://".$host, $port, $errno, $errstr, 10);
			if (!$fp) {
			    echo date("Y-m-d H:i:s")." -> ERROR:  Could not re-connect HTTPS socket on port {$port} for APIC event subscriptions: $errstr ($errno)\n";
			    return;
			}
			stream_set_blocking($fp, 0);
			stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			//Send the message to the server
			if(!fwrite($fp, $out)) {
			    echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to APIC to re-start event subscription.\n";
			    return;		
			}
			$sessionIDofRecord = $this->themap->apicstack['sessionId'];
			echo date("Y-m-d H:i:s")." -> Sucessfully reconnected via HTTPS to APIC for event subscriptions.\n";
			$time_now = microtime(true);
			sleep(1);
		    } else {
		        //if we are past a timer, then we are to assume lost
		        if(intval(microtime(true) - $time_now) >= 900) {	// 15 minutes with nothing, assume dead subscription as we write keepalive items every 5
			    echo date("Y-m-d H:i:s")." -> ERROR: No valid data seen on APIC event subscription socket in over 15 minutes, want to reset the subscriptions.\n";
			    socket_close($sock);
			    $sessionIDofRecord = -1;
			    sleep(1);
			}
			continue;
		    }
		}
		continue;
	    }
	    $time_now = microtime(true);	// we have actual subscription data
	    // Add in the timing update for an RX on the subscription socket.... v1.0.1e
	    $this->themap->apiccallqueue[$this->themap->apicstack['APIC_IP']] = "APIC_RX_EVENT<->".$this->themap->apicstack['APIC_IP'];
	    //echo "12-15-14 TESTING... data in on APIC-ES socket.\n";
	    if($this->themap->apicstack['ACI_MONITOR_SUBSCRIPTION'] === true) {
	        if(strlen($returnraw) > 0) {
		    $this->themap->debugger->dwrite("\nACI EVENT IN START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
		    $this->themap->debugger->dwrite($returnraw);
		    $this->themap->debugger->dwrite("ACI EVENT IN END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	        }
	        //if($monitorspeed_subscription < 500) {
		//    $monitorspeed_subscription++;
	        //} else {
		    // When I want to see what the threads are doing, I indicate with a large 'A' that we need to handle an event from APIC
		    echo "[A]";
		    $monitorspeed_subscription=0;
	        //}
	    }
	    //if($returnraw !== false) {
	    //	echo ">>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>\n";
	    //	var_dump($returnraw);
	    //	echo "<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<\n";
	    //}
	    $returnraw = substr($returnraw, strpos($returnraw, '{"subscriptionId'));
	    if($returnraw === '') {
		echo date("Y-m-d H:i:s")." -> APIC Event Subscripton breaking, strlen of returnraw string is :".strlen($returnraw)." bytes\n";
	        break;
	    }
	    if($this->printevents == true) {		
	        echo $returnraw;
	    }
	    $events = explode("~", substr($returnraw, strpos($returnraw, "{")));
	    foreach($events as $key=>$value) {
	        $tmp=substr($value, strpos($value, "{"));
	        $tmp=substr($tmp, 0, strrpos($tmp, "}")+1);
	        $events[$key]=$tmp;		 
	    }
	    $doggy=1; // set doggy to one to activate the watchdog for incoming events...
	    foreach($events as $event) {
	        if(strpos($event, "{") > -1) {
		    $return = json_decode(substr($event, strpos($event, "{")),true);
		    unset($dn);
		    unset($status);
		    if($this->printevents == true) {
		        echo "EVENT RECIEVED BEGIN===>\n";
		    }
		    $eventID=$return["subscriptionId"][0];
		    if (isset($return["imdata"]) == false) continue;
		    foreach($return["imdata"] as $indexkey=>$tmp) {
		        foreach($return["imdata"][$indexkey] as $class => $tmp) {
			    if(isset($return["imdata"][$indexkey][$class]["attributes"]["dn"])) {
			        $dn=$return["imdata"][$indexkey][$class]["attributes"]["dn"];
			        //echo "\nDN==>".$dn."\n";				
			    }		    
			    if(isset($return["imdata"][$indexkey][$class]["attributes"]["status"])) {
			        $status=$return["imdata"][$indexkey][$class]["attributes"]["status"];
			        //echo "STATUS==>".$status."\n";
			    }
			    $flag=0;
			    if($flag === 0 && isset($dn) && isset($status) && $status === "deleted") {
			        $doggy=1;
			        //echo "5-19-15: Calling remove_dn from APIC >{$class}, {$dn}, {$this->session->ip}\n";
			        remove_dn($this->themap, $class, $dn, $this->session->ip);
			        $flag=1;
			    }
			    if($flag === 0 && isset($dn) && isset($status) && $status !== "deleted") {
			        $doggy=1;				
			        unset($mo);
			        $mo[$dn]=true;
			        $mo[$class]=true;
			        foreach($return["imdata"][$indexkey][$class]["attributes"] as $key => $value) {
				    if($key === "status") {
				        $value="";
				    }
				    if($key !== "rn") {
				        //echo "5-19-15: Calling doer from APIC >{$class}, {$dn}, {$key}, {$value} {$this->session->ip}\n";
				        doer($this->themap, $class, $dn, $key, $value, $this->themap->apicsession->ip,"RAW");				
				    }
			        }
			        $flag=1;
			    }
		        }			
		    }
		    if($this->printevents == true) {
		        echo "EVENT RECIEVED END<===\n";
		    }
	        }
	        if($doggy == 0) {
		    if($return !== $return_tmp || $returnraw !== $returnraw_tmp) {
		        echo "Return:\n";
		        var_dump($return);
		        echo "Returnraw:\n";
		        var_dump($returnraw);
		        echo "Events:\n";
		        var_dump($events);
		        $return_tmp=$return;
		        $returnraw_tmp=$returnraw;
		    }
	        }
	    }
        }
        echo date("Y-m-d H:i:s")." -> APIC EVENT SUBSCRIPTION ENDING\n";  	    
        socket_close($sock);
    }
}

class apic {
    var $protocol="";
    var $ip="";
    var $username="";
    var $password="";
    var $cookie="";
    var $refreshTimeoutSeconds="";
    var $guiIdleTimeoutSeconds="";
    var $restTimeoutSeconds="";
    var $creationTime="";
    var $userName="";
    var $remoteUser="";
    var $unixUserId="";
    var $sessionId="";
    var $lastName="";
    var $firstName="";
    var $version="";
    var $buildTime="";
    var $node="";
    var $sessioninfo="";
    var $url="";
    var $baseheader="";
    var $cookieheader="";
    var $header="";
    var $_classarray;
    
    public function apic_unsubscribe(&$themap, $class, $killString) {
	switch ($class) {
	    case 'fabricEthLanPc':
		$totalString[]='<->node/mo/uni/infra/accportprof-'.$killString;
	        $totalString[]='<->node/mo/uni/infra/funcprof/accbundle-'.$killString;
	        $totalString[]='<->node/mo/uni/infra/nprof-'.$killString;
	        break;
	}
	$killCounter=0;
	foreach($themap->APICeventids as $eventKey=>$eventId) {
	    foreach($totalString as $searchString) {
		//echo "********************6-29-15 checking for {$eventKey}, against string:{$searchString}\n";
		if (strstr($eventKey,$searchString) != NULL) {
		    //echo "************6-29-15 clearing it out!!!\n";
		    unset($themap->APICeventids[$eventKey]);
		    $killCounter++;
		}
	    }
	}
	return $killCounter;
    }

    public function apic_subscribe(&$themap, $context, $class, $type='RAW') {
	date_default_timezone_set('UTC');
	//echo "11-15-14 TEST subscriptions to APIC.  Inputs to apic_subscribe are context={$context}, class={$class}, type={$type}\n";
        $classes = explode(',', $class);
	$return = NULL;
	$goToSubscribe=false;
        foreach($classes as $class) {
	    //echo "6-22-15 TEST subscriptions to APIC.  Inputs to apic_subscribe are context={$context}, class={$class}, type={$type}, and eventid[key]={$themap->APICeventids[$class.'<->'.$context]}\n";
	    if($themap->APICeventids[$class.'<->'.$context] === 1) {	// This is the first time subscribing
		$subscriptionType="initial";
		$goToSubscribe=true;
	    } elseif ($themap->APICeventids[$class.'<->'.$context] === -1) {	// This is the case where we have broken, and rebuilding the subscription
		$subscriptionType="existing";
		$goToSubscribe=true;
	    }
	    if ($goToSubscribe) {
		while (true) {
		    $requestLine="{$context}.json?query-target=subtree&target-subtree-class={$class}&subscription=yes";
		    //echo "****************6-22-15 Testing, the subscription request line is: {$requestLine}\n";
		    $return = $this->apic_get($themap, $requestLine);
		    if(isset($return["subscriptionId"])) {
		        break;
		    } else {
		        echo date("Y-m-d H:i:s")." -> ERROR:  Unsucessful {$subscriptionType} subcription request for CLASS:{$class} CONTEXT:{$context}, retrying in 1 second.\n";
			//var_dump($return);
		        sleep (1);
		    }
	        }
	        $eventid=$return["subscriptionId"];
	        $themap->APICeventids[$class.'<->'.$context]=$eventid;
		if ($subscriptionType === "initial") {
		    $subscriptionCounter=$themap->apicstack['subscriptionCounter'];
		    $subscriptionCounter++;
		    $themap->apicstack['subscriptionCounter']=$subscriptionCounter;
		    $myLogMsg=date("Y-m-d H:i:s")." -> New Subscription (number {$subscriptionCounter}) to CLASS:{$class} in CONTEXT:{$context} has id:{$eventid}\n";
		    //echo $myLogMsg;
		} else {
		    $myLogMsg=date("Y-m-d H:i:s")." -> (Re)establishing subscription to CLASS:{$class} in CONTEXT:{$context} has id:{$eventid}\n";		    
		    echo $myLogMsg;
		}
		if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
	        foreach($return["imdata"] as $indexkey=>$tmp) {
	            $dn=$return["imdata"][$indexkey][$class]["attributes"]["dn"];		    
	            $themap->storage[$dn]=true;
	            $themap->storage[$class]=true;
	            foreach($return["imdata"][$indexkey][$class]["attributes"] as $key => $value) {
	                //echo "5-19-15: Calling doer from APIC_SUBSCRIBE>{$class}, {$dn}, {$key}, {$value} {$themap->apicsession->ip} {$type}\n";
		        doer($themap, $class, $dn, $key, $value, $themap->apicsession->ip, $type);
		    }
		}
	    }
        }
	/*if (isset($return)) {
	    echo "12-6-14 TESTPOINT!!!!!!!.  Redone the apic subscribe, and returned data is:\n";
	    var_dump($return);
	    echo "12-6-14 END TEST.\n";
	}*/
        return $eventid;
    }
    
    public function __construct($protocol, $ip, $username, $password) {
        //echo "Initializing Session object in apic class __construct...\n";
        $this->protocol = $protocol;
        $this->ip = $ip;
        $this->username = $username;
        $this->password = $password;
        $this->url = $this->protocol."://".$this->ip."/api/"; 
        $header = "";
        $header.="Host: ".$this->ip."\r\n";
        $header .= "Accept: text/html,application/xhtml+xml,application/xml,application/json\r\n";
        //$header .= "Origin: ".$this->protocol."://".$this->ip."\r\n";
        $header .= "X-Requested-With: XMLHttpRequest\r\n";
        $header .= "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:38.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.153 Safari/537.36\r\n";
        $header .= "Content-Type: application/json; charset=UTF-8\r\n";
        $header .= "Referer: ".$this->protocol."://".$this->ip."/\r\n";
        $header .= "Accept-Encoding: gzip,deflate\r\n";
        $header .= "Accept-Language: en-US,en;q=0.5\r\n";
	$header .= "Pragma: no-cache\r\n";
	$header .= "Cache-Control: no-cache\r\n";
        $this->baseheader = $header;
        $this->_classarray = Array(Array(Array()));
    }
    
    private function apic_http_post_flds($rest, $payload, $headers=null, &$sendItem, &$replyItem) {
        $url=$this->url.$rest;
        $opts = array('http' => array('method' => 'POST', 'content' => $payload));
        if($headers) {
	    $opts['http']['header'] = $headers;
        }
        $st = stream_context_create($opts);
	$sendItem=$payload;
        $fp = @fopen($url, 'rb', false, $st);
        $result="";
        if(!$fp) {
	    $result=false;
        } else {
	    $result = stream_get_contents($fp);
	    fclose($fp);
        }
	$replyItem=$result;
        return $result;
    }

    private function apic_https_post_flds($rest, $payload, $headers=null, &$sendItem, &$replyItem) {
	$sendItem = $payload;
        if($headers) {
	    // For the CURL case we need to transform the header array from a long text string with \r\n into array
	    $headerArray=explode("\r\n", $headers);
	    foreach($headerArray as $arrayIndex=>$arrayItem) {
		$curlHeader[]=$arrayItem;
	    }
	    $opts = array(CURLOPT_URL=>$this->url.$rest, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
			  CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_HTTPHEADER=>$curlHeader, CURLOPT_TIMEOUT => 10);
        } else {
	    $opts = array(CURLOPT_URL=>$this->url.$rest, CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload, CURLOPT_SSL_VERIFYPEER=>false,
			  CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_TIMEOUT => 10);    
	}
	//echo "***********7-5-15****:  in APIC https post, sending URL: POST {$this->url}{$rest}, with payload={$payload}, and header vardump is:\n";
	//var_dump($headers);
	//echo "*****transformed:*****\n";
	//var_dump($curlHeader);
	$ch=curl_init();
	curl_setopt_array($ch, $opts);
	$result="";
	$result = curl_exec($ch);
	if (!$result) {
	    echo "ERROR:  HTTPS write failed and we had a false return: [".curl_error($ch)."]\n";
	    $result=false;
	} else {
	    curl_close($ch);
	}
	//echo "*******7-5-15******  APIC POST result vardump:\n";
	//var_dump($result);
	$replyItem=$result;
	return $result;
    }

    private function apic_https_get_flds($rest, $headers=null, &$sendItem, &$replyItem) {
	date_default_timezone_set('UTC');
        $orig = $this->url.$rest;
	$url = parse_url($this->url.$rest);
	$host = parse_url($this->url.$rest, PHP_URL_HOST);
	$path = parse_url($this->url.$rest, PHP_URL_PATH);
	$query = parse_url($this->url.$rest, PHP_URL_QUERY);
        $path .= $query ? '?'. $query : '';
	//echo "***7-6-15 test... orig={$orig}, url={$url}, host={$host}, path={$path}, query={$query}\n";
        $out = "https://".$host.$path;
	$sendItem = $out;
	// For the CURL case we need to transform the header array from a long text string with \r\n into array
        if($headers) {
	    $headerArray=explode("\r\n", $headers);
	    foreach($headerArray as $arrayIndex=>$arrayItem) {
	        $curlHeader[]=$arrayItem;
	    }
	}
	$opts = array(CURLOPT_URL => $out, CURLOPT_HTTPHEADER => $curlHeader, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPGET => true, CURLOPT_SSL_VERIFYPEER => false,
		      CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_TIMEOUT => 10);
	//echo "***********7-6-15****:  in APIC https get, sending URL: GET {$out}, and header vardump is:\n";
	//var_dump($headers);
	//echo "*****transformed:*****\n";
	//var_dump($curlHeader);
	$ch = curl_init();
	curl_setopt_array($ch, $opts);
	$result="";
	$result = curl_exec($ch);
	if (!$result) {
	    echo date("Y-m-d H:i:s")." -> INFORMATION: APIC GET had a false return for the request: {$out} [".curl_error($ch)."]\n";
	} else {
	    curl_close($ch);
	}
	//echo "*******7-6-15******  APIC GET result vardump:\n";
	//var_dump($result);
	$replyItem = $result;
        return $result;
    }
    
    private function apic_http_get_flds($rest, $headers=null, &$sendItem, &$replyItem) {
	date_default_timezone_set('UTC');
        $url   = parse_url($this->url.$rest);
	$host = parse_url($this->url.$rest, PHP_URL_HOST);
	$path = parse_url($this->url.$rest, PHP_URL_PATH);
	$query = parse_url($this->url.$rest, PHP_URL_QUERY);
        $path .= $query ? '?'. $query : '';	    
        $out = "GET {$path} HTTP/1.1\r\n";
        $out .= $headers."\r\n\r\n";
	
	$sendItem = $out;
        if(!($sock = socket_create(AF_INET, SOCK_STREAM, 0))) {
	    echo date("Y-m-d H:i:s")." -> ERROR: Could not create socket for APIC GET.\n";
        }
        //echo "Socket created\n";    
        //Connect socket to remote server
        if(!socket_connect($sock , $this->ip , 80)) {
	    echo date("Y-m-d H:i:s")." -> ERROR: Could not connect to APIC port 80 for GET.\n";
        }
        //echo "Connection established_E\n";
        //Send the message to the server
        if(!socket_send ($sock, $out, strlen($out), 0)) {
	    echo date("Y-m-d H:i:s")." -> ERROR: Could not send GET to APIC.\n";
        }
        //echo "Message send successfully_E: {$out}\n";
        socket_set_nonblock($sock);
        $receiveStartTime = microtime(true);
        $response = '';
        $retval = false;
        while(microtime(true) - $receiveStartTime < 0.2) {	// here we just wait .2 seconds for any immediate command response
	    $n = @socket_recv($sock, $dataIn, 50000, 0);
	    if ($n) {
	        $response .= $dataIn;
	    }
	    if(socket_last_error($sock) == 35) {
	        @socket_clear_error($sock);
	    }
        }
        if (socket_last_error($sock) > 0 && socket_last_error($sock) !== 11) {
	    echo date("Y-m-d H:i:s")." -> Last Error: ".socket_last_error($sock);
	    $this->lastErrorNum = socket_last_error($sock);
	    $this->lastErrorMsg = 'Unable to read from socket: ' . socket_strerror($this->lastErrorNum);
	    @socket_clear_error($sock);
	    echo date("Y-m-d H:i:s")." -> ".$this->lastErrorMsg;
	    $response = '';
        } else {
	    $response = substr($response, strpos($response, '{"totalCount'));
	    $response = substr($response, strpos($response, '{"imdata":'));		
	    $response = substr($response, 0, strrpos($response, '}') +1);
        }
        //echo "TESTACIREFRESH-GET-RESPONSE: ".$response."\n";
	$replyItem = $response;
	socket_close($sock);
        return $response;
    }
    
    function apic_post(&$themap, $rest, $payload) {
        $result = json_decode($this->apic_https_post_flds($rest,$payload,$themap->apicstack['header'],$send,$reply),true);
        if($themap->apicstack['ACI_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nACI UPDATE POST START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("ACI UPDATE POST END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nACI UPDATE POST REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("ACI UPDATE POST REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        return $result;	
    }
    
    function apic_get(&$themap, $rest) {
        $extraheader="";
        if(strpos($rest, 'subscription=yes') !== false) {
	    $extraheader.="Connection: keep-alive\r\n";
        }
        $result = json_decode($this->apic_https_get_flds($rest,$extraheader.$themap->apicstack['header'], $send, $reply),true);
        if($themap->apicstack['ACI_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nACI UPDATE GET START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("ACI UPDATE GET END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nACI UPDATE GET REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("ACI UPDATE GET REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
	//echo "TEST 11-13-14 apic_get return: {$reply}\n";
        return $result;	
    }
    
    function apic_aaaLogin(&$themap) {
        $themap->apicstack['username'] = $this->username;
        $themap->apicstack['password'] = $this->password;
        $this->sessioninfo = $this->apic_https_post_flds("aaaLogin.json", '{"aaaUser" : {"attributes" : {"name" : "'.$themap->apicstack['username'].'", "pwd" : "'.$themap->apicstack['password'].'"}}}',$this->baseheader, $send, $reply);
        if($themap->apicstack['ACI_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nACI UPDATE POST START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("ACI UPDATE POST END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nACI UPDATE POST REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("ACI UPDATE POST REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        $data = json_decode($this->sessioninfo,true);
        if(isset($data["imdata"][0]["aaaLogin"]["attributes"]["token"])) {
	    $themap->apicstack['cookie'] = $data["imdata"][0]["aaaLogin"]["attributes"]["token"];
	    $themap->apicstack['refreshTimeoutSeconds'] = $data["imdata"][0]["aaaLogin"]["attributes"]["refreshTimeoutSeconds"];
	    $themap->apicstack['guiIdleTimeoutSeconds'] = $data["imdata"][0]["aaaLogin"]["attributes"]["guiIdleTimeoutSeconds"];
	    $themap->apicstack['restTimeoutSeconds'] = $data["imdata"][0]["aaaLogin"]["attributes"]["restTimeoutSeconds"];
	    $themap->apicstack['creationTime'] = $data["imdata"][0]["aaaLogin"]["attributes"]["creationTime"];
	    $themap->apicstack['userName'] = $data["imdata"][0]["aaaLogin"]["attributes"]["userName"];
	    $themap->apicstack['remoteUser'] = $data["imdata"][0]["aaaLogin"]["attributes"]["remoteUser"];
	    $themap->apicstack['unixUserId'] = $data["imdata"][0]["aaaLogin"]["attributes"]["unixUserId"];
	    $themap->apicstack['sessionId'] = $data["imdata"][0]["aaaLogin"]["attributes"]["sessionId"];
	    $themap->apicstack['lastName'] = $data["imdata"][0]["aaaLogin"]["attributes"]["lastName"];
	    $themap->apicstack['firstName'] = $data["imdata"][0]["aaaLogin"]["attributes"]["firstName"];
	    $themap->apicstack['version'] = $data["imdata"][0]["aaaLogin"]["attributes"]["version"];
	    $themap->apicstack['buildTime'] = $data["imdata"][0]["aaaLogin"]["attributes"]["buildTime"];
	    $themap->apicstack['node'] = $data["imdata"][0]["aaaLogin"]["attributes"]["node"];
	    $themap->apicstack['cookieheader'] = 'Cookie: APIC-cookie='.$themap->apicstack['cookie'];
	    $themap->apicstack['header'] = $this->baseheader . $themap->apicstack['cookieheader'];
	    return true;
	} else {
	    return false;
	}
    }
    
    function apic_aaaLogout(&$themap) {
        $result = $this->apic_https_post_flds("aaaLogout.json", '{"aaaUser" : {"attributes" : {"name" : "'.$username.'"}}}',$themap->apicstack['header'], $send, $reply);
        if($themap->apicstack['ACI_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nACI UPDATE POST START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("ACI UPDATE POST END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nACI UPDATE POST REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("ACI UPDATE POST REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        return $result;
    }
    
    function apic_aaarefresh(&$themap) {
        $this->sessioninfo = $this->apic_https_get_flds("aaaRefresh.json",  $themap->apicstack['header'], $send, $reply);
        if($themap->apicstack['ACI_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nACI UPDATE GET START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("ACI UPDATE GET END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nACI UPDATE GET REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("ACI UPDATE GET REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
	//echo "TEST 11-13-14 apic_aaarefresh sent: {$send}\nreturn: {$reply}\n";
	$data = json_decode($this->sessioninfo,true);
        if(isset($data["imdata"][0]["aaaLogin"]["attributes"]["token"])) {
	    $themap->apicstack['cookie'] = $data["imdata"][0]["aaaLogin"]["attributes"]["token"];
	    $themap->apicstack['refreshTimeoutSeconds'] = $data["imdata"][0]["aaaLogin"]["attributes"]["refreshTimeoutSeconds"];
	    $themap->apicstack['guiIdleTimeoutSeconds'] = $data["imdata"][0]["aaaLogin"]["attributes"]["guiIdleTimeoutSeconds"];
	    $themap->apicstack['restTimeoutSeconds'] = $data["imdata"][0]["aaaLogin"]["attributes"]["restTimeoutSeconds"];
	    $themap->apicstack['creationTime'] = $data["imdata"][0]["aaaLogin"]["attributes"]["creationTime"];
	    $themap->apicstack['userName'] = $data["imdata"][0]["aaaLogin"]["attributes"]["userName"];
	    $themap->apicstack['remoteUser'] = $data["imdata"][0]["aaaLogin"]["attributes"]["remoteUser"];
	    $themap->apicstack['unixUserId'] = $data["imdata"][0]["aaaLogin"]["attributes"]["unixUserId"];
	    $themap->apicstack['sessionId'] = $data["imdata"][0]["aaaLogin"]["attributes"]["sessionId"];
	    $themap->apicstack['lastName'] = $data["imdata"][0]["aaaLogin"]["attributes"]["lastName"];
	    $themap->apicstack['firstName'] = $data["imdata"][0]["aaaLogin"]["attributes"]["firstName"];
	    $themap->apicstack['version'] = $data["imdata"][0]["aaaLogin"]["attributes"]["version"];
	    $themap->apicstack['buildTime'] = $data["imdata"][0]["aaaLogin"]["attributes"]["buildTime"];
	    $themap->apicstack['node'] = $data["imdata"][0]["aaaLogin"]["attributes"]["node"];
	    $themap->apicstack['cookieheader'] = 'Cookie: APIC-cookie='.$themap->apicstack['cookie'];
	    $themap->apicstack['header'] = $this->baseheader . $themap->apicstack['cookieheader'];
	    return true;
	} else {
	    return false;
	}
    }

    function apic_topinforefresh(&$themap) {
	$extraheader ="Connection: keep-alive\r\n";
	$value='';
	for ($i=0; $i<13; $i++) {
	    $value .= mt_rand(0,9);
	}
        $result = $this->apic_https_get_flds("node/mo/info.json?_dc={$value}", $extraheader.$themap->apicstack['header'], $send, $reply);
        if($themap->apicstack['ACI_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nACI UPDATE GET START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("ACI UPDATE GET END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nACI UPDATE GET REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("ACI UPDATE GET REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        $result_urlencoded = http_build_query(array("" => $result));
	$data = json_decode($result,true);
        return true;
    }
    

    // 11-9-14:
/*    
    function apic_get_vmmDomP(&$themap) {
        $dn=array();
        $return = $this->apic_get($themap, "node/class/vmmDomP.json");
        foreach($return['imdata'] as $system){
	    $dn[]=$system['vmmDomP']['attributes']['dn'];
        }
        return $dn;
    }
    */
    // 11-9-14:

    // 11-9-14:
/*    function apic_get_fvnsEncapBlk(&$themap, $vmmDomP) {
        $provider=$this->apic_get($themap, "node/mo/".$vmmDomP.".json?query-target=children&target-subtree-class=infraRsVlanNs");
        foreach($provider['imdata'] as $item){
	    $dnvlanpool=$item['infraRsVlanNs']['attributes']['tDn'];
	    //echo "Found dn for VLAN Pool: ".$dnvlanpool."\n";   	    
	    $vlanpool=$this->apic_get($themap, "node/mo/".$dnvlanpool.".json?query-target=children&target-subtree-class=fvnsEncapBlk");
	    foreach($vlanpool['imdata'] as $key=>$vlanpool){
	        $vlanpool['fvnsEncapBlk']['attributes']['from'] = substr($vlanpool['fvnsEncapBlk']['attributes']['from'],5,10);
	        $vlanpool['fvnsEncapBlk']['attributes']['to'] = substr($vlanpool['fvnsEncapBlk']['attributes']['to'],5,10);
	    }
        }
        return $vlanpool['fvnsEncapBlk']['attributes'];
    }
    */
    // 11-9-14:
    
//Get variables etc
    //function apic_get_token( )  {
    //    return $this->cookie;
    //}
    //function apic_get_refreshTimeoutSeconds( )  {
    //    return $this->refreshTimeoutSeconds;
    //}
    //function apic_get_guiIdleTimeoutSeconds( )  {
    //    return $this->guiIdleTimeoutSeconds;
    //}
    //function apic_get_restTimeoutSeconds( )  {
    //    return $this->restTimeoutSeconds;
    //}
    //function apic_get_creationTime( )  {
    //    return $this->creationTime;
    //}
    //function apic_get_userName( )  {
    //    return $this->userName;
    //}
    //function apic_get_remoteUser( )  {
    //    return $this->remoteUser;
    //}
    //function apic_get_unixUserId( )  {
    //    return $this->unixUserId;
    //}
    //function apic_get_sessionId( )  {
    //    return $this->sessionId;
    //}
    //function apic_get_lastName( )  {
    //    return $this->lastName;
    //}
    //function apic_get_firstName( )  {
    //    return $this->firstName;
    //}
    //function apic_get_version( )  {
    //    return $this->version;
    //}
    //function apic_get_buildTime( )  {
    //    return $this->buildTime;
    //}
    //function apic_get_node( )  {
    //    return $this->node;
    //}
    //function apic_get_sessioninfo() {
    //    return $this->sessioninfo;
    //}
    //function apic_get_header() {
    //    return $this->header;
    //}
}

?>
