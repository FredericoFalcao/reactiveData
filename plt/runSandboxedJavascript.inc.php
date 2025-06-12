<?php
/**
 * Runs dynamically generated JavaScript code in a sandboxed environment using Node.js.
 *
 * @param string $code   The JavaScript code to execute.
 * @param string &$stdout Captured standard output from the execution.
 * @param string &$stderr Captured standard error from the execution.
 *
 * @return int Exit code of the executed script (0 means success).
 */
function runSandboxedJavascript($code, &$stdout = null, &$stderr = null) {
    $tempFile = tempnam(sys_get_temp_dir(), 'sandboxed_') . '.js';

    file_put_contents($tempFile, $code);

    $command = "/usr/bin/node " . escapeshellarg($tempFile);

    $descriptorspec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorspec, $pipes);

    if (!is_resource($process)) {
        unlink($tempFile);
        throw new Exception('Failed to execute sandboxed JavaScript code.');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    unlink($tempFile);

    return $exitCode;
}
