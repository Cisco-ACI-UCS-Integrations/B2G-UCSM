<?PHP

$rackserverlist = array();
$UCSdomainlist = array();

date_default_timezone_set('UTC');
define("CURL_SSLVERSION_TLSv1", 1);
define("CURL_SSLVERSION_TLSv1_0", 4);
define("CURL_SSLVERSION_TLSv1_1", 5);
define("CURL_SSLVERSION_TLSv1_2", 6);

$apicEventSubTestSeconds=20;
$ucsmEventSubTestSeconds=20;

// defaults
$userstory=0;
$realEnvironment=true;   // this is the default of not using ACI or UCS simulators.  The init file will set this for simulators, etc.
$apicip="empty";
$apicuser="empty";
$apicpwd="empty";

function check_init_file (&$userstory, &$realEnvironment, &$apicip, &$apicuser, &$apicpwd, &$UCSdomainlist, &$rackserverlist) {
    $nlChar=sprintf("\n");
    $commentItem=sprintf("// ");
    $usecaseText[0]="VPC Auto-Form with UCS FI's";
    $usecaseText[1]="VMM Domain with hosts inside a UCS domain - VLANs";
    $usecaseText[2]="VMM Domain with hosts inside a UCS domain - VXLANs";
    $usecaseText[3]="Extend APIC EPG's into a UCS Domain and Auto-Create backing VLANs for bare metal servers";
    $usecaseText[4]="Coordinate the UCS SPAN destiniation ports, to ACI SPAN source ports";
    $usecaseText[5]="Group UCS C series into a domain (by description field), and auto-create logical adapters for each EPG on server";    
    $usecaseText[10]="Perform all UCSM usecases simultaneously";

    if(file_exists("b2g.init")) {        // first scan for the userstory
        $thefiledata=file_get_contents("b2g.init");
        //var_dump($thefiledata);
        $filelines=explode($nlChar, $thefiledata);
        foreach($filelines as $key=>$value) {
            //echo "init file line #".$key." = ".$value."\n";
            if (strlen($value) === 0) continue; // empty line
            if ((strstr($value, $commentItem) != NULL) && (strpos($value, $commentItem) === 0)) {
                // comment line all the way
                $inputItem=false;
                //echo "init file line #".$key." is a comment line: ".$value."\n";
            } elseif (strstr($value, $commentItem) != NULL) {
                // comment after some valid configuration item
                $inputItem=true;
            } else {
                $inputItem=true;
            }
            if ($inputItem) {
                //echo "OK - valid line #".$key." data to read the start, value is: ".$value."\n";
                $inputElement=explode(" ", $value);
                switch ($inputElement[0]) {
                    case "userstory:":
                        $userstory = $inputElement[1];
                        echo date("Y-m-d H:i:s")." -> userstory is: ".$inputElement[1]." - ".$usecaseText[$userstory]."\n";
                        break;
                    case "environmentType:":
                        echo date("Y-m-d H:i:s")." -> environmentType is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "simulated") {
                            $realEnvironment=false;
                        } else {    // this is read as real then
                            $realEnvironment=true;
                        }
                        break;
                    case "apicip:":
                        echo date("Y-m-d H:i:s")." -> apicip is: ".$inputElement[1]."\n";
                        $apicip = $inputElement[1];
                        break;
                    case "apicuser:":
                        echo date("Y-m-d H:i:s")." -> apicuser is: ".$inputElement[1]."\n";
                        $apicuser = $inputElement[1];
                        break;
                    case "apicpwd:":
                        echo date("Y-m-d H:i:s")." -> apicpwd is gathered.\n";
                        $apicpwd = $inputElement[1];
                        break;
                    case "ucsmip:":
                        echo date("Y-m-d H:i:s")." -> ucsip is: ".$inputElement[1]."\n";
                        $tempUCSip = $inputElement[1];
                        break;
                    case "ucsmuser:":
                        echo date("Y-m-d H:i:s")." -> ucsuser is: ".$inputElement[1]."\n";
                        $tempUCSuser = $inputElement[1];
                        break;
                    case "ucsmpwd:":
                        echo date("Y-m-d H:i:s")." -> ucspwd is gathered.\n";
                        $tempUCSpwd = $inputElement[1];
                        echo date("Y-m-d H:i:s")." -> a whole UCS domain is in:  IP=".$tempUCSip.", User=".$tempUCSuser.", adding to array.\n";
                        $UCSdomainlist[] = $tempUCSip.'<->'.$tempUCSuser.'<->'.$tempUCSpwd;
                        break;
                    case "cserverip:":
                        echo date("Y-m-d H:i:s")." -> cserverip is: ".$inputElement[1]."\n";
                        $tempCip=$inputElement[1];
                        break;
                    case "cserveruser:":
                        echo date("Y-m-d H:i:s")." -> cserveruser is: ".$inputElement[1]."\n";
                        $tempCuser=$inputElement[1];
                        break;
                    case "cserverpwd:":
                        echo date("Y-m-d H:i:s")." -> cserverpwd is gathered\n";
                        $tempCpwd=$inputElement[1];
                        echo date("Y-m-d H:i:s")." -> a whole c server is in:  IP=".$tempCip.", User=".$tempCuser.", adding to array.\n";
                        $rackserverlist[] = $tempCip.'<->'.$tempCuser.'<->'.$tempCpwd;
                        break;
                    default:
                        continue;
                }
            }
        }
    }
}

