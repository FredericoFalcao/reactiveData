<?php
/* 
*   
*     2. PLT-Level 
*     
*       
*/
function processAllTheActiveTables() {
  echo "Scanning all the tables that need updating...\n";
  foreach(sql("SELECT Name, onUpdate_phpCode, onUpdate_pyCode, LastUpdated FROM SYS_PRD_BND.Tables") as $activeTable) {
    extract($activeTable);
    echo "Found Table : ".greenText($Name)."\n";
    echo "Scanning all the rows in table $Name that need to be ran through trigger code:\n";
    // @todo: check if the table has a column called : LastUpdated. If try to auto-create it. (ALTER TABLE ... LastUpdated DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
    foreach(sql("SELECT * FROM $Name WHERE LastUpdated > '$LastUpdated'") as $unprocessedRow) {
      echo "Found row\n".json_encode($unprocessedRow)."\n";
      $functionName = "handleNew".str_replace(".","__",$activeTable["Name"])."Row";

      echo "Checking if function ".greenText($functionName)." exists at code-level (sys).\n";
      if (function_exists($functionName)) {
        echo "Calling function ".greenText("$functionName(...)")."\n";
        if ($functionName($unprocessedRow, $error) === false) {
          sql("UPDATE SYS_PRD_BND.Tables SET LastError = '".str_replace("'",'"',$error)."', LastUpdated = NOW() WHERE Name = '{$activeTable["Name"]}'");
          sendTelegramMessage("*ERROR* on trigger function for table {$activeTable["Name"]} : ".$error,"DatabaseGroup");
        } else {
          sql("UPDATE SYS_PRD_BND.Tables SET LastError = '', LastUpdated = NOW() WHERE Name = '{$activeTable["Name"]}'");
        }

      }
      /*
       * PHP CODE 
       */
      echo "Checking if function ".greenText($functionName)." exists in PHP at database-level (app).\n";
      if (!is_null($onUpdate_phpCode) && !empty($onUpdate_phpCode)) {
        echo "Creating function ".greenText("$functionName(...)")." context\n";
        // 1. Add the PHP START TAG
        $code = "<"."?"."php \n";
        // 2.  Add the CONSTANTS
        foreach(sql("SELECT Name, Type, Value FROM SYS_PRD_BND.Constants") as $const)
                $code .= "define(\"{$const["Name"]}\",".($const["Type"]!="String"?$const["Value"]:'"'.$const["Value"].'"').");\n";
        // 3. Add the SUPPORT FUNCTIONS
        foreach(sql("SELECT Name, InputArgs_json, PhpCode FROM SYS_PRD_BND.SupportFunctions WHERE PhpCode IS NOT NULL") as $f)
                $code .= "function {$f["Name"]} (".implode(", ",array_map(fn($s)=>"\$$s",array_keys(json_decode($f["InputArgs_json"],1)))).") {\n".$f["PhpCode"]."\n}\n";

        $code .= "require_once '/root/DbContinuousIntegration/sys.php'; \n";
        $code .= "function $functionName (&\$data, &\$error) {\n";
        $code .= $onUpdate_phpCode;
        $code .= "\n}\n";
        $code .= "\$data = ".var_export($unprocessedRow,1).";\n";
        $code .= "\$initial_data = json_encode(".var_export($unprocessedRow,1).");\n";
        $code .= "$functionName(\$data,\$error);";
        $code .= "\necho json_encode(\$data);\n";
        file_put_contents(__DIR__."/test_code.php", $code);
        echo "Running function ".greenText("$functionName(...)")." in sandbox environment\n";
        if (runProcess("/usr/bin/php",$code,$stdout,$error) != 0) {
          sql("UPDATE SYS_PRD_BND.Tables SET LastError = '".str_replace("'",'"',$error)."', LastUpdated = NOW() WHERE Name = '{$activeTable["Name"]}'");
          sendTelegramMessage("*ERROR* on trigger function for table {$activeTable["Name"]} : ".$error,"DatabaseGroup");
        } else {
          // Update database row if needed
          $newRowValue = json_decode($stdout,1);
          echo "\n".redText("DEBUG new-row-value-json: ").json_encode($newRowValue)."\n";
          echo "\n".redText("DEBUG unprocessedRow-json: ").json_encode($unprocessedRow)."\n";
          if (json_encode($newRowValue) != json_encode($unprocessedRow)) {
                $pkColsName = getTblPrimaryKeyColName($activeTable["Name"]);
                $pkColsValues = array_map(fn($cName)=>$unprocessedRow[$cName],$pkColsName);
                $sql_instruction  = "";
                $sql_instruction .= "UPDATE ";
                $sql_instruction .= $activeTable["Name"];
                $sql_instruction .= " SET ".implode(",",array_map(fn($k,$v)=>"$k=".(is_numeric($v)?$v:"'".str_replace("'","''",$v)."'"),array_keys($newRowValue),array_values($newRowValue)));
                $sql_instruction .= " WHERE ".implode(" AND ",array_map(fn($k,$v)=>"$k = ".(is_numeric($newRowValue[$k])?$newRowValue[$k]:'"'.$newRowValue[$k].'"'),$pkColsName,$pkColsValues));
                echo "\n".redText("DEBUG sql-instruction: ").$sql_instruction."\n";
                sql($sql_instruction);
          }
          // Update the status of the operation
          sql("UPDATE SYS_PRD_BND.Tables SET LastError = '', LastUpdated = NOW() WHERE Name = '{$activeTable["Name"]}'");
        }

      }
      /*
       * Python CODE 
       */
      echo "Checking if function ".greenText($functionName)." exists in Python at database-level (app).\n";
      if (!is_null($onUpdate_pyCode) && !empty($onUpdate_pyCode)) {
        echo "Creating function ".greenText("$functionName(...)")." context\n";
        $code = "";
        // 1. Insert the import to all the external libraries
        foreach(sql("SELECT LibName, AliasName FROM SYS_PRD_BND.PyPi") as $module)
                $code .= "import ".$module["LibName"] .(is_null($module["AliasName"])&&!empty($module["AliasName"])?" as ".$module["AliasName"]:"")."\n\n";

        // 2. Define the handler function
        $code .= "def $functionName (data, error) :\n";
        $code .= "  ".implode("\n  ",explode("\n",$onUpdate_pyCode))."\n";

        // 3. Pass the data
        $code .= "data = ".json_encode($unprocessedRow)."\n";
        $code .= "error = { \"status\": \"ok\", \"message\": \"\"}\n";

        // 4. Call the handler function
        $code .= "$functionName(data, error)\n";

        // 5. Data changing code
        $code .= "\nprint(json.dumps(data))\n";

        file_put_contents(__DIR__."/test_code.py",$code);
        echo "Running function ".greenText("$functionName(...)")." in sandbox python environment\n";
        if (runProcess("/usr/bin/python3",$code,$stdout,$error) != 0) {
          sql("UPDATE SYS_PRD_BND.Tables SET LastError = '".str_replace("'",'"',$error)."', LastUpdated = NOW() WHERE Name = '{$activeTable["Name"]}'");
          sendTelegramMessage("*ERROR* on trigger function for table {$activeTable["Name"]} : ".$error,"DatabaseGroup");
          break;
        } else {
          // Update database row if needed
          $newRowValue = json_decode($stdout,1);
          echo "\n".redText("DEBUG new-row-value-json: ").json_encode($newRowValue)."\n";
          echo "\n".redText("DEBUG unprocessedRow-json: ").json_encode($unprocessedRow)."\n";
          if (json_encode($newRowValue) != json_encode($unprocessedRow)) {
                $pkColsName = getTblPrimaryKeyColName($activeTable["Name"]);
                $pkColsValues = array_map(fn($cName)=>$unprocessedRow[$cName],$pkColsName);
                $sql_instruction  = "";
                $sql_instruction .= "UPDATE ";
                $sql_instruction .= $activeTable["Name"];
                $sql_instruction .= " SET ".implode(",",array_map(fn($k,$v)=>"$k=".(is_numeric($v)?$v:"\"$v\""),array_keys($newRowValue),array_values($newRowValue)));
                $sql_instruction .= " WHERE ".implode(" AND ",array_map(fn($k,$v)=>"$k = ".(is_numeric($newRowValue[$k])?$newRowValue[$k]:'"'.$newRowValue[$k].'"'),$pkColsName,$pkColsValues));
                echo "\n".redText("DEBUG sql-instruction: ").$sql_instruction."\n";
                sql($sql_instruction);
          }
          // Update the status of the operation
          sql("UPDATE SYS_PRD_BND.Tables SET LastError = '', LastUpdated = NOW() WHERE Name = '{$activeTable["Name"]}'");
        }

      }
    }
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
