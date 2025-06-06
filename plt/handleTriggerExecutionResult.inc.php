<?php
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
