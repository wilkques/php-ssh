<?php

namespace Wilkques\Ssh\Tests;

use Mockery;
use Wilkques\Ssh\Exceptions\ScpException;
use Wilkques\Ssh\Scp;

class ScpTest extends TestCase
{
    /** @var Scp */
    protected $scp;

    /** @var \Mockery\MockInterface */
    protected $runner;

    protected function additionalSetUp()
    {
        $this->runner = Mockery::spy('Wilkques\\Ssh\\Support\\ProcessRunner')->makePartial();

        $this->scp = new Scp();
        $this->scp->setSshIp('10.10.2.58')
            ->setUser('deploy')
            ->setProcessRunner($this->runner);
    }

    public function testPutUploadsTheLocalFileToTheRemotePath()
    {
        $capturedCmd = null;

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedCmd) {
                $capturedCmd = $cmd;

                return array('exitCode' => 0, 'stdout' => '', 'stderr' => '');
            });

        $this->scp->put('/remote/path/file.log', '/local/path/file.log');

        $this->assertStringContainsStringCompat('scp', $capturedCmd);
        $this->assertStringContainsStringCompat('/local/path/file.log', $capturedCmd);
        $this->assertStringContainsStringCompat('deploy@10.10.2.58:/remote/path/file.log', $capturedCmd);
    }

    public function testGetDownloadsTheRemoteFileToTheLocalPath()
    {
        $capturedCmd = null;

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedCmd) {
                $capturedCmd = $cmd;

                return array('exitCode' => 0, 'stdout' => '', 'stderr' => '');
            });

        $this->scp->get('/remote/path/file.log', '/local/path/file.log');

        $this->assertStringContainsStringCompat('deploy@10.10.2.58:/remote/path/file.log', $capturedCmd);
        $this->assertStringContainsStringCompat('/local/path/file.log', $capturedCmd);
    }

    public function testThrowsScpExceptionWithStderrOnNonZeroExit()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\ScpException');

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturn(array('exitCode' => 1, 'stdout' => '', 'stderr' => 'Permission denied'));

        $this->scp->get('/remote/forbidden', '/local/forbidden');
    }

    public function testPutWithoutRecursiveOmitsDashR()
    {
        $capturedCmd = null;
        $this->captureCommand($capturedCmd);

        $this->scp->put('/remote/path/dir', '/local/path/dir');

        $this->assertStringNotContainsStringCompat("'-r'", $capturedCmd);
    }

    public function testPutWithRecursiveAddsDashR()
    {
        $capturedCmd = null;
        $this->captureCommand($capturedCmd);

        $this->scp->put('/remote/path/dir', '/local/path/dir', true);

        $this->assertStringContainsStringCompat("'-r'", $capturedCmd);
    }

    public function testGetWithRecursiveAddsDashR()
    {
        $capturedCmd = null;
        $this->captureCommand($capturedCmd);

        $this->scp->get('/remote/path/dir', '/local/path/dir', true);

        $this->assertStringContainsStringCompat("'-r'", $capturedCmd);
    }

    public function testPortUsesUppercasePFlag()
    {
        $this->scp->setPort(2222);

        $capturedCmd = null;
        $this->captureCommand($capturedCmd);

        $this->scp->put('/remote/path/file.log', '/local/path/file.log');

        $this->assertStringContainsStringCompat("'-P' '2222'", $capturedCmd);
    }

    /**
     * Stubs runForeground() once so that, once the scp call under test runs,
     * $capturedCmd (passed by reference) holds the built command line.
     *
     * @param string|null $capturedCmd
     *
     * @return void
     */
    protected function captureCommand(&$capturedCmd)
    {
        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedCmd) {
                $capturedCmd = $cmd;

                return array('exitCode' => 0, 'stdout' => '', 'stderr' => '');
            });
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
