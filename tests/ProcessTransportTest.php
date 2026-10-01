<?php

namespace Wilkques\Ssh\Tests;

use Mockery;
use Wilkques\Ssh\Exceptions\SshException;
use Wilkques\Ssh\Support\ProcessTransport;
use Wilkques\Ssh\Tests\Support\BrokenPipeStream;
use Wilkques\Ssh\Tests\Support\ChunkedStream;

class ProcessTransportTest extends TestCase
{
    /** @var \Mockery\MockInterface */
    protected $runner;

    protected function additionalSetUp()
    {
        $this->runner = Mockery::spy('Wilkques\\Ssh\\Support\\ProcessRunner')->makePartial();

        // Lenient defaults so __destruct() (which always calls close()) never
        // reaches the real proc_get_status()/proc_terminate()/proc_close()
        // against a fake "process" resource that was never actually
        // proc_open()'d. Mirrors TunnelTest's identical reasoning. Individual
        // tests override these with their own ->once() expectations.
        $this->runner->shouldReceive('status')->andReturn(array('running' => false, 'pid' => null))->byDefault();
        $this->runner->shouldReceive('terminate')->andReturn(true)->byDefault();
        $this->runner->shouldReceive('close')->andReturn(0)->byDefault();
        $this->runner->shouldReceive('taskkill')->andReturnNull()->byDefault();
    }

    protected function additionalTearDown()
    {
        ChunkedStream::unregister();
        BrokenPipeStream::unregister();
    }

    /**
     * fread() on a real pipe can — and routinely does — return fewer bytes
     * than asked; read() must keep calling it until it has exactly as many
     * bytes as requested. ChunkedStream forces every single fread() call to
     * return at most 1 byte regardless of how many are requested, so this
     * only passes if the accumulation loop is actually there.
     */
    public function testReadAccumulatesAcrossShortReads()
    {
        ChunkedStream::register(1);
        ChunkedStream::seed('stdout', 'hello world');

        $stdout = fopen('chunked://stdout', 'r');
        $stdin = fopen('php://memory', 'w+');
        $process = fopen('php://memory', 'r');

        $transport = new ProcessTransport($this->runner, $process, array($stdin, $stdout), $this->tmpDir . '/stderr');

        $data = $transport->read(11);

        $this->assertSame('hello world', $data);
    }

    public function testReadThrowsWhenPeerClosesBeforeEnoughBytesArrive()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SshException');

        ChunkedStream::register(1);
        ChunkedStream::seed('stdout', 'short');

        $stdout = fopen('chunked://stdout', 'r');
        $stdin = fopen('php://memory', 'w+');
        $process = fopen('php://memory', 'r');

        $transport = new ProcessTransport($this->runner, $process, array($stdin, $stdout), $this->tmpDir . '/stderr');

