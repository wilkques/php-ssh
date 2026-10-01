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

    /** @var int|null */
    protected $port;

    /** @var string */
    protected $strictHostKeyChecking = 'accept-new';

    /** @var string|null */
    protected $knownHostsFile;

    /** @var string|null */
    protected $proxyJump;

    /** @var bool */
    protected $compression = false;

    /** @var array */
    protected $extraOptions = array();

    /** @var bool */
    protected $multiplexing = false;

    /** @var string */
    protected $controlPersist = '10m';

    /** @var string|null */
    protected $controlPath;

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
     * 接到 `-o ConnectTimeout=<seconds>`（TCP 連線逾時秒數，不是指令整體逾時）
     *
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
     * @param int|null $port
     *
     * @return static
     */
    public function setPort($port)
    {
        $this->port = $port;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * `-o StrictHostKeyChecking=<value>`；預設 `accept-new`（第一次連線自動信任、之後若 host key 變動仍會擋下來）。
     * 想要嚴格驗證（只信任 `known_hosts` 裡已經有的）就傳 `'yes'`；完全不驗證（不建議，僅限測試環境）就傳 `'no'`。
     *
     * @param string $value
     *
     * @return static
     */
    public function setStrictHostKeyChecking($value)
    {
        $this->strictHostKeyChecking = $value;

        return $this;
    }

    /**
     * @return string
     */
    public function getStrictHostKeyChecking()
    {
        return $this->strictHostKeyChecking;
    }

    /**
     * `-o UserKnownHostsFile=<path>`，搭配 `setStrictHostKeyChecking('yes')` 使用，指定自己的 known_hosts 檔案
     *
     * @param string|null $path
     *
     * @return static
     */
    public function setKnownHostsFile($path)
    {
        $this->knownHostsFile = $path;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getKnownHostsFile()
    {
        return $this->knownHostsFile;
    }

    /**
     * `-J <user@host[:port]>`，透過跳板主機再連到真正的目標（多跳）
     *
     * @param string|null $spec
     *
     * @return static
     */
    public function setProxyJump($spec)
    {
        $this->proxyJump = $spec;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getProxyJump()
    {
        return $this->proxyJump;
    }

    /**
     * `-C`，開啟壓縮（高延遲、低頻寬連線比較有感）
     *
     * @param bool $enabled
     *
     * @return static
     */
    public function setCompression($enabled = true)
    {
        $this->compression = $enabled;

        return $this;
    }

    /**
     * @return bool
     */
    public function isCompressionEnabled()
    {
        return $this->compression;
    }

    /**
     * 附加任意額外的 `-o key=value`，本套件沒特別包裝的 ssh 選項都可以透過這個加
     *
     * @param string $key
     * @param string $value
     *
     * @return static
     */
    public function addOption($key, $value)
    {
        $this->extraOptions[$key] = $value;

        return $this;
    }

    /**
     * 開啟 ssh 連線多工（`ControlMaster`/`ControlPath`/`ControlPersist`）：同一個物件實例在
     * `$persist` 時間內重複呼叫 exec()/put()/get() 等，會重用同一條已建立的連線，省掉重新
     * 握手的開銷。僅限 Linux/macOS 穩定可用；Windows 上是否支援取決於該台機器安裝的
     * OpenSSH 版本（較新的 Win32-OpenSSH 才有 AF_UNIX socket 支援），非每台 Windows 都保證可用。
     *
     * @param bool $enabled
     * @param string $persist 例如 '10m'、'1h'，語法同 ssh_config 的 ControlPersist
     *
     * @return static
     */
    public function setMultiplexing($enabled = true, $persist = '10m')
    {
        $this->multiplexing = $enabled;
        $this->controlPersist = $persist;

        return $this;
    }

    /**
     * @return bool
     */
    public function isMultiplexingEnabled()
    {
        return $this->multiplexing;
    }

    /**
     * @return string
     */
    public function getControlPath()
    {
        if (!$this->controlPath) {
            $this->controlPath = sys_get_temp_dir() . '/wilkques-ssh-cm-'
                . md5($this->user . '@' . $this->sshIp . ':' . $this->port) . '.sock';
        }

        return $this->controlPath;
    }

    /**
     * 主動關閉 `setMultiplexing()` 建立的共用連線（`ssh -O exit`），沒開啟過就直接跳過
     *
     * @return void
     */
    public function closeMultiplexedConnection()
    {
        if (!$this->multiplexing) {
            return;
        }

        $controlPath = $this->getControlPath();

        $cmd = $this->buildCommandLine('ssh', array(
            '-O', 'exit',
            '-o', 'ControlPath=' . $controlPath,
            $this->getUser() . '@' . $this->getSshIp(),
        ));

        $this->getProcessRunner()->runForeground($cmd);

        @unlink($controlPath);

        $this->controlPath = null;
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
     * Common ssh/sftp/scp CLI options shared by every component.
     *
     * `ssh` takes the port flag as lowercase `-p`; `scp`/`sftp` are the odd
     * ones out and take uppercase `-P` for the same thing (lowercase `-p`
     * means "preserve file attributes" for those two instead) — callers must
     * pass whichever their underlying binary actually expects.
     *
     * @param string $portFlag '-p' for ssh, '-P' for scp/sftp
     *
     * @return array
     */
    protected function sshOptions($portFlag)
    {
        $options = array('-o', 'StrictHostKeyChecking=' . $this->strictHostKeyChecking);

        if ($this->knownHostsFile) {
            $options[] = '-o';
            $options[] = 'UserKnownHostsFile=' . $this->knownHostsFile;
        }

        $options[] = '-o';
        $options[] = 'ConnectTimeout=' . $this->timeout;

        if ($this->idRsaPath) {
            $options[] = '-i';
            $options[] = $this->idRsaPath;
        }

        if ($this->port) {
            $options[] = $portFlag;
            $options[] = $this->port;
        }

        if ($this->proxyJump) {
            $options[] = '-J';
            $options[] = $this->proxyJump;
        }

        if ($this->compression) {
            $options[] = '-C';
        }

        if ($this->multiplexing) {
            $options[] = '-o';
            $options[] = 'ControlMaster=auto';
            $options[] = '-o';
            $options[] = 'ControlPath=' . $this->getControlPath();
            $options[] = '-o';
            $options[] = 'ControlPersist=' . $this->controlPersist;
        }

        foreach ($this->extraOptions as $key => $value) {
            $options[] = '-o';
            $options[] = $key . '=' . $value;
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
