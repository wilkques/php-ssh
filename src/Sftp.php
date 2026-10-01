<?php

namespace Wilkques\Ssh;

use Wilkques\Ssh\Exceptions\SftpException;
use Wilkques\Ssh\Support\AbstractSshProcess;
use Wilkques\Ssh\Support\SftpPacket;

/**
 * File transfer over a real SFTPv3 protocol channel: `ssh -s host sftp`
 * held open as a persistent subprocess, with PHP speaking the file-transfer
 * packet layer directly against its pipes (see SftpChannel). This used to
 * shell out to `sftp -q -b <batchfile>` once per call instead — that never
 * exposed per-byte progress on any platform, because sftp(1)'s own
 * progress meter is unconditionally disabled in batch mode (sftp.c) and,
 * even interactively, only ever draws when stdout is a foreground
 * terminal — something proc_open can't provide on Windows. Needs only the
 * `ssh` binary now, not `sftp`.
 */
class Sftp extends AbstractSshProcess
{
    /**
     * Bytes per WRITE/READ request. A future enhancement could size this
     * from the `limits@openssh.com` extension's advertised max write/read
     * length instead of this fixed fallback; this is the same default v3
     * itself effectively assumes before any such negotiation.
     */
    const CHUNK_SIZE = 32768;

    /** S_IFMT: the file-type bits within a POSIX mode. SFTPv3's `permissions`
     * ATTRS field is the full st_mode (type bits included), unlike later
     * SFTP protocol versions, which split file type into its own field. */
    const S_IFMT = 0170000;
    const S_IFDIR = 0040000;

    /**
     * 上傳本機檔案到遠端。$localPath 若是本機目錄，需要在 $options 傳
     * array('recursive' => true) 才會遞迴上傳整個目錄樹，否則丟例外；
     * array('resume' => true) 則是從遠端現有檔案大小的地方接著上傳
     * （不驗證內容是否相符，是最單純的「接著位移量繼續傳」）
     *
     * @param string $remotePath
     * @param string $localPath
     * @param array $options 'recursive' => bool, 'resume' => bool
     *
     * @return void
     */
    public function put($remotePath, $localPath, array $options = array())
    {
        if (is_dir($localPath)) {
            if (empty($options['recursive'])) {
                throw $this->credentialException(sprintf(
                    '%s is a local directory; pass array(\'recursive\' => true) to upload it',
                    $localPath
                ));
            }

            $this->putDirectory($remotePath, $localPath);

            return;
        }

        if (!is_file($localPath)) {
            throw $this->credentialException(sprintf('Unable to read local file: %s', $localPath));
        }

        $this->putFile($remotePath, $localPath, !empty($options['resume']));
    }

