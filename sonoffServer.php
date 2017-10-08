<?php

#
# ITEAD Sonoff SWITCH Cloud Server
#

#
# (v) Vitaly Ponomarev, vitaly.ponomarev@gmail.com
# Publish date: 2017/10/08
#

// ============================ CONFIG START ======================
$configFile = "sonoffServer.config.json";

$IOTServer = array(
    'serverIP'		=> '192.168.1.16',
    'configPort'	=> 2443,
    'iotPort'		=> 2333,
    'ssl'		=> array(
	'local_cert'		=> './ssl/selfcert.in.crt',
	'local_pk'		=> './ssl/selfcert.in.pem',
    ),
);


// ============================ CONFIG END   ======================
global $IOTServer, $uList, $LogFile, $cList, $configFile, $config;


require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;


//
// Stop with error if we cannot load config file
if (!loadConfig()) {
    die("Cannot load configuration file [".$configFile."]\n");
}

if (!isset($config['config']['log']['fileName'])) {
    print "WARNING. Log file is not specified, logs will be displayed only into console!\n";
}

// Init TimeZone
ini_set('date.timezone', isset($config['timezone'])?$config['timezone']:'Europe/Moscow');


//
// Config file management
function loadConfig() {
    global $config, $configFile;
    
    if (($data = file_get_contents($configFile)) == FALSE) {
	// Cannot read file
	return false;
    }

    // search and remove comments like /* */ and //
    $data = preg_replace("#(/\*([^*]|[\r\n]|(\*+([^*/]|[\r\n])))*\*+/)|([\s\t]//.*)|(^//.*)#", '', $data);

    // Try to decode JSON
    if (($c = json_decode($data, true)) !== NULL) {
	$config = $c;

	// Repopulate some sections
	if (!isset($config['config']))
	    $config['config'] = array();

	// Enable console logging if [log] section is not defined
	if (!isset($config['config']['log']))
	    $config['config']['log']['console'] = true;


    } else {
	// Cannot parse JSON
	return false;
    }
    return true;
}


// Logger
function xLog($conn, $level, $entity, $text) {
    global $config, $uList;

    $identity = "[?]";
    if (is_object($conn) && isset($conn->id)) {
        $identity = "[".$conn->id. ((isset($uList[$conn->id]) && isset($uList[$conn->id]['info']['deviceInfo']['apikey']))?", ".$uList[$conn->id]['info']['deviceInfo']['apikey']:", A=".$conn->getRemoteAddress())."]";
    }
    $LogInfo = strftime("%F %T")." ".$level." ".$entity." ".$identity." ".$text."\n";

    if ($config['config']['log']['console'])
	print $LogInfo;

    if (isset($config['config']['log']['fileName']) && ($f = fopen($config['config']['log']['fileName'], "a"))) {
	fwrite($f, $LogInfo);
	fclose($f);

	return true;
    }
    return false;
};


// IPC Service
$cServer = new Channel\Server('0.0.0.0', 2206);

//
// HTTPS Server: configuration + status page
//
$cServer = new Worker('http://'.$IOTServer['serverIP'].':'.$IOTServer['configPort'], array('ssl' => $IOTServer['ssl']));
$cServer->transport = 'ssl';
$cServer->count = 1;

