<?php

namespace Wilkques\Ssh\Tests\Integration;

use Wilkques\Ssh\Exec;
use Wilkques\Ssh\Tests\TestCase;

/**
 * Runs a real `ssh` binary against a real sshd. Only meaningful with a
 * reachable test sshd (see the dedicated `integration-ssh` CI job, which
 * stands one up on localhost with a throwaway keypair) — skipped otherwise.
 *
 * @group integration
 */
class ExecIntegrationTest extends TestCase
{
    /** @var Exec */
    protected $exec;

    protected function additionalSetUp()
    {
        if (getenv('SSH_EXEC_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set SSH_EXEC_INTEGRATION=1 (and SSH_INTEGRATION_HOST/USER/KEY) to run this test.');
        }

        $host = getenv('SSH_INTEGRATION_HOST');
        $user = getenv('SSH_INTEGRATION_USER');
        $key = getenv('SSH_INTEGRATION_KEY');

        if (!$host || !$user || !$key) {
            $this->markTestSkipped('SSH_INTEGRATION_HOST/USER/KEY must all be set to run this test.');
        }

        $this->exec = new Exec();
        $this->exec->setSshIp($host)
            ->setUser($user)
            ->setIdRsaPath($key);
    }

    public function testExecRunsARealRemoteCommand()
    {
        $result = $this->exec->exec('echo integration-ok');

        $this->assertSame("integration-ok\n", $result);
    }
}
