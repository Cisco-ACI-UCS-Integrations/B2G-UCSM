<?PHP

// This class will handle all the needed UCSM object creation and deletion
class ucs_updater extends Thread {
    private $session="";
    private $themap;

    public function __construct(&$themap, Array $properties=array(array())) {
	date_default_timezone_set('UTC');
        echo date("Y-m-d H:i:s")." -> Initializing UCSM Updater object\n";
        $this->themap = $themap;
        $this->session = $themap->ucssession;
        $this->storage = $themap->storage;
        $this->printevents=false;		// This will indicate if we want to see the UCSM manipulations we are doing
        $this->flowmap=$themap->flowmap;
        $this->attributemap=$themap->attributemap;
        //echo "Initializing UCS Updater object Done.\n";
    }

    public function run() {	    
	date_default_timezone_set('UTC');
        echo date("Y-m-d H:i:s")." -> Starting UCSM Updater\n";
        $time_now = microtime(true);
	$UCSsubcriptions_time_now = microtime(true);
	$displayUCSrxCount=false;
	$UCSrxCount=0;
	$last_socket_rx_time = microtime(true);
        $return="";
        while($this->themap->ucsstack['EVENT_ACTIVE'] !== true || $this->themap->apicstack['EVENT_ACTIVE'] !== true) {
	    if($this->themap->apicstack['EVENT_ACTIVE'] !== true && $this->themap->ucsstack['EVENT_ACTIVE'] !== true)
		echo date("Y-m-d H:i:s")." -> UCS Updater: Waiting for APIC and UCS Eventsubscription to start (performing initial APIC and UCSM data gathering)...\n";
	    if($this->themap->apicstack['EVENT_ACTIVE'] === true && $this->themap->ucsstack['EVENT_ACTIVE'] !== true)
	        echo date("Y-m-d H:i:s")." -> UCS Updater: Waiting for UCS Eventsubscription to start (performing initial UCSM data gathering)...\n";
	    if($this->themap->apicstack['EVENT_ACTIVE'] !== true && $this->themap->ucsstack['EVENT_ACTIVE'] === true)
	        echo date("Y-m-d H:i:s")." -> UCS Updater: Waiting for APIC Eventsubscription to start (performing initial APIC data gathering)...\n";
	    sleep(1);
        }
        echo date("Y-m-d H:i:s")." -> UCSM initial updater thread synchronization starting on domain: [{$this->themap->ucsstack['VIP']}], name: [{$this->themap->ucsstack['name']}]\n";
	//Initial Configurations - we are the stimulus on program startup to do all these - then rely on the returned DOER event to build synthetic object items like normal
        if (($this->storage['userstory'] == 1) || ($this->storage['userstory'] == 2) || ($this->storage['userstory'] == 10)) {  // VMM with VLAN or VXLAN backing userstory
            echo date("Y-m-d H:i:s")." -> Setting the startup UCSM objects for the VMM cases: Multicast Policy for VXLAN, NCP for LLDP, MTU of BE class, ACI HV QoS policy, and ACI VMQ policy\n";
	    $tempdn="org-root/nwctrl-ACI-LLDP";
            $tempKey=$this->session->ip."<1>".$this->themap->apicstack['APIC_IP']."<2>".$tempdn."<3>VMM_LLDPNCP";
	    if (isset($this->themap->storage[$tempKey]) == false) {
		$tmp=$this->themap->storage[$tempKey];
		$tmp = array("CLASS"=>"nwctrlDefinition", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="UCS Startup (nwctrlDefinition): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	    $tempdn="org-root/nwctrl-ACI-LLDP/mac-sec";
            $tempKey=$this->session->ip."<1>".$this->themap->apicstack['APIC_IP']."<2>".$tempdn."<3>VMM_LLDPMACSEC";
	    if (isset($this->themap->storage[$tempKey]) == false) {
		$tmp=$this->themap->storage[$tempKey];
		$tmp = array("CLASS"=>"dpsecMac", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="UCS Startup (dpsecMac): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	    $tempdn="fabric/lan/classes/class-best-effort";
            $tempKey=$this->session->ip."<1>".$this->themap->apicstack['APIC_IP']."<2>".$tempdn."<3>VMM_MTUBE";
	    if (isset($this->themap->storage[$tempKey]) == false) {
		$tmp=$this->themap->storage[$tempKey];
		$tmp = array("CLASS"=>"qosclassEthBE", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="UCS Startup (qosclassEthBE): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	    $tempdn="org-root/ep-qos-ACIleafHV";
            $tempKey=$this->session->ip."<1>".$this->themap->apicstack['APIC_IP']."<2>".$tempdn."<3>VMM_QOSDEF";
	    if (isset($this->themap->storage[$tempKey]) == false) {
		$tmp=$this->themap->storage[$tempKey];
		$tmp = array("CLASS"=>"epqosDefinition", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="UCS Startup (epqosDefinition): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	    $tempdn="org-root/ep-qos-ACIleafHV/egress";
            $tempKey=$this->session->ip."<1>".$this->themap->apicstack['APIC_IP']."<2>".$tempdn."<3>VMM_EGRESSQOS";
	    if (isset($this->themap->storage[$tempKey]) == false) {
		$tmp=$this->themap->storage[$tempKey];
		$tmp = array("CLASS"=>"epqosEgress", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="UCS Startup (epqosEgress): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	    $tempdn="org-root/vmq-con-ACIleafVMQ";
            $tempKey=$this->session->ip."<1>".$this->themap->apicstack['APIC_IP']."<2>".$tempdn."<3>VMM_VMQCONPOL";
	    if (isset($this->themap->storage[$tempKey]) == false) {
		$tmp=$this->themap->storage[$tempKey];
		$tmp = array("CLASS"=>"vnicVmqConPolicy", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="UCS Startup (vnicVmqConPolicy): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	}
	if (($this->storage['userstory'] == 2) || ($this->storage['userstory'] == 10)) {  // VMM with VLAN or VXLAN backing userstory
	    $tempdn="org-root/mc-policy-for-VXLAN-mcast";
            $tempKey=$this->session->ip."<1>".$this->themap->apicstack['APIC_IP']."<2>".$tempdn."<3>VMM_MCAST_POL";
	    if (isset($this->themap->storage[$tempKey]) == false) {
		$tmp=$this->themap->storage[$tempKey];
		$tmp = array("CLASS"=>"fabricMulticastPolicy", "peerdn"=>array($tempdn=>true), "Adminstate"=>"created");
		$myLogMsg="UCS Startup (fabricMulticastPolicy): doing the initial memory object creation for {$tempKey}\n";
		if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		$this->themap->storage[$tempKey]=$tmp;
	    }
	}
	$keepaliveVLANname="B2GKEEPALIVE";
	//In BM case, the vlan for a template are deleted prior to the template, we need to re-order the reconstruction to last remove first back
	$BMtemplateCacheVLANinfoXml='';
	//Now the perpetual looping and handling of items with respect to UCSM
	$monitorspeed_update=0;
	//Add array for extra config re-writing
	$VMMtemplateCacheVLANinfoXml = array();
        while(true) {
	    //Start of XML write and event testing v1.0.1e
	    if(intval(microtime(true) - $last_socket_rx_time) >= 330) {
		$actionType="created";
		$xml='<fabricVlan dn="fabric/lan/net-'.$keepaliveVLANname.'" name="'.$keepaliveVLANname.'" status="'.$actionType.'"></fabricVlan>';
	        echo date("Y-m-d H:i:s").": >>>>>Keepalive UCS XML Send for Event: ".date("Y-m-d H:i:s").": {$xml}\n";
		$this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		$return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		usleep(50000);
		$actionType="deleted";
		$xml='<fabricVlan dn="fabric/lan/net-'.$keepaliveVLANname.'" name="'.$keepaliveVLANname.'" status="'.$actionType.'"></fabricVlan>';
	        echo date("Y-m-d H:i:s").": >>>>>Keepalive UCS XML Send for Event: ".date("Y-m-d H:i:s").": {$xml}\n";
		$this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		$return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		//$last_socket_rx_time = microtime(true);	// this should be updated by the reception of event(s)
	    }
	    //11-24-14: End of XML write and event testing	    
	    if($this->themap->ucsstack['UCS_MONITOR_UPDATE'] === true) {
	        if($monitorspeed_update < 100) {
		    $monitorspeed_update++;
	        } else {
		    // When I want to see what the threads are doing, I indicate with a small 'u' that we need to write some data to UCSM
		    echo "[u]";
		    $monitorspeed_update=0;
	        }
	    }
	    //Output the UCSMeventids count that we actually have read every 30 seconds or so, like the ACI side
	    if(intval(microtime(true) - $UCSsubcriptions_time_now) >= 30) {
		$UCSsubcriptions_time_now = microtime(true);
		$countRead=count($this->themap->UCSMeventids);
		echo date("Y-m-d H:i:s")." -> B2G is filtering {$countRead} UCSM entities in the respective classes on its single subscription\n";
		$displayUCSrxCount=true;	// next we will throw a message on the RX events received in this time
	    }
	    //Refresh cookie every 600 seconds or so...
	    if(intval(microtime(true) - $time_now) >= 600) {
	        $refreshOK=$this->session->ucs_aaarefresh($this->themap);
		if ($refreshOK) {
		    $time_now = microtime(true);
		    echo date("Y-m-d H:i:s")." -> UCS Cookie refreshed\n";
		} else {
		    echo date("Y-m-d H:i:s")." -> UCS Cookie refresh FAILED\n";
		}
	    }
	    usleep(50000);
	    // Now we check to see if any pending create or delete events there for UCSM
	    foreach($this->storage as $key => $value) {	    
		// Now we check to see if any pending events are out there to create any needed UCS fabric VLAN's from the EPG's mapped here
	        if(strpos($key,'<1>'.$this->session->ip.'<2>') > -1 && strpos($key,'<3>BM_ROOT') !== false) {
		    $AsideUplink = "";
		    $BsideUplink = "";
		    if ($this->themap->ucsstack['MANAGE_DJL2']) {
		        if (isset($this->themap->ucsstack['fabricAvpc'])) {
			    $atmpVPCid=$this->themap->ucsstack['fabricAvpc'];
			    $aVPCid=substr($atmpVPCid,3);  // starts after 'pc-'
		            $AsideUplink = '<fabricEthVlanPc portId="'.$aVPCid.'" switchId="A"/>';	// The A fabric disjoint L2 uplink to utilize
			}
		        if (isset($this->themap->ucsstack['fabricBvpc'])) {
			    $btmpVPCid=$this->themap->ucsstack['fabricBvpc'];
			    $bVPCid=substr($btmpVPCid,3);
			    $BsideUplink = '<fabricEthVlanPc portId="'.$bVPCid.'" switchId="B"/>';	// The B fabric disjoint L2 uplink to utilize
			}
		    }
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if(($Adminstate == 'created') || ($Adminstate == 'modified')) {	// this was created on the synthetic object in memory, so write to the UCS domain
		        $default="";	// here we bring in the default settings from the flowmap definition
			if(isset($this->storage[$key]['fabricVlan_defaults'])) {
			    foreach($this->storage[$key]['fabricVlan_defaults'] as $key1 => $value1) {
			        $default .=  $key1.'="'.$value1.'" ';
			    }
			}
		        $xml='<fabricVlan dn="'.$this->storage[$key]["fabricVlan_dn"].'" name="'.$this->storage[$key]['fabricVlan_name'];
			$xml.='" id="'.$this->storage[$key]["fabricVlan_id"].'" '.$default.' status="'.$Adminstate.'">'.$AsideUplink.$BsideUplink.'</fabricVlan>';
		        $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		        $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			$myConstructor=get_constructor($this->themap, "fabricVlan");
			$this->themap->UCSMeventids[$myConstructor.'<->fabricVlan<->'.$this->storage[$key]["fabricVlan_dn"]] = true;
			$myConstructor=get_constructor($this->themap, "fabricEthVlanPc");
			if (isset($aVPCid)) {
			    $this->themap->UCSMeventids[$myConstructor.'<->fabricEthVlanPc<->'.$this->storage[$key]["fabricVlan_dn"].'/pc-switch-A-pc-'.$aVPCid] = true;
			}
			if (isset($bVPCid)) {
			    $this->themap->UCSMeventids[$myConstructor.'<->fabricEthVlanPc<->'.$this->storage[$key]["fabricVlan_dn"].'/pc-switch-B-pc-'.$aVPCid] = true;
			}
		        //echo "6-30-15 working, sending to UCS: [{$xml}], return vardump is:\n";
		        //var_dump($return);
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";		// clear the adminstate when done
		        $this->storage[$key]=$tmp;
		    } elseif ($Adminstate == 'deleted') {
		        if($this->storage[$key]['CLASS'] === 'fabricVlan') {
		            $xml='<fabricVlan dn="'.$this->storage[$key]["fabricVlan_dn"].'" name="'.$this->storage[$key]['fabricVlan_name'].'" status="deleted"></fabricVlan>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "fabricVlan");
			    unset($this->themap->UCSMeventids[$myConstructor.'<->fabricVlan<->'.$this->storage[$key]["fabricVlan_dn"]]);
			    $myConstructor=get_constructor($this->themap, "fabricEthVlanPc");
			    if (isset($aVPCid)) {
			        unset($this->themap->UCSMeventids[$myConstructor.'<->fabricEthVlanPc<->'.$this->storage[$key]["fabricVlan_dn"].'/pc-switch-A-pc-'.$aVPCid]);
			    }
			    if (isset($bVPCid)) {
			        unset($this->themap->UCSMeventids[$myConstructor.'<->fabricEthVlanPc<->'.$this->storage[$key]["fabricVlan_dn"].'/pc-switch-B-pc-'.$aVPCid]);
			    }
		            unset($this->storage[$key]);
		        }
		    }
	        }
		// Now we check to see if any pending events are out there to create or modify vNIC adapter templates
	        if(strpos($key,'<1>'.$this->session->ip.'<2>') > -1 && strpos($key,'<3>BM_CHILD') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'vnicLanConnTempl') {
			if(($Adminstate == 'created') || ($Adminstate == 'modified')) {	// this was created on the synthetic object in memory, so write to the UCS domain
		            $default="";	// here we bring in the default settings from the flowmap definition
			    if ($this->storage['userstory'] == 3) {
				$index=get_flowmap($this->themap, 'BM', $this->storage[$key]['CLASS']);
				foreach($this->flowmap[$index]['DEST_DEFAULT'] as $key1 => $value1) {
				    $default .=  $key1.'="'.$value1.'" ';
				}
			    } elseif ($this->storage['userstory'] == 10) {
				$index=get_flowmap($this->themap, 'VMM-BM', $this->storage[$key]['CLASS']);				
				foreach($this->flowmap[$index]['DEST_BM_DEFAULT'] as $key1 => $value1) {
				    $default .=  $key1.'="'.$value1.'" ';
				}
			    }
		            $xml = '<vnicLanConnTempl dn="'.$this->storage[$key]["vnicLanConnTempl_dn"].'" name="'.$this->storage[$key]['vnicLanConnTempl_name'];
			    $xml .= '" switchId="'.$this->storage[$key]['vnicLanConnTempl_switchId'].'" descr="'.$this->storage[$key]['vnicLanConnTempl_descr'];
			    $xml .= '" '.$default.' status="'.$Adminstate.'"></vnicLanConnTempl>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "vnicLanConnTempl");
			    $this->themap->UCSMeventids[$myConstructor.'<->vnicLanConnTempl<->'.$this->storage[$key]["vnicLanConnTempl_dn"]] = true;
			    //echo "6-30-15 working, sending to UCS: [{$xml}], return vardump is:\n";
			    //var_dump($return);
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";	// clear the adminstate when done
		            $this->storage[$key]=$tmp;
			    foreach($BMtemplateCacheVLANinfoXml as $extraKey => $extraXML) {
				if (strstr($extraKey, $this->storage[$key]["vnicLanConnTempl_dn"]) != NULL) {
				    // now we send the VLAN membership that is cached
				    $this->themap->debugger->dwrite("XML OUT TO UCS: {$extraXML}\n");
				    $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$extraXML);
				    //echo "6-30-15 working, sending to UCS: [{$extraXML}], return vardump is:\n";
				    //var_dump($return);
				    unset($BMtemplateCacheVLANinfoXml[$extraKey]);
				}
			    }
    			} elseif ($Adminstate == 'deleted') {
		            $xml='<vnicLanConnTempl dn="'.$this->storage[$key]["vnicLanConnTempl_dn"].'" name="'.$this->storage[$key]['vnicLanConnTempl_name'].'" status="deleted"></vnicLanConnTempl>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "vnicLanConnTempl");
			    unset($this->themap->UCSMeventids[$myConstructor.'<->vnicLanConnTempl<->'.$this->storage[$key]["vnicLanConnTempl_dn"]]);
		            unset($this->storage[$key]);
		        }
		    }
	        }
		// Here we work the scanning and possible restoration of vnic interface
	        if(strpos($key,'<1>'.$this->session->ip.'<2>') > -1 && strpos($key,'<3>BM_CHILD') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'vnicEtherIf') {
			if(($Adminstate == 'created') || ($Adminstate == 'modified')) {	// this was created on the synthetic object in memory, so write to the UCS domain
		            $default="";
			    if ($this->storage['userstory'] == 3) {
				$index=get_flowmap($this->themap, 'BM', $this->storage[$key]['CLASS']);
				foreach($this->flowmap[$index]['DEST_DEFAULT'] as $key1 => $value1) {
				    $default .=  $key1.'="'.$value1.'" ';
				}
			    } elseif ($this->storage['userstory'] == 10) {
				$index=get_flowmap($this->themap, 'VMM-BM', $this->storage[$key]['CLASS']);
				foreach($this->flowmap[$index]['DEST_BM_DEFAULT'] as $key1 => $value1) {
				    $default .=  $key1.'="'.$value1.'" ';
				}
			    }
		            $xml = '<vnicEtherIf dn="'.$this->storage[$key]["vnicEtherIf_dn"].'" name="'.$this->storage[$key]['vnicEtherIf_name'];
			    $xml .= '" rn="if-'.$this->storage[$key]['vnicEtherIf_name'].'" '.$default.' status="'.$Adminstate.'"></vnicEtherIf>';
			    $BMtemplateCacheVLANinfoXml[$this->storage[$key]["vnicEtherIf_dn"]]=$xml;	// we cache this, in case the vnic template was deleted then I need to send again after that is recreated
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "vnicEtherIf");
			    $this->themap->UCSMeventids[$myConstructor.'<->vnicEtherIf<->'.$this->storage[$key]["vnicEtherIf_dn"]] = true;
			    //echo "6-30-15 working, sending and caching for later use: [{$xml}]\n";
			    $tmp=$this->storage[$key];
			    $tmp["Adminstate"] = "";
		            $this->storage[$key]=$tmp;
			} elseif ($Adminstate == 'deleted') {
		            $xml='<vnicEtherIf dn="'.$this->storage[$key]["vnicEtherIf_dn"].'" name="'.$this->storage[$key]['vnicEtherIf_name'].'" status="deleted"></vnicEtherIf>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "vnicEtherIf");
			    unset($this->themap->UCSMeventids[$myConstructor.'<->vnicEtherIf<->'.$this->storage[$key]["vnicEtherIf_dn"]]);
		            unset($this->storage[$key]);
		        }
		    }
	        }
		// Here we work the writing of CDN values on the Service Profile vnic interface if we manage them
	        if(strpos($key,$this->session->ip.'<1>') > -1 && strpos($key,'<3>BM_CDN') !== false) {
		    // format is UCS_IP<1>vnicEtherIf_dn<2>cdn_text<3>BM_CDN
		    //echo "*****************************6-30-15 in UCS writer, and found a BM_CDN event to write at: {$key}\n";
		    $etherDn=substr($key, strrpos($key,"<1>") + 3, strrpos($key,"<2>") - strrpos($key,"<1>") -3);	// the dn for this interface
		    $cdnText=substr($key, strrpos($key,"<2>") + 3, strrpos($key,"<3>") - strrpos($key,"<2>") -3);	// the CDN value to set, but need to add fabric
		    $return = $this->session->ucs_post($this->themap, 'configResolveDn','dn="'.$etherDn.'" inHierarchical="false"');
		    //echo "******sent configResolveDn for {$etherDn}, and return is:\n";
		    //var_dump($return);
		    $fabricUsed=$return['configResolveDn']['outConfig']['vnicEther']['@attributes']['switchId'];	// A or B
		    $xml='<vnicEther dn="'.$etherDn.'" adminCdnName="'.$cdnText.'-'.$fabricUsed.'" status="modified"></vnicEther>';
		    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		    $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		    //echo "******sent: [{$xml}], and return is:\n";
		    //var_dump($return);
		    unset($this->storage[$key]);
		}
		// Now we check to see if any pending events are out there to create any needed UCS fabric VLAN's from the EPG's mapped here
	        if(strpos($key,'<1>'.$this->session->ip.'<2>') > -1 && strpos($key,'<3>VMM_ROOT') !== false) {
		    $AsideUplink = "";
		    $BsideUplink = "";
		    if ($this->themap->ucsstack['MANAGE_DJL2']) {
		        if (strstr($key, "B2G-VXLAN-Transport") != NULL) {
			    $nativeAdd = 'isNative="yes"';
		        } else {
		            $nativeAdd = 'isNative="no"';
			}
			if (isset($this->themap->ucsstack['fabricAvpc'])) {
			    $atmpVPCid=$this->themap->ucsstack['fabricAvpc'];
			    $aVPCid=substr($atmpVPCid,3);  // starts after 'pc-'
			    $AsideUplink = '<fabricEthVlanPc portId="'.$aVPCid.'" '.$nativeAdd.' switchId="A"/>';	// The A fabric disjoint L2 uplink to utilize
			}
			if (isset($this->themap->ucsstack['fabricBvpc'])) {
			    $btmpVPCid=$this->themap->ucsstack['fabricBvpc'];
			    $bVPCid=substr($btmpVPCid,3);
			    $BsideUplink = '<fabricEthVlanPc portId="'.$bVPCid.'" '.$nativeAdd.' switchId="B"/>';	// The B fabric disjoint L2 uplink to utilize
			}
		    }
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if(($Adminstate == 'created') || ($Adminstate == 'modified')) {	// this was created on the synthetic object in memory, so write to the UCS domain
		        $default="";	// here we bring in the default settings from the flowmap definition
			if(isset($this->storage[$key]['fabricVlan_defaults'])) {
			    foreach($this->storage[$key]['fabricVlan_defaults'] as $key1 => $value1) {
			        $default .=  $key1.'="'.$value1.'" ';
			    }
			}
			if ((strstr($key, "B2G-VXLAN-Transport") != NULL) || (strstr($key, "B2G-VXLAN-Infrastructure") != NULL)) {
			    $default .= 'mcastPolicyName="for-VXLAN-mcast" ';				
		        }
		        $xml='<fabricVlan dn="'.$this->storage[$key]["fabricVlan_dn"].'" name="'.$this->storage[$key]['fabricVlan_name'].'" id="'.$this->storage[$key]["fabricVlan_id"];
			$xml.='" '.$default.' status="'.$Adminstate.'">'.$AsideUplink.$BsideUplink.'</fabricVlan>';
		        $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		        $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			$myConstructor=get_constructor($this->themap, "fabricVlan");
			$this->themap->UCSMeventids[$myConstructor.'<->fabricVlan<->'.$this->storage[$key]["fabricVlan_dn"]] = true;
			$myConstructor=get_constructor($this->themap, "fabricEthVlanPc");
			if (isset($aVPCid)) {
			    $this->themap->UCSMeventids[$myConstructor.'<->fabricEthVlanPc<->'.$this->storage[$key]["fabricVlan_dn"].'/pc-switch-A-pc-'.$aVPCid] = true;
			}
			if (isset($bVPCid)) {
			    $this->themap->UCSMeventids[$myConstructor.'<->fabricEthVlanPc<->'.$this->storage[$key]["fabricVlan_dn"].'/pc-switch-B-pc-'.$aVPCid] = true;
			}
			//echo "****************5-5-15 working, sending to UCS: [{$xml}], return vardump is:\n";
			//var_dump($return);
		        $tmp=$this->storage[$key];
		        $tmp["Adminstate"] = "";		// clear the adminstate when done
		        $this->storage[$key]=$tmp;
		    } elseif ($Adminstate == 'deleted') {
		        if($this->storage[$key]['CLASS'] === 'fabricVlan') {
		            $xml='<fabricVlan dn="'.$this->storage[$key]["fabricVlan_dn"].'" name="'.$this->storage[$key]['fabricVlan_name'].'" status="deleted"></fabricVlan>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "fabricVlan");
			    unset($this->themap->UCSMeventids[$myConstructor.'<->fabricVlan<->'.$this->storage[$key]["fabricVlan_dn"]]);
			    $myConstructor=get_constructor($this->themap, "fabricEthVlanPc");
			    if (isset($aVPCid)) {
			        unset($this->themap->UCSMeventids[$myConstructor.'<->fabricEthVlanPc<->'.$this->storage[$key]["fabricVlan_dn"].'/pc-switch-A-pc-'.$aVPCid]);
			    }
			    if (isset($bVPCid)) {
			        unset($this->themap->UCSMeventids[$myConstructor.'<->fabricEthVlanPc<->'.$this->storage[$key]["fabricVlan_dn"].'/pc-switch-B-pc-'.$aVPCid]);
			    }
		            unset($this->storage[$key]);
		        }
		    }
	        }
		// Now we check to see if any pending events are out there to create or modify vNIC adapter templates
	        if(strpos($key,'<1>'.$this->session->ip.'<2>') > -1 && strpos($key,'<3>VMM_CHILD') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'vnicLanConnTempl') {
			if(($Adminstate == 'created') || ($Adminstate == 'modified')) {	// this was created on the synthetic object in memory, so write to the UCS domain
		            $default="";	// here we bring in the default settings from the flowmap definition
			    if ($this->storage['userstory'] == 1 || $this->storage['userstory'] == 2) {
				$index=get_flowmap($this->themap, 'VMM', $this->storage[$key]['CLASS']);
				foreach($this->flowmap[$index]['DEST_DEFAULT'] as $key1 => $value1) {
				    $default .=  $key1.'="'.$value1.'" ';
				}
			    } elseif ($this->storage['userstory'] == 10) {
				$index=get_flowmap($this->themap, 'VMM-BM', $this->storage[$key]['CLASS']);				
				foreach($this->flowmap[$index]['DEST_VMM_DEFAULT'] as $key1 => $value1) {
				    $default .=  $key1.'="'.$value1.'" ';
				}
			    }
		            $xml = '<vnicLanConnTempl dn="'.$this->storage[$key]["vnicLanConnTempl_dn"].'" name="'.$this->storage[$key]['vnicLanConnTempl_name'];
			    $xml .= '" switchId="'.$this->storage[$key]['vnicLanConnTempl_switchId'].'" descr="'.$this->storage[$key]['vnicLanConnTempl_descr'];
			    $xml .= '" '.$default.' status="'.$Adminstate.'"></vnicLanConnTempl>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "vnicLanConnTempl");
			    $this->themap->UCSMeventids[$myConstructor.'<->vnicLanConnTempl<->'.$this->storage[$key]["vnicLanConnTempl_dn"]] = true;
			    //echo "5-5-15 working, sending to UCS: [{$xml}], return vardump is:\n";
			    //var_dump($return);
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";	// clear the adminstate when done
		            $this->storage[$key]=$tmp;
			    foreach($VMMtemplateCacheVLANinfoXml as $extraKey => $extraXML) {
				if (strstr($extraKey, $this->storage[$key]["vnicLanConnTempl_dn"]) != NULL) {
				    // now we send the VLAN membership that is cached
				    $this->themap->debugger->dwrite("XML OUT TO UCS: {$extraXML}\n");
				    $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$extraXML);
				    //echo "5-5-15 working, sending to UCS: [{$extraXML}], return vardump is:\n";
				    //var_dump($return);
				    unset($VMMtemplateCacheVLANinfoXml[$extraKey]);
				}
			    }

			    $xml='<vnicUsnicConPolicyRef dn="'.$this->storage[$key]["vnicLanConnTempl_dn"].'/usnic-con-ref" status="deleted"></vnicUsnicConPolicyRef>';
			    $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    //echo "4-1-15 WORKING:  usnic removal return vardump is:\n";
			    //var_dump($return);
			    
			    $xml='<vnicVmqConPolicyRef dn="'.$this->storage[$key]["vnicLanConnTempl_dn"].'/vmq-con-ref" conPolicyName="ACIleafVMQ"></vnicVmqConPolicyRef>';
			    //echo "*****UCS XML SEND FOR ACI VMQ POLILCY ADD TO TEMPLATE*****".date("Y-m-d H:i:s").": {$xml}\n";
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
			    $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    //echo "4-1-15 WORKING:  return vardump is:\n";
			    //var_dump($return);
			    echo date("Y-m-d H:i:s")." -> Assigning VMQ policy to vNIC template: Result=";
			    if (isset($return['configConfMos'])) {
			        echo "Success.\n";
			    } else {
			        echo "Unknown Reply.\n";
			    }
			} elseif ($Adminstate == 'deleted') {
		            $xml='<vnicLanConnTempl dn="'.$this->storage[$key]["vnicLanConnTempl_dn"].'" name="'.$this->storage[$key]['vnicLanConnTempl_name'].'" status="deleted"></vnicLanConnTempl>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "vnicLanConnTempl");
			    unset($this->themap->UCSMeventids[$myConstructor.'<->vnicLanConnTempl<->'.$this->storage[$key]["vnicLanConnTempl_dn"]]);
		            unset($this->storage[$key]);
		        }
		    }
	        }
		// Here we work the scanning and possible restoration of vnic interface
	        if(strpos($key,'<1>'.$this->session->ip.'<2>') > -1 && strpos($key,'<3>VMM_CHILD') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'vnicEtherIf') {
			if(($Adminstate == 'created') || ($Adminstate == 'modified')) {	// this was created on the synthetic object in memory, so write to the UCS domain
		            $default="";
			    if ($this->storage['userstory'] == 1 || $this->storage['userstory'] == 2) {
				$index=get_flowmap($this->themap, 'VMM', $this->storage[$key]['CLASS']);
				foreach($this->flowmap[$index]['DEST_DEFAULT'] as $key1 => $value1) {
				    $default .=  $key1.'="'.$value1.'" ';
				}
			    } elseif ($this->storage['userstory'] == 10) {
				$index=get_flowmap($this->themap, 'VMM-BM', $this->storage[$key]['CLASS']);
				foreach($this->flowmap[$index]['DEST_VMM_DEFAULT'] as $key1 => $value1) {
				    $default .=  $key1.'="'.$value1.'" ';
				}
			    }
			    if (strstr($this->storage[$key]['vnicEtherIf_dn'], "B2G-VXLAN-Transport") != NULL) {
				$default .= 'defaultNet="yes"';
			    } else {
				$default .= 'defaultNet="no"';
			    }
		            $xml='<vnicEtherIf dn="'.$this->storage[$key]["vnicEtherIf_dn"].'" name="'.$this->storage[$key]['vnicEtherIf_name'].'" rn="if-'.$this->storage[$key]['vnicEtherIf_name'];
			    $xml.='" '.$default.' status="'.$Adminstate.'"></vnicEtherIf>';
			    $VMMtemplateCacheVLANinfoXml[$this->storage[$key]["vnicEtherIf_dn"]]=$xml;	// we cache this, in case the vnic template was deleted then I need to send again after that is recreated
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "vnicEtherIf");
			    $this->themap->UCSMeventids[$myConstructor.'<->vnicEtherIf<->'.$this->storage[$key]["vnicEtherIf_dn"]] = true;
			    //echo "********************5-5-15 working, sending and caching for later use: [{$xml}]\n";
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";
		            $this->storage[$key]=$tmp;
			} elseif ($Adminstate == 'deleted') {
		            $xml='<vnicEtherIf dn="'.$this->storage[$key]["vnicEtherIf_dn"].'" name="'.$this->storage[$key]['vnicEtherIf_name'].'" status="deleted"></vnicEtherIf>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
			    $myConstructor=get_constructor($this->themap, "vnicEtherIf");
			    unset($this->themap->UCSMeventids[$myConstructor.'<->vnicEtherIf<->'.$this->storage[$key]["vnicEtherIf_dn"]]);
		            unset($this->storage[$key]);
		        }
		    }
	        }
		// Here we work the scanning and possible restoration of the NCP needs
	        if(strpos($key, $this->session->ip.'<1>') > -1 && strpos($key,'<3>VMM_LLDPNCP') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'nwctrlDefinition') {
		        if($Adminstate == 'created') {			
			    $attributes="";
			    $index=get_flowmap($this->themap, 'VMM', $this->storage[$key]['CLASS']);
		            foreach($this->flowmap[$index]['DEST_ATTRIBUTES'] as $key1 => $value1) {
			        $attributes .=  $key1.'="'.$value1.'" ';
			    }
			    $xml='<nwctrlDefinition '.$attributes.'></nwctrlDefinition>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
			    $myLogMsg="********6-9-15************ sending the xml to UCS: {$xml}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";
		            $this->storage[$key]=$tmp;
		        }
		    }
		}
		// Here we work the scanning and possible restoration of the NCP MAC forge update needs
	        if(strpos($key, $this->session->ip.'<1>') > -1 && strpos($key,'<3>VMM_LLDPMACSEC') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'dpsecMac') {
		        if($Adminstate == 'created') {			
			    $attributes="";
			    $index=get_flowmap($this->themap, 'VMM', $this->storage[$key]['CLASS']);
		            foreach($this->flowmap[$index]['DEST_ATTRIBUTES'] as $key1 => $value1) {
			        $attributes .=  $key1.'="'.$value1.'" ';
			    }
			    $xml='<dpsecMac '.$attributes.'></dpsecMac>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
			    $myLogMsg="********6-9-15************ sending the xml to UCS: {$xml}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";
		            $this->storage[$key]=$tmp;
		        }
		    }
		}
		// Here we work the scanning and possible restoration of the best effort class MTU settings
	        if (strpos($key, $this->session->ip.'<1>') > -1 && strpos($key,'<3>VMM_MTUBE') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'qosclassEthBE') {
		        if($Adminstate == 'created') {			
			    $attributes="";
			    $index=get_flowmap($this->themap, 'VMM', $this->storage[$key]['CLASS']);
		            foreach($this->flowmap[$index]['DEST_ATTRIBUTES'] as $key1 => $value1) {
			        $attributes .=  $key1.'="'.$value1.'" ';
			    }
			    $xml='<qosclassEthBE '.$attributes.'></qosclassEthBE>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
			    $myLogMsg="********6-9-15************ sending the xml to UCS: {$xml}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";
		            $this->storage[$key]=$tmp;
		        }
		    }
		}
		// Here we work the scanning and possible restoration of the adapter template QoS policy needs
	        if (strpos($key, $this->session->ip.'<1>') > -1 && strpos($key,'<3>VMM_QOSDEF') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'epqosDefinition') {
		        if($Adminstate == 'created') {			
			    $attributes="";
			    $index=get_flowmap($this->themap, 'VMM', $this->storage[$key]['CLASS']);
		            foreach($this->flowmap[$index]['DEST_ATTRIBUTES'] as $key1 => $value1) {
			        $attributes .=  $key1.'="'.$value1.'" ';
			    }
			    $xml='<epqosDefinition '.$attributes.'></epqosDefinition>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
			    $myLogMsg="********6-9-15************ sending the xml to UCS: {$xml}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";
		            $this->storage[$key]=$tmp;
		        }
		    }
		}
		// Here we work the scanning and possible restoration of the multicast policy needs
	        if (strpos($key, $this->session->ip.'<1>') > -1 && strpos($key,'<3>VMM_MCAST_POL') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'fabricMulticastPolicy') {
		        if($Adminstate == 'created') {			
			    $attributes="";
			    $index=get_flowmap($this->themap, 'VMM', $this->storage[$key]['CLASS']);
		            foreach($this->flowmap[$index]['DEST_ATTRIBUTES'] as $key1 => $value1) {
			        $attributes .=  $key1.'="'.$value1.'" ';
			    }
			    $xml='<fabricMulticastPolicy '.$attributes.'></fabricMulticastPolicy>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
			    $myLogMsg="********6-9-15************ sending the xml to UCS: {$xml}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";
		            $this->storage[$key]=$tmp;
		        }
		    }
		}
		// Here we work the scanning and possible restoration of the adapter template QoS policy priority and host control needs
	        if (strpos($key, $this->session->ip.'<1>') > -1 && strpos($key,'<3>VMM_EGRESSQOS') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'epqosEgress') {
		        if($Adminstate == 'created') {			
			    $attributes="";
			    $index=get_flowmap($this->themap, 'VMM', $this->storage[$key]['CLASS']);
		            foreach($this->flowmap[$index]['DEST_ATTRIBUTES'] as $key1 => $value1) {
			        $attributes .=  $key1.'="'.$value1.'" ';
			    }
			    $xml='<epqosEgress '.$attributes.'></epqosEgress>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
			    $myLogMsg="********6-9-15************ sending the xml to UCS: {$xml}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";
		            $this->storage[$key]=$tmp;
		        }
		    }
		}
		// Here we work the scanning and possible restoration of the adapter template VMQ policy needs
	        if (strpos($key, $this->session->ip.'<1>') > -1 && strpos($key,'<3>VMM_VMQCONPOL') !== false) {
		    $Adminstate=$this->storage[$key]['Adminstate'];
		    if($this->storage[$key]['CLASS'] === 'vnicVmqConPolicy') {
		        if($Adminstate == 'created') {			
			    $attributes="";
			    $index=get_flowmap($this->themap, 'VMM', $this->storage[$key]['CLASS']);
		            foreach($this->flowmap[$index]['DEST_ATTRIBUTES'] as $key1 => $value1) {
			        $attributes .=  $key1.'="'.$value1.'" ';
			    }
			    $xml='<vnicVmqConPolicy '.$attributes.'></vnicVmqConPolicy>';
			    $this->themap->debugger->dwrite("XML OUT TO UCS: {$xml}\n");
			    $myLogMsg="********6-9-15************ sending the xml to UCS: {$xml}\n";
			    if ($this->themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		            $return = $this->session->ucs_post($this->themap, 'configConfMos','inHierarchical="false"',$xml);
		            $tmp=$this->storage[$key];
		            $tmp["Adminstate"] = "";
		            $this->storage[$key]=$tmp;
		        }
		    }
		}
	    }
	    //******Update the receive timer if data in v1.0.1e
	    foreach($this->themap->ucscallqueue as $key=>$value) {
		if (strstr($value,'UCSM_RX_EVENT<->') != NULL) {
		    $last_socket_rx_time = microtime(true);
		    $UCSrxCount++;
		    if ($displayUCSrxCount) {
			echo date("Y-m-d H:i:s")." -> {$UCSrxCount} UCSM Event(s) received since last update\n";
			$UCSrxCount=0;
			$displayUCSrxCount=false;	// to reset until the next filter update event
		    }
		    unset($this->themap->ucscallqueue[$key]);
		}
	    }

        }
    }
}

