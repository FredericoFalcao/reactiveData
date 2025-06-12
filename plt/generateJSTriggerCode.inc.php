<?php
/**
 * Generates JavaScript code for sandbox execution based on provided trigger code and row data.
 *
 * @param string $functionName The name of the JavaScript function to generate.
 * @param string $jsCode       The JavaScript code to embed within the generated function.
 * @param array  $row          The row data to pass to the generated function.
 *
 * @return string The complete JavaScript code ready for sandbox execution.
 */
function generateJSTriggerCode($functionName, $jsCode, $row) {
    $constants = getJSConstantsDefinition();
    $supportFunctions = getJavascriptSupportFunctionsDefinition();
    $requires = getNodeRequires();

    $rowJson = json_encode($row, JSON_UNESCAPED_SLASHES);
    $indentedCode = implode("\n", array_map(fn($l) => '    ' . $l, explode("\n", $jsCode)));

    $javascript = <<<JS
$requires
$constants
$supportFunctions
function $functionName(data, error) {
$indentedCode
}
let data = $rowJson;
let error = null;
$functionName(data, error);
console.log(JSON.stringify(data));
JS;
    return $javascript;
}
