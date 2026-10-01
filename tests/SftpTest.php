<?php

namespace Wilkques\Ssh\Tests;

use Mockery;
use Wilkques\Ssh\Exceptions\SftpException;
use Wilkques\Ssh\Sftp;

class SftpTest extends TestCase
{
    /** @var Sftp */
    protected $sftp;

    /** @var \Mockery\MockInterface */
    protected $runner;

    protected function additionalSetUp()
    {
        $this->runner = Mockery::spy('Wilkques\\Ssh\\Support\\ProcessRunner')->makePartial();

        $this->sftp = new Sftp();
        $this->sftp->setSshIp('10.10.2.58')
            ->setUser('deploy')
            ->setProcessRunner($this->runner);
    }

    public function testPutBuildsAPutBatchCommandAndCleansUpTheBatchFile()
    {
        // PHP 5.3 closures don't auto-bind $this, so these deliberately only
        // capture plain values via `use` and call built-in functions —
        // no $this->assert*()/$this->method() calls inside the closure.
        $capturedCmd = null;
        $capturedBatchFile = null;
        $capturedBatchContents = null;

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedCmd, &$capturedBatchFile, &$capturedBatchContents) {
                $capturedCmd = $cmd;

                if (preg_match("/'-b'\\s+'([^']+)'/", $cmd, $matches)) {
                    $capturedBatchFile = $matches[1];
                    $capturedBatchContents = file_get_contents($capturedBatchFile);
                }

                return array('exitCode' => 0, 'stdout' => '', 'stderr' => '');
            });

        $this->sftp->put('/remote/path/file.log', '/local/path/file.log');

        $this->assertStringContainsStringCompat('sftp', $capturedCmd);
        $this->assertNotNull($capturedBatchContents, 'expected a -b <batchfile> flag in: ' . $capturedCmd);
        $this->assertStringContainsStringCompat('put ', $capturedBatchContents);
        $this->assertFalse(file_exists($capturedBatchFile));
    }

    public function testGetBuildsAGetBatchCommand()
    {
        $capturedBatchContents = null;

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedBatchContents) {
                if (preg_match("/'-b'\\s+'([^']+)'/", $cmd, $matches)) {
                    $capturedBatchContents = file_get_contents($matches[1]);
                }

                return array('exitCode' => 0, 'stdout' => '', 'stderr' => '');
            });

        $this->sftp->get('/remote/path/file.log', '/local/path/file.log');

        $this->assertNotNull($capturedBatchContents);
        $this->assertStringContainsStringCompat('get ', $capturedBatchContents);
    }

    public function testNlistParsesLinesAndSkipsPromptEchoes()
    {
        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturn(array(
                'exitCode' => 0,
                'stdout' => "sftp> ls -1 /var/log\r\naccess.log\r\nerror.log\r\n\r\n",
                'stderr' => '',
            ));

        $result = $this->sftp->nlist('/var/log');

        $this->assertSame(array('access.log', 'error.log'), $result);
    }

    public function testThrowsSftpExceptionWithStderrOnNonZeroExit()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturn(array('exitCode' => 1, 'stdout' => '', 'stderr' => 'Permission denied'));

        $this->sftp->get('/remote/forbidden', '/local/forbidden');
    }

    public function testDeleteBuildsARmBatchCommand()
    {
        $contents = null;
        $this->captureBatchContents($contents);

        $this->sftp->delete('/remote/path/file.log');

        $this->assertStringContainsStringCompat('rm ', $contents);
    }

    public function testMkdirBuildsAMkdirBatchCommand()
    {
        $contents = null;
        $this->captureBatchContents($contents);

        $this->sftp->mkdir('/remote/path/newdir');

        $this->assertStringContainsStringCompat('mkdir ', $contents);
    }

    public function testRmdirBuildsARmdirBatchCommand()
    {
        $contents = null;
        $this->captureBatchContents($contents);

        $this->sftp->rmdir('/remote/path/olddir');

        $this->assertStringContainsStringCompat('rmdir ', $contents);
    }

    public function testRenameBuildsARenameBatchCommandWithBothPaths()
    {
        $contents = null;
        $this->captureBatchContents($contents);

        $this->sftp->rename('/remote/old.log', '/remote/new.log');

        $this->assertStringContainsStringCompat('rename ', $contents);
        $this->assertStringContainsStringCompat('/remote/old.log', $contents);
        $this->assertStringContainsStringCompat('/remote/new.log', $contents);
    }

    public function testChmodAcceptsOctalIntLiteral()
    {
        $contents = null;
        $this->captureBatchContents($contents);

        $this->sftp->chmod('/remote/path/file.log', 0644);

        $this->assertStringContainsStringCompat('chmod 644 ', $contents);
    }

    public function testChmodAcceptsStringMode()
    {
        $contents = null;
        $this->captureBatchContents($contents);

        $this->sftp->chmod('/remote/path/file.log', '755');

        $this->assertStringContainsStringCompat('chmod 755 ', $contents);
    }

    public function testExistsReturnsTrueWhenLsSucceeds()
    {
        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturn(array('exitCode' => 0, 'stdout' => '', 'stderr' => ''));

        $this->assertTrue($this->sftp->exists('/remote/path/file.log'));
    }

    public function testExistsReturnsFalseWhenLsFails()
    {
        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturn(array('exitCode' => 1, 'stdout' => '', 'stderr' => 'No such file'));

        $this->assertFalse($this->sftp->exists('/remote/path/missing.log'));
    }

    /**
     * Stubs runForeground() once so that, once the sftp call under test runs,
     * $capturedContents (passed by reference) holds the batch file's content.
     *
     * @param string|null $capturedContents
     *
     * @return void
     */
    protected function captureBatchContents(&$capturedContents)
    {
        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedContents) {
                if (preg_match("/'-b'\\s+'([^']+)'/", $cmd, $matches)) {
                    $capturedContents = file_get_contents($matches[1]);
                }

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
}
