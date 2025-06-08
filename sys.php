<?php
/* 
*   
*     3. SYS-Level 
*     
*       
*/
function sql_read($query) {
    global $db;
      if (!isset($db) || is_null($db)) $db = new PDO("mysql:host=".DB_HOST.";",DB_USER,DB_PASS);
      $stmt = $db->prepare($query);
        if ($stmt->execute() === false) return ["status" => "error", "query"=>$query];
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function sql_write($query) {
    global $db;
      if (!isset($db) || is_null($db)) $db = new PDO("mysql:host=".DB_HOST.";",DB_USER,DB_PASS);
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

/**
 * Executes a SELECT query and hydrates any foreign key columns with the
 * referenced row data.
 *
 * The query should target a single table (e.g., "SELECT * FROM db.table WHERE …").
 * For each foreign key in that table, an additional key named
 * `<column>_ref` is added to each returned row containing the referenced row
 * from the foreign table.
 *
 * @param string $query SQL SELECT statement for a single table.
 * @return array        Result set with hydrated foreign key data.
 */
function sql_read_and_hydrate($query) {
    $rows = sql_read($query);
    if (empty($rows)) {
        return $rows;
    }

    if (!preg_match('/FROM\s+([`\w\.]+)/i', $query, $m)) {
        return $rows; // Unable to determine table
    }

    $table = str_replace('`', '', $m[1]);
    if (strpos($table, '.') !== false) {
        list($dbName, $tblName) = explode('.', $table, 2);
    } else {
        $dbName = DB_NAME;
        $tblName = $table;
    }

    $fks = sql_read(
        "SELECT COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, " .
        "REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE " .
        "WHERE TABLE_SCHEMA = '$dbName' AND TABLE_NAME = '$tblName' " .
        "AND REFERENCED_TABLE_NAME IS NOT NULL"
    );

    if (empty($fks)) {
        return $rows; // no foreign keys
    }

    foreach ($rows as &$row) {
        foreach ($fks as $fk) {
            $col = $fk['COLUMN_NAME'];
            if (!isset($row[$col])) continue;

            $val = $row[$col];
            if ($val === null) {
                $row[$col . '_ref'] = null;
                continue;
            }

            $refTable = $fk['REFERENCED_TABLE_SCHEMA'] . '.' . $fk['REFERENCED_TABLE_NAME'];
            $refCol = $fk['REFERENCED_COLUMN_NAME'];
            $condition = is_numeric($val)
                ? "$refCol = $val"
                : "$refCol = '" . str_replace("'", "''", $val) . "'";
            $refRow = sql_read("SELECT * FROM $refTable WHERE $condition LIMIT 1");
            $row[$col . '_ref'] = $refRow[0] ?? null;
        }
    }
    unset($row);

    return $rows;
}

