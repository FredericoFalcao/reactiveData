#!/bin/bash
cd "$(dirname ${BASH_SOURCE[0]})"
while [[ 1 ]]
do
	killall DbContinuousIntegrationWrapper.sh
	/usr/bin/php composer.json.php > composer.json
	touch -t $(/usr/bin/mysql -se "SELECT DATE_FORMAT(LastUpdated, '%Y%m%d%H%i.%s') FROM SYS_PRD_BND.Composer ORDER BY LastUpdated DESC LIMIT 1") composer.json
	make
	/usr/bin/php DbContinuousIntegration.php
	sleep 10
done