//This class is responsible for receiving the events and send them to the common doer class 
class ucsm_eventsubscription extends Thread {
    var $request_url="";
    var $cookie="";
    var $session="";
    var $status=false;
    var $themap;
    var $storage;
    var $printevents;
    var $flowmap;
    var $attributemap;

    public function __construct(&$themap, Array $properties=array(array())) {
	date_default_timezone_set('UTC');
        echo date("Y-m-d H:i:s")." -> Initializing UCSM Event Subscription object\n";
        $this->themap = $themap;
        $this->session = $themap->ucssession;
        $this->request_url=$this->session->protocol."://".$this->session->ip."/nuova"; 
        $this->printevents=false;			// This flag will be set if we want to see the UCSM events we are subscribed to
        $this->flowmap=$themap->flowmap;
        $this->attributemap=$themap->attributemap;
        $themap->ucsstack['EVENT_ACTIVE'] = false;
	//echo "TEST**********************: in UCSM event subscription construct function.  Just set EVENT_ACTIVE to false.\n";	
        //Grab some metadata regading the session info
        //Get the UCS serial numbers
        $return = $this->session->ucs_post($themap, 'configResolveClass','classId="networkElement" inHierarchical="false"');
        $themap->ucsstack['serial_A'] = $return['configResolveClass']['outConfigs']['networkElement']['0']['@attributes']['serial'];
        $themap->ucsstack['serial_B'] = $return['configResolveClass']['outConfigs']['networkElement']['1']['@attributes']['serial'];
        //SystemName
        $return = $this->session->ucs_post($themap, 'configResolveDn','dn="sys/switch-A/mgmt/if-1" inHierarchical="false"');
        $themap->ucsstack['IP_A']=$return['configResolveDn']['outConfig']['mgmtIf']['@attributes']['extIp'];
        $return = $this->session->ucs_post($themap, 'configResolveDn','dn="sys/switch-B/mgmt/if-1" inHierarchical="false"');
        $themap->ucsstack['IP_B']=$return['configResolveDn']['outConfig']['mgmtIf']['@attributes']['extIp'];
        $return = $this->session->ucs_post($themap, 'configResolveClass','classId="firmwareInfra" inHierarchical="false"');	    
	//echo "For testing, the firmwareInfra returned data var_dump here is:\n";
	//var_dump($return);
        $themap->ucsstack['UCSMversion']=$return['configResolveClass']['outConfigs']['firmwareInfra']['@attributes']['operVersion'];
        $return = $this->session->ucs_post($themap, 'configResolveClass','classId="topSystem" inHierarchical="false"');	    
	//echo "For testing, the topSystem returned data var_dump here is:\n";
	//var_dump($return);
        $themap->ucsstack['name']=$return['configResolveClass']['outConfigs']['topSystem']['@attributes']['name'];
        $themap->ucsstack['VIP']=$return['configResolveClass']['outConfigs']['topSystem']['@attributes']['address'];
        $themap->ucsstack['physdomainname']=strtoupper('UCSM_DOMAIN_'.$themap->ucsstack['name']);
        $themap->ucsstack['physdomainnamedn']='uni/phys-'.strtoupper('UCSM_DOMAIN_'.$themap->ucsstack['name']);
	$minVLAN = $this->themap->storage['ucsmVLANpoolmin'];
        $themap->ucsstack['physdomainminvlan'] = $minVLAN;
	$maxVLAN = $this->themap->storage['ucsmVLANpoolmax'];
        $themap->ucsstack['physdomainmaxvlan'] = $maxVLAN;
        echo date("Y-m-d H:i:s")." -> Initialization of UCS domain event subscription thread done for domain: [{$themap->ucsstack['VIP']}] name: [{$themap->ucsstack['name']}], VLANS: {$minVLAN}-{$maxVLAN}.\n";
    }

