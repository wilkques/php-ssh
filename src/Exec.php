<?php

namespace Wilkques\Ssh;

use Wilkques\Ssh\Exceptions\ExecException;
use Wilkques\Ssh\Support\AbstractSshProcess;

/**
 * Runs a single command on a remote host over ssh.
 */
class Exec extends AbstractSshProcess
{
    /**
     * 在遠端主機執行一個指令，回傳 stdout；非 0 結束碼會丟例外
     *
     * @param string $commandLine
     *
     * @return string
     */
    public function exec($commandLine)
    {
        $args = $this->sshOptions('-p');
        $args[] = $this->getUser() . '@' . $this->getSshIp();
        $args[] = $commandLine;

        $result = $this->runForeground('ssh', $args);

        if ($result['exitCode'] !== 0) {
            throw new ExecException(sprintf(
                'Remote command exited with status %d: %s',
                $result['exitCode'],
                trim($result['stderr'])
            ));
        }

        return $result['stdout'];
    }

    /**
     * @param string $message
     * @param int $code
     *
     * @return \Wilkques\Ssh\Exceptions\ExecException
     */
    public function credentialException($message, $code = 0)
    {
        return new ExecException($message, $code);
    }
}
