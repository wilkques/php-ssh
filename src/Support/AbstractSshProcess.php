<?php

namespace Wilkques\Ssh\Support;

use Wilkques\Ssh\Exceptions\SshException;

/**
 * Shared credential/connection-option handling for Tunnel, Exec, Sftp, and
 * Scp. Not a trait: this package's PHP floor is 5.3, which has no traits.
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
     * Shared by Sftp and Scp (both one engine, two facades over the same
     * SftpChannel — see openSftpChannel()); Exec and Tunnel never touch it.
     *
     * @var SftpChannel|null
     */
    protected $channel;

    /**
     * @var ProgressReporter|null
     */
    protected $progress;

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
     * Inject an already-connected channel, bypassing openSftpChannel()
     * entirely — the seam Sftp/Scp's unit tests use to drive a real
     * SftpChannel against a FakeTransport instead of a real ssh process.
     *
     * @param SftpChannel $channel
     *
     * @return static
     */
    public function setChannel(SftpChannel $channel)
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * Lazily open (and cache) the SFTP channel used by Sftp/Scp's
     * operations.
     *
     * @return SftpChannel
     */
    protected function channel()
    {
        if (!$this->channel) {
            $this->channel = $this->openSftpChannel();
        }

        return $this->channel;
    }

    /**
     * Close the underlying channel (the ssh subprocess it holds open), if
     * one was ever opened. Safe to call when there isn't one. The next
     * put()/get()/etc. call reconnects lazily.
     *
     * @return static
     */
    public function disconnect()
    {
        if ($this->channel) {
            $this->channel->disconnect();
            $this->channel = null;
        }

        return $this;
    }

    /**
     * Set (or, with null, clear) a progress callback for put()/get(). The
     * callback signature is `function($transferred, $total, $path)`, called
     * with the number of bytes transferred and the total so far (byte
     * counts a caller already confirmed the peer has processed — a write's
     * progress only advances once its SSH_FXP_STATUS ack comes back, not
     * when the bytes are merely handed to the pipe).
     *
     * $total may be 0 if a download's remote file genuinely is empty.
     *
     * Reporting is throttled by default — see ProgressReporter — so a slow
     * callback (writing to a database, flushing to a browser, ...) can't
     * dominate a transfer's wall-clock time just because the transfer
     * happens to use a small chunk size.
     *
     * @param callable|null $callback
     * @param array $options 'interval' => seconds between reports at minimum (default 0.2),
     *                       'minDelta' => fraction of $total between reports at minimum (default 0.01)
     *
     * @return static
     */
    public function setProgress($callback, array $options = array())
    {
        $this->progress = $callback === null ? null : new ProgressReporter($callback, $options);

        return $this;
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
     * Builds this component's own exception type from a message (and,
     * since SSH_FX_* status codes need somewhere to live, an optional
     * code). Originally just a credential-setup-error factory (see
     * setIdRsaPath()), this is now also handed to SftpChannel as
     * `array($this, 'credentialException')` so one protocol engine can
     * throw SftpException for Sftp and ScpException for Scp — which is
     * also why it has to be public rather than protected: call_user_func()
     * checks visibility against the scope it's called *from*
     * (SftpChannel::raise(), unrelated to this class hierarchy), not
     * wherever the callable array happened to be built, so a protected
     * target can never be invoked that way on any PHP version.
     *
     * @param string $message
     * @param int $code
     *
     * @return \Wilkques\Ssh\Exceptions\SshException
     */
    abstract public function credentialException($message, $code = 0);

    /**
     * Launch a persistent `ssh -s host sftp` subsystem process and complete
     * the SFTP handshake over it, for Sftp/Scp — both of which need the
     * exact same launch sequence (shared ssh options, ASKPASS lifecycle,
     * the channel's platform-dependent in-flight cap) and differ only in
     * which exception type the resulting channel should throw, which
     * credentialException() already resolves polymorphically.
     *
     * @return \Wilkques\Ssh\Support\SftpChannel
     */
    protected function openSftpChannel()
    {
        $args = $this->sshOptions('-p');

        // Mirrors the four options `sftp` sets for its own ssh invocation
        // (sftp.c), so a channel opened this way behaves the same as the
        // real sftp binary would over the same connection.
        $args[] = '-o';
        $args[] = 'ForwardX11=no';
        $args[] = '-o';
        $args[] = 'PermitLocalCommand=no';
        $args[] = '-o';
        $args[] = 'ClearAllForwardings=yes';

        $args[] = '-s';
        $args[] = $this->getUser() . '@' . $this->getSshIp();
        $args[] = 'sftp';

        $cmd = $this->buildCommandLine('ssh', $args);

        $stderrFile = tempnam(sys_get_temp_dir(), 'wilkques-ssh-sftp-stderr');

        $script = $this->beginAskPass();

        $pipes = null;
        $process = $this->getProcessRunner()->openChannel($cmd, $stderrFile, $pipes);

        $this->endAskPass($script);

        if (!is_resource($process)) {
            @unlink($stderrFile);

            throw $this->credentialException('Failed to start the SFTP channel: unable to launch the ssh process.');
        }

        $transport = new ProcessTransport($this->getProcessRunner(), $process, $pipes, $stderrFile, $this->isWindows());

        $maxInFlight = $this->isWindows() ? SftpChannel::MAX_IN_FLIGHT_WINDOWS : SftpChannel::MAX_IN_FLIGHT_DEFAULT;

        $channel = new SftpChannel($transport, array($this, 'credentialException'), $maxInFlight);

        $channel->connect();

        return $channel;
    }

    /**
     * Bytes per WRITE/READ request for putFile()/getFile(). A future
     * enhancement could size this from the `limits@openssh.com` extension's
     * advertised max write/read length instead of this fixed fallback;
     * this is the same default v3 itself effectively assumes before any
     * such negotiation.
     */
    const CHUNK_SIZE = 32768;

    /**
     * S_IFMT: the file-type bits within a POSIX mode. SFTPv3's ATTRS
     * `permissions` field is the full st_mode (type bits included), unlike
     * later SFTP protocol versions, which split file type into its own
     * field.
     */
    const S_IFMT = 0170000;
    const S_IFDIR = 0040000;

    /**
     * Single-file upload, shared by Sftp::put() and Scp::put() — "one
     * engine, two facades" extends to the transfer mechanics, not just the
     * protocol channel itself, since both need the identical open/pipeline/
     * close sequence and differ only in how the caller's path arguments get
     * resolved before reaching here.
     *
     * @param string $remotePath
     * @param string $localPath
     * @param bool $resume
     *
     * @return void
     */
    protected function putFile($remotePath, $localPath, $resume)
    {
        $fp = @fopen($localPath, 'rb');

        if ($fp === false) {
            throw $this->credentialException(sprintf('Unable to open local file for reading: %s', $localPath));
        }

        $channel = $this->channel();
        $context = sprintf("put('%s')", $remotePath);

        // Resuming means NOT truncating an existing remote file — opening
        // without FXF_TRUNC leaves whatever's already there in place so a
        // subsequent WRITE at the resume offset only appends past it.
        $pflags = SftpPacket::FXF_WRITE | SftpPacket::FXF_CREAT;

        if (!$resume) {
            $pflags |= SftpPacket::FXF_TRUNC;
        }

        $openPayload = SftpPacket::packString($remotePath) . SftpPacket::uint32ToBytes($pflags) . SftpPacket::encodeAttrs(array());

        $handle = $channel->expectHandle(SftpPacket::TYPE_OPEN, $openPayload, $context);

        $startOffset = 0;

        if ($resume) {
            $attrs = $channel->expectAttrs(SftpPacket::TYPE_FSTAT, SftpPacket::packString($handle), $context);
            $startOffset = isset($attrs['size']) ? $attrs['size'] : 0;

            fseek($fp, $startOffset);
        }

        $size = filesize($localPath);
        $remaining = $size > $startOffset ? $size - $startOffset : 0;
        $chunkSize = self::CHUNK_SIZE;
        $totalChunks = $remaining > 0 ? (int) ceil($remaining / $chunkSize) : 0;

        $progress = $this->progress;

        if ($progress) {
            $progress->reset();
        }

        // Starts at $startOffset (not 0) so a resumed transfer's progress
        // reflects what the server already has, rather than restarting a
        // caller's progress bar from zero.
        $transferred = $startOffset;
        $chunkLengths = array();

        $caught = null;

        try {
            $channel->pipeline(
                $totalChunks,
                function ($index) use ($fp, $chunkSize, $handle, $startOffset, &$chunkLengths) {
                    $offset = $startOffset + $index * $chunkSize;
                    $data = fread($fp, $chunkSize);
                    $data = $data === false ? '' : $data;

                    $chunkLengths[$index] = strlen($data);

                    $payload = SftpPacket::packString($handle)
                        . SftpPacket::uint64ToBytes($offset)
                        . SftpPacket::packString($data);

                    return array(SftpPacket::TYPE_WRITE, $payload);
                },
                function ($index, $type, $payload) use ($channel, $context, $progress, &$transferred, &$chunkLengths, $size, $remotePath) {
                    $channel->assertStatusOk($type, $payload, $context);

                    if ($progress) {
                        // Progress only advances once the peer has actually
                        // acked a WRITE, not when bytes are merely handed to
                        // the pipe — unlike phpseclib, whose put() progress
                        // fires before any ack is read at all.
                        $transferred += $chunkLengths[$index];
                        unset($chunkLengths[$index]);

                        $progress->report($transferred, $size, $remotePath);
                    }
                }
            );
        } catch (\Exception $e) {
            $caught = $e;
        }

        fclose($fp);

        // Best-effort: close the remote handle even after a failed transfer
        // so a half-written file's handle doesn't linger server-side.
        // Swallow a second failure here — the original exception (if any)
        // is what the caller actually needs to see.
        try {
            $channel->expectStatusOk(SftpPacket::TYPE_CLOSE, SftpPacket::packString($handle), $context);
        } catch (\Exception $e) {
            if (!$caught) {
                $caught = $e;
            }
        }

        if ($caught) {
            throw $caught;
        }
    }

    /**
     * Recursive local-directory-to-remote-directory upload, shared by
     * Sftp::put() and Scp::put().
     *
     * @param string $remotePath
     * @param string $localPath
     *
     * @return void
     */
    protected function putDirectoryTree($remotePath, $localPath)
    {
        if (!$this->remoteExists($remotePath)) {
            $this->channel()->expectStatusOk(
                SftpPacket::TYPE_MKDIR,
                SftpPacket::packString($remotePath) . SftpPacket::encodeAttrs(array()),
                sprintf("mkdir('%s')", $remotePath)
            );
        }

        $entries = scandir($localPath);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $localChild = rtrim($localPath, '/\\') . DIRECTORY_SEPARATOR . $entry;
            $remoteChild = rtrim($remotePath, '/') . '/' . $entry;

            if (is_dir($localChild)) {
                $this->putDirectoryTree($remoteChild, $localChild);
            } else {
                $this->putFile($remoteChild, $localChild, false);
            }
        }
    }

    /**
     * Single-file download, shared by Sftp::get() and Scp::get().
     *
     * @param string $remotePath
     * @param string $localPath
     * @param bool $resume
     *
     * @return void
     */
    protected function getFile($remotePath, $localPath, $resume)
    {
        $channel = $this->channel();
        $context = sprintf("get('%s')", $remotePath);

        $openPayload = SftpPacket::packString($remotePath)
            . SftpPacket::uint32ToBytes(SftpPacket::FXF_READ)
            . SftpPacket::encodeAttrs(array());

        $handle = $channel->expectHandle(SftpPacket::TYPE_OPEN, $openPayload, $context);

        // FSTAT on the just-opened handle rather than STAT on the path, so
        // there's no race between resolving the path and opening it.
        $attrs = $channel->expectAttrs(SftpPacket::TYPE_FSTAT, SftpPacket::packString($handle), $context);
        $size = isset($attrs['size']) ? $attrs['size'] : 0;

        $startOffset = 0;
        $localMode = 'wb';

        if ($resume && is_file($localPath)) {
            $localSize = filesize($localPath);

            // A local file bigger than the remote one isn't a sane resume
            // point (nothing to continue from) — fall back to a full
            // re-download rather than silently producing a truncated file.
            if ($localSize <= $size) {
                $startOffset = $localSize;
                $localMode = 'ab';
            }
        }

        $fp = @fopen($localPath, $localMode);

        if ($fp === false) {
            try {
                $channel->expectStatusOk(SftpPacket::TYPE_CLOSE, SftpPacket::packString($handle), $context);
            } catch (\Exception $e) {
                // the local-file error below is what the caller needs to see
            }

            throw $this->credentialException(sprintf('Unable to open local file for writing: %s', $localPath));
        }

        $remaining = $size > $startOffset ? $size - $startOffset : 0;
        $chunkSize = self::CHUNK_SIZE;
        $totalChunks = $remaining > 0 ? (int) ceil($remaining / $chunkSize) : 0;

        $progress = $this->progress;

        if ($progress) {
            $progress->reset();
        }

        $transferred = $startOffset;

        $caught = null;

        try {
            $channel->pipeline(
                $totalChunks,
                function ($index) use ($handle, $chunkSize, $startOffset) {
                    $offset = $startOffset + $index * $chunkSize;

                    $payload = SftpPacket::packString($handle)
                        . SftpPacket::uint64ToBytes($offset)
                        . SftpPacket::uint32ToBytes($chunkSize);

                    return array(SftpPacket::TYPE_READ, $payload);
                },
                function ($index, $type, $payload) use ($fp, $channel, $context, $progress, &$transferred, $size, $remotePath) {
                    if ($type === SftpPacket::TYPE_DATA) {
                        $data = SftpPacket::decodeData($payload);

                        fwrite($fp, $data);

                        if ($progress) {
                            $transferred += strlen($data);

                            $progress->report($transferred, $size, $remotePath);
                        }

                        return;
                    }

                    if ($type === SftpPacket::TYPE_STATUS) {
                        $status = SftpPacket::decodeStatus($payload);

                        // Every READ here targets an offset within the size
                        // FSTAT just reported, so this shouldn't happen —
                        // but a file truncated concurrently on the server
                        // could still produce it; treat it the same way
                        // nlist()'s READDIR loop treats EOF, not as an error.
                        if ($status['code'] === SftpPacket::STATUS_EOF) {
                            return;
                        }
                    }

                    $channel->assertStatusOk($type, $payload, $context);
                }
            );
        } catch (\Exception $e) {
            $caught = $e;
        }

        fclose($fp);

        try {
            $channel->expectStatusOk(SftpPacket::TYPE_CLOSE, SftpPacket::packString($handle), $context);
        } catch (\Exception $e) {
            if (!$caught) {
                $caught = $e;
            }
        }

        if ($caught) {
            throw $caught;
        }
    }

    /**
     * Recursive remote-directory-to-local-directory download, shared by
     * Sftp::get() and Scp::get().
     *
     * @param string $remotePath
     * @param string $localPath
     *
     * @return void
     */
    protected function getDirectoryTree($remotePath, $localPath)
    {
        if (!is_dir($localPath) && !@mkdir($localPath, 0777, true) && !is_dir($localPath)) {
            throw $this->credentialException(sprintf('Unable to create local directory: %s', $localPath));
        }

        $names = $this->listDirectory($remotePath);

        foreach ($names as $name) {
            $remoteChild = rtrim($remotePath, '/') . '/' . $name;
            $localChild = rtrim($localPath, '/\\') . DIRECTORY_SEPARATOR . $name;

            $attrs = $this->fetchAttrs($remoteChild);

            if ($this->isDirectoryMode(isset($attrs['permissions']) ? $attrs['permissions'] : 0)) {
                $this->getDirectoryTree($remoteChild, $localChild);
            } else {
                $this->getFile($remoteChild, $localChild, false);
            }
        }
    }

    /**
     * @param int $permissions the ATTRS 'permissions' field (full st_mode, not just rwx bits)
     *
     * @return bool
     */
    protected function isDirectoryMode($permissions)
    {
        return ($permissions & self::S_IFMT) === self::S_IFDIR;
    }

    /**
     * SSH_FXP_STAT — follows symlinks. The primitive behind Sftp::stat();
     * also used internally by getDirectoryTree() to tell files from
     * directories while walking a remote tree.
     *
     * @param string $remotePath
     *
     * @return array decoded ATTRS
     */
    protected function fetchAttrs($remotePath)
    {
        return $this->channel()->expectAttrs(
            SftpPacket::TYPE_STAT,
            SftpPacket::packString($remotePath),
            sprintf("stat('%s')", $remotePath)
        );
    }

    /**
     * SSH_FXP_OPENDIR/READDIR/CLOSE loop — the primitive behind
     * Sftp::nlist(); also used internally by getDirectoryTree().
     *
     * @param string $remotePath
     *
     * @return string[] filenames, '.'/'..' filtered
     */
    protected function listDirectory($remotePath)
    {
        $channel = $this->channel();
        $context = sprintf("nlist('%s')", $remotePath);

        $handle = $channel->expectHandle(SftpPacket::TYPE_OPENDIR, SftpPacket::packString($remotePath), $context);

        $names = array();
        $caught = null;

        try {
            while (true) {
                $entries = $channel->expectNameList(SftpPacket::TYPE_READDIR, SftpPacket::packString($handle), $context);

                if ($entries === null) {
                    break;
                }

                foreach ($entries as $entry) {
                    // '.'/'..' are filtered (matching what a caller actually
                    // wants from "list this directory's contents"); unlike
                    // the old `sftp -b` + `ls -1` path this replaces,
                    // dotfiles are NOT otherwise hidden — SSH_FXP_READDIR
                    // doesn't hide them, and there's no good reason to.
                    if ($entry['filename'] === '.' || $entry['filename'] === '..') {
                        continue;
                    }

                    $names[] = $entry['filename'];
                }
            }
        } catch (\Exception $e) {
            $caught = $e;
        }

        try {
            $channel->expectStatusOk(SftpPacket::TYPE_CLOSE, SftpPacket::packString($handle), $context);
        } catch (\Exception $e) {
            if (!$caught) {
                $caught = $e;
            }
        }

        if ($caught) {
            throw $caught;
        }

        return $names;
    }

    /**
     * SSH_FXP_LSTAT (not STAT — reports the path entry itself without
     * following a symlink, so a broken symlink still counts as "exists").
     * The primitive behind Sftp::exists(); also used internally by
     * putDirectoryTree() to decide whether a remote directory needs
     * creating.
     *
     * @param string $remotePath
     *
     * @return bool
     */
    protected function remoteExists($remotePath)
    {
        try {
            $this->channel()->expectAttrs(
                SftpPacket::TYPE_LSTAT,
                SftpPacket::packString($remotePath),
                sprintf("exists('%s')", $remotePath)
            );
        } catch (SshException $e) {
            if ($e->getCode() === SftpPacket::STATUS_NO_SUCH_FILE) {
                return false;
            }

            throw $e;
        }

        return true;
    }
}