// onMessage event
$cServer->onMessage = function($conn, $data) use ($uList){
    global $IOTServer, $uList, $cList, $config;

    // Get URL
    $requestURI = $_SERVER['REQUEST_URI'];
    if (preg_match("#^(.+?)\?#", $requestURI, $m)) {
	$requestURI = $m[1];
    }
    xLog($conn, "D", "HTTP", "Received request to: [".$requestURI."]");
    
    switch($requestURI) {
	// Dispatch request
	case '/dispatch/device':
	    $reqRaw = $GLOBALS['HTTP_RAW_POST_DATA'];
	    if (($req = json_decode($reqRaw, true)) !== null) {
		// Received valid JSON string
		$resp = json_encode( array('error' => 0, 'reason' => 'ok', 'IP' => $IOTServer['serverIP'], 'port' => $IOTServer['iotPort']) ) ;

		xLog($conn, "I", "HTTP", "Dispatch request from [".$req['apikey']."][".$req['deviceid']."][".$req['model']."], routing to ".$IOTServer['serverIP'].":".$IOTServer['iotPort']);
		xLog($conn, "D", "HTTP", "Request data: ".$reqRaw);
		xLog($conn, "D", "HTTP", "Resp data: ".$resp);

		$conn->send($resp);
		$conn->close();
	    } else {
		// Incorrect request
		xLog($conn, "E", "HTTP", "Incorrect dispatch request received from: ".$conn->getRemoteAddress());
		xLog($conn, "D", "HTTP", "Request data: ".$reqRaw);
		$conn->send('<html><body>Incorrect request format, sorry.<br/>'.$reqRaw.'</body></html>');
		$conn->close();
	    }
	    break;

	// Request for list of active devices
	case '/':
	case '/list':
	    // Load template
	    if (($data = file_get_contents("template/list.html")) !== FALSE) {
		$conn->send($data);
	    } else {
		$conn->send("<html><body>Cannot open template file [teplate/list.html]</body></html>");
	    }

	    $conn->close();
	    return;

	// Request for config reload
	case '/reload':
	    // Try to load config file
	    $conn->send("<html><body>Load config request: ".(loadConfig()?"DONE":"FAILED")."</br></body></html>\n");
	    $conn->close();
	    return;

	// API: List of connected devices
	case '/api/get':
	    $aList = array();
	    foreach ($uList as $uk => $uv) {
		if (isset($uv['deviceInfo']['apikey'])) {
		    $rec = array(
			'apikey'	=> $uv['deviceInfo']['apikey'],
			'deviceid'	=> isset($uv['deviceInfo']['deviceid'])?$uv['deviceInfo']['deviceid']:'',
			'model'		=> isset($uv['deviceInfo']['model'])?$uv['deviceInfo']['model']:'',
			'lastUpdate'	=> isset($uv['lastUpdateTime'])?(time()-$uv['lastUpdateTime']):-1,
		    );
		    if (isset($config['devices']) && isset($config['devices'][$uv['deviceInfo']['apikey']]) && isset($config['devices'][$uv['deviceInfo']['apikey']]['alias'])) {
			$rec['alias']	= $config['devices'][$uv['deviceInfo']['apikey']]['alias'];
		    }
		    // Calculate lastSeen time
		    $lastSeen = max(isset($uv['lastUpdateTime'])?$uv['lastUpdateTime']:-1, isset($uv['lastPingTime'])?$uv['lastPingTime']:-1);
		    if ($lastSeen > 0) {
			$rec['lastSeen'] = time() - $lastSeen;
		    }

		    if (isset($uv['params']['rssi'])) {
			$rec['rssi'] = $uv['params']['rssi'];
		    }
		    if (isset($uv['params']['switch'])) {
			$rec['state'] = $uv['params']['switch'];
		    }

		    $aList []= $rec;
		}
	    }
	    $conn->send(json_encode($aList));
	    $conn->close();
	    return;
	
	// API: Update state
	case '/api/set':
	    // Check for mondatory keys
	    foreach (array('apikey', 'state') as $mKey) {
		if (!isset($_GET[$mKey])) {
		    $conn->send(json_encode(array('error' => 1, 'reason' => $mKey.' parameter is not set')));
		    $conn->close();
		    return;
		}
	    }

	    if (!in_array($_GET['state'], array('on', 'off'))) {
		    $conn->send(json_encode(array('error' => 2, 'reason' => $_GET['state'].': incorrect state, only [on, off] is accepted')));
		    $conn->close();
		    return;
	    }

	    // Search for user
	    foreach ($uList as $uk => $uv) {
		if (isset($uv['deviceInfo']['apikey']) && ($uv['deviceInfo']['apikey'] == $_GET['apikey'])) {
		    // LOG
		    xLog($conn, "I", "HTTP", "Update request for [".$_GET['apikey']."] => [".$_GET['state']."]");

		    // GOT IT! Send update request to WS worker
		    Channel\Client::publish('update', serialize(array('apikey' => $_GET['apikey'], 'state' => $_GET['state'], 'connID' => $conn->id)));

		    // Save client into $cList array with pending request info
		    $cList[$conn->id] = array('connection' => $conn, 'apikey' => $_GET['apikey'], 'state' => $_GET['state']);

		    // Wait for answer via Channel or close connection with TIMEOUT in case if no resp via Channel
		//    $conn->send(json_encode(array('error' => 0, 'reason' => 'Request is sent in background')));
		//    $conn->close();
		    return;
		}
	    }
	    // Device is not found
	    xLog($conn, "E", "HTTP", "Update request for [".$_GET['apikey']."] => device is not connected now");
	    $conn->send(json_encode(array('error' =>2, 'reason' => $_GET['apikey']." is not connected now")));
	    $conn->close();
	    return;

	    
	default:
	    xLog($conn, "E", "HTTP", "Received request to unsupported URL: ".$requestURI);
	    var_dump($_SERVER);
	    $conn->send("HTTP/1.1 404 Not found\r\n\r\n<html><body>Unsupported URL.</body></html>", true);
    }
    $conn->close();
    return;
};