        $transport->read(100);
    }

    public function testIsEofBecomesTrueAfterAFailedRead()
    {
        ChunkedStream::register(1);
        // Two bytes: one to consume successfully (proving isEof() isn't
        // just "the stream was ever empty"), one left over that's then too
        // few to satisfy a bigger read.
        ChunkedStream::seed('stdout', 'xy');

        $stdout = fopen('chunked://stdout', 'r');
        $stdin = fopen('php://memory', 'w+');
        $process = fopen('php://memory', 'r');

        $transport = new ProcessTransport($this->runner, $process, array($stdin, $stdout), $this->tmpDir . '/stderr');

        $this->assertFalse($transport->isEof());

        $transport->read(1);

        $this->assertFalse($transport->isEof());

        try {
            $transport->read(5);
            $this->fail('expected read() to throw when fewer bytes remain than requested');
        } catch (SshException $e) {
            // expected
        }

        $this->assertTrue($transport->isEof());
    }

    /**
     * A real broken pipe makes fwrite() return 0 permanently; write() must
     * recognize that as failure rather than spinning forever re-trying a
     * write that will never succeed.
     */
    public function testWriteThrowsWhenThePeerHasGoneAway()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SshException');

        BrokenPipeStream::register();

        $stdin = fopen('broken-pipe://stdin', 'w');
        $stdout = fopen('php://memory', 'r');
        $process = fopen('php://memory', 'r');

        $transport = new ProcessTransport($this->runner, $process, array($stdin, $stdout), $this->tmpDir . '/stderr');

        $transport->write('anything');
    }

    public function testErrorOutputReadsTheStderrFile()
    {
        $stderrFile = $this->tmpDir . '/stderr.log';
        file_put_contents($stderrFile, 'Permission denied (publickey).');

        $stdin = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'r');
        $process = fopen('php://memory', 'r');

        $transport = new ProcessTransport($this->runner, $process, array($stdin, $stdout), $stderrFile);

        $this->assertSame('Permission denied (publickey).', $transport->errorOutput());
    }

    public function testErrorOutputIsEmptyStringWhenNoStderrWasWritten()
    {
        $stdin = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'r');
        $process = fopen('php://memory', 'r');

        $transport = new ProcessTransport($this->runner, $process, array($stdin, $stdout), $this->tmpDir . '/never-written.log');

        $this->assertSame('', $transport->errorOutput());
    }

    /**
     * close() must mirror Tunnel::stop()'s teardown order exactly: read the
     * pid out of status() before terminating (terminate()/close() can
     * invalidate the resource), then terminate(), then close().
     */
    public function testCloseTerminatesAndClosesTheProcessAndRemovesTheStderrFile()
    {
        $stderrFile = $this->tmpDir . '/stderr.log';
        file_put_contents($stderrFile, 'leftover');

        $stdin = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'r');
        $process = fopen('php://memory', 'r');

        $this->runner->shouldReceive('status')->once()->andReturn(array('running' => true, 'pid' => 4242));
        $this->runner->shouldReceive('terminate')->once()->andReturn(true);
        $this->runner->shouldReceive('close')->once()->andReturn(0);
        $this->runner->shouldReceive('taskkill')->never();

        $transport = new ProcessTransport($this->runner, $process, array($stdin, $stdout), $stderrFile, false);

        $transport->close();

        $this->assertFalse(file_exists($stderrFile));
    }

    public function testCloseAlsoTaskkillsOnWindows()
    {
        $stderrFile = $this->tmpDir . '/stderr.log';
        file_put_contents($stderrFile, 'leftover');

        $stdin = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'r');
        $process = fopen('php://memory', 'r');

        $this->runner->shouldReceive('status')->once()->andReturn(array('running' => true, 'pid' => 4242));
        $this->runner->shouldReceive('terminate')->once()->andReturn(true);
        $this->runner->shouldReceive('close')->once()->andReturn(0);
        $this->runner->shouldReceive('taskkill')->once()->with(4242);

        $transport = new ProcessTransport($this->runner, $process, array($stdin, $stdout), $stderrFile, true);

        $transport->close();

        // The ->once() expectations above (including taskkill(4242)) are
        // verified by Mockery::close() in tearDown(); this asserts the
        // transport's own observable effect too.
        $this->assertFalse(file_exists($stderrFile));
    }

    public function testCloseIsSafeToCallMoreThanOnce()
    {
        $stderrFile = $this->tmpDir . '/stderr.log';
        file_put_contents($stderrFile, 'leftover');

        $stdin = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'r');
        $process = fopen('php://memory', 'r');

        $this->runner->shouldReceive('status')->once()->andReturn(array('running' => true, 'pid' => 4242));
        $this->runner->shouldReceive('terminate')->once()->andReturn(true);
        $this->runner->shouldReceive('close')->once()->andReturn(0);

        $transport = new ProcessTransport($this->runner, $process, array($stdin, $stdout), $stderrFile, false);

        $transport->close();
        $transport->close();

        // The ->once() expectations above are verified by Mockery::close()
        // in tearDown() — a second close() call must not re-invoke any of
        // them. This asserts the transport's own observable effect too.
        $this->assertFalse(file_exists($stderrFile));
    }
}
