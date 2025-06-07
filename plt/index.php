<?php
/* 
*   
*     2. PLT-Level 
*     
 * Overview:
* This PHP script automates database updates by processing rows marked for action in various tables.
* It dynamically executes predefined functions based on table-specific instructions stored in the database.
* Functions are executed in either PHP or Python environments depending on configuration.
* 
* How It Works:
* 1. The script queries the `SYS_PRD_BND.Tables` table to determine which tables require processing.
* 2. For each identified table, it selects rows updated since the last run (based on the `LastUpdated` column).
* 3. Each row is individually processed through:
*     - PHP-level triggers defined in system-level or database-level code.
*     - Python-level triggers similarly defined.
* 4. If a trigger modifies the row, the script updates the database with new values.
* 5. Errors encountered during processing are logged and sent as notifications via Telegram.
* 
* Tables and Their Purpose:
* - SYS_PRD_BND.Tables:
*   Defines active tables and their respective PHP or Python trigger codes.
*   Columns: Name, onUpdate_phpCode, onUpdate_pyCode, LastUpdated, LastError
*
* - SYS_PRD_BND.Constants:
*   Holds constants that are injected into the PHP environment during execution.
*   Columns: Name, Type, Value
* 
* - SYS_PRD_BND.SupportFunctions:
*   Stores reusable PHP functions available during trigger execution.
*   Columns: Name, InputArgs_json, PhpCode
*
* - SYS_PRD_BND.PyPi:
*   Lists external Python libraries imported into Python trigger execution.
*   Columns: LibName, AliasName
* 
* Dynamic Tables:
* - Application-specific tables, each must include at least:
*   - LastUpdated (timestamp for tracking)
*   - Primary key columns for row identification (defined externally)
*
* Important Functions:
* - sendTelegramMessage($message, $dstUsers): Sends notifications to a specified Telegram group.
* - runProcess($command, $code, &$stdout, &$error): Executes external PHP/Python code securely.
*
* Usage:
* Ensure proper configuration of constants, Telegram bot token, and required Python modules.
* Regularly schedule this script to automate database maintenance and data integrity tasks.
*
*
*
*
*     Expects tables: 
*      SYS_PRD_BND.Tables (Name, onUpdate_phpCode, onUpdate_pyCode, LastUpdated, LastError)
*      SYS_PRD_BND.Constants (Name, Type, Value)
*      SYS_PRD_BND.SupportFunctions (Name, InputArgs_json, PhpCode)
*      SYS_PRD_BND.PyPi (LibName, AliasName)
*      DynamicTables (LastUpdated, [PrimaryKeyColumns], ...)  
*/

// Auto-include all files in this folder with extension .inc.php
foreach(scandir(__DIR__) as $filename) if (substr($filename,-8) == ".inc.php") require_once __DIR__."/$filename";

/**
 * Processes all active tables, updating rows based on trigger conditions.
 * This function scans each active table, executes dynamic trigger code,
 * and handles errors accordingly.
 */
function processAllTheActiveTables() {
    echo "Scanning all the tables that need updating...\n";
    $activeTables = sql("SELECT Name, onUpdate_phpCode, onUpdate_pyCode, LastUpdated FROM SYS_PRD_BND.Tables");

    foreach ($activeTables as $activeTable) {
        processActiveTable($activeTable);
    }
}

/**
 * Processes a single active table.
 */
function processActiveTable($activeTable) {
    extract($activeTable);
    echo "Found Table: " . greenText($Name) . "\n";
    echo "Scanning rows in table $Name that require trigger execution:\n";

    ensureLastUpdatedColumnExists($Name);

    $rowsToProcess = sql("SELECT * FROM $Name WHERE LastUpdated > '$LastUpdated'");

    foreach ($rowsToProcess as $unprocessedRow) {
        processTableRow($activeTable, $unprocessedRow);
    }
}

/**
 * Ensures the 'LastUpdated' column exists, adding it if missing.
 */