$cServer->onClose = function($conn) {
    global $cList;

    //print "#> onClose(".$conn->id.")\n";

    if (isset($cList[$conn->id])) {
	unset($cList[$conn->id]);
    }
};

$cServer->onWorkerStart = function() {
    loadConfig();

    Channel\Client::connect('192.168.1.16', 2206);
    Channel\Client::on('uList', function($data) { 
	global $uList; 
	$uList = unserialize($data); 
    });
    Channel\Client::on('update.response', function($data) {
	global $cList;
	$resp = unserialize($data);
	xLog(null, "D", "HTTP", "Update response received: ".$data);

	if (isset($cList[$resp['connID']])) {
	    // Socket is still waiting. Write response
	    $cList[$resp['connID']]['connection']->send(json_encode($resp));
	    $cList[$resp['connID']]['connection']->close();
	    unset($resp['connID']);
	}

    });    
};


// Prepare $uList for sending via Channel
function sendUList($uList) {
    $out = array();
    foreach ($uList as $uID => $uValue) {
	$out [$uID] = $uValue['info'];
	$out [$uID]['id'] = $uID;
	if (isset($uValue['params']))
	    $out [$uID]['params'] = $uValue['params'];
    }
    return serialize($out);
};


// ==============================================================================================================================
//
// WebSocket server: manage devices
//
$uList = array();
$cWorker = new Worker("websocket://".$IOTServer['serverIP'].':'.$IOTServer['iotPort'], array('ssl' => $IOTServer['ssl']));
$cWorker->transport = 'ssl';
$cWorker->count = 1;

$cWorker->onConnect = function($conn) {
    global $uList;
    $uList [$conn->id]= array('connection' => $conn, 'info' => array('remoteAddress' => $conn->getRemoteAddress(), 'state' => 0));
    xLog($conn, "I", "WS", "Incoming connection from: ".$conn->getRemoteAddress()." [TOTAL: ".count($uList)."]");
    Channel\Client::publish('uList', sendUList($uList));
};

$cWorker->onClose = function($conn) {
    global $uList;
    unset($uList[$conn->id]);
    xLog($conn, "I", "WS", "Disconnect from: ".$conn->getRemoteAddress()." [TOTAL: ".count($uList)."]");
    Channel\Client::publish('uList', serialize(array_keys($uList)));
};

