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

    /**
     * 上傳本機檔案到遠端
     *
     * @param string $remotePath
     * @param string $localPath
     *
     * @return void
     */
    public function put($remotePath, $localPath)
    {
        if (!is_file($localPath)) {
            throw $this->credentialException(sprintf('Unable to read local file: %s', $localPath));
        }

        $fp = @fopen($localPath, 'rb');

        if ($fp === false) {
            throw $this->credentialException(sprintf('Unable to open local file for reading: %s', $localPath));
        }

        $channel = $this->channel();
        $context = sprintf("put('%s')", $remotePath);

        $openPayload = SftpPacket::packString($remotePath)
            . SftpPacket::uint32ToBytes(SftpPacket::FXF_WRITE | SftpPacket::FXF_CREAT | SftpPacket::FXF_TRUNC)
            . SftpPacket::encodeAttrs(array());

        $handle = $channel->expectHandle(SftpPacket::TYPE_OPEN, $openPayload, $context);

        $size = filesize($localPath);
        $chunkSize = self::CHUNK_SIZE;
        $totalChunks = $size > 0 ? (int) ceil($size / $chunkSize) : 0;

        $caught = null;

        try {
            $channel->pipeline(
                $totalChunks,
                function ($index) use ($fp, $chunkSize, $handle) {
                    $offset = $index * $chunkSize;
                    $data = fread($fp, $chunkSize);

                    $payload = SftpPacket::packString($handle)
                        . SftpPacket::uint64ToBytes($offset)
                        . SftpPacket::packString($data === false ? '' : $data);

                    return array(SftpPacket::TYPE_WRITE, $payload);
                },
                function ($index, $type, $payload) use ($channel, $context) {
                    $channel->assertStatusOk($type, $payload, $context);
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
     * 從遠端下載檔案到本機
     *
     * @param string $remotePath
     * @param string $localPath
     *
     * @return void
     */
    public function get($remotePath, $localPath)
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

        $fp = @fopen($localPath, 'wb');

        if ($fp === false) {
            try {
                $channel->expectStatusOk(SftpPacket::TYPE_CLOSE, SftpPacket::packString($handle), $context);
            } catch (\Exception $e) {
                // the local-file error below is what the caller needs to see
            }

            throw $this->credentialException(sprintf('Unable to open local file for writing: %s', $localPath));
        }

        $chunkSize = self::CHUNK_SIZE;
        $totalChunks = $size > 0 ? (int) ceil($size / $chunkSize) : 0;

        $caught = null;

        try {
            $channel->pipeline(
                $totalChunks,
                function ($index) use ($handle, $chunkSize) {
                    $offset = $index * $chunkSize;

                    $payload = SftpPacket::packString($handle)
                        . SftpPacket::uint64ToBytes($offset)
                        . SftpPacket::uint32ToBytes($chunkSize);

                    return array(SftpPacket::TYPE_READ, $payload);
                },
                function ($index, $type, $payload) use ($fp, $channel, $context) {
                    if ($type === SftpPacket::TYPE_DATA) {
                        fwrite($fp, SftpPacket::decodeData($payload));

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