// This sets the userstories and the systems/credentials from the B2G.init file
check_init_file($userstory, $realEnvironment, $apicip, $apicuser, $apicpwd, $UCSdomainlist, $rackserverlist);

switch($userstory) {
    case 5:
	check_cimc_connections($rackserverlist);
	check_apic_connections($apicip, $apicuser, $apicpwd, $apicEventSubTestSeconds);
	break;
    default:
	check_ucsm_connections($UCSdomainlist, $ucsmEventSubTestSeconds);
	check_apic_connections($apicip, $apicuser, $apicpwd, $apicEventSubTestSeconds);
	break;
}
echo date("Y-m-d H:i:s")." -> Testing Complete.\n";
exit;

function check_ucsm_connections($domainlist, $subscriptionTestSeconds) {
    foreach($domainlist as $key=>$instanceLine) {
        $myCookie='';
	$ucsmItems=explode('<->', $instanceLine);
	$host = $ucsmItems[0];
	$data = '<aaaLogin inName="'.$ucsmItems[1].'" inPassword="'.$ucsmItems[2].'"/>';
	/*echo "\n".date("Y-m-d H:i:s")." -> ****TESTING UCSM LOGIN VIA HTTP TO: ({$host})****\n";
	$opts = array('http' => array('method' => 'POST', 'content' => $data));
	$st = stream_context_create($opts);
	$url = 'http://'.$host.'/nuova';
	$fp = @fopen($url, 'rb', false, $st);
	$result="";
	if(!$fp) {
	    echo date("Y-m-d H:i:s")." -> UCSM HTTP ({$host}) LOGIN FAILED\n";
	} else {
	    $result = stream_get_contents($fp);
	    echo date("Y-m-d H:i:s")." -> UCSM HTTP ({$host}) LOGIN REPLY SUCCESS START -->>>\n";
	    var_dump($result);
            $myCookiePtr=strpos($result, "outCookie=") + strlen("outCookie=");    // this is "cookie"........
            //echo "TEST - the cookie pointer is location: {$myCookiePtr}\n";
            $myCookie = substr($result, $myCookiePtr+1, 47);        // 47 byte cookie
	    echo date("Y-m-d H:i:s")." -> UCSM HTTP ({$host}) LOGIN REPLY SUCCESS END -->>>\n";
            fclose($fp);
            $dataLogout = '<aaaLogout inCookie="'.$myCookie.'" />';
            $opts = array('http' => array('method' => 'POST', 'content' => $dataLogout));
            $st = stream_context_create($opts);
            $url = 'http://'.$host.'/nuova';
            $fp = @fopen($url, 'rb', false, $st);
            $result="";
            if(!$fp) {
                echo date("Y-m-d H:i:s")." -> UCSM HTTP ({$host}) LOGOUT FAILED\n";
            } else {
                $result = stream_get_contents($fp);
                echo date("Y-m-d H:i:s")." -> UCSM HTTP ({$host}) LOGOUT REPLY SUCCESS START -->>>\n";
                var_dump($result);
                echo date("Y-m-d H:i:s")." -> UCSM HTTP ({$host}) LOGOUT REPLY SUCCESS END -->>>\n\n";
                fclose($fp);
            }
        }*/
        
	echo "\n".date("Y-m-d H:i:s")." -> ****TESTING UCSM LOGIN VIA HTTPS TO: ({$host})****\n";
	$urlSSL = "https://".$host."/nuova";
	$ch = curl_init();
	$options2 = array(CURLOPT_URL => $urlSSL, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data,
                          CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_SSL_VERIFYPEER => false);
	curl_setopt_array($ch, $options2);
	$response = curl_exec($ch);
	if (!$response) {
	    echo date("Y-m-d H:i:s")." -> UCSM HTTPS ({$host}) LOGIN FAILED, ERROR:".curl_error($ch)."\n";
	} else {
	    echo date("Y-m-d H:i:s")." -> UCSM HTTPS ({$host}) LOGIN REPLY SUCCESS START -->>>\n";
	    var_dump($response);
            $myCookiePtr=strpos($response, "outCookie=") + strlen("outCookie=");    // this is "cookie"........
            //echo "TEST - the cookie pointer is location: {$myCookiePtr}\n";
            $myCookie = substr($response, $myCookiePtr+1, 47);        // 47 byte cookie
	    echo date("Y-m-d H:i:s")." -> UCSM HTTPS ({$host}) LOGIN REPLY SUCCESS END -->>>\n";
            curl_close($ch);
            // leave logged in, to subscribe to events
        
            echo date("Y-m-d H:i:s")." -> ****TESTING UCSM SSL EVENT SUBSCRIPTION FUNCTIONALITY FOR {$subscriptionTestSeconds} SECONDS ON: ({$host})****\n";
            $protocol = "https://";
            $port = "443";
            $dataout='<eventSubscribe cookie="'.$myCookie.'"><inFilter></inFilter></eventSubscribe>';
            $out="POST /nuova HTTP/1.1\r\n";
            $out.="Host: ".$host."\r\n";
            $out.="Content-Length: ".strlen($dataout)."\r\n";
            $out.="Content-Type: application/x-www-form-urlencoded\r\n";
            $out.="\r\n";
            $out.=$dataout;
            $fp = fsockopen("ssl://".$host, $port, $errno, $errstr, 30);
            if (!$fp) {
                echo date("Y-m-d H:i:s")." -> UCSM Event Subcription socket open failed: ".$errstr."-".$errno."\n";
            } else {
                //echo "fsockopen was ok, and structure of fp is:\n";
                //var_dump($fp);
                stream_set_blocking($fp, 0);
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $secondsSinceStartup=0;
                //echo "sending on socket: {$out}";
                fwrite($fp, $out);
                echo date("Y-m-d H:i:s")." -> UCSM EVENT RECEIVE STARTING -->>>\n";
                while (true) {
                    $secondsSinceStartup++;
                    if (feof($fp) === true) {
                        echo date("Y-m-d H:i:s")." -> Socket closed from UCSM side.\n";
                        break;
                    }
                    $dataIn = fgets($fp, 1024);
                    if (!$dataIn) {
                        echo "[".$secondsSinceStartup."]";
                        sleep(1);
                    } else {
                        echo $dataIn;
                    }
                    // Now for me to breakout after test seconds for this testing routine
                    if ($secondsSinceStartup > $subscriptionTestSeconds) break;
                }
                echo date("Y-m-d H:i:s")." -> UCSM EVENT RECEIVE ENDING -->>>\n";
                //echo "closing socket.\n\n";
                fclose($fp);
        
                // Now we logout
                $dataLogout = '<aaaLogout inCookie="'.$myCookie.'" />';
                $ch = curl_init();
                $options2 = array(CURLOPT_URL => $urlSSL, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $dataLogout,
                                  CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_SSL_VERIFYPEER => false);
                curl_setopt_array($ch, $options2);
                $response = curl_exec($ch);
                if (!$response) {
                    echo date("Y-m-d H:i:s")." -> UCSM HTTPS ({$host}) LOGOUT FAILED, ERROR:".curl_error($ch)."\n";
                } else {
                    echo date("Y-m-d H:i:s")." -> UCSM HTTPS ({$host}) LOGOUT REPLY SUCCESS START -->>>\n";
                    var_dump($response);
                    echo date("Y-m-d H:i:s")." -> UCSM HTTPS ({$host}) LOGOUT REPLY SUCCESS END -->>>\n\n";
                    curl_close($ch);        
                }
            }
        }
    }
}

