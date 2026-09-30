<?php

namespace Wilkques\Ssh\Tests;

use Mockery;
use Wilkques\Ssh\Exceptions\TunnelException;
use Wilkques\Ssh\Tunnel;

class TunnelTest extends TestCase
{
    /** @var Tunnel */
    protected $tunnel;

    /** @var \Mockery\MockInterface */
    protected $runner;

    protected function additionalSetUp()
    {
        $this->runner = Mockery::spy('Wilkques\\Ssh\\Support\\ProcessRunner')->makePartial();

        // Lenient defaults so a test's fake "process" resource never reaches
        // the real proc_terminate()/proc_close() if stop() also fires later
        // from __destruct() — individual tests can still override these with
        // their own ->once() expectations.
        $this->runner->shouldReceive('terminate')->andReturn(true)->byDefault();
        $this->runner->shouldReceive('close')->andReturn(0)->byDefault();
        $this->runner->shouldReceive('taskkill')->andReturnNull()->byDefault();

        $this->tunnel = new Tunnel();
        $this->tunnel->setSshIp('10.10.2.58')
            ->setUser('deploy')
            ->setProcessRunner($this->runner);
    }

    public function testStartSucceedsWhenPortBecomesReady()
    {
        $process = fopen('php://memory', 'r');

        $this->runner->shouldReceive('openBackground')
            ->once()
            ->andReturn($process);

        $this->runner->shouldReceive('status')
            ->andReturn(array('running' => true, 'pid' => 4242));

        $this->runner->shouldReceive('connect')
            ->once()
            ->andReturn(true);

        $result = $this->tunnel->start(33061, '10.10.2.105', 3306, 5);

        $this->assertSame($this->tunnel, $result);
    }

    public function testStartThrowsWhenProcessFailsToLaunch()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\TunnelException');

        $this->runner->shouldReceive('openBackground')->once()->andReturn(false);

        $this->tunnel->start(33061, '10.10.2.105', 3306, 5);
    }

    public function testStartThrowsWhenProcessExitsEarly()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\TunnelException');

        $process = fopen('php://memory', 'r');

        $this->runner->shouldReceive('openBackground')->once()->andReturn($process);
        $this->runner->shouldReceive('status')->andReturn(array('running' => false, 'pid' => 4242));
        $this->runner->shouldReceive('terminate')->andReturn(true);
        $this->runner->shouldReceive('close')->andReturn(0);

        $this->tunnel->start(33061, '10.10.2.105', 3306, 5);
    }

    public function testStartThrowsOnTimeoutAndStopsTheProcess()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\TunnelException');

        $process = fopen('php://memory', 'r');

        $this->runner->shouldReceive('openBackground')->once()->andReturn($process);
        $this->runner->shouldReceive('status')->andReturn(array('running' => true, 'pid' => 4242));
        $this->runner->shouldReceive('connect')->andReturn(false);
        $this->runner->shouldReceive('terminate')->once()->andReturn(true);
        $this->runner->shouldReceive('close')->once()->andReturn(0);

        $this->tunnel->start(33061, '10.10.2.105', 3306, 1);
    }

    public function testStopTerminatesAndClosesTheProcess()
    {
        $process = fopen('php://memory', 'r');

        $this->runner->shouldReceive('openBackground')->once()->andReturn($process);
        $this->runner->shouldReceive('status')->andReturn(array('running' => true, 'pid' => 4242));
        $this->runner->shouldReceive('connect')->once()->andReturn(true);

        $this->tunnel->start(33061, '10.10.2.105', 3306, 5);

        $this->runner->shouldReceive('terminate')->once()->andReturn(true);
        $this->runner->shouldReceive('close')->once()->andReturn(0);

        $this->tunnel->stop();

        // The ->once() expectations above are verified by Mockery::close()
        // in tearDown(); this asserts the class's own observable state too.
        $this->assertNull($this->peek($this->tunnel, 'process'));
    }

    public function testStopAlsoTaskkillsOnWindows()
    {
        $tunnel = Mockery::mock('Wilkques\\Ssh\\Tunnel')->makePartial();
        $tunnel->shouldAllowMockingProtectedMethods();
        $tunnel->shouldReceive('isWindows')->andReturn(true);
        $tunnel->setSshIp('10.10.2.58')->setUser('deploy')->setProcessRunner($this->runner);

        $process = fopen('php://memory', 'r');

        $this->runner->shouldReceive('openBackground')->once()->andReturn($process);
        $this->runner->shouldReceive('status')->andReturn(array('running' => true, 'pid' => 4242));
        $this->runner->shouldReceive('connect')->once()->andReturn(true);

        $tunnel->start(33061, '10.10.2.105', 3306, 5);

        $this->runner->shouldReceive('terminate')->once()->andReturn(true);
        $this->runner->shouldReceive('close')->once()->andReturn(0);
        $this->runner->shouldReceive('taskkill')->once()->with(4242);

        $tunnel->stop();

        // The ->once() expectations above (including taskkill(4242)) are
        // verified by Mockery::close() in tearDown().
        $this->assertNull($this->peek($tunnel, 'process'));
    }
}
