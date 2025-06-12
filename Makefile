#!/bin/bash

all: composer.sync requirements.sync npm.sync

composer.sync: composer.json
	COMPOSER_ALLOW_SUPERUSER=1 composer update > $@ 2>&1

requirements.sync: requirements.txt
	pip install -r requirements.txt > $@ 2>&1

npm.sync: package.json
	npm install > $@ 2>&1

install:
@echo "Add this line to crontab -e"
@echo '@reboot /bin/bash /root/DbContinuousIntegration/DbContinuousIntegrationWrapper.sh 2> /dev/null > /dev/null &'
@echo
@echo 'CREATE TABLE SYS_PRD_BND.Tables (Name, onUpdate_phpCode, onUpdate_pyCode, onUpdate_jsCode, LastUpdated);' | sudo mysql
