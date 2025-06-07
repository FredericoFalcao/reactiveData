<?php
/**
 * Generates Python code for sandbox execution based on provided trigger code and row data.
 *
 * @param string $functionName The name of the Python function to generate.
 * @param string $pyCode       The Python code to embed within the generated function.
 * @param array  $row          The row data to pass to the generated function.
 *
 * @return string The complete Python code ready for sandbox execution.
 */
function generatePythonTriggerCode($functionName, $pyCode, $row) {
    // Build constant definitions
    $constants = '';
    foreach (sql("SELECT Name, Type, Value FROM SYS_PRD_BND.Constants") as $const) {
        switch ($const['Type']) {
            case 'String':
                $val = "'" . str_replace("'", "\\'", $const['Value']) . "'";
                break;
            case 'Json':
                $val = 'json.loads(' . json_encode($const['Value']) . ')';
                break;
            default: // Int or Double
                $val = $const['Value'];
        }
        $constants .= "{$const['Name']} = {$val}\n";
    }

    // Prepare imports
    $imports = "import json\n" . getPythonImports();

    // Prepare row data for Python
    $rowJson = str_replace("'", "\\'", json_encode($row));

    // Indent user-provided Python code
    $indentedCode = implode("\n", array_map(fn($line) => '    ' . $line, explode("\n", $pyCode)));

    // Assemble complete Python script
    $pythonCode = <<<PY
$imports
$constants
def $functionName(data, error):
$indentedCode

data = json.loads('$rowJson')
error = None
$functionName(data, error)
print(json.dumps(data))
PY;

    return $pythonCode;
}
