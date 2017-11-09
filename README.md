# Sonoff-Server
PHP based implementation of Sonoff cloud server, running in your own home network.
You can control your Sonoff switches without need of changing basic firmware.

# Supported devices
In theory all Sonoff devices are supported.
But for now, only "Sonoff Basic" will work fine.
Please send me LOG file if you want to add support of any other Sonoff device.

# Requirements
1) Linux based server (Raspberry PI or any generic modern Linux)
2) PHP 7.x (but PHP 5.x should also be ok) with composer dependency manager (https://getcomposer.org/)

# Installation
0) Create directory for Sonoff Server
```
pi$ mkdir ~/phpSonoff
```
1) Install required libs via PHP composer
```pi$ mkdir ~/phpSonoff
pi$ cd ~/phpSonoff
pi$ composer require workerman/workerman workerman/channel
```
2) Download Sonoff Server software
```
pi$ git clone https://github.com/vponomarev/Sonoff-Server.git
pi$ mv Sonoff-Server/* .
pi$ rm -rf Sonoff-Server/
```
3) Create your own SSL certificates (you can also use self signed from this package)

4) Edit configuration section in sonoffServer.php file

# Running
Run server using:
```
pi$ php ./sonoffServer.php start -d
```
WEB GUI can be accessed via:
```
https://<serverIP>:<configPort>/
```
WEB GUI example:
![](https://github.com/vponomarev/Sonoff-Server/blob/master/doc/Sonoff-Server-GUI.PNG)
![](https://github.com/vponomarev/Sonoff-Server/blob/master/doc/Sonoff-Server-GUI2.PNG)

# API
1) List of connected devices:
```
https://<serverIP>:<configPort>/api/get
```
Returns JSON array with connected devices, example:
```
[{"apikey":"xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx","deviceid":"10000xxxxx","model":"ITA-GZ1-GL","lastUpdate":2572,"lastSeen":15,"rssi":-67,"state":"off"}]
```

2) Update state request:
```
https://<serverIP>:<configPort>/api/set?apikey=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx&state=off
```
Where:
apikey - Device API Key
state - new state ("on" or "off")

Return JSON array with result:
```
{"connID":26,"error":0,"state":"off"}
```
Where:
error - error code, 0 is "ok"
state - new state

# Configuring Sonoff
First, you need to configure your device via eWeLink software (there is also an alternative way, i'll explain it later).

On startup each Sonoff device send HTTPS POST request to configuration server (eu-disp.coolkit.cc).
You can reroute this requests into configuration page of Sonoff-Server using your home router.


