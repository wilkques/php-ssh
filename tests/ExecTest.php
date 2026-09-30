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
}
