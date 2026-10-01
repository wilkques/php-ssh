<?php

namespace Wilkques\Ssh;

use Wilkques\Ssh\Exceptions\SftpException;
use Wilkques\Ssh\Support\AbstractSshProcess;

/**
 * File transfer over the real sftp CLI in batch mode (genuinely speaking the
 * SFTP subsystem, not scp).
 */
class Sftp extends AbstractSshProcess
{
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
        $this->runBatch('put', 'put ' . $this->quoteBatchArg($localPath) . ' ' . $this->quoteBatchArg($remotePath));
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
        $this->runBatch('get', 'get ' . $this->quoteBatchArg($remotePath) . ' ' . $this->quoteBatchArg($localPath));
    }

    /**
     * 列出遠端目錄內容
     *
     * known fragility: sftp batch-mode output formatting varies slightly
     * across OpenSSH versions/platforms; this parses standard OpenSSH
     * `sftp -q -b` output and may need adjustment for unusual sftp builds.
     *
     * @param string $remotePath
     *
     * @return string[]
     */
    public function nlist($remotePath = '.')
    {
        $stdout = $this->runBatch('nlist', 'ls -1 ' . $this->quoteBatchArg($remotePath), true);

        $lines = preg_split('/\r\n|\r|\n/', $stdout);

        $names = array();

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, 'sftp>') === 0) {
                continue;
            }

            // 真實 sftp 的 `ls -1 <dir>` 會把查詢路徑原樣接回每一行（例如
            // `ls -1 /tmp/xxx` 印出 `/tmp/xxx/test.txt`，不是單純檔名），這裡
            // 統一轉成 basename，符合「列出目錄內容」直覺預期的檔名陣列
            $names[] = basename($line);
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
        $this->runBatch('delete', 'rm ' . $this->quoteBatchArg($remotePath));
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
        $this->runBatch('mkdir', 'mkdir ' . $this->quoteBatchArg($remotePath));
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
        $this->runBatch('rmdir', 'rmdir ' . $this->quoteBatchArg($remotePath));
    }

    /**
     * 重新命名/搬移遠端檔案或目錄
     *
     * @param string $fromPath
     * @param string $toPath
     *
     * @return void
     */
    public function rename($fromPath, $toPath)
    {
        $this->runBatch('rename', 'rename ' . $this->quoteBatchArg($fromPath) . ' ' . $this->quoteBatchArg($toPath));
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
        $modeString = is_int($mode) ? decoct($mode) : $mode;

        $this->runBatch('chmod', 'chmod ' . $modeString . ' ' . $this->quoteBatchArg($remotePath));
    }

    /**
     * 判斷遠端路徑是否存在（檔案或目錄皆可）
     *
     * @param string $remotePath
     *
     * @return bool
     */
    public function exists($remotePath)
    {
        try {
            $this->runBatch('exists', 'ls ' . $this->quoteBatchArg($remotePath));
        } catch (SftpException $e) {
            return false;
        }

        return true;
    }

    /**
     * @param string $operation
     * @param string $batchCommand
     * @param bool $returnStdout
     *
     * @return string
     */
    protected function runBatch($operation, $batchCommand, $returnStdout = false)
    {
        $batchFile = tempnam(sys_get_temp_dir(), 'wilkques-sftp-batch');

        file_put_contents($batchFile, $batchCommand . "\n");

        $args = $this->sshOptions('-P');
        $args[] = '-q';
        $args[] = '-b';
        $args[] = $batchFile;
        $args[] = $this->getUser() . '@' . $this->getSshIp();

        $result = null;
        $caught = null;

        try {
            $result = $this->runForeground('sftp', $args);
        } catch (\Exception $e) {
            $caught = $e;
        }

        @unlink($batchFile);

        if ($caught) {
            throw $caught;
        }

        if ($result['exitCode'] !== 0) {
            throw new SftpException(sprintf(
                'sftp %s failed (exit %d): %s',
                $operation,
                $result['exitCode'],
                trim($result['stderr'])
            ));
        }

        return $returnStdout ? $result['stdout'] : '';
    }

    /**
     * sftp 的 batch 指令用空白分隔參數，路徑含空白時用雙引號包起來
     *
     * @param string $value
     *
     * @return string
     */
    protected function quoteBatchArg($value)
    {
        return '"' . str_replace('"', '\\"', $value) . '"';
    }

    /**
     * @param string $message
     *
     * @return \Wilkques\Ssh\Exceptions\SftpException
     */
    protected function credentialException($message)
    {
        return new SftpException($message);
    }
}
