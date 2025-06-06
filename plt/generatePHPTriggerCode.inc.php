<?php
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
