<?php
/* 
*   
*     3. SYS-Level 
*     
*       
*/
function sql_read($query) {
    global $db;
      if (!isset($db) || is_null($db)) $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME,DB_USER,DB_PASS);
      $stmt = $db->prepare($query);
        if ($stmt->execute() === false) return ["status" => "error", "query"=>$query];
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function sql_write($query) {
    global $db;
      if (!isset($db) || is_null($db)) $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME,DB_USER,DB_PASS);
      $stmt = $db->prepare($query);
        if ($stmt->execute() === false) die ("sql error: $query");
        return $stmt->rowCount();
}
function sql($query) {
    if (strpos($query, "SELECT ") === 0) return sql_read($query);
      else return sql_write($query);
}


function runProcess($cmd, $stdin, &$stdout, &$stderr) {
    $descriptorspec = array(
        0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
        1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
        2 => array("pipe", "w")   // stderr is a pipe that the child will write to
    );

    $process = proc_open($cmd, $descriptorspec, $pipes);

    if (is_resource($process)) {
        // Write to stdin and close it
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        // Read the output of the command
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // Read the error output of the command
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        // It's important to close all pipes before calling proc_close in order to avoid a deadlock
        $return_value = proc_close($process);

        return $return_value;
    } else {
        // Return an error code if the process could not be started
        return -1;
    }
}
function getTblPrimaryKeyColName($tblName) {
        $dbName = explode(".",$tblName)[0];
        $tblName = explode(".",$tblName)[1];

        $cols = [];
        foreach(sql("SELECT COLUMN_NAME PksColName FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '$dbName' AND TABLE_NAME = '$tblName' AND CONSTRAINT_NAME = 'PRIMARY';") as $row)
                $cols[] = $row["PksColName"];
        return $cols;
}

