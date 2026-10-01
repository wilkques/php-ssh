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
 *
 * The actual transfer mechanics (open/pipeline/close, recursive directory
 * walks, exists/stat/nlist's underlying requests) live on AbstractSshProcess,
 * shared with Scp — "one engine, two facades" over the same SftpChannel,
 * mirroring the relationship upstream `sftp`/`scp` have had since OpenSSH
 * 9.0 made `scp` an SFTP client too. The methods here are thin wrappers
 * that resolve this class's own path-argument conventions before handing
 * off to those shared primitives.
 */
class Sftp extends AbstractSshProcess
{
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

            $this->putDirectoryTree($remotePath, $localPath);

            return;
        }

        if (!is_file($localPath)) {
            throw $this->credentialException(sprintf('Unable to read local file: %s', $localPath));
        }

        $this->putFile($remotePath, $localPath, !empty($options['resume']));
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
            $attrs = $this->fetchAttrs($remotePath);

            if ($this->isDirectoryMode(isset($attrs['permissions']) ? $attrs['permissions'] : 0)) {
                $this->getDirectoryTree($remotePath, $localPath);

                return;
            }
        }

        $this->getFile($remotePath, $localPath, !empty($options['resume']));
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
        return $this->listDirectory($remotePath);
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
        return $this->remoteExists($remotePath);
    }

    /**
     * 取得遠端路徑的檔案資訊（會 follow symlink；exists() 內部另外用不對外
     * 開放的 lstat 邏輯，才不會跟著符號連結走）。回傳的陣列視伺服器實際回報
     * 的欄位而定，可能包含 'size'、'uid'、'gid'、'permissions'、'atime'、'mtime'
     *
     * @param string $remotePath
     *
     * @return array
     */
    public function stat($remotePath)
    {
        return $this->fetchAttrs($remotePath);
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
