<?php

namespace Wilkques\Ssh;

use Wilkques\Ssh\Exceptions\ScpException;
use Wilkques\Ssh\Exceptions\SshException;
use Wilkques\Ssh\Support\AbstractSshProcess;
use Wilkques\Ssh\Support\SftpPacket;

/**
 * File/directory copy with `scp`'s own path semantics (an existing
 * directory target means "copy into it"), by default over the same
 * SftpChannel as Sftp — "one engine, two facades", mirroring the
 * relationship upstream `scp` and `sftp` have had since OpenSSH 9.0, when
 * `scp` itself switched to speaking SFTP under the hood by default (`-O`
 * reverts it to the legacy SCP/RCP protocol; see scp.c). setLegacy(true)
 * does the same thing here: falls back to shelling out to the real `scp`
 * binary with `-O`, for a server with no SFTP subsystem. Progress
 * reporting (see AbstractSshProcess::setProgress()) only works against the
 * channel, not in legacy mode.
 */
class Scp extends AbstractSshProcess
{
    /** @var bool */
    protected $legacy = false;

    /**
     * @param bool $enabled
     *
     * @return static
     */
    public function setLegacy($enabled = true)
    {
        $this->legacy = $enabled;

        return $this;
    }

    /**
     * @return bool
     */
    public function isLegacy()
    {
        return $this->legacy;
    }

    /**
     * 上傳本機檔案（或目錄，$recursive = true 時）到遠端。遠端路徑若已經是
     * 一個目錄，會複製「進去」（檔名變成 `$remotePath/basename($localPath)`），
     * 跟真正 scp 的行為一致
     *
     * @param string $remotePath
     * @param string $localPath
     * @param bool $recursive
     *
     * @return void
     */
    public function put($remotePath, $localPath, $recursive = false)
    {
        $this->guardAgainstLegacyProgressConflict();

        if ($this->legacy) {
            $this->putLegacy($remotePath, $localPath, $recursive);

            return;
        }

        $remotePath = $this->resolveRemoteDirectoryTarget($remotePath, basename(rtrim($localPath, '/\\')));

        if (is_dir($localPath)) {
            if (!$recursive) {
                throw $this->credentialException(sprintf(
                    '%s is a local directory; pass $recursive = true to upload it',
                    $localPath
                ));
            }

            $this->putDirectoryTree($remotePath, $localPath);

            return;
        }

        if (!is_file($localPath)) {
            throw $this->credentialException(sprintf('Unable to read local file: %s', $localPath));
        }

        $this->putFile($remotePath, $localPath, false);
    }

    /**
     * 從遠端下載檔案（或目錄，$recursive = true 時）到本機。本機路徑若已經是
     * 一個目錄，會下載「進去」（檔名變成 `$localPath/basename($remotePath)`），
     * 跟真正 scp 的行為一致
     *
     * @param string $remotePath
     * @param string $localPath
     * @param bool $recursive
     *
     * @return void
     */
    public function get($remotePath, $localPath, $recursive = false)
    {
        $this->guardAgainstLegacyProgressConflict();

        if ($this->legacy) {
            $this->getLegacy($remotePath, $localPath, $recursive);

            return;
        }

        $attrs = $this->fetchAttrs($remotePath);
        $remoteIsDir = $this->isDirectoryMode(isset($attrs['permissions']) ? $attrs['permissions'] : 0);

        if ($remoteIsDir && !$recursive) {
            throw $this->credentialException(sprintf(
                '%s is a remote directory; pass $recursive = true to download it',
                $remotePath
            ));
        }

        if (is_dir($localPath)) {
            $localPath = rtrim($localPath, '/\\') . DIRECTORY_SEPARATOR . basename(rtrim($remotePath, '/'));
        }

        if ($remoteIsDir) {
            $this->getDirectoryTree($remotePath, $localPath);

            return;
        }

        $this->getFile($remotePath, $localPath, false);
    }

    /**
     * @return void
     */
    protected function guardAgainstLegacyProgressConflict()
    {
        if ($this->legacy && $this->progress) {
            throw $this->credentialException(
                "setProgress() has no effect with setLegacy(true) — legacy mode shells out to the real scp binary, "
                . 'which this package does not parse output from. Call setLegacy(false) to get progress back, '
                . 'or setProgress(null) to proceed without it.'
            );
        }
    }

    /**
     * If $remotePath already exists and is a directory, resolve to
     * `$remotePath/$basename` instead — e.g. `put('/existing/dir', 'local.txt')`
     * uploads to `/existing/dir/local.txt`, not literally to a file named
     * `dir`, matching what `scp localfile user@host:/existing/dir` does.
     *
     * @param string $remotePath
     * @param string $basename
     *
     * @return string
     */
    protected function resolveRemoteDirectoryTarget($remotePath, $basename)
    {
        $isDir = false;

        try {
            $attrs = $this->fetchAttrs($remotePath);
            $isDir = $this->isDirectoryMode(isset($attrs['permissions']) ? $attrs['permissions'] : 0);
        } catch (SshException $e) {
            if ($e->getCode() !== SftpPacket::STATUS_NO_SUCH_FILE) {
                throw $e;
            }
        }

        if ($isDir) {
            return rtrim($remotePath, '/') . '/' . $basename;
        }

        return $remotePath;
    }

    /**
     * @param string $remotePath
     * @param string $localPath
     * @param bool $recursive
     *
     * @return void
     */
    protected function putLegacy($remotePath, $localPath, $recursive)
    {
        $args = $this->sshOptions('-P');

        $args[] = '-O';

        if ($recursive) {
            $args[] = '-r';
        }

        $args[] = $localPath;
        $args[] = $this->getUser() . '@' . $this->getSshIp() . ':' . $remotePath;

        $this->runScp('put', $args);
    }

    /**
     * @param string $remotePath
     * @param string $localPath
     * @param bool $recursive
     *
     * @return void
     */
    protected function getLegacy($remotePath, $localPath, $recursive)
    {
        $args = $this->sshOptions('-P');

        $args[] = '-O';

        if ($recursive) {
            $args[] = '-r';
        }

        $args[] = $this->getUser() . '@' . $this->getSshIp() . ':' . $remotePath;
        $args[] = $localPath;

        $this->runScp('get', $args);
    }

    /**
     * @param string $operation
     * @param array $args
     *
     * @return void
     */
    protected function runScp($operation, array $args)
    {
        $result = $this->runForeground('scp', $args);

        if ($result['exitCode'] !== 0) {
            throw new ScpException(sprintf(
                'scp %s failed (exit %d): %s',
                $operation,
                $result['exitCode'],
                trim($result['stderr'])
            ));
        }
    }

    /**
     * @param string $message
     * @param int $code
     *
     * @return \Wilkques\Ssh\Exceptions\ScpException
     */
    public function credentialException($message, $code = 0)
    {
        return new ScpException($message, $code);
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
