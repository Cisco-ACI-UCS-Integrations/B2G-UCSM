<?PHP

//This class is responsible writing to the debug.out file which we open a tail -f of in a separate session to keep an eye on things.
class debugger extends Thread {
    private $fp="";
    private $themap;	
    
    // This builds the debug file sent in when the thread is invoked, and opens for append.
    public function __construct($file) {
	date_default_timezone_set('UTC');	
        $this->fp = fopen($file, "a");
        $data = "\n\n==========<<<<<<<<<<<<<<    ---   DEBUG STARTED   ---    ".date("Y-m-d H:i:s")." >>>>>>>>>>>>>>>>>>==============";
        $this->dwrite($data);
    }
    
    public function dwrite($data) {
        fwrite($this->fp, $data."\n");
    }

    public function dclose() {
	date_default_timezone_set('UTC');
        $data = "\n\n==========<<<<<<<<<<<<<<    ---   DEBUG ENDED   ---    ".date("Y-m-d H:i:s")." >>>>>>>>>>>>>>>>>>==============";
        $this->dwrite($data);	    
        fclose($this->fp);
    }
}

//This class is responsible for soaking the object prior to deletion, if object gets created during soak time, it will be discarded from soak queue.
//If an object gets deleted again during soak period, the soak period restarts. Default soak time is 30 seconds.
//This is most important in the VPC cases, where the LLDP adjacencies can jump around even when policy things are accidentally removed - and this b2g process restores automatically
class soaker extends Thread {
    private $themap;
	
    // This function is the constructor, where we pass the entire memory map pointer structure into the thread
    public function __construct(&$themap, Array $properties=array(array())) {
        $this->themap = $themap;
    }
	
    public function run() {
	date_default_timezone_set('UTC');
        $booker=array();
        $soaktime=30.00; //Default soak time in seconds
        while(true) {
	    foreach($this->themap->soakqueue as $key=>$value) {
		if(strpos($key, '<A>NEW') !== false) {
		    $newkey=substr($key,0,strpos($key,'<A>NEW'));
		    if(isset($booker[$newkey]) === true) {
		        $booker[$newkey] = microtime(true);
		        echo date("Y-m-d H:i:s")." -> SOAKER: ${newkey} is getting a {$soaktime} second refill of chill pill...\n";
		    } else {
		        echo date("Y-m-d H:i:s")." -> SOAKER: ${newkey} is getting a {$soaktime} second chill pill...\n";
			//echo "4-11-15 working - just setting the location key=[{$newkey}], with the contents=[{$this->themap->soakqueue[$key]}]\n";
			$this->themap->soakqueue[$newkey] = $this->themap->soakqueue[$key];
		        $booker[$newkey] = microtime(true);
			unset($this->themap->soakqueue[$key]);
		    }	
	        }elseif(strpos($key, '<A>REMOVE') !== false) {
		    $newkey=substr($key,0,strpos($key,'<A>REMOVE'));
		    //echo "4-11-15 Testing in soakqueue, found deletion: Looking at this key=[{$newkey}], and vardump of value:\n";
		    //var_dump($value);
		    echo date("Y-m-d H:i:s")." -> Soaker discarded {$newkey}\n";
	            unset($booker[$newkey]);
	            unset($this->themap->soakqueue[$newkey]);
		    unset($this->themap->soakqueue[$key]);
		} else {
		    if(microtime(true) >= $booker[$key]  + $soaktime) {
		        echo date("Y-m-d H:i:s")." -> SOAKER: Time in soak queue has run out for key: {$key}, passing the remove_dn event back into handler.\n";
		        $class = substr($this->themap->soakqueue[$key], 0, strpos($this->themap->soakqueue[$key], '<A>'));
		        $dn = substr($this->themap->soakqueue[$key], strpos($this->themap->soakqueue[$key], '<A>') + 3,
		    		 strpos($this->themap->soakqueue[$key], '<B>') - strpos($this->themap->soakqueue[$key], '<A>') - 3);
		        $ip = substr($this->themap->soakqueue[$key], strpos($this->themap->soakqueue[$key], '<B>') + 3);
		        unset($booker[$key]);
		        unset($this->themap->soakqueue[$key]);
		        remove_dn($this->themap, $class, $dn, $ip, true);
	            }
		}
	    }
	    usleep(500);
	}
    }
} 

//This class sets up "The Map", which is the main shared memory for all processes and functions
class the_map {
    var $ucssession;
    var $apicsession;
    var $racksession;
    var $storage;
    var $APICeventids;
    var $UCSMeventids;
    var $attributemap;
    var $flowmap;
    var $dynamicflowmap;
    var $tmpflowmap;
    var $ucsstack;
    var $apicstack;
    var $rackstack;
    var $apiextensions;
    var $nodelist;
    var $apiccallqueue;
    var $ucscallqueue;
    var $keyvaluepair;
    var $junkfilter;
    var $rackservers;
    var $serverlist;
    var $rackcommand;
    var $storageindex_class;
    var $debugger;	
	
    public function __construct(&$ucssession, &$apicsession, &$racksession, &$storage, &$APICeventids, &$UCSMeventids, &$attributemap, &$flowmap, &$ucsstack, &$apicstack,
				&$rackstack, &$apiextensions, &$nodelist, &$dynamicflowmap, &$tmpflowmap, &$apiccallqueue, &$ucscallqueue, &$keyvaluepair, &$soakqueue,
				&$junkfilter, &$rackservers, &$serverlist, &$rackcommand, &$storageindex_class, &$debugger) {
	$this->ucssession = $ucssession;
	$this->apicsession = $apicsession;
        $this->racksession = $racksession;	    
        $this->storage = $storage;
        $this->APICeventids = $APICeventids;    
        $this->UCSMeventids = $UCSMeventids;    
        $this->attributemap = $attributemap;
        $this->flowmap = $flowmap;
        $this->ucsstack = $ucsstack;
        $this->apicstack = $apicstack;
	$this->rackstack = $rackstack;
        $this->apiextensions = $apiextensions;
        $this->nodelist = $nodelist;
        $this->dynamicflowmap = $dynamicflowmap;
        $this->tmpflowmap = $tmpflowmap;
        $this->apiccallqueue = $apiccallqueue;
        $this->ucscallqueue = $ucscallqueue;
        $this->keyvaluepair = $keyvaluepair;
        $this->soakqueue = $soakqueue;
        $this->junkfilter = $junkfilter;
        $this->rackservers = $rackservers;
        $this->serverlist = $serverlist;
        $this->rackcommand = $rackcommand;
        $this->storageindex_class = $storageindex_class;
        $this->debugger = $debugger;
    }
}

//This is for conversion between Unix and Windows UUID format, converts both ways.  We need this as APIC reported "guid's" from vCenter are ordered different from UCSM
function uuidconvert($uuid) {
    $u = str_split($uuid);
    $uW = explode('-', $uuid);
    $result=$u[6].$u[7].$u[4].$u[5].$u[2].$u[3].$u[0].$u[1]."-".$u[11].$u[12].$u[9].$u[10]."-".$u[16].$u[17].$u[14].$u[15]."-".$uW[3]."-".$uW[4];
    return $result;
}

class storage extends Stackable {
    public function run(){}
}

// We create the baseline array for the XML parser.  PHP does not natively have an XML parser, but it does for JSON - so we need these next few functions which are open source    
function xml_decode($xml) {
    return XML2Array::createArray($xml);
}

// This is our mapping for the memory in this program to the open source XML parser
function xml_encode($array) {
    $root_node=array_keys($array)[0];
    $xml = Array2XML::createXML($root_node, $array[$root_node]);
    return $xml->saveXML();
}

/**
* Array2XML: A class to convert array in PHP to XML
* It also takes into account attributes names unlike SimpleXML in PHP
* It returns the XML in form of DOMDocument class for further manipulation.
* It throws exception if the tag name or attribute name has illegal chars.
*
* Author : Lalit Patel
* Website: http://www.lalit.org/lab/convert-php-array-to-xml-with-attributes
* License: Apache License 2.0
*          http://www.apache.org/licenses/LICENSE-2.0
* Version: 0.8 (02 May 2012)
*          - Removed htmlspecialchars() before adding to text node or attributes.
*/
class Array2XML {
    private static $xml = null;
    private static $encoding = 'UTF-8';

    /**
    * Initialize the root XML node [optional]
    * @param $version
    * @param $encoding
    * @param $format_output
    */
    public static function init($version = '1.0', $encoding = 'UTF-8', $format_output = true) {
	self::$xml = new DomDocument($version, $encoding);
	self::$xml->formatOutput = $format_output;
	self::$encoding = $encoding;
    }
    
    /**
    * Convert an Array to XML
    * @param string $node_name - name of the root node to be converted
    * @param array $arr - aray to be converterd
    * @return DomDocument
    */
    public static function &createXML($node_name, $arr=array()) {
	$xml = self::getXMLRoot();    
	$xml->appendChild(self::convert($node_name, $arr));
	self::$xml = null;    // clear the xml node in the class for 2nd time use.
	return $xml;
    }
    
    /**
    * Convert an Array to XML
    * @param string $node_name - name of the root node to be converted
    * @param array $arr - aray to be converterd
    * @return DOMNode
    */
    private static function &convert($node_name, $arr=array()) {
        //print_arr($node_name);
        $xml = self::getXMLRoot();
        $node = $xml->createElement($node_name);
	if(is_array($arr)){
	    // get the attributes first.;
	    if(isset($arr['@attributes'])) {
	        foreach($arr['@attributes'] as $key => $value) {
		    if(!self::isValidTagName($key)) {
			throw new Exception('[Array2XML] Illegal character in attribute name. attribute: '.$key.' in node: '.$node_name);
		    }
		    $node->setAttribute($key, self::bool2str($value));
	        }
	        unset($arr['@attributes']); //remove the key from the array once done.
	    }
	    // check if it has a value stored in @value, if yes store the value and return
	    // else check if its directly stored as string
	    if(isset($arr['@value'])) {
	        $node->appendChild($xml->createTextNode(self::bool2str($arr['@value'])));
	        unset($arr['@value']);    //remove the key from the array once done.
	        //return from recursion, as a note with value cannot have child nodes.
	        return $node;
	    } else if(isset($arr['@cdata'])) {
	        $node->appendChild($xml->createCDATASection(self::bool2str($arr['@cdata'])));
	        unset($arr['@cdata']);    //remove the key from the array once done.
	        //return from recursion, as a note with cdata cannot have child nodes.
	        return $node;
	    }
        }
	//create subnodes using recursion
	if(is_array($arr)){
	    // recurse to get the node for that key
	    foreach($arr as $key=>$value){
	        if(!self::isValidTagName($key)) {
		    throw new Exception('[Array2XML] Illegal character in tag name. tag: '.$key.' in node: '.$node_name);
	        }
	        if(is_array($value) && is_numeric(key($value))) {
		    // MORE THAN ONE NODE OF ITS KIND;
		    // if the new array is numeric index, means it is array of nodes of the same kind
		    // it should follow the parent key name
		    foreach($value as $k=>$v){
		        $node->appendChild(self::convert($key, $v));
		    }
	        } else {
		    // ONLY ONE NODE OF ITS KIND
		    $node->appendChild(self::convert($key, $value));
		}
	        unset($arr[$key]); //remove the key from the array once done.
	    }
        }
	// after we are done with all the keys in the array (if it is one)
        // we check if it has any text value, if yes, append it.
        if(!is_array($arr)) {
	    $node->appendChild($xml->createTextNode(self::bool2str($arr)));
        }
        return $node;
    }
    
    /*
     * Get the root XML node, if there isn't one, create it.
    */
    private static function getXMLRoot(){
        if(empty(self::$xml)) {
	    self::init();
        }
        return self::$xml;
    }
    
    /*
    * Get string representation of boolean value
    */
    private static function bool2str($v){
	//convert boolean to text value.
	$v = $v === true ? 'true' : $v;
	$v = $v === false ? 'false' : $v;
	return $v;
    }
    
    /*
    * Check if the tag name or attribute name contains illegal characters
    * Ref: http://www.w3.org/TR/xml/#sec-common-syn
    */
    private static function isValidTagName($tag){
	$pattern = '/^[a-z_]+[a-z0-9\:\-\.\_]*[^:]*$/i';
        return preg_match($pattern, $tag, $matches) && $matches[0] == $tag;
    }
}

/*
* XML2Array: A class to convert XML to array in PHP
* It returns the array which can be converted back to XML using the Array2XML script
* It takes an XML string or a DOMDocument object as an input.
*
* See Array2XML: http://www.lalit.org/lab/convert-php-array-to-xml-with-attributes
*
* Author : Lalit Patel
* Website: http://www.lalit.org/lab/convert-xml-to-array-in-php-xml2array
* License: Apache License 2.0
*          http://www.apache.org/licenses/LICENSE-2.0
* Version: 0.2 (04 Mar 2012)
* 			Fixed typo 'DomDocument' to 'DOMDocument'
*/
class XML2Array {
    private static $xml = null;
    private static $encoding = 'UTF-8';
    
    /**
    * Initialize the root XML node [optional]
    * @param $version
    * @param $encoding
    * @param $format_output
    */
    public static function init($version = '1.0', $encoding = 'UTF-8', $format_output = true) {
        self::$xml = new DOMDocument($version, $encoding);
        self::$xml->formatOutput = $format_output;
        self::$encoding = $encoding;
    }
    
    /**
    * Convert an XML to Array
    * @param string $node_name - name of the root node to be converted
    * @param array $arr - aray to be converterd
    * @return DOMDocument
    */
    public static function &createArray($input_xml) {
	$xml = self::getXMLRoot();
        if(is_string($input_xml)) {
    	    $parsed = $xml->loadXML($input_xml);
    	    if(!$parsed) {
	        throw new Exception('[XML2Array] Error parsing the XML string.');
    	    }
        } else {
    	    if(get_class($input_xml) != 'DOMDocument') {
		// we must have some bad XML in, echo it here
		echo "12-3-12 TESTPOINT!!!!!!!:  Bad XML in? it is: [{$input_xml}]\n";
		throw new Exception('[XML2Array] The input XML object should be of type: DOMDocument.');
	    }
    	    $xml = self::$xml = $input_xml;
        }
        $array[$xml->documentElement->tagName] = self::convert($xml->documentElement);
        self::$xml = null;    // clear the xml node in the class for 2nd time use.
        return $array;
    }
    
    /**
    * Convert an Array to XML
    * @param mixed $node - XML as a string or as an object of DOMDocument
    * @return mixed
    */
    private static function &convert($node) {
        $output = array();
        switch ($node->nodeType) {
    	    case XML_CDATA_SECTION_NODE:
		$output['@cdata'] = trim($node->textContent);
		break;
	    case XML_TEXT_NODE:
	        $output = trim($node->textContent);
	        break;
	    case XML_ELEMENT_NODE:
	        // for each child node, call the covert function recursively
	        for ($i=0, $m=$node->childNodes->length; $i<$m; $i++) {
		    $child = $node->childNodes->item($i);
		    $v = self::convert($child);
		    if(isset($child->tagName)) {
		        $t = $child->tagName;
		        // assume more nodes of same kind are coming
		        if(!isset($output[$t])) {
			    $output[$t] = array();
			}
		        $output[$t][] = $v;
		    } else {
		        //check if it is not an empty text node
		        if($v !== '') {
			    $output = $v;
		        }
		    }
	        }
	        if(is_array($output)) {
		    // if only one node of its kind, assign it directly instead if array($value);
		    foreach ($output as $t => $v) {
		        if(is_array($v) && count($v)==1) {
			    $output[$t] = $v[0];
		        }
		    }
		    if(empty($output)) {
		        //for empty nodes
		        $output = '';
		    }
	        }
	        // loop through the attributes and collect them
	        if($node->attributes->length) {
		    $a = array();
		    foreach($node->attributes as $attrName => $attrNode) {
		        $a[$attrName] = (string) $attrNode->value;
		    }
		    // if its an leaf node, store the value in @value instead of directly storing it.
		    if(!is_array($output)) {
		        $output = array('@value' => $output);
		    }
		    $output['@attributes'] = $a;
	        }
	        break;
	}
	return $output;
    }
    
    /*
    * Get the root XML node, if there isn't one, create it.
    */
    private static function getXMLRoot(){
        if(empty(self::$xml)) {
	    self::init();
        }
        return self::$xml;
    }
}
 
// Now we are back to our custom code, and assigning the timer to wait when removing items 
function givechillpill(&$themap, $class, $dn, $ip) {
    $themap->soakqueue[$dn.'<A>NEW']=$class.'<A>'.$dn.'<B>'.$ip;
}

// Now we see an event, that causes us to remove something from the soakqueue
function nukechillpill(&$themap, $class, $dn, $ip) {
    $themap->soakqueue[$dn.'<A>REMOVE']=$class.'<A>'.$dn.'<B>'.$ip;
}

// With the UCS event subscription we cannot do a granular filtering of the types of events to gather.  The need here is to make a filter that can weed
// out all the extraneous events and data.  The item is junkfilter - which is really a list of interesting classes to keep, and discard all other items.
// This filter is created from the flowmap and dynamic flowmap entries.  This is called one time on startup.
function load_junkfilter(&$themap) {
    foreach($themap->flowmap as $key => $value) {
        if($themap->flowmap[$key]["SOURCE_SYSTEM"] === $themap->ucssession->ip) {
	    $themap->junkfilter[$themap->flowmap[$key]['SOURCE_CLASS']]=false;
        }
    }
    foreach($themap->dynamicflowmap as $key => $value) {
        if($themap->dynamicflowmap[$key]["SOURCE_SYSTEM"] === $themap->ucssession->ip) {
	    $themap->junkfilter[$themap->dynamicflowmap[$key]['SOURCE_CLASS']]=false;
        }
    }	
}

// This function reloads the flowmap into memory for the ACI events, and maps the subscriptions to event ID's for later scanning
function reload_flowmap_ACI(&$themap) {
    if($themap->apicstack['EVENT_ACTIVE'] === true) {
        foreach($themap->flowmap as $key => $value) {
	    if(strpos($key,$themap->apicsession->ip.'<A>') > -1) {
		// The APIC is the source of data in this flowmap entry
	        $class = $themap->flowmap[$key]['SOURCE_CLASS'];
	        $scope = "node/mo/".$themap->flowmap[$key]['SOURCE_SCOPE'];
		if(isset($themap->APICeventids[$class.'<->'.$scope]) == false && isSubscriptionInteresting($themap->ucsstack['physdomainname'], $class, $scope)) {
		    // set it here for other threads calling in here to not call if same (the eventID will be updated with the subscription number)
		    $themap->APICeventids[$class.'<->'.$scope] = 1;
		    //echo "_________________________6-22-15 from common.php reload_flowmap_ACI area 1 key:{$key}.\n";
		    $themap->apicsession->apic_subscribe($themap, $scope, $class);
	        }
	        if(isset($themap->flowmap[$key]['DEST_CLASS']) == true && $themap->flowmap[$key]['SOURCE_CLASS'] !== $themap->flowmap[$key]['DEST_CLASS']) {
		    $class = $themap->flowmap[$key]['DEST_CLASS'];
		    $scope = "node/mo/".$themap->flowmap[$key]['DEST_SCOPE'];
		    if(isset($themap->APICeventids[$class.'<->'.$scope]) == false && isSubscriptionInteresting($themap->ucsstack['physdomainname'], $class, $scope)) {
			// set it here for other threads calling in here to not call if same (the eventID will be updated with the subscription number)
			$themap->APICeventids[$class.'<->'.$scope] = 1;
			//echo "_________________________6-22-15 from common.php reload_flowmap_ACI area 2 key:{$key}.\n";
		        $themap->apicsession->apic_subscribe($themap, $scope, $class);
		    }
	        }	    
	    }
	    if(strpos($key,'<C>'.$themap->apicsession->ip) > -1) {
		// The APIC is the destination for data in this flowmap entry
	        if(isset($themap->flowmap[$key]['DEST_CLASSES']) == true ) {
		    foreach($themap->flowmap[$key]['DEST_CLASSES'] as $class => $scope) {
			if(isset($themap->APICeventids[$class.'<->node/mo/'.$scope]) == false && isSubscriptionInteresting($themap->ucsstack['physdomainname'], $class, $scope)) {
			    // set it here for other threads calling in here to not call if same (the eventID will be updated with the subscription number)
			    $themap->APICeventids[$class.'<->node/mo/'.$scope] = 1;
			    //echo "_________________________6-22-15 from common.php reload_flowmap_ACI area 3 key:{$key}.\n";
			    $themap->apicsession->apic_subscribe($themap, "node/mo/".$scope, $class);
			}
		    }
	        }
	    }		
        }
    } else {
	//echo "6-22-15 reload_flowmap_ACI called, but the EVENT_ACTIVE is not yet true.....doing nothing.\n";
    }
}

// This function reloads the flowmap or dynamic additions into memory for the UCS events, and calls the main handler which is the doer function when events are present
// The reason we actually handle this here, is the UCS subscriptions are not granular, so we junkfilter out unneeded things by type, and also blacklist the
// right types of things but are not needed.  All that processing is done, so we call the handler next.
function reload_flowmap_UCS(&$themap) {
    date_default_timezone_set('UTC');
    //$thisThread=rand(1,1000);
    //$myLogMsg="********6-22-15************ Userstory={$themap->storage['userstory']}: Time: ".date("Y-m-d H:i:s").": Came into reload_flowmap_UCS instance #{$thisThread}\n";
    //if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
    $dn=array();
    if($themap->ucsstack['EVENT_ACTIVE'] === true) {
	foreach($themap->flowmap as $key => $value) {
	    foreach ($dn as $nukeIndex => $nukeValue) {	// clear out the dn array to start
		unset($dn[$nukeIndex]);
	    }
	    if(strpos($key,$themap->ucssession->ip.'<A>') > -1) {
		$class=$themap->flowmap[$key]['SOURCE_CLASS'];
		$themap->junkfilter[$class]=true;		// we signal that one call is now handling this track all together
		$myConstructor=$themap->flowmap[$key]['CONSTRUCTOR'];
		$return = $themap->ucssession->ucs_post($themap, 'configResolveClass','classId="'.$class.'" inHierarchical="false"');
		if(isset($return['configResolveClass']['outConfigs'][$value['SOURCE_CLASS']]['@attributes'])) {			// Single Object Back
		    $dn[0]=$return['configResolveClass']['outConfigs'][$value['SOURCE_CLASS']]['@attributes']['dn'];
		} elseif(isset($return['configResolveClass']['outConfigs'][$value['SOURCE_CLASS']][0]['@attributes'])) {	// Multiple Objects Back
		    foreach($return['configResolveClass']['outConfigs'][$value['SOURCE_CLASS']] as $key2=>$value2) {
			$dn[$key2]=$return['configResolveClass']['outConfigs'][$value['SOURCE_CLASS']][$key2]['@attributes']['dn'];
		    }
		}
		$myLogMsg="New UCSM Filter resolved for UCS class: {$class} ".count($dn)." objects returned\n";
		if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		foreach($dn as $classKey=>$workingDn) {
		    if(isset($themap->UCSMeventids[$myConstructor.'<->'.$class.'<->'.$workingDn]) === false) {	// we have not yet done the initial data import
			// set it here for other threads calling in here to not call if same class
			$themap->UCSMeventids[$myConstructor.'<->'.$class.'<->'.$workingDn] = true;
			$subscriptionCount=$themap->ucsstack['subscriptionCounter'];
			$subscriptionCount++;
			$themap->ucsstack['subscriptionCounter']=$subscriptionCount;
			$myLogMsg = date("Y-m-d H:i:s")." -> [".$myConstructor."] New UCSM Filter (number ".$subscriptionCount.") allowing CLASS:".$class." and DN:".$workingDn."\n";
			//echo $myLogMsg;
			if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		        if (count($dn) === 1) {
			    foreach($return['configResolveClass']['outConfigs'][$value['SOURCE_CLASS']]['@attributes'] as $key2=>$value2) {
				//echo "1Calling custom doer: {$themap->ucssession->ip} {$dn} {$key2} {$value2}\n";
				doer($themap, $value["SOURCE_CLASS"], $workingDn, $key2, $value2, $themap->ucssession->ip, $myConstructor);
			    }
			} else {
			    foreach($return['configResolveClass']['outConfigs'][$value['SOURCE_CLASS']][$classKey]['@attributes'] as $key3=>$value3) {
			        //echo "2Calling custom doer: {$themap->ucssession->ip} {$dn} {$key3} {$value3}\n";
				if(!doer($themap, $value["SOURCE_CLASS"], $workingDn, $key3, $value3, $themap->ucssession->ip, $myConstructor)) break;
			    }
			}
		    } else {
			//echo "6-22-15, called into reload_flowmap_UCS... but was already being worked for class<->dn: {$class}<->{$workingDn}\n";
		    }
		}
	    }
        }
    } else {
	echo "WARNING: reload_flowmap_UCS called, but the EVENT_ACTIVE is not yet true.....doing nothing.\n";
    }
    //$myLogMsg="********6-22-15************ Userstory={$themap->storage['userstory']}: Time: ".date("Y-m-d H:i:s").": Now Leaving reload_flowmap_UCS thread #{$thisThread}\n";
    //if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
}

// this is a filter, where we dont want to get events of a certain type (that are learned from dynamic flowmaps, to add to our list of subscriptions)
function isSubscriptionInteresting($textForInclusion, $evalClass, $evalContext) {
    //echo "6-6-15 Adding the logic to see if this new item should be in our subscriptions we listen do (for our UCS domain)\n";
    //echo "evalClass: {$evalClass}, evalContext: {$evalContext}, and my domain of interest: {$textForInclusion}\n";
    switch ($evalClass) {
	case "infraRsAccPortP":
	case "infraHPortS":
	case "infraPortBlk":
	case "infraRsAccBaseGrp":
	case "infraRsLacpPol":
	    // time to check if the context includes our UCS domains for these types
	    if (strstr($evalContext, $textForInclusion) == NULL) return false;
	    break;
    }
    return true;
}

/*function checkpeer(&$haystack, &$needle) {
    if(is_null($haystack) || is_null($needle)) return false;
    foreach($haystack as $index) {
	if($index === $needle) {
	    return true;
	}
    }
    return false;
}*/

// This function is used to just get the base prefix of the key, prior to the dn for many uses throughout the program
// As a reference, keys for storageindex_class and the storage objects is:  LearnedSystem<1>PeerSystem<2>dn<3>object_type or LearnedSystem<1>PeerSystem<2>dn<=>root_object_type
function get_storagePrefix($akey, $prefixTerminator) {
    if (($endPointer=strpos($akey,$prefixTerminator)) === false) {
	return false;
    }
    $endPosition=$endPointer + strlen($prefixTerminator);
    $result=substr($akey, 0, $endPosition);
    //echo "-----------------TESTING... in get_storagePrefix, input:{$akey}, preterm: {$prefixTerminator}, returning:{$result}\n";
    return $result;
}

// This function is used to just get the base prefix of the key, prior to the dn for many uses throughout the program
// As a reference, keys for storageindex_class and the storage objects is:  LearnedSystem<1>PeerSystem<2>dn<3>object_type or LearnedSystem<1>PeerSystem<2>dn<=>root_object_type
function get_swappedstoragePrefix($akey, $pivot, $prefixTerminator) {
    if(($endPointer=strpos($akey,$prefixTerminator)) === false || ($pivotPtr=strpos($akey,$pivot)) === false) {
        return false;
    }
    $pivotEnd = $pivotPtr + strlen($pivot);
    $endPosition=$endPointer + strlen($prefixTerminator);
    $section1 = substr($akey,0,$pivotPtr);
    $section2 = substr($akey,$pivotEnd,$endPointer-$pivotEnd);
    $result=$section2.$pivot.$section1.$prefixTerminator;
    //echo "-----------------TESTING... in get_swappedstoragePrefix, input:{$akey}, pv: {$pivot}, preterm: {$prefixTerminator}, returning:{$result}\n";
    return $result;
}

// This function is used on a storageindex key, to just pull out the stuff prior to the separator (typcially <=>) and to append the separator
// and new item after it (i.e. a <=>FLOWMAP etc.).  This is used when the item we gather in storageindex for either UCS or APIC, needs to gather the larger storage object by the toget value
function get_newindex($akey, $separator, $toget) {
    if(($separatorPtr=strpos($akey,$separator)) === false) {
        return false;
    }
    $result=substr($akey, 0, $separatorPtr).$separator.$toget;
    //echo "-----------------TESTING... in get_newindex, input:{$akey}, sep: {$separator}, toget: {$toget}, returning:{$result}\n";
    return $result;
}

// This function is used on a storageindex key, to just pull out and swap around the pivot (typically <1>) the stuff prior to the separator (typcially <=>)
// and to append the separator and new item after it (i.e. a <=>FLOWMAP etc.).  Pivot is first, then separator item to stop the pivot, finally the replacement start location
// This is used when the item we gather in storageindex for either UCS or APIC, needs to gather the larger storage object from the other controller
function get_swappednewindex($akey, $pivot, $separator, $replacer, $toget) {
    if(($separatorPtr=strpos($akey,$separator)) === false || ($pivotPtr=strpos($akey,$pivot)) === false || ($replacerPtr=strpos($akey,$replacer)) === false) {
	echo "ERROR:  Call to get_swappednewindex, and the pivot or separator or replacer are not there.\n";
        return false;
    }
    //172.25.180.32<1>172.25.177.226<2>uni/infra/funcprof/accbundle-UCSM_DOMAIN_UCS-TME-LAB-A<=>CLASS
    $pivotEnd = $pivotPtr + strlen($pivot);
    $separatorEnd = $separatorPtr + strlen($separator);
    $section1 = substr($akey,0,$pivotPtr);
    $section2 = substr($akey,$pivotEnd,$separatorPtr-$pivotEnd);
    $section3 = substr($akey,$separatorEnd,$replacerPtr-$separatorEnd);
    $result=$section2.$pivot.$section1.$separator.$section3.$toget;	// here I dont insert the <=> in output, so I have freedom to set the <3>VMM_ROOT type thing directly
    //echo "-----------------TESTING... in get_swappednewindex, input:{$akey}, pv: {$pivot}, sep: {$separator}, toget: {$toget}, returning:{$result}\n";
    return $result;
}

// This function extracts a dn from a longer text string typically between a <2> and a <3> or a <=>
// 172.25.180.32<1>172.25.177.226<2>uni/infra/funcprof/accbundle-UCSM_DOMAIN_UCS-TME-LAB-A<=>CLASS
function get_dn($akey,$separatorstart,$separatorend) {
    if(($startPtr=strrpos($akey,$separatorstart)) === false || ($endPtr=strrpos($akey,$separatorend)) === false) {
        return false;
    }
    $start = $startPtr + strlen($separatorstart);
    $end = $endPtr;
    $result=substr($akey, $start, $end-$start);
    //echo "-----------------TESTING... in get_dn, input:{$akey}, ss: {$separatorstart}, se: {$separatorend}, returning:{$result}\n";
    return $result;
}

