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
/**
 * Generates PHP code for sandbox execution based on provided dynamic PHP code and row data.
 *
 * @param string $functionName       The name of the PHP function to generate.
 * @param string $onUpdate_phpCode   The PHP code to embed within the generated function.
 * @param array  $row                The row data to pass to the generated function.
 *
 * @return string                    The complete PHP code ready for sandbox execution.
 */
function generatePHPTriggerCode($functionName, $onUpdate_phpCode, $row) {
    // Prepare the constants definition from the database
    $constants = getConstantsDefinition();

    // Prepare the support functions definitions from the database
    $supportFunctions = getSupportFunctionsDefinition();

    // Export the row data into PHP code format
    $rowExport = var_export($row, true);

    // Assemble the complete PHP script
    $phpCode = <<<PHP
<?php
// Define constants
$constants

// Include support functions
$supportFunctions

// Include system-level support file
require_once 'sys.php';

// Dynamically defined trigger function
function $functionName(&\$data, &\$error) {
$onUpdate_phpCode
}

// Data passed to the function
\$data = $rowExport;
\$initial_data = json_encode(\$data);

// Execute the dynamically generated function
$functionName(\$data, \$error);

// Output the modified data as JSON
echo json_encode(\$data);
PHP;

    return $phpCode;
}
/**
 * Runs dynamically generated PHP code in a sandboxed environment.
 *
 * @param string $code The PHP code to execute.
 * @param string &$stdout Captured standard output from the execution.
 * @param string &$stderr Captured error output from the execution.
 *
 * @return int Exit code of the executed PHP script (0 means success).
 */
function runSandboxedPHP($code, &$stdout = null, &$stderr = null) {
    // Create a temporary file to hold the PHP code
    $tempFile = tempnam(sys_get_temp_dir(), 'sandboxed_') . '.php';

    // Write the generated PHP code to the temporary file
    file_put_contents($tempFile, $code);

    // Prepare the command for executing the PHP code
    $command = "/usr/bin/php " . escapeshellarg($tempFile);

    // Descriptor spec to capture stdout and stderr
    $descriptorspec = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    // Execute the PHP code using proc_open
    $process = proc_open($command, $descriptorspec, $pipes);

    if (!is_resource($process)) {
        unlink($tempFile);
        throw new Exception('Failed to execute sandboxed PHP code.');
    }

    // Capture stdout and stderr
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    // Get the exit code
    $exitCode = proc_close($process);

    // Cleanup temporary file
    unlink($tempFile);

    return $exitCode;
}
/**
 * Handles the result of trigger execution, including error handling and updating database rows if necessary.
 *
 * @param int    $result       Exit code from the sandbox execution (0 for success, non-zero for failure).
 * @param string $stdout       Captured standard output from the execution (JSON encoded new row data).
 * @param string $stderr       Captured standard error from the execution (error message if any).
 * @param array  $originalRow  Original database row before trigger execution.
 * @param array  $activeTable  The active table details (including Name).
 */
function handleTriggerExecutionResult($result, $stdout, $stderr, $originalRow, $activeTable) {
    $tableName = $activeTable['Name'];

    if ($result !== 0) {
        // Update table error status and notify via Telegram if execution failed
        updateTriggerError($tableName, $stderr);
    } else {
        // Decode the output from JSON to array
        $newRowValue = json_decode($stdout, true);

        if ($newRowValue === null) {
            updateTriggerError($tableName, 'Invalid JSON output from sandboxed execution.');
            return;
        }

        // Check if the new data differs from the original data
        if ($newRowValue !== $originalRow) {
            // Update the database row accordingly
            updateDatabaseRow($tableName, $originalRow, $newRowValue);
        }

        // Clear any previous error status
        clearTriggerError($tableName);
    }
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
