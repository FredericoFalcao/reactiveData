<?php

/* 
 *
 * 1. APP-Level 
 *
 * */

/*
 *   Create a new table handler:
 *    (1) create a function called handleXXXXRow(data, error)
 *       (1.1) XXX is DbName__TableName
 *    (2) function should return "true" on success
 *    (3) function should fill in "error" on error and return false
 *
 */
 
function main() {

	processAllTheActiveTables();
	sleep(1);
}