// This function looks to see if the needle is in a haystack.  The reason for this need, is some items will have trailing / items, and some are substrings so we first check for equality then strstr
function compare_dn($haystack_dn, $needle_dn) {
    if ($haystack_dn === $needle_dn) {
	return true;
    } else {
	if (strstr($haystack_dn, $needle_dn.'/') != NULL) {
	    return true;
	}
    }
    return false;
}

// This function
/*function get_storage_index(&$themap, $inclass, $inattribute, $invalue) {
    $mo=array();
    //foreach($themap->storage as $key=>$value) {
    foreach($themap->storageindex_class as $key => $value) {	    
        if(strpos($key, "<=>CLASS") !== false && $themap->storage[$key] === $inclass && $themap->storage[get_newindex($key,'<=>',$inattribute)] === $invalue) {
	    $mo[]=substr($key,0, -8);
        }
    }
    return $mo;
}

// This function
function get_storage_dn(&$themap, $inclass, $inattribute, $invalue) {
    $mo=array();
    //foreach($themap->storage as $key=>$value) {
    foreach($themap->storageindex_class as $key => $value) {
        if(strpos($key, "<=>CLASS") !== false && $themap->storage[$key] === $inclass && $themap->storage[get_newindex($key,'<=>',$inattribute)] === $invalue) {
	    $mo[substr($key, strpos($key,'<2>')+3, -8)]=true;
        }
    }
    return $mo;
}
    
// This function 
function get_storage_pre(&$themap, $inclass, $inattribute, $invalue) {
    //foreach($themap->storage as $key=>$value) {
    foreach($themap->storageindex_class as $key => $value) {	
        if(strpos($key, "<=>CLASS") !== false && $themap->storage[$key] === $inclass && $themap->storage[get_newindex($key,'<=>',$inattribute)] === $invalue) {
	    return substr($key, 0, strpos($key,'<2>')+3);
        }
    }
    return false;
}*/
    
// This function
function get_storage_key(&$themap, $inclass, $inattribute, $invalue) {
    $mo=array();
    //foreach($themap->storage as $key=>$value) {
    foreach($themap->storageindex_class as $key => $value) {	    
        if(strpos($key, "<=>CLASS") !== false && $themap->storage[$key] === $inclass && $themap->storage[get_newindex($key,'<=>',$inattribute)] === $invalue) {
	    $mo[substr($key, 0, -8)]=true;
        }
    }
    return $mo;
}  

// This function tests a given key to the storage already - in order to remove more processing burdens by repeat data sends
function check_storage_key(&$themap, $indn, $inattribute, $invalue, $system) {
    // 9-1-15 need to clean up for rack case
    if($themap->storage['userstory'] == 5) {
	// this is rack, so we set the IP to....
	$systemCheck="RACKSERVERS";
    } else {
    	// this is one of the UCSM stories, wo we set the IP to the ucs
	$systemCheck=$themap->ucssession->ip;
    }
    if($system === $systemCheck) {
        $ipcheck=$themap->apicsession->ip;
    } else {
        $ipcheck=$systemCheck;
    }
    // 9-1-15 end
    if(isset($themap->storage[$system.'<1>'.$ipcheck."<2>".$indn."<=>CLASS"]) == true && $themap->storage[$system.'<1>'.$ipcheck."<2>".$indn."<=>".$inattribute] === $invalue) {
        //echo "1 CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT\n";
        return true;
    }
    if(isset($themap->storage[$ipcheck.'<1>'.$system."<2>".$indn."<=>CLASS"]) == true && $themap->storage[$ipcheck.'<1>'.$system."<2>".$indn."<=>".$inattribute] === $invalue) {
        //echo "2 CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT CACHE HIT\n";
        return true;
    }	
}  

// This function finds a given key to a flowmap based on constructor and source class
function get_flowmap(&$themap, $inconstructor, $inclass) {
    //echo "GOT:{$inconstructor} -- {$inclass}\n";
    foreach($themap->flowmap as $key=>$value) {
        if($themap->flowmap[$key]['CONSTRUCTOR'] === $inconstructor && $themap->flowmap[$key]['SOURCE_CLASS'] === $inclass) {	    
	    return $key;
        }
    }
    return false;
}

// This function gets the actual ACI leaf side node/port for each UCS VPC port extracted from a given dn on the UCS side
function get_vpcports_from_UCS(&$themap, $fabricEthLanPc_dn) {
    $returnObject=array();
    $tmpneedle=$fabricEthLanPc_dn.'/';
    $domainname=$themap->ucsstack['physdomainname'];
    $dnArray=explode('/',$tmpneedle);
    $fabricname=$domainname.'-'.$dnArray[2];
    $tmpVPCid=$dnArray[3];
    $buildIt=false;
    // do a search for the interfaces on the UCS
    foreach($themap->storageindex_class as $key => $value1) {
        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'fabricEthLanPcEp') {
	    $tmphaystack=$themap->storage[get_newindex($key,'<=>','dn')];
	    if(strstr($tmphaystack, $tmpneedle) != NULL) {
	        $interfaceName="Ethernet".$themap->storage[get_newindex($key,'<=>','slotId')].'/'.$themap->storage[get_newindex($key,'<=>','portId')];
	        $returnObject[$domainname][$fabricname][$tmpVPCid][]=$interfaceName;
	    }
	}
    }
    return $returnObject;
}

