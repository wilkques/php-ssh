<?php

namespace Wilkques\Ssh\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Wilkques\Ssh\Exec;
use Wilkques\Ssh\Tests\TestCase;

/**
 * Runs a real `ssh` binary against a real sshd. Only meaningful with a
 * reachable test sshd (see the dedicated `integration-ssh` CI job, which
 * stands one up on localhost with a throwaway keypair) — skipped otherwise.
 *
 * @group integration
 *
 * The #[Group] attribute below duplicates the @group docblock tag above:
 * PHPUnit 10+ stopped reading docblock @group tags for --group filtering
 * (verified directly — `--list-groups` shows only "default" without this),
 * but this package's PHP 5.3 floor runs under PHPUnit 4.8.x, which only
 * understands the docblock form and has no idea what a PHP 8 attribute is.
 * `#[...]` is backward-compatible as a harmless `#`-comment on PHP < 8, so
 * both can coexist — confirmed directly on real PHP 5.6/7.4/8.3 builds.
 */
#[Group('integration')]
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
