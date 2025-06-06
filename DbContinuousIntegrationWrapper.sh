#!/bin/bash
cd "$(dirname ${BASH_SOURCE[0]})"
while [[ 1 ]]
do
	killall DbContinuousIntegrationWrapper.sh
	/usr/bin/php DbContinuousIntegration.php
	make
	sleep 10
done
