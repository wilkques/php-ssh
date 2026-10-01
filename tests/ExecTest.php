<?php

namespace Wilkques\Ssh\Tests;

use Mockery;
use Wilkques\Ssh\Exceptions\ExecException;
use Wilkques\Ssh\Exec;

class ExecTest extends TestCase
{
    /** @var Exec */
    protected $exec;

    /** @var \Mockery\MockInterface */
    protected $runner;

    protected function additionalSetUp()
    {
        $this->runner = Mockery::spy('Wilkques\\Ssh\\Support\\ProcessRunner')->makePartial();

        $this->exec = new Exec();
        $this->exec->setSshIp('10.10.2.58')
            ->setUser('deploy')
            ->setProcessRunner($this->runner);
    }

    public function testExecReturnsStdoutOnSuccess()
    {
        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturn(array('exitCode' => 0, 'stdout' => "hello\n", 'stderr' => ''));

        $result = $this->exec->exec('echo hello');

        $this->assertSame("hello\n", $result);
    }

    public function testExecThrowsWithStderrOnNonZeroExit()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\ExecException');

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturn(array('exitCode' => 127, 'stdout' => '', 'stderr' => 'command not found'));

        $this->exec->exec('nope');
    }

    public function testExecWiresUpAskPassOnlyWhenPasswordIsSet()
    {
        $capturedAskPass = null;

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedAskPass) {
                $capturedAskPass = getenv('SSH_ASKPASS');

                return array('exitCode' => 0, 'stdout' => 'ok', 'stderr' => '');
            });

        $originalAskPass = getenv('SSH_ASKPASS');

        $this->exec->setPassword('secret')->exec('ls');

        $this->assertNotEmpty($capturedAskPass);
        $this->assertFalse(file_exists($capturedAskPass));
        $this->assertSame($originalAskPass, getenv('SSH_ASKPASS'));
    }

    public function testExecDoesNotWireUpAskPassWithoutAPassword()
    {
        $capturedAskPass = 'unset';

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedAskPass) {
                $capturedAskPass = getenv('SSH_ASKPASS');

                return array('exitCode' => 0, 'stdout' => 'ok', 'stderr' => '');
            });

        $this->exec->exec('ls');

        $this->assertFalse($capturedAskPass);
    }

    public function testDefaultCommandLineIncludesStrictHostKeyCheckingAndConnectTimeout()
    {
        $capturedCmd = $this->captureCommand();

        $this->assertStringContainsStringCompat("StrictHostKeyChecking=accept-new", $capturedCmd);
        $this->assertStringContainsStringCompat("ConnectTimeout=30", $capturedCmd);
    }

    public function testSetPortAddsLowercasePFlag()
    {
        $this->exec->setPort(2222);

        $capturedCmd = $this->captureCommand();

        $this->assertStringContainsStringCompat("'-p' '2222'", $capturedCmd);
        $this->assertStringNotContainsStringCompat("'-P' '2222'", $capturedCmd);
    }

    public function testSetStrictHostKeyCheckingOverridesTheDefault()
    {
        $this->exec->setStrictHostKeyChecking('yes');

        $capturedCmd = $this->captureCommand();

        $this->assertStringContainsStringCompat('StrictHostKeyChecking=yes', $capturedCmd);
        $this->assertStringNotContainsStringCompat('StrictHostKeyChecking=accept-new', $capturedCmd);
    }

    public function testSetKnownHostsFileAddsOption()
    {
        $this->exec->setKnownHostsFile('/tmp/my_known_hosts');

        $capturedCmd = $this->captureCommand();

        $this->assertStringContainsStringCompat('UserKnownHostsFile=/tmp/my_known_hosts', $capturedCmd);
    }

    public function testSetProxyJumpAddsJFlag()
    {
        $this->exec->setProxyJump('jumpuser@jumphost:2200');

        $capturedCmd = $this->captureCommand();

        $this->assertStringContainsStringCompat("'-J' 'jumpuser@jumphost:2200'", $capturedCmd);
    }

    public function testSetCompressionAddsCFlag()
    {
        $this->exec->setCompression();

        $capturedCmd = $this->captureCommand();

        $this->assertStringContainsStringCompat("'-C'", $capturedCmd);
    }

    public function testCompressionFlagOmittedByDefault()
    {
        $capturedCmd = $this->captureCommand();

        $this->assertStringNotContainsStringCompat("'-C'", $capturedCmd);
    }

    public function testSetTimeoutChangesConnectTimeout()
    {
        $this->exec->setTimeout(5);

        $capturedCmd = $this->captureCommand();

        $this->assertStringContainsStringCompat('ConnectTimeout=5', $capturedCmd);
    }

    public function testAddOptionAppendsArbitraryDashOFlag()
    {
        $this->exec->addOption('ServerAliveInterval', '60');

        $capturedCmd = $this->captureCommand();

        $this->assertStringContainsStringCompat('ServerAliveInterval=60', $capturedCmd);
    }

    public function testSetMultiplexingAddsControlMasterOptions()
    {
        $this->exec->setMultiplexing(true, '5m');

        $capturedCmd = $this->captureCommand();

        $this->assertStringContainsStringCompat('ControlMaster=auto', $capturedCmd);
        $this->assertStringContainsStringCompat('ControlPersist=5m', $capturedCmd);
        $this->assertStringContainsStringCompat('ControlPath=' . $this->exec->getControlPath(), $capturedCmd);
    }

    public function testMultiplexingOmittedByDefault()
    {
        $capturedCmd = $this->captureCommand();

        $this->assertStringNotContainsStringCompat('ControlMaster', $capturedCmd);
    }

    public function testCloseMultiplexedConnectionIsNoopWhenNeverEnabled()
    {
        $this->runner->shouldReceive('runForeground')->never();

        $this->exec->closeMultiplexedConnection();

        // The ->never() expectation above is verified by Mockery::close() in
        // tearDown(); this asserts the class's own observable state too.
        $this->assertNull($this->peek($this->exec, 'controlPath'));
    }

    public function testCloseMultiplexedConnectionRunsSshDashOExitWhenEnabled()
    {
        $this->exec->setMultiplexing(true);

        $capturedCmd = null;

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedCmd) {
                $capturedCmd = $cmd;

                return array('exitCode' => 0, 'stdout' => '', 'stderr' => '');
            });

        $this->exec->closeMultiplexedConnection();

        $this->assertStringContainsStringCompat("'-O' 'exit'", $capturedCmd);
        $this->assertStringContainsStringCompat('deploy@10.10.2.58', $capturedCmd);
    }

    /**
     * Runs exec() once, capturing and returning the built command line string.
     *
     * @return string
     */
    protected function captureCommand()
    {
        $capturedCmd = null;

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedCmd) {
                $capturedCmd = $cmd;

                return array('exitCode' => 0, 'stdout' => '', 'stderr' => '');
            });

        $this->exec->exec('true');

        return $capturedCmd;
    }

    /**
     * @param string $needle
     * @param string $haystack
     *
     * @return void
     */
    protected function assertStringContainsStringCompat($needle, $haystack)
    {
        if (method_exists($this, 'assertStringContainsString')) {
            $this->assertStringContainsString($needle, $haystack);

            return;
        }

        $this->assertContains($needle, $haystack);
    }

    /**
     * @param string $needle
     * @param string $haystack
     *
     * @return void
     */
    protected function assertStringNotContainsStringCompat($needle, $haystack)
    {
        if (method_exists($this, 'assertStringNotContainsString')) {
            $this->assertStringNotContainsString($needle, $haystack);

            return;
        }

        $this->assertNotContains($needle, $haystack);
    }
}