    public function run() {
	date_default_timezone_set('UTC');
	// 7-6-15 Working on SSL for the event subscriptions
	//$interestingClass="none";
	$interestingClass="fabricMulticastPolicy";
	$useSSL=true;	// Set to false to use plain http (the early way this was implemented)
	
        echo date("Y-m-d H:i:s")." -> Starting UCSM Event Listener\n";
	$url = parse_url($this->themap->ucssession->url);
	$host = parse_url($this->themap->ucssession->url, PHP_URL_HOST);
	$path = parse_url($this->themap->ucssession->url, PHP_URL_PATH).$this->themap->ucsstack['cookie'];
	$query = parse_url($this->themap->ucssession->url, PHP_URL_QUERY);
        $path .= $query ? '?'. $query : '';
        $dataout='<eventSubscribe cookie="'.$this->themap->ucsstack['cookie'].'"><inFilter></inFilter></eventSubscribe>';
        $out="POST /nuova HTTP/1.1\r\n";
        $out.="Host: {$host}\r\n";
        $out.="Content-Length: ".strlen($dataout)."\r\n";
        $out.="Content-Type: application/x-www-form-urlencoded\r\n";
        $out.="\r\n";
        $out.=$dataout;

	// 7-6-15 Changing this from cleartext http to the https
	if (!$useSSL) {
	    $port = "80";
	    if(!($sock = socket_create(AF_INET, SOCK_STREAM, 0))) {
	        echo date("Y-m-d H:i:s")." -> ERROR: Could not create socket for UCSM event subscription.\n";
		return;
	    }
	    //echo "Socket created_E\n";    
	    //Connect socket to remote server
	    if(!socket_connect($sock , $host , $port)) {
	        echo date("Y-m-d H:i:s")." -> ERROR: Could not connect to UCSM instance on port {$port} for event subscription.\n";
		return;
	    }
	    echo date("Y-m-d H:i:s")." -> UCSM Event Subscription HTTP socket open and connected to: {$host}\n"; 
	    socket_set_nonblock($sock);
	    //Send the message to the server
	    if(!socket_send ( $sock , $out , strlen($out) , 0)) {
	        echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to UCSM to perform event subscription.\n";
		return;
	    }
	    echo date("Y-m-d H:i:s")." -> UCSM Event Subscription message sent successfully\n";  //{$out}\n";
	} else {
	    $port = "443";
	    $fp = fsockopen("ssl://".$host, $port, $errno, $errstr, 10);
	    if (!$fp) {
	        echo date("Y-m-d H:i:s")." -> ERROR:  Could not open socket for UCSM event subscriptions: $errstr ($errno)\n";
	        return;
	    } else {
	        echo date("Y-m-d H:i:s")." -> UCSM Event Subscription SSL socket open and connected to: {$host}\n";
	    }
	    stream_set_blocking($fp, 0);
	    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
	    //Send the message to the server
	    if(!fwrite($fp, $out)) {
	        echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to UCSM to perform event subscription.\n";
		return;		
	    }
	    echo date("Y-m-d H:i:s")." -> UCSM Event Subscription message sent successfully\n";  //{$out}\n";
	}
        $this->status=true;
        $message = '';
        $return = ''; 
        //loop through to continue appending until everything has been read
        $this->themap->ucsstack['EVENT_ACTIVE']=true;
	//echo "TEST***************************: in UCSM event subscription run function.  Just set EVENT_ACTIVE to true.\n";		
        load_junkfilter($this->themap);
        reload_flowmap_UCS($this->themap);
        $time_now = microtime(true);  
        $monitorspeed_subscription=0;
        while(true) {
	    usleep(5000);
	    $in = NULL;
	    if (!$useSSL) {
		$in = &socket_read($sock, 500000, PHP_BINARY_READ);
	    } else {
		$in = fgets($fp, 8192);
	    }
	    /*
	    echo "11-14-14 TEST****** the return from last UCS subscription socket_read is: [{$in}], which is: ";
	    if ($in) {
		echo "True.\n";
	    } else {
		echo "False.\n";
	    }
	    */
	    //11-24-14 testing
	    if(!$in) {
		if (!$useSSL) {
		    $myErrorNum = socket_last_error($sock);
		    $myErrorText = socket_strerror($myErrorNum);
		    if ($myErrorNum === 11) {
			continue;
		    } elseif ((($myErrorNum === 0) && (strlen($in) === 0)) || ($myErrorNum === 104)) {
			echo date("Y-m-d H:i:s")." -> WARNING: UCSM HTTP Event Subscription socket was closed by the UCSM side, setting it back up.\n";
			$port = "80";
			$dataout='<eventSubscribe cookie="'.$this->themap->ucsstack['cookie'].'"><inFilter></inFilter></eventSubscribe>';
			$out="POST /nuova HTTP/1.1\r\n";
			$out.="Host: {$host}\r\n";
			$out.="Content-Length: ".strlen($dataout)."\r\n";
			$out.="Content-Type: application/x-www-form-urlencoded\r\n";
			$out.="\r\n";
			$out.=$dataout;
			if(!($sock = socket_create(AF_INET, SOCK_STREAM, 0))) {
			    echo date("Y-m-d H:i:s")." -> ERROR: Could not re-create HTTP UCSM event subscription socket.\n";
			    return;
			}
			if(!socket_connect($sock , $host , $port)) {
			    echo date("Y-m-d H:i:s")." -> ERROR: Could not re-connect on port {$port} for UCSM event subscription.\n";
			    return;
			}
			socket_set_nonblock($sock);
			if(!socket_send ( $sock , $out , strlen($out) , 0)) {
			    echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to UCSM to re-start event subscription.\n";
			    return;
			}
			echo date("Y-m-d H:i:s")." -> Sucessfully reconnected via HTTP to UCSM for event subscriptions.\n";
			sleep(1);	// Just to allow things to settle and not overrun any logs
		    } else {
			echo date("Y-m-d H:i:s")." -> UCS Event Subscripton HTTP socket read return was false with val={$myErrorNum}: {$myErrorText}\n";
		    }
		    @socket_clear_error($sock);
		} else {
		    if(feof($fp) === true) {
			echo date("Y-m-d H:i:s")." -> WARNING: UCSM HTTPS Event Subscription socket was closed by the UCSM side, setting it back up.\n";
			$port = "443";
			$dataout='<eventSubscribe cookie="'.$this->themap->ucsstack['cookie'].'"><inFilter></inFilter></eventSubscribe>';
			$out="POST /nuova HTTP/1.1\r\n";
			$out.="Host: {$host}\r\n";
			$out.="Content-Length: ".strlen($dataout)."\r\n";
			$out.="Content-Type: application/x-www-form-urlencoded\r\n";
			$out.="\r\n";
			$out.=$dataout;
			$fp = fsockopen("ssl://".$host, $port, $errno, $errstr, 10);
			if (!$fp) {
			    echo date("Y-m-d H:i:s")." -> ERROR:  Could not re-connect HTTPS socket on port {$port} for UCSM event subscriptions: $errstr ($errno)\n";
			    return;
			}
			stream_set_blocking($fp, 0);
			stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			//Send the message to the server
			if(!fwrite($fp, $out)) {
			    echo date("Y-m-d H:i:s")." -> ERROR: Could not send data to UCSM to re-start event subscription.\n";
			    return;		
			}
			echo date("Y-m-d H:i:s")." -> Sucessfully reconnected via HTTPS to UCSM for event subscriptions.\n";
			sleep(1);	// Just to allow things to settle and not overrun any logs
		    } else {
			continue;
		    }
		}
		continue;
	    }
	    if(strlen($in) === 0) {
		//echo "11-14-14 TEST********** read a length of 0 bytes from socket.\n";
		//sleep(1);
		continue;	// this is just saying no more data to read, so continue;
	    }
	    // Add in the timing update for an RX on the subscription socket.... v1.0.1e
	    $this->themap->ucscallqueue[$this->themap->ucsstack['VIP']] = "UCSM_RX_EVENT<->".$this->themap->ucsstack['VIP'];
	    // end 11-24-14 testing
	    if($this->themap->ucsstack['UCS_MONITOR_SUBSCRIPTION'] === true) {
	        if(strlen($in) > 0) {
		    $this->themap->debugger->dwrite("\nUCS EVENT IN START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
		    $this->themap->debugger->dwrite($in);
		    $this->themap->debugger->dwrite("UCS EVENT IN END --<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<");			
	        }
	        //if($monitorspeed_subscription < 1) {
		//    $monitorspeed_subscription++;
	        //} else {
		    // When I want to see what the threads are doing, I indicate with a capital 'U' that we have to handle some event from UCSM
		    echo "[U]";
		    $monitorspeed_subscription=0;
	        //}
	    }
	    $return.=$in;
	    $dataIsJunk=true;
	    $startpos=strpos($return, "<configMoChangeEvent ");
	    $endpos=strrpos($return, "</configMoChangeEvent>") + 22;	// this is the very last configMoChangeEvent (there could be more than 1 in this reception buffer)
	    $lengthpos=$endpos- $startpos;
	    $data2process="";
	    $data2process = substr($return, $startpos, $lengthpos);		
	    if(strpos($data2process, "<configMoChangeEvent ") === false) {
	       $data2process="";
	    }
	    $return = substr($return, $endpos);		
	    if(strlen($data2process) > 0 && count($this->themap->junkfilter) > 0 ) {
	        foreach($this->themap->junkfilter as $needle=>$subVal) {
		    //echo "COMPARING:\n{$data2process}\n{$needle}\n";
		    if(strpos($data2process,$needle) !== false) {
			$dataIsJunk=false;	// the junkfilter is of stuff to allow in (blacklist by default) and we have at least some items there that are in the junkfilter
		        break;
		    }
	        }
	        if($dataIsJunk === true) {
		    $data2process="";
	        }
	    }
	    if($this->printevents == true) {		
		// echo $in;
	    }
	    while(strpos($data2process, "<configMoChangeEvent ") !== false && strpos($data2process, "</configMoChangeEvent>") !== false) {
	        $startpos=strpos($data2process, "<configMoChangeEvent ");
	        $endpos=strpos($data2process, "</configMoChangeEvent>") + 22;
	        $lengthpos=$endpos - $startpos;
	        $data = substr($data2process, $startpos, $lengthpos);
	        $data2process=substr($data2process, $endpos);
	        $dataIsJunk=true;
	        if(strlen($data) > 0 && count($this->themap->junkfilter) > 0) {		
		    foreach($this->themap->junkfilter as $needle=>$subVal) {
		        if(strpos($data,$needle) !== false) {
			    $dataIsJunk=false;	// the junkfilter is of stuff to allow in (blacklist by default) and this event instance is good
			    break;
		        }
		    }
		    if($dataIsJunk === true) {		    
		        $data="";
		    }
	        }
	        if(strlen($data) > 0 && strpos($data, "configMoChangeEvent") !== false ) {
		    $data = xml_decode($data);		
	        } 
	        //var_dump($data);
	        unset($dn);
	        unset($status);
	        //echo "EVENT RECIEVED BEGIN===>\n";
		if (isset($data["configMoChangeEvent"]['@attributes']['inEid']) == false) continue;
		// 8-20-15 Event data work
		ob_start();
		var_dump($data);
		$myESdata=ob_get_clean();
		$myLogMsg="UCSM EVENT DATA VAR_DUMP:\n{$myESdata}\n";
		if ($this->themap->storage['logUCSMevents']) file_put_contents("UCSMeventdata.txt", $myLogMsg, FILE_APPEND);
		// 8-20-15 end
	        $inEid=$data['configMoChangeEvent']['@attributes']['inEid'];
	        foreach($data['configMoChangeEvent']['inConfig'] as $class=>$tmp) {
		    if($class !== "eventRecord" && $class !== "eventLog") {			    
		        if(isset($data['configMoChangeEvent']['inConfig'][$class]['@attributes']["dn"])) {
			    $dn=$data['configMoChangeEvent']['inConfig'][$class]['@attributes']["dn"];
			    if($class === $interestingClass) {
				$myLogMsg="Item DN==>".$dn."\n";				
				if ($this->themap->storage['logUCSMevents']) file_put_contents("UCSMeventdata.txt", $myLogMsg, FILE_APPEND);
			    }
		        }   
		        if(isset($data['configMoChangeEvent']['inConfig'][$class]['@attributes']['status'])) {
			    $status=$data['configMoChangeEvent']['inConfig'][$class]['@attributes']['status'];
			    if($class === $interestingClass) {
				$myLogMsg="Item STATUS==>".$status."\n";
				if ($this->themap->storage['logUCSMevents']) file_put_contents("UCSMeventdata.txt", $myLogMsg, FILE_APPEND);
			    }
		        }
		        $eventElementHandled=false;
		        if($eventElementHandled == false && isset($dn) && isset($status) && $status === "deleted") {
			    if($class === $interestingClass) {
			        $myLogMsg="Interesting UCSM Class Deletion Event Received!!  Calling remove_dn: {$class}, {$dn}, {$this->session->ip}\n";
				if ($this->themap->storage['logUCSMevents']) file_put_contents("UCSMeventdata.txt", $myLogMsg, FILE_APPEND);
			    }
			    remove_dn($this->themap, $class, $dn, $this->session->ip);
			    $eventElementHandled=true;
		        }
		        if($eventElementHandled == false && isset($dn) && isset($status) && $status !== "deleted") {
			    unset($mo);
			    foreach($data['configMoChangeEvent']['inConfig'][$class]['@attributes'] as $key => $value) {
			        if($key === "status") {
				    $value="";
			        }
			        if($key !== "rn") {
				    if($class === $interestingClass) {
					$myLogMsg="Interesting UCSM Class Non-Deletion Event Received!!  Calling doer: {$class}, {$dn}, {$key}, {$value}, {$this->session->ip}, RAW\n";
					if ($this->themap->storage['logUCSMevents']) file_put_contents("UCSMeventdata.txt", $myLogMsg, FILE_APPEND);
				    }
				    doer($this->themap, $class, $dn, $key, $value, $this->session->ip, "RAW");
			        }
			    }
			    $eventElementHandled=true;
		        }
		    }
	        }
	        //echo "EVENT RECIEVED END<===\n";
	    }
        }
        echo "UCS EVENT SUBSCIPTION ENDING\n";
	if(!$useSSL) {
	    socket_close($sock);
	} else {
	    fclose($fp);
	}
    }
}

class ucs_manager {
    var $url="";	
    var $protocol="";
    var $ip="";
    var $username="";
    var $password="";
    var $cookie="";
    var $outRefreshPeriod="";
    var $outPriv="";
    var $outDomains="";
    var $outChannel="";
    var $outEvtChannel="";
    var $outName="";
    var $errorCode="";
    var $invocationResult="";
    var $errorDescr="";
    var $sessioninfo="";
    var $sessioninfo_urlencoded="";
    var $_classarray;

