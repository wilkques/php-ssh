<?php

namespace Wilkques\Ssh;

use Wilkques\Ssh\Exceptions\ScpException;
use Wilkques\Ssh\Support\AbstractSshProcess;

/**
 * File transfer over the real scp binary.
 */
class Scp extends AbstractSshProcess
{
    /**
     * 上傳本機檔案（或目錄，$recursive = true 時）到遠端
     *
     * @param string $remotePath
     * @param string $localPath
     * @param bool $recursive
     *
     * @return void
     */
    public function put($remotePath, $localPath, $recursive = false)
    {
        $args = $this->sshOptions('-P');

        if ($recursive) {
            $args[] = '-r';
        }

        $args[] = $localPath;
        $args[] = $this->getUser() . '@' . $this->getSshIp() . ':' . $remotePath;

        $this->runScp('put', $args);
    }

    /**
     * 從遠端下載檔案（或目錄，$recursive = true 時）到本機
     *
     * @param string $remotePath
     * @param string $localPath
     * @param bool $recursive
     *
     * @return void
     */
    public function get($remotePath, $localPath, $recursive = false)
    {
        $args = $this->sshOptions('-P');

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
     *
     * @return \Wilkques\Ssh\Exceptions\ScpException
     */
    protected function credentialException($message)
    {
        return new ScpException($message);
    }
}
