<?php

namespace Wilkques\Ssh\Support;

/**
 * Shared credential/connection-option handling for Tunnel, Exec, and Sftp.
 * Not a trait: this package's PHP floor is 5.3, which has no traits.
 */
abstract class AbstractSshProcess
{
    /** @var string */
    protected $sshIp;

    /** @var string */
    protected $user;

    /** @var string|null */
    protected $idRsaPath;

    /** @var string|null */
    protected $password;

    /** @var int */
    protected $timeout = 30;

    /** @var ProcessRunner|null */
    protected $processRunner;

    /** @var array */
    protected $previousAskPassEnv = array();

    /**
     * @param string $ip
     *
     * @return static
     */
    public function setSshIp($ip)
    {
        $this->sshIp = $ip;

        return $this;
    }

    /**
     * @return string
     */
    public function getSshIp()
    {
        return $this->sshIp;
    }

    /**
     * @param string $user
     *
     * @return static
     */
    public function setUser($user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return string
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param string|null $path
     *
     * @return static
     */
    public function setIdRsaPath($path)
    {
        if ($path !== null && !file_exists($path)) {
            throw $this->credentialException(sprintf('Unable to find the private key file at path: %s', $path));
        }

        $this->idRsaPath = $path;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getIdRsaPath()
    {
        return $this->idRsaPath;
    }

    /**
     * @param string|null $password
     *
     * @return static
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param int $seconds
     *
     * @return static
     */
    public function setTimeout($seconds)
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * @return int
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * @param ProcessRunner $runner
     *
     * @return static
     */
    public function setProcessRunner(ProcessRunner $runner)
    {
        $this->processRunner = $runner;

        return $this;
    }

    /**
     * @return ProcessRunner
     */
    public function getProcessRunner()
    {
        if (!$this->processRunner) {
            $this->processRunner = new ProcessRunner();
        }

        return $this->processRunner;
    }

    /**
     * @return bool
     */
    protected function isWindows()
    {
        // PHP_OS_FAMILY only exists since PHP 7.2; this package supports
        // PHP 5.3, so detect Windows the old-fashioned, always-available way.
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Common ssh/sftp CLI options shared by every component.
     *
     * @return array
     */
    protected function sshOptions()
    {
        $options = array('-o', 'StrictHostKeyChecking=accept-new');

        if ($this->idRsaPath) {
            $options[] = '-i';
            $options[] = $this->idRsaPath;
        }

        return $options;
    }

    /**
     * @param string $binary
     * @param array $args
     *
     * @return string
     */
    protected function buildCommandLine($binary, array $args)
    {
        $parts = array($binary);

        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        return implode(' ', $parts);
    }

    /**
     * Run $binary $args to completion via the injected ProcessRunner, with
     * SSH_ASKPASS wired up first if a password has been set. When no
     * password is set, the child inherits this process's stdio so the real
     * binary can prompt on the terminal directly (key auth or interactive
     * password entry) — the same fallback Tunnel has always relied on.
     *
     * @param string $binary
     * @param array $args
     *
     * @return array array('exitCode' => int, 'stdout' => string, 'stderr' => string)
     */
    protected function runForeground($binary, array $args)
    {
        $cmd = $this->buildCommandLine($binary, $args);

        $script = $this->beginAskPass();

        try {
            $result = $this->getProcessRunner()->runForeground($cmd);
        } catch (\Exception $e) {
            $this->endAskPass($script);

            throw $e;
        }

        $this->endAskPass($script);

        return $result;
    }

    /**
     * Writes a temp SSH_ASKPASS helper script and points the relevant env
     * vars at it, exactly the technique documented in this project's own
     * REMOTE_ACCESS.md SOP for non-interactive password auth. No-op (and
     * returns null) when no password has been set — the caller falls back
     * to inherited stdio for key auth / interactive prompting.
     *
     * @return string|null path to the temp script, or null if no password is set
     */
    protected function beginAskPass()
    {
        if (!$this->password) {
            return null;
        }

        $script = tempnam(sys_get_temp_dir(), 'wilkques-ssh-askpass');

        if ($this->isWindows()) {
            $contents = "@echo off\r\necho " . $this->password . "\r\n";
        } else {
            $contents = "#!/bin/sh\necho " . escapeshellarg($this->password) . "\n";
        }

        file_put_contents($script, $contents);

        if (!$this->isWindows()) {
            chmod($script, 0700);
        }

        $this->previousAskPassEnv = array(
            'SSH_ASKPASS' => getenv('SSH_ASKPASS'),
            'SSH_ASKPASS_REQUIRE' => getenv('SSH_ASKPASS_REQUIRE'),
            'DISPLAY' => getenv('DISPLAY'),
        );

        putenv('SSH_ASKPASS=' . $script);
        putenv('SSH_ASKPASS_REQUIRE=force');
        putenv('DISPLAY=:0');

        return $script;
    }

    /**
     * @param string|null $script
     *
     * @return void
     */
    protected function endAskPass($script)
    {
        if ($script === null) {
            return;
        }

        foreach ($this->previousAskPassEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        @unlink($script);
    }

    /**
     * @param string $message
     *
     * @return \Wilkques\Ssh\Exceptions\SshException
     */
    abstract protected function credentialException($message);
}