    public function __construct($protocol, $ip, $username, $password) {
	//echo "Initializing Session object...\n";
        $this->protocol = $protocol;
        $this->ip = $ip;
        $this->username = $username;
        $this->password = $password;   
        $this->url = $this->protocol."://".$this->ip."/nuova"; 
        $this->_classarray = Array(Array(Array()));
    }
    
    private function ucs_http_post_flds($data, &$sendItem, &$replyItem) {
	$sendItem=$data;
        $opts = array('http' => array('method' => 'POST', 'content' => $data));
        $st = stream_context_create($opts);
        $fp = @fopen($this->url, 'rb', false, $st);
        $result="";
        if(!$fp) {
	    $result=false;
        } else {
	    $result = stream_get_contents($fp);
	    //var_dump($result);
	    fclose($fp);
	}
	$replyItem=$result;
        return $result;
    }

    private function ucs_https_post_flds($data, &$sendItem, &$replyItem) {
	date_default_timezone_set('UTC');
	$opts = array(CURLOPT_URL=>$this->url, CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$data, CURLOPT_SSL_VERIFYPEER=>false,
		      CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_TIMEOUT => 10);
	$sendItem = $data;
	$ch=curl_init();
	curl_setopt_array($ch, $opts);
        $result="";
	$result = curl_exec($ch);
	if (!$result) {
	    echo date("Y-m-d H:i:s")." -> ERROR:  HTTPS write failed and we had a false return: [".curl_error($ch)."]\n";
	    $result=false;
	} else {
	    curl_close($ch);
	}
	$replyItem=$result;
        return $result;	
    }
    
