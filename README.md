# Sonoff-Server
PHP based implementation of Sonoff cloud server, running in your own home network.
You can control your Sonoff switches without need of changing basic firmware.

# Requirements
1) Linux based server (Raspberry PI or any generic modern Linux)
2) PHP 7.x (but PHP 5.x should also be ok)

# Installation
1) Install required libs via PHP composer
`pi$ mkdir ~/phpSonoff
`pi$ cd ~/phpSonoff
`pi$ composer install workerman/workerman workeman/channel

2) Download Sonoff Server software
`pi$ git clone https://github.com/vponomarev/Sonoff-Server.git

3) Create your own SSL certificates (self signed is ok)

4) Edit configuration section in sonoffServer.php file

5) Run server using:
`pi$ php ./sonoffServer.php start

# Configuring Sonoff
First, you need to configure your device via eWeLink software (there is also an alternative way, i'll explain it later).

On startup each Sonoff device send HTTPS POST request to configuration server (eu-disp.coolkit.cc).
You can reroute this requests into configuration page of Sonoff-Server using your home router.


