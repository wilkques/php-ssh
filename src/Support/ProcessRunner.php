<?php

namespace Wilkques\Ssh\Support;

/**
 * Thin wrapper over PHP's process-management functions so callers (and
 * their tests) can inject a mock instead of touching real OS processes.
 */
class ProcessRunner
{
    /**
     * Start a process and return immediately without waiting for it to exit
     * (used by Tunnel, which must keep the process running in the background).
     *
     * bypass_shell is required so the returned pid is the real binary's pid,
     * not a wrapping cmd.exe's — without it, proc_terminate()/a pid-based
     * kill on Windows only kills the cmd.exe wrapper and leaves the real
     * process (e.g. ssh.exe) running as an orphan.
     *
     * @param string $cmd
     * @param array $descriptorspec
     * @param array $pipes (by reference, populated by proc_open)
     *
     * @return resource|false
     */
    public function openBackground($cmd, array $descriptorspec, &$pipes)
    {
        return proc_open($cmd, $descriptorspec, $pipes, null, null, array('bypass_shell' => true));
    }

    /**
     * Run a process to completion, capturing stdout/stderr and the exit code
     * (used by Exec/Sftp, which need to wait for a one-shot command).
     *
     * @param string $cmd
     *
     * @return array array('exitCode' => int, 'stdout' => string, 'stderr' => string)
     */
    public function runForeground($cmd)
    {
        $descriptorspec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = proc_open($cmd, $descriptorspec, $pipes, null, null, array('bypass_shell' => true));

        if (!is_resource($process)) {
            return array('exitCode' => -1, 'stdout' => '', 'stderr' => 'Failed to start the process.');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return array('exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr);
    }

    /**
     * @param resource $process
     *
     * @return array
     */
    public function status($process)
    {
        return proc_get_status($process);
    }

    /**
     * @param resource $process
     *
     * @return bool
     */
    public function terminate($process)
    {
        return proc_terminate($process);
    }

    /**
     * @param resource $process
     *
     * @return int
     */
    public function close($process)
    {
        return proc_close($process);
    }

    /**
     * @param string $host
     * @param int $port
     * @param float $timeout
     *
     * @return bool
     */
    public function connect($host, $port, $timeout = 1)
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$socket) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Windows-only belt-and-suspenders process kill, used by Tunnel::stop()
     * in case proc_terminate() alone didn't reach the real process.
     *
     * @param int $pid
     *
     * @return void
     */
    public function taskkill($pid)
    {
        exec('taskkill /F /T /PID ' . escapeshellarg($pid) . ' > NUL 2>&1');
    }
}
