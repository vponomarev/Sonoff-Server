<?php

#
# ITEAD Sonoff SWITCH Cloud Server
#

global $IOTServer, $uList, $xLogFile;
// ============================ CONFIG START ======================
$IOTServer = array(
    'serverIP'		=> '192.168.1.16',
    'configPort'	=> 2443,
    'iotPort'		=> 2333,
    'ssl'		=> array(
	'local_cert'		=> './ssl/selfcert.in.crt',
	'local_pk'		=> './ssl/selfcert.in.pem',
    ),
);
$LogFile = "/home/pi/phpWS/sonoff.log";
$LogConsole = true;

ini_set('date.timezone', 'Europe/Moscow');

// ============================ CONFIG END   ======================


require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

// Logger
function xLog($conn, $level, $entity, $text) {
    global $LogFile, $LogConsole, $uList;

    $identity = "[?]";
    if (is_object($conn) && isset($conn->id)) {
        $identity = "[".$conn->id. ((isset($uList[$conn->id]) && isset($uList[$conn->id]['info']['deviceInfo']['apikey']))?", ".$uList[$conn->id]['info']['deviceInfo']['apikey']:", A=".$conn->getRemoteAddress())."]";
    }
    $LogInfo = strftime("%F %T")." ".$level." ".$entity." ".$identity." ".$text."\n";
    if ($LogConsole)
	print $LogInfo;

    if ($f = fopen($LogFile, "a")) {
	fwrite($f, $LogInfo);
	fclose($f);

	return true;
    }
    return false;
};


//date_default_timezone_set('Europe/Moscow');

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
    global $IOTServer, $uList;

    // Get URL
    $requestURI = $_SERVER['REQUEST_URI'];
    if (preg_match("#^(.+?)\?#", $requestURI, $m)) {
	$requestURI = $m[1];
    }

    
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
	case '/list':
	    $msg = '<html><body><h1>List of active users</h1>';
	    $msg .= var_export($uList, true);
	    $msg .= '['.count($uList).']<br/>';
	    foreach ($uList as $uk => $uv) {
		$msg .= $uv['id']." ".$uv['remoteAddress']."<br/>\n";
	    }
	    $conn->send($msg);
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

	    // Search for user
	    foreach ($uList as $uk => $uv) {
		if (isset($uv['deviceInfo']['apikey']) && ($uv['deviceInfo']['apikey'] == $_GET['apikey'])) {
		    // GOT IT! Send update request to WS worker
		    Channel\Client::publish('update', serialize(array('apikey' => $_GET['apikey'], 'state' => $_GET['state'], 'connID' => $conn->id)));
		    
		    // LOG
		    xLog($conn, "I", "HTTP", "Update request for [".$_GET['apikey']."] => [".$_GET['state']."]");

		    // Wait for answer via Channel or close connection with TIMEOUT in case if no resp via Channel
		    $conn->send(json_encode(array('error' => 0, 'reason' => 'Request is sent in background')));
		    $conn->close();
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

$cServer->onWorkerStart = function() {
    Channel\Client::connect('192.168.1.16', 2206);
    Channel\Client::on('uList', function($data) { 
	global $uList; 
	$uList = unserialize($data); 
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
    xLog($conn, "I", "WS", "Disconnect from: ".$conn->getRemoteAddress()." [TOTAL: ".$count($uList)."]");
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
	$newApiKey = "df6725f6-0b86-4415-9951-9cf8900c7825";
	$uList[$conn->id]['info']['sessionApiKey'] = $newApiKey;

	// Write log
	xLog($conn, "I", "WS", "Registered new device [deviceID=".$req['deviceid']."][apikey=".$req['apikey']."][mode=".$req['model']."][romVersion=".$req['romVersion']."][version=".$req['version']."][new.apikey=".$newApiKey."]");

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

		xLog($conn, "D", "WS", "Received [update] request: ".join("", $pList));
		$uList [$conn->id]['params'] = $req['params'];
		$uList [$conn->id]['info']['lastUpdateTime'] = time();

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
	xLog(false, "I", "WS-Channel", "Received update request: ".$data);

	if (isset($req['apikey'])) {
	    // Search for requested deviceID
	    foreach ($uList as $uk => $uv) {
		if (isset($uv['info']['deviceInfo']['apikey']) && ($uv['info']['deviceInfo']['apikey'] == $req['apikey'])) {
		    // Found. Send UPDATE request from server
		    $cmd = json_encode(array('userAgent' => 'app', 'action' => 'update', 'deviceid' => $uv['info']['deviceInfo']['deviceid'], 'apikey' => $uv['info']['sessionApiKey'], 'sequence' => time()."", "ts" => 0, "params" => array("switch" => $req['state']), "from" => "app"));
		    xLog(false, "D", "WS-Channel", "Send cmd: ".$cmd);
		    $uv['connection']->send($cmd);
		    return;    
		}
	    }
	}
	

    });    

};


Worker::RunAll();



/////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////
exit;
// Create a Websocket server
$worker = new Worker("websocket://0.0.0.0:2346");
$worker->count = 1;

$worker->onConnect = function($connection) {
    echo "# New connection\n";
 };

$worker->onMessage = function($connection, $data) {
    print("# Incoming message: ".$data."\n");
    // Send hello $data
    $connection->send('# Received your string: ' . $data);
};

$worker->onClose = function($connection) {
    echo "# Connection is closed\n";
};

$worker->onWorkerStart = function() {
    echo "# Worker start procedure..\n";
    $ws = new AsyncTcpConnection("ws://35.157.208.224:443/api/ws", array('ssl' => array('verify_peer' => false,'verify_peer_name' => false)));
    $ws->onConnect = function($connection) {
	echo "# Client WS: connection is set.\n";
	$connection->send('{"userAgent":"device","apikey":"d8fb64c8-bba6-4d2a-96e6-12dd2ef3c34a","deviceid":"100008db11","action":"register","version":2,"romVersion":"1.5.5","model":"ITA-GZ1-GL","ts":970}');
    };
    $ws->onMessage = function($connection,$data) {
	echo "# Client WS: received message: ".$data."\n";
    };

    $ws->onError = function($connection, $code, $msg) {
	echo "# Client WS: error: ".$msg."\n";
    };

    $ws->onClose = function($connection) {
	echo "# Client WS: closed connection.\n";
    };
    $ws->connect();
};



// Run worker
Worker::runAll();

