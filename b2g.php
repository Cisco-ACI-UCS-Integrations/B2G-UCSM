<?PHP

define("CURL_SSLVERSION_TLSv1", 1);
define("CURL_SSLVERSION_TLSv1_0", 4);
define("CURL_SSLVERSION_TLSv1_1", 5);
define("CURL_SSLVERSION_TLSv1_2", 6);

//Setup the classes and the opther php scripts
require 'class.ucs.php';
require 'class.apic.php';
require 'class.common.php';
require 'class.rack.php';

date_default_timezone_set('UTC');

//Allocate some memory to be shared
$flowmap = new storage();
$dynamicflowmap = new storage();
$tmpflowmap = new storage();
$attributemap = new storage();
$apiccallqueue = new storage();
$ucscallqueue = new storage();
$keyvaluepair = new storage();
$soakqueue = new storage();
$junkfilter = new storage();
$rackservers = new storage();
$serverlist = new storage();
$rackcommand = new storage();
$storageindex_class = new storage();

// defaults
$userstory=0;
$realEnvironment=true;   // this is the default of not using ACI or UCS simulators.  The init file will set this for simulators, etc.
$simFIAPortSeed=20;
$simFIBPortSeed=24;
$simLeafSpanPort=10;
$simVPCkey=35;
$simSPANkey=5;
$runInteractive=true;
$manageMyDJL2=true;
$manageAPICVTEP=false;
$manageVMMUUflooding=false;
$physDomainStartupDelay=30;
$apicip="empty";
$apicuser="empty";
$apicpwd="empty";
$ucsip="empty";
$ucsuser="empty";
$ucspwd="empty";
$ucsDomainsAEP="UCS-default";
$ucsvSwitchPol="UCS-default";
$ucsmVXLANtransportVLAN=3000;
$ucsmVLANpoolmin=1;
$ucsmVLANpoolmax=2047;
$cimcVLANpoolmin=1;
$cimcVLANpoolmax=4092;
$logGeneraloperations=false;
$logBareMetaloperations=false;
$logVMMoperations=false;
$logVPCoperations=false;
$logSPANoperations=false;
$logRackoperations=false;
$logUCSMevents=false;
$logDoerCalls=false;

// check the command line arguments for runAsDaemon on commandline
//echo "command line arg vardump:\n";
//var_dump($arg);
for ($argCount=0; $argCount<10; $argCount++) {
    if (isset($argv[$argCount])) {
        //echo "Argument #{$argCount} is: {$argv[$argCount]}\n";
        switch ($argv[$argCount]) {
            case "-?":
            case "-h":
            case "-help":
                echo "Please review and update the b2g.init file for the descriptions of items to configure (UCSM instances, APIC instance, UCS Rack instances, VLAN ranges, etc.).\n";
                echo "To run this process in the background (so your terminal timeout does not stop the process), startup via the runB2G script ";
                echo "using 'php ./b2g.php -runAsDaemon' to start, but this is included in the 'runB2G' script.  You would then use 'tail -f nohup.out' to view messages as the program runs.\n";
                echo "To run this interactively (for troubleshooting, monitoring, etc.) use 'php ./b2g.php' to start, and 'tail -f debug.out' in another terminal session to view messages.\n";
                echo "This is developed by Dan Hanson (danhanso@cisco.com) under an open source model, on a framework developed by Roger Andersson.  Please contribute any feedback and changes via aci-ucs-b2g@cisco.com.\n";
                return;
            case "-runAsDaemon":
                $runInteractive=false;
                break;
        }
    } else {
        break;
    }
}

function check_init_file (&$userstory, &$realEnvironment, &$simFIAPortSeed, &$simFIBPortSeed, &$simLeafSpanPort, &$simVPCkey, &$simSPANkey, &$manageMyDJL2, &$manageAPICVTEP,
                          &$ucsDomainsAEP, &$ucsvSwitchPol, &$manageVMMUUflooding, &$physDomainStartupDelay, &$apicip, &$apicuser, &$apicpwd, &$ucsip, &$ucsuser,
                          &$ucspwd, &$serverlist, &$ucsmVXLANtransportVLAN, &$ucsmVLANpoolmin, &$ucsmVLANpoolmax, &$cimcVLANpoolmin, &$cimcVLANpoolmax,
                          &$logGeneraloperations, &$logBareMetaloperations, &$logVMMoperations, &$logVPCoperations, &$logSPANoperations, &$logRackoperations, &$logUCSMevents, &$logDoerCalls) {
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
                    case "simFIA-LeafPortSeed:":
                        echo date("Y-m-d H:i:s")." -> simFIA-LeafPortSeed is: ".$inputElement[1]."\n";
                        $simFIAPortSeed = $inputElement[1];
                        break;
                    case "simFIB-LeafPortSeed:":
                        echo date("Y-m-d H:i:s")." -> simFIB-LeafPortSeed is: ".$inputElement[1]."\n";
                        $simFIBPortSeed = $inputElement[1];
                        break;
                    case "simLeafSpanPort:":
                        echo date("Y-m-d H:i:s")." -> simLeafSpanPort is: ".$inputElement[1]."\n";
                        $simLeafSpanPort = $inputElement[1];
                        break;
                    case "simVPCkey:":
                        echo date("Y-m-d H:i:s")." -> simVPCkey is: ".$inputElement[1]."\n";
                        $simVPCkey = $inputElement[1];
                        break;
                    case "simSPANkey:":
                        echo date("Y-m-d H:i:s")." -> simSPANkey is: ".$inputElement[1]."\n";
                        $simSPANkey = $inputElement[1];
                        break;
                    case "manageDJL2:":
                        echo date("Y-m-d H:i:s")." -> manageDJL2 is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $manageMyDJL2=true;
                        } else {
                            $manageMyDJL2=false;
                        }
                        break;
                    case "manageAPICVTEP:":
                        echo date("Y-m-d H:i:s")." -> manageAPICVTEP is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $manageAPICVTEP=true;
                        } else {
                            $manageAPICVTEP=false;
                        }
                        break;
                    case "UCSgroupAEPname:":
                        echo date("Y-m-d H:i:s")." -> ucsDomainsAEP is: ".$inputElement[1]."\n";
                        $ucsDomainsAEP = $inputElement[1];
                        break;
                    case "UCSvSwitchPolicyName:":
                        echo date("Y-m-d H:i:s")." -> UCSvSwitchPolicyName is: ".$inputElement[1]."\n";
                        $ucsvSwitchPol = $inputElement[1];
                        break;
                    case "manageVMMUUflooding:":
                        echo date("Y-m-d H:i:s")." -> manageVMMUUflooding is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $manageVMMUUflooding=true;
                        } else {
                            $manageVMMUUflooding=false;
                        }
                        break;
                    case "physDomainStartupDelay:":
                        echo date("Y-m-d H:i:s")." -> physDomainStartupDelay is: ".$inputElement[1]."\n";
                        $physDomainStartupDelay = $inputElement[1];
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
                        $ucsip = $inputElement[1];
                        break;
                    case "ucsmuser:":
                        echo date("Y-m-d H:i:s")." -> ucsuser is: ".$inputElement[1]."\n";
                        $ucsuser = $inputElement[1];
                        break;
                    case "ucsmpwd:":
                        echo date("Y-m-d H:i:s")." -> ucspwd is gathered.\n";
                        $ucspwd = $inputElement[1];
                        break;
                    case "ucsmVXLANtransportVLAN:":
                        echo date("Y-m-d H:i:s")." -> the UCSM transport VLAN for VXLAN connections is: ".$inputElement[1]."\n";
                        $ucsmVXLANtransportVLAN = $inputElement[1];
                        break;
                    case "ucsmVLANpoolmin:":
                        echo date("Y-m-d H:i:s")." -> the UCSM min VLAN for dynamic pool usage is: ".$inputElement[1]."\n";
                        $ucsmVLANpoolmin = $inputElement[1];
                        break;
                    case "ucsmVLANpoolmax:":
                        echo date("Y-m-d H:i:s")." -> the UCSM max VLAN for dynamic pool usage is: ".$inputElement[1]."\n";
                        $ucsmVLANpoolmax = $inputElement[1];
                        break;
                    case "cimcVLANpoolmin:":
                        echo date("Y-m-d H:i:s")." -> the CIMC min VLAN for dynamic pool usage is: ".$inputElement[1]."\n";
                        $cimcVLANpoolmin = $inputElement[1];
                        break;
                    case "cimcVLANpoolmax:":
                        echo date("Y-m-d H:i:s")." -> the CIMC max VLAN for dynamic pool usage is: ".$inputElement[1]."\n";
                        $cimcVLANpoolmax = $inputElement[1];
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
                        $serverlist[] = array("ip"=>$tempCip, "username"=>$tempCuser, "password"=>$tempCpwd);
                        break;
                    case "logGeneraloperations:":
                        echo date("Y-m-d H:i:s")." -> logGeneraloperations is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $logGeneraloperations=true;
                        } else {
                            $logGeneraloperations=false;
                        }
                        break;
                    case "logBareMetaloperations:":
                        echo date("Y-m-d H:i:s")." -> logBareMetaloperations is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $logBareMetaloperations=true;
                        } else {
                            $logBareMetaloperations=false;
                        }
                        break;
                    case "logVMMoperations:":
                        echo date("Y-m-d H:i:s")." -> logVMMoperations is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $logVMMoperations=true;
                        } else {
                            $logVMMoperations=false;
                        }
                        break;
                    case "logVPCoperations:":
                        echo date("Y-m-d H:i:s")." -> logVPCoperations is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $logVPCoperations=true;
                        } else {
                            $logVPCoperations=false;
                        }
                        break;
                    case "logSPANoperations:":
                        echo date("Y-m-d H:i:s")." -> logSPANoperations is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $logSPANoperations=true;
                        } else {
                            $logSPANoperations=false;
                        }
                        break;
                    case "logRackoperations:":
                        echo date("Y-m-d H:i:s")." -> logRackoperations is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $logRackoperations=true;
                        } else {
                            $logRackoperations=false;
                        }
                        break;
                    case "logUCSMevents:":
                        echo date("Y-m-d H:i:s")." -> logUCSMevents is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $logUCSMevents=true;
                        } else {
                            $logUCSMevents=false;
                        }
                        break;
                    case "logDoerCalls:":
                        echo date("Y-m-d H:i:s")." -> logDoerCalls is: ".$inputElement[1]."\n";
                        if ($inputElement[1] === "true") {
                            $logDoerCalls=true;
                        } else {
                            $logDoerCalls=false;
                        }
                        break;
                    default:
                        continue;
                }
            }
        }
    }
}

// This sets the userstories and the systems/credentials from the B2G.init file
check_init_file($userstory, $realEnvironment, $simFIAPortSeed, $simFIBPortSeed, $simLeafSpanPort, $simVPCkey, $simSPANkey, $manageMyDJL2, $manageAPICVTEP, $ucsDomainsAEP,
                $ucsvSwitchPol, $manageVMMUUflooding, $physDomainStartupDelay, $apicip, $apicuser, $apicpwd, $ucsip, $ucsuser, $ucspwd, $serverlist,
                $ucsmVXLANtransportVLAN, $ucsmVLANpoolmin, $ucsmVLANpoolmax, $cimcVLANpoolmin, $cimcVLANpoolmax, $logGeneraloperations, $logBareMetaloperations,
                $logVMMoperations, $logVPCoperations, $logSPANoperations, $logRackoperations, $logUCSMevents, $logDoerCalls);

