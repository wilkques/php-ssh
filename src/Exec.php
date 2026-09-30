<?php

namespace Wilkques\Ssh;

use Wilkques\Ssh\Exceptions\ExecException;
use Wilkques\Ssh\Support\AbstractSshProcess;

/**
 * Runs a single command on a remote host over ssh. Method name matches
 * phpseclib's SSH2::exec() for a familiar API, but the implementation
 * shells out to the system ssh binary — no phpseclib dependency at all.
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
        $args = $this->sshOptions();
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
     *
     * @return \Wilkques\Ssh\Exceptions\ExecException
     */
    protected function credentialException($message)
    {
        return new ExecException($message);
    }
}