function check_cimc_connections($serverlist) {
    foreach($serverlist as $key=>$instanceLine) {
	$cimcItems=explode('<->', $instanceLine);
	$host = $cimcItems[0];
	echo date("Y-m-d H:i:s")." -> ****TESTING CIMC LOGIN HTTP TO: ({$host})****\n";
	$data = '<aaaLogin inName="'.$cimcItems[1].'" inPassword="'.$cimcItems[2].'"/>';
	$opts = array('http' => array('method' => 'POST', 'content' => $data));
	$st = stream_context_create($opts);
	$url = "http://".$host."/nuova";
	$fp = @fopen($url, 'rb', false, $st);
	$result="";
	if(!$fp) {
	    $result=false;
	    echo date("Y-m-d H:i:s")." -> CIMC HTTP ({$host}) LOGIN FAILED\n";
	} else {
	    $result = stream_get_contents($fp);
	    echo date("Y-m-d H:i:s")." -> CIMC HTTP ({$host}) LOGIN REPLY SUCCESS START -->>>\n";
	    var_dump($result);
	    echo date("Y-m-d H:i:s")." -> CIMC HTTP ({$host}) LOGIN REPLY SUCCESS END -->>>\n\n";
            fclose($fp);
	}

	echo date("Y-m-d H:i:s")." -> ****TESTING CIMC LOGIN HTTPS TO: ({$host})****\n";
	$urlSSL = "https://".$host."/nuova";
	$ch = curl_init();
	$options2 = array(CURLOPT_URL => $urlSSL, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data,
                          CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_SSL_VERIFYPEER => false);
	curl_setopt_array($ch, $options2);
	$response = curl_exec($ch);
	if (!$response) {
	    echo date("Y-m-d H:i:s")." -> UCSM HTTPS ({$host}) LOGIN FAILED, ERROR: ".curl_error($ch)."\n";
	} else {
	    echo date("Y-m-d H:i:s")." -> CIMC HTTPS ({$host}) LOGIN REPLY START -->>>\n";
	    var_dump($response);
	    echo date("Y-m-d H:i:s")." -> CIMC HTTPS ({$host}) LOGIN REPLY END -->>>\n\n";
            curl_close($ch);
 	}
    }
}