//=================================================================================================
//Flowmap and Controller IP setup.  Flowmaps are simply nested arrays of strings, pointing to other array's underneath.  These define how the program will act upon receiving events from APIC, UCSM,
//or Even CIMC events.  The index on the flowmap arrays, are simply a method to create a unique string by concatenation of the IP's, classes, first array element, and scope.
//
//  Key (inside the $flowmap()) is just a globally unique index to the entry in the array.  We use the <A><B>... as field delimiters to understand what we are indexing.
//  CONSTRUCTOR:        Function call from the doer on the received events
//  KEEP_SYNC:          Weather the destination should be synchronized to the source when its items are deleted or not
//  PARENT:             ROOT or ROOT_PEER
//  SOURCE_SYSTEM:      The IP of the source of data in this flowmap entry
//  SOURCE_CLASS:       The DME objects of the source of data that we need
//  SOURCE_ATTRIBUTES:  An array of properties and values of interest to be included from the source object - this is a multi-item filter
//  SOURCE_SCOPE:       The dn scope of the DME object for the source of the data
//  DEST_SYSTEM:        The IP of the destination of data in this flowmap entry
//  DEST_CLASSES:       The DME objects of the receiver of data in this flowmap entry.  We could write multiple dest objects for a source item.
//  DEST_ATTRIBUTES:    An array of properties and values to be included for the destination object.
//  DEST_DEFAULT:       An array of default properties and values to be used when creating a destination object (Optional)
//
//Dynamic Flowmap setup.  The only difference here, is we dynamically need some flowmaps at some point, to gather more monitoring information from object that are created at runtime within APIC.
//These objects are created based on events occuring in the normal definition of data on the APIC.  Examples are Tennant names/App profile names/EPG's to monitor after they are created.
//  DEST_SYSTEM:                    This is removed as this is internal program logic regarding relationships on the Source between objects
//  BASTARD:                        If parent is removed, then we remove our subscription also
//  Added detail on SOURCE_SCOPE:   This has the REGEX format of the DN of an object, with a flag for the relevant labels and names to gather information under created objects
//
//=================================================================================================

