<?php

namespace Wilkques\Ssh\Support;

use Wilkques\Ssh\Exceptions\SshException;

/**
 * SftpTransport over a live `proc_open` channel: stdin and stdout as pipes,
 * stderr redirected to a temp file rather than a third pipe.
 *
 * That last part is deliberate, not an oversight. SftpChannel needs to hold
 * stdin and stdout open at once and read/write both without deadlocking —
 * neither of which PHP can do reliably on Windows (no non-blocking reads, no
 * stream_select() on pipes). A third pipe for stderr would need exactly that
 * kind of polling to drain without risking a full-pipe stall, so stderr goes
 * to a file instead and is only ever read back after something has already
 * gone wrong (see errorOutput()) — it never participates in the read/write
 * loop that has to stay deadlock-safe.
 */
class ProcessTransport implements SftpTransport
{
    /** @var ProcessRunner */
    protected $processRunner;

    /** @var resource */
    protected $process;

    /** @var resource */
    protected $stdin;

    /** @var resource */
    protected $stdout;

    /** @var string */
    protected $stderrFile;

    /** @var bool */
    protected $isWindows;

    /** @var bool */
    protected $eof = false;

    /** @var bool */
    protected $closed = false;

    /**
     * @param ProcessRunner $processRunner
     * @param resource $process the proc_open() resource
     * @param array $pipes array(0 => stdin pipe, 1 => stdout pipe), as populated by ProcessRunner::openChannel()
     * @param string $stderrFile path stderr was redirected to
     * @param bool $isWindows passed in rather than detected here, so platform
     *                        detection stays in one place (AbstractSshProcess::isWindows())
     */
    public function __construct(ProcessRunner $processRunner, $process, array $pipes, $stderrFile, $isWindows = false)
    {
        $this->processRunner = $processRunner;
        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderrFile = $stderrFile;
        $this->isWindows = $isWindows;
    }

    /**
     * {@inheritdoc}
     */
    public function write($bytes)
    {
        $length = strlen($bytes);
        $written = 0;

        while ($written < $length) {
            $chunk = @fwrite($this->stdin, substr($bytes, $written));

            if ($chunk === false || ($chunk === 0 && feof($this->stdin))) {
                $this->eof = true;

                throw new SshException('Failed to write to the SFTP channel: the remote process is no longer accepting input.');
            }

            $written += $chunk;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($length)
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = @fread($this->stdout, $length - strlen($data));

            if ($chunk === false || ($chunk === '' && feof($this->stdout))) {
                $this->eof = true;

                throw new SshException(sprintf(
                    'Unexpected end of the SFTP channel: expected %d more byte(s) but the remote process closed the connection.',
                    $length - strlen($data)
                ));
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function isEof()
    {
        return $this->eof || (is_resource($this->stdout) && feof($this->stdout));
    }

    /**
     * {@inheritdoc}
     */
    public function errorOutput()
    {
        return is_file($this->stderrFile) ? (string) @file_get_contents($this->stderrFile) : '';
    }

    /**
     * {@inheritdoc}
     *
     * Mirrors Tunnel::stop()'s teardown order exactly: read the pid out of
     * status() before terminating (terminate()/close() invalidate the
     * resource), terminate() then close(), Windows-only taskkill() as a
     * belt-and-suspenders in case proc_terminate() alone didn't reach the
     * real ssh.exe.
     */
    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (is_resource($this->stdin)) {
            @fclose($this->stdin);
        }

        if (is_resource($this->stdout)) {
            @fclose($this->stdout);
        }

        if (is_resource($this->process)) {
            $status = $this->processRunner->status($this->process);
            $pid = isset($status['pid']) ? $status['pid'] : null;

            $this->processRunner->terminate($this->process);
            $this->processRunner->close($this->process);

            if ($pid && $this->isWindows) {
                $this->processRunner->taskkill($pid);
            }
        }

        if ($this->stderrFile && is_file($this->stderrFile)) {
            @unlink($this->stderrFile);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
