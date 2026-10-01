<?php

namespace Wilkques\Ssh\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Wilkques\Ssh\Support\ProcessRunner;
use Wilkques\Ssh\Tests\TestCase;

/**
 * Proves proc_open(['bypass_shell' => true]) + proc_terminate()/proc_close()
 * + (on Windows) the taskkill fallback genuinely start and kill a real OS
 * process, on real Linux and real Windows runners — without needing any SSH
 * keys, a real sshd, or network access. Substitutes a harmless long-running
 * PHP process for the real `ssh` binary, since what's actually under test
 * here is process lifecycle management, not the SSH protocol (that part is
 * entirely delegated to the system ssh binary and isn't this package's to
 * re-verify).
 *
 * @group integration
 *
 * (also tagged with the #[Group] attribute below — see
 * ExecIntegrationTest's docblock for why both forms are needed)
 */
#[Group('integration')]
class TunnelIntegrationTest extends TestCase
{
    protected function additionalSetUp()
    {
        if (getenv('SSH_TUNNEL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set SSH_TUNNEL_INTEGRATION=1 to run this test.');
        }
    }

    public function testProcessRunnerStartsAndKillsARealProcess()
    {
        $runner = new ProcessRunner();

        $descriptorspec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        // PHP_BINARY only exists since PHP 5.4; this package (and its test
        // suite) supports PHP 5.3, where referencing it would silently
        // evaluate to the literal string "PHP_BINARY" instead of a real path.
        $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';

        $cmd = escapeshellarg($phpBinary) . ' -r ' . escapeshellarg('usleep(30000000);');

        $pipes = null;
        $process = $runner->openBackground($cmd, $descriptorspec, $pipes);

        $this->assertTrue(is_resource($process));

        $status = $runner->status($process);

        $this->assertTrue($status['running']);

        $pid = $status['pid'];

        $runner->terminate($process);
        $runner->close($process);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $runner->taskkill($pid);
        }

        usleep(300000);

        $this->assertFalse($this->pidIsRunning($pid));
    }

    /**
     * @param int $pid
     *
     * @return bool
     */
    protected function pidIsRunning($pid)
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = shell_exec('tasklist /FI "PID eq ' . (int) $pid . '" 2>NUL');

            return $output !== null && strpos($output, (string) $pid) !== false;
        }

        return posix_kill($pid, 0);
    }
}
