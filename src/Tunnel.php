<?php

namespace Wilkques\Ssh;

use Wilkques\Ssh\Exceptions\TunnelException;
use Wilkques\Ssh\Support\AbstractSshProcess;

/**
 * SSH local port forward (ssh -L), by shelling out to the system ssh binary.
 */
class Tunnel extends AbstractSshProcess
{
    /** @var resource|null */
    protected $process;

    /** @var int|null */
    protected $localPort;

    /**
     * 建立 local port forward：本機 127.0.0.1:$localPort -> 經由 sshIp 轉發到 $remoteHost:$remotePort
     * 用完務必呼叫 stop()，讓連線來源在遠端看起來是 sshIp（跳板機），而不是本機真實 IP
     *
     * @param int $localPort
     * @param string $remoteHost
     * @param int $remotePort
     * @param int $timeoutSeconds
     *
     * @return static
     */
    public function start($localPort, $remoteHost, $remotePort, $timeoutSeconds = 30)
    {
        $this->localPort = $localPort;

        $args = array('-N', '-o', 'ExitOnForwardFailure=yes', '-L', $localPort . ':' . $remoteHost . ':' . $remotePort);

        $args = array_merge($args, $this->sshOptions('-p'));

        $args[] = $this->getUser() . '@' . $this->getSshIp();

        $cmd = $this->buildCommandLine('ssh', $args);

        // 沒有金鑰/密碼時 ssh 會自己跳密碼提示，直接沿用當前終端機的 stdio 即可輸入
        $descriptorspec = array(
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        );

        $script = $this->beginAskPass();

        $pipes = null;
        $process = $this->getProcessRunner()->openBackground($cmd, $descriptorspec, $pipes);

        $this->endAskPass($script);

        if (!is_resource($process)) {
            throw new TunnelException('Failed to start the SSH tunnel: unable to launch the ssh process.');
        }

        $this->process = $process;

        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $status = $this->getProcessRunner()->status($this->process);

            if (!$status['running']) {
                throw new TunnelException('Failed to start the SSH tunnel: the ssh process exited early (authentication failure or the jump host refused the connection).');
            }

            if ($this->getProcessRunner()->connect('127.0.0.1', $localPort, 1)) {
                return $this;
            }

            usleep(300000);
        }

        $this->stop();

        throw new TunnelException(sprintf(
            'SSH tunnel establishment timed out after %d second(s); verify the credentials and network connectivity.',
            $timeoutSeconds
        ));
    }

    /**
     * 關閉 tunnel，釋放本機轉發的 port
     *
     * @return static
     */
    public function stop()
    {
        if (is_resource($this->process)) {
            $status = $this->getProcessRunner()->status($this->process);
            $pid = isset($status['pid']) ? $status['pid'] : null;

            $this->getProcessRunner()->terminate($this->process);
            $this->getProcessRunner()->close($this->process);

            // 保險起見：Windows 上直接用 pid 補一刀，避免 proc_terminate 沒生效導致
            // ssh.exe 變孤兒程序占著 port（bypass_shell 已經讓這個 pid 是 ssh.exe 本身）
            if ($pid && $this->isWindows()) {
                $this->getProcessRunner()->taskkill($pid);
            }

            $this->process = null;
        }

        return $this;
    }

    /**
     * @param string $message
     * @param int $code
     *
     * @return \Wilkques\Ssh\Exceptions\TunnelException
     */
    public function credentialException($message, $code = 0)
    {
        return new TunnelException($message, $code);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