switch($userstory) {
    case 0: // vPC Auto Connection to ACI leaf pair, from the UCSM Setup
        // UCSM Flowmaps:
        // Subscribe to UCS VLAN events from UCSM, in this usercase it is just for the event subscription keepalives
        $flowmap["$ucsip<A>fabricVlan<B>name<C>$apicip<D>fabric/lan"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricVlan",
                                                                            "SOURCE_ATTRIBUTES"=>array("id"=>""),
                                                                            "SOURCE_SCOPE"=>"fabric/lan", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // A fabric PC formation events
        $flowmap["$ucsip<A>fabricEthLanPc<B>portId<C>$apicip<D>fabric/lan/A"]=array("CONSTRUCTOR" => "VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                    "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthLanPc",
                                                                                    "SOURCE_ATTRIBUTES"=>array("portId"=>"", "switchId"=>"", "type"=>"lan", "dn"=>""),
                                                                                    "SOURCE_SCOPE"=>"fabric/lan/A", "DEST_SYSTEM"=>$apicip,
                                                                                    "DEST_CLASSES"=>array(),
                                                                                    "DEST_ATTRIBUTES"=>array());
        // B fabric PC formation events
        $flowmap["$ucsip<A>fabricEthLanPc<B>portId<C>$apicip<D>fabric/lan/B"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                    "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthLanPc",
                                                                                    "SOURCE_ATTRIBUTES"=>array("portId"=>"", "switchId"=>"", "type"=>"lan", "dn"=>""),
                                                                                    "SOURCE_SCOPE"=>"fabric/lan/B", "DEST_SYSTEM"=>$apicip,
                                                                                    "DEST_CLASSES"=>array(),
                                                                                    "DEST_ATTRIBUTES"=>array());
        $flowmap["$ucsip<A>fabricEthVlanPc<B>name<C>$apicip<D>fabric/lan"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthVlanPc",
                                                                            "SOURCE_ATTRIBUTES"=>array("isNative"=>""),
                                                                            "SOURCE_SCOPE"=>"fabric/lan", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // A fabric PC port membership events (when things happen to the underlying ports of the PC on the UCS)
        $dynamicflowmap["fabricEthLanPcEp<A>portIdA"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthLanPcEp",
                                                            "SOURCE_ATTRIBUTES"=>array("portId"=>"^([0-9]|[1-4][0-9])$", "slotId"=>"^([0-3])$", "switchId"=>"", "dn"=>""),
                                                            "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"fabric/lan/A/pc-#lbl:fabricEthLanPc->portId#",
                                                            "DEST_CLASSES"=>array(),
                                                            "DEST_ATTRIBUTES"=>array());
        // B fabric PC port membership events (when things happen to the underlying ports of the PC on the UCS)
        $dynamicflowmap["fabricEthLanPcEp<A>portIdB"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthLanPcEp",
                                                            "SOURCE_ATTRIBUTES"=>array("portId"=>"^([0-9]|[1-4][0-9])$", "slotId"=>"^([0-3])$", "switchId"=>"", "dn"=>""),
                                                            "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"fabric/lan/B/pc-#lbl:fabricEthLanPc->portId#",
                                                            "DEST_CLASSES"=>array(),
                                                            "DEST_ATTRIBUTES"=>array());
        // APIC Flowmaps:
        // Subscribe to explicit port grouping events from APIC
        $flowmap["$apicip<A>fabricExplicitGEp<B>dn<C>$ucsip<D>uni/fabric/protpol"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fabricExplicitGEp",
                                                                                         "SOURCE_ATTRIBUTES"=>array("dn"=>"", "id"=>"", "name"=>""),
                                                                                         "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>$ucsip,
                                                                                         "DEST_CLASSES"=>array(),
                                                                                         "DEST_ATTRIBUTES"=>array());
        // Subscribe to physical node events from APIC
        $flowmap["$apicip<A>infraNodeP<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraNodeP",
                                                                         "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                         "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                         "DEST_CLASSES"=>array(),
                                                                         "DEST_ATTRIBUTES"=>array());
        // Subscribe to physical access port events from APIC
        $flowmap["$apicip<A>infraAccPortP<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                            "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraAccPortP",
                                                                            "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                            "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // Subscribe to link aggregation policy events from APIC
        $flowmap["$apicip<A>lacpLagPol<B>mode<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"lacpLagPol",
                                                                         "SOURCE_ATTRIBUTES"=>array("mode"=>""),
                                                                         "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                         "DEST_CLASSES"=>array(),
                                                                         "DEST_ATTRIBUTES"=>array());
        // Subscribe to port access bundle events from APIC
        $flowmap["$apicip<A>infraAccBndlGrp<B>dn<C>$ucsip<D>uni/infra/funcprof"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                       "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraAccBndlGrp",
                                                                                       "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                                       "SOURCE_SCOPE"=>"uni/infra/funcprof", "DEST_SYSTEM"=>$ucsip,
                                                                                       "DEST_CLASSES"=>array(),
                                                                                       "DEST_ATTRIBUTES"=>array());
        // Subscribe to infrastructure leaf events from APIC
        $flowmap["$apicip<A>infraLeafS<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraLeafS",
                                                                         "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                         "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                         "DEST_CLASSES"=>array(),
                                                                         "DEST_ATTRIBUTES"=>array());
        // Subscribe to infrastructure node bulk events
        $flowmap["$apicip<A>infraNodeBlk<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                           "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraNodeBlk",
                                                                           "SOURCE_ATTRIBUTES"=>array("dn"=>"", "from_"=>""),
                                                                           "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                           "DEST_CLASSES"=>array(),
                                                                           "DEST_ATTRIBUTES"=>array());
        // Subscribe to AEP events
        $flowmap["$apicip<A>infraAttEntityP<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                           "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraAttEntityP",
                                                                           "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                                           "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                           "DEST_CLASSES"=>array(),
                                                                           "DEST_ATTRIBUTES"=>array());
        // Subscribe to DHCP provider
        $flowmap["$apicip<A>dhcpRelayP<B>dn<C>$ucsip<D>uni/tn-infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                           "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"dhcpRelayP",
                                                                           "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                                           "SOURCE_SCOPE"=>"uni/tn-infra", "DEST_SYSTEM"=>$ucsip,
                                                                           "DEST_CLASSES"=>array(),
                                                                           "DEST_ATTRIBUTES"=>array());
        // Subscribe to DHCP label in infra default BD
        $flowmap["$apicip<A>dhcpLabel<B>dn<C>$ucsip<D>uni/tn-infra/BD-default"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                           "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"dhcpLbl",
                                                                           "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                                           "SOURCE_SCOPE"=>"uni/tn-infra/BD-default", "DEST_SYSTEM"=>$ucsip,
                                                                           "DEST_CLASSES"=>array(),
                                                                           "DEST_ATTRIBUTES"=>array());
        // Subscribe to physical domain events from APIC
        $flowmap["$apicip<A>physDomP<B>name<C>$ucsip<D>uni"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"physDomP",
                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                   "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>$ucsip,
                                                                   "DEST_CLASSES"=>array(),
                                                                   "DEST_ATTRIBUTES"=>array());
        // Subscribe to VLAN pool events from APIC
        $flowmap["$apicip<A>fvnsVlanInstP<B>name<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                              "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvnsVlanInstP",
                                                                              "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                              "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                              "DEST_CLASSES"=>array(),
                                                                              "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fabricNodePEp<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                    "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fabricNodePEp",
                                                    "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                    "BASTARD"=>"FALSE","SOURCE_SCOPE"=>"uni/fabric/protpol/expgep-#lbl:fabricExplicitGEp->name#",
                                                    "DEST_CLASSES"=>array(),
                                                    "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraHPortS<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                  "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraHPortS",
                                                  "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                  "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/accportprof-#lbl:infraAccPortP->name#",
                                                  "DEST_CLASSES"=>array(),
                                                  "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraPortBlk<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraPortBlk",
                                                   "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                   "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/accportprof-#lbl:infraAccPortP->name#/hports-#lbl:infraHPortS->name#",
                                                   "DEST_CLASSES"=>array(),
                                                   "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraRsAccBaseGrp<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                        "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraRsAccBaseGrp",
                                                        "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                        "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/accportprof-#lbl:infraAccPortP->name#/rsaccBaseGrp",
                                                        "DEST_CLASSES"=>array(),
                                                        "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraRsLacpPol<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                     "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraRsLacpPol",
                                                     "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                     "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/funcprof/accbundle-#lbl:infraAccBndlGrp->name#",
                                                     "DEST_CLASSES"=>array(),
                                                     "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraRsAccPortP<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                      "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraRsAccPortP",
                                                      "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                      "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/nprof-#lbl:infraNodeP->name#/rsaccPortP-[uni/infra/accportprof-#lbl:infraAccPortP->name#]",
                                                      "DEST_CLASSES"=>array(),
                                                      "DEST_ATTRIBUTES"=>array());
        break;
    case 2: // VMM with VXLAN backing to UCSM (we will use a flag on if VLAN or VXLAN backed in routines to do the unique things)
        // Items unique to the VXLAN case
        // UCSM Flowmaps:
        // Subscribe to events on the needed UCS multicast policy
        $flowmap["$ucsip<A>fabricMulticastPolicy<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricMulticastPolicy",
                                                                            "SOURCE_ATTRIBUTES"=>array("snoopingState"=>""),
                                                                            "SOURCE_SCOPE"=>"org-root/mc-policy-for-VXLAN-mcast", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("name"=>"for-VXLAN-mcast", "dn"=>"org-root/mc-policy-for-VXLAN-mcast", "snoopingState"=>"enabled", "descr"=>"Auto Created by B2G for VXLAN Needs"));
        $flowmap["$ucsip<A>fabricEthVlanPc<B>name<C>$apicip<D>fabric/lan"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthVlanPc",
                                                                            "SOURCE_ATTRIBUTES"=>array("isNative"=>""),
                                                                            "SOURCE_SCOPE"=>"fabric/lan", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // APIC Flowmaps:
        // Subscribe to APIC VXLAN virtualwire events
        $flowmap["$apicip<A>hvsExtPol<B>startEncap<C>$ucsip<D>comp/prov-VMware"]=array("CONSTRUCTOR" => "VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                              "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"hvsExtPol",
                                                                              "SOURCE_ATTRIBUTES"=>array("startEncap"=>"vxlan-.*"),
                                                                              "SOURCE_SCOPE"=>"comp/prov-VMware", "DEST_SYSTEM"=>$ucsip,
                                                                              "DEST_CLASSES"=>array(),
                                                                              "DEST_ATTRIBUTES"=>array());
    case 1: // VMM with VLANs to the UCSM Setup - and the items common with the VXLAN case        
        // UCSM Flowmaps:
        // Subscribe to UCS VLAN events from UCSM
        $flowmap["$ucsip<A>fabricVlan<B>name<C>$apicip<D>fabric/lan"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricVlan",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"", "id"=>""),
                                                                            "SOURCE_SCOPE"=>"fabric/lan", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // Subscribe to UCS compute blade events from UCSM, to correlate via UUID where the UCS servers hosting a hypervisor live, and therefore the UCSM domain needs these EPG networks
        $flowmap["$ucsip<A>computeBlade<B>uuid<C>$apicip<D>sys"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                       "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"computeBlade",
                                                                       "SOURCE_ATTRIBUTES"=>array("uuid"=>""),
                                                                       "SOURCE_SCOPE"=>"sys", "DEST_SYSTEM"=>$apicip,
                                                                       "DEST_CLASSES"=>array(),
                                                                       "DEST_ATTRIBUTES"=>array());
        //UCSM - nwctrlDefinition 
        $flowmap["$ucsip<A>nwctrlDefinition<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"nwctrlDefinition",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"ACI-LLDP", "lldpReceive"=>"", "lldpTransmit"=>""),
                                                                            "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("name"=>"ACI-LLDP", "dn"=>"org-root/nwctrl-ACI-LLDP", "lldpReceive"=>"enabled", "lldpTransmit"=>"enabled", "descr"=>"Auto created by B2G process"));
        //UCSM - if the mac-forge is reset to deny in the vNIC template case for VMM servers, then we reset to allow for the VM MAC's to work
        $flowmap["$ucsip<A>dpsecMac<B>forge<C>$apicip<D>org-root/nwctrl-ACI-LLDP/mac-sec"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"dpsecMac",    // the source IP to listen to subscription events, and the class to look for
                                                                            "SOURCE_ATTRIBUTES"=>array("forge"=>""),    // allow any of these events into the DOER and REMOVE_DN routines
                                                                            "SOURCE_SCOPE"=>"org-root/nwctrl-ACI-LLDP/mac-sec", "DEST_SYSTEM"=>$apicip,  // scope so we dont blacklist, and we just set the APIC IP even though we dont write to it
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("forge"=>"allow", "dn"=>"org-root/nwctrl-ACI-LLDP/mac-sec"));    // set this on the re-writing
        //UCSM - if the best effort class is changed in MTU, we reset for this case
        $flowmap["$ucsip<A>qosclassEthBE<B>mtu<C>$apicip<D>fabric/lan/classes/class-best-effort"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"qosclassEthBE",
                                                                            "SOURCE_ATTRIBUTES"=>array("mtu"=>""),
                                                                            "SOURCE_SCOPE"=>"fabric/lan/classes/class-best-effort", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("mtu"=>"9000", "dn"=>"fabric/lan/classes/class-best-effort"));
        //UCSM - epqosDefinition 
        $flowmap["$ucsip<A>epqosDefinition<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"epqosDefinition",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"ACIleafHV"),
                                                                            "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("name"=>"ACIleafHV", "dn"=>"org-root/ep-qos-ACIleafHV", "descr"=>"Auto created by B2G process"));
        //UCSM - if the ACI Leaf hypervisor QoS definition had its priority or host control changed, then we reset them
        $flowmap["$ucsip<A>epqosEgress<B>prio<C>$apicip<D>org-root/ep-qos-ACIleafHV/egress"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"epqosEgress",
                                                                            "SOURCE_ATTRIBUTES"=>array("hostControl"=>"", "prio"=>""),
                                                                            "SOURCE_SCOPE"=>"org-root/ep-qos-ACIleafHV/egress", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("hostControl"=>"full", "dn"=>"org-root/ep-qos-ACIleafHV/egress", "prio"=>"best-effort"));
        //UCSM - vnicVmqConPolicy 
        $flowmap["$ucsip<A>vnicVmqConPolicy<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicVmqConPolicy",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"ACIleafVMQ"),
                                                                            "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("name"=>"ACIleafVMQ", "dn"=>"org-root/vmq-con-ACIleafVMQ", "descr"=>"Auto created by B2G process"));
        //vnicVmqConPolicyRef
        $flowmap["$ucsip<A>vnicVmqConPolicyRef<B>dn<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicVmqConPolicyRef",
                                                                            "SOURCE_ATTRIBUTES"=>array("dn"=>"", "conPolicyName"=>""),
                                                                            "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // Subscribe to UCS VMM host vNIC template events from UCSM, to correlate and re-add any needed, missing, admin removed, etc. VLANs within the vNIC template - but only for those domains that need these EPG networks
        // One important add here, when we get LLDP on VIF's in UCSM 2.2(4) then we will want to create a nwCtrlPolicy for LLDP on, and set.  This is an updating template as we can later publish other EPG's to the VMM domain, and those are auto-added.
        $flowmap["$ucsip<A>vnicLanConnTempl<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                                  "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicLanConnTempl",
                                                                                  "SOURCE_ATTRIBUTES"=>array("name"=>"", "qosPolicyName"=>"", "nwCtrlPolicyName"=>"", "switchId"=>"", "templType"=>"", "mtu"=>""),
                                                                                  "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                                  "DEST_CLASSES"=>array(),
                                                                                  "DEST_ATTRIBUTES"=>array(),
                                                                                  "DEST_DEFAULT"=>array("identPoolName"=>"default", "mtu"=>"9000", "nwCtrlPolicyName"=>"ACI-LLDP", "pinToGroupName"=>"",
                                                                                                          "qosPolicyName"=>"ACIleafHV", "statsPolicyName"=>"default", "target"=>"adaptor", "templType"=>"updating-template"));
        // Subscribe to UCS service profile vNIC events from UCSM, to correlate and re-add any needed, missing, admin removed, etc. VLANs within the vNIC - but only for those domains that need these EPG networks
        $flowmap["$ucsip<A>vnicEtherIf<B>defaultNet<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                             "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicEtherIf",
                                                                             "SOURCE_ATTRIBUTES"=>array("defaultNet"=>""),
                                                                             "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                             "DEST_CLASSES"=>array(),
                                                                             "DEST_ATTRIBUTES"=>array(),
                                                                             "DEST_DEFAULT"=>array());
        // APIC Flowmaps:
        // Subscribe to virtual machine manager domain events from APIC
        $flowmap["$apicip<A>vmmDomP<B>name<C>$ucsip<D>uni/vmmp-VMware"]=array("CONSTRUCTOR" => "VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                              "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"vmmDomP",
                                                                              "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                              "SOURCE_SCOPE"=>"uni/vmmp-VMware", "DEST_SYSTEM"=>$ucsip,
                                                                              "DEST_CLASSES"=>array(),
                                                                              "DEST_ATTRIBUTES"=>array());
        // Subscribe to physical domain events from APIC
        $flowmap["$apicip<A>physDomP<B>name<C>$ucsip<D>uni"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"physDomP",
                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                   "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>$ucsip,
                                                                   "DEST_CLASSES"=>array(),
                                                                   "DEST_ATTRIBUTES"=>array());
        // Subscribe to VLAN pool events from APIC
        $flowmap["$apicip<A>fvnsVlanInstP<B>name<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                              "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvnsVlanInstP",
                                                                              "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                              "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                              "DEST_CLASSES"=>array(),
                                                                              "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["vmmCtrlrP<A>name"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                  "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"vmmCtrlrP",
                                                  "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                  "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/vmmp-VMware/dom-#lbl:vmmDomP->name#",
                                                  "DEST_CLASSES"=>array(),
                                                  "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["vmmEpPD<A>encap"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                 "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"vmmEpPD",
                                                 //"SOURCE_ATTRIBUTES"=>array("encap"=>"unknown|vlan-.*", "lbAlgo"=>""),
                                                 "SOURCE_ATTRIBUTES"=>array("encap"=>"^(unknown|vlan\-.*)$"),
                                                 "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/vmmp-VMware/dom-#lbl:vmmDomP->name#",
                                                 "DEST_CLASSES"=>array(),
                                                 "DEST_ATTRIBUTES"=>array(),
                                                 "DEST_DEFAULT"=>array("mcastPolicyName"=>"", "sharing"=>"none"));
        $dynamicflowmap["compHv<A>guid"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                               "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"compHv",
                                               "SOURCE_ATTRIBUTES"=>array("guid"=>""),
                                               "BASTARD"=>"TRUE", "SOURCE_SCOPE"=>"comp/prov-VMware/ctrlr-[#lbl:vmmDomP->name#]-#lbl:vmmCtrlrP->name#",
                                               "DEST_CLASSES"=>array(),
                                               "DEST_ATTRIBUTES"=>array());
        break;
    case 3: // UCSM Bare Metal Server Uses
        // UCSM Flowmaps:
        // Subscribe to UCS VLAN events from UCSM
        $flowmap["$ucsip<A>fabricVlan<B>name<C>$apicip<D>fabric/lan"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricVlan",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"", "id"=>""),
                                                                            "SOURCE_SCOPE"=> "fabric/lan", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // Subscribe to UCS BM vNIC template events from UCSM, to correlate and re-add any needed, missing, admin removed, etc. VLANs within the vNIC template - but only for those domains that need these EPG networks
        $flowmap["$ucsip<A>vnicLanConnTempl<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                                  "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicLanConnTempl",
                                                                                  "SOURCE_ATTRIBUTES"=>array("name"=>"", "qosPolicyName"=>"", "nwCtrlPolicyName"=>"", "switchId"=>"", "templType"=>"", "mtu"=>""),
                                                                                  "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                                  "DEST_CLASSES"=>array(),
                                                                                  "DEST_ATTRIBUTES"=>array(),
                                                                                  "DEST_DEFAULT"=>array("identPoolName"=>"default", "target"=>"adaptor", "templType"=>"updating-template"));
        // Subscribe to UCS service profile vNIC events from UCSM, to correlate and re-add any needed, missing, admin removed, etc. VLANs within the vNIC - but only for those domains that need these EPG networks
        $flowmap["$ucsip<A>vnicEtherIf<B>defaultNet<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                             "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicEtherIf",
                                                                             "SOURCE_ATTRIBUTES"=>array("defaultNet"=>""),
                                                                             "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                             "DEST_CLASSES"=>array(),
                                                                             "DEST_ATTRIBUTES"=>array(),
                                                                             "DEST_DEFAULT"=>array("defaultNet"=>"yes"));
        // APIC Flowmaps:
        // Subscribe to physical domain events from APIC
        $flowmap["$apicip<A>physDomP<B>name<C>$ucsip<D>uni"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"physDomP",
                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                   "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>$ucsip,
                                                                   "DEST_CLASSES"=>array(),
                                                                   "DEST_ATTRIBUTES"=>array());
        // Subscribe to VLAN pool events from APIC
        $flowmap["$apicip<A>fvnsVlanInstP<B>name<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                              "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvnsVlanInstP",
                                                                              "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                              "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                              "DEST_CLASSES"=>array(),
                                                                              "DEST_ATTRIBUTES"=>array());
        // Subscribe to fabric node events from APIC
        // Subscribe to physical access port events from APIC
        $flowmap["$apicip<A>infraAccPortP<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                            "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraAccPortP",
                                                                            "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                            "SOURCE_SCOPE"=>  "uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // Subscribe to infrastructure leaf events from APIC
        $flowmap["$apicip<A>infraLeafS<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraLeafS",
                                                                         "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                         "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                         "DEST_CLASSES"=>array(),
                                                                         "DEST_ATTRIBUTES"=>array());
        // Subscribe to infrastructure node bulk events
        $flowmap["$apicip<A>infraNodeBlk<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                           "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraNodeBlk",
                                                                           "SOURCE_ATTRIBUTES"=>array("dn"=>"", "from_"=>""),
                                                                           "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                           "DEST_CLASSES"=>array(),
                                                                           "DEST_ATTRIBUTES"=>array());
        // Subscribe to APIC tenant information to keep in sync when needed in later steps within dynamic flowmaps.
        $flowmap["$apicip<A>fvTenant<B>name<C>$ucsip<D>uni"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvTenant",
                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                   "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>$ucsip,
                                                                   "DEST_CLASSES"=>array(),
                                                                   "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvAp<A>name"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                             "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvAp",
                                             "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                             "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/tn-#lbl:fvTenant->name#",
                                             "DEST_CLASSES"=>array(),
                                             "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvAEPg<A>name"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                               "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvAEPg",
                                               "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                               "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"ap-#lbl:fvAp->name#",
                                               "DEST_CLASSES"=>array(),
                                               "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvRsDomAtt<A>tDn"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                  "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvRsDomAtt",
                                                  "SOURCE_ATTRIBUTES"=>array("tDn"=>"", "dn"=>""),
                                                  "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"epg-#lbl:fvAEPg->name#",
                                                  "DEST_CLASSES"=>array(),
                                                  "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvRsPathAtt<A>encap"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                     "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvRsPathAtt",
                                                     "SOURCE_ATTRIBUTES"=>array("encap"=>"", "tDn"=>"", "dn"=>""),
                                                     "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"epg-#lbl:fvAEPg->name#",
                                                     "DEST_CLASSES"=>array(),
                                                     "DEST_ATTRIBUTES"=>array());
        break;
    case 4: // SPAN to the UCSM Setup
        // UCSM Flowmaps:
        // Subscribe to UCS VLAN events from UCSM, in this usercase it is just for the event subscription keepalives
        $flowmap["$ucsip<A>fabricVlan<B>name<C>$apicip<D>fabric/lan"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricVlan",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"", "id"=>""),
                                                                            "SOURCE_SCOPE"=>"fabric/lan", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // Subscribe to SPAN session monitor instances of UCS FI-A    
        $flowmap["$ucsip<A>fabricEthMon<B>name<C>$apicip<D>fabric/lanmon/A"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                   "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthMon",
                                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>"", "id"=>""),
                                                                                   "SOURCE_SCOPE"=>"fabric/lanmon/A", "DEST_SYSTEM"=>$apicip,
                                                                                   "DEST_CLASSES"=>array("spanSrcGrp"=>"uni/infra"),
                                                                                   "DEST_ATTRIBUTES"=>array("name"=>"", "type"=>"", "transport"=>""));
        // Subscribe to SPAN session monitor instances of UCS FI-B    
        $flowmap["$ucsip<A>fabricEthMon<B>name<C>$apicip<D>fabric/lanmon/B"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                   "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthMon",
                                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>"", "id"=>""),
                                                                                   "SOURCE_SCOPE"=>"fabric/lanmon/B", "DEST_SYSTEM"=>$apicip,
                                                                                   "DEST_CLASSES"=>array("spanSrcGrp"=>"uni/infra"),
                                                                                   "DEST_ATTRIBUTES"=>array("name"=>"", "type"=>"", "transport"=>""));
        // Subscribe to SPAN destination fabric A port events on the UCSM
        $flowmap["$ucsip<A>fabricEthMonDestEp<B>switchID<C>$apicip<D>fabric/lanmon/A"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"fabricEthMon",
                                                                                             "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthMonDestEp",
                                                                                             "SOURCE_ATTRIBUTES"=>array("switchId"=>"", "portId"=>"","slotId"=>""),
                                                                                             "SOURCE_SCOPE"=>"fabric/lanmon/A", "DEST_SYSTEM"=>$apicip,
                                                                                             "DEST_CLASSES"=>array("spanSrc"=>"uni/infra", "spanRsSrcToPathEp"=>"uni/infra"),
                                                                                             "DEST_ATTRIBUTES"=>array());
        // Establish a flowmap that identifies we need to be on point to get the SPAN destination fabric B port events on the UCSM
        $flowmap["$ucsip<A>fabricEthMonDestEp<B>switchID<C>$apicip<D>fabric/lanmon/B"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"fabricEthMon",
                                                                                        "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthMonDestEp",
                                                                                        "SOURCE_ATTRIBUTES"=>array("switchId"=>"", "portId"=>"", "slotId"=>""),
                                                                                        "SOURCE_SCOPE"=>"fabric/lanmon/B", "DEST_SYSTEM"=>$apicip,
                                                                                        "DEST_CLASSES"=>array("spanSrc"=>"uni/infra","spanRsSrcToPathEp"=>"uni/infra"),
                                                                                        "DEST_ATTRIBUTES"=>array());
        // APIC Flowmaps:
        // Subscribe to physical domain events from APIC
        $flowmap["$apicip<A>physDomP<B>name<C>$ucsip<D>uni"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"physDomP",
                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                   "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>$ucsip,
                                                                   "DEST_CLASSES"=>array(),
                                                                   "DEST_ATTRIBUTES"=>array());
        // Subscribe to VLAN pool events from APIC
        $flowmap["$apicip<A>fvnsVlanInstP<B>name<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                              "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvnsVlanInstP",
                                                                              "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                              "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                              "DEST_CLASSES"=>array(),
                                                                              "DEST_ATTRIBUTES"=>array());
        break;
    case 5: // CIMC connected to ACI leaf or leaves.  The connection can be individualLinks or vPC (as the OS would then need to be configured to support vPC), signaled by a VPC somewhere in the userlabel string.
        // UCSM Flowmaps:
        // None. This case only talks to the CIMC XML API, and we poll for information there
        // APIC Flowmaps:
        // Subscribe to physical domain events from APIC
        $flowmap["$apicip<A>physDomP<B>nameC>RackDom<D>uni"]=array("CONSTRUCTOR"=>"RACK", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                       "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"physDomP",
                                                                       "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                       "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>"RACKSERVERS",
                                                                       "DEST_CLASSES"=>array(),
                                                                       "DEST_ATTRIBUTES"=>array());
        // Subscribe to VLAN pool events from APIC
        $flowmap["$apicip<A>fvnsVlanInstP<B>name<C>RackDom<D>uni/infra"]=array("CONSTRUCTOR"=>"RACK", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvnsVlanInstP",
                                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                                   "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>"RACKSERVERS",
                                                                                   "DEST_CLASSES"=>array(),
                                                                                   "DEST_ATTRIBUTES"=>array()); 
        // Subscribe to APIC tenant information to keep in sync when needed in later steps within dynamic flowmaps.
        $flowmap["$apicip<A>fvTenant<B>name<C>RackDom<D>uni"]=array("CONSTRUCTOR"=>"RACK", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                    "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvTenant",
                                                                    "SOURCE_ATTRIBUTES"=>array("name"=>"") ,
                                                                    "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>"RACKSERVERS",
                                                                    "DEST_CLASSES"=>array() ,
                                                                    "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvAp<A>name"]=array("CONSTRUCTOR"=>"RACK", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                             "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvAp",
                                             "SOURCE_ATTRIBUTES"=>array("name"=>"") ,
                                             "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/tn-#lbl:fvTenant->name#",
                                             "DEST_CLASSES"=>array(),
                                             "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvAEPg<A>name"]=array("CONSTRUCTOR"=>"RACK", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                               "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvAEPg",
                                               "SOURCE_ATTRIBUTES"=>array("name"=>"") ,
                                               "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"ap-#lbl:fvAp->name#",
                                               "DEST_CLASSES"=>array(),
                                               "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvRsDomAtt<A>RackDomains"]=array("CONSTRUCTOR"=>"RACK", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                          "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvRsDomAtt",
                                                          "SOURCE_ATTRIBUTES"=>array("tDn"=>"", "dn"=>"") ,
                                                          "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"epg-#lbl:fvAEPg->name#",
                                                          "DEST_CLASSES"=>array(),
                                                          "DEST_ATTRIBUTES"=>array());  
        $dynamicflowmap["fvRsPathAtt<A>RackEncap"]=array("CONSTRUCTOR"=>"RACK", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvRsPathAtt",
                                                         "SOURCE_ATTRIBUTES"=>array("encap"=>"", "tDn"=>"", "dn"=>""),
                                                         "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"epg-#lbl:fvAEPg->name#",
                                                         "DEST_CLASSES"=>array(),
                                                         "DEST_ATTRIBUTES"=>array());
        break;
    case 10: // This is the case to have 1 instance of this process run all the UCSM use cases.  An alternative for performance could be to run each as its own process.
        // The reasoning to run all (as the normal case), is some make use of others - like the bare metal and VMM on ucs utilize the VPC case.
        // UCSM Flowmaps:
        // Subscribe to UCS VLAN events from UCSM, in this usercase it is just for the event subscription keepalives
        $flowmap["$ucsip<A>fabricVlan<B>name<C>$apicip<D>fabric/lan"]=array("CONSTRUCTOR"=>"VMM-BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricVlan",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"", "id"=>""),
                                                                            "SOURCE_SCOPE"=>"fabric/lan", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // A fabric PC formation events
        $flowmap["$ucsip<A>fabricEthLanPc<B>portId<C>$apicip<D>fabric/lan/A"]=array("CONSTRUCTOR" => "VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                    "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthLanPc",
                                                                                    "SOURCE_ATTRIBUTES"=>array("portId"=>"", "switchId"=>"", "type"=>"lan", "dn"=>""),
                                                                                    "SOURCE_SCOPE"=>"fabric/lan/A", "DEST_SYSTEM"=>$apicip,
                                                                                    "DEST_CLASSES"=>array(),
                                                                                    "DEST_ATTRIBUTES"=>array());
        // B fabric PC formation events
        $flowmap["$ucsip<A>fabricEthLanPc<B>portId<C>$apicip<D>fabric/lan/B"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                    "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthLanPc",
                                                                                    "SOURCE_ATTRIBUTES"=>array("portId"=>"", "switchId"=>"", "type"=>"lan", "dn"=>""),
                                                                                    "SOURCE_SCOPE"=>"fabric/lan/B", "DEST_SYSTEM"=>$apicip,
                                                                                    "DEST_CLASSES"=>array(),
                                                                                    "DEST_ATTRIBUTES"=>array());
        // 6-30-15 Added BM
        $flowmap["$ucsip<A>fabricEthVlanPc<B>name<C>$apicip<D>fabric/lan"]=array("CONSTRUCTOR"=>"VMM-BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthVlanPc",
                                                                            "SOURCE_ATTRIBUTES"=>array("isNative"=>""),
                                                                            "SOURCE_SCOPE"=>"fabric/lan", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // Subscribe to events on the needed UCS multicast policy
        $flowmap["$ucsip<A>fabricMulticastPolicy<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricMulticastPolicy",
                                                                            "SOURCE_ATTRIBUTES"=>array("snoopingState"=>""),
                                                                            "SOURCE_SCOPE"=>"org-root/mc-policy-for-VXLAN-mcast", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("name"=>"for-VXLAN-mcast", "dn"=>"org-root/mc-policy-for-VXLAN-mcast", "snoopingState"=>"enabled", "descr"=>"Auto Created by B2G for VXLAN Needs"));
        // Subscribe to UCS compute blade events from UCSM, to correlate via UUID where the UCS servers hosting a hypervisor live, and therefore the UCSM domain needs these EPG networks
        $flowmap["$ucsip<A>computeBlade<B>uuid<C>$apicip<D>sys"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                       "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"computeBlade",
                                                                       "SOURCE_ATTRIBUTES"=>array("uuid"=>""),
                                                                       "SOURCE_SCOPE"=>"sys", "DEST_SYSTEM"=>$apicip,
                                                                       "DEST_CLASSES"=>array(),
                                                                       "DEST_ATTRIBUTES"=>array());
        //UCSM - nwctrlDefinition 
        $flowmap["$ucsip<A>nwctrlDefinition<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"nwctrlDefinition",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"ACI-LLDP", "lldpReceive"=>"", "lldpTransmit"=>""),
                                                                            "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("name"=>"ACI-LLDP", "dn"=>"org-root/nwctrl-ACI-LLDP", "lldpReceive"=>"enabled", "lldpTransmit"=>"enabled", "descr"=>"Auto created by B2G process"));
        //UCSM - if the mac-forge is reset to deny in the vNIC template case for VMM servers, then we reset to allow for the VM MAC's to work
        $flowmap["$ucsip<A>dpsecMac<B>forge<C>$apicip<D>org-root/nwctrl-ACI-LLDP/mac-sec"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"dpsecMac",    // the source IP to listen to subscription events, and the class to look for
                                                                            "SOURCE_ATTRIBUTES"=>array("forge"=>""),    // allow any of these events into the DOER and REMOVE_DN routines
                                                                            "SOURCE_SCOPE"=>"org-root/nwctrl-ACI-LLDP/mac-sec", "DEST_SYSTEM"=>$apicip,  // scope so we dont blacklist, and we just set the APIC IP even though we dont write to it
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("forge"=>"allow", "dn"=>"org-root/nwctrl-ACI-LLDP/mac-sec"));    // set this on the re-writing
        //UCSM - if the best effort class is changed in MTU, we reset for this case
        $flowmap["$ucsip<A>qosclassEthBE<B>mtu<C>$apicip<D>fabric/lan/classes/class-best-effort"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"qosclassEthBE",
                                                                            "SOURCE_ATTRIBUTES"=>array("mtu"=>""),
                                                                            "SOURCE_SCOPE"=>"fabric/lan/classes/class-best-effort", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("mtu"=>"9000", "dn"=>"fabric/lan/classes/class-best-effort"));
        //UCSM - epqosDefinition 
        $flowmap["$ucsip<A>epqosDefinition<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"epqosDefinition",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"ACIleafHV"),
                                                                            "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("name"=>"ACIleafHV", "dn"=>"org-root/ep-qos-ACIleafHV", "descr"=>"Auto created by B2G process"));
        //UCSM - if the ACI Leaf hypervisor QoS definition had its priority or host control changed, then we reset them
        $flowmap["$ucsip<A>epqosEgress<B>hostControl<C>$apicip<D>org-root/ep-qos-ACIleafHV/egress"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"epqosEgress",
                                                                            "SOURCE_ATTRIBUTES"=>array("hostControl"=>"", "prio"=>""),
                                                                            "SOURCE_SCOPE"=>"org-root/ep-qos-ACIleafHV/egress", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("hostControl"=>"full", "dn"=>"org-root/ep-qos-ACIleafHV/egress", "prio"=>"best-effort"));
        //UCSM - vnicVmqConPolicy 
        $flowmap["$ucsip<A>vnicVmqConPolicy<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicVmqConPolicy",
                                                                            "SOURCE_ATTRIBUTES"=>array("name"=>"ACIleafVMQ"),
                                                                            "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array("name"=>"ACIleafVMQ", "dn"=>"org-root/vmq-con-ACIleafVMQ", "descr"=>"Auto created by B2G process"));
        //vnicVmqConPolicyRef
        $flowmap["$ucsip<A>vnicVmqConPolicyRef<B>dn<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicVmqConPolicyRef",
                                                                            "SOURCE_ATTRIBUTES"=>array("dn"=>"", "conPolicyName"=>""),
                                                                            "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // Subscribe to UCS VMM host vNIC template events from UCSM, to correlate and re-add any needed, missing, admin removed, etc. VLANs within the vNIC template - but only for those domains that need these EPG networks
        // One important add here, when we get LLDP on VIF's in UCSM 2.2(4) then we will want to create a nwCtrlPolicy for LLDP on, and set.  This is an updating template as we can later publish other EPG's to the VMM domain, and those are auto-added.
        $flowmap["$ucsip<A>vnicLanConnTempl<B>name<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM-BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                                  "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicLanConnTempl",
                                                                                  "SOURCE_ATTRIBUTES"=>array("name"=>"", "qosPolicyName"=>"", "nwCtrlPolicyName"=>"", "switchId"=>"", "templType"=>"", "mtu"=>""),
                                                                                  "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                                  "DEST_CLASSES"=>array(),
                                                                                  "DEST_ATTRIBUTES"=>array(),
                                                                                  "DEST_VMM_DEFAULT"=>array("identPoolName"=>"default", "mtu"=>"9000", "nwCtrlPolicyName"=>"ACI-LLDP", "pinToGroupName"=>"",
                                                                                                          "qosPolicyName"=>"ACIleafHV", "statsPolicyName"=>"default", "target"=>"adaptor", "templType"=>"updating-template"),
                                                                                  "DEST_BM_DEFAULT"=>array("identPoolName"=>"default", "target"=>"adaptor", "templType"=>"updating-template"));
        // Subscribe to UCS service profile vNIC events from UCSM, to correlate and re-add any needed, missing, admin removed, etc. VLANs within the vNIC - but only for those domains that need these EPG networks
        $flowmap["$ucsip<A>vnicEtherIf<B>defaultNet<C>$apicip<D>org-root"]=array("CONSTRUCTOR"=>"VMM-BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                                             "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"vnicEtherIf",
                                                                             "SOURCE_ATTRIBUTES"=>array("defaultNet"=>""),
                                                                             "SOURCE_SCOPE"=>"org-root", "DEST_SYSTEM"=>$apicip,
                                                                             "DEST_CLASSES"=>array(),
                                                                             "DEST_ATTRIBUTES"=>array(),
                                                                             "DEST_VMM_DEFAULT"=>array(),
                                                                             "DEST_BM_DEFAULT"=>array("defaultNet"=>"yes"));
        // Subscribe to SPAN session monitor instances of UCS FI-A    
        $flowmap["$ucsip<A>fabricEthMon<B>name<C>$apicip<D>fabric/lanmon/A"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                   "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthMon",
                                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>"", "id"=>""),
                                                                                   "SOURCE_SCOPE"=>"fabric/lanmon/A", "DEST_SYSTEM"=>$apicip,
                                                                                   "DEST_CLASSES"=>array("spanSrcGrp"=>"uni/infra"),
                                                                                   "DEST_ATTRIBUTES"=>array("name"=>"", "type"=>"", "transport"=>""));
        // Subscribe to SPAN session monitor instances of UCS FI-B    
        $flowmap["$ucsip<A>fabricEthMon<B>name<C>$apicip<D>fabric/lanmon/B"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                   "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthMon",
                                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>"", "id"=>""),
                                                                                   "SOURCE_SCOPE"=>"fabric/lanmon/B", "DEST_SYSTEM"=>$apicip,
                                                                                   "DEST_CLASSES"=>array("spanSrcGrp"=>"uni/infra"),
                                                                                   "DEST_ATTRIBUTES"=>array("name"=>"", "type"=>"", "transport"=>""));
        // Subscribe to SPAN destination fabric A port events on the UCSM
        $flowmap["$ucsip<A>fabricEthMonDestEp<B>switchID<C>$apicip<D>fabric/lanmon/A"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"fabricEthMon",
                                                                                             "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthMonDestEp",
                                                                                             "SOURCE_ATTRIBUTES"=>array("switchId"=>"", "portId"=>"","slotId"=>""),
                                                                                             "SOURCE_SCOPE"=>"fabric/lanmon/A", "DEST_SYSTEM"=>$apicip,
                                                                                             "DEST_CLASSES"=>array("spanSrc"=>"uni/infra", "spanRsSrcToPathEp"=>"uni/infra"),
                                                                                             "DEST_ATTRIBUTES"=>array());
        // Establish a flowmap that identifies we need to be on point to get the SPAN destination fabric B port events on the UCSM
        $flowmap["$ucsip<A>fabricEthMonDestEp<B>switchID<C>$apicip<D>fabric/lanmon/B"]=array("CONSTRUCTOR"=>"SPAN", "KEEP_SYNC"=>"TRUE", "PARENT"=>"fabricEthMon",
                                                                                        "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthMonDestEp",
                                                                                        "SOURCE_ATTRIBUTES"=>array("switchId"=>"", "portId"=>"", "slotId"=>""),
                                                                                        "SOURCE_SCOPE"=>"fabric/lanmon/B", "DEST_SYSTEM"=>$apicip,
                                                                                        "DEST_CLASSES"=>array("spanSrc"=>"uni/infra","spanRsSrcToPathEp"=>"uni/infra"),
                                                                                        "DEST_ATTRIBUTES"=>array());
        // A fabric PC port membership events (when things happen to the underlying ports of the PC on the UCS)
        $dynamicflowmap["fabricEthLanPcEp<A>portIdA"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthLanPcEp",
                                                            "SOURCE_ATTRIBUTES"=>array("portId"=>"^([0-9]|[1-4][0-9])$", "slotId"=>"^([0-3])$", "switchId"=>"", "dn"=>""),
                                                            "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"fabric/lan/A/pc-#lbl:fabricEthLanPc->portId#",
                                                            "DEST_CLASSES"=>array(),
                                                            "DEST_ATTRIBUTES"=>array());
        // B fabric PC port membership events (when things happen to the underlying ports of the PC on the UCS)
        $dynamicflowmap["fabricEthLanPcEp<A>portIdB"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                            "SOURCE_SYSTEM"=>$ucsip, "SOURCE_CLASS"=>"fabricEthLanPcEp",
                                                            "SOURCE_ATTRIBUTES"=>array("portId"=>"^([0-9]|[1-4][0-9])$", "slotId"=>"^([0-3])$", "switchId"=>"", "dn"=>""),
                                                            "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"fabric/lan/B/pc-#lbl:fabricEthLanPc->portId#",
                                                            "DEST_CLASSES"=>array(),
                                                            "DEST_ATTRIBUTES"=>array());
        // APIC Flowmaps:
        // Subscribe to explicit port grouping events from APIC
        $flowmap["$apicip<A>fabricExplicitGEp<B>dn<C>$ucsip<D>uni/fabric/protpol"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fabricExplicitGEp",
                                                                                         "SOURCE_ATTRIBUTES"=>array("dn"=>"", "id"=>"", "name"=>""),
                                                                                         "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>$ucsip,
                                                                                         "DEST_CLASSES"=>array(),
                                                                                         "DEST_ATTRIBUTES"=>array());
        // Subscribe to physical node events from APIC
        $flowmap["$apicip<A>infraNodeP<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraNodeP",
                                                                         "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                         "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                         "DEST_CLASSES"=>array(),
                                                                         "DEST_ATTRIBUTES"=>array());
        // Subscribe to physical access port events from APIC
        $flowmap["$apicip<A>infraAccPortP<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC-BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                            "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraAccPortP",
                                                                            "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                            "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                            "DEST_CLASSES"=>array(),
                                                                            "DEST_ATTRIBUTES"=>array());
        // Subscribe to link aggregation policy events from APIC
        $flowmap["$apicip<A>lacpLagPol<B>mode<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"lacpLagPol",
                                                                         "SOURCE_ATTRIBUTES"=>array("mode"=>""),
                                                                         "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                         "DEST_CLASSES"=>array(),
                                                                         "DEST_ATTRIBUTES"=>array());
        // Subscribe to port access bundle events from APIC
        $flowmap["$apicip<A>infraAccBndlGrp<B>dn<C>$ucsip<D>uni/infra/funcprof"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                                       "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraAccBndlGrp",
                                                                                       "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                                       "SOURCE_SCOPE"=>"uni/infra/funcprof", "DEST_SYSTEM"=>$ucsip,
                                                                                       "DEST_CLASSES"=>array(),
                                                                                       "DEST_ATTRIBUTES"=>array());
        // Subscribe to infrastructure leaf events from APIC
        $flowmap["$apicip<A>infraLeafS<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC-BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                         "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraLeafS",
                                                                         "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                                         "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                         "DEST_CLASSES"=>array(),
                                                                         "DEST_ATTRIBUTES"=>array());
        // Subscribe to infrastructure node bulk events
        $flowmap["$apicip<A>infraNodeBlk<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC-BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                           "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraNodeBlk",
                                                                           "SOURCE_ATTRIBUTES"=>array("dn"=>"", "from_"=>""),
                                                                           "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                           "DEST_CLASSES"=>array(),
                                                                           "DEST_ATTRIBUTES"=>array());
        // Subscribe to AEP events
        $flowmap["$apicip<A>infraAttEntityP<B>dn<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                           "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraAttEntityP",
                                                                           "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                                           "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                           "DEST_CLASSES"=>array(),
                                                                           "DEST_ATTRIBUTES"=>array());
        // Subscribe to DHCP provider
        $flowmap["$apicip<A>dhcpRelayP<B>dn<C>$ucsip<D>uni/tn-infra"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                           "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"dhcpRelayP",
                                                                           "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                                           "SOURCE_SCOPE"=>"uni/tn-infra", "DEST_SYSTEM"=>$ucsip,
                                                                           "DEST_CLASSES"=>array(),
                                                                           "DEST_ATTRIBUTES"=>array());
        // Subscribe to DHCP label in infra default BD
        $flowmap["$apicip<A>dhcpLabel<B>dn<C>$ucsip<D>uni/tn-infra/BD-default"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                           "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"dhcpLbl",
                                                                           "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                                           "SOURCE_SCOPE"=>"uni/tn-infra/BD-default", "DEST_SYSTEM"=>$ucsip,
                                                                           "DEST_CLASSES"=>array(),
                                                                           "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fabricNodePEp<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                    "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fabricNodePEp",
                                                    "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                    "BASTARD"=>"FALSE","SOURCE_SCOPE"=>"uni/fabric/protpol/expgep-#lbl:fabricExplicitGEp->name#",
                                                    "DEST_CLASSES"=>array(),
                                                    "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraHPortS<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                  "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraHPortS",
                                                  "SOURCE_ATTRIBUTES"=>array("dn"=>"", "name"=>""),
                                                  "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/accportprof-#lbl:infraAccPortP->name#",
                                                  "DEST_CLASSES"=>array(),
                                                  "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraPortBlk<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraPortBlk",
                                                   "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                   "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/accportprof-#lbl:infraAccPortP->name#/hports-#lbl:infraHPortS->name#",
                                                   "DEST_CLASSES"=>array(),
                                                   "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraRsAccBaseGrp<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                        "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraRsAccBaseGrp",
                                                        "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                        "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/accportprof-#lbl:infraAccPortP->name#/rsaccBaseGrp",
                                                        "DEST_CLASSES"=>array(),
                                                        "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraRsLacpPol<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                     "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraRsLacpPol",
                                                     "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                     "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/funcprof/accbundle-#lbl:infraAccBndlGrp->name#",
                                                     "DEST_CLASSES"=>array(),
                                                     "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["infraRsAccPortP<A>dn"]=array("CONSTRUCTOR"=>"VPC", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                      "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"infraRsAccPortP",
                                                      "SOURCE_ATTRIBUTES"=>array("dn"=>""),
                                                      "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/infra/nprof-#lbl:infraNodeP->name#/rsaccPortP-[uni/infra/accportprof-#lbl:infraAccPortP->name#]",
                                                      "DEST_CLASSES"=>array(),
                                                      "DEST_ATTRIBUTES"=>array());
        // Subscribe to APIC VXLAN virtualwire events
        $flowmap["$apicip<A>hvsExtPol<B>startEncap<C>$ucsip<D>comp/prov-VMware"]=array("CONSTRUCTOR" => "VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                              "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"hvsExtPol",
                                                                              "SOURCE_ATTRIBUTES"=>array("startEncap"=>"vxlan-.*"),
                                                                              "SOURCE_SCOPE"=>"comp/prov-VMware", "DEST_SYSTEM"=>$ucsip,
                                                                              "DEST_CLASSES"=>array(),
                                                                              "DEST_ATTRIBUTES"=>array());
        // Subscribe to virtual machine manager domain events from APIC
        $flowmap["$apicip<A>vmmDomP<B>name<C>$ucsip<D>uni/vmmp-VMware"]=array("CONSTRUCTOR" => "VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                              "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"vmmDomP",
                                                                              "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                              "SOURCE_SCOPE"=>"uni/vmmp-VMware", "DEST_SYSTEM"=>$ucsip,
                                                                              "DEST_CLASSES"=>array(),
                                                                              "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["vmmCtrlrP<A>name"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                  "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"vmmCtrlrP",
                                                  "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                  "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/vmmp-VMware/dom-#lbl:vmmDomP->name#",
                                                  "DEST_CLASSES"=>array(),
                                                  "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["vmmEpPD<A>encap"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                 "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"vmmEpPD",
                                                 //"SOURCE_ATTRIBUTES"=>array("encap"=>"unknown|vlan-.*", "lbAlgo"=>""),
                                                 "SOURCE_ATTRIBUTES"=>array("encap"=>"^(unknown|vlan\-.*)$"),
                                                 "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/vmmp-VMware/dom-#lbl:vmmDomP->name#",
                                                 "DEST_CLASSES"=>array(),
                                                 "DEST_ATTRIBUTES"=>array(),
                                                 "DEST_DEFAULT"=>array("mcastPolicyName"=>"", "sharing"=>"none"));
        $dynamicflowmap["compHv<A>guid"]=array("CONSTRUCTOR"=>"VMM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                               "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"compHv",
                                               "SOURCE_ATTRIBUTES"=>array("guid"=>""),
                                               "BASTARD"=>"TRUE", "SOURCE_SCOPE"=>"comp/prov-VMware/ctrlr-[#lbl:vmmDomP->name#]-#lbl:vmmCtrlrP->name#",
                                               "DEST_CLASSES"=>array(),
                                               "DEST_ATTRIBUTES"=>array());
        // Subscribe to physical domain events from APIC
        $flowmap["$apicip<A>physDomP<B>name<C>$ucsip<D>uni"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"physDomP",
                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                   "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>$ucsip,
                                                                   "DEST_CLASSES"=>array(),
                                                                   "DEST_ATTRIBUTES"=>array());
        // Subscribe to VLAN pool events from APIC
        $flowmap["$apicip<A>fvnsVlanInstP<B>name<C>$ucsip<D>uni/infra"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                              "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvnsVlanInstP",
                                                                              "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                              "SOURCE_SCOPE"=>"uni/infra", "DEST_SYSTEM"=>$ucsip,
                                                                              "DEST_CLASSES"=>array(),
                                                                              "DEST_ATTRIBUTES"=>array());
        // Subscribe to APIC tenant information to keep in sync when needed in later steps within dynamic flowmaps.
        $flowmap["$apicip<A>fvTenant<B>name<C>$ucsip<D>uni"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT>",
                                                                   "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvTenant",
                                                                   "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                                                   "SOURCE_SCOPE"=>"uni", "DEST_SYSTEM"=>$ucsip,
                                                                   "DEST_CLASSES"=>array(),
                                                                   "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvAp<A>name"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                             "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvAp",
                                             "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                             "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"uni/tn-#lbl:fvTenant->name#",
                                             "DEST_CLASSES"=>array(),
                                             "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvAEPg<A>name"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                               "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvAEPg",
                                               "SOURCE_ATTRIBUTES"=>array("name"=>""),
                                               "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"ap-#lbl:fvAp->name#",
                                               "DEST_CLASSES"=>array(),
                                               "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvRsDomAtt<A>tDn"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                  "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvRsDomAtt",
                                                  "SOURCE_ATTRIBUTES"=>array("tDn"=>"", "dn"=>""),
                                                  "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"epg-#lbl:fvAEPg->name#",
                                                  "DEST_CLASSES"=>array(),
                                                  "DEST_ATTRIBUTES"=>array());
        $dynamicflowmap["fvRsPathAtt<A>encap"]=array("CONSTRUCTOR"=>"BM", "KEEP_SYNC"=>"TRUE", "PARENT"=>"<ROOT_PEER>",
                                                     "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS"=>"fvRsPathAtt",
                                                     "SOURCE_ATTRIBUTES"=>array("encap"=>"", "tDn"=>"", "dn"=>""),
                                                     "BASTARD"=>"FALSE", "SOURCE_SCOPE"=>"epg-#lbl:fvAEPg->name#",
                                                     "DEST_CLASSES"=>array(),
                                                     "DEST_ATTRIBUTES"=>array());
        break;
    default:
        echo "No Cases Selected, Ending.\n";
        exit;
}

//
// End of the flow maps
//

echo "\n*********************\nB2G Process Starting UserStory: ".$userstory."\nTimestamp: (".date("Y-m-d H:i:s").")\n*********************\n\n"; 

$storage = new storage();
$APICeventids = new storage();
$UCSMeventids = new storage();
$ucsstack = new storage();
$apicstack = new storage();
$rackstack = new storage();
$apiextensions = new storage();
$nodelist = new storage();

$storage['userstory'] = $userstory;
$storage['physDomainStartupDelay'] = $physDomainStartupDelay;
$storage['ucsmVXLANtransportVLAN'] = $ucsmVXLANtransportVLAN;
$storage['ucsmVLANpoolmin'] = $ucsmVLANpoolmin;
$storage['ucsmVLANpoolmax'] = $ucsmVLANpoolmax;
$storage['cimcVLANpoolmin'] = $cimcVLANpoolmin;
$storage['cimcVLANpoolmax'] = $cimcVLANpoolmax;
$storage['ucsDomainsAEP'] = $ucsDomainsAEP;
$storage['ucsvSwitchPol'] = $ucsvSwitchPol;
$storage['myOwnIP'] = file_get_contents("../../B2G_VM_Config/.configured-ip");
$storage['runningInteractive'] = $runInteractive;
$storage['realEnvironment'] = $realEnvironment;
$storage['simFIAPortSeed'] = $simFIAPortSeed;
$storage['simFIBPortSeed'] = $simFIBPortSeed;
$storage['simLeafSpanPort'] = $simLeafSpanPort;
$storage['simVPCkey'] = $simVPCkey;
$storage['simSPANkey'] = $simSPANkey;
$storage['priorIndex'] = array();
$storage['logGeneraloperations'] = $logGeneraloperations;
$storage['logBareMetaloperations'] = $logBareMetaloperations;
$storage['logVMMoperations'] = $logVMMoperations;
$storage['logVPCoperations'] = $logVPCoperations;
$storage['logSPANoperations'] = $logSPANoperations;
$storage['logRackoperations'] = $logRackoperations;
$storage['logUCSMevents'] = $logUCSMevents;
$storage['logDoerCalls'] = $logDoerCalls;

echo date("Y-m-d H:i:s")." -> Found my IP as: {$storage['myOwnIP']}";

if ($userstory != 5) {
    echo date("Y-m-d H:i:s")." -> Starting new UCSM I/O thread to:  ".$ucsip." with user=".$ucsuser."\n";
    //$ucs_session = new ucs_manager("http",$ucsip, $ucsuser, $ucspwd);
    // 7-5-15 Switch to https
    $ucs_session = new ucs_manager("https",$ucsip, $ucsuser, $ucspwd);
}
echo date("Y-m-d H:i:s")." -> Starting new APIC I/O thread to:  ".$apicip." with user=".$apicuser."\n";
//$apic_session = new apic("http",$apicip, $apicuser, $apicpwd);
// 7-5-15 Switch to https
$apic_session = new apic("https",$apicip, $apicuser, $apicpwd);
$debugger = new debugger("debug.out");    

$apicstack['EVENT_ACTIVE']=false;
// For userstory 5, we never subscribe to the UCSM, so the event active is never set.  Most use cases want it so we just
// set it to true here for simplicity
if ($userstory != 5) {
    $ucsstack['EVENT_ACTIVE']=false;
} else {
    $ucsstack['EVENT_ACTIVE']=true;
}
    
$ucsstack['MANAGE_DJL2'] = $manageMyDJL2;
$ucsstack['UCS_MONITOR_SUBSCRIPTION'] = false;
$ucsstack['UCS_MONITOR_UPDATE'] = false;
$ucsstack['subscriptionCounter']=0;
$apicstack['APIC_IP'] = $apicip;
$apicstack['ACI_MONITOR_SUBSCRIPTION'] = false;
$apicstack['ACI_MONITOR_UPDATE'] = false;
$apicstack['manageAPICVTEP'] = $manageAPICVTEP;
$apicstack['manageVMMUUflooding'] = $manageVMMUUflooding;
$apicstack['subscriptionCounter']=0;
$apicstack['vmmDomCounter']=0;

// Now we are creating the map of things we need in memory, with the items as:
// $ucs_session:        This is the details about the UCS session within the current thread
// $apic_session:       This is the details about the APIC session within the current thread
// $rack_session:       This is the details about the CIMC XML session within the current thread
// $storage:            This is an object of memory where we insert and retrieve miscelaneous things
// $APICeventids:       This is an object in memory with the class and context for our APIC subscriptions
// $UCSMeventids:       This is an object in memory with the class and context for our UCSM filtered item on the subscription
// $attributemap:
// $flowmap:            This is an object of memory with all the mappings of what flows to implement between these systems
// $ucsstack:           This is the object for all things UCS related
// $apicstack:          This is the object for all things APIC related
// $rackstack:          This is the object for all things related to all CIMC servers indexed by the rack domain name (multi domains)
// $apiextensions:      
// $nodelist:           This is a list in memory of the ACI leaf node data
// $dynamicflowmap:     This is an object of memory with mappings of what flows to implement, based on existence of user created objects - to subscribe to those new classes
// $tmpflowmap:
// $apiccallqueue:      This is the queue of calls we have made to have the APIC thread take actions
// $ucscallqueue:       This is the queue of calls we have made to have the UCSM thread take actions
// $keyvaluepair:       This is scratchpad storage that is persistent around the learned dynamic instances we create flowmaps from dynamic flowmaps
// $soakqueue:          This is a queue of items we soak before deleting - the notifications are stored here as the timer decrements
// $junkfilter:         This is the list of UCSM subscription items that we virutally suscribe to (really filter out all the unwanted items but these)
// $rackservers:
// $serverlist:
// $rackcommand:        This is an object of memory with a queue of commands to issue to a rack server in the right thread
// $storageindex_class: This is a smaller storage array, with just the matching class key for the larger storage[key] itmes that have the <=>CLASS to find the key
// $debugger:           This is the object for the debug capabilities that run in a debug thread
// This function is in the common class, to setup all the underlying baseline memory items

$mymap= new the_map($ucs_session, $apic_session, $rack_session, $storage, $APICeventids, $UCSMeventids, $attributemap, $flowmap, $ucsstack, $apicstack, $rackstack,
                    $apiextensions, $nodelist, $dynamicflowmap, $tmpflowmap, $apiccallqueue, $ucscallqueue, $keyvaluepair,
                    $soakqueue, $junkfilter, $rackservers, $serverlist, $rackcommand, $storageindex_class, $debugger);
 
// User story 5 involves logging into the C servers
if ($userstory == 5) {
    foreach($serverlist as $key=>$value) {
        //$rack_session[] = new rack_command($mymap, "http", $serverlist[$key]['ip'], $serverlist[$key]['username'], $serverlist[$key]['password']);
        // 7-5-15 Switch to https
        $rack_session[] = new rack_command($mymap, "https", $serverlist[$key]['ip'], $serverlist[$key]['username'], $serverlist[$key]['password']);
    }
    foreach($rack_session as $key=>$value) {
        $rack_session[$key]->start();
    }
    // Each session to a CIMC device will start in its own thread
    echo date("Y-m-d H:i:s")." -> Waiting for C-Series threads to complete logins...\n";
    while(count($rackservers) < count($serverlist)){
        sleep(1);
    }
}

// All user stories except 5 log into the UCSM domain
if ($userstory !=5) {
    echo date("Y-m-d H:i:s")." -> Login to UCS in new thread\n";
    $return = $ucs_session->ucs_aaaLogin($mymap);
    if($return == false) {
        echo date("Y-m-d H:i:s")." -> UCS Login Failed\n";
        exit;
    } else {
        echo date("Y-m-d H:i:s")." -> Success, now starting UCS Event Subscription\n";    
        $ucs_events = new ucsm_eventsubscription($mymap);
        $ucs_events->start();
        echo date("Y-m-d H:i:s")." -> UCS Event Subscription startup done, starting UCS Updater\n"; 
        $ucs_update = new ucs_updater($mymap);
        $ucs_update->start();
        echo date("Y-m-d H:i:s")." -> UCS Updater startup done\n";
    }
}
    
echo date("Y-m-d H:i:s")." -> Login APIC in new thread\n";
$return = $apic_session->apic_aaaLogin($mymap);
if($return == false) {
    echo date("Y-m-d H:i:s")." -> APIC Login Failed\n";
    exit;
} else {
    echo date("Y-m-d H:i:s")." -> APIC Login Success, now starting APIC Event Subscription\n";
    $refresh = new apic_event_refresh($mymap);
    $refresh->start();    
    $apic_events = new apic_eventsubscription($mymap);
    $apic_events->start();
    echo date("Y-m-d H:i:s")." -> APIC Even Subscription startup done, starting APIC Updater\n"; 
    $apic_update = new apic_updater($mymap);
    $apic_update->start();
    echo date("Y-m-d H:i:s")." -> APIC Updater startup done.\n";
}

$soaker = new soaker($mymap);
$soaker->start();
    
// To get this far, our APIC Connection is up and logged in

// Get the array of ACI leaf nodes and extend flowmap
$return = $apic_session->apic_get($mymap, 'node/class/fabricNode.json?query-target-filter=and(eq(fabricNode.role,"leaf"))');
foreach($return['imdata'] as $key=>$value) {
    $dn=$return['imdata'][$key]['fabricNode']['attributes']['dn'];
    $name=$return['imdata'][$key]['fabricNode']['attributes']['name'];
    $id=$return['imdata'][$key]['fabricNode']['attributes']['id'];
    $serial=$return['imdata'][$key]['fabricNode']['attributes']['serial'];
    $nodelist[$name.'<=>NAME']=$id;
    $nodelist[$id.'<=>ID']=$name;   
    $nodelist[$name.'('.$serial.')<=>DEVICEID']=$id;
    switch ($userstory) {
        case 0:  //  VPC case needs LLDP
            echo date("Y-m-d H:i:s")." -> Extending Flowmap with: lldpAdjEp: {$dn}\n";
            $flowmap["$apicip<A>lldpAdjEp<B>devId<C>$ucsip<D>".$dn]=array("CONSTRUCTOR" => "VPC", "KEEP_SYNC" => "TRUE", "PEER"=>array(), "PARENT"=>"<ROOT_PEER>",
                                                                          "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS" => "lldpAdjEp", "SOURCE_ATTRIBUTES" => array("portDesc"=>"","sysName"=>"(.*?)-(A|B)$","mgmtIp"=>"","chassisIdV"=>"", "portIdV"=>""),
                                                                          "SOURCE_SCOPE" => $dn, "DEST_SYSTEM"=>$ucsip, "DEST_CLASSES" => array(), "DEST_ATTRIBUTES" => array());
            break;
        case 1:  //  VMM case needs LLDP for the DJL2 part
        case 2:  //  VMM with VXLAN case
            echo date("Y-m-d H:i:s")." -> Extending Flowmap with: lldpAdjEp: {$dn}\n";
            $flowmap["$apicip<A>lldpAdjEp<B>devId<C>$ucsip<D>".$dn]=array("CONSTRUCTOR" => "VMM", "KEEP_SYNC" => "TRUE", "PEER"=>array(), "PARENT"=>"<ROOT_PEER>",
                                                                          "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS" => "lldpAdjEp", "SOURCE_ATTRIBUTES" => array("portDesc"=>"","sysName"=>"(.*?)-(A|B)$","mgmtIp"=>"","chassisIdV"=>"", "portIdV"=>""),
                                                                          "SOURCE_SCOPE" => $dn, "DEST_SYSTEM"=>$ucsip, "DEST_CLASSES" => array(), "DEST_ATTRIBUTES" => array());
            break;
        case 3:  //  Bare Metal needs LLDP for the DJL2 part
            echo date("Y-m-d H:i:s")." -> Extending Flowmap with: lldpAdjEp: {$dn}\n";
            $flowmap["$apicip<A>lldpAdjEp<B>devId<C>$ucsip<D>".$dn]=array("CONSTRUCTOR" => "BM", "KEEP_SYNC" => "TRUE", "PEER"=>array(), "PARENT"=>"<ROOT_PEER>",
                                                                          "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS" => "lldpAdjEp", "SOURCE_ATTRIBUTES" => array("portDesc"=>"","sysName"=>"(.*?)-(A|B)$","mgmtIp"=>"","chassisIdV"=>"", "portIdV"=>""),
                                                                          "SOURCE_SCOPE" => $dn, "DEST_SYSTEM"=>$ucsip, "DEST_CLASSES" => array(), "DEST_ATTRIBUTES" => array());
            break;
        case 4:  // Here in the SPAN case, UCS Fabric Interconnects (as of 2.2(3)) only send CDP packets when the SPAN dest is configured with no source.  So we use that to build adjacency one time.    
            echo date("Y-m-d H:i:s")." -> Extending Flowmap with: cdpAdjEp: {$dn}\n";
            $flowmap["$apicip<A>cdpAdjEp<B>devId<C>$ucsip<D>".$dn]=array("CONSTRUCTOR" => "SPAN", "KEEP_SYNC" => "TRUE",
                                                                        "PEER"=>array("$ucsip<A>fabricEthMonDestEp<B><C>$apicip<D>fabric/lanmon/A","$ucsip<A>fabricEthMonDestEp<B><C>$apicip<D>fabric/lanmon/B"), "PARENT"=>"<ROOT_PEER>",
                                                                        "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS" => "cdpAdjEp", "SOURCE_ATTRIBUTES" => array("portId"=>"","sysName"=>"","platId"=>"UCS-.*", "devId"=>"") , "SOURCE_SCOPE" => $dn,
                                                                        "DEST_SYSTEM"=>$ucsip, "DEST_CLASSES" => array(), "DEST_ATTRIBUTES" => array());
            break;
        case 5:  // Case 5 is for rack servers, and we look for LLDP events on APIC, we take action on the C server setup at install time only
            echo date("Y-m-d H:i:s")." -> Extending Flowmap with: lldpAdjEp: {$dn}\n";
            $flowmap["$apicip<A>lldpAdjEp<B>devId<C>rackGroups<D>".$dn]=array("CONSTRUCTOR" => "RACK", "KEEP_SYNC" => "TRUE", "PEER"=>array(), "PARENT"=>"<ROOT_PEER>",
                                                                          "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS" => "lldpAdjEp", "SOURCE_ATTRIBUTES" => array("portDesc"=>"","sysName"=>"(.*?)-(A|B)$","mgmtIp"=>"","chassisIdV"=>"", "portIdV"=>""),
                                                                          "SOURCE_SCOPE" => $dn, "DEST_SYSTEM"=>$ucsip, "DEST_CLASSES" => array(), "DEST_ATTRIBUTES" => array());
            break;
        case 10:  //  All Cases
            echo date("Y-m-d H:i:s")." -> Extending Flowmap with: lldpAdjEp: {$dn}\n";
            $flowmap["$apicip<A>lldpAdjEp<B>portDesc<C>$ucsip<D>".$dn]=array("CONSTRUCTOR" => "VPC-BM", "KEEP_SYNC" => "TRUE", "PEER"=>array(), "PARENT"=>"<ROOT_PEER>",
                                                                          "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS" => "lldpAdjEp", "SOURCE_ATTRIBUTES" => array("portDesc"=>"","sysName"=>"(.*?)-(A|B)$","mgmtIp"=>"","chassisIdV"=>"", "portIdV"=>""),
                                                                          "SOURCE_SCOPE" => $dn, "DEST_SYSTEM"=>$ucsip, "DEST_CLASSES" => array(), "DEST_ATTRIBUTES" => array());
            echo date("Y-m-d H:i:s")." -> Extending Flowmap with: cdpAdjEp: {$dn}\n";
            $flowmap["$apicip<A>cdpAdjEp<B>devId<C>$ucsip<D>".$dn]=array("CONSTRUCTOR" => "SPAN", "KEEP_SYNC" => "TRUE",
                                                                        "PEER"=>array("$ucsip<A>fabricEthMonDestEp<B><C>$apicip<D>fabric/lanmon/A","$ucsip<A>fabricEthMonDestEp<B><C>$apicip<D>fabric/lanmon/B"), "PARENT"=>"<ROOT_PEER>",
                                                                        "SOURCE_SYSTEM"=>$apicip, "SOURCE_CLASS" => "cdpAdjEp", "SOURCE_ATTRIBUTES" => array("portId"=>"","sysName"=>"","platId"=>"UCS-.*", "devId"=>"") , "SOURCE_SCOPE" => $dn,
                                                                        "DEST_SYSTEM"=>$ucsip, "DEST_CLASSES" => array(), "DEST_ATTRIBUTES" => array());
            break;
    }
}
 
sleep(5);
//date_default_timezone_set('America/Los_Angeles');     
$text="";
while($runInteractive) {
    $f=popen("read; echo \$REPLY","r");
    $input=fgets($f,100);
    pclose($f);
    //if($runInteractive == true) {
	ob_start();
        if($input ==="\n") {
	    $text="MENU";
	    echo "=======================<<<<<<<<<<<<<< ".$text." ".date("Y-m-d H:i:s")." >>>>>>>>>>>>>>>>>>===========================\n";
	    echo "?  This help menu\n";
            echo "0. storageindex_class\n";
            echo "1. storage\n";
            echo "2. APICeventids - APIC subscriptions\n";
            echo "3. ucsstack\n";
            echo "4. apicstack\n";
	    echo "5. apiextensions\n";
	    echo "6. nodelist\n";
	    echo "7. flowmap\n";
	    echo "8. tmpflowmap\n";
	    echo "9. apiccallqueue\n";
	    echo "a. ucscallqueue\n";	 		    
	    echo "b. bastards\n";
	    echo "c. chill-pill\n";
	    echo "d. Stats\n";
            echo "e. UCSMeventids - UCSM subscriptions\n";
	    echo "j. junkfilter - what types of UCSM objects to catch\n";
	    echo "k. rackservers/rackstack\n";			    
	    echo "m. Start/Stop Monitor all subscriptions and EVENTS\n";
	    echo "q. Start/Stop Monitor subscriptions UCS EVENTS\n";
	    echo "w. Start/Stop Monitor UCS UPDATES\n";
	    echo "n. Start/Stop Monitor subscriptions ACI EVENTS\n";
	    echo "r. Start/Stop Monitor ACI UPDATES\n";
            echo "t. Start/Stop Monitor polling CIMC EVENTS\n";
            echo "y. Start/Stop Monitor CIMC UPDATES\n";
            echo "v. Show the Monitoring States\n";
            echo "x. Logout of UCS and APIC and exit\n";
	} else {
            if($input === "0\n") {
                $text="storageindex_class";
                $myFilter=readline("Enter Text to Include in Filter (enter for all): ");
            }
	    if($input === "1\n") {
	        $text="storage";
                $myFilter=readline("Enter Text to Include in Filter (enter for all): ");
	    }
	    if($input === "2\n") {
	        $text="APICeventids - APIC subscriptions";
	    }
	    if($input === "3\n") {
	        $text="ucsstack";
	    }
	    if($input === "4\n") {
	        $text="apicstack";
	    }
	    if($input === "5\n") {
		$text="apiextensions";
	    }
	    if($input ==="6\n") {
	        $text="nodelist";
	    }
	    if($input === "7\n") {
	        $text="flowmap";
	    }
	    if($input === "8\n") {
	        $text="tmpflowmap";
	    }
	    if($input === "9\n") {
	        $text="apiccallqueue";
	    }
	    if($input === "a\n") {
	        $text="ucscallqueue";
	    }		
	    if($input === "b\n") {
	        $text="bastards";
	    }
	    if($input === "c\n") {
	        $text="soakqueue";
	    }
	    if($input === "d\n") {
	        $text="stats";
	    }		
	    if($input === "e\n") {
	        $text="UCSMeventids - UCSM subscriptions";
	    }
	    if($input === "j\n") {
                $text="junkfilter - UCSM subscriptions";
	    }
	    if($input === "k\n") {
	        $text="rackservsers/rackstack";
	    }		
	    echo "=======================<<<<<<<<<<<<<< ".$text." ".date("Y-m-d H:i:s")." >>>>>>>>>>>>>>>>>>===========================\n";
	    if($input ==="0\n") {
                //var_dump($storageindex_class);
                if (strlen($myFilter) < 1) {
                    var_dump($storageindex_class);
                } else {
                    echo "Storageindex_class dump, filtered by: {$myFilter}:\n";
                    foreach($storageindex_class as $filteredKey => $filteredValue) {
                        $dumpIt=false;
                        if (strstr($filteredKey, $myFilter) != NULL) {
                            $dumpIt=true;
                        }
                        if (is_array($filteredValue)) {
                            if (in_array($myFilter, $filteredValue)) {
                                $dumpIt=true;
                            }
                        }
                        if (is_string($filteredValue)) {
                            if (strstr($filteredValue, $myFilter) != NULL) {
                                $dumpIt=true;
                            }
                        }
                        if ($dumpIt) {
                            echo '["'.$filteredKey.'"]=>';
                            var_dump($storageindex_class[$filteredKey]);                            
                        }
                    }
                }
            }
            if($input ==="1\n") {
                if (strlen($myFilter) < 1) {
                    var_dump($storage);
                } else {
                    echo "Storage dump, filtered by: {$myFilter}:\n";
                    foreach($storage as $filteredKey => $filteredValue) {
                        $dumpIt=false;
                        if (strstr($filteredKey, $myFilter) != NULL) {
                            $dumpIt=true;
                        }
                        if (is_array($filteredValue)) {
                            if (in_array($myFilter, $filteredValue)) {
                                $dumpIt=true;
                            }
                        }
                        if (is_string($filteredValue)) {
                            if (strstr($filteredValue, $myFilter) != NULL) {
                                $dumpIt=true;
                            }
                        }
                        if ($dumpIt) {
                            echo '["'.$filteredKey.'"]=>';
                            var_dump($storage[$filteredKey]);
                        }
                    }
                }
	    }
	    if($input ==="2\n") {
	        var_dump($APICeventids);
	    }
	    if($input ==="3\n") {
	        var_dump($ucsstack);
	    }
	    if($input ==="4\n") {;
	        var_dump($apicstack);
	    }
	    if($input ==="5\n") {
	        var_dump($apiextensions);
	    }
	    if($input ==="6\n") {
	        var_dump($nodelist);
	    }
	    if($input ==="7\n") {
	        var_dump($flowmap); 
	    }
	    if($input ==="8\n") {
	        var_dump($tmpflowmap); 
	    }
	    if($input ==="9\n") {
	        var_dump($apiccallqueue); 
	    }
	    if($input ==="a\n") {
	        var_dump($ucscallqueue); 
	    }
	    if($input ==="b\n") {
	        var_dump($keyvaluepair); 
	    }
	    if($input ==="c\n") {
	        var_dump($soakqueue); 
	    }
	    if($input ==="d\n") {
		$stats=array();
		$total=0;
		foreach($storage as $key=>$value) {
		    if(strpos($key,"<=>CLASS") !== false) {
		        $tag="    ";
		        echo "${key}\n";
		        if(strpos($key,$apicip."<1>") !== false) {
                            $tag=" (A)";
		        }
                        if(strpos($key,$ucsip."<1>") !== false) {
			    $tag=" (U)";
                        }
                        if(isset($stats[$storage[$key].$tag])) {
                            $stats[$storage[$key].$tag]++;
                        } else {
                            $stats[$storage[$key].$tag]=1;
                        }
		    }
		}
		foreach($stats as $key=>$value) {
		    echo str_pad($key, 24 ) . $value . "\n";
		    $total+=$value;
		}
		echo "=========================\n";
		echo str_pad("Total", 24 ) . $total . "\n";
		unset($total);
		unset($stats);
	    }
            if($input ==="e\n") {
	        var_dump($UCSMeventids);
	    }	
	    if($input ==="j\n") {
		var_dump($junkfilter); 
	    }
	    if($input ==="k\n") {
	        var_dump($rackservers);
                echo "<<rack servers, rack stack below>>\n";
                var_dump($rackstack);
	    }			
	    if($input ==="q\n") {
	        $ucsstack['UCS_MONITOR_SUBSCRIPTION']=!$ucsstack['UCS_MONITOR_SUBSCRIPTION'];
	    }		
	    if($input ==="w\n") {
	        $ucsstack['UCS_MONITOR_UPDATE']=!$ucsstack['UCS_MONITOR_UPDATE'];
	    }
	    if($input ==="n\n") {
	        $apicstack['ACI_MONITOR_SUBSCRIPTION']=!$apicstack['ACI_MONITOR_SUBSCRIPTION'];
	    }		
	    if($input ==="r\n") {
	        $apicstack['ACI_MONITOR_UPDATE']=!$apicstack['ACI_MONITOR_UPDATE'];
	    }
	    if($input ==="t\n") {
                foreach($rackstack as $key=>$value) {       // key is the domainnamedn and right now we just toggle all rack groups the same
                    $tmp = $rackstack[$key];
                    $tmp['CIMC_MONITOR_POLLING']=!$tmp['CIMC_MONITOR_POLLING'];
                    $rackstack[$key] = $tmp;
                }
	    }		
	    if($input ==="y\n") {
                foreach($rackstack as $key=>$value) {       // key is the domainnamedn and right now we just toggle all rack groups the same
                    $tmp = $rackstack[$key];
                    $tmp['CIMC_MONITOR_UPDATE']=!$tmp['CIMC_MONITOR_UPDATE'];
                    $rackstack[$key] = $tmp;
                }
	    }
	    if($input ==="m\n") {
	        $ucsstack['UCS_MONITOR_SUBSCRIPTION']=!$ucsstack['UCS_MONITOR_SUBSCRIPTION'];
	        $ucsstack['UCS_MONITOR_UPDATE']=!$ucsstack['UCS_MONITOR_UPDATE'];
	        $apicstack['ACI_MONITOR_SUBSCRIPTION']=!$apicstack['ACI_MONITOR_SUBSCRIPTION'];
	        $apicstack['ACI_MONITOR_UPDATE']=!$apicstack['ACI_MONITOR_UPDATE'];
                foreach($rackstack as $key=>$value) {       // key is the groupname and right now we just toggle all groups
                    $tmp = $rackstack[$key];
                    $tmp['CIMC_MONITOR_POLLING']=!$tmp['CIMC_MONITOR_POLLING'];
                    $tmp['CIMC_MONITOR_UPDATE']=!$tmp['CIMC_MONITOR_UPDATE'];
                    $rackstack[$key] = $tmp;
                }
	    }
            if($input ==="v\n") {
                echo "Monitoring States:\nUCS-Subscriptions: ";
                echo ($ucsstack['UCS_MONITOR_SUBSCRIPTION'])?"Yes\n":"No\n";
                echo "UCS-Updates: ";
                echo ($ucsstack['UCS_MONITOR_UPDATE'])?"Yes\n":"No\n";
                echo "APIC-Subscriptions: ";
                echo ($apicstack['ACI_MONITOR_SUBSCRIPTION'])?"Yes\n":"No\n";
                echo "APIC-Updates: ";
                echo ($apicstack['ACI_MONITOR_UPDATE'])?"Yes\n":"No\n";
                foreach($rackstack as $key=>$value) {       // key is the domainnamedn and right now we just toggle all groups
                    echo "CIMC-Polling [{$key}]: ";
                    echo ($rackstack[$key]['CIMC_MONITOR_POLLING'])?"Yes\n":"No\n";
                    echo "CIMC-Updates [{$key}]: ";
                    echo ($rackstack[$key]['CIMC_MONITOR_UPDATE'])?"Yes\n":"No\n";
                }
            }
            if($input === "x\n") {
                if ($userstory !=5) {
                    echo "UCS Logout...\n";
                    $return = $ucs_session->ucs_aaaLogout($mymap);
                    if($return == false) {
                        echo "Failed\n\n";
                    } else {
                        echo "Success\n\n";
                    }
                }
                echo "APIC Logout...\n";
                $return = $apic_session->apic_aaaLogout($mymap);
                if($return == false) {
                    echo "Failed\n\n";
                } else {
                    echo "Success\n\n";
                }
                // TODO:  Need to end all the threads.
                break;
            }
	    //var_dump($todump);
        }
        $b = ob_get_clean();
	$debugger->dwrite($b);	    
    //}
}
exit;
?>
