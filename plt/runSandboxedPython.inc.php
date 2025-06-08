<?php
/**
 * Runs dynamically generated Python code in a sandboxed environment.
 *
 * @param string $code   The Python code to execute.
 * @param string &$stdout Captured standard output from the execution.
 * @param string &$stderr Captured standard error from the execution.
 *
 * @return int Exit code of the executed Python script (0 means success).
 */
function runSandboxedPython($code, &$stdout = null, &$stderr = null) {
    // Create a temporary file to hold the Python code
    $tempFile = tempnam(sys_get_temp_dir(), 'sandboxed_') . '.py';
    echo "Creating ".redText("python temp code file")." for sandboxed execution at: $tempFile \n";

    // Write the generated Python code to the temporary file
    file_put_contents($tempFile, $code);

    // Prepare the command for executing the Python code
    $command = "/usr/bin/python3 " . escapeshellarg($tempFile);

    // Descriptor spec to capture stdout and stderr
    $descriptorspec = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    // Execute the Python code using proc_open
    $process = proc_open($command, $descriptorspec, $pipes);

    if (!is_resource($process)) {
        unlink($tempFile);
        throw new Exception('Failed to execute sandboxed Python code.');
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