function check_apic_connections($apicIP, $userid, $password, $subscriptionTestSeconds) {
    $myCookieHeader='';
    $myCookie='';
    echo date("Y-m-d H:i:s")." -> ****TESTING APIC HTTPS PUT FUNCTIONALITY ON: ({$apicIP})****\n";
    $data = '{"aaaUser" : {"attributes" : {"name" : "'.$userid.'", "pwd" : "'.$password.'"}}}';
    $urlSSL = "https://".$apicIP."/api/aaaLogin.json";
    $ch = curl_init();
    $options2 = array(CURLOPT_URL => $urlSSL, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data,
                      CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_SSL_VERIFYPEER => false);
    curl_setopt_array($ch, $options2);
    $response = curl_exec($ch);
    if (!$response) {
	echo date("Y-m-d H:i:s")." -> APIC HTTPS ({$apicIP}) LOGIN FAILED, ERROR: ".curl_error($ch)."\n";
    } else {
	echo date("Y-m-d H:i:s")." -> APIC LOGIN REPLY SUCCESS START -->>>\n";
	var_dump($response);
	$response2 = array();
	$response2=json_decode($response, true);
	//echo "decoded vardump:\n";
	//var_dump($response2);
	if(isset($response2["imdata"][0]["aaaLogin"]["attributes"]["token"])) {
	    $myCookie = $response2["imdata"][0]["aaaLogin"]["attributes"]["token"];
	    $myCookieHeader = 'Cookie: APIC-cookie='.$myCookie;
	    echo date("Y-m-d H:i:s")." -> APIC cookieHeader is: [{$myCookieHeader}]\n";
	}
	echo date("Y-m-d H:i:s")." -> APIC LOGIN REPLY SUCCESS END -->>>\n\n";
    }
    curl_close($ch);

    echo date("Y-m-d H:i:s")." -> ****TESTING APIC HTTPS GET FUNCTIONALITY ON: ({$apicIP})****\n";
    $header = array(
	"Host: {$apicIP}",
	"Accept: text/html,application/xhtml+xml,application/xml,application/json",
	"Origin: https:/{$apicIP}",
	"X-Requested-With: XMLHttpRequest",
	"User-Agent: Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.153 Safari/537.36",
	"Content-Type: application/json",
	"Refer: https://{$apicIP}",
	"Accept-Encoding: gzip,deflate,sdch",
	"Accept-Language: en-US,en;q=0.8",
	$myCookieHeader
    );
    $urlSSL = "https://".$apicIP."/api/node/mo/topology/pod-1/node-1/sys.json?query-target=subtree&target-subtree-class=l3EncRtdIf";
    $ch = curl_init();
    $options2 = array(CURLOPT_URL => $urlSSL, CURLOPT_HTTPHEADER => $header, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPGET => true,
                      CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 1);
    curl_setopt_array($ch, $options2);
    $response = curl_exec($ch);
    if (!$response) {
        echo date("Y-m-d H:i:s")." -> APIC INTERFACE GET REPLY FAILED ERROR: ".curl_error($ch)."\n";
    } else {
	echo date("Y-m-d H:i:s")." -> APIC INTERFACE GET REPLY START -->>>\n";
	var_dump($response);
	echo date("Y-m-d H:i:s")." -> APIC INTERFACE GET REPLY END -->>>\n\n";
    }
    curl_close($ch);

    echo "****TESTING APIC SSL EVENT SUBSCRIPTION FUNCTIONALITY FOR {$subscriptionTestSeconds} SECONDS ON: ({$apicIP})****\n";
    $key = "6666777799998888";
    $protocol = "https://";
    $port = "443";
    $context = "node/mo/uni/infra";
    $class = "fvnsVlanInstP";
    $path = "{$context}.json?query-target=subtree&target-subtree-class={$class}&subscription=yes";
    $sockUpgrade = "/socket{$myCookie}";

    $out  = "GET {$sockUpgrade} HTTP/1.1\r\n";
    $out .= "Upgrade: websocket\r\n";
    $out .= "Connection: upgrade\r\n";
    $out .= "Host: {$apicIP}\r\n";
    $out .= "Origin: {$protocol}{$apicIP}\r\n";
    $out .= "Pragma: no-cache\r\n";
    $out .= "Cache-Control: no-cache\r\n";
    $out .= "Connection: keep-alive\r\n";
    $out .= "Sec-WebSocket-Key: ".$key."\r\n";
    $out .= "Sec-WebSocket-Version: 13\r\n";
    $out .= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits, x-webkit-deflate-frame\r\n";
    $out .= "User-Agent: Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.153 Safari/537.36\r\n";
    $out .= $myCookieHeader."\r\n\r\n";
    $fp = fsockopen("ssl://".$apicIP, $port, $errno, $errstr, 30);
    if (!$fp) {
        echo "fsockopen to [ssl://{$apicIP}:{$port}] returned a false: $errstr ($errno)\n";
    } else {
        //echo "fsockopen was ok, and structure of fp is:\n";
        //var_dump($fp);
	stream_set_blocking($fp, 0);
	stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
	$secondsSinceStartup=0;
	$sentSubscribe=false;
	$sendVlanPoolToggle=false;
	echo "sending on socket: {$out}";
	fwrite($fp, $out);
	echo "APIC EVENT RECEPTION START -->>>\n";
	while (true) {
	    $secondsSinceStartup++;
	    if (($secondsSinceStartup > 5) && (!$sentSubscribe)) {
	        // after 5 seconds here, then send the event subscription with the curl get method from above and we want a valid eventID back
	        $urlSSL = $protocol.$apicIP."/api/".$path;
	        $ch = curl_init();
	        $options2 = array(CURLOPT_URL => $urlSSL, CURLOPT_HTTPHEADER => $header, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPGET => true,
                                  CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 1);
	        curl_setopt_array($ch, $options2);
	        $response = curl_exec($ch);
	        if (!$response) {
	            echo "test-data: had a false return: [".curl_error($ch)."]\n";
	        }
	        curl_close($ch);
	        echo "APIC INTERFACES SUBSCRIBE REPLY START -->>>\n";
	        var_dump($response);
	        echo "APIC INTERFACES SUBSCRIBE REPLY END -->>>\n\n";
	        $sentSubscribe=true;
	    }
	    if (($secondsSinceStartup > 10) && (!$sendVlanPoolToggle)) {
		// send the keepalive pool creation and deletion one time - we should then get an event here
		$keepalivePoolName="CONNECTTESTPOOL";
		$keepaliveMin="100";
		$keepaliveMax="102";
		$urlSSL = "https://".$apicIP."/api/node/mo/uni/infra/vlanns-['.$keepalivePoolName.']-dynamic.json";
		$actionType="created";
		$json = '{"fvnsVlanInstP":{"attributes":{"dn":"uni/infra/vlanns-['.$keepalivePoolName.']-dynamic","name":"'.$keepalivePoolName.'","rn":"vlanns-['.$keepalivePoolName.']-dynamic","status":"';
	        $json .= $actionType.'"},"children":[{"fvnsEncapBlk":{"attributes":{"dn":"uni/infra/vlanns-['.$keepalivePoolName.']-dynamic/from-[vlan-';
	        $json .= $keepaliveMin.']-to-[vlan-'.$keepaliveMax.']","from":"vlan-'.$keepaliveMin.'","to":"vlan-'.$keepaliveMax.'","rn":"from-[vlan-'.$keepaliveMin.']-to-[vlan-'.$keepaliveMax.']","status":"';
		$json .= $actionType.'"},"children":[]}}]}}';
	        //echo date("Y-m-d H:i:s")." >>>>>Keepalive VLAN APIC JSON Send for Event: {$json}\n";
		$ch = curl_init();
		$options2 = array(CURLOPT_URL => $urlSSL, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
                                  CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_HTTPHEADER=>$header, CURLOPT_SSL_VERIFYPEER => false);
		curl_setopt_array($ch, $options2);
		$response = curl_exec($ch);
   		usleep(500000);
		$urlSSL = "https://".$apicIP."/api/node/mo/uni/infra.json";
		$actionType="deleted";
	        $json = '{"infraInfra":{"attributes":{"dn":"uni/infra","status":"modified"},"children":[{"fvnsVlanInstP":{"attributes":{"dn":"uni/infra/vlanns-['.$keepalivePoolName.']-dynamic","status":"';
		$json .= $actionType.'"},"children":[]}}]}}';
	        //echo date("Y-m-d H:i:s")." >>>>>Keepalive APIC JSON Send for Event: {$json}\n";
		$ch = curl_init();
		$options2 = array(CURLOPT_URL => $urlSSL, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
                                  CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1, CURLOPT_HTTPHEADER=>$header, CURLOPT_SSL_VERIFYPEER => false);
		curl_setopt_array($ch, $options2);
		$response = curl_exec($ch);
		curl_close($ch);
		$sendVlanPoolToggle=true;
	    }
	    if (feof($fp) === true) {
	        echo "Socket closed from APIC side.\n";
	        break;
	    }
	    $dataIn = fgets($fp, 1024);
	    if (!$dataIn) {
	        echo "[".$secondsSinceStartup."]";
	        sleep(1);
	    } else {
		echo $dataIn;
	    }
	    // Now for me to breakout after test seconds for this testing routine
	    if ($secondsSinceStartup > $subscriptionTestSeconds) break;
	}
	echo "APIC EVENT RECEPTION END -->>>\n\n";
	//echo "closing socket.\n";
	fclose($fp);
    }
}