    function ucs_post(&$themap, $method, $header,$xml=null) {
        //var_dump($method);
        //var_dump($header);
        //var_dump($xml);
        if($xml !== null) {
	    $word_start=strpos($xml , 'dn="')+4;
	    $tmp=substr($xml,$word_start);
	    $word_end = strpos ($tmp , '"');
	    $dn = substr($tmp, 0, $word_end);
	    $out='<'.$method.' cookie="'.$themap->ucsstack['cookie'].'" '.$header.'>';
	    $out.='<inConfigs>';
	    $out.='<pair key="'.$dn.'">';		
	    $out.=$xml;
	    $out.='</pair>';	
	    $out.='</inConfigs>';
	    $out.='</'.$method.'>';	
        } else {
	    $out='<'.$method.' cookie="'.$themap->ucsstack['cookie'].'" '.$header.'/>';	
        }
        $result = xml_decode($this->ucs_https_post_flds($out, $send, $reply));
        if($themap->ucsstack['UCS_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nUCS POST UPDATE START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("UCS POST UPDATE END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nUCS POST UPDATE REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("UCS POST UPDATE REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        return $result;	
    }
      
    function ucs_aaaLogin(&$themap) {   
	date_default_timezone_set('UTC');
        $themap->ucsstack['username'] = $this->username;
        $themap->ucsstack['password'] = $this->password;
        if(file_exists("cookie")) {
	    $themap->ucsstack['cookie']=file_get_contents("cookie");
	    echo date("Y-m-d H:i:s")." -> Found cookie on filesystem, will try to use it... {$themap->ucsstack['cookie']}\n";		
	    if($this->ucs_aaarefresh($themap) == true) {
	        echo date("Y-m-d H:i:s")." -> Worked, I now have a new cookie: {$themap->ucsstack['cookie']}\n";
	        return true;
	    } else {
	        echo date("Y-m-d H:i:s")." -> Nope, cookie to old, doing regular login...\n";
	    }
        }
        $this->sessioninfo = $this->ucs_https_post_flds('<aaaLogin inName="'.$themap->ucsstack['username'].'" inPassword="'.$themap->ucsstack['password'].'" cookie="" />', $send, $reply);
        if($themap->ucsstack['UCS_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nUCS LOGIN UPDATE START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("UCS LOGIN UPDATE END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nUCS LOGIN UPDATE REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("UCS LOGIN UPDATE REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        $data = xml_decode($this->sessioninfo);
        if(isset($data["aaaLogin"]["@attributes"]["outCookie"])) {
	    $themap->ucsstack['cookie'] = $data["aaaLogin"]["@attributes"]["outCookie"];
	    $themap->ucsstack['outRefreshPeriod'] = $data["aaaLogin"]["@attributes"]["outRefreshPeriod"];
	    $themap->ucsstack['outPriv'] = $data["aaaLogin"]["@attributes"]["outPriv"];
	    $themap->ucsstack['outDomains'] = $data["aaaLogin"]["@attributes"]["outDomains"];
	    $themap->ucsstack['outChannel'] = $data["aaaLogin"]["@attributes"]["outChannel"];
	    $themap->ucsstack['outEvtChannel'] = $data["aaaLogin"]["@attributes"]["outEvtChannel"];
	    $themap->ucsstack['outName'] = $data["aaaLogin"]["@attributes"]["outName"];
	    file_put_contents("cookie", $themap->ucsstack['cookie']);
	    return true;
	}
        if(isset($data["aaaLogin"]["@attributes"]["errorCode"])) {
	    $this->errorCode = $data["aaaLogin"]["@attributes"]["errorCode"];
	    $this->invocationResult = $data["aaaLogin"]["@attributes"]["invocationResult"];
	    $this->errorDescr = $data["aaaLogin"]["@attributes"]["errorDescr"];
        } 
	return false;    
    }
    
    function ucs_aaaLogout(&$themap) {
        $result = $this->ucs_https_post_flds('<aaaLogout inCookie="'.$themap->ucsstack['cookie'].'" />', $send, $reply);
        if($themap->ucsstack['UCS_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nUCS LOGOUT UPDATE START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("UCS LOGOUT UPDATE END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nUCS LOGOUT UPDATE REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("UCS LOGOUT UPDATE REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        return $result;
    }
    
    function ucs_aaarefresh(&$themap) {
        $xml='<aaaRefresh inName="'.$themap->ucsstack['username'].'" inPassword="'.$themap->ucsstack['password'].'" inCookie="'.$themap->ucsstack['cookie'].'" />';
        $this->sessioninfo = $this->ucs_https_post_flds($xml, $send, $reply);
        if($themap->ucsstack['UCS_MONITOR_UPDATE'] === true) {
	    $themap->debugger->dwrite("\nUCS REFRESH UPDATE START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($send);
	    $themap->debugger->dwrite("UCS REFRESH UPDATE END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	    $themap->debugger->dwrite("\nUCS REFRESH UPDATE REPLY START -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");			
	    $themap->debugger->dwrite($reply);
	    $themap->debugger->dwrite("UCS REFRESH UPDATE REPLY END -->>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
	}
        $data = xml_decode($this->sessioninfo,true);
	//echo "11-24-14 TEST!!!  tried refresh and we have send as: [{$send}], and reply as: [{$reply}]\n";
        if(isset($data["aaaRefresh"]["@attributes"]["outCookie"])) {
	    $themap->ucsstack['cookie'] = $data["aaaRefresh"]["@attributes"]["outCookie"];
	    $themap->ucsstack['outRefreshPeriod'] = $data["aaaRefresh"]["@attributes"]["outRefreshPeriod"];
	    $themap->ucsstack['outPriv'] = $data["aaaRefresh"]["@attributes"]["outPriv"];
	    $themap->ucsstack['outDomains'] = $data["aaaRefresh"]["@attributes"]["outDomains"];
	    $themap->ucsstack['outChannel'] = $data["aaaRefresh"]["@attributes"]["outChannel"];
	    $themap->ucsstack['outEvtChannel'] = $data["aaaRefresh"]["@attributes"]["outEvtChannel"];
	    $themap->ucsstack['outName'] = $data["aaaRefresh"]["@attributes"]["outName"];
	    file_put_contents("cookie", $themap->ucsstack['cookie']);
	    return true;
        } else {
	    return false;
        }
    }
}

?>