//Main function for the VMM user story
function VMM(&$themap, $inclass, $indn, $inattribute, $invalue, $insystem, $inoperation) {
    date_default_timezone_set('UTC');
    //echo "VMM GOT: {$inclass} -- {$indn} -- {$inattribute} -- {$invalue} -- {$insystem} -- {$inoperation}\n";
    $myLogMsg="********6-31-15 Common inside VMM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);

    $vmmdomainArray=array();
    if($inoperation === "DOER") {
	if($inclass === "fabricEthVlanPc" && $inattribute === "isNative") {
	    // the DJL2 configuration native VLAN has changed - we only manage this for the VXLAN case, for the transport VLAN
	    if (strstr($indn, "B2G-VXLAN-Transport") != NULL) {
		$transportDn = "fabric/lan/net-B2G-VXLAN-Transport";
		foreach($themap->storage as $key => $value) {
		    if(strpos($key, '<2>'.$transportDn.'<3>VMM_ROOT') > 1 ) {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "modified";
		        $themap->storage[$key]=$tmp;
		        echo date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$key}\n";
		        break;
		    }
		}
	    }
	    return;
	}
	if($inclass === "vnicEtherIf" && $inattribute === "defaultNet") {
	    //echo "5-2-15 working:  ready to reset the VLANs on the vnic template if we control.\n";
	    // we need to check if this is related to a vNIC tempalte that we control for APIC, and if so, reset the VLANs
	    // another cause for this, is someone adding another VLAN to the template.  This will also just reset the VLAN to the one we want
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'vnicEtherIf' && strpos($key, $themap->ucssession->ip.'<1>') > -1) {
		    //echo "5-2-15 working *:  looking to parse the key: [{$key}]\n";
		    //gather the key for the APIC object that would map to this vnicEtherIf in the UCS
		    $vnicEtherItem=get_swappednewindex($key,'<1>','<2>','<=>','<3>VMM_CHILD');
		    if ($themap->storage[$vnicEtherItem] === NULL) continue;	// we do not manage this template on this domain
		    //echo "5-2-15 working **: strstr within [{$indn}], for [{$themap->storage[$vnicEtherItem]['vnicEtherIf_dn']}]\n";
		    if (strstr($indn, $themap->storage[$vnicEtherItem]['vnicEtherIf_dn']) == NULL) continue;  // this is not the template we are looking for
		    //echo "5-2-15 working ***: looking at index: [{$vnicEtherItem}], and vardump is:\n";
		    //var_dump($themap->storage[$vnicEtherItem]);
		    //we received a defaultNet change, then reset all VLANs on the template and resync if the keep sync is set
		    foreach($themap->storage[$vnicEtherItem]['peerdn'] as $thePeerDn => $boolValue) {
			if ($boolValue) break;
		    }
		    $pre=get_storagePrefix($key, "<2>");
		    $vnicEtherItemFlowmap = $pre.$thePeerDn."<=>FLOWMAP";
		    //echo "5-2-15 working ****: looking at index: [".$vnicEtherItemFlowmap."], and vardump is:\n";
		    //var_dump($themap->storage[$vnicEtherItemFlowmap]);
		    //echo "and....";
		    //var_dump($themap->flowmap[$themap->storage[$vnicEtherItemFlowmap]]);
		    if($themap->flowmap[$themap->storage[$vnicEtherItemFlowmap]]['KEEP_SYNC'] === "TRUE") {
			$tmp=$themap->storage[$vnicEtherItem];
			$tmp["Adminstate"] = "modified";		// we re-assert the need to write this in the synthetic object
			echo date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$vnicEtherItem}\n";
			$themap->storage[$vnicEtherItem]=$tmp;
		    }
		}
	    }
	    return;
	}
	if($inclass === "vnicLanConnTempl") {
	    $myLogMsg="********6-31-15 detailed inside VMM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    switch($inattribute) {
		case "qosPolicyName":
		case "nwCtrlPolicyName":
		case "switchId":
		case "templType":
		case "mtu":
		    // find the synthetic object in memory matching this dn, and flag that it needs to be recreated
		    foreach($themap->storageindex_class as $key => $value) {
			if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'vnicLanConnTempl' && strpos($key, $themap->ucssession->ip.'<1>') > -1) {
			    if (strpos($key,$indn) > -1) {
				$peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
				$templateID=$peerKey.$indn.'<3>VMM_CHILD';
				if(isset($themap->storage[$templateID]) != false) {
				    $tmp=$themap->storage[$templateID];
				    $tmp["Adminstate"] = "modified";
				    $themap->storage[$templateID]=$tmp;
				}
			    }
			}
		    }
		    break;
	    }
	    return;
	}
	if($inclass === "fabricVlan" && $inattribute === "id") {
	    // We see a modification of a UCS fabric VLAN.  We do not check for a keep sync, as this is assumed as part of the whole program logic
	    foreach($themap->storage as $key => $value) {
	        if(strpos($key, '<2>'.$indn.'<3>VMM_ROOT') > 1 ) {
		    $tmp=$themap->storage[$key];
		    $tmp["Adminstate"] = "modified";
		    $themap->storage[$key]=$tmp;
	            echo date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$key}\n";
		    break;
		}
	    }
	    return;
	}
	/*if($inclass === "lldpAdjEp") {
	    //echo "5-4-15, lldpAdjEp input is here*****, indn={$indn}, inattr={$inattribute}, inval={$invalue}, inoperation={$inoperation}\n";
	    if($inattribute === "portIdV") {
		//echo "5-4-15, port for this connection is: {$invalue}\n";
		$themap->ucsstack['tmpPort'] = $invalue;
	    } else if($inattribute === "sysName") {
		if (isset($themap->ucsstack['tmpPort']) == false) {
		    //echo "ERROR: tmpPort not set, but needed - so returning\n";
		    return;
		}
		foreach($themap->ucsstack as $key => $value) {
		    if ($key === "physdomainname") {
			// append the -A then -B to test
			$aValue = $value.'-A';
			$bValue = $value.'-B';
			//echo "5-4-15 testing if value:{$value}-A or {$value}-B contains invalue:{$invalue}\n";
			if (strpos($aValue, $invalue) > 0) {
			    $themap->ucsstack['lastReportedVPCuplinkPort-A'] = $themap->ucsstack['tmpPort'];
			    unset($themap->ucsstack['tmpPort']);
			    //echo "5-4-15, we matched domain={$invalue} last reported VPC A side port={$themap->ucsstack['lastReportedVPCuplinkPort-A']}\n";
			} elseif (strpos($bValue, $invalue) > 0) {
			    $themap->ucsstack['lastReportedVPCuplinkPort-B'] = $themap->ucsstack['tmpPort'];
			    unset($themap->ucsstack['tmpPort']);
			    //echo "5-4-15, we matched domain={$invalue} last reported VPC B side port={$themap->ucsstack['lastReportedVPCuplinkPort-B']}\n";
			} else {
			    unset($themap->ucsstack['tmpPort']);
			}
		    }
		}
	    }
	    return;
	}*/
	if($inclass === "nwctrlDefinition") {
	    $classText="VMM_LLDPNCP";
	    $myLogMsg="********6-31-15 detailed inside VMM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "nwctrl-ACI-LLDP") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key, "<2>");
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->ucsstack['VIP']."<1>".$themap->apicstack['APIC_IP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="********6-31-15 specific inside VMM: UCSM Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    // now if we received a rx or tx that is disabled, then reset to enabled if KEEP_SYNC is true
	    $sync = false;
	    if($themap->flowmap[$themap->storage[$pre.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") $sync=true;
	    if((($inattribute === "lldpReceive" && $invalue !== "enabled") || ($inattribute === "lldpTransmit" && $invalue !== "enabled")) && ($sync)) {
		$tmp=$themap->storage[$storageIndex];
		$tmp["Adminstate"] = "created";		// we re-assert the need to write this in the synthetic object
		$themap->storage[$storageIndex]=$tmp;
	        $myLogMsg=date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$indn}\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	        echo $myLogMsg;
	    }
	    return;
	}
	if($inclass === "dpsecMac") {
	    $classText="VMM_LLDPMACSEC";
	    $myLogMsg="********6-31-15 specific inside VMM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "nwctrl-ACI-LLDP/mac-sec") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=substr($key, 0, strrpos($key,"<2>") + 3);
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->ucsstack['VIP']."<1>".$themap->apicstack['APIC_IP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="********6-31-15 specific inside VMM: UCSM Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    // now if we received a forge=deny, then reset to allow if KEEP_SYNC is true
	    $sync = false;
	    if($themap->flowmap[$themap->storage[$pre.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") $sync=true;
	    if(($inattribute === "forge") && ($invalue !== "allow") && ($sync)) {
	        $tmp=$themap->storage[$storageIndex];
	        $tmp["Adminstate"] = "created";		// we re-assert the need to write this in the synthetic object
	        $themap->storage[$storageIndex]=$tmp;
	        $myLogMsg=date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$indn}\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	        echo $myLogMsg;
	    }
	    return;
	}
	if($inclass === "qosclassEthBE") {
	    $classText="VMM_MTUBE";
	    $myLogMsg="********6-31-15 specific inside VMM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key, "<2>");
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->ucsstack['VIP']."<1>".$themap->apicstack['APIC_IP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="********6-31-15 specific inside VMM: UCSM Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    // now if we received an MTU change, then reset to allow if KEEP_SYNC is true
	    $sync = false;
	    if($themap->flowmap[$themap->storage[$pre.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") $sync=true;
	    if(($inattribute === "mtu" && $invalue !== "9000") && ($sync)) {
		$tmp=$themap->storage[$storageIndex];
		$tmp["Adminstate"] = "created";		// we re-assert the need to write this in the synthetic object
		$themap->storage[$storageIndex]=$tmp;
	        $myLogMsg=date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$indn}\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	        echo $myLogMsg;
	    }
	    return;
	}
	if($inclass === "fabricMulticastPolicy") {
	    $classText="VMM_MCAST_POL";
	    $myLogMsg="********6-31-15 specific inside VMM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "for-VXLAN-mcast") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key, "<2>");
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->ucsstack['VIP']."<1>".$themap->apicstack['APIC_IP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="********6-31-15 specific inside VMM: UCSM Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    // now if we received a message of multicast policy change, then reset to allow if KEEP_SYNC is true
	    $sync = false;
	    if($themap->flowmap[$themap->storage[$pre.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") $sync=true;
	    if(($inattribute === "snoopingState") && ($invalue !== 'enabled') && ($sync)) {
		$tmp=$themap->storage[$storageIndex];
		$tmp["Adminstate"] = "created";		// we re-assert the need to write this in the synthetic object
		$themap->storage[$storageIndex]=$tmp;
	        $myLogMsg=date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$indn}\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	        echo $myLogMsg;
	    }
	    return;
	}
	if($inclass === "epqosDefinition") {
	    $classText="VMM_QOSDEF";
	    $myLogMsg="********6-31-15 specific inside VMM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "ACIleafHV") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key, "<2>");
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->ucsstack['VIP']."<1>".$themap->apicstack['APIC_IP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="********6-31-15 specific inside VMM: UCSM Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    return;
	}
	if($inclass === "epqosEgress") {
	    $classText="VMM_EGRESSQOS";
	    $myLogMsg="********6-31-15 specific inside VMM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "ACIleafHV/egress") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key, "<2>");
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->ucsstack['VIP']."<1>".$themap->apicstack['APIC_IP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="********6-31-15 specific inside VMM: UCSM Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    // now if we received a message of hostControl priority change, then reset to allow if KEEP_SYNC is true
	    $sync = false;
	    if($themap->flowmap[$themap->storage[$pre.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") $sync=true;
	    if((($inattribute === "hostControl" && $invalue !== "full") || ($inattribute === "prio" && $invalue !== "best-effort")) && ($sync)) {
		$tmp=$themap->storage[$storageIndex];
		$tmp["Adminstate"] = "created";		// we re-assert the need to write this in the synthetic object
		$themap->storage[$storageIndex]=$tmp;
	        $myLogMsg=date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$indn}\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	        echo $myLogMsg;
	    }
	    return;
	}
	if($inclass === "vnicVmqConPolicy") {
	    $classText="VMM_VMQCONPOL";
	    $myLogMsg="********6-31-15 specific inside VMM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "ACIleafVMQ") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key, "<2>");
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->ucsstack['VIP']."<1>".$themap->apicstack['APIC_IP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="********6-31-15 specific inside VMM: UCSM Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    return;
	}
	if($inclass === "vnicVmqConPolicyRef") {	// this was selected to some other named conn policy, reset here.  ***Note the UCSM Centrale does not update, refresh the screen to see this.
	    // Need to find the adapter template that is within the dn (if any), and reset this
	    foreach($themap->storageindex_class as $key => $value) {
		if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'vnicLanConnTempl' && strpos($key, $themap->ucssession->ip.'<1>') > -1) {
		    $templateDn=get_dn($key,'<2>','<=>');
		    if (compare_dn($indn,$templateDn)) {
			$peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
			$templateID=$peerKey.$templateDn.'<3>VMM_CHILD';
			if(isset($themap->storage[$templateID]) != false) {
			    $tmp=$themap->storage[$templateID];
			    $tmp["Adminstate"] = "modified";
		            $themap->storage[$templateID]=$tmp;
		        }
		    }
	        }
	    }
	    return;
	}
	if($inclass === "hvsExtPol") {		// this is where we are being told of VXLAN virtual wires for the name of the EPG and the VXLAN encap
	    $start = strrpos($indn, "ctrlr-[") + strlen("ctrlr-[");
	    $end = strrpos($indn, "]-") - $start;
	    if ($start > 0 && $end > 0) {
		$controllerDomainName = substr($indn, $start, $end);
		if ($inattribute === "startEncap") {
		    $vxlanEncap=$invalue;
		    echo date("Y-m-d H:i:s")." -> Found a VXLAN virtual wire encapsulation on domain: {$controllerDomainName} with encap: {$vxlanEncap}\n";
		}
	    }	// we dont need to do anything at this point, as the learned data is pushed onto the storage stack by the framework
	}
	if($inclass === "vmmDomP" || $inclass === "vmmEpPD") {
	    $vmmDomCounter=$themap->apicstack['vmmDomCounter'];
	    $myLogMsg="********7-2-15 Specific inside VMM case for {$inclass}: indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
	    if ($inclass === "vmmDomP") {	// this first section is just unique to the domain learning parts, then both update the adapter templates, etc.
		// Now we start by ensuring this VMM association is written to storage, such that we can create the vNIC templates (that are initially empty) and we add the right networks later
		$prefix=$themap->apicstack['APIC_IP'].'<1>'.$themap->ucsstack['VIP'].'<2>';
		$vmmDomainSyntheticObject=$prefix.$indn.'<3>VMM_ASSOCIATIONS';
		preg_match("/vmmp-VMware\/dom-(.*)/", $indn, $output_array);
		$vmmdomain=$output_array[1];
		if (isset($vmmdomainArray[$vmmdomain])) {
		    $myLogMsg="********6-31-15 VMM domain {$vmmdomain} already has written backing VLANs and Adapter Templates to UCS.  Continuing on...\n";
		    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		} else {
		    $vmmDomCounter++;
		    $themap->apicstack['vmmDomCounter']=$vmmDomCounter;
		    $vmmdomainArray[$vmmdomain] = $vmmDomCounter;
		    $myLogMsg=date("Y-m-d H:i:s")." -> UCS System:{$themap->ucssession->ip} is being enabled to host servers in VMM domain #{$vmmdomainArray[$vmmdomain]}: {$vmmdomain}\n";
		    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", "********6-31-15 ".$myLogMsg, FILE_APPEND);
		    echo $myLogMsg;
		    if(isset($themap->storage[$vmmDomainSyntheticObject]) === false) {
		        $myLogMsg="********6-31-15 Creating VMM AEP Memory Object: {$vmmDomainSyntheticObject}\n";
		        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		        $themap->storage[$vmmDomainSyntheticObject] = array("CLASS"=>"vmmDomP-AEP", "vmmDomP-AEP_name"=>$vmmdomain, "vmmDomP-AEP_dn"=>$indn, "vmmDomP_counter"=>$vmmDomCounter,
		    							    "UCSdomainmapping"=>array($themap->ucsstack['physdomainname']=>true), "Adminstate"=>"");
		    } else {
		        $myLogMsg="********6-31-15 PHP thinks that VMM memory object {$vmmDomainSyntheticObject} is already there????\n";
		        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		    }
		}
		// Now write the startup vNIC templates for this VMM domain
		$myLogMsg="********6-31-15 Adding synthetic objects for this instance now: {$vmmdomain}\n";
		if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		$vnicLanConnTempl_nameA=$vmmdomain.':A';
		if(strlen($vnicLanConnTempl_nameA) > 16) {
		    $vnicLanConnTempl_nameA=substr($vnicLanConnTempl_nameA, strlen($vnicLanConnTempl_nameA) - 16);
		}	
		$vnicLanConnTempl_dnA='org-root/lan-conn-templ-'.$vnicLanConnTempl_nameA;
		$vnicLanConnTempl_nameB=$vmmdomain.':B';
		if(strlen($vnicLanConnTempl_nameB) > 16) {
		    $vnicLanConnTempl_nameB=substr($vnicLanConnTempl_nameB, strlen($vnicLanConnTempl_nameB) - 16);
		}
		$vnicLanConnTempl_dnB='org-root/lan-conn-templ-'.$vnicLanConnTempl_nameB;
		$vnicLanConnTempl_A=$prefix.$vnicLanConnTempl_dnA.'<3>VMM_CHILD';
		$vnicLanConnTempl_B=$prefix.$vnicLanConnTempl_dnB.'<3>VMM_CHILD';
		$vnicLanConnTempl_descr='Auto Created for VMM domain: '.$vmmdomain;
		if(isset($themap->storage[$vnicLanConnTempl_A]) === false) {
		    $myLogMsg="********6-31-15 Creating Template: {$vnicLanConnTempl_nameA}\n";
		    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		    $themap->storage[$vnicLanConnTempl_A] = array("CLASS" => "vnicLanConnTempl", "peerdn"=>array(), "vnicLanConnTempl_dn" => $vnicLanConnTempl_dnA, "vnicLanConnTempl_descr" => $vnicLanConnTempl_descr,
		  					          "vnicLanConnTempl_switchId" => "A", "vnicLanConnTempl_name" => $vnicLanConnTempl_nameA, "Adminstate"=>"created");
		} else {
		    $myLogMsg="********6-31-15 PHP thinks that template {$vnicLanConnTempl_nameA} is already there????\n";
		    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		}
		if(isset($themap->storage[$vnicLanConnTempl_B]) === false) {
		    $myLogMsg="********6-31-15 Creating Template: {$vnicLanConnTempl_nameB}\n";
		    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		    $themap->storage[$vnicLanConnTempl_B] = array("CLASS" => "vnicLanConnTempl", "peerdn"=>array(), "vnicLanConnTempl_dn" => $vnicLanConnTempl_dnB, "vnicLanConnTempl_descr" => $vnicLanConnTempl_descr,
							          "vnicLanConnTempl_switchId" => "B", "vnicLanConnTempl_name" => $vnicLanConnTempl_nameB, "Adminstate"=>"created");
		} else {
		    $myLogMsg="********6-31-15 PHP thinks that template {$vnicLanConnTempl_nameA} is already there????\n";
		    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		}
	    } elseif($inclass === "vmmEpPD") {
		// Now we start by ensuring this VMM association is written to storage, such that we can create the vNIC templates (that are initially empty) and we add the right networks later
		$prefix=$themap->apicstack['APIC_IP'].'<1>'.$themap->ucsstack['VIP'].'<2>';
		preg_match("/(.*)\/eppd-/", $indn, $output_array);
		$assocDn=$output_array[1];
		$vmmDomainSyntheticObject=$prefix.$assocDn.'<3>VMM_ASSOCIATIONS';
		preg_match("/vmmp-VMware\/dom-(.*)\/eppd-/", $indn, $output_array);
		$vmmdomain=$output_array[1];
		$myLogMsg="********7-2-15 we are looking to find the vmmdomain instance number, the vmmDomainSynthObject is: {$vmmDomainSyntheticObject}\n";
	        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		if(isset($themap->storage[$vmmDomainSyntheticObject])) {	// if this is not set, it will be after our startup vmmDomP... so do nothing
		    $vmmdomainArray[$vmmdomain] = $themap->storage[$vmmDomainSyntheticObject]["vmmDomP_counter"];
		} else {
		    $myLogMsg="********7-2-15 We received a vmmEpPD before a vmmDomP.  So returning as that will be first!!!!!!\n";
		    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		    return;
		}
	    }
	    // Done writing templates, now onto the hunt to match servers to this APIC VMM domain to the physical servers on our UCS and write these networks onto the templates if needed
	    $domainAlreadyWritten2UCS=false;
	    $vmmDomPservercount=0;
	    $simMessagePresented=false;
	    foreach($themap->storageindex_class as $key => $value) {
		if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'computeBlade' && strpos($key, $themap->ucssession->ip.'<1>') > -1) {
		    $uuidKey =get_newindex($key,'<=>','uuid');
		    $ucsuuid=$themap->storage[$uuidKey];
		    $myLogMsg="********6-31-15 Checking UCS UUID:{$ucsuuid}\n";
		    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
		    foreach($themap->storageindex_class as $key1 => $value2) {		    
		        if(strpos($key1,"<=>CLASS") > -1 && $themap->storage[$key1] === 'compHv'){
			    $pre1=get_storagePrefix($key1,'<2>');
			    $dn1=get_dn($key1,'<2>','<=>');
			    $apicKey =get_newindex($key1,'<=>','guid');
			    $apicguid = $themap->storage[$apicKey];
			    $myLogMsg="********6-31-15 Checking APIC Reported GUID:{$apicguid} -- {$pre1}{$dn1}\n";
			    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
			    // Now, if we have a real system - we only add the networks to the template if hosts in this UCS domain support the VMM domain.  In a simulated environment, we just add the networks
			    if( ($themap->storage['realEnvironment'] && ($ucsuuid === $apicguid || $ucsuuid === uuidconvert($apicguid))) || ($themap->storage['realEnvironment'] == false) ) {
				// Now we check that the APIC compHv entry is within the domain we are reviewing
				preg_match("/prov-VMware\/ctrlr-\[(.*)\]-/", $dn1, $output_array);
				$myLogMsg="********7-2-15 We found server in the UCSM domain, that matches one of the VMM domains machines!  Now checking to see if the vmmdomain:{$vmmdomain} is equal to the storage object domain:{$output_array[1]}\n";
				if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
				if ($vmmdomain !== $output_array[1]) continue;
				$vmmDomPservercount++;
				if ($themap->storage['realEnvironment']) {
				    $myLogMsg=date("Y-m-d H:i:s")." -> UCS System:{$themap->ucssession->ip} now has {$vmmDomPservercount} hypervisors in Domain #{$vmmdomainArray[$vmmdomain]}: {$vmmdomain}\n";
				    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", "********6-31-15 ".$myLogMsg, FILE_APPEND);
				    echo $myLogMsg;
				} else {
				    if ($simMessagePresented == false) {
					$myLogMsg=date("Y-m-d H:i:s")." -> UCS System:{$themap->ucssession->ip} now has SIMULATED hypervisors in Domain #{$vmmdomainArray[$vmmdomain]}: {$vmmdomain}\n";
					$simMessagePresented=true;
					if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", "********6-31-15 ".$myLogMsg, FILE_APPEND);
					echo $myLogMsg;
				    }
				}
				if ($domainAlreadyWritten2UCS) {	// 5-29-15
				    $myLogMsg="********6-31-15 VMM domain {$vmmdomain} already has written backing VLANs and Adapter Templates to UCS.  Continuing on...\n";
				    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
				    continue;
				}
				//write the VMM domain to the AEP
				$tmp=$themap->storage[$vmmDomainSyntheticObject];
				$tmp["Adminstate"] = "created";
				$themap->storage[$vmmDomainSyntheticObject]=$tmp;
				$myLogMsg="********6-31-15 VMM domain {$vmmdomain} AEP membership is now being written to APIC since we have servers participating\n";
			        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
				//find all epgs and corresponding vlans...
				$infraForVXLAN=false;
				$transportForVXLAN=false;
				$workingVXLAN=false;
				foreach($themap->storageindex_class as $key2 => $value2) {
				    if(strpos($key2,"<=>CLASS") > -1 ) {
				        if ($themap->storage[$key2] === 'vmmEpPD') {
					    $myLogMsg="7-24-15 checking on the vmmEpPd key: {$key2}, setting workingVXLAN to false\n";
					    $workingVXLAN=false;
					} elseif ($themap->storage[$key2] === 'hvsExtPol') {
					    $myLogMsg="7-24-15 checking on the hvsExtPol key: {$key2}, setting workingVLAN to true\n";
					    $workingVXLAN=true;
					} elseif ($workingVXLAN && !$infraForVXLAN) {
					    // do nothing here, this is just to not continue yet, but do another loop here to get the infrastructure VXLAN set on the template when we find a VXLAN
					    // we need this if only 1 VXLAN virtual wire is present (this would then only loop through one time, but we need to set both infra and transport VLANs)
					    $myLogMsg="7-24-15 in the case where only 1 of the 2 needed VLANs for VXLAN written - moving on\n";
					} else {
					    continue;
					}
					if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					$dn2=get_dn($key2,'<2>','<=>');
					$pre2=get_storagePrefix($key2,'<2>');
					$goForVXLAN=false;
					$goForVLAN=false;
					if ($workingVXLAN) {
					    //$myLogMsg="7-24-15 starting the working VXLAN area\n";
					    //if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					    // now check if we have the right VXLAN VMM domain to continue
					    preg_match("/prov-VMware\/ctrlr-\[(.*)\]-/", $dn2, $output_array);
					    if (isset($output_array[1]) === false) continue;	// 8-24-15 
					    if ($vmmdomain !== $output_array[1]) continue;
					    if (!$transportForVXLAN) {
						if (isset($themap->storage['ucsmVXLANtransportVLAN'])) {
						    $epgEncap = $themap->storage['ucsmVXLANtransportVLAN'];
						    $goForVXLAN=true;
						    $transportForVXLAN=true;
						    $myLogMsg=date("Y-m-d H:i:s")." -> Adding the VXLAN transport VLAN to the UCS templates we use: {$epgEncap}\n";
						} else {
						    $myLogMsg=date("Y-m-d H:i:s")." -> Can not determine the UCS transport VLAN for VXLAN traffic, nothing will be added to UCS Domain\n";
						}
						echo $myLogMsg;
						if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					    } else {
					        if (!$infraForVXLAN) {
						    if (isset($themap->apicstack['infraVLAN'])) {
						        $epgEncap = $themap->apicstack['infraVLAN'];
						        $goForVXLAN=true;
						        $infraForVXLAN=true;
						        $myLogMsg=date("Y-m-d H:i:s")." -> Adding the APIC infrastructure VLAN to the UCS templates we use: {$epgEncap}\n";
						    } else {
						        $myLogMsg=date("Y-m-d H:i:s")." -> Can not determine the APIC infrastructure VLAN yet, nothing will be added to UCS Domain\n";
						    }
						    echo $myLogMsg;
						    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
						} else {
						    continue;
						}
					    }
					} else {
					    $source_scope = $themap->storage[$pre2.$dn2.'<=>SOURCE_SCOPE'];
					    //9-1-15
					    if ($themap->storage[$pre2.$dn2.'<=>encap'] === 'unknown') {
						$myLogMsg=date("Y-m-d H:i:s")." -> VLAN case, cannot determine the UCS VLAN for vmmEpPd at [$key2], memory currently at ".$pre2.$dn2."<=>encap is set to [unknown]\n";
						//echo $myLogMsg;
						if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);						
						continue;
					    } else {
						$epgEncap = substr($themap->storage[$pre2.$dn2.'<=>encap'],5);  // trim the vlan-
						$myLogMsg=date("Y-m-d H:i:s")." -> VLAN case, determined the UCS VLAN for vmmEpPd at [$key2], memory currently at ".$pre2.$dn2."<=>encap is set to [".$epgEncap."]\n";
						//echo "5-29-15 looking at the storage scopes here as: {$source_scope}, and for encap as: {$epgEncap}\n";
						if($source_scope === 'uni/vmmp-VMware/dom-'.$vmmdomain) $goForVLAN=true;
						//echo $myLogMsg;
						if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);						
					    }
					    //9-1-15 end
					}
					if($goForVLAN || $goForVXLAN) {
					    $myLogMsg="********6-31-15 Adding synthetic objects for this instance now: {$ucsuuid}--{$dn2}--{$vmmdomain}--{$epgEncap}\n";
					    if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					    $fabricVlan_id=$epgEncap;
					    $tenant="noTenant";
					    if ($workingVXLAN) {
					        if ($transportForVXLAN && !$infraForVXLAN) {
						    $fabricVlan_name='B2G-VXLAN-Transport';
						} else if ($transportForVXLAN && $infraForVXLAN) {
						    $fabricVlan_name='B2G-VXLAN-Infrastructure';
						}
					    } else {
					        preg_match("/\[(.*?)\]/", $dn2, $output_array);
					        $tmpnames=$output_array[1];
					        $tenant=preg_match("/(?<=\/tn-)(.*?)\//", $tmpnames, $output_array);
					        $tenant=$output_array[1];
					        $application=preg_match("/(?<=\/ap-)(.*?)\//", $tmpnames, $output_array);
					        $application=$output_array[1];
					        $epg=preg_match("/(?<=\/epg-).*/", $tmpnames, $output_array);
					        $epg=$output_array[0];
					        $fabricVlan_name=$tenant.'-'.$application.'-'.$epg.'-V'.$vmmdomainArray[$vmmdomain];
					    }
					    if(strlen($fabricVlan_name) > 32) {
					        $fabricVlan_name=substr($fabricVlan_name, strlen($fabricVlan_name) - 32);
					    }
					    $fabricVlan_dn='fabric/lan/net-'.$fabricVlan_name;
					    $vnicLanConnTempl_nameA=$vmmdomain.':A';
					    if(strlen($vnicLanConnTempl_nameA) > 16) {
					        $vnicLanConnTempl_nameA=substr($vnicLanConnTempl_nameA, strlen($vnicLanConnTempl_nameA) - 16);
					    }	
					    $vnicLanConnTempl_dnA='org-root/lan-conn-templ-'.$vnicLanConnTempl_nameA;
					    $vnicLanConnTempl_nameB=$vmmdomain.':B';
					    if(strlen($vnicLanConnTempl_nameB) > 16) {
					        $vnicLanConnTempl_nameB=substr($vnicLanConnTempl_nameB, strlen($vnicLanConnTempl_nameB) - 16);
					    }
					    $vnicLanConnTempl_dnB='org-root/lan-conn-templ-'.$vnicLanConnTempl_nameB;
					    $vnicEtherIf_nameA=$fabricVlan_name;
					    $vnicEtherIf_dnA=$vnicLanConnTempl_dnA.'/if-'.$vnicEtherIf_nameA;
					    $vnicEtherIf_nameB=$fabricVlan_name;
					    $vnicEtherIf_dnB=$vnicLanConnTempl_dnB.'/if-'.$vnicEtherIf_nameA;
					    $vmmrootindex=$pre2.$fabricVlan_dn.'<3>VMM_ROOT';
					    $vnicLanConnTempl_A=$pre2.$vnicLanConnTempl_dnA.'<3>VMM_CHILD';
					    $vnicLanConnTempl_B=$pre2.$vnicLanConnTempl_dnB.'<3>VMM_CHILD';
					    $vnicEtherIf_A=$pre2.$vnicEtherIf_dnA.'<3>VMM_CHILD';
					    $vnicEtherIf_B=$pre2.$vnicEtherIf_dnB.'<3>VMM_CHILD';
					    $vnicLanConnTempl_descr='Auto Created for '.$tenant.'-'.$vmmdomain;
					    $fabricVlan_defaults=array();
					    if (isset($themap->flowmap[$themap->storage[$pre2.$dn2.'<=>FLOWMAP']]['DEST_DEFAULT'])) {
						$fabricVlan_defaults=$themap->flowmap[$themap->storage[$pre2.$dn2.'<=>FLOWMAP']]['DEST_DEFAULT'];
					    }
					    $vnicLanConnTempl_defaults="";
					    if(isset($themap->storage[$vmmrootindex]) === false) {
					        $myLogMsg="********6-31-15 Creating VLAN: {$fabricVlan_name} and template VLANs for: {$dn2} -- {$ucsuuid}\n";
					        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					        $themap->storage[$vmmrootindex] = array("CLASS" => "fabricVlan", "peerdn"=>array($dn2=>true), "peeruuid"=>$ucsuuid, "fabricVlan_dn" => $fabricVlan_dn, "fabricVlan_name" => $fabricVlan_name, "fabricVlan_id" =>$fabricVlan_id, "fabricVlan_defaults"=> $fabricVlan_defaults, "Adminstate"=>"created");
					        $themap->storage[$vnicEtherIf_A] = array("CLASS" => "vnicEtherIf", "peerdn"=>array($fabricVlan_dn => true), "vnicEtherIf_dn" => $vnicEtherIf_dnA, "vnicEtherIf_name" => $vnicEtherIf_nameA, "Adminstate"=>"created");
					        $themap->storage[$vnicEtherIf_B] = array("CLASS" => "vnicEtherIf", "peerdn"=>array($fabricVlan_dn => true), "vnicEtherIf_dn" => $vnicEtherIf_dnB, "vnicEtherIf_name" => $vnicEtherIf_nameB, "Adminstate"=>"created");						
					    } else {
					        $myLogMsg="********6-31-15 Adding to existing: {$dn2} -- {$ucsuuid}\n";
					        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					        $tmp=$themap->storage[$vmmrootindex];
					        $tmp['peeruuid']=$ucsuuid;
						$tmp['Adminstate']="modified";	// 9-3-15 working for the VXLAN to add in DJL2 once we learn it in VPC case
					        $themap->storage[$vmmrootindex]=$tmp;
					        $themap->storage[$vnicEtherIf_A] = array("CLASS" => "vnicEtherIf", "peerdn"=>array($fabricVlan_dn => true), "vnicEtherIf_dn" => $vnicEtherIf_dnA, "vnicEtherIf_name" => $vnicEtherIf_nameA, "Adminstate"=>"created");
					        $themap->storage[$vnicEtherIf_B] = array("CLASS" => "vnicEtherIf", "peerdn"=>array($fabricVlan_dn => true), "vnicEtherIf_dn" => $vnicEtherIf_dnB, "vnicEtherIf_name" => $vnicEtherIf_nameB, "Adminstate"=>"created");						
					    }
					    if(isset($themap->storage[$vnicLanConnTempl_A]) === false) {
					        $myLogMsg="********6-31-15 Creating Template: {$vnicLanConnTempl_nameA}\n";
					        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					        $themap->storage[$vnicLanConnTempl_A] = array("CLASS" => "vnicLanConnTempl", "peerdn"=>array($fabricVlan_dn => true), "vnicLanConnTempl_dn" => $vnicLanConnTempl_dnA, "vnicLanConnTempl_descr" => $vnicLanConnTempl_descr, "vnicLanConnTempl_switchId" => "A", "vnicLanConnTempl_name" => $vnicLanConnTempl_nameA, "Adminstate"=>"created");
					    } else {
					        $myLogMsg="********6-31-15 We have Existing Template: {$vnicLanConnTempl_nameA}\n";
					        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					        $tmp=$themap->storage[$vnicLanConnTempl_A];
					        $tmp['peerdn'][$fabricVlan_dn]=true;
					        $themap->storage[$vnicLanConnTempl_A]=$tmp;
					    }
					    if(isset($themap->storage[$vnicLanConnTempl_B]) === false) {
					        $myLogMsg="********6-31-15 Creating Template: {$vnicLanConnTempl_nameB}\n";
					        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					        $themap->storage[$vnicLanConnTempl_B] = array("CLASS" => "vnicLanConnTempl", "peerdn"=>array($fabricVlan_dn => true), "vnicLanConnTempl_dn" => $vnicLanConnTempl_dnB, "vnicLanConnTempl_descr" => $vnicLanConnTempl_descr, "vnicLanConnTempl_switchId" => "B", "vnicLanConnTempl_name" => $vnicLanConnTempl_nameB, "Adminstate"=>"created");
					    } else {
					        $myLogMsg="********6-31-15 We have Existing Template: {$vnicLanConnTempl_nameB}\n";
					        if ($themap->storage['logVMMoperations']) file_put_contents("vmmMessages.txt", $myLogMsg, FILE_APPEND);
					        $tmp=$themap->storage[$vnicLanConnTempl_B];
					        $tmp['peerdn'][$fabricVlan_dn]=true;
					        $themap->storage[$vnicLanConnTempl_B]=$tmp;
					    }
					}
				    }
				}
				$domainAlreadyWritten2UCS=true;
			    }
			}
		    }
		}
	    }
        }
    }
    if($inoperation === "REMOVE_DN") {
        //In all remove_dn cases, we return a true to keep the object in memory (like when we resync), false to clean it up
	if($inclass === "vnicVmqConPolicyRef") {  // Here we are only on UCSM (no source or dest) so we need to find the adapter template that is within the dn (if any), and restore this
	    foreach($themap->storageindex_class as $key => $value) {
		if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'vnicLanConnTempl' && strpos($key, $themap->ucssession->ip.'<1>') > -1) {
		    $templateDn=get_dn($key,'<2>','<=>');
		    if (compare_dn($indn,$templateDn)) {
			$peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
			$templateID=$peerKey.$templateDn.'<3>VMM_CHILD';
			if(isset($themap->storage[$templateID]) != false) {
			    $tmp=$themap->storage[$templateID];
			    $tmp["Adminstate"] = "modified";
		            $themap->storage[$templateID]=$tmp;
			    return true;	// 5-19-15
		        }
		    }
	        }
	    }
	}
        //Remove object at destination if source gets deleted.  Later we look to see if we should resync the destination (for ease of code viewing)
        foreach($themap->storage as $key => $value) {
	    if(isset($themap->storage[$key]['peerdn'][$indn]) == false) continue;
	    if($themap->storage[$key]['peerdn'][$indn] === true) {
		if($themap->storage[$key]['CLASS'] == "nwctrlDefinition") {
		    // dont remove, this is a b2g stimulated object
		} elseif ($themap->storage[$key]['CLASS'] == "epqosDefinition") {
		    // dont remove, this is a b2g stimulated object
		} elseif ($themap->storage[$key]['CLASS'] == "fabricMulticastPolicy") {
		    // dont remove, this is a b2g stimulated object
		} else if ($themap->storage[$key]['CLASS'] == "vnicVmqConPolicy") {
		    // dont remove, this is a b2g stimulated object
		} else if ($themap->storage[$key]['CLASS'] == "vnicLanConnTempl") {
		    // dont remove, this is a b2g stimulated object
		} else {
		    $tmp=$themap->storage[$key];
		    unset($tmp['peerdn'][$indn]);
		    echo date("Y-m-d H:i:s")." -> VMM Process has removed the peerdn of {$indn} for key {$key}, will remove if no more peerEPG and no more peerdn's\n"; 
		    $themap->storage[$key]=$tmp;
		    if(count($themap->storage[$key]['peerdn']) === 0 && isset($themap->storage[$key]['peerEPG']) == false) {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "deleted";
		        $themap->storage[$key]=$tmp;
		    }
		}
	    }
        }
        $index="";
        $peerdn="";
        //Resync if Destination object got deleted, and the force resync is true
	$sync=false;
        foreach($themap->storage as $key => $value) {
            if(strpos($key, '<2>'.$indn.'<3>VMM_') > 1 ) {
	        $index=$key;
	        foreach($themap->storage[$key]['peerdn'] as $key1=>$value1) {
	            foreach($themap->storage as $key2=>$value2) {
		        if(strpos($key2, "<2>".$key1."<=>FLOWMAP")) {
		            if(is_null($themap->flowmap[$themap->storage[$key2]]['KEEP_SYNC']) !== true && $themap->flowmap[$themap->storage[$key2]]['KEEP_SYNC'] === "TRUE") {
				$sync=true;
			        break 3;
		            } 
		        }
		    }
	        }
		if ($inclass === "vnicLanConnTempl") {		// since we now just create the templates to start - we always resync these even when peerdn array is empty
		    $sync=true;
		}
	        break;
            }
	}
	if($index !== "") {
            if($sync == true) {
	        $tmp=$themap->storage[$index];
	        $tmp["Adminstate"] = "created";
	        $themap->storage[$index]=$tmp;
	        if(strpos($index, "VMM_ROOT") > -1) {
	            echo date("Y-m-d H:i:s")." -> VMM Process Re-Synched Parent: {$index}\n";
	            foreach($themap->storage as $key => $value1) {	// cycle through the children dependant on this parent (i.e. templates dependant on this VLAN backing an EPG)
			if(isset($themap->storage[$key]['vnicEtherIf_name'])) {
			    if(strpos($key, "VMM_CHILD") >1 && strpos($indn, $themap->storage[$key]['vnicEtherIf_name']) > 1) {	// first case is adapter template that used a VLAN
			        echo date("Y-m-d H:i:s")." -> VMM Process Also Re-Synced Child: {$key}\n";
			        $tmp=$themap->storage[$key];
			        $tmp["Adminstate"] = "created";
				// 9-1-15 and add the peerdn entry back for this VLAN
				$tmp["peerdn"][get_dn($index, '<2>', '<3>')]=true;
			        $themap->storage[$key]=$tmp;
			    }
			}
	            }
	        } else {
	            echo date("Y-m-d H:i:s")." -> VMM Process Re-Synched Child: {$index}\n";
	        }
		return true;
	    } else {
		echo date("Y-m-d H:i:s")." -> VMM Process did not try to re-sync {$indn} of class {$inclass}\n";
	    }
        }
    }
    return false;
}

//Main function for the Bare Metal user story
function BM(&$themap, $inclass, $indn, $inattribute, $invalue, $insystem, $inoperation) {
    date_default_timezone_set('UTC');
    //echo "BM GOT: {$inclass} -- {$indn} -- {$inattribute} -- {$invalue} -- {$insystem} -- {$inoperation}\n";
    $myLogMsg="********8-23-15 Common inside BM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);

    if($inoperation === "DOER") {
	if($inclass === "vnicEtherIf" && $inattribute === "defaultNet") {
	    $myLogMsg="********8-23-15 Specific inside BM:  Recevied a defaultNet event, ready to reset the VLANs on the vnic template inside indn: {$indn} if we control.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    // we need to check if this is related to a vNIC tempalte that we control for APIC, and if so, reset the VLANs
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'vnicEtherIf' && strpos($key, $themap->ucssession->ip.'<1>') > -1) {
		    $vnicEtherItem=get_swappednewindex($key,'<1>','<2>','<=>','<3>BM_CHILD');
		    if ($themap->storage[$vnicEtherItem] === NULL) continue;	// we do not manage this template on this domain
		    if (strstr($indn, $themap->storage[$vnicEtherItem]['vnicEtherIf_dn']) == NULL) continue;  // this is not the template we are looking for
		    // now if we received a defaultNet change, then reset all VLANs on the template and write this as program logic assumes this
		    foreach($themap->storage[$vnicEtherItem]['peerdn'] as $thePeerDn => $boolValue) {
			if ($boolValue) break;
		    }
		    $peerKey=get_storagePrefix($key,'<2>');
		    $vnicEtherItemFlowmap = $peerKey.$thePeerDn."<=>FLOWMAP";
		    if($themap->flowmap[$themap->storage[$vnicEtherItemFlowmap]]['KEEP_SYNC'] === "TRUE") {
			$tmp=$themap->storage[$vnicEtherItem];
			$tmp["Adminstate"] = "modified";		// we re-assert the need to write this in the synthetic object
			echo date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$vnicEtherItem}\n";
			$themap->storage[$vnicEtherItem]=$tmp;
		    }
		}
	    }
	    if ($invalue === "yes") {	// Lets write the CDN - you need UCSM v2.2(4) and later here.  We do NOT re-write this value if the UCS administrator changes it currently
		// the net task here, is we scan all etherif's in memory, and for those not templates, with a native VLAN, that is on a VLAN we manage for B2G, we write the CDN
		if (strpos($indn, "root/lan-conn-templ") > -1) return;
		$matchTemplate=false;
		//echo "************************  5-10-15 working for CDN.  indn={$indn}, invalue=yes\n";
		// if you have mirrored adapters on both A and B, all the A objects will be caught here and used to send message to update for both A and B items
		foreach($themap->storage as $key => $value) {
		    if (strpos($key, "-P:A<=>name") > -1) {
			$templateName=$value;
			//echo "*******5-10-15 templateName on A is: {$templateName}\n";
			// strip the :A
			$testIfName=substr($templateName, 0, strrpos($templateName,":A"));
			if (strpos($indn, $testIfName) > -1) {
			    //echo "*******5-10-15 Setting Match A Template to true for indn=[{$indn}]\n";
			    $matchTemplate=true;
			}
		    } elseif (strpos($key, "-P:B<=>name") > -1) {
			$templateName=$value;
			//echo "*******5-10-15 templateName on B is:{$templateName}\n";
			// strip the :B
			$testIfName=substr($templateName, 0, strrpos($templateName,":B"));
			if (strpos($indn, $testIfName) > -1) {
			    //echo "*******5-10-15 Setting Match B Template to true for indn=[{$indn}]\n";
			    $matchTemplate=true;
			}
		    }
		    if ($matchTemplate) break;
		}
		if ($matchTemplate) {
		    //echo "*******OK, write the CDN to this interface: {$indn}, by parsing the indn based on /if-, for the network name then trim to 14 bytes.\n";
		    $cdn_array = explode("/if-", $indn);
		    $int_dn = $cdn_array[0];
		    $cdn_name = $cdn_array[1];
		    if(strlen($cdn_name) > 14) {	// this is 14 here, as a -A or -B will be added when in the UCS updater thread
			$cdn_name=substr($cdn_name, -14);
		    }
		    $rootindex=$insystem.'<1>'.$int_dn.'<2>'.$cdn_name.'<3>BM_CDN';
		    $themap->storage[$rootindex] = true;
		    //echo "***** Just set the index: {$rootindex} to true.\n";
		}
	    }
	    return;
	}
	if($inclass === "vnicLanConnTempl" && ($inattribute === "switchId" || $inattribute === "templType")) {
	    $myLogMsg="********8-23-15 Specific inside BM:  Received a {$inattribute} change event, ready to reset the whole vnic template if we control.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    // we need to check if this is related to a vNIC tempalte that we control for APIC, and if so, reset the whole vNIC template and VLANs under it
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'vnicLanConnTempl' && strpos($key, $themap->ucssession->ip.'<1>') > -1) {
		    $vnicEtherTemplItem=get_swappednewindex($key,'<1>','<2>','<=>','<3>BM_CHILD');
		    if ($themap->storage[$vnicEtherTemplItem] === NULL) continue;	// we do not manage this template on this domain
		    if (strstr($indn, $themap->storage[$vnicEtherTemplItem]['vnicLanConnTempl_dn']) == NULL) continue;  // this is not the template we are looking for
		    // now if we received a switchID change, then reset all VLANs on the template - and we dont give option to keep sync, as this is needed within program
		    foreach($themap->storage[$vnicEtherTemplItem]['peerdn'] as $thePeerDn => $boolValue) {
			if ($boolValue) break;
		    }
		    $peerKey=get_storagePrefix($key,'<2>');
		    $vnicEtherTemplItemFlowmap = $peerKey.$thePeerDn."<=>FLOWMAP";
		    if($themap->flowmap[$themap->storage[$vnicEtherTemplItemFlowmap]]['KEEP_SYNC'] === "TRUE") {
			$tmp=$themap->storage[$vnicEtherTemplItem];
			$tmp["Adminstate"] = "modified";		// we re-assert the need to write this in the synthetic object
			echo date("Y-m-d H:i:s")." -> VMM Process Re-synced: {$vnicEtherTemplItem}\n";
			$themap->storage[$vnicEtherTemplItem]=$tmp;
		    }
		}
	    }
	    return;
	}
	if($inclass === "fabricVlan" && $inattribute === "id") {
	    //We do not check for a keep sync, as this is assumed as part of the whole program to write the correct VLAN backing items always
	    $myLogMsg="********8-23-15 Specific inside BM:  Received a VLAN id change event, ready to reset the id if we control.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    foreach($themap->ucsstack as $key=>$value) {
		if (strstr($key, "UCSVLANindex<.>") != false) {
		    $keyArray = explode("<.>", $key);
		    $truncatedNetName = $keyArray[1];
		    // save these to match up on tests below determine if we remove it
		    $rootindex=$value;
		    $savedTruncatedName=$truncatedNetName;
		}
		if (strstr($key, "UCSVLANarray<.>") != false) {
		    // need to validate, then remove
		    $keyArray = explode("<.>", $key);
		    $truncatedNetName = $keyArray[1];
		    //echo "Domain resync test:  indn={$indn}, key={$key}, value={$value}, truncatedName={$truncatedNetName}\n";
	    	    if (strstr($savedTruncatedName, $truncatedNetName) != false) {
			// we know these 2 objects are the same, now test for removal by truncatedNames to ensure we are looking at right item
			//echo "***OK, matching truncated names = [$truncatedNetName], looking inside indn=[$indn]\n";
			$return=strstr($indn, $truncatedNetName);
			$len=strlen($rootindex);
			//echo "return from strstr is: [$return], rootindex is {$rootindex}, length of rootindex is $len\n";
			if (strlen($return) > 0) {
			    echo date("Y-m-d H:i:s")." -> BM Process Re-synced:  Recreation of needed VLAN ($truncatedNetName) within UCSM domain ($insystem)\n";
			    $tmp = $themap->storage[$rootindex];
			    $tmp["Adminstate"] = "modified";
			    $themap->storage[$rootindex] = $tmp;
			    break;
			}
		    }
		}
	    }
	    return;
	}
	/*if($inclass === "lldpAdjEp") {
	    if (isset($themap->ucsstack['tmpPort'])) {
		$portText=$themap->ucsstack['tmpPort'];
	    } else {
		$portText="notset";
	    }
	    //echo "5-4-15, lldpAdjEp input is here*****, indn={$indn}, inattr={$inattribute}, inval={$invalue}, inoperation={$inoperation}, portText={$portText}\n";
	    if($inattribute === "portIdV") {
		//echo "5-4-15, port for this connection is: {$invalue}\n";
		$themap->ucsstack['tmpPort'] = $invalue;
	    } else if($inattribute === "sysName") {
		if (isset($themap->ucsstack['tmpPort']) == false) {
		    //echo "ERROR: tmpPort not set, but needed - so returning\n";
		    return;
		}
		foreach($themap->ucsstack as $key => $value) {
		    if ($key === "physdomainname") {
			// append the -A then -B to test
			$aValue = $value.'-A';
			$bValue = $value.'-B';
			//echo "5-4-15 testing if value:{$value}-A or {$value}-B contains invalue:{$invalue}\n";
			if (strpos($aValue, $invalue) > 0) {
			    $themap->ucsstack['lastReportedVPCuplinkPort-A'] = $themap->ucsstack['tmpPort'];
			    unset($themap->ucsstack['tmpPort']);
			    //echo "5-4-15, we matched domain={$invalue} last reported VPC A side port={$themap->ucsstack['lastReportedVPCuplinkPort-A']}\n";
			} elseif (strpos($bValue, $invalue) > 0) {
			    $themap->ucsstack['lastReportedVPCuplinkPort-B'] = $themap->ucsstack['tmpPort'];
			    unset($themap->ucsstack['tmpPort']);
			    //echo "5-4-15, we matched domain={$invalue} last reported VPC B side port={$themap->ucsstack['lastReportedVPCuplinkPort-B']}\n";
			} else {
			    unset($themap->ucsstack['tmpPort']);
			}
		    }
		}
	    }
	    return;
	}*/
	if($inclass === "fvnsVlanInstP") {
	    $classText="UCSM_VLAN_POOL";
	    $myLogMsg="********8-23-15 Specific inside BM:  Received a APIC VLAN pool change event, ready to restore it if we control.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "[UCSM_DOMAINS]-dynamic") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=substr($key, 0, strrpos($key,"<2>") + 3);
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->apicstack['APIC_IP']."<1>".$themap->ucsstack['VIP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="APIC Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	}
	if($inclass === "physDomP") {
	    $classText="UCSM_PHYS_DOMAIN";
	    $myLogMsg="********8-23-15 Specific inside BM:  Received a physDomP change event, ready to restore it if we control.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "UCSM_DOMAIN_") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key,'<2>');
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->apicstack['APIC_IP']."<1>".$themap->ucsstack['VIP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="APIC Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	}
	// ****From this point on, we are matching the UCS domain event of interest to these events****
	if(!strpos($indn, $themap->ucsstack['physdomainname'])) {
	    $myLogMsg="********8-23-15 Specific inside BM:  We have an event that is not meant for our UCS domain, so returning and doing nothing.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    return;	// this domain is not of interest to us
	}
	if($inclass === 'infraAccPortP' && $inattribute === 'name') {
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an infraAccPortP change event, not handling currently.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    // this is the name of a given vPC port group on the APIC - set these in available memory
	    $tmp=$themap->ucsstack;
	    //if(!strpos($indn, $tmp['physdomainname'])) return;
	    /*for($instCount=0; $instCount < 100; $instCount++) {	// 100 vPC instances max here
	        if(isset($tmp["VPCinstance-$instCount"]) == true) {
		    if ($tmp["VPCinstance-$instCount"] == "$invalue") break;	// was already there
		} else {
		    $tmp["VPCinstance-$instCount"] = "$invalue";
		    $themap->ucsstack=$tmp;
		    break;
		}
	    }*/
	    // 6-18-15
	    if(isset($tmp['physdomainname']) && $invalue === $tmp['physdomainname'].'-A') {
		if (!isset($tmp['fabricAname'])) {
		    $tmp['fabricAname']=$invalue;
		    //echo "******6-18-15 set the fabricAname to: {$tmp['fabricAname']} from the BM area after getting {$inclass} event.\n";
		} else {
		    //echo "******6-18-15 the fabricAname was already set to: {$tmp['fabricAname']} when I looked from the BM area after getting {$inclass} event.\n";		    
		}
	    } elseif(isset($tmp['physdomainname']) && $invalue === $tmp['physdomainname'].'-B') {
		if (!isset($tmp['fabricBname'])) {
		    $tmp['fabricBname']=$invalue;
		    //echo "******6-18-15 set the fabricBname to: {$tmp['fabricBname']} from the BM area after getting {$inclass} event.\n";
		} else {
		    //echo "******6-18-15 the fabricAname was already set to: {$tmp['fabricBname']} when I looked from the BM area after getting {$inclass} event.\n";		    
		}
	    }
	}
	if($inclass === 'infraLeafS' && $inattribute === 'dn') {
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an infraLeafS dn change event, not handling currently.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    // if we have not yet receive the infraAccPortP on startup, extract the domain name from dn and set for both the LeafS and NodeBlk next
	    //$myLogMsg="*******6-18-15 APIC Inside BM:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    //echo $myLogMsg;
	    /*$foundVPCinstance=false;
	    foreach($themap->ucsstack as $key=>$value) {
		if (strstr($key, "VPCinstance-") != NULL) {
		    if (strstr($indn, $value) != NULL) {
			$foundVPCinstance=true;  // ok this is already written
			break;
		    }
		}
	    }
	    if (!$foundVPCinstance) {
		$tmp=$themap->ucsstack;
		$instanceNum=-1;
		$start = strlen("uni/infra/nprof-");
		if (strstr($invalue, "-A:") != NULL) {
		    $end = strpos($invalue,"-A:") - $start + 2;
		    $instanceNum=0;
		} elseif (strstr($invalue, "-B:") != NULL) {
		    $end = strpos($invalue,"-B:") - $start + 2;
		    $instanceNum=1;
		}
		if ($instanceNum > -1) {
		    $domainText = substr($invalue, $start, $end);
		    $tmp["VPCinstance-$instanceNum"] = "$domainText";
		    $themap->ucsstack=$tmp;
		}
	    }*/
	}
	if($inclass === 'infraLeafS' && $inattribute === 'name') {
	    // this is possibly the UCS fabric and name of a given vPC port group on the APIC - set in memory
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an infraLeafS name change event, not handling currently.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    //$tmp=$themap->ucsstack;
	    //if(!strpos($indn, $tmp['physdomainname'])) return;
	    /*foreach($themap->ucsstack as $key=>$value) {
		if (strstr($key, "VPCinstance-") != NULL) {
		    // this we want to write deeper into
		    if (strstr($indn, $value) != NULL) {
			//echo "DANTEST2***:  looking to set the VPCname to {$invalue}\n";
			// this is the item we want
			$tmp["$key-VPCname"] = "$invalue";
			$themap->ucsstack=$tmp;
			break;
		    }
		}
	    }*/
	}
	if($inclass === 'infraNodeBlk' && $inattribute === 'from_') {
	    // this is the leaf node ID supporting a given vPC to the domain of interest on the APIC - set in memory
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an infraNodeBlk from_ change event, not handling currently.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    $tmp=$themap->ucsstack;
	    //if(!strpos($indn, $tmp['physdomainname'])) return;
	    /*foreach($themap->ucsstack as $key=>$value) {
		if (strstr($key, "VPCinstance-") != NULL) {
		    // this we want to write deeper into
		    if (strstr($indn, $value) != NULL) {
			//echo "DANTEST2***:  looking to set the VPCmember node to {$invalue}\n";
			// this is the item we want, for the demo we will only have 2 upstream leafs in a vPC
		        if(isset($tmp["$key-VPCnode0"]) == false) {
			    $tmp["$key-VPCnode0"] = "$invalue";
			} else {
			    $tmp["$key-VPCnode1"] = "$invalue";
			}
			$themap->ucsstack=$tmp;
			break;
		    }
		}
	    }*/
	}
	if($inclass === "fvTenant") {
	    // We learned an APIC Tenant to store for later binding
	    //set($themap->storage["tn-$invalue"]);
	    $tmp=$themap->apicstack;
	    $tmp["$indn"] = "tenant";
	    $themap->apicstack=$tmp;
	}
	if($inclass === "fvAp") {
	    // We learned an App Profile under a given tenant
	    $tmp=$themap->apicstack;
	    $tmp["$indn"] = "app-profile";
	    $themap->apicstack=$tmp;
	}
	if($inclass === "fvAEPg") {
	    // We learned an EPG under a tenant and app profile
	    $tmp=$themap->apicstack;
	    $tmp["$indn"] = "epg";
	    $themap->apicstack=$tmp;
	}
	if($inclass === 'fvRsPathAtt' && $inattribute === 'encap') {
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an fvRsPathAtt encap event, handling if we manage.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    // 11-1-14: if we have an input encap value, we need to overwrite the number selected from pool above
	    // This is for the case where we startup this program, with bindings already configured on APIC so we need to match those
	    $evaluateThisPath=false;
	    $tmp=$themap->ucsstack;
	    //echo "examining UCS domain:".$tmp['VIP']." and looking to see if the path ports are in this FI set.\n";
	    foreach($tmp as $arrayItem => $arrayValue) {
		for ($x=0; $x<2; $x++) {	// only 2 VPC on a given UCS Domain right now
		    if ($arrayItem === "fabricAname" || $arrayItem === "fabricBname") {
		        if (strstr($indn, $arrayValue) !== false ) {
			    $evaluateThisPath=true;
			    break 2;
			}
		    }
		}
	    }
	    if ($evaluateThisPath) {
		$domArray = explode("/rspathAtt", $indn);
	        $domAttachEpgName = $domArray[0];
		$startVLAN = $tmp['physdomainmaxvlan'];
		$finalVLAN = $tmp['physdomainminvlan'];
		for($x=$startVLAN; $x >= $finalVLAN; $x--) {
		    if($tmp["vlan-$x"] === "$domAttachEpgName") unset($tmp["vlan-$x"]);  // just nuke any entry here for this EPG
		}
	        $tmp[$invalue] = $domAttachEpgName;	// just write again here
		$themap->ucsstack=$tmp;
	    }
	    // 11-1-14: Done
	}
        if($inclass === "fvRsPathAtt" && $inattribute !== 'dn') {
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an fvRsPathAtt dn event, handling if we manage.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    //Clean up all peer EPG's...
	    foreach($themap->storage as $key => $value1) {
	        if(strpos($key,"<3>BM_ROOT") !== false)  {
		    $tmp=$themap->storage[$key];
		    unset($tmp['peerEPG']);
		    $themap->storage[$key]=$tmp;		    
	        }
	    }
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'fvRsDomAtt' && $themap->storage[get_newindex($key,'<=>','tDn')] === $themap->ucsstack['physdomainnamedn']) {	    
		    foreach($themap->storageindex_class as $key1 => $value1) {
		        if(strpos($key1,"<=>CLASS") !== false && $themap->storage[$key1] === 'fvRsPathAtt') {	    
			    if(strpos($themap->storage[get_newindex($key1,'<=>','dn')], substr($themap->storage[get_newindex($key,'<=>','dn')], 0, strpos($themap->storage[get_newindex($key,'<=>','dn')] ,'rsdomAtt-'))) !== false) {
				if($inattribute !== 'encap') return;	// breakout as we are only storing these on the encap commands (out of the repeated sends)
				$dn=$themap->storage[get_newindex($key1,'<=>','dn')];			    
				if(!strpos($dn, $themap->ucsstack['physdomainname'])) {
				    continue;	// this instance in the list was not for our domain
				}
				$pre=get_storagePrefix($key1,'<2>');
			        $fabricVlan_id=substr($themap->storage[get_newindex($key1,'<=>','encap')],5);	    
			        $tenant=preg_match("/(?<=\/tn-)(.*?)\//", $themap->storage[get_newindex($key1,'<=>','dn')], $output_array);
			        $tenant=$output_array[1];
			        $application=preg_match("/(?<=\/ap-)(.*?)\//", $themap->storage[get_newindex($key1,'<=>','dn')], $output_array);
			        $application=$output_array[1];
			        $epg=preg_match("/(?<=\/epg-)(.*?)\//", $themap->storage[get_newindex($key1,'<=>','dn')], $output_array);
			        $epg=$output_array[1];
			        $fabricVlan_name=$tenant.'-'.$application.'-'.$epg.'-P';
				$mappedTnApEpg='uni/tn-'.$tenant.'/ap-'.$application.'/epg-'.$epg;
				//echo "*****************5-18-15 ****WORKING.... validating correct entry for this epg: {$epg} name under the VLAN: {$fabricVlan_id}\n";
				$tmp=$themap->ucsstack;
				$tmp["vlan-$fabricVlan_id"]="$mappedTnApEpg";
				$themap->ucsstack=$tmp;
				//echo "****************5-18-15 ****Done.\n";
				// Here I have to truncate this to 30 bytes, and I want to create an adapter template for this with the CDN field set ***TODO***
			        if(strlen($fabricVlan_name) > 32) {
				    $fabricVlan_name=substr($fabricVlan_name, -32);
			        }
			        $fabricVlan_dn='fabric/lan/net-'.$fabricVlan_name;
				$fabricVlan_defaults=array();
			        //echo "Needs VLAN: {$fabricVlan_name} ID: {$fabricVlan_id} DN:{$fabricVlan_dn}\n";
			        $rootindex=$pre.$fabricVlan_dn.'<3>BM_ROOT';
			        if(isset($themap->storage[$rootindex]) === false) {
				    $themap->storage[$rootindex] = array("CLASS" => "fabricVlan", "peerdn"=>array($dn=>true), "fabricVlan_dn" => $fabricVlan_dn,
									 "fabricVlan_name" => $fabricVlan_name, "fabricVlan_id" =>$fabricVlan_id,
									 "mappedTnApEpg" => $mappedTnApEpg, "fabricVlan_defaults"=> $fabricVlan_defaults,
									 "Adminstate"=>"created");
				    //echo "CREATING {$rootindex} in storage\n";
				    $tmp=$themap->ucsstack;
				    $tmp["UCSVLANindex<.>$fabricVlan_name"]="$rootindex";
				    $tmp["UCSVLANarray<.>$fabricVlan_name"]=$themap->storage[$rootindex];  // here we snapshot the storage locations for this VLAN needs
				    $themap->ucsstack = $tmp;
				    //var_dump($themap->storage[$rootindex]);
			        } else {
				    //echo "UPDATING...{$rootindex}\n";
				    $tmp=$themap->storage[$rootindex];
				    //var_dump($tmp);
				    $tmp['peerdn'][$dn]=true;
				    $themap->storage[$rootindex]=$tmp;
				    //var_dump($themap->storage[$rootindex]);
			        }
				// Now do the vNIC template with just the bare metal network
				$vnicLanConnTempl_nameA=$fabricVlan_name.':A';
				if(strlen($vnicLanConnTempl_nameA) > 16) {
				    $vnicLanConnTempl_nameA=substr($vnicLanConnTempl_nameA, strlen($vnicLanConnTempl_nameA) - 16);
				}
			        $vnicLanConnTempl_dnA='org-root/lan-conn-templ-'.$vnicLanConnTempl_nameA;
				$vnicLanConnTempl_nameB=$fabricVlan_name.':B';
				if(strlen($vnicLanConnTempl_nameB) > 16) {
				    $vnicLanConnTempl_nameB=substr($vnicLanConnTempl_nameB, strlen($vnicLanConnTempl_nameB) - 16);
			        }
				$vnicLanConnTempl_dnB='org-root/lan-conn-templ-'.$vnicLanConnTempl_nameB;
				$vnicEtherIf_nameA=$fabricVlan_name;
				$vnicEtherIf_dnA=$vnicLanConnTempl_dnA.'/if-'.$vnicEtherIf_nameA;
			        $vnicEtherIf_nameB=$fabricVlan_name;
			        $vnicEtherIf_dnB=$vnicLanConnTempl_dnB.'/if-'.$vnicEtherIf_nameA;
			        $bmrootindex=$pre.$fabricVlan_dn.'<3>BM_ROOT';
				$vnicLanConnTempl_A=$pre.$vnicLanConnTempl_dnA.'<3>BM_CHILD';
				$vnicLanConnTempl_B=$pre.$vnicLanConnTempl_dnB.'<3>BM_CHILD';
				$vnicEtherIf_A=$pre.$vnicEtherIf_dnA.'<3>BM_CHILD';
				$vnicEtherIf_B=$pre.$vnicEtherIf_dnB.'<3>BM_CHILD';
				$vnicLanConnTempl_descr='Auto Created for EPG: '.$fabricVlan_name;
				if(isset($themap->flowmap[$themap->storage[$pre.$dn.'<=>FLOWMAP']]['DEST_DEFAULT'])) {
				    $fabricVlan_defaults=$themap->flowmap[$themap->storage[$pre.$dn.'<=>FLOWMAP']]['DEST_DEFAULT'];
				}
				$vnicLanConnTempl_defaults="";
				if(isset($themap->storage[$bmrootindex]) === false) {
				    //echo "BM Case, CREATING: {$dn}\n";
				    $themap->storage[$bmrootindex] = array("CLASS" => "fabricVlan", "peerdn"=>array($dn=>true), "fabricVlan_dn" => $fabricVlan_dn,
									   "fabricVlan_name" => $fabricVlan_name, "fabricVlan_id" =>$fabricVlan_id,
									   "mappedTnApEpg" => $mappedTnApEpg, "fabricVlan_defaults"=> $fabricVlan_defaults,
									   "Adminstate"=>"created");
			        } else {
				    //echo "BM Case, EXISTING: {$dn}\n";
				}
				$themap->storage[$vnicEtherIf_A] = array("CLASS" => "vnicEtherIf", "peerdn"=>array($fabricVlan_dn => true),
									 "vnicEtherIf_dn" => $vnicEtherIf_dnA, "mappedTnApEpg" => $mappedTnApEpg,
									 "vnicEtherIf_name" => $vnicEtherIf_nameA, "Adminstate"=>"created");
			        $themap->storage[$vnicEtherIf_B] = array("CLASS" => "vnicEtherIf", "peerdn"=>array($fabricVlan_dn => true),
									 "vnicEtherIf_dn" => $vnicEtherIf_dnB, "mappedTnApEpg" => $mappedTnApEpg, 
									 "vnicEtherIf_name" => $vnicEtherIf_nameB, "Adminstate"=>"created");
			        if(isset($themap->storage[$vnicLanConnTempl_A]) === false) {
				    $themap->storage[$vnicLanConnTempl_A] = array("CLASS" => "vnicLanConnTempl", "peerdn"=>array($fabricVlan_dn => true),
										  "vnicLanConnTempl_dn" => $vnicLanConnTempl_dnA,
										  "vnicLanConnTempl_descr" => $vnicLanConnTempl_descr,
										  "vnicLanConnTempl_switchId" => "A", "mappedTnApEpg" => $mappedTnApEpg,
										  "vnicLanConnTempl_name" => $vnicLanConnTempl_nameA, "Adminstate"=>"created");
				    echo date("Y-m-d H:i:s")." -> Bare Metal on UCS Needs template:{$vnicLanConnTempl_dnA} on VLAN: {$fabricVlan_name} ID: {$fabricVlan_id}\n";
			        } else {
				    $tmp=$themap->storage[$vnicLanConnTempl_A];
				    $tmp['peerdn'][$fabricVlan_dn]=true;
				    $themap->storage[$vnicLanConnTempl_A]=$tmp;
				}
				if(isset($themap->storage[$vnicLanConnTempl_B]) === false) {
				    $themap->storage[$vnicLanConnTempl_B] = array("CLASS" => "vnicLanConnTempl", "peerdn"=>array($fabricVlan_dn => true),
										  "vnicLanConnTempl_dn" => $vnicLanConnTempl_dnB,
										  "vnicLanConnTempl_descr" => $vnicLanConnTempl_descr,
										  "vnicLanConnTempl_switchId" => "B", "mappedTnApEpg" => $mappedTnApEpg, 
										  "vnicLanConnTempl_name" => $vnicLanConnTempl_nameB, "Adminstate"=>"created");
				    echo date("Y-m-d H:i:s")." -> Bare Metal on UCS Needs template:{$vnicLanConnTempl_dnB} on VLAN: {$fabricVlan_name} ID: {$fabricVlan_id}\n";
			        } else {
				    $tmp=$themap->storage[$vnicLanConnTempl_B];
				    $tmp['peerdn'][$fabricVlan_dn]=true;
				    $themap->storage[$vnicLanConnTempl_B]=$tmp;
			        }
			    }
		        }
		    }
	        }
	    }
	    //Clean up all peer EPG's...
	    //foreach($themap->storage as $key => $value) {
	    foreach($themap->storageindex_class as $key => $value) {		    
	        if(strpos($key,"<3>BM_ROOT") !== false && is_null($themap->storage[$key]['peeruuid']) === true && is_null($themap->storage[$key]['peerEPG']) === true)  {
		    $tmp=$themap->storage[$key];
		    $tmp['Adminstate']="deleted";
		    $themap->storage[$key]=$tmp;
/*		    foreach($themap->storage as $key1 => $value1) {
			if(strpos($key1,"<3>BM_CHILD") !== false && $themap->storage[$key1]['peerdn'][$themap->storage[$key]['fabricVlan_dn']] === true)  {
			    $tmp=$themap->storage[$key1];			    
			    //var_dump($tmp);
			    //echo "Counter before:".count($tmp['peerdn'])."\n";
			    unset($tmp['peerdn'][$themap->storage[$key]['fabricVlan_dn']]);
			    //echo "Counter after:".count($tmp['peerdn'])."\n";			    
			    if(count($tmp['peerdn']) === 0) {
			        $tmp['Adminstate'] = "deleted";
			    }
			    $themap->storage[$key1]=$tmp;
			    //var_dump($tmp);
		        }
		    }
		    */
	        }
	    }		
	}
        if($inclass === 'fvRsDomAtt' && $inattribute === 'tDn') {
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an fvRsDomAtt tDn event, handling if we manage.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    //echo "********OK, need to loop through UCSM domains, and check that {$invalue} matches the #0={$themap->ucsstack['physdomainnamedn']}\n";
	    if ($themap->ucsstack['physdomainnamedn'] === $invalue) {
		//echo "We have a domain match, time to do binding for domain=$invalue\n";
		$startVLAN = $themap->ucsstack['physdomainmaxvlan'];
		$finalVLAN = $themap->ucsstack['physdomainminvlan'];
		for($x=$startVLAN; $x >= $finalVLAN; $x--) {
		    $tmp=$themap->ucsstack;
		    if(isset($tmp["vlan-$x"]) == false) {
			if ($x === $themap->storage['ucsmVXLANtransportVLAN']) continue;	// 5-18-15 to avoid the VXLAN transport usage just in case
		        // Use this VLAN - but lets find just the EPG name to be the value, not the indn
		        $domArray = explode("/rsdomAtt", $indn);
		        $domAttachEpgName = $domArray[0];
		        $tmp["vlan-$x"]="$domAttachEpgName";
		        $themap->ucsstack=$tmp;
		        break;
		    }
	        }
	        echo date("Y-m-d H:i:s")." -> Mapping an EPG to the UCSM instance, and trying to utilize VLAN: {$x}, out of start={$startVLAN} and final={$finalVLAN}\n";
	        $themap->apiccallqueue[$indn]='UCSM-BindIt';
	    } else {
		// wrong one, dont do anything here
		//echo "10-30-14 TEST*****:  This domain is not of interest, moving along.\n";
	    }
	}
    }
    if($inoperation === "REMOVE_DN") {
        //In all remove_dn cases, we return a true to keep the object in memory (like when we resync), false to clean it up
	if($indn === 'uni/infra/vlanns-[UCSM_DOMAINS]-dynamic') {	
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an APIC UCSM VLAN pool removal event, handling if we manage.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    //if the keep sync is set, then resync the UCSM VLAN pool
	    foreach($themap->flowmap as $key=>$value) {		
		if($themap->flowmap[$key]['SOURCE_CLASS'] === 'fvnsVlanInstP' && $themap->flowmap[$key]['KEEP_SYNC'] ==='TRUE') {
		    foreach($themap->storage as $key1=>$value1) {
			if (strpos($key1,'<3>UCSM_VLAN_POOL') !== false) {
			    $tmp=$themap->storage[$key1];
			    $tmp["Adminstate"] = "created";
			    $themap->storage[$key1]=$tmp;
			    echo date("Y-m-d H:i:s")." -> BM Process Re-synced: {$indn}\n";
			    return true;
			}
		    }
	        }
	    }
        }
	if($indn === $themap->ucsstack['physdomainnamedn']) {	
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an APIC UCSM physical domain removal event, handling if we manage.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
	    //if the keep sync is set, then resync the UCSM physical domain
	    foreach($themap->flowmap as $key=>$value) {		    
	        if($themap->flowmap[$key]['SOURCE_CLASS'] === 'physDomP' && $themap->flowmap[$key]['KEEP_SYNC'] ==='TRUE') {
		    foreach($themap->storage as $key1=>$value1) {
			if (strpos($key1,'<3>UCSM_PHYS_DOMAIN') !== false) {
			    $tmp=$themap->storage[$key1];
			    $tmp["Adminstate"] = "created";
			    $themap->storage[$key1]=$tmp;
			    echo date("Y-m-d H:i:s")." -> BM Process Re-synced: {$indn}\n";
			    return true;
			}
		    }
	        }
	    }
        }
	if($inclass === 'fvRsDomAtt') {
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an fvRsDomAtt removal event, handling if we manage.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
            //echo "********OK, looking to remove, comparing {$themap->ucsstack['physdomainnamedn']} to {$indn}\n";
	    if (strstr($indn, $themap->ucsstack['physdomainnamedn']) != false) {
	        //echo "********OK, Time to issue the commands to remove the VPC binding to the UCS domain {$themap->ucsstack['physdomainnamedn']}********\n";
	        $startVLAN = $themap->ucsstack['physdomainmaxvlan'];
	        $finalVLAN = $themap->ucsstack['physdomainminvlan'];
	        for($x=$startVLAN; $x >= $finalVLAN; $x--) {
		    $tmp=$themap->ucsstack;
		    if(isset($tmp["vlan-$x"])) {
		        // this vlan is in use, need to check if the epg matches
		        if (strstr($indn, $tmp["vlan-$x"]) != false) {
		            $domArray = explode("/rsdomAtt", $indn);
			    $domAttachEpgName = $domArray[0];
			    // We dont clear out the memory structure for this EPG to domain and encapsulation yet, we do that after the removal is done in apic class.
			    break;
			}
		    }
		}
		$themap->apiccallqueue[$indn]='UCSM-UnBindIt';
		// Now we need to clear out the ucsstack item that has our UCSM fabricVlan items
		foreach($themap->ucsstack as $key=>$value) {
		    if (strstr($key, "UCSVLANindex<.>") != false) {
		        $keyArray = explode("<.>", $key);
		        $truncatedNetName = $keyArray[1];
		        // save these to match up on tests below determine if we remove it
		        $savedKey=$key;
		        $savedTruncatedName=$truncatedNetName;
		    }
		    if (strstr($key, "UCSVLANarray<.>") != false) {
		        // need to validate, then remove
		        $keyArray = explode("<.>", $key);
		        $truncatedNetName = $keyArray[1];
		        //echo "Domain unbinding test:  indn={$indn}, key={$key}, value={$value}, truncatedName={$truncatedNetName}\n";
		        if (strstr($savedTruncatedName, $truncatedNetName) != false) {
			    // we know these 2 objects are the same, now test for removal by dn
			    foreach($value as $vlanArrayItem=>$nextVal) {
			        //echo "TESTING......... value=$value, vlanArrayItem=$vlanArrayItem, nextVal=$nextVal\n";
			        if ($vlanArrayItem == "peerEPG") {
				    foreach($nextVal as $epgDn=>$presentEPG) {
				        //echo "TESTING2...... epgDn=$epgIndex, presentEPG=$presentEPG\n";
				        $peerArray = explode("/rspathAtt", $epgDn);
				        $peerDn = $peerArray[0];	// this is now the EPG name
				        //echo "*******peerDn = $peerDn\n";
				        if (strstr($indn, $peerDn) != false) {
					    $tmp = $themap->ucsstack;
					    unset($tmp[$savedKey]);
					    unset($tmp[$key]);
					    $themap->ucsstack = $tmp;
					    break;
					}
				    }
				    break;
				}
			    }
			}
		    }
		}
	    }
	}
	if ($inclass === "fvRsPathAtt") {
	    $myLogMsg="********8-23-15 Specific inside BM:  Received an fvRsPathAtt removal event, handling if we manage.\n";
	    if ($themap->storage['logBareMetaloperations']) file_put_contents("bmMessages.txt", $myLogMsg, FILE_APPEND);
    	    $startVLAN = $themap->ucsstack['physdomainmaxvlan'];
	    $finalVLAN = $themap->ucsstack['physdomainminvlan'];
	    for($x=$startVLAN; $x >= $finalVLAN; $x--) {
		$tmp=$themap->ucsstack;
		if(isset($tmp["vlan-$x"])) {
		    // compare the value (EPG) to the indn to see if there
		    if (strstr($indn, $tmp["vlan-$x"]) != false) {
			// this EPG in is bound to this UCSM domain, now re-sync
			$themap->apiccallqueue[$indn]='UCSM-BindIt';
			echo date("Y-m-d H:i:s")." -> BM Process Re-synced: {$indn}\n";
			return true;	// 5-19-15
			break;
		    } 
		} else {
		    // This was removed when the domain unmapped, and the unbinding happened in the domain remove_dn, so we need to set the UCSM side
		    foreach($themap->storage as $key => $value) {
		        if(strpos($key, "<3>BM_")) {	    
			    $tmp = $themap->storage[$key];
			    if(strstr($indn, $tmp['mappedTnApEpg']) != NULL) {
				$tmp["Adminstate"] = "deleted";
				$themap->storage[$key] = $tmp;
			    }
			}
		    }
		    break;
		}
	    }
	}
	if($inclass === "fvTenant") {
	    // We need to remove an APIC Tenant from storage, and nuke things underneath *unless the BASTARD is signalled
	    //set($themap->storage["tn-$indn"]);
	    //echo "********OK, need to remove tenant of {$indn} from APIC memory, and all underlying app profile's, EPG's, and Domain Attachments will get thier own removal message.\n";
	    $tmp=$themap->apicstack;
	    unset($tmp["$indn"]);
	    $themap->apicstack=$tmp;
	}
	if($inclass === "fvAp") {
	    // We need to remove an APIC app profile from storage, and nuke things underneath *unless the BASTARD is signalled
	    //echo "********OK, need to remove app profile of {$indn} from APIC memory, and all underlying EPGs and Domain Attachments will get thier own removals.\n";
	    $tmp=$themap->apicstack;
	    unset($tmp["$indn"]);
	    $themap->apicstack=$tmp;
	}
	if($inclass === "fvAEPg") {
	    // We need to remove an APIC EPG from storage, and nuke any binding if this is there *unless the fvRsDomAtt removal above is already called
	    //echo "********OK, need to remove and EPG of {$indn} from APIC memory, and all underlying Domain Attachments will get thier own removals.\n";
	    $tmp=$themap->apicstack;
	    unset($tmp["$indn"]);
	    $themap->apicstack=$tmp;
	}
	//Remainder of the cases in a generic check and restoral if needed
        //Remove object at destination if source gets deleted.
        foreach($themap->storage as $key => $value) {
	    if(isset($themap->storage[$key]['peerEPG'])) {
		if(isset($themap->storage[$key]['peerEPG'][$indn])) {
		    if($themap->storage[$key]['peerEPG'][$indn] === true) {	    
		        $tmp=$themap->storage[$key];
		        unset($tmp["peerEPG"][$indn]);
			echo date("Y-m-d H:i:s")." -> BM Process has removed the peerEPG of {$indn} for key {$key}, will remove if no more peerEPG and nor more peerdn's\n"; 
		        $themap->storage[$key]=$tmp;
		        if(count($themap->storage[$key]['peerEPG']) === 0 && is_null($themap->storage[$key]['peerdn']) == true) {
			    $tmp=$themap->storage[$key];
			    $tmp["Adminstate"] = "deleted";
			    $themap->storage[$key]=$tmp;
			}
		    }
		}
	    }
        }
        $index="";
        //Resync if Destination object got deleted, and the force resync is true
	$sync=false;
        foreach($themap->storage as $key => $value) {
            if(strpos($key, '<2>'.$indn.'<3>BM_') > 1 ) {
	        $index=$key;
		if(isset($themap->storage[$key]['peerdn'])) {
		    foreach($themap->storage[$key]['peerdn'] as $key1=>$value1) {
		        foreach($themap->storage as $key2=>$value2) {
			    if(strpos($key2, "<2>".$key1."<=>FLOWMAP")) {
			        if(is_null($themap->flowmap[$themap->storage[$key2]]['KEEP_SYNC']) !== true && $themap->flowmap[$themap->storage[$key2]]['KEEP_SYNC'] === "TRUE") {
				    $sync=true;
				    break 3;
				} 
			    }
			}
		    }
		}
	        break;
            }
	}
	if($index !== "") {
            if($sync == true) {
	        $tmp=$themap->storage[$index];
	        $tmp["Adminstate"] = "created";
	        $themap->storage[$index]=$tmp;
	        if(strpos($index, "BM_ROOT") > -1) {
	            echo date("Y-m-d H:i:s")." -> BM Process Re-Synched Parent: {$index} with indn:{$indn}\n";
	            foreach($themap->storage as $key => $value1) {	// cycle through the children dependant on this parent (i.e. templates dependant on this VLAN backing an EPG)
			if(isset($themap->storage[$key]['vnicEtherIf_name'])) {		// first case is adapter template that used a VLAN - add the VLAN back in
			    if(strpos($key, "BM_CHILD") > 1 && strpos($indn, $themap->storage[$key]['vnicEtherIf_name']) > 1) {
			        echo date("Y-m-d H:i:s")." -> BM Process Also Re-Synced Child: {$key}\n";
			        $tmp=$themap->storage[$key];
			        $tmp["Adminstate"] = "created";
			        // 9-1-15 and add the peerdn entry back for this VLAN
				$tmp["peerdn"][get_dn($index, '<2>', '<3>')]=true;
				$themap->storage[$key]=$tmp;
			    }
			}
	            }
	        } else {
	            echo date("Y-m-d H:i:s")." -> BM Process Re-Synched Child: {$index}\n";
	        }
		return true;
	    } else {
		echo date("Y-m-d H:i:s")." -> BM Process did not try to re-sync {$indn} of class {$inclass}\n";
	    }
        }	
	return false;
    }
}

//Main function for the VPC user story
function VPC(&$themap, $inclass, $indn, $inattribute, $invalue, $insystem, $inoperation) {
    date_default_timezone_set('UTC');
    //echo "VPC GOT: {$inclass} -- {$indn} -- {$inattribute} -- {$invalue} -- {$insystem} -- {$inoperation}\n";

    if($inoperation === "DOER") {
	if($inclass === "lacpLagPol")  {
	    $myLogMsg=date("Y-m-d H:i:s")." -> Entry to VPC {$inclass}:  indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    $pinPolicyName=$themap->storage['ucsvSwitchPol'];
	    if ($indn === 'uni/infra/lacplagp-'.$pinPolicyName) {
		$classText="AEP_UCSVSW_PINPOLICY";
		$myMode="mac-pin";
		$foundSyntheticObject=false;
		foreach($themap->storageindex_class as $key => $value) {
		    if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		        $foundSyntheticObject=true;
			$pre=get_storagePrefix($key,'<2>');
		        $storageIndex=$pre.$indn.'<3>'.$classText;
			break;
		    }
		}
		if (!$foundSyntheticObject) {
		    $pre=$themap->apicstack['APIC_IP']."<1>".$themap->ucsstack['VIP']."<2>";
		    $storageIndex=$pre.$indn."<3>".$classText;
		}
		if (isset($themap->storage[$storageIndex]) === false) {
		    $themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		    $myLogMsg=date("Y-m-d H:i:s")." -> Set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		    if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
		}
	    } elseif($indn === ('uni/infra/lacplagp-'.$themap->ucsstack['physdomainname'])) {
		$myMode="active";
		// need a pre, and create the storageIndex to set
		foreach($themap->storageindex_class as $key => $value) {
		    if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, ($indn.'<=>')) > -1) {
			$pre=get_storagePrefix($key,'<2>');
			$storageIndex=get_swappednewindex($key,'<1>','<2>','<=>','<3>VPC_CHILD');
			//echo "TEST....  now the storageIndex is: {$storageIndex}\n";
			break;
		    }
		}
	    } else {
		return;
	    }
	    // now if we received a message of mode change, then reset to allow if KEEP_SYNC is true
	    $sync = false;
	    if($themap->flowmap[$themap->storage[$pre.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") $sync=true;
	    if(($inattribute === "mode" && $invalue !== $myMode) && ($sync)) {
		$tmp=$themap->storage[$storageIndex];
		$tmp["Adminstate"] = "modified";		// we re-assert the need to write this in the synthetic object
		$themap->storage[$storageIndex]=$tmp;
	        echo date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
	    }
	    return;
	}
	if($inclass === "infraAttEntityP") {
	    $classText="AEP_UCS_SYSTEMS";
	    $myLogMsg=date("Y-m-d H:i:s")." -> Entry to VPC {$inclass}:  indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    $AEPname=$themap->storage['ucsDomainsAEP'];
	    if (strstr($indn, $AEPname) == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key,'<2>');
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->apicstack['APIC_IP']."<1>".$themap->ucsstack['VIP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg=date("Y-m-d H:i:s")." -> Set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    return;
	}
	if($inclass === "dhcpRelayP") {
	    $classText="DHCP_RELAY_TO_APIC";
	    $myLogMsg=date("Y-m-d H:i:s")." -> Entry to VPC {$inclass}:  indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "B2G-vShield-DHCP-relay") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
	            $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key,'<2>');
	            $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
	        }
	    }
	    if (!$foundSyntheticObject) {
	        $pre=$themap->apicstack['APIC_IP']."<1>".$themap->ucsstack['VIP']."<2>";
	        $storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
	        $themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
	        $myLogMsg=date("Y-m-d H:i:s")." -> Set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
	        if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    // We look at changed in future to reset here***********
	    return;
	}
	if($inclass === "dhcpLbl") {
	    $classText="DHCP_RELAY_LBL_TO_APIC";
	    $myLogMsg=date("Y-m-d H:i:s")." -> Entry to VPC {$inclass}:  indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "B2G-vShield-DHCP-relay") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=get_storagePrefix($key,'<2>');
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
	        }
	    }
	    if (!$foundSyntheticObject) {
	        $pre=$themap->apicstack['APIC_IP']."<1>".$themap->ucsstack['VIP']."<2>";
	        $storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
	        $themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
	        $myLogMsg=date("Y-m-d H:i:s")." -> Set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
	        if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	    return;
	}
	if($inclass === "fabricEthLanPcEp" && $inattribute === "switchId") {
	    $myLogMsg=date("Y-m-d H:i:s")." -> Entry to VPC {$inclass}:  indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
	    $sim0node=0;
	    $sim1node=0;
	    $nodeCount=0;
	    $nodes = array();
	    $nodeportArray = array();	// 6-18-15 Initialize the Port Array
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'fabricEthLanPc') {
		    $needle_dn=get_dn($key,'<2>','<=>');			// this is the dn for this particular key
		    $myLogMsg=date("Y-m-d H:i:s")." -> We have a class [{$inclass}] match with the key: {$key}, comparing its dn: {$needle_dn} to indn: {$indn}\n";
		    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
		    if (!compare_dn($indn, $needle_dn)) continue;	// we are not looking at the right key for this VPC endpoint indn
		    $myLogMsg=date("Y-m-d H:i:s")." -> The compare was true\n";
		    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
		    foreach($themap->storageindex_class as $key1 => $value1) {
			if ($themap->storage[$key1] === 'fabricEthLanPcEp') {
			    $myLogMsg=date("Y-m-d H:i:s")." -> Found a fabricEthLanPcEp at key1: {$key1}\n";
			    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			    // this is the dn of the item we are searching to look for the fabricEthLanPcEp (and hope to find the Needle in)
			    if(($haystack_dn=$themap->storage[get_newindex($key1,'<=>','dn')]) === NULL) continue;
			    $myLogMsg=date("Y-m-d H:i:s")." -> Testing match and looking for needle_dn: [{$needle_dn}] in haystack_dn: [{$haystack_dn}]\n";
			    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			}
		        if(strpos($key1,"<=>CLASS") !== false && $themap->storage[$key1] === 'fabricEthLanPcEp' && compare_dn($haystack_dn, $needle_dn)) {		    
			    $myLogMsg=date("Y-m-d H:i:s")." -> We have found this needle in the haystack\n";
			    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			    $pre=get_storagePrefix($key1,'<2>');
			    $switchIdPc = $themap->storage[get_newindex($key,'<=>','switchId')];
			    $switchId = $themap->storage[get_newindex($key1,'<=>','switchId')];
			    $myLogMsg=date("Y-m-d H:i:s")." -> We just gathered the PC switchId={$switchIdPc}, and the PcEp switchId={$switchId}, will break out if PcEp not set to A or B\n";
			    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			    // on startup, this might not be filled yet - but will be otherwise, so check and continue if not
			    if (!($switchId === 'A' || $switchId === 'B')) continue;
			    $mgmtIP = $themap->ucsstack['IP_'.$switchId];
			    $fabricEthLanPc_portId = $themap->storage[get_newindex($key,'<=>','portId')];
			    $index=array();
			    $tmpindex=get_storage_key($themap, 'lldpAdjEp', 'mgmtIp', $mgmtIP);	// this will be empty when using a simulator environment as there are no real cables nor LLDP
			    $myLogMsg=date("Y-m-d H:i:s")." -> We just gathered many things as:  switchId={$switchId}, mgmtIP={$mgmtIP}, portId=pc-{$fabricEthLanPc_portId}\n";
			    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			    ob_start();
			    var_dump($tmpindex);
			    $myLogMsg=date("Y-m-d H:i:s")." -> Looking at tmpindex (if empty we could not find LLDP entry for {$mgmtIP} in memory from APIC - UCS Uplink Ports not set?  Using simulator?):\n";
			    $myLogMsg.=ob_get_clean();
			    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			    $vpcports=get_vpcports_from_UCS($themap, $themap->storage[get_newindex($key,'<=>','dn')]);
			    ob_start();
			    $myLogMsg=date("Y-m-d H:i:s")." -> Ran through get_vpcports_from_UCS for fabric {$switchId}, vardump of the vpcports object returned:\n";
			    var_dump($vpcports);
			    $myLogMsg.=ob_get_clean();
			    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			    $dnArray=explode('/',$themap->storage[get_newindex($key,'<=>','dn')]);
			    $tmpVPCid=$dnArray[3];
			    foreach($vpcports[$themap->ucsstack['physdomainname']] as $fabricKey=>$fabricObject) {
				// 8-1-15 this is only used in simulator environments
				$simulatedNodeCounter=0;
				$simulatedIfAdder=-1;
				if ($switchId === "A") {
				    $startIfCounter=$themap->storage['simFIAPortSeed'];
				} else {
				    $startIfCounter=$themap->storage['simFIBPortSeed'];
				}
				foreach($themap->nodelist as $nodeKey=>$nodeVal) {
				    if (strpos($nodeKey, "<=>NAME") > 0) {
					if ($simulatedNodeCounter == 0) {
					    $sim0node=$nodeVal;
					} else {
					    $sim1node=$nodeVal;
					    break;
					}
					$simulatedNodeCounter++;
				    }
				}
				// 8-1 end of this section of simulator setup
			        foreach($fabricObject[$tmpVPCid] as $intCount=>$interface) {
				    if ($themap->storage['realEnvironment']) {
					foreach($tmpindex as $key2=>$value2) {
					    if($themap->storage[$key2.'<=>portDesc'] ===  $interface) {
					        $myLogMsg=date("Y-m-d H:i:s")." -> FOUND A LLDP MATCH on APIC interfaces in {$key2}, pointing to the FI at: {$mgmtIP}:{$interface}\n";
					        if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					        $tmpdn=substr($key2, strpos($key2, '<2>') + 3);
						// 8-22-15 work to move the node capture stuff earlier
						preg_match("/(?<=\/node-)(.*?)\//", $tmpdn, $apic_node);
						preg_match("/\[eth1\/(.*?)\]/", $tmpdn, $apic_remoteInterface);
						$myLogMsg=date("Y-m-d H:i:s")." -> Checking if we need to add node {$apic_node[1]} to the nodes array";
						if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
						if(in_array($apic_node[1], $nodes) == false) {
						    $nodes[$nodeCount]=$apic_node[1];
						    $myLogMsg=" -- this was not there, so adding this node to location {$nodeCount}\n";
						    $nodeCount++;
						} else {
						    $myLogMsg=" -- this was already there\n";
						}
						if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
						// 8-22-15 now we fill the node port array
						$myLogMsg=date("Y-m-d H:i:s")." -> Mapping LLDP at port level, just set the ports entry key: {$interface} to port: ".$apic_remoteInterface[1]." on node: ".$apic_node[1]."\n";
						if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
						array_push($nodeportArray, $apic_node[1].'<=>'.$apic_remoteInterface[1].'<=>'.$interface);
						// 8-22-15 Now we check if the tmpdn and key2 are already in the VPC root object and if so dont add it here
						$tmprootindex=$pre.'uni/infra/accportprof-'.$themap->ucsstack['physdomainname'].'-'.$switchId.'<3>VPC_ROOT';
						if(isset($themap->storage[$tmprootindex]['peerLLDP'][$tmpdn]) == false) {
						    $index[$tmpdn]=$key2;
						    $myLogMsg=date("Y-m-d H:i:s")." -> Set indexKey: {$tmpdn}, to: {$key2}\n";
						    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
						} else {
						    $myLogMsg=date("Y-m-d H:i:s")." -> We already stored this key in the VPC_ROOT peerLLDP array: {$tmpdn} so not adding\n";
						    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
						}
						// end of the 8-22-15 slimming down and array merges for faster operation
					    }
					}
				    } else {
					// 8-1-15 we are in a APIC and UCSM simulator environment - only do stuff if this is the key VPC ID
					$myLogMsg=date("Y-m-d H:i:s")." -> Ready to write the VPC entities on APIC SIM, but only if the reported VPC id:{$tmpVPCid} matches vpcKey:pc-{$themap->storage['simVPCkey']}\n";
					if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					if ($tmpVPCid == 'pc-'.$themap->storage['simVPCkey']) {
					    if ($intCount & 1) {    // intcount is odd
					        // if odd, then write to sim1node and increment addr
					        $nodeNeeded=$sim1node;
					    } else {
					        // if 0 or even, then increment adder and write to sim0 node
					        $simulatedIfAdder++;
					        $nodeNeeded=$sim0node;
					    }
					    $myLogMsg=date("Y-m-d H:i:s")." -> Checking if we need to add node {$nodeNeeded} to the nodes array";
					    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					    if(in_array($nodeNeeded, $nodes) == false) {
						$nodes[$nodeCount]=$nodeNeeded;
						$myLogMsg=" -- this was not there, so adding this node to location {$nodeCount}\n";
						$nodeCount++;
					    } else {
						$myLogMsg=" -- this was already there\n";
					    }
					    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					    $port2use=$startIfCounter+$simulatedIfAdder;
					    $mockKey2 = $themap->apicstack['APIC_IP']."<1>".$themap->ucsstack['VIP'];
					    $mockKey2 .= "<2>/topology/pod-1/node-".$nodeNeeded."/sys/lldp/inst/if-[eth1/".$port2use."]/adj-1";
					    $themap->storageindex_class[$mockKey2.'<=>simulatedLink'] = $switchId.'<->'.$tmpVPCid.'<->'.$interface.'<->'.$nodeNeeded.'<->'.$port2use;
					    $themap->storage[$mockKey2.'<=>portDesc'] = $interface;			// write this for later usage in other areas of program
					    $themap->storage[$mockKey2.'<=>mgmtIP'] = $mgmtIP;				// write this for later usage in other areas of program
					    $themap->storage[$mockKey2.'<=>sysName'] = $fabricKey;			// write this for later usage in other areas of program
					    $themap->storage[$mockKey2.'<=>CLASS'] = 'lldpAdjEp';			// write this for later usage in other areas of program
					    $tmpdn=substr($mockKey2, strpos($mockKey2, '<2>') + 3);
					    // 8-22-15 now we fill the node port array
					    $myLogMsg=date("Y-m-d H:i:s")." -> Mapping LLDP at port level, just set the ports entry key:{$interface} to:{$port2use} on node:{$nodeNeeded}\n";
					    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					    array_push($nodeportArray, $nodeNeeded.'<=>'.$port2use.'<=>'.$interface);
					    // 8-22-15 Now we check if the tmpdn and mockKey2 are already in the VPC root object and if so dont add it here
					    $tmprootindex=$pre.'uni/infra/accportprof-'.$themap->ucsstack['physdomainname'].'-'.$switchId.'<3>VPC_ROOT';
					    if(isset($themap->storage[$tmprootindex]['peerLLDP'][$tmpdn]) == false) {
						$index[$tmpdn]=$mockKey2;
						$myLogMsg=date("Y-m-d H:i:s")." -> Set SIMULATOR ITEM indexKey: {$tmpdn}, to: {$mockKey2}\n";
						if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					    } else {
						$myLogMsg=date("Y-m-d H:i:s")." -> We already stored SIMULATOR ITEM in the VPC_ROOT peerLLDP array: {$tmpdn} so not adding\n";
						if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					    }	// end of the 8-22-15 slimming down and array merges for faster operation
					    // 8-1-15 this part done
					}
				    }
				}
			    }
			    unset($tmpindex);
			    if(count($index) === 0) {
			        unset($index);
			        break;
			    }
			    if(count($index) > 0) {
			        $UCS_domain=$themap->ucsstack['physdomainname'];
			        $infraAccPortP_name=$themap->ucsstack['physdomainname'].'-'.$switchId;
			        $themap->ucsstack['fabric'.$switchId.'name']=$infraAccPortP_name;	// 6-18-15
			        $themap->ucsstack['fabric'.$switchId.'vpc']='pc-'.$fabricEthLanPc_portId; // 6-18-15
			        $infraAccPortP_dn='uni/infra/accportprof-'.$infraAccPortP_name;
			        $infraAccPortP_rn='accportprof-'.$infraAccPortP_name;
			        $infraAccPortP_url='uni/infra/accportprof-'.$infraAccPortP_name;
			        $infraHPortS_name='TO_FI-'.$switchId.':VPC-'.$fabricEthLanPc_portId;
				$infraHPortS_pcID=$fabricEthLanPc_portId;
			        $infraHPortS_dn=$infraAccPortP_dn.'/hports-'.$infraHPortS_name.'-typ-range';
			        $infraHPortS_rn='hports-'.$infraHPortS_name.'-typ-range';
			        $infraHPortS_url=$infraAccPortP_dn.'/hports-'.$infraHPortS_name.'-typ-range';
				$lacpLagPol_name="UCS_Fabric_Interconnect_Links";
			        $lacpLagPol_dn='uni/infra/lacplagp-'.$lacpLagPol_name;
			        $lacpLagPol_rn='lacplagp-'.$lacpLagPol_name;
			        $lacpLagPol_url='uni/infra/lacplagp-'.$lacpLagPol_name;
			        $lacpLagPol_mode="active";
			        $lacpLagPol_ctrl="";
			        $infraAccBndlGrp_name=$themap->ucsstack['physdomainname'].'-'.$switchId;
			        $infraAccBndlGrp_dn='uni/infra/funcprof/accbundle-'.$infraAccBndlGrp_name;
			        $infraAccBndlGrp_rn='accbundle-'.$infraAccBndlGrp_name;
			        $infraAccBndlGrp_url='uni/infra/funcprof/accbundle-'.$infraAccBndlGrp_name;
			        $infraAccBndlGrp_tnLacpLagPolName=$lacpLagPol_name;
			        $infraRsAccBaseGrp_tDn='uni/infra/funcprof/accbundle-'.$infraAccBndlGrp_name;
			        $rootindex=$pre.$infraAccPortP_dn.'<3>VPC_ROOT';
			        if(isset($themap->storage[$rootindex]) === false) {
			            $themap->storage[$rootindex] = array("CLASS" => "infraAccPortP", "infraAccPortP_UCS_domain"=>$UCS_domain, "infraAccPortP_UCS_fabric"=>$switchId,
									 "peerLLDP"=>$index, "infraAccPortP_dn" => $infraAccPortP_dn, "infraAccPortP_name" => $infraAccPortP_name,
									 "infraAccPortP_rn" => $infraAccPortP_rn, "infraAccPortP_url"=>$infraAccPortP_url, "Adminstate"=>"created");
			            //echo "CREATING {$rootindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Creating {$rootindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				} else {
				    //echo "UPDATING...{$rootindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Updating {$rootindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				    $tmp=$themap->storage[$rootindex];
				    $tmp['peerLLDP']=array_merge($tmp['peerLLDP'],$index);
				    $themap->storage[$rootindex]=$tmp;
				}
				$interfaceProfileindex=$pre.$infraHPortS_dn.'<3>VPC_CHILD';
				if(isset($themap->storage[$interfaceProfileindex]) === false) {
				    $themap->storage[$interfaceProfileindex] = array("CLASS" => "infraHPortS", "peerLLDP"=>$index, "infraHPortS_dn" => $infraHPortS_dn, "infraHPortS_pcID"=>$infraHPortS_pcID,
										     "infraHPortS_UCS_domain"=>$UCS_domain, "infraHPortS_UCS_fabric"=>$switchId,
										     "infraHPortS_name" => $infraHPortS_name, "infraHPortS_rn" => $infraHPortS_rn, "infraHPortS_url"=>$infraHPortS_url,
										     'infraRsAccBaseGrp_tDn'=>$infraRsAccBaseGrp_tDn, "Adminstate"=>"created");
				    //echo "CREATING {$interfaceProfileindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Creating {$interfaceProfileindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				} else {
				    //echo "UPDATING...{$interfaceProfileindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Updating {$interfaceProfileindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				    $tmp=$themap->storage[$interfaceProfileindex];				
				    $tmp['peerLLDP']=array_merge($tmp['peerLLDP'],$index);
				    $themap->storage[$interfaceProfileindex]=$tmp;				
				}
				foreach($index as $key2=>$value2) {
				    preg_match("/\[eth1\/(.*?)\]/", $value2, $apic_remoteInterface);
				    preg_match("/(?<=\/node-)(.*?)\//", $value2, $apic_remoteNode);
				    $infraPortBlk_fromPort=$apic_remoteInterface[1];
				    $infraPortBlk_toPort=$infraPortBlk_fromPort;
				    $ucsportId=$themap->storage[$value2.'<=>portDesc'];
				    $infraPortBlk_name=str_replace('/',':', $ucsportId);
				    $infraPortBlk_dn=$infraHPortS_dn.'/portblk-'.$infraPortBlk_name;
				    $infraPortBlk_rn='portblk-'.$infraPortBlk_name;
				    $infraPortBlk_url=$infraHPortS_dn.'/portblk-'.$infraPortBlk_name;
				    $infraPortBlk_UCS_port=$ucsportId;
				    $infraPortBlk_leafNode=$apic_remoteNode[1];
				    $portindex=$pre.$infraPortBlk_dn.'<3>VPC_CHILD';
				    if(isset($themap->storage[$portindex]) === false) {
				        $themap->storage[$portindex] = array("CLASS" => "infraPortBlk", "peerLLDP"=>$index, "infraPortBlk_dn" => $infraPortBlk_dn, "infraPortBlk_rn" => $infraPortBlk_rn,
									     "infraPortBlk_url"=>$infraPortBlk_url, "infraPortBlk_fromPort"=>$infraPortBlk_fromPort,
									     "infraPortBlk_toPort"=>$infraPortBlk_toPort, "infraPortBlk_UCS_domain"=>$UCS_domain, "infraPortBlk_UCS_fabric"=>$switchId,
									     "infraPortBlk_UCS_port"=>$infraPortBlk_UCS_port, "infraPortBlk_leafNode"=>$infraPortBlk_leafNode, "Adminstate"=>"created");
				        //echo "CREATING {$portindex}\n";
				        $myLogMsg=date("Y-m-d H:i:s")." -> Creating {$portindex}\n";
				        if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				    } else {
				        //echo "UPDATING...{$portindex}\n";
				        $myLogMsg=date("Y-m-d H:i:s")." -> Updating {$portindex}\n";
				        if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				        $tmp=$themap->storage[$portindex];
					$tmp['peerLLDP']=array_merge($tmp['peerLLDP'],$index);
				        $themap->storage[$portindex]=$tmp;
				    }
				}
				// 6-18-15 Write it and signal for it to map along with AEP to the UCSM domain for the filter ports
				$newKey='fabric'.$switchId.'vpc-'.$fabricEthLanPc_portId.'-nodes-ports';
				$themap->ucsstack[$newKey]=$nodeportArray;
				foreach($themap->storageindex_class as $key2 => $value2) {
				    if(strpos($key2,"<=>CLASS") !== false && $themap->storage[$key2] === 'infraAccBndlGrp' && strpos($key2, $infraAccPortP_name) > -1) {
				        $keyStart=get_swappedstoragePrefix($key2,'<1>','<2>');
				        $myDn=get_dn($key2,'<2>','<=>');
				        $tempKey=$keyStart.$myDn.'<3>VPC_CHILD';
				        //echo "*****TESTTEST - keystart:{$keyStart}, mydn:{$myDn}, tempKey:{$tempKey}, isset={isset($themap->storage[$tempKey])}\n";
				        if (isset($themap->storage[$tempKey]) !== false) {
				            $tmp=$themap->storage[$tempKey];
				            $tmp['Adminstate']='created';
				            $themap->storage[$tempKey]=$tmp;					    
				            //echo "-----Adding the ACI Leaf ports to AEP filter for UCS Domain at {$infraAccPortP_name}\n";
					    $myLogMsg=date("Y-m-d H:i:s")." -> Adding the ACI Leaf ports to AEP filter for UCS Domain at {$infraAccPortP_name}\n";
					    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					    break;
					}
				    }
				}
				// 6-18-15 end
				$lacpProfileindex=$pre.$lacpLagPol_dn.'<3>VPC_CHILD';
				if(isset($themap->storage[$lacpProfileindex]) === false) {
				    $themap->storage[$lacpProfileindex] = array("CLASS" => "lacpLagPol", "peerLLDP"=>$index, "lacpLagPol_dn" => $lacpLagPol_dn, "lacpLagPol_name" => $lacpLagPol_name,
										"lacpLagPol_rn" => $lacpLagPol_rn, "lacpLagPol_url"=>$lacpLagPol_url, "lacpLagPol_mode"=>$lacpLagPol_mode,
										"lacpLagPol_ctrl"=>$lacpLagPol_ctrl, "Adminstate"=>"created");
				    //echo "CREATING {$lacpProfileindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Creating {$lacpProfileindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				} else {
				    //echo "UPDATING...{$lacpProfileindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Updating {$lacpProfileindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				    $tmp=$themap->storage[$lacpProfileindex];				
				    $tmp['peerLLDP']=array_merge($tmp['peerLLDP'],$index);
				    $themap->storage[$lacpProfileindex]=$tmp;				
				}
				$infraAccBndlGrpindex=$pre.$infraAccBndlGrp_dn.'<3>VPC_CHILD';
				if(isset($themap->storage[$infraAccBndlGrpindex]) === false) {
				    $themap->storage[$infraAccBndlGrpindex] = array("CLASS" => "infraAccBndlGrp", "peerLLDP"=>$index, "infraAccBndlGrp_dn" => $infraAccBndlGrp_dn,
										    "infraAccBndlGrp_name" => $infraAccBndlGrp_name, "infraAccBndlGrp_rn" => $infraAccBndlGrp_rn,
										    "infraAccBndlGrp_url"=>$infraAccBndlGrp_url, "infraAccBndlGrp_tnLacpLagPolName"=>$infraAccBndlGrp_tnLacpLagPolName,
										    "infraAccBndlGrp_UCS_fabric"=>$switchId, "Adminstate"=>"created");
				    //echo "CREATING {$infraAccBndlGrpindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Creating {$infraAccBndlGrpindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				} else {
				    //echo "UPDATING...{$infraAccBndlGrpindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Updating {$infraAccBndlGrpindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				    $tmp=$themap->storage[$infraAccBndlGrpindex];				
				    $tmp['peerLLDP']=array_merge($tmp['peerLLDP'],$index);
				    $themap->storage[$infraAccBndlGrpindex]=$tmp;				
				}
				$infraNodeP_name=$themap->ucsstack['physdomainname'].'-'.$switchId.':VPC-'.$fabricEthLanPc_portId;
				$infraNodeP_dn='uni/infra/nprof-'.$infraNodeP_name;
				$infraNodeP_rn='nprof-'.$infraNodeP_name;
				$infraNodeP_url='uni/infra/nprof-'.$infraNodeP_name;
				$infraLeafS_name=$switchId.':VPC-'.$fabricEthLanPc_portId;
				$infraLeafS_dn=$infraNodeP_dn.'/leaves-'.$infraLeafS_name.'-typ-range';
				$infraLeafS_rn='leaves-'.$infraLeafS_name.'-typ-range';
				$infraLeafS_type='range';
				$infraRsAccPortP_tDn='uni/infra/accportprof-'.$infraAccPortP_name;
				$infraRsAccPortP_dn='uni/infra/nprof-root/rsaccPortP-['.$infraRsAccPortP_tDn.']';
				$infraNodePindex=$pre.$infraNodeP_dn.'<3>VPC_CHILD';
				if(isset($themap->storage[$infraNodePindex]) === false) {
				    $themap->storage[$infraNodePindex] = array("CLASS" => "infraNodeP", "peerLLDP"=>$index, "infraNodeP_dn" => $infraNodeP_dn, "infraNodeP_name" => $infraNodeP_name,
									       "infraNodeP_rn" => $infraNodeP_rn, "infraNodeP_url"=>$infraNodeP_url,"infraLeafS_dn" => $infraLeafS_dn,
									       "infraLeafS_name" => $infraLeafS_name, "infraLeafS_rn" => $infraLeafS_rn, "infraLeafS_type"=>$infraLeafS_type,
									       "infraRsAccPortP_dn"=>$infraRsAccPortP_dn,"infraRsAccPortP_tDn"=>$infraRsAccPortP_tDn, "Adminstate"=>"created");					
				    //echo "CREATING {$infraNodePindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Creating {$infraNodePindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				} else {				
				    //echo "UPDATING...{$infraNodePindex}\n";
				    $myLogMsg=date("Y-m-d H:i:s")." -> Updating {$infraNodePindex}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				    $tmp=$themap->storage[$infraNodePindex];				
				    $tmp['peerLLDP']=array_merge($tmp['peerLLDP'],$index);
				    $themap->storage[$infraNodePindex]=$tmp;				
				}
				foreach($index as $key2=>$value2) {
				    preg_match("/(?<=\/node-)(.*?)\//", $index[$key2], $apic_node);
				    $infraNodeBlk_name='Node-'.$apic_node[1].':VPC-'. $fabricEthLanPc_portId;
				    $infraNodeBlk_dn=$infraLeafS_dn.'/nodeblk-'.$infraNodeBlk_name;
				    $infraNodeBlk_rn='nodeblk-'.$infraNodeBlk_name;
				    $infraNodeBlk_url=$infraLeafS_dn.'/nodeblk-'.$infraNodeBlk_name;
				    $infraNodeBlk_from_=$apic_node[1];
				    $infraNodeBlk_to_=$apic_node[1];
				    $infraNodeBlkindex=$pre.$infraNodeBlk_dn.'<3>VPC_CHILD';
				    if(isset($themap->storage[$infraNodeBlkindex]) === false) {
				        $themap->storage[$infraNodeBlkindex] = array("CLASS" => "infraNodeBlk", "peerLLDP"=>array($key2=>$value2), "infraNodeBlk_dn" => $infraNodeBlk_dn,
										     "infraNodeBlk_name" => $infraNodeBlk_name, "infraNodeBlk_rn" => $infraNodeBlk_rn,
										     "infraNodeBlk_url"=>$infraNodeBlk_url, "infraNodeBlk_from_"=>$infraNodeBlk_from_,
										     "infraNodeBlk_to_"=>$infraNodeBlk_to_,"infraRsAccPortP_dn"=>$infraRsAccPortP_dn,
										     "infraRsAccPortP_tDn"=>$infraRsAccPortP_tDn, "Adminstate"=>"created");					
				        //echo "CREATING {$infraNodeBlkindex} -- {$infraNodeBlk_from_}\n";
				        $myLogMsg=date("Y-m-d H:i:s")." -> Creating {$infraNodeBlkindex}\n";
				        if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				    } else {				
				        //echo "UPDATING...{$infraNodeBlkindex} -- {$infraNodeBlk_from_}\n";
				        $myLogMsg=date("Y-m-d H:i:s")." -> Updating {$infraNodeBlkindex}\n";
				        if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				        $tmp=$themap->storage[$infraNodeBlkindex];
					$tmp['peerLLDP']=array_merge($tmp['peerLLDP'],array($key2=>$value2));
				        $themap->storage[$infraNodeBlkindex]=$tmp;
				    }
				}
				if(count($nodes) >= 2) {
				    if ($nodes[0] > $nodes[1]) {
					$tmpNodeNum=$nodes[0];
					$nodes[0]=$nodes[1];
					$nodes[1]=$tmpNodeNum;
				    }
				    $fabricExplicitGEp_name="B2G_Created_Explicit_VPC_".$nodes[0]."-".$nodes[1];
				    $fabricExplicitGEp_dn='uni/fabric/protpol/expgep-'.$fabricExplicitGEp_name;
				    $fabricExplicitGEp_rn='expgep-'.$fabricExplicitGEp_name;
				    $fabricExplicitGEp_url='uni/fabric/protpol/expgep-'.$fabricExplicitGEp_name;
				    $fabricExplicitGEpindex=$pre.$fabricExplicitGEp_dn.'<3>VPC_CHILD';
				    $ids=array();
				    $newid="";
				    foreach($themap->storageindex_class as $key2 => $value2) {
				        if(strpos($key2,"<=>CLASS") !== false && $themap->storage[$key2] === 'fabricExplicitGEp') {
				            $tmpdn=get_dn($key2,'<2>','<=>');
				            if($tmpdn === $fabricExplicitGEp_dn) {
					        $newid=$themap->storage[get_newindex($key2,'<=>','id')];
					        break;
					    }
					    $ids[0+$themap->storage[get_newindex($key2,'<=>','id')]]=$tmpdn;
					}
				    }
				    if($newid === "") {
				        for($x=1000; $x >=1; $x--) {
				            if(isset($ids[$x]) == false) {
					        unset($ids);
					        $newid="$x";
					        break;
					    }
					}
				    }
				    $fabricExplicitGEp_id=$newid;
				    $fabricNodePEp_name1=$nodes[0];
				    $fabricNodePEp_dn1=$fabricExplicitGEp_dn.'/nodepep-'.$fabricNodePEp_name1;
				    $fabricNodePEp_rn1='nodepep-'.$fabricNodePEp_name1;
				    $fabricNodePEp_id1=$nodes[0];
				    $fabricNodePEp_name2=$nodes[1];
				    $fabricNodePEp_dn2=$fabricExplicitGEp_dn.'/nodepep-'.$fabricNodePEp_name2;
				    $fabricNodePEp_rn2='nodepep-'.$fabricNodePEp_name2;
				    $fabricNodePEp_id2=$nodes[1];
				    if(isset($themap->storage[$fabricExplicitGEpindex]) === false) {
				        $themap->storage[$fabricExplicitGEpindex] = array("CLASS" => "fabricExplicitGEp", "peerLLDP"=>$index, "fabricExplicitGEp_dn" => $fabricExplicitGEp_dn,
											  "fabricExplicitGEp_name" => $fabricExplicitGEp_name, "fabricExplicitGEp_rn" => $fabricExplicitGEp_rn,
											  "fabricExplicitGEp_url"=>$fabricExplicitGEp_url, "fabricExplicitGEp_id"=>$fabricExplicitGEp_id,
											  "fabricNodePEp_dn1" => $fabricNodePEp_dn1, "fabricNodePEp_name1" => $fabricNodePEp_name1,
											  "fabricNodePEp_rn1" => $fabricNodePEp_rn1, "fabricNodePEp_id1"=>$fabricNodePEp_id1,
											  "fabricNodePEp_dn2" => $fabricNodePEp_dn2, "fabricNodePEp_name2" => $fabricNodePEp_name2,
											  "fabricNodePEp_rn2" => $fabricNodePEp_rn2, "fabricNodePEp_id2"=>$fabricNodePEp_id2, "Adminstate"=>"created");					
				        //echo "CREATING {$fabricExplicitGEpindex}\n";
				        $myLogMsg=date("Y-m-d H:i:s")." -> Creating {$fabricExplicitGEpindex}\n";
				        if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					foreach ($themap->storage['priorIndex'] as $indexKey=>$indexValue) {
					    $myLogMsg=date("Y-m-d H:i:s")." -> Updating from the priorIndex value: {$fabricExplicitGEpindex}\n";
					    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					    $tmp=$themap->storage[$fabricExplicitGEpindex];
					    $tmp['peerLLDP']=array_merge($tmp['peerLLDP'],$themap->storage['priorIndex']);
					    $themap->storage[$fabricExplicitGEpindex]=$tmp;
					    // now clear the priorIndex
					    $nullArray=array();
					    $themap->storage['priorIndex']=$nullArray;
					    break;
					}
				    } else {				
				        //echo "UPDATING...{$fabricExplicitGEpindex}\n";
				        $myLogMsg=date("Y-m-d H:i:s")." -> Updating {$fabricExplicitGEpindex}\n";
				        if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				        $tmp=$themap->storage[$fabricExplicitGEpindex];
				        $tmp['peerLLDP']=array_merge($tmp['peerLLDP'],$index);
				        $themap->storage[$fabricExplicitGEpindex]=$tmp;
					foreach ($themap->storage['priorIndex'] as $indexKey=>$indexValue) {
					    $myLogMsg=date("Y-m-d H:i:s")." -> Updating from the priorIndex value: {$fabricExplicitGEpindex}\n";
					    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
					    $tmp=$themap->storage[$fabricExplicitGEpindex];
					    $tmp['peerLLDP']=array_merge($tmp['peerLLDP'],$themap->storage['priorIndex']);
					    $themap->storage[$fabricExplicitGEpindex]=$tmp;
					    // now clear the priorIndex
					    $nullArray=array();
					    $themap->storage['priorIndex']=$nullArray;
					    break;
					}
				    }
				} else {
				    $themap->storage['priorIndex'] = $index;	// only know of the 1 node at this point, so store temporarliy this for adding to peerdn when learn of 2nd node
				    $myLogMsg=date("Y-m-d H:i:s")." -> Only 1 node known so far, so just storing this cycle index in priorIndex\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				}
			    }
			}
		    }
		}
	    }
	    $myLogMsg=date("Y-m-d H:i:s")." -> Leaving the handler for inclass={$inclass}\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
        }
    }
    if($inoperation === "REMOVE_DN") {
	$myLogMsg=date("Y-m-d H:i:s")." -> Entry to VPC {$inclass}:  indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
        //In all remove_dn cases, we return a true to keep the object in memory (like when we resync), false to clean it up
        $removedAtSrc=false;
        //Remove object at destination if source gets deleted.
	if($inclass === "fabricEthLanPc") {    // we need to remove the VPC entries for this item in dn
	    $myLogMsg=date("Y-m-d H:i:s")." -> We have a fabricEthLanPc REMOVE_DN for dn:{$indn}.\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
	    if(isset($themap->ucsstack['fabricAvpc'])) {
		$tmpPCnum=$themap->ucsstack['fabricAvpc'];
		$searchString='lan/A/'.$tmpPCnum;
		if(strpos($indn,$searchString) > 0) {
		    // This was the PC to remove the ucsstack memory entries as this was just deleted on the UCS
		    unset($themap->ucsstack['fabricAvpc']);
		    unset($themap->ucsstack['fabricAv'.$tmpPCnum.'-nodes-ports']);
		    foreach($themap->UCSMeventids as $Ueventkey=>$UeventValue) {
			if(strpos($Ueventkey,'<->fabric/lan/A/'.$tmpPCnum) > 0) {
			    unset($themap->UCSMeventids[$Ueventkey]);
			    $myLogMsg=date("Y-m-d H:i:s")." -> Removed the UCSM event filter: {$Ueventkey}\n";
			    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			    echo $myLogMsg;
			}
		    }
		    // Remove the subscription on the APIC side
		    $items=$themap->apicsession->apic_unsubscribe($themap, $inclass, $themap->ucsstack['fabricAname']);
		    $myLogMsg=date("Y-m-d H:i:s")." -> Removed {$items} APIC subscriptions to: CLASS:{$inclass} for KEY:{$indn}\n";
		    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
		    echo $myLogMsg;
		    return false;
		}
	    }
	    if(isset($themap->ucsstack['fabricBvpc'])) {
		$tmpPCnum=$themap->ucsstack['fabricBvpc'];
		$searchString='lan/B/'.$tmpPCnum;
		if(strpos($indn,$searchString) > 0) {
		    // This was the PC to remove the ucsstack memory entries as this was just deleted on the UCS
		    unset($themap->ucsstack['fabricBvpc']);
		    unset($themap->ucsstack['fabricBv'.$tmpPCnum.'-nodes-ports']);
		    foreach($themap->UCSMeventids as $Ueventkey=>$UeventValue) {
			if(strpos($Ueventkey,'<->fabric/lan/B/'.$tmpPCnum) > 0) {
			    unset($themap->UCSMeventids[$Ueventkey]);
			    $myLogMsg=date("Y-m-d H:i:s")." -> Removed the UCSM event filter: {$Ueventkey}\n";
			    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			    echo $myLogMsg;
			}
		    }
		    // Remove the subscription on the APIC side
		    $items=$themap->apicsession->apic_unsubscribe($themap, $inclass, $themap->ucsstack['fabricBname']);
		    $myLogMsg=date("Y-m-d H:i:s")." -> Removed {$items} APIC subscriptions to: CLASS:{$inclass} for KEY:{$indn}\n";
		    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
		    echo $myLogMsg;
		    return false;
		}
	    }
	}
        //8-1-15 taking simulator operations into account - we will catch this VPC endpoint removal from UCSM and translate to look as if LLDP removal was done from APIC
	if (!$themap->storage['realEnvironment'] && ($inclass === "fabricEthLanPcEp")) {
	    preg_match("/fabric\/lan\/(.*?)\//", $indn, $fabricID);
	    preg_match("/\/pc-(.*?)\/ep-slot/", $indn, $vpcID);
	    preg_match("/\/ep-slot-1-port-(.*)/", $indn, $portID);
	    $simFabric=$fabricID[1];
	    $simVPC=$vpcID[1];
	    $simPort=$portID[1];
	    $myLogMsg=date("Y-m-d H:i:s")." -> We have a removal event for the simulator VPC member endpoint on UCSM:  fabric-{$simFabric}, vpcID-{$simVPC}, port-{$simPort}\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
	    if ($simVPC === $themap->storage['simVPCkey']) {
		foreach($themap->storageindex_class as $key => $value) {
		    if(strpos($key,'<=>simulatedLink') > 0) {
			$simLinkArray = explode("<->", $value);		// this is a string of fabric<->vpcID<->ucsinterface<->node<->leafinterface
			$myLogMsg=date("Y-m-d H:i:s")." -> We have parsed the simlinkarray into: {$simLinkArray[0]} -- {$simLinkArray[1]} -- {$simLinkArray[2]} -- {$simLinkArray[3]} -- {$simLinkArray[4]}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			if ($simFabric == $simLinkArray[0]) {
			    preg_match("/Ethernet1\/(.*)/", $simLinkArray[2], $portInteger);
			    if ($simPort == $portInteger[1]) {
				$searchString='fabric/lan/'.$simLinkArray[0].'/'.$simLinkArray[1].'/ep-slot-1-port-'.$portInteger[1];
				$myLogMsg=date("Y-m-d H:i:s")." -> Constructed a search string: {$searchString} to compare to indn: {$indn}\n";
				if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				if ($indn === $searchString) {		// we have a hit, as this needs to be transformed
				    $indn = '/topology/pod-1/node-'.$simLinkArray[3].'/sys/lldp/inst/if-[eth1/'.$simLinkArray[4].']/adj-1';
				    // We can now clear out all the memory objects that were created
				    unset($themap->storageindex_class[$key]);
				    $mainStoreKey=get_storagePrefix($key, '<=>');
				    unset($themap->storage[$mainStoreKey.'<=>portDesc']);
				    unset($themap->storage[$mainStoreKey.'<=>mgmtIP']);
				    unset($themap->storage[$mainStoreKey.'<=>sysName']);
				    unset($themap->storage[$mainStoreKey.'<=>CLASS']);
				    $myLogMsg=date("Y-m-d H:i:s")." -> We have created a new indn as: {$indn}, and cleared memory tied to key: {$key} and: {$mainStoreKey}\n";
				    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
				    break;
				}
			    }
			}
		    }
		}
	    }
	}
	//8-1-15 end of section to take simulator usage into account
        foreach($themap->storage as $key => $value) {
	    if(isset($themap->storage[$key]['peerLLDP'][$indn]) === true) {
	        $myLogMsg=date("Y-m-d H:i:s")." -> Want to clear out peerLLDP for key:{$key}\n";
		if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
		$tmp=$themap->storage[$key];
	        unset($tmp['peerLLDP'][$indn]);
	        $themap->storage[$key]=$tmp;
	        if(count($themap->storage[$key]['peerLLDP']) === 0) {
		    $myLogMsg=date("Y-m-d H:i:s")." -> Part 1 no more peerLLDP entries for key:{$key}, delete this item.\n";
		    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
		    echo $myLogMsg;
		    $tmp=$themap->storage[$key];
		    $tmp["Adminstate"] = "deleted";
		    $themap->storage[$key]=$tmp;
		    $removedAtSrc=true;
	        }
	    }
	    // This next one is to clean up if we have some out of order things
	    if(isset($themap->storage[$key]['peerLLDP']) === true) {
	        if(count($themap->storage[$key]['peerLLDP']) === 0) {
		    $myLogMsg=date("Y-m-d H:i:s")." -> Part 2 no more peerLLDP entries for key:{$key}, delete this item.\n";
		    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
		    echo $myLogMsg;
		    $tmp=$themap->storage[$key];
		    $tmp["Adminstate"] = "deleted";
		    $themap->storage[$key]=$tmp;
		    $removedAtSrc=true;
	        }
	    }
        }
        if($removedAtSrc === false) {
	    $myLogMsg=date("Y-m-d H:i:s")." -> Object was not removed at the source, so will resync if KEEP_SYNC is true.\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
	    foreach($themap->storage as $key => $value) {
		/*if (strpos($key, $indn) > -1) {
		    ob_start();
		    $myLogMsg=date("Y-m-d H:i:s")." -> found a storage reference to this indn and its key=[{$key}], vardump of value is:\n";
		    var_dump($value);
		    $myLogMsg.=ob_get_clean();
		    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
		}*/
		if(isset($themap->storage[$key]['CLASS']) == false) continue;
	        if($inclass === 'infraAccPortP' && strpos($key,"<3>VPC_ROOT") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraAccPortP_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
		if($inclass === 'infraNodeP' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraNodeP_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
		if($inclass === 'infraLeafS' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraLeafS_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
		if($inclass === 'infraNodeBlk' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraNodeBlk_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
	        if($inclass === 'infraRsAccPortP' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraRsAccPortP_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
		if($inclass === 'fabricExplicitGEp' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['fabricExplicitGEp_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
			$tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        } 
	        if($inclass === 'lacpLagPol' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['lacpLagPol_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }    
	        if($inclass === 'infraAccBndlGrp' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraAccBndlGrp_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
	        if($inclass === 'infraRsLacpPol' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraRsLacpPol_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
	        if($inclass === 'infraHPortS' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraHPortS_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
	        if($inclass === 'infraPortBlk' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraPortBlk_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
	        if($inclass === 'infraRsAccBaseGrp' && strpos($key,"<3>VPC_CHILD") !== false && $themap->storage[$key]['CLASS'] === $inclass && $themap->storage[$key]['infraRsAccBaseGrp_dn'] === $indn) {
		    $peerKey=get_swappedstoragePrefix($key,'<1>','<2>');
		    if($themap->flowmap[$themap->storage[$peerKey.$indn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		        $tmp=$themap->storage[$key];
		        $tmp["Adminstate"] = "created";
		        $themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }
		// Now I worry about resyncing the objects that B2G created on APIC for the VPC case
	        if($inclass === 'lacpLagPol' && strpos($key,"<3>AEP_UCSVSW_PINPOLICY") !== false && $themap->storage[$key]['CLASS'] === $inclass) {
		    if (isset($themap->storage[$key]['peerdn'][$indn]) === true) {
			$myLogMsg=date("Y-m-d H:i:s")." -> Working to recreate the UCS AEP vswitch policy that was deleted.\n";
			if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			$tmp=$themap->storage[$key];
			$tmp["Adminstate"] = "created";
			$themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }    
	        if($inclass === 'infraAttEntityP' && strpos($key,"<3>AEP_UCS_SYSTEMS") !== false && $themap->storage[$key]['CLASS'] === $inclass) {
		    if (isset($themap->storage[$key]['peerdn'][$indn]) === true) {
			$myLogMsg=date("Y-m-d H:i:s")." -> Working to recreate the UCS AEP core policy that was deleted.\n";
			if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			$tmp=$themap->storage[$key];
			$tmp["Adminstate"] = "created";
			$themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }    
	        if($inclass === 'dhcpRelayP' && strpos($key,"<3>DHCP_RELAY_TO_APIC") !== false && $themap->storage[$key]['CLASS'] === $inclass) {
		    if (isset($themap->storage[$key]['peerdn'][$indn]) === true) {
			$myLogMsg=date("Y-m-d H:i:s")." -> Working to recreate the APIC DHCP relay policy that was deleted.\n";
			if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			$tmp=$themap->storage[$key];
			$tmp["Adminstate"] = "created";
			$themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }    
	        if($inclass === 'dhcpLbl' && strpos($key,"<3>DHCP_RELAY_LBL_TO_APIC") !== false && $themap->storage[$key]['CLASS'] === $inclass) {
		    if (isset($themap->storage[$key]['peerdn'][$indn]) === true) {
			$myLogMsg=date("Y-m-d H:i:s")." -> Working to recreate the APIC DHCP relay label policy that was deleted.\n";
			if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
			$tmp=$themap->storage[$key];
			$tmp["Adminstate"] = "created";
			$themap->storage[$key]=$tmp;
		        $myLogMsg=date("Y-m-d H:i:s")." -> VPC Process Re-synced: {$indn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return true; 	// 5-19-15
		    }
	        }    
	    }
	} else {
	    $myLogMsg=date("Y-m-d H:i:s")." -> Object was removed at the source, so we will not resync anything.\n";
	    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
	}
    }
    return false;
}

//Main function for the CIMC Rack user story
function RACK(&$themap, $inclass, $indn, $inattribute, $invalue, $insystem, $inoperation) {
    date_default_timezone_set('UTC');
    //echo "RACK GOT: {$inclass} -- {$indn} -- {$inattribute} -- {$invalue} -- {$insystem} -- {$inoperation}\n";
    $myLogMsg="********7-4-15 Working, Inside RACK:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
    if ($themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);

    if($inoperation === "DOER") {  
	$vifindex="";
        if($inclass === 'lldpAdjEp' && $inattribute === 'chassisIdV') {
            foreach($themap->rackservers as $key=>$value) {
	        if(strtoupper($themap->rackservers[$key]["chassisid"]) === strtoupper($invalue)) {
	            preg_match("/(?<=\/node-)(.*?)\//", $indn, $output_array);
	            $innode=$output_array[1];
	            preg_match("/(?<=\/if-)(.*?)\]/", $indn, $output_array);
	            $inif=$output_array[0];
		    //echo "***TEST***:  indn is {$indn}, rack peer is {$rackserverpeer}, pathAtt is {$fvRsPathAtt}, chassis is {$chassisIdV}, port is {$portIdV}, innode is {$innode}, inif is {$inif}\n";
		    $tmp=$themap->rackservers[$key];
		    $index=$tmp['physdomainname'];		// 5-18-15 gathering rackstack domain index for the rackstack usage later
		    $tmp["paths-$innode"] = "$inif";
		    $themap->rackservers[$key]=$tmp;	
		    $myLogMsg="********7-4-15 Inside RACK: Just set the rackserver {$key} path-{$innode} to {$inif}\n";
		    if ($themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);	
		}
	    }
	}
	if($inclass === 'fvRsDomAtt' && $inattribute === 'tDn') {
	    foreach($themap->rackstack as $key => $value) {
	        if ($key === $invalue) {
		    echo date("Y-m-d H:i:s")." -> Time to form interface bindings to the rackservers in domain [{$key}]\n";
		    $startVLAN = $themap->rackstack[$key]['physdomainmaxvlan'];
		    $finalVLAN = $themap->rackstack[$key]['physdomainminvlan'];
		    for($x=$startVLAN; $x >= $finalVLAN; $x--) {
			$tmp=$themap->rackstack[$key];
			if(isset($tmp["vlan-$x"]) === false) {
			    // Use this VLAN - but lets find just the EPG name to be the value, not the indn
			    $domArray = explode("/rsdomAtt", $indn);
			    $domAttachEpgName = $domArray[0];
			    $tmp["vlan-$x"]="$domAttachEpgName";
			    $themap->rackstack[$key]=$tmp;
			    break;
			}
		    }
		    echo date("Y-m-d H:i:s")." -> Trying to utilize VLAN: {$x}, out of start={$startVLAN} and final={$finalVLAN}\n";
		    if ($themap->rackstack[$key]['connectPolicy'] === "NONVPC") {
			$themap->apiccallqueue[$indn]='UCSC-NONVPC<->BindIt<->'.$domAttachEpgName.'<->'.$key.'<->'.$x;
		    } else {
			$themap->apiccallqueue[$indn]='UCSC-VPC<->BindIt<->'.$domAttachEpgName.'<->'.$key.'<->'.$x;
		    }
		}
	    }
	}  	  
	if($inclass === 'fvRsPathAtt' && $inattribute === 'encap') {
	    // 11-1-14: if we have an input encap value, we need to overwrite the number selected from pool above
	    // This is for the case where we startup this program, with bindings already configured on APIC so we need to match those
	    foreach($themap->rackservers as $key => $value) {
		$evaluateThisPath=false;
		$tmp=$themap->rackservers[$key];
		//echo "examining host:".$key." and looking to see if the path ports are in this node, which are reflected in others in domain.\n";
		foreach($value as $arrayItem => $arrayValue) {
		    if (strstr($arrayItem, "paths-") !== false) {
			if (strstr($indn, $arrayItem) !== false && strstr($indn, $arrayValue) != false) {
			    $evaluateThisPath=true;
			}
		    }
		    if (strstr($arrayItem, "physdomainnamedn") !== false) {
			$tmpDomain = $arrayValue;
		    }
		}
		if (!$evaluateThisPath) continue;
	        $domArray = explode("/rspathAtt", $indn);
	        $domAttachEpgName = $domArray[0];
		//echo "11-12-14 TEST***  domAttachEpgName is {$domAttachEpgName}, and tmpDomain is {$tmpDomain}\n";
		foreach ($themap->rackstack as $key2 => $value2) {
		    if ($key2 === $tmpDomain) {
			$tmp2=$themap->rackstack[$key2];
			$startVLAN = $tmp2['physdomainmaxvlan'];
			$finalVLAN = $tmp2['physdomainminvlan'];
			for($x=$startVLAN; $x >= $finalVLAN; $x--) {
			    if(isset($tmp2["vlan-$x"])) {
				if($tmp2["vlan-$x"] === "$domAttachEpgName") {
				    unset($tmp2["vlan-$x"]);  // just nuke any entry here for this EPG
				    //echo "11-12-14 TEST*** Just removed the entry of vlan-{$x} for the epg={$domAttachEpgName}\n";
				    break;
				}
			    }
			}
			$tmp2[$invalue] = $domAttachEpgName;	// just write again here
			//echo "11-12-14 TEST*** Just added an entry of {$invalue} for the epg={$domAttachEpgName}\n";
			$themap->rackstack[$key2]=$tmp2;
			break;
		    }
		}
	    }
	    // 11-1-14: Done
	    preg_match("/(?<=\/paths-)(.*?)\//", $indn, $output_array);
	    if(isset($output_array[1]) == false) {
		$myLogMsg="********7-4-15 Inside RACK:  looking for the [paths-...] inside the indn: {$indn} and the output_array[1] does not exist!\n";
		if ($themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);	
		return;		// 9-2-15
	    } else {
		$myLogMsg="********7-4-15 Inside RACK:  looking for the [paths-...] inside the indn: {$indn} and the output_array[1] exists! Its value is [{$output_array[1]}]\n";
		if ($themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);	
	    }
	    $innode=$output_array[1];
	    preg_match("/(?<=pathep-)(.*?)\]/", $indn, $output_array);
	    $inif=$output_array[0];
	    //foreach($themap->storage as $key => $value) {
	    //echo "Test Point 0\n";
	    foreach($themap->storageindex_class as $key => $value) {
		//echo "Test Point 1, key = {$key}, item={$themap->storage[$key]}\n";
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'lldpAdjEp') {
		    $fvRsPathAtt=$indn;
		    preg_match("/(?<=<2>)(.*?)<=>/", $key, $output_array);
		    $lldpAdjEp=$output_array[1];
		    $chassisIdV=strtoupper($themap->storage[get_newindex($key,'<=>','chassisIdV')]);
		    $portIdV=strtoupper($themap->storage[get_newindex($key,'<=>','portIdV')]);
		    preg_match("/(?<=\/node-)(.*?)\//", $key, $output_array);
		    $node=$output_array[1];
		    preg_match("/(?<=\/if-)(.*?)\]/", $key, $output_array);
		    $if=$output_array[0];
		    //echo "Test Point 2, innode={$innode}, node={$node}, inif={$inif}, if={$if}\n";
		    if($innode === $node && $inif === $if) {
		        $rackserverpeer="";
		        foreach($themap->rackservers as $key1=>$value1) {
		            //echo "Test Point 3, Comparing: {$themap->rackservers[$key1]["chassisid"]} -- {$chassisIdV}\n";
		            if(strtoupper($themap->rackservers[$key1]["chassisid"]) === $chassisIdV) {
			        $rackserverpeer=$themap->rackservers[$key1]["ip"];
			        break;
			    }
			}
			if($rackserverpeer === "") {
			    break;
			}
			//echo "Test Point 3, rackserverpeer = {$rackserverpeer}\n";
			preg_match("/(?<=\/tn-)(.*?)\//", $indn, $output_array);
			$tenant=$output_array[1];
			preg_match("/(?<=\/ap-)(.*?)\//", $indn, $output_array);
			$application=$output_array[1];
			preg_match("/(?<=\/epg-)(.*?)\//", $indn, $output_array);
			$epg=$output_array[1];
			preg_match("/(?<=vlan-).*/", $invalue, $output_array);  //********************9-2-15 put the text star instead of * this line
			$vlan=$output_array[0];			
			$vifname=$tenant.'-'.$application.'-'.$epg;
			// Here I have to truncate this to 30 bytes, as I need to keep 2 for the -0 or -1 in the CDN field
			if(strlen($vifname) > 30) {
			    $vifname=substr($vifname, -30);
			}	
			$vifindex=$node.'<1>'.$if.'<2>'.$vifname.'<3>RACK_ROOT';
			$myLogMsg="********7-4-15 Inside RACK:  fvRsPathAtt encap area set the vifindex to {$vifindex}, node is {$node}, if is {$if}, tenant is {$tenant}, AP is {$application}, EPG is {$epg}\n";
			if ($themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);	
			break;
		    }	
		}
	    }
	}
	if($inclass === "fvnsVlanInstP") {
	    $classText="UCSC_VLAN_POOL";
	    //$myLogMsg="********APIC Inside RACK:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    //if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "[UCS_RACK_DOMAINS]-dynamic") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    //$pre=substr($key, 0, strrpos($key,"<2>") + 3);
		    $pre=get_storagePrefix($key,'<2>');
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->apicstack['APIC_IP']."<1>".$themap->ucsstack['VIP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="APIC Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	}
	if($inclass === "physDomP") {
	    $classText="UCSC_PHYS_DOMAIN";
	    //$myLogMsg="********APIC Inside RACK:  inclass={$inclass}, indn={$indn}, inattribute={$inattribute}, invalue={$invalue}, insystem={$insystem}, inoperation={$inoperation}\n";
	    //if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
	    // Need to construct the synthetic object for this entry, so that framework will later restore if KEEP_SYNC, etc.
	    if (strstr($indn, "UCS_RACKDOMAIN_") == NULL) return;
	    $foundSyntheticObject=false;
	    foreach($themap->storageindex_class as $key => $value) {
	        if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === $inclass && strpos($key, $indn) > -1) {
		    $foundSyntheticObject=true;
		    $pre=substr($key, 0, strrpos($key,"<2>") + 3);
		    $storageIndex=$pre.$indn.'<3>'.$classText;
		    break;
		}
	    }
	    if (!$foundSyntheticObject) {
		$pre=$themap->apicstack['APIC_IP']."<1>".$themap->ucsstack['VIP']."<2>";
		$storageIndex=$pre.$indn."<3>".$classText;
	    }
	    if (isset($themap->storage[$storageIndex]) === false) {
		$themap->storage[$storageIndex] = array("CLASS"=>$inclass, "peerdn"=>array($indn=>true), "Adminstate"=>"");	// this was not yet created
		$myLogMsg="APIC Startup ({$inclass}): set the synthetic object [{$storageIndex}] for existing {$inclass} on APIC controller\n";
		if ($themap->storage['logRackoperations']) file_put_contents("rackMessages.txt", $myLogMsg, FILE_APPEND);
	    }
	}
	if($vifindex !== "") {	    
	    //echo "vifname constructed = {$vifname}, vifindex = {$vifindex}\n";
	    if(isset($themap->storage[$vifindex]) === false) {					
	        $themap->storage[$vifindex] = array("CLASS" => "vif", "rackserverpeer"=>$rackserverpeer, "vifname"=>$vifname, "peerLLDP"=>$lldpAdjEp,
						    "peerEPG"=>$fvRsPathAtt, "vlan"=>$vlan, "node" => $node, "if" => $if, "chassisIdV" => $chassisIdV,
						    "portIdV"=>$portIdV, "Adminstate"=>"created");					
	        //echo "CREATING {$vifindex}\n";
	    } else {				
	        //echo "UPDATING...{$vifindex}\n";
	        $tmp=$themap->storage[$vifindex];				
	        $tmp['peerLLDP']=$lldpAdjEp;
	        $tmp['peerEPG']=$fvRsPathAtt;		
	        $themap->storage[$vifindex]=$tmp;				
	    }
	    $themap->rackcommand[$rackserverpeer] = array("cmd"=>"update");
	}
	if($inclass === "fvTenant") {
	    // We learned an APIC Tenant to store for later binding
	    //set($themap->storage["tn-$invalue"]);
	    $tmp=$themap->apicstack;
	    $tmp["$indn"] = "tenant";
	    $themap->apicstack=$tmp;
	}
	if($inclass === "fvAp") {
	    // We learned an App Profile under a given tenant
	    $tmp=$themap->apicstack;
	    $tmp["$indn"] = "app-profile";
	    $themap->apicstack=$tmp;
	}
	if($inclass === "fvAEPg") {
	    // We learned an EPG under a tenant and app profile
	    $tmp=$themap->apicstack;
	    $tmp["$indn"] = "epg";
	    $themap->apicstack=$tmp;
	}
    }
    if($inoperation === "REMOVE_DN") {
	//Returning from this, a true means we need to keep the items, false indicates we want to clean up the storage
        if($indn === 'uni/infra/vlanns-[UCS_RACK_DOMAINS]-dynamic') {	
	    //if the keep sync is set, then resync the UCSM VLAN pool
	    foreach($themap->flowmap as $key=>$value) {		
	        if($themap->flowmap[$key]['SOURCE_CLASS'] === 'fvnsVlanInstP' && $themap->flowmap[$key]['KEEP_SYNC'] ==='TRUE') {
		    foreach($themap->storage as $key1=>$value1) {
			if (strpos($key1,'<3>UCSC_VLAN_POOL') !== false) {
			    $tmp=$themap->storage[$key1];
			    $tmp["Adminstate"] = "created";
			    $themap->storage[$key1]=$tmp;
			    echo date("Y-m-d H:i:s")." -> Rack Process Re-synced: {$indn}\n";
			    return true;
			}
		    }
	        }
	    }
        }
        //Resync the physical rack domain instance if it gets deleted
	foreach($themap->rackstack as $tempdn=>$value) {
	    if($indn === $tempdn) {
		//this rack stack instance is the one we manage and have a deletion for - if the keep sync is set, then resync the UCSM physical domain
	        foreach($themap->flowmap as $key=>$value) {
		    if($themap->flowmap[$key]['SOURCE_CLASS'] === 'physDomP' && $themap->flowmap[$key]['KEEP_SYNC'] ==='TRUE') {
			foreach($themap->storage as $key1=>$value1) {
			    if (strpos($key1,'<3>UCSC_PHYS_DOMAIN') !== false) {	// this is an interesting C domain entry to analyze
				// now check if this is our domain
				if (strpos($key1, $indn) > 0) {
				    $tmp=$themap->storage[$key1];		// it is so reset the synthetic object flag
				    $tmp["Adminstate"] = "created";
				    $themap->storage[$key1]=$tmp;
				    echo date("Y-m-d H:i:s")." -> Rack Process Re-synced: {$indn}\n";
				    return true;
				}
			    }
			}
		    }
		}
	    }
	}
        if($inclass === "fvRsDomAtt") {
	    foreach($themap->rackstack as $key => $value) {
		//echo "3-12-15 TEST - looking for {$key} within {$indn}\n";
	        if (strstr($indn, $key) != NULL) {	// if this is true, I need to check the EPG next for any match
		    $startVLAN = $themap->rackstack[$key]['physdomainmaxvlan'];
		    $finalVLAN = $themap->rackstack[$key]['physdomainminvlan'];
		    $tmp=$themap->rackstack[$key];
		    for($x=$startVLAN; $x >= $finalVLAN; $x--) {
			if(isset($tmp["vlan-$x"])) {
			    // gather the domain name from the VLAN we want to test
			    $domAttachEpgName = $tmp["vlan-$x"];
			    //echo "3-12-15 TEST - looking for {$domAttachEpgName} within {$indn}\n";
			    if (strstr($indn, $domAttachEpgName) != NULL) {
			        echo date("Y-m-d H:i:s")." -> Time to remove interface or VPC (later) bindings to the rackservers for EPG [{$domAttachEpgName}] in domain [{$key}]\n";
			        if ($themap->rackstack[$key]['connectPolicy'] === "NONVPC") {
			            $themap->apiccallqueue[$indn]='UCSC-NONVPC<->UnBindIt<->'.$domAttachEpgName.'<->'.$key.'<->'.$x;
			        } else {
			            $themap->apiccallqueue[$indn]='UCSC-VPC<->UnBindIt<->'.$domAttachEpgName.'<->'.$key.'<->'.$x;
			        }
			        break;
			    }
			}
		    }
		}
	    }
	}    
	if($inclass === "fvTenant") {
	    // We need to remove an APIC Tenant from storage, and nuke things underneath *unless the BASTARD is signalled
	    //set($themap->storage["tn-$indn"]);
	    //echo "********OK, need to remove tenant of {$indn} from APIC memory, and all underlying app profile's, EPG's, and Domain Attachments will get thier own removal message.\n";
	    echo date("Y-m-d H:i:s")." -> Need to remove the dynamicflowmap monitoring that tenant.********\n";
	    $tmp=$themap->apicstack;
	    unset($tmp["$indn"]);
	    $themap->apicstack=$tmp;
	}
	if($inclass === "fvAp") {
	    // We need to remove an APIC app profile from storage, and nuke things underneath *unless the BASTARD is signalled
	    //echo "********OK, need to remove app profile of {$indn} from APIC memory, and all underlying EPGs and Domain Attachments will get thier own removals.\n";
	    echo date("Y-m-d H:i:s")." -> Need to remove the dynamicflowmap monitoring that app profile.********\n";
	    $tmp=$themap->apicstack;
	    unset($tmp["$indn"]);
	    $themap->apicstack=$tmp;
	}
	if($inclass === "fvAEPg") {
	    // We need to remove an APIC EPG from storage, and nuke any binding if this is there *unless the fvRsDomAtt removal above is already called
	    //echo "********OK, need to remove and EPG of {$indn} from APIC memory, and all underlying Domain Attachments will get thier own removals.\n";
	    echo date("Y-m-d H:i:s")." -> Need to remove the dynamicflowmap monitoring that EPG.********\n";
	    $tmp=$themap->apicstack;
	    unset($tmp["$indn"]);
	    $themap->apicstack=$tmp;
	}
	if ($inclass === "fvRsPathAtt") {
	    $allowUnbind=true;
	    // first, check if the domain is still attached, and if so then re-sync the binding that was removed
	    foreach($themap->rackstack as $key => $value) {
    	        $startVLAN = $themap->rackstack[$key]['physdomainmaxvlan'];
	        $finalVLAN = $themap->rackstack[$key]['physdomainminvlan'];
		for($x=$startVLAN; $x >= $finalVLAN; $x--) {
		    $tmp=$themap->rackstack[$key];
		    if(isset($tmp["vlan-$x"])) {
			// compare the value (EPG) to the indn to see if there
			$domAttachEpgName = $tmp["vlan-$x"];
			//echo "3-12-15 TEST - looking for {$domAttachEpgName} within {$indn}\n";
			if (strstr($indn, $domAttachEpgName) != NULL) {
			    // this EPG in is bound to this domain and rack server, now re-sync
			    echo date("Y-m-d H:i:s")." -> Need to re-attach the detached EPG=".$domAttachEpgName." with binding of VLAN=".$x." from rack servers belonging to domain= ".$key."\n";
			    $allowUnbind=false;
			    if ($themap->rackstack[$key]['connectPolicy'] === "NONVPC") {
				$themap->apiccallqueue[$indn]='UCSC-NONVPC<->BindIt<->'.$domAttachEpgName.'<->'.$key.'<->'.$x;
			    } else {
				$themap->apiccallqueue[$indn]='UCSC-VPC<->BindIt<->'.$domAttachEpgName.'<->'.$key.'<->'.$x;
			    }
			    echo date("Y-m-d H:i:s")." -> Rack Process Re-synced: {$indn}\n";
			    return true;	// 5-19-15
			    break;
		        }  // else we do not resync		    
		    } // else we do not resync
		}
	    }
	    //echo "allowUnbind = {$allowUnbind}\n";
	    if ($allowUnbind) {
		echo date("Y-m-d H:i:s")." -> Unbinding interfaces that were for EPG {$indn}\n";
	        // Actually delete the adapter on the rackserverpeer
	        preg_match("/(?<=\/paths-)(.*?)\//", $indn, $output_array);
	        $node=$output_array[1];
	        preg_match("/(?<=pathep-)(.*?)\]/", $indn, $output_array);
	        $if=$output_array[0]; 
	        preg_match("/(?<=\/tn-)(.*?)\//", $indn, $output_array);
	        $tenant=$output_array[1];
	        preg_match("/(?<=\/ap-)(.*?)\//", $indn, $output_array);
	        $application=$output_array[1];
	        preg_match("/(?<=\/epg-)(.*?)\//", $indn, $output_array);
	        $epg=$output_array[1];   
	        $vifname=$tenant.'-'.$application.'-'.$epg;
		// Here I have to truncate this to 30 bytes, as I need to keep 2 for the -0 or -1 in the CDN field
	        if(strlen($vifname) > 30) {
	            $vifname=substr($vifname, -30);
	        }	
	        $vifindex=$node.'<1>'.$if.'<2>'.$vifname.'<3>RACK_ROOT';
	        //echo "TEST for index = {$vifindex}\n";
	        //var_dump($themap->storage[$vifindex]);
	        $rackserverpeer=$themap->storage[$vifindex]["rackserverpeer"];
	        unset($themap->storage[$vifindex]);
	        unset($themap->storageindex_class[$vifindex]);
	        $themap->rackcommand[$rackserverpeer] = array("cmd"=>"update");
	    }
	}
    }
    return false;	// 5-19-15
}

//Main function for the SPAN user story
function SPAN(&$themap, $inclass, $indn, $inattribute, $invalue, $insystem, $inoperation) {
    date_default_timezone_set('UTC');
    //echo "SPAN GOT: {$inclass} -- {$indn} -- {$inattribute} -- {$invalue} -- {$insystem} -- {$inoperation}\n";
    
    if($inoperation === "DOER") {
	$myLogMsg=date("Y-m-d H:i:s")." -> SPAN GOT: {$inclass} -- {$indn} -- {$inattribute} -- {$invalue} -- {$insystem} -- {$inoperation}\n";
	if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
	// 8-1-15 we have the real environment catching CDP events, but simulator will not so we work around this
 	if ($themap->storage['realEnvironment']) {
	    if ($inattribute !== "sysName") return;  	// to not keep repeating these calls by every SPAN attribute received in the event channel
	} else {
	    if ($inclass !== "fabricEthMonDestEp") return;
	    if ($inattribute !== "switchId") return;	// to just get the one event
	    // here, we are simulator setup, and we have the right class of event to scan - check that the port is the SPANkey
	    $spanKey = $themap->storage['simSPANkey'];
	    $inputInterface = 'Ethernet1/'.$spanKey;
	    if (strstr($indn, 'dest-slot-1-port-'.$spanKey) == NULL) return;
	    // done, now we re-construct the expected cdp memory entries, and rebuild the indn to pass on
	    $inputFabric=$invalue;
	    $simulatedNodeCounter=0;
	    foreach($themap->nodelist as $nodeKey=>$nodeVal) {
		if (strpos($nodeKey, "<=>NAME") > 0) {
		    if ($simulatedNodeCounter == 0) {
			$sim0node=$nodeVal;
		    } else {
			$sim1node=$nodeVal;
			break;
		    }
		    $simulatedNodeCounter++;
		}
	    }
	    if ($inputFabric === 'A') {
		$nodeNeeded=$sim0node;
	    } else {
		$nodeNeeded=$sim1node;
	    }
	    $port2use = $themap->storage['simLeafSpanPort'];
	    $indn = "topology/pod-1/node-".$nodeNeeded."/sys/cdp/inst/if-[eth1/".$port2use."]/adj-1";	// set for later checks
	    $invalue = $themap->ucsstack['name'].'-'.$inputFabric;					// set for later checks
	    $cdpkey = $themap->apicstack['APIC_IP'].'<1>'.$themap->ucsstack['VIP'].'<2>'.$indn;
	    $themap->storageindex_class[$cdpkey.'<=>simulatedCDPLink'] = $inputFabric.'<->'.$inputInterface.'<->'.$nodeNeeded.'<->'.$port2use;
	    $themap->storage[$cdpkey.'<=>portId'] = $inputInterface;		// write this for later usage in other areas of program
	    $themap->storage[$cdpkey.'<=>sysName'] = $invalue;			// write this for later usage in other areas of program
	    $themap->storage[$cdpkey.'<=>CLASS'] = 'cdpAdjEp';			// write this for later usage in other areas of program
	    $themap->storageindex_class[$cdpkey.'<=>CLASS'] = 'cdpAdjEp';	// write this for later usage in other areas of program
	    $myLogMsg=date("Y-m-d H:i:s")." -> Set SIMULATOR SPAN ITEM cdpkey to: {$cdpkey}, \n\t\tand wrote new indn: {$indn}\n";
	    if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
	}
	// 8-1-15 end of first test
	$myLogMsg=date("Y-m-d H:i:s")." -> Past the entry test cases for SPAN, with indn={$indn}\n";
	if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
	$spandone[]=array();
        //Loop to get a FabricEthMon object
        foreach($themap->storageindex_class as $key => $value) {
	    if(strpos($key,"<=>CLASS") !== false && $themap->storage[$key] === 'fabricEthMon') {
		$dn=get_dn($key,'<2>','<=>');
		$pre=get_storagePrefix($key,'<2>');
		$scope=$themap->storage[$pre.$dn.'<=>SOURCE_SCOPE'];
	        $spanSrcGrp_name = $themap->ucsstack['name'].'-Fabric-'.$themap->storage[$pre.$dn.'<=>id'];
		$UCSfabric = $themap->storage[$pre.$dn.'<=>id'];
		$UCSinstance = $themap->ucsstack['name'].'-'.$UCSfabric;
	        $myLogMsg=date("Y-m-d H:i:s")." -> Found SPAN ROOT: dn: {$dn}, pre: {$pre}, scope: {$scope}, spanSrcGrpname: {$spanSrcGrp_name}, UCSfabric: {$UCSfabric}, UCSinstance: {$UCSinstance}, invalue: {$invalue}\n";
		if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
		if ($UCSinstance !== $invalue) continue;	// only continue on the right UCS fabric side
	        $spanSrcGrp_url = 'uni/infra/srcgrp-'.$spanSrcGrp_name;
	        $spanSrcGrp_dn = 'uni/infra/srcgrp-'.$spanSrcGrp_name;
	        $spanSrcGrp_rn = 'srcgrp-'.$spanSrcGrp_name;
	        $spanrootindex=$pre.$spanSrcGrp_dn.'<3>SPAN_ROOT';
	        $myLogMsg=date("Y-m-d H:i:s")." -> spanrootindex: {$spanrootindex}\n";
		if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
	        //if there are multiple FabricEthMon on same FI, we will make sure we only porcess the first one we found... (and all fabricEthMonEp on same FI regardless of parent)
	        if(isset($spandone[$spanrootindex]) === false) {
		    $myLogMsg=date("Y-m-d H:i:s")." -> The SPAN index {$spanrootindex} was not previously set\n";
		    if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
		    $spandone[$spanrootindex] = true;
		    $themap->storage[$spanrootindex] = array("peerdn"=>$dn, "spanSrcGrp_url"=>$spanSrcGrp_url, "spanSrcGrp_dn" => $spanSrcGrp_dn, "spanSrcGrp_name" => $spanSrcGrp_name,
							     "spanSrcGrp_rn"=>$spanSrcGrp_rn, "spanSrcGrp_status" => "created", "Adminstate"=>"created");			    
		    $myLogMsg=date("Y-m-d H:i:s")." -> Destination system found existing SPAN Object in APIC {$spanSrcGrp_name} index:{$pre}{$spanSrcGrp_dn}<3>SPAN_ROOT\n";
		    if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
		    //Look for fabricEthMonEp that are children on same FI as the FabricEthMon
		    foreach($themap->storageindex_class as $key1 => $value1) {
		        if(strpos($key1,"<=>CLASS") !== false && $themap->storage[$key1] === 'fabricEthMonDestEp' && strpos($key1,"<2>".$scope) !== false) {
			    $dnDestEp=get_dn($key1,'<2>','<=>');
			    $slotId=$themap->storage[get_newindex($key1,'<=>','slotId')];
			    $portId=$themap->storage[get_newindex($key1,'<=>','portId')];
			    $spanSrc_name = 'To-FI-'.$UCSfabric.'-Port-'.$slotId.'-'.$portId;
			    $localInterface="Ethernet".$slotId.'/'.$portId;
			    $spanSrc_url = $spanSrcGrp_url.'/src-'.$spanSrc_name;
			    $spanSrc_dn = $spanSrcGrp_dn.'/src-'.$spanSrc_name;
			    $spanSrc_rn = 'src-'.$spanSrc_name;
			    $myLogMsg=date("Y-m-d H:i:s")." -> Found needed fabricEthMonDestEp object, dn: {$dnDestEp}, slot: {$slotId}, port: {$portId}, name: {$spanSrc_name}\n";
			    if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
			    //Look for CDP information for the fabricEthMonEp child
			    $cdpkey="";
			    foreach($themap->storageindex_class as $key2 => $value2) {
				if(strpos($key2,"<=>CLASS") !== false && $themap->storage[$key2] === 'cdpAdjEp') {
				    if ($themap->storage[get_newindex($key2,'<=>','portId')] === $localInterface) {
					$myLogMsg=date("Y-m-d H:i:s")." -> Comparing ".$themap->storage[get_newindex($key2,'<=>','portId')]." to ".$localInterface."\n\t\tand key2: {$key2}, indn: {$indn}\n";
					if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
					if (strpos($key2, $indn)) {	// added check to ensure we have the right leaf here
					    $cdpkey=$key2;
					    break;
					}
				    }
				}
			    }
			    if($cdpkey !== "") {
			        preg_match("/\[(.*?)\]/", $cdpkey, $apic_remoteInterface); //i.e. eth1/3
			        preg_match("/(?<=\/node-)[^\/]*/", $cdpkey, $apic_node); //i.e. 101
			        $spanRsSrcToPathEp_tdn='topology/pod-1/paths-'.$apic_node[0].'/pathep-'.$apic_remoteInterface[0];
			        $spanRsSrcToPathEp_dn = $spanSrc_dn .'/rssrcToPathEp-['.$spanRsSrcToPathEp_tdn.']';
			        $spanchildindex=$pre.$spanSrc_dn.'<3>SPAN_CHILD';
			        $themap->storage[$spanchildindex] = array("peerdn"=>$dnDestEp, "parent" => $spanrootindex, "spanSrc_url"=>$spanSrc_url, "spanSrc_dn" => $spanSrc_dn,
									  "spanSrc_name" => $spanSrc_name, "spanSrc_rn"=>$spanSrc_rn, "spanSrc_status" => "created",
									  "spanRsSrcToPathEp_tdn" => $spanRsSrcToPathEp_tdn, "spanRsSrcToPathEp_dn"=>$spanRsSrcToPathEp_dn, "Adminstate"=>"created");
				break 2;
			    }
		        }
		    }
	        }	    
	    }
        }
        unset($spandone);
    }
    if($inoperation === "REMOVE_DN") {
        //We return a true to keep the memory if resync, and false to clean it up
        //Remove object at destination if source gets deleted.
        //Removed the peerdn link from the syntetic object, that was pointing to the object that just got deleted
        //If synthetic object has no peerdn, this was the last reference, flag the syntetic object for deletion (The class.apic.php will remove corresponding object in ACI)
	$myLogMsg=date("Y-m-d H:i:s")." -> SPAN GOT: {$inclass} -- {$indn} -- {$inattribute} -- {$invalue} -- {$insystem} -- {$inoperation}\n";
	if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
  	if (!$themap->storage['realEnvironment'] && ($inclass === "fabricEthMonDestEp")) {
	    //8-1-15 taking simulator operations into account - we will catch this SPAN endpoint removal from UCSM and translate to look as if LLDP removal was done from APIC
	    preg_match("/fabric\/lanmon\/(.*?)\//", $indn, $fabricID);
	    preg_match("/\/dest-slot-1-port-(.*)/", $indn, $portID);
	    preg_match("/eth-mon-(.*?)\/dest/", $indn, $monName);
	    $simFabric=$fabricID[1];
	    $simPort=$portID[1];
	    $simSessionName=$monName[1];
	    $myLogMsg=date("Y-m-d H:i:s")." -> We have a removal event for the simulator SPAN endpoint on UCSM:  fabric-{$simFabric}, port-{$simPort}\n";
	    if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
	    foreach($themap->storageindex_class as $key => $value) {
		if(strpos($key,'<=>simulatedCDPLink') > 0) {
		    $simLinkArray = explode("<->", $value);		// this is a string of fabric<->vpcID<->ucsinterface<->node<->leafinterface
		    $myLogMsg=date("Y-m-d H:i:s")." -> We have parsed the simlinkarray into: {$simLinkArray[0]} -- {$simLinkArray[1]} -- {$simLinkArray[2]} -- {$simLinkArray[3]}\n";
		    if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
		    if ($simFabric == $simLinkArray[0]) {
			preg_match("/Ethernet1\/(.*)/", $simLinkArray[1], $portInteger);
			if ($simPort == $portInteger[1]) {
			    $searchString='fabric/lanmon/'.$simLinkArray[0].'/eth-mon-'.$simSessionName.'/dest-slot-1-port-'.$portInteger[1];
			    $myLogMsg=date("Y-m-d H:i:s")." -> Constructed a search string: {$searchString} to compare to indn: {$indn}\n";
			    if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
			    if ($indn === $searchString) {		// we have a hit, as this needs to be transformed
				// We can now clear out all the memory objects that were created
				unset($themap->storageindex_class[$key]);
				$mainStoreKey=get_storagePrefix($key, '<=>');	// this includes the <=>
				unset($themap->storage[$mainStoreKey.'portId']);
				unset($themap->storage[$mainStoreKey.'sysName']);
				unset($themap->storage[$mainStoreKey.'CLASS']);
				unset($themap->storageindex_class[$mainStoreKey.'CLASS']);
				$myLogMsg=date("Y-m-d H:i:s")." -> We have kept indn: {$indn}, and cleared memory tied to key: {$key} and: {$mainStoreKey}\n";
				if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
				break;
			    }
		        }
		    }
	        }
	    }  // 8-1-15 complete
	}
	$removeFlag=false;
	foreach($themap->storage as $key => $value) {
	    if(isset($themap->storage[$key]["peerdn"]) === false) continue;
	    if($themap->storage[$key]["peerdn"] === $indn) {
		$myLogMsg=date("Y-m-d H:i:s")." -> We have found a storage item that had this peerdn of: {$indn}, at: {$key}[peerdn].  Setting state to deleted to remove from APIC\n";
		if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);
	        $tmp = $themap->storage[$key];
	        $tmp['Adminstate'] = 'deleted';
	        $themap->storage[$key]=$tmp;
		$removeFlag=true;
	    }
        }
	if (!$removeFlag) {
		$myLogMsg=date("Y-m-d H:i:s")." -> We could not find a storage item that had this peerdn of: {$indn}.  No action to remove taken\n";
		if ($themap->storage['logSPANoperations']) file_put_contents("spanMessages.txt", $myLogMsg, FILE_APPEND);	    
	}
        foreach($themap->storage as $key => $value) {
	    /*if (strpos($key, $indn) > -1) {
	        echo "found a storage reference to this indn and its key=[{$key}], vardump of value is:\n";
	        var_dump($value);
	    }*/
	    if(strpos($key, '<3>SPAN_ROOT') !== false && $themap->storage[$key]["spanSrcGrp_dn"]  === $indn ) {
		$peerdn = $themap->storage[$key]["peerdn"];
		$peerKey=get_storagePrefix($key,'<2>');
	        if($themap->flowmap[$themap->storage[$peerKey.$peerdn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
		    echo date("Y-m-d H:i:s")." -> SPAN Process Re-synced: {$indn}\n";
		    $tmp=$themap->storage[$key];
		    $tmp["Adminstate"] = "created";
		    $themap->storage[$key]=$tmp;
		    return true;	// 5-19-15
		}
	    }
        }
        foreach($themap->storage as $key => $value) {
	    if(strpos($key, '<3>SPAN_CHILD') !== false && $themap->storage[$key]["spanSrc_dn"]  === $indn ) {
		$peerdn = $themap->storage[$key]["peerdn"];
		$peerKey=get_storagePrefix($key,'<2>');
	        if($themap->flowmap[$themap->storage[$peerKey.$peerdn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
	            echo date("Y-m-d H:i:s")." -> SPAN Process Re-synced: {$indn}\n";
	            $tmp=$themap->storage[$key];
	            $tmp["Adminstate"] = "created";
	            $themap->storage[$key]=$tmp;
	            return true;	// 5-19-15
		}
	    } elseif(strpos($key, '<3>SPAN_CHILD') !== false && $themap->storage[$key]["spanRsSrcToPathEp_dn"]  === $indn ) {
		$peerdn = $themap->storage[$key]["peerdn"];
		$peerKey=get_storagePrefix($key,'<2>');
	        if($themap->flowmap[$themap->storage[$peerKey.$peerdn.'<=>FLOWMAP']]['KEEP_SYNC'] === "TRUE") {
	            echo date("Y-m-d H:i:s")." -> SPAN Process Re-synced: {$indn}\n";
	            $tmp=$themap->storage[$key];
	            $tmp["Adminstate"] = "created";
	            $themap->storage[$key]=$tmp;
	            return true;	// 5-19-15
		}
	    }
        }
    }
    return false;
}

//Main function for the RAW data consumption
function RAW(&$themap, $class, $dn, $attribute, $value, $system, $constructor) {
    date_default_timezone_set('UTC');
    //echo "RAW GOT: {$class} -- {$dn} -- {$attribute} -- {$value} -- {$system} -- {$constructor}\n";

    // For general flowmap troubleshooting, we enable the classes that we get raw from the controllers
    $interestingClass="fvnsVlanInstP";
    /*if($class == $interestingClass) {
	$myLogMsg="********RAW Input of Interest:  class={$class}, dn={$dn}, attribute={$attribute}, value={$value}, system={$system}, constructor={$constructor}\n";
	if ($themap->storage['logGeneraloperations']) file_put_contents("logMessages.txt", $myLogMsg, FILE_APPEND);
    }*/
    
    $flag=0;
    if($constructor === "RAW") {
        foreach($themap->flowmap as $key => $value2) {
	    $flag=0;
	    $flowmapkey=$system.'<A>'.$class.'<B>'.$attribute;
	    $attributekey=$system.'<A>'.$dn.'<B>'.$attribute;
	    if(strpos($key, $flowmapkey) === 0) {
	        $flowmapkey=$key;
	        //CREATE A NEW OBJECT
	        //Check to see if class exists, but this DN is a new one and it is in the scope...
	        if($flag == 0 && isset($themap->flowmap[$flowmapkey]) === true && isset($themap->attributemap[$attributekey]) == false && strpos($dn, $themap->flowmap[$flowmapkey]['SOURCE_SCOPE']) === 0 ) {
		    $flag=1;
		    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "RAW") {
		        $themap->storage[$attributekey.'<=>FLOWMAP'] = $flowmapkey;
		        $themap->storage[$attributekey.'<=>SOURCE_DN'] = $dn;
		        $themap->storage[$attributekey."Adminstate"] = $value;
		        $themap->storage[$attributekey] = $value;			
		        $name=$result = substr( $dn, strrpos( $dn, '/'.$themap->flowmap[$flowmapkey]['SOURCE_PREFIX']) + strlen($themap->flowmap[$flowmapkey]['SOURCE_PREFIX']) + 1);
		        $themap->storage[$attributekey.'<=>DEST_DN'] = $themap->flowmap[$flowmapkey]['DEST_SCOPE'].'/'.$themap->flowmap[$flowmapkey]['DEST_PREFIX'].$name;
		        $word_start = strrpos ($key , "<C>") + 3;
		        $word_end = strrpos ($key , "<D>");
		        $dest_system = substr($key, $word_start, $word_end);
		        $dest_dn = $themap->storage[$attributekey.'<=>DEST_DN'];
		        $dest_attribute = $themap->flowmap[$flowmapkey]['DEST_ATTRIBUTE'];						
		        $dest_attributekey=$dest_system.'<A>'.$dest_dn.'<B>'.$dest_attribute;
		        //echo "-->{$attributekey} <--> {$dest_attributekey} {$value}\n";
		        $themap->storage[$dest_attributekey."<=>DEST_DN"] = $dest_dn;
		        $themap->storage[$dest_attributekey."Adminstate"] = $value;
		        $themap->storage[$dest_attributekey.'<=>FLOWMAP'] = $flowmapkey;			
		        $themap->apiextensions[$dn]=true;
		        $class = $themap->flowmap[$themap->storage[$attributekey.'<=>FLOWMAP']]['SOURCE_CLASS'];
		        $themap->apiextensions[$class]=true;
			echo "6-4-15, [A] just set a NEW apiextension index dn:{$dn} and class:{$class} both to true\n";
		    }
	        }
	        //Update an Existing Object
	        if($flag == 0 && isset($themap->attributemap[$attributekey]) == true) {
		    $flag=1;
		    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "RAW") {
		        $themap->storage[$attributekey.'<=>FLOWMAP'] = $flowmapkey;
		        $themap->storage[$attributekey.'<=>SOURCE_DN'] = $dn;
		        $themap->storage[$attributekey."Adminstate"] = $value;		
		        $themap->storage[$attributekey] = $value;
		        $name=$result = substr( $dn, strrpos( $dn, '/'.$themap->flowmap[$flowmapkey]['SOURCE_PREFIX']) + strlen($themap->flowmap[$flowmapkey]['SOURCE_PREFIX']) + 1);
		        $themap->storage[$attributekey.'<=>DEST_DN'] = $themap->flowmap[$flowmapkey]['DEST_SCOPE'].'/'.$themap->flowmap[$flowmapkey]['DEST_PREFIX'].$name;
		        $themap->apiextensions[$dn]=true;
		        $class = $themap->flowmap[$themap->storage[$attributekey.'<=>FLOWMAP']]['SOURCE_CLASS'];
		        $themap->apiextensions[$class]=true;
			echo "6-4-15, [A] just updated the existing apiextension index dn:{$dn} and class:{$class} both to true\n";
		    }
	        }
	    }
	    $flowmapkey='<A>'.$class.'<B>'.$attribute.'<C>'.$system;
	    $attributekey=$system.'<A>'.$dn.'<B>'.$attribute;
	    if(strpos($key, $flowmapkey) > 0) {	
	        $flowmapkey=$key;
	        //CREATE A NEW OBJECT
	        //Check to see if class exists, but this DN is a new one and it is in the scope...		   
	        if($flag == 0 && isset($themap->flowmap[$flowmapkey]) === true && isset($themap->attributemap[$attributekey]) == false && strpos($dn, $themap->flowmap[$flowmapkey]['DEST_SCOPE']) === 0 ) {
		    $flag=1;
		    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "RAW") {
		        $themap->storage[$attributekey.'<=>FLOWMAP'] = $flowmapkey;
		        $themap->storage[$attributekey.'<=>DEST_DN'] = $dn;
		        $themap->storage[$attributekey] = $value;	
		        $name=$result = substr( $dn, strrpos( $dn, '/'.$themap->flowmap[$flowmapkey]['DEST_PREFIX']) + strlen($themap->flowmap[$flowmapkey]['DEST_PREFIX']) + 1);
		        $themap->storage[$attributekey.'<=>DEST_DN'] = $themap->flowmap[$flowmapkey]['DEST_SCOPE'].'/'.$themap->flowmap[$flowmapkey]['DEST_PREFIX'].$name;
		        //var_dump($themap->storage);
		        $themap->apiextensions[$dn]=true;	
		        $class = $themap->flowmap[$themap->storage[$attributekey.'<=>FLOWMAP']]['SOURCE_CLASS'];
		        $themap->apiextensions[$class]=true;				
			echo "6-4-15, [B] just set a NEW apiextension index dn:{$dn} and class:{$class} both to true\n";
		    }
	        }
	        //Update an Existing Object
	        if($flag == 0 && isset($themap->attributemap[$attributekey]) == true) {
		    $flag=1;
		    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "RAW") {
		        $themap->storage[$attributekey.'<=>FLOWMAP'] = $flowmapkey;
		        $themap->storage[$attributekey.'<=>SOURCE_DN'] = $dn;
		        $themap->storage[$attributekey."Adminstate"] = $value;	
		        $themap->storage[$attributekey] = $value;
		        $name=$result = substr( $dn, strrpos( $dn, '/'.$themap->flowmap[$flowmapkey]['DEST_PREFIX']) + strlen($themap->flowmap[$flowmapkey]['DEST_PREFIX']) + 1);
		        $themap->storage[$attributekey.'<=>DEST_DN'] = $themap->flowmap[$flowmapkey]['DEST_SCOPE'].'/'.$themap->flowmap[$flowmapkey]['DEST_PREFIX'].$name;
		        $themap->apiextensions[$dn]=true;
		        $class = $themap->flowmap[$themap->storage[$attributekey.'<=>FLOWMAP']]['SOURCE_CLASS'];
		        $themap->apiextensions[$class]=true;
			echo "6-4-15, [B] just updated the existing apiextension index dn:{$dn} and class:{$class} both to true\n";
		    }
	        }
	    }
        }
    }
}

// This function will add dynamic flowmaps into the regular flowmap area
function add_dynamicflowmap(&$themap, $flowmapkey, $inclass, $inattr, $invalue, $inindex) {
    date_default_timezone_set('UTC');
    //echo "\nadd_dynamicflowmap GOT: {$flowmapkey} -- {$inclass} -- {$inattr} -- {$invalue} -- {$inindex}\n";
    
    $reloadflag=false;
    if ($inclass === "vmmDomP") {
	// Here, we set to false all the previous work on the keyvaluepair, to start fresh on multiple entries for a dynamic flowmap with the bastard set
	foreach($themap->keyvaluepair as $nukekey=>$nukevalue) {
	    $themap->keyvaluepair[$nukekey] = false;
	}
    }
    foreach($themap->dynamicflowmap as $key=>$value) {
        if($themap->dynamicflowmap[$key]['BASTARD'] === "TRUE" && strpos($themap->dynamicflowmap[$key]['SOURCE_SCOPE'], $inclass.'->'.$inattr) !== false) {
	    $newindex=$inclass.'<A>'.$inattr.'<B>'.$invalue;
	    $themap->keyvaluepair[$newindex] = true;	// set to true meaning not handled yet
	    echo date("Y-m-d H:i:s")." -> Found a bastard at key:{$key}, and set keyvaluepair:{$newindex} to:{$themap->keyvaluepair[$newindex]}\n";
	    $path=$themap->dynamicflowmap[$key]['SOURCE_SCOPE'];
	    $dependencies=array();
	    foreach($themap->keyvaluepair as $key1=>$value1) {
		if (!$value1) continue;
	        $tmpclass=substr($key1, 0, strpos($key1, "<A>"));
	        $tmpattribute= substr($key1, strpos($key1, "<A>") + 3, strpos($key1, "<B>") - strpos($key1, "<A>") -3 );
	        $tmpvalue=substr($key1, strpos($key1, "<B>") + 3);
	        $tmppath=$path;
	        $path=str_replace("#lbl:".$tmpclass."->".$tmpattribute."#",$tmpvalue, $path);
	        if($path !== $tmppath) {
		    $dependencies["#lbl:".$tmpclass."->".$tmpattribute."#"] = $tmpvalue;
	        }
	        if(strpos($path, "#lbl:") === false) {
		    //echo "Object is ready: {$path}\n";
		    $source_system = $themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'];
		    $dest_system = $themap->flowmap[$flowmapkey]['DEST_SYSTEM'];
		    $postname=$themap->dynamicflowmap[$key]['SOURCE_ATTRIBUTES'];
		    reset($postname);
		    $postname=key($postname);
		    $newkey =  "{$source_system}<A>{$inclass}<B>{$postname}<C>{$dest_system}<D>{$path}";
		    $tobereplaced = "#lbl:".$tmpclass."->".$tmpattribute."#";
		    $mo=$themap->flowmap[$flowmapkey];
		    $mo['SOURCE_CLASS']=$themap->dynamicflowmap[$key]['SOURCE_CLASS'];
		    $mo['SOURCE_ATTRIBUTES']=$themap->dynamicflowmap[$key]['SOURCE_ATTRIBUTES'];
		    $mo['SOURCE_SCOPE'] = $path;
		    $mo['PEER_DEPENDENCIES']=$dependencies;
		    $mo['DYNFLOWMAP'] = $key;
		    if(is_null($themap->flowmap[$newkey]) === true) {
		        //echo "1Calling RELOAD!!!!!!!!!!!!!!!!!!!!!!!\n";				
		        $themap->flowmap[$newkey]=$mo; // This is the root mo
		        $reloadflag=true;
			foreach($themap->keyvaluepair as $nukekey=>$nukevalue) {	// clear out all again as we handled this
			    $themap->keyvaluepair[$nukekey] = false;
			}
		    }
	        }		
	    }
        }
        if(strpos($themap->dynamicflowmap[$key]['SOURCE_SCOPE'], "#lbl:".$inclass."->".$inattr."#" ) !== false) {
	    $source_system = $themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'];
	    $dest_system = $themap->flowmap[$flowmapkey]['DEST_SYSTEM'];		    
	    $class = $themap->dynamicflowmap[$key]['SOURCE_CLASS'];
	    $postname=$themap->dynamicflowmap[$key]['SOURCE_ATTRIBUTES'];
	    reset($postname);
	    $postname=key($postname);		    
	    $path=substr($inindex,strpos($inindex, "<2>") + 3);	    
	    $newkey =  "{$source_system}<A>{$class}<B>{$postname}<C>{$dest_system}<D>{$path}";
	    $tobereplaced = "#lbl:".$inclass."->".$inattr."#";
	    $mo=$themap->flowmap[$flowmapkey];
	    $mo['SOURCE_CLASS']=$themap->dynamicflowmap[$key]['SOURCE_CLASS'];
	    $mo['SOURCE_ATTRIBUTES']=$themap->dynamicflowmap[$key]['SOURCE_ATTRIBUTES'];
	    $mo['SOURCE_SCOPE'] = $path;
	    $mo['PEER_DEPENDENCIES'][$tobereplaced]=$inindex;
	    $mo['DYNFLOWMAP'] = $key;
	    if(is_null($themap->flowmap[$newkey]) === true) {
	        //echo "2Calling RELOAD!!!!!!!!!!!!!!!!!!!!!!!\n";
	        $themap->flowmap[$newkey]=$mo; // This is the root mo
	        $reloadflag=true;
	    }
        }
    }
    //We always reload the ACI flowmaps, but only ucs if class matches the junkfilter as the whole of anything happening on UCS will come in here
    if($reloadflag === true) {
        if(isset($themap->junkfilter[$inclass])) {
	    reload_flowmap_UCS($themap);
	}
        reload_flowmap_ACI($themap);
        $reloadflag=false;
    }
}
 
//This is the function that receives all objects and attributes from the events received by ACI and UCS event subscription classes
//Purpose with this function is to determine if an attribute is of intrest to us (Based on flowmap meta map), if so store it in memory, if not discard it
//Based on the flowmap meta data, the Constructor will determine what use case funtions to call (This is the stimuli for syntetic object modifications)
//The doer will call the main user story based on flowmap Constuctor, which may be subparts of each case
//The doer will only store attributes it defined in flowmap, either source attribute or destination attributes, all other attributes are ignored
//
function doer(&$themap, $class, $dn, $attribute, $value, $system, $constructor) {
    date_default_timezone_set('UTC');
   // echo "DOER: {$class} {$dn} {$attribute} {$value} {$system} {$constructor}\n";
    
    // For general flowmap troubleshooting, we enable the classes that we get raw from the controllers
    //$interestingClass="none";
    $interestingClass="fabricMulticastPolicy";
    //$interestingClass="lldpAdjEp";
    //$interestingClass="infraHPortS";    
    //$interestingClass="fabricExplicitGEp";    
    //$interestingClass="fvnsVlanInstP";
    //$interestingClass="fabricVlan";
    //$interestingClass="fabricEthLanPc";
    //$interestingClass="fabricEthLanPcEp";
    //$interestingClass="vmmCtrlrP";
    if($class === $interestingClass) {
	$attrCount=$themap->ucsstack['attrCounter'];
	if (is_null($attrCount)) $attrCount=0;
	$attrCount++;
	$themap->ucsstack['attrCounter']=$attrCount;
	$myLogMsg=date("Y-m-d H:i:s")." -> DOER Input of Interest:  class={$class}, dn={$dn}, attribute#{$attrCount}={$attribute}, value={$value}, system={$system}, constructor={$constructor}\n";
	if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
    }
    
    $keepaliveAPICPoolName="KEEPALIVEPOOL";
    $keepaliveUCSMVLANname="B2GKEEPALIVE";

    if ((strstr($dn, "fabric/lan/net-".$keepaliveUCSMVLANname) != NULL)) {
	$myLogMsg=date("Y-m-d H:i:s")." <<<<<UCSM Event Subscription Keepalive Event Received for dn: {$dn} from system: {$system} with attribute: {$attribute} and value: {$value}\n";
	if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
	echo $myLogMsg;
    }
    if ((strstr($dn, "uni/infra/vlanns-[".$keepaliveAPICPoolName."]") != NULL) && ($class === "fvnsVlanInstP") && ($attribute === "name")) {
	$myLogMsg=date("Y-m-d H:i:s")." <<<<<APIC Event Subscription Keepalive Event Received for dn: {$dn} from system: {$system} with attribute: {$attribute} and value: {$value}\n";
	if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
	echo $myLogMsg;
    }

    if(is_null($themap->soakqueue[$dn]) === false) {
	$myLogMsg=date("Y-m-d H:i:s")." -> We have an input element in doer that was in the soakqueue for deletion.\n\tclass:{$class}, dn:{$dn}, attribute:{$attribute}, value:{$value}, system:{$system}, constructor:{$constructor}\n";
	if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
        nukechillpill($themap, $class, $dn, $system);
	$myLogMsg=date("Y-m-d H:i:s")." -> Just called nukechillpill for class:[{$class}], dn:[{$dn}], ip:[{$system}]\n";
	if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
    }
    if(check_storage_key($themap, $dn, $attribute, $value, $system) === true) {
	$myLogMsg=date("Y-m-d H:i:s")." -> Checked storage key, and the return was true so we are returning as this was a repeat send.\n";
	if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
        return true;
    }
    if($constructor === "RAW") {
        RAW($themap, $class, $dn, $attribute, $value, $system, $constructor);
    }

    //CUSTOM DOERS
    //echo "=======================<<<<<<<<<<<<<< CUSTOM DOER >>>>>>>>>>>>>>>>>>===========================\n";
    if($themap->storage[$system.'<B>'.$dn.'<=>BLACKLIST'] !== true) {
 	$breakWhenDone=false;
        foreach($themap->flowmap as $key => $value2) {
	    if(isset($themap->flowmap[$key]['SOURCE_CLASS']) == true) {
		if ($class === $interestingClass) {
		    if ($themap->flowmap[$key]['SOURCE_CLASS'] === $interestingClass) {
			$myLogMsg=date("Y-m-d H:i:s")." -> Good flowmap found - class: {$themap->flowmap[$key]['SOURCE_CLASS']} -- {$class}, {$attribute}: {$themap->flowmap[$key]['SOURCE_SCOPE']} -- {$dn}\n";
			if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
		    }
		}
		//If the new attribute (And object) information is defined as source information in flowmap
		$srcHandled = false;
		$rightSrcClass=false;
		$scopeString = $themap->flowmap[$key]['SOURCE_SCOPE'];
		if($themap->flowmap[$key]['SOURCE_CLASS'] === $class) {
		    $rightSrcClass=true;
		    $rightSrcScope=compare_dn($dn, $scopeString);
		    if ($class === $interestingClass) {
		        if ($rightSrcScope) {
			    $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: source class/scope testing: source class={$class} DOES match, and we DID match the source scope=[{$scopeString}] with dn=[{$dn}]\n";
			} else {
			    $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: source class/scope testing: source class={$class} DOES match, and we DID NOT match the source scope=[{$scopeString}] with dn=[{$dn}]\n";
			}
			if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);	// Do not break this when done, as we loop to next flowmap to try to find match
		    }
		}
		if($rightSrcClass && $rightSrcScope) {
		    $breakWhenDone=true;
		    if ($class == $interestingClass) {
		        $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: we have the right source class: {$class} and scope: {$scopeString}, so going into the source attribute section.\n";
		        if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
		    }
		    $flowmapkey=$key;
		    //Add Source Attributes	    
		    if(isset($themap->flowmap[$flowmapkey]['SOURCE_ATTRIBUTES'])) {
		        foreach($themap->flowmap[$flowmapkey]['SOURCE_ATTRIBUTES'] as $attr=>$value3) {
			    if ($class == $interestingClass) {
			        $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: looking at the memory attribute of: {$attr} vs the input attribute of: {$attribute}\n";
			        if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
			    }
			    if($attribute === $attr) {
			        if($value3 === "") {
			            $value3=".*";
			        }
			        if ($class == $interestingClass) {
			            $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: looking for the regex match of value=[{$value}], within regex value3=[{$value3}], flowmapkey=[{$flowmapkey}]\n";
				    if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
				}
				if(empty($value) === true || preg_match("/".$value3."/",$value) == true) {
				    if ($class == $interestingClass) {
				        $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: Reading the source attributes and we have a regex match, ADDING-:({$class}) -- {$dn} -- {$attr}={$value}\n";
				        if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
				    }
				    $themap->debugger->dwrite("ADDING-:({$class}) -- {$dn} -- {$attr}={$value}\n");
				    $index=$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn;
				    $srcHandled=true;
				    $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>'.$attribute] = $value;
				    $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>'.$attribute."Adminstate"] = $value;
				    add_dynamicflowmap($themap, $flowmapkey, $class, $attr, $value, $index);
				} else {
				    $myLogMsg=date("Y-m-d H:i:s")." -> BLACKLISTED AT SOURCE SIDE: {$dn}\n";
				    if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
				    echo $myLogMsg;
				    $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<B>'.$dn.'<=>BLACKLIST']=true;			    
				    foreach($themap->storage as $bkey=>$bvalue) {
				        if(strpos($bkey,$system."<1>") === 0 && strpos($bkey,"<2>".$dn."<=>") !== false ) {
					    unset($themap->storage[$bkey]);
					    unset($themap->storageindex_class[$bkey]);
					}
				    }
				}
				break;
			    }
			}
		    }
		    if($srcHandled) {
		        if ($class == $interestingClass) {
			    $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: Setting source side memory objects: flowmapkey={$flowmapkey}\n";
			    if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
			}
			$themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>CLASS'] = $class;
			$themap->storageindex_class[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>CLASS'] = $class;
			$themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>FLOWMAP'] = $flowmapkey;
			$themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>CONSTRUCTOR'] = $themap->flowmap[$flowmapkey]['CONSTRUCTOR'];
			$themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>PARENT'] = $themap->flowmap[$flowmapkey]['PARENT'];
			$themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>SOURCE_SCOPE'] = $themap->flowmap[$flowmapkey]['SOURCE_SCOPE'];
			break;
		    }
		}
	    }
	    if(isset($themap->flowmap[$key]['DEST_CLASSES']) == true) {
		//Add destination attributes as well
		$dstHandled = false;
		$rightDstClass=false;
	        foreach($themap->flowmap[$key]['DEST_CLASSES'] as $class2=>$scope2) {
		    if($class2 === $class) {
			$rightDstClass=true;
			$rightDstScope=compare_dn($dn, $scope2);
			if ($class2 === $interestingClass) {
			    if ($rightDstScope) {
				$myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: dest class/scope testing: dest class={$class2} DOES match, and we DID match the dest scope=[{$scope2}] with dn=[{$dn}]\n";
			    } else {
				$myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: dest class/scope testing: dest class={$class2} DOES match, and we DID NOT match the dest scope=[{$scope2}] with dn=[{$dn}]\n";
			    }
			    if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
			}
		    }
		    if($rightDstClass && $rightDstScope) {
			if ($class2 == $interestingClass) {
			    $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: We have the right dest class and scope, so going into the dest attribute section.\n";
			    if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
			}
		        $flowmapkey=$key;    
		        if(isset($themap->flowmap[$flowmapkey]['DEST_ATTRIBUTES'])) {
			    foreach($themap->flowmap[$flowmapkey]['DEST_ATTRIBUTES'] as $attr=>$value3) {
			        if($attribute === $attr) {
				    if($value3 === "") {
				        $value3=".*";
				    }
				    if(empty($value) === true || preg_match("/".$value3."/",$value) == true) {
				        $themap->debugger->dwrite("ADDING+:({$class2}) -- {$dn} -- {$attr}={$value}\n");
					if ($class2 == $interestingClass) {
					    $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: adding destination memory objects to track: class:{$class2}, dn:{$dn}, attr:{$attr}, value:{$value}\n";
					    if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
					}
				        $dstHandled=true;
				        $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>'.$attribute] = $value;
				        $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>'.$attribute."Adminstate"] = $value;
				        break;
				    } else {
				        $myLogMsg=date("Y-m-d H:i:s")." -> BLACKLISTED AT DEST SIDE: {$dn}\n";
					if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
					echo $myLogMsg;
				        $themap->storage[$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<B>'.$dn.'<=>BLACKLIST']=true;
				        foreach($themap->storage as $bkey=>$bvalue) {
					    if(strpos($bkey,$system."<1>") === 0 && strpos($bkey,"<2>".$dn."<=>") > -1) {
					        unset($themap->storage[$bkey]);
					        unset($themap->storageindex_class[$bkey]);
					    }
				        }	    
				    }
			        }
			    }
		        }
		        if($dstHandled) {
			    if ($class2 == $interestingClass) {
				$myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: setting dest side memory objects: flowmapkey={$flowmapkey}\n";
				if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
				echo $myLogMsg;
			    }
			    $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>CLASS'] = $class2;
			    $themap->storageindex_class[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>CLASS']  = $class2;
			    $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>FLOWMAP'] = $flowmapkey;
			    $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>CONSTRUCTOR'] = $themap->flowmap[$flowmapkey]['CONSTRUCTOR'];
			    $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>PARENT'] = $themap->flowmap[$flowmapkey]['PARENT']."_DEST";   
			    $themap->storage[$themap->flowmap[$flowmapkey]['SOURCE_SYSTEM'].'<1>'.$themap->flowmap[$flowmapkey]['DEST_SYSTEM'].'<2>'.$dn.'<=>SOURCE_SCOPE'] = $scope2;
			    break 1;
		        }
		    }
	        }
	    }
	    if($breakWhenDone) break;
        }
	if (isset($flowmapkey)) {
	    //if applicable, call the main user story class, based on constuctor definition in flowmap
	    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "SPAN" && ($srcHandled || $dstHandled)) {
	        //echo "CALLING SPAN!!!!!\n";
	        SPAN($themap, $class, $dn, $attribute, $value, $system, "DOER");
	    }
	    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "VMM" && ($srcHandled || $dstHandled)) {
	        //echo "CALLING VMM!!!!!\n";
	        VMM($themap, $class, $dn, $attribute, $value, $system, "DOER");
	    }
	    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "BM" && ($srcHandled || $dstHandled)) {
	        //echo "CALLING BM!!!!!\n";
	        BM($themap, $class, $dn, $attribute, $value, $system, "DOER");
	    }
	    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "VPC" && ($srcHandled || $dstHandled)) {
	        //echo "CALLING VPC!!!!!\n";
	        VPC($themap, $class, $dn, $attribute, $value, $system, "DOER");
	    }
	    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "RACK" && ($srcHandled || $dstHandled)) {
	        //echo "CALLING RACK!!!!!\n";
	        RACK($themap, $class, $dn, $attribute, $value, $system, "DOER");
	    }
	    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "VMM-BM" && ($srcHandled || $dstHandled)) {
	        //echo "Received Event for VMM, then BM\n";
	        VMM($themap, $class, $dn, $attribute, $value, $system, "DOER");
	        BM($themap, $class, $dn, $attribute, $value, $system, "DOER");
	    }
	    if($themap->flowmap[$flowmapkey]['CONSTRUCTOR'] === "VPC-BM" && ($srcHandled || $dstHandled)) {
	        //echo "Received Event for VPC, then BM\n";
	        VPC($themap, $class, $dn, $attribute, $value, $system, "DOER");
	        BM($themap, $class, $dn, $attribute, $value, $system, "DOER");
	    }
	}
	if (isset($rightSrcScope) && isset($rightSrcClass)) {
	    if ($class === $interestingClass) {
	        $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: determine return value from SRC - rightSrcScope={$rightSrcScope}, rightSrcClass={$rightSrcClass}\n";
	        if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
		if (isset($rightDstScope) && isset($rightDstClass)) {
		    $myLogMsg=date("Y-m-d H:i:s")." -> In doer handling: determine return value from DST - rightDstScope={$rightDstScope}, rightDstClass={$rightDstClass}\n";
		    if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
		}
	    }
	    if ($rightSrcScope && $rightSrcClass) {
	        return true;	// this means keep sending more attributes in
	    }
	}
	return false;	// this means the inspection is not on the right path, continue to next dn
    }
}
 
/*function common_get_class($themap, $class) {
    unset($mo);
    foreach($themap->storage as $key => $value) {    
        if(strpos($key, "<=>FLOWMAP") > -1 && $attribute = $themap->flowmap[$value]['SOURCE_CLASS'] === $class) {
	    $index=substr($key, 0 , strrpos($key,"<=>FLOWMAP"));
	    $dn=$themap->storage[$index."<=>SOURCE_DN"];
	    $attribute=$themap->flowmap[$value]['SOURCE_ATTRIBUTE'];
	    $attribvalue=$themap->flowmap[$value]['SOURCE_ATTRIBUTE'];
	    $mo[$dn][$attribute]=$attribvalue;
        }   
    }
    unset($tmp);
    $counter=0;
    foreach($mo as $dn => $value) {
        $tmp[$counter]["dn"]=$dn;
        foreach($value as $key2=>$value2) {
	    $tmp[$counter][$key2]=$value2;	    
        }
        $counter++;
    }
    return $tmp;
}

function common_get_objectmap($themap) {
    $counter=0;
    unset($map);
    //$map=array(array());
    foreach($themap->storage as $key => $value) {
        if(strpos($key, "<=>FLOWMAP") > -1) {
	    //echo "FOUND {$key}\n";		
	    $index=substr($key, 0 , strrpos($key,"<=>FLOWMAP"));
	    $word_start =strrpos ($key , "<A>")+3;
	    $word_end = strrpos ($key , "<B>")-$word_start;
	    $dn = substr($key, $word_start, $word_end);
	    //		echo "DN is: {$dn} -- {$value} {$word_start} {$word_end}\n";
	    $word_start =strrpos ($key , "<B>")+3;
	    $word_end = strrpos ($key , "<=>FLOWMAP")-$word_start;
	    $attribute=substr($key, $word_start, $word_end);
	    $map[$counter]["SOURCE_DN"] = $themap->storage[$index."<=>SOURCE_DN"];
	    $map[$counter]["DEST_DN"] = $themap->storage[$index."<=>DEST_DN"];
	    $map[$counter]["SOURCE_ATTRIBUTE"] = $themap->flowmap[$value]['SOURCE_ATTRIBUTE'];
	    $map[$counter]["DEST_ATRIBUTE"] = $themap->flowmap[$value]['DEST_ATTRIBUTE'];
	    $map[$counter]["SOURCE_CLASS"] = $themap->flowmap[$value]['SOURCE_CLASS'];
	    $map[$counter]["DEST_CLASS"] = $themap->flowmap[$value]['DEST_CLASS'];
	    $map[$counter]["VALUE"] = $themap->storage[$index];
	    $map[$counter]["VALUE_ADMINSTATE"] = $themap->storage[$index."Adminstate"];
	    $counter++;
        }
    }
    //var_dump($map);
    return $map;
}*/

/*function common_clear_dn_byclass($themap,$class) {
    foreach($themap->storage as $key => $value) {
        if(strpos($key, "<=>FLOWMAP") !== false && $attribute = $themap->flowmap[$value]['SOURCE_CLASS'] === $class) {	    	    
	    $word_start =strrpos ($key , "<A>")+3;
	    $word_end = strrpos ($key , "<B>")-$word_start;
	    $dn = substr($key, $word_start, $word_end);
	    $themap->apiextensions[$dn]=false;
        }
    } 	    
    return true;
}

function common_clear_class($themap,$class) {
    $themap->apiextensions[$class]=false;
    return true;
}*/

function get_constructor(&$themap, $inclass) {
    //echo "IN CONSTRUCTOR ($inclass)\n";
    foreach($themap->flowmap as $key=>$value) {
        if($themap->flowmap[$key]['SOURCE_CLASS'] === $inclass) {
	    return $themap->flowmap[$key]['CONSTRUCTOR'];
        }
        foreach($themap->flowmap[$key]['DEST_CLASSES'] as $key1=>$value1) {
	    if($key1 === $inclass) {
	        return $themap->flowmap[$key]['CONSTRUCTOR'];
	    }		    
        }
    }
    foreach($themap->dynamicflowmap as $key=>$value) {
        if($themap->dynamicflowmap[$key]['SOURCE_CLASS'] === $inclass) {
	    return $themap->dynamicflowmap[$key]['CONSTRUCTOR'];
        }
        foreach($themap->dynamicflowmap[$key]['DEST_CLASSES'] as $key1=>$value1) {
	    if($key1 === $inclass) {
	        return $themap->dynamicflowmap[$key]['CONSTRUCTOR'];
	    }		    
        }	    
    }
    return false;
}

//This class is called from UCS and ACI eventsubscription classes, if the event recveived indicating that the object was removed
//The $soak variable is not used by the UCS and ACI eventsubscription, but by the soaker, if true, it means it's comming from soaker rather than an event 
function remove_dn(&$themap, $class, $dn, $ip, $soak=false) {
    date_default_timezone_set('UTC');
    //echo "REMOVE_DN: class:{$class} dn:{$dn} system:{$ip}\n";	

    $keepaliveAPICPoolName="KEEPALIVEPOOL";
    $keepaliveUCSMVLANname="B2GKEEPALIVE";

    if (strstr($dn, "fabric/lan/net-".$keepaliveUCSMVLANname) != NULL) {
	$myLogMsg=date("Y-m-d H:i:s")." <<<<<UCSM ES Keepalive Event Received for dn: {$dn} from system: {$ip} and class: {$class} - removal.\n";
	if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
	echo $myLogMsg;
    }
    if ((strstr($dn, "uni/infra/vlanns-[".$keepaliveAPICPoolName."]") != NULL) && ($class === "fvnsVlanInstP")) {
	$myLogMsg=date("Y-m-d H:i:s")." <<<<<APIC ES Keepalive Event Received for dn: {$dn} from system: {$ip} with class: {$class} - removal\n";
	if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
	echo $myLogMsg;
    }

    $constructor=get_constructor($themap, $class);
    if($constructor === false) {
        return false;
    }
    //Sent the remove request to soaker if applicable
    if($soak === false && $class === 'lldpAdjEp') {
        //echo "class:{$class} for dn:{$dn} for ip:{$ip} is asked to take a chill pill..., returning from remove_dn handler.\n";
        givechillpill($themap, $class, $dn, $ip);
        return false;
    }
    if($soak === true) {
	// The operation here is if we get lldp removals from APIC, while the configuration on the UCS says we need it,
	// we will not listen to lldp events to determine if we remove objects in memory
        echo "class:{$class} for dn:{$dn} for ip:{$ip} is back from its chill pill trip, time to complete the delayed remove_dn action.\n";
	foreach($themap->ucsstack as $key=>$value) {
	    if(strpos($key,"-nodes-ports") > 0) {
		foreach($themap->ucsstack[$key] as $key2=>$value2) {
		    $portArray=explode("<=>", $value2);
		    // index0 is the node, and index1 is the port on that node - construct the entry to test
		    $searchString='node-'.$portArray[0].'/sys/lldp/inst/if-[eth1/'.$portArray[1].']/adj-1';
		    $myLogMsg=date("Y-m-d H:i:s")." -> Looking if searchString:{$searchString} is in dn:{$dn} in order to block this LLDP from getting through\n";
		    if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
		    if (strstr($dn,$searchString) != NULL) {
			$myLogMsg=date("Y-m-d H:i:s")." -> Blocking the LLDP event from removing the configured VPC information related to: {$dn}\n";
			if ($themap->storage['logVPCoperations']) file_put_contents("vpcMessages.txt", $myLogMsg, FILE_APPEND);
			echo $myLogMsg;
			return;
		    }
		}
	    }
	}
    }
    //Call the main user story function if any action should be taken
    $didWeResync=false;
    if($constructor === "VPC") {
        $didWeResync = VPC($themap, $class, $dn, "", "", $ip, "REMOVE_DN");
    }
    if($constructor === "SPAN") {
        $didWeResync = SPAN($themap, $class, $dn, "", "", $ip, "REMOVE_DN");
    }
    if($constructor === "VMM") {
        $didWeResync = VMM($themap, $class, $dn, "", "", $ip, "REMOVE_DN");
    }
    if($constructor === "BM") {
        $didWeResync = BM($themap, $class, $dn, "", "", $ip, "REMOVE_DN");
    }
    if($constructor === "RACK") {
        $didWeResync = RACK($themap, $class, $dn, "", "", $ip, "REMOVE_DN");
    }
    if($constructor === "VMM-BM") {
	//echo "Received Remove Event for VMM, then BM\n";
        $resync1 = VMM($themap, $class, $dn, "", "", $ip, "REMOVE_DN");
        $resync2 = BM($themap, $class, $dn, "", "", $ip, "REMOVE_DN");
	$didWeResync = $resync1 | $resync2;
    }
    if($constructor === "VPC-BM") {
	//echo "Received Remove Event for VPC, then BM\n";
        $resync1 = VPC($themap, $class, $dn, "", "", $ip, "REMOVE_DN");
        $resync2 = BM($themap, $class, $dn, "", "", $ip, "REMOVE_DN");
	$didWeResync = $resync1 | $resync2;
    }
    //Cleanup...
    if($didWeResync === false) {
	$myLogMsg=date("Y-m-d H:i:s")." -> We did not re-sync this remove_dn of {$dn} in class of {$class}, so removing synthetic objects from memory\n";
	if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
	echo $myLogMsg;
        unset($themap->apiextensions[$dn]);
        $themap->apiextensions[$class]=true;
        foreach($themap->storage as $key => $value) {
	    if(strpos($key, $ip."<A>".$dn."<B>") === 0) {
	        $index="";
	        if(strrpos($key,"<=>") > -1) {
		    $index=substr($key, 0 , strrpos($key,"<=>"));
	        } else {
		    $index=$key;
	        }
	        $flowmapkey = $themap->storage[$index."<=>FLOWMAP"];
	        $dest_class = $themap->flowmap[$flowmapkey]['DEST_CLASS'];
	        $dest_attribute = $themap->flowmap[$flowmapkey]['DEST_ATTRIBUTE'];
	        if(strrpos($flowmapkey, $ip."<A>".$dest_class.'<B>'.$dest_attribute) === 0 && strlen($themap->storage[$index.'<=>SOURCE_DN']) > 0) {
		    $dest_dn = $themap->storage[$index.'<=>DEST_DN'];
		    $dest_system = $themap->flowmap[$flowmapkey]['DEST_SYSTEM'];
		    $dest_attributekey=$dest_system.'<A>'.$dest_dn.'<B>'.$dest_attribute;		
		    $themap->storage[$dest_attributekey."Adminstate"] = "<DELETE>";
	        }		    
		$myLogMsg=date("Y-m-d H:i:s")." -> First check cleaning up storage and storage_index key: {$key}\n";
		if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
	        unset($themap->storage[$key]);
	        unset($themap->storageindex_class[$key]);
	    }
	    if(strpos($key, $ip."<1>") === 0 && strpos($key, "<2>".$dn."<") > -1) {
	        foreach($themap->storage as $key2=>$value2) {
		    if(strpos($key2,"<3>SPAN") > -1 && isset($themap->storage[$key2]['peerdns']) == true) {
		        foreach($themap->storage[$key2]['peerdns'] as $key3=>$value3){
			    //echo"PARSING:{$value3} vs {$dn}\n";
			    if($value3 == $dn) {
			        //echo "FLAGGED FOR DELETION: {$key2}\n";
			        $tmp=$themap->storage[$key2];
			        $tmp["Adminstate"] = "deleted";
			        $themap->storage[$key2]=$tmp;	
			        break 3;
			    }
		        }
		    }
	        }
		$myLogMsg=date("Y-m-d H:i:s")." -> Second check cleaning up storage and storage_index key: {$key}\n";
		if ($themap->storage['logDoerCalls']) file_put_contents("doerMessages.txt", $myLogMsg, FILE_APPEND);
	        unset($themap->storage[$key]);
	        unset($themap->storageindex_class[$key]);		    
	    }	    
        }
    }
/*
	$dest_attributekey=$dest_system.'<A>'.$dest_dn.'<B>'.$dest_attribute;
	
	//echo "-->{$attributekey} <--> {$dest_attributekey} {$value}\n";
	$themap->storage[$dest_attributekey."<=>DEST_DN"] = $dest_dn;
	$themap->storage[$dest_attributekey."Adminstate"] = $value;
	$themap->storage[$dest_attributekey.'<=>FLOWMAP'] = $flowmapkey;
*/
    //echo "Completion of remove_dn handler\n";
    return true;
}

/*function common_check_dn($themap,$dn) {
    return $themap->apiextensions[$dn];
}

function common_check_class($themap,$class) {
    return $themap->apiextensions[$class];
}*/

?>