function ensureLastUpdatedColumnExists($_tableName) {
    echo "Ensuring LastUpdated column is created if not exists..\n";
    list($dbName,$tableName) = (strpos($_tableName,".") ? explode(".",$_tableName) : ["",$_tableName]);
    sql((empty($dbName)?"":"USE $dbName; ")."ALTER TABLE `$tableName` ADD COLUMN IF NOT EXISTS LastUpdated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

/**
 * Processes an individual row for triggers.
 */
function processTableRow($activeTable, $unprocessedRow) {
    $functionName = "handleNew" . str_replace(".", "__", $activeTable["Name"]) . "Row";

    if (function_exists($functionName)) {
        runSystemLevelTrigger($functionName, $activeTable, $unprocessedRow);
    }

    if (!empty($activeTable['onUpdate_phpCode'])) {
        runPHPCodeTrigger($functionName, $activeTable, $unprocessedRow);
    }

    if (!empty($activeTable['onUpdate_pyCode'])) {
        runPythonCodeTrigger($functionName, $activeTable, $unprocessedRow);
    }
}

/**
 * Executes system-level PHP trigger function.
 */
function runSystemLevelTrigger($functionName, $activeTable, &$row) {
    echo "Calling system-level function " . greenText($functionName) . "\n";
    $error = '';

    if ($functionName($row, $error) === false) {
        updateTriggerError($activeTable['Name'], $error);
    } else {
        clearTriggerError($activeTable['Name']);
    }
}

/**
 * Executes database-level dynamic PHP trigger code.
 */
function runPHPCodeTrigger($functionName, $activeTable, $row) {
    echo "Running PHP Code trigger...\n";
    $phpCode = generatePHPTriggerCode($functionName, $activeTable['onUpdate_phpCode'], $row);
    $result = runSandboxedPHP($phpCode,$stdout,$stderr);

    handleTriggerExecutionResult($result, $stdout,$stderr,$row, $activeTable);
}

/**
 * Executes database-level dynamic Python trigger code.
 */
function runPythonCodeTrigger($functionName, $activeTable, $row) {
    echo "Running Python Code trigger...\n";
    $pyCode = generatePythonTriggerCode($functionName, $activeTable['onUpdate_pyCode'], $row);
    $result = runSandboxedPython($pyCode,$stdout,$stderr);

    handleTriggerExecutionResult($result, $stdout, $stderr, $row, $activeTable);
}

/**
 * Helper functions implementations.
 */

function updateDatabaseRow($tableName, $originalRow, $newRowValue) {
    $pkColsName = getTblPrimaryKeyColName($tableName);
    $pkColsValues = array_map(fn($cName) => $originalRow[$cName], $pkColsName);

    $setStatements = [];
    foreach ($newRowValue as $k => $v) {
        $value = is_numeric($v) ? $v : "'" . str_replace("'", "''", $v) . "'";
        $setStatements[] = "$k = $value";
    }

    $whereStatements = [];
    foreach ($pkColsName as $k) {
        $val = is_numeric($originalRow[$k]) ? $originalRow[$k] : "'" . $originalRow[$k] . "'";
        $whereStatements[] = "$k = $val";
    }

    $sql_instruction = "UPDATE $tableName SET " . implode(", ", $setStatements) . " WHERE " . implode(" AND ", $whereStatements);
    sql($sql_instruction);
}

function updateTriggerError($tableName, $error) {
    sql("UPDATE SYS_PRD_BND.Tables SET LastError = '" . str_replace("'", '"', $error) . "', LastUpdated = NOW() WHERE Name = '$tableName'");
    sendTelegramMessage("*ERROR* on trigger function for table $tableName : " . $error, "DatabaseGroup");
}

function clearTriggerError($tableName) {
    sql("UPDATE SYS_PRD_BND.Tables SET LastError = '', LastUpdated = NOW() WHERE Name = '$tableName'");
}

function getConstantsDefinition() {
    $constants = "";
    foreach (sql("SELECT Name, Type, Value FROM SYS_PRD_BND.Constants") as $const) {
        $value = $const["Type"] != "String" ? $const["Value"] : '"' . $const["Value"] . '"';
        $constants .= "define(\"{$const['Name']}\", $value);\n";
    }
    return $constants;
}

function getSupportFunctionsDefinition() {
    $supportFunctions = "";
    foreach (sql("SELECT Name, InputArgs_json, PhpCode FROM SYS_PRD_BND.SupportFunctions WHERE PhpCode IS NOT NULL") as $f) {
        $args = implode(", ", array_map(fn($s) => "\$$s", array_keys(json_decode($f["InputArgs_json"], true))));
        $supportFunctions .= "function {$f['Name']}($args) {\n{$f['PhpCode']}\n}\n";
    }
    return $supportFunctions;
}

function getPythonImports() {
    $imports = "";
    foreach (sql("SELECT LibName, AliasName FROM SYS_PRD_BND.PyPi") as $module) {
        $alias = !empty($module["AliasName"]) ? " as {$module['AliasName']}" : "";
        $imports .= "import {$module['LibName']}{$alias}\n";
    }
    return $imports;
}
function greenText($string) { $green = "\033[0;32m"; $reset = "\033[0m"; return $green . $string . $reset ; }
function blueText($string)  { $green = "\033[0;34m"; $reset = "\033[0m"; return $green . $string . $reset ; }
function redText($string)   { $green = "\033[0;31m"; $reset = "\033[0m"; return $green . $string . $reset ; }
function sendTelegramMessage($message, $dstUsers) {
  global $BOT_TOKEN, $CHAT_IDS;
  
  if (is_string($dstUsers)) $dstUsers = [$dstUsers];

  foreach($dstUsers as $dstUser) {
    if (!isset($CHAT_IDS[$dstUser])) 
	continue;
    else
    	$CHAT_ID = $CHAT_IDS[$dstUser];


    $JSON_RAW_DATA = json_encode([
        'chat_id' => $CHAT_ID,
        'text' => $message,
        'parse_mode' => 'markdown'
    ]);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, "https://api.telegram.org/bot$BOT_TOKEN/sendMessage");
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $JSON_RAW_DATA);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    //print_r($response);
    curl_close($curl);
  }
}