$cWorker->onMessage = function($conn, $data) {
    global $uList;
    xLog($conn, "D", "WS", "Incoming message: ".$data);
    
    if (($req = json_decode($data, true)) === null) {
	// Incorrect JSON request format
	xLog($conn, "E", "WS", "Incorrect JSON string");
	$conn->send(json_encode(array('error' => 1, 'reason' => 'Cannot parse JSON string')));
	return;
    }

    if (!isset($req['action'])) {
	// Check if this is REPLY
	if (isset($req['sequence'])) {
	    // Check for sequence QUEUE in requests
	    if (isset($uList[$conn->id]) && isset($uList[$conn->id]['info']) && isset($uList[$conn->id]['info']['cmd']) && isset($uList[$conn->id]['info']['cmd'][$req['sequence']])) {
		$seq      = $uList[$conn->id]['info']['cmd'][$req['sequence']][0];
		$setState = $uList[$conn->id]['info']['cmd'][$req['sequence']][1];

		// Generate REPLY
		xLog($conn, "D", "WS", "Unparking reply for request [".$seq."] for setting [".$setState."][error=".$req['error']."]");
		xLog($conn, "I", "WS", ($req['error']?"FAILED WITH ERROR [".$req['error']."]":"Confirmed")." change state request for [".$uList[$conn->id]['info']['deviceInfo']['apikey']."] => [".$setState."]");
		Channel\Client::publish('update.response', serialize(array('connID' => $seq, 'error' => $req['error'], 'state' => $setState, 'apikey' => $uList[$conn->id]['info']['deviceInfo']['apikey'])));
		unset($uList[$conn->id]['info']['cmd'][$req['sequence']]);

		// Update internal state info if request is complete
		if (!$req['error']) {
		    $uList[$conn->id]['params']['switch'] = $setState;
		    $uList[$conn->id]['info']['lastUpdateTime'] = time();

		    // Update STATE in WEB server
		    Channel\Client::publish('uList', sendUList($uList));
		}

	    } else {
		xLog($conn, "D", "WS", "Received unrequested sequence [".$req['sequence']."]");
	    }
	    return;
	}


	xLog($conn, "E", "WS", "Action field is lost in JSON request");
	$conn->send(json_encode(array('error' => 1, 'reason' => 'Action is not set')));
	return;
    }

    if (!$uList[$conn->id]['info']['state']) {
	// NO interaction yet, waiting for register request
	if ($req['action'] != 'register') {
	    xLog($conn, "E", "WS", "Received [".$req['action']."] while device is not registered yet");
	    $conn->send(json_encode(array('error' => 1, 'reason' => 'Waiting for [register] request')));
	    return;
	}
	// Process register request

	// Generate apiKey for current device
	// This key is used locally, so it's ok if it's the same for all devices
	$newApiKey = "df6725f6-0b86-4415-9951-9cf8900c7825";
	$uList[$conn->id]['info']['sessionApiKey'] = $newApiKey;

	// Write log
	xLog($conn, "I", "WS", "Registered new device [deviceID=".$req['deviceid']."][apikey=".$req['apikey']."][mode=".$req['model']."][romVersion=".$req['romVersion']."][version=".$req['version']."][new.apikey=".$newApiKey."]");

	// Check if there're devices with the same apikey and force deregister in case of conflict
	foreach ($uList as $uK => $uV) {
	    if (isset($uV['info']) && isset($uV['info']['deviceInfo']) && isset($uV['info']['deviceInfo']['apikey']) && ($uV['info']['deviceInfo']['apikey'] == $req['apikey'])) {
		// FORCE DEREGISTER
		xLog($conn, "E", "WS", "Force deregister another device [".$uK."] with the same apikey");
		unset($uList[$uK]);
	    }
	}

	// - Save device info into local DB
	$uList[$conn->id]['info']['deviceInfo'] = $req;
	$uList[$conn->id]['info']['state'] = 1;
	unset($uList[$conn->id]['info']['deviceInfo']['action']);
	unset($uList[$conn->id]['info']['deviceInfo']['userAgent']);

	// Return register confirmation
	$conn->send(json_encode(array(
		"error"		=> 0,
		"deviceid"	=> $req['deviceid'],
		"apikey"	=> $newApiKey,
		"config"	=> array(
			"devConfig"	=> array(
				"storeAppsecret"	=> "",
				"bucketName"		=> "",
				"lengthOfVideo"		=> 0,
				"deleteAfterDays"	=> 0,
				"persistentPipeline"	=> "",
				"storeAppid"		=> "",
				"uploadLimit"		=> 0,
				"statusReportUrl"	=> "",
				"storetype"		=> 0,
				"callbackHost"		=> "",
				"persistentNotifyUrl"	=> "",
				"callbackUrl"		=> "",
				"persistentOps"		=> "",
				"captureNumber"		=> 0,
				"callbackBody"		=> "",
			),
		),
		"hb"		=> 1,
		"hbInterval"	=> 30,
	)));
	Channel\Client::publish('uList', sendUList($uList));
    } else {
	// Registration is done, processing requests
	switch ($req['action']) {
	    // Date request from device
	    case 'date':
		xLog($conn, "D", "WS", "Received [date] request");
		$resp = json_encode(array(
		    "error"	=> 0,
		    "deviceid"	=> $req['deviceid'],
		    "apikey"	=> $req['apikey'],
		    "date"	=> date("Y-m-d")."T".date("H:i:s").".000Z",
		));
		xLog($conn, "D", "WS", "[date] response: ".$resp);

		$conn->send($resp);
		break;

	    // Query data request from device
	    case 'query':
		// Not supported yet.
		xLog($conn, "D", "WS", "Received [query] request, not supported yet");
		$conn->send(json_encode(array(
		    "error"	=> 0,
		    "deviceid"	=> $req['deviceid'],
		    "apikey"	=> $req['apikey'],
		    "params"	=> 0,
		)));
		break;

	    // Update notification from device
	    case 'update':
		$pList = array();
		foreach ($req['params'] as $k => $v) { $pList []= "[".$k."=".$v."]"; }

		xLog($conn, "I", "WS", "Received [update] notification: ".join("", $pList));
		$uList[$conn->id]['params'] = $req['params'];
		$uList[$conn->id]['info']['lastUpdateTime'] = time();

		$conn->send(json_encode(array(
		    "error"	=> 0,
		    "deviceid"	=> $req['deviceid'],
		    "apikey"	=> $req['apikey'],
		)));
		Channel\Client::publish('uList', sendUList($uList));
		break;
	    default:
		// Unsupported request
		xLog($conn, "E", "WS", "Received unsupported [".$req['action']."] request");
		$conn->send(json_encode(array(
		    "error"	=> 0,
		    "deviceid"	=> $req['deviceid'],
		    "apikey"	=> $req['apikey'],
		    "params"	=> 0,
		)));
		break;
	}
    }

};

