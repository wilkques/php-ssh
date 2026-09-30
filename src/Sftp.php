<?php

namespace Wilkques\Ssh;

use Wilkques\Ssh\Exceptions\SftpException;
use Wilkques\Ssh\Support\AbstractSshProcess;

/**
 * File transfer over the real sftp CLI in batch mode (genuinely speaking the
 * SFTP subsystem, not scp). Method names (put/get/nlist) match phpseclib's
 * SFTP class for a familiar API, but the implementation shells out to the
 * system sftp binary — no phpseclib dependency at all.
 */
class Sftp extends AbstractSshProcess
{
    /**
     * 上傳本機檔案到遠端（引數順序比照 phpseclib SFTP::put($remote, $local)）
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
     * 從遠端下載檔案到本機（引數順序比照 phpseclib SFTP::get($remote, $local)）
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
     * 列出遠端目錄內容（比照 phpseclib SFTP::nlist()）
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

            $names[] = $line;
        }

        return $names;
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

        $args = $this->sshOptions();
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