    /**
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
     * @param string $remotePath
     * @param string $localPath
     *
     * @return void
     */
    protected function putDirectory($remotePath, $localPath)
    {
        if (!$this->exists($remotePath)) {
            $this->mkdir($remotePath);
        }

        $entries = scandir($localPath);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $localChild = rtrim($localPath, '/\\') . DIRECTORY_SEPARATOR . $entry;
            $remoteChild = rtrim($remotePath, '/') . '/' . $entry;

            if (is_dir($localChild)) {
                $this->putDirectory($remoteChild, $localChild);
            } else {
                $this->putFile($remoteChild, $localChild, false);
            }
        }
    }

    /**
     * 從遠端下載檔案到本機。$remotePath 若是遠端目錄，需要在 $options 傳
     * array('recursive' => true) 才會遞迴下載整個目錄樹，否則丟例外；
     * array('resume' => true) 則是從本機現有檔案大小的地方接著下載
     * （不驗證內容是否相符）
     *
     * @param string $remotePath
     * @param string $localPath
     * @param array $options 'recursive' => bool, 'resume' => bool
     *
     * @return void
     */
    public function get($remotePath, $localPath, array $options = array())
    {
        if (!empty($options['recursive'])) {
            $stat = $this->stat($remotePath);

            if ($this->isDirectoryMode(isset($stat['permissions']) ? $stat['permissions'] : 0)) {
                $this->getDirectory($remotePath, $localPath);

                return;
            }
        }

        $this->getFile($remotePath, $localPath, !empty($options['resume']));
    }

    /**
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
     * @param string $remotePath
     * @param string $localPath
     *
     * @return void
     */
    protected function getDirectory($remotePath, $localPath)
    {
        if (!is_dir($localPath) && !@mkdir($localPath, 0777, true) && !is_dir($localPath)) {
            throw $this->credentialException(sprintf('Unable to create local directory: %s', $localPath));
        }

        $names = $this->nlist($remotePath);

        foreach ($names as $name) {
            $remoteChild = rtrim($remotePath, '/') . '/' . $name;
            $localChild = rtrim($localPath, '/\\') . DIRECTORY_SEPARATOR . $name;

            $stat = $this->stat($remoteChild);

            if ($this->isDirectoryMode(isset($stat['permissions']) ? $stat['permissions'] : 0)) {
                $this->getDirectory($remoteChild, $localChild);
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
     * 列出遠端目錄內容
     *
     * @param string $remotePath
     *
     * @return string[]
     */
    public function nlist($remotePath = '.')
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
     * 刪除遠端檔案
     *
     * @param string $remotePath
     *
     * @return void
     */
    public function delete($remotePath)
    {
        $this->channel()->expectStatusOk(
            SftpPacket::TYPE_REMOVE,
            SftpPacket::packString($remotePath),
            sprintf("delete('%s')", $remotePath)
        );
    }

    /**
     * 建立遠端目錄
     *
     * @param string $remotePath
     *
     * @return void
     */
    public function mkdir($remotePath)
    {
        $payload = SftpPacket::packString($remotePath) . SftpPacket::encodeAttrs(array());

        $this->channel()->expectStatusOk(SftpPacket::TYPE_MKDIR, $payload, sprintf("mkdir('%s')", $remotePath));
    }

    /**
     * 刪除遠端目錄（目錄必須是空的）
     *
     * @param string $remotePath
     *
     * @return void
     */
    public function rmdir($remotePath)
    {
        $this->channel()->expectStatusOk(
            SftpPacket::TYPE_RMDIR,
            SftpPacket::packString($remotePath),
            sprintf("rmdir('%s')", $remotePath)
        );
    }

    /**
     * 重新命名/搬移遠端檔案或目錄。伺服器有支援 `posix-rename@openssh.com`
     * extension 的話優先使用（蓋過既有目標），沒有才退回標準 v3 RENAME
     * （目標已存在時會失敗）——跟真正 `sftp` 二進位檔的行為一致
     *
     * @param string $fromPath
     * @param string $toPath
     *
     * @return void
     */
    public function rename($fromPath, $toPath)
    {
        $channel = $this->channel();
        $context = sprintf("rename('%s', '%s')", $fromPath, $toPath);

        if ($channel->hasExtension('posix-rename@openssh.com', '1')) {
            $payload = SftpPacket::packString('posix-rename@openssh.com')
                . SftpPacket::packString($fromPath)
                . SftpPacket::packString($toPath);

            $channel->expectStatusOk(SftpPacket::TYPE_EXTENDED, $payload, $context);

            return;
        }

        $payload = SftpPacket::packString($fromPath) . SftpPacket::packString($toPath);

        $channel->expectStatusOk(SftpPacket::TYPE_RENAME, $payload, $context);
    }

    /**
     * 修改遠端檔案權限。$mode 可以傳 PHP 的 8 進位整數字面值（如 0644）或字串（如 '644'）
     *
     * @param string $remotePath
     * @param int|string $mode
     *
     * @return void
     */
    public function chmod($remotePath, $mode)
    {
        // An int literal like 0644 already numerically IS the POSIX mode
        // bits to send; a string like '644' is digits-as-text and needs
        // octdec() to become that same number (intval('644') would give
        // decimal 644, not octal 0644 — the wrong bits entirely).
        $modeInt = is_int($mode) ? $mode : octdec($mode);

        $payload = SftpPacket::packString($remotePath) . SftpPacket::encodeAttrs(array('permissions' => $modeInt));

        $this->channel()->expectStatusOk(SftpPacket::TYPE_SETSTAT, $payload, sprintf("chmod('%s')", $remotePath));
    }

    /**
     * 判斷遠端路徑是否存在（檔案或目錄皆可）。只有在伺服器明確回報
     * SSH_FX_NO_SUCH_FILE 時才回傳 false；其他錯誤（例如權限不足）一律往上丟，
     * 不會被誤判成「不存在」
     *
     * @param string $remotePath
     *
     * @return bool
     */
    public function exists($remotePath)
    {
        try {
            // LSTAT (not STAT) to match what the old `ls <path>` batch
            // command did: report the path entry itself without following
            // a symlink, so a broken symlink still counts as "exists".
            $this->channel()->expectAttrs(
                SftpPacket::TYPE_LSTAT,
                SftpPacket::packString($remotePath),
                sprintf("exists('%s')", $remotePath)
            );
        } catch (SftpException $e) {
            if ($e->getCode() === SftpPacket::STATUS_NO_SUCH_FILE) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * 取得遠端路徑的檔案資訊（會 follow symlink；要拿符號連結本身的資訊而不是
     * 它指向的目標，請改用內部的 lstat 邏輯——目前沒有對外開放 lstat()，
     * 因為 exists() 已經是唯一需要它的地方）。回傳的陣列視伺服器實際回報
     * 的欄位而定，可能包含 'size'、'uid'、'gid'、'permissions'、'atime'、'mtime'
     *
     * @param string $remotePath
     *
     * @return array
     */
    public function stat($remotePath)
    {
        return $this->channel()->expectAttrs(
            SftpPacket::TYPE_STAT,
            SftpPacket::packString($remotePath),
            sprintf("stat('%s')", $remotePath)
        );
    }

    /**
     * 請伺服器解析遠端路徑為絕對路徑（例如展開 `~`、處理 `..`）
     *
     * @param string $remotePath
     *
     * @return string
     */
    public function realpath($remotePath)
    {
        $channel = $this->channel();
        $context = sprintf("realpath('%s')", $remotePath);

        $entries = $channel->expectNameList(SftpPacket::TYPE_REALPATH, SftpPacket::packString($remotePath), $context);

        if (empty($entries)) {
            throw $this->credentialException(sprintf('%s: server returned no result', $context));
        }

        return $entries[0]['filename'];
    }

    /**
     * @param string $message
     * @param int $code
     *
     * @return \Wilkques\Ssh\Exceptions\SftpException
     */
    public function credentialException($message, $code = 0)
    {
        return new SftpException($message, $code);
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