$cWorker->onWebSocketPing = function($conn) {
    global $uList;
    xLog($conn, "D", "WS", "WS Ping from device: ".$uList[$conn->id]['info']['deviceInfo']['apikey']);
    $uList [$conn->id]['info']['lastPingTime'] = time();
    Channel\Client::publish('uList', sendUList($uList));

    $conn->send(pack('H*', '8a00'), true);
};


$cWorker->onWorkerStart = function() {
    Channel\Client::connect('192.168.1.16', 2206);
    xLog(false, "I", "Main", "Starting daemon");

    Channel\Client::on('update', function($data) { 
	global $uList; 
	$req = unserialize($data);
	xLog(false, "D", "WS-Channel", "Received update request: ".$data);

	if (isset($req['apikey'])) {
	    // Search for requested deviceID
	    foreach ($uList as $uk => $uv) {
		if (isset($uv['info']['deviceInfo']['apikey']) && ($uv['info']['deviceInfo']['apikey'] == $req['apikey'])) {
		    // Found. Send UPDATE request from server
		    $seq = time();
		    // Mark pending request
		    $uList[$uk]['info']['cmd'][$seq] = array($req['connID'], $req['state']);

		    // Prepare cmd
		    $cmd = json_encode(array('userAgent' => 'app', 'action' => 'update', 'deviceid' => $uv['info']['deviceInfo']['deviceid'], 'apikey' => $uv['info']['sessionApiKey'], 'sequence' => $seq."", "ts" => 0, "params" => array("switch" => $req['state']), "from" => "app"));
		    xLog(false, "D", "WS-Channel", "Send cmd: ".$cmd);
		    $uv['connection']->send($cmd);
		    return;
		}
	    }
	}
    });

};


Worker::RunAll();
