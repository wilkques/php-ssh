<?php

namespace Wilkques\Ssh\Tests;

use Mockery;
use Wilkques\Ssh\Exceptions\ScpException;
use Wilkques\Ssh\Scp;
use Wilkques\Ssh\Support\SftpChannel;
use Wilkques\Ssh\Support\SftpPacket;
use Wilkques\Ssh\Tests\Support\FakeTransport;

class ScpTest extends TestCase
{
    /** @var Scp */
    protected $scp;

    /** @var \Mockery\MockInterface */
    protected $runner;

    /** @var FakeTransport */
    protected $transport;

    /** @var int */
    protected $requestCursor;

    protected function additionalSetUp()
    {
        $this->runner = Mockery::spy('Wilkques\\Ssh\\Support\\ProcessRunner')->makePartial();

        $this->scp = new Scp();
        $this->scp->setSshIp('10.10.2.58')
            ->setUser('deploy')
            ->setProcessRunner($this->runner);

        $this->useChannelWithExtensions(array());
    }

    /**
     * Wires $this->scp's channel-mode path to a fresh FakeTransport. Legacy
     * tests don't need this at all (they drive $this->runner directly) but
     * it's cheap to always set up since Scp only touches the channel on
     * the methods under test.
     *
     * @param array<string,string> $extensions
     *
     * @return void
     */
    protected function useChannelWithExtensions(array $extensions)
    {
        $this->transport = new FakeTransport();
        $this->transport->queueVersion(3, $extensions);

        $channel = new SftpChannel($this->transport, array($this->scp, 'credentialException'));
        $channel->connect();

        $this->scp->setChannel($channel);

        $this->requestCursor = strlen($this->transport->sentBytes());
    }

    /**
     * @return array array(int $type, int $id, string $payload)
     */
    protected function nextRequest()
    {
        $bytes = $this->transport->sentBytes();

        $length = SftpPacket::bytesToUint32(substr($bytes, $this->requestCursor, 4));
        $this->requestCursor += 4;

        $frameBody = substr($bytes, $this->requestCursor, $length);
        $this->requestCursor += $length;

        return SftpPacket::decodeResponse($frameBody);
    }

    /**
     * @param int $expectedType
     *
     * @return string the request's payload
     */
    protected function assertNextRequestIs($expectedType)
    {
        list($type, $id, $payload) = $this->nextRequest();

        $this->assertSame($expectedType, $type);

        return $payload;
    }

    // --- Legacy mode (setLegacy(true)): shells out to `scp -O ...`, exactly
    // --- the CLI path this class used to always run. ---

    public function testLegacyPutUploadsTheLocalFileToTheRemotePath()
    {
        $this->scp->setLegacy(true);

        $capturedCmd = null;

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedCmd) {
                $capturedCmd = $cmd;

                return array('exitCode' => 0, 'stdout' => '', 'stderr' => '');
            });

        $this->scp->put('/remote/path/file.log', '/local/path/file.log');

        $this->assertStringContainsStringCompat('scp', $capturedCmd);
        $this->assertStringContainsStringCompat($this->quotedArg('-O'), $capturedCmd);
        $this->assertStringContainsStringCompat('/local/path/file.log', $capturedCmd);
        $this->assertStringContainsStringCompat('deploy@10.10.2.58:/remote/path/file.log', $capturedCmd);
    }

    public function testLegacyGetDownloadsTheRemoteFileToTheLocalPath()
    {
        $this->scp->setLegacy(true);

        $capturedCmd = null;

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturnUsing(function ($cmd) use (&$capturedCmd) {
                $capturedCmd = $cmd;

                return array('exitCode' => 0, 'stdout' => '', 'stderr' => '');
            });

        $this->scp->get('/remote/path/file.log', '/local/path/file.log');

        $this->assertStringContainsStringCompat($this->quotedArg('-O'), $capturedCmd);
        $this->assertStringContainsStringCompat('deploy@10.10.2.58:/remote/path/file.log', $capturedCmd);
        $this->assertStringContainsStringCompat('/local/path/file.log', $capturedCmd);
    }

    public function testLegacyThrowsScpExceptionWithStderrOnNonZeroExit()
    {
        $this->scp->setLegacy(true);

        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\ScpException');

        $this->runner->shouldReceive('runForeground')
            ->once()
            ->andReturn(array('exitCode' => 1, 'stdout' => '', 'stderr' => 'Permission denied'));

        $this->scp->get('/remote/forbidden', '/local/forbidden');
    }

    public function testLegacyPutWithoutRecursiveOmitsDashR()
    {
        $this->scp->setLegacy(true);

        $capturedCmd = null;
        $this->captureCommand($capturedCmd);

        $this->scp->put('/remote/path/dir', '/local/path/dir');

        $this->assertStringNotContainsStringCompat($this->quotedArg('-r'), $capturedCmd);
    }

    public function testLegacyPutWithRecursiveAddsDashR()
    {
        $this->scp->setLegacy(true);

        $capturedCmd = null;
        $this->captureCommand($capturedCmd);

        $this->scp->put('/remote/path/dir', '/local/path/dir', true);

        $this->assertStringContainsStringCompat($this->quotedArg('-r'), $capturedCmd);
    }

    public function testLegacyGetWithRecursiveAddsDashR()
    {
        $this->scp->setLegacy(true);

        $capturedCmd = null;
        $this->captureCommand($capturedCmd);

        $this->scp->get('/remote/path/dir', '/local/path/dir', true);

        $this->assertStringContainsStringCompat($this->quotedArg('-r'), $capturedCmd);
    }

    public function testLegacyPortUsesUppercasePFlag()
    {
        $this->scp->setLegacy(true);
        $this->scp->setPort(2222);

        $capturedCmd = null;
        $this->captureCommand($capturedCmd);

        $this->scp->put('/remote/path/file.log', '/local/path/file.log');

        $this->assertStringContainsStringCompat($this->quotedArg('-P') . ' ' . $this->quotedArg('2222'), $capturedCmd);
    }

    public function testSetProgressWithLegacyThrows()
    {
        $this->scp->setLegacy(true);
        $this->scp->setProgress(function () {
        });

        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\ScpException');

        $this->scp->put('/remote/path/file.log', '/local/path/file.log');
    }

    // --- Channel mode (the default): same SftpChannel as Sftp. ---

    public function testPutUploadsFileViaChannel()
    {
        $localFile = $this->tmpDir . '/upload.txt';
        file_put_contents($localFile, 'hello');

        // STAT on the target (checking for the directory-target rule) ->
        // NO_SUCH_FILE, so the path is used literally.
        $this->transport->queueStatus(1, SftpPacket::STATUS_NO_SUCH_FILE);
        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 2, SftpPacket::packString('h1'));
        $this->transport->queueStatus(3, SftpPacket::STATUS_OK);
        $this->transport->queueStatus(4, SftpPacket::STATUS_OK);

        $this->scp->put('/remote/path/upload.txt', $localFile);

        $this->assertNextRequestIs(SftpPacket::TYPE_STAT);
        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_OPEN);
        $offset = 0;
        $this->assertSame('/remote/path/upload.txt', SftpPacket::unpackString($payload, $offset));
        $this->assertNextRequestIs(SftpPacket::TYPE_WRITE);
        $this->assertNextRequestIs(SftpPacket::TYPE_CLOSE);
    }

    /**
     * The one behavior that actually distinguishes Scp from Sftp: an
     * existing directory target means "copy into it", matching what the
     * real scp binary does.
     */
    public function testPutAppendsBasenameWhenRemoteTargetIsADirectory()
    {
        $localFile = $this->tmpDir . '/upload.txt';
        file_put_contents($localFile, 'hello');

        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 1, SftpPacket::encodeAttrs(array('permissions' => Scp::S_IFDIR | 0755)));
        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 2, SftpPacket::packString('h1'));
        $this->transport->queueStatus(3, SftpPacket::STATUS_OK);
        $this->transport->queueStatus(4, SftpPacket::STATUS_OK);

        $this->scp->put('/remote/existing-dir', $localFile);

        $this->assertNextRequestIs(SftpPacket::TYPE_STAT);
        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_OPEN);
        $offset = 0;
        $this->assertSame('/remote/existing-dir/upload.txt', SftpPacket::unpackString($payload, $offset));
    }

    public function testPutRethrowsWhenTargetStatFailsForAReasonOtherThanMissing()
    {
        $localFile = $this->tmpDir . '/upload.txt';
        file_put_contents($localFile, 'hello');

        $this->transport->queueStatus(1, SftpPacket::STATUS_PERMISSION_DENIED, 'Permission denied');

        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\ScpException');

        $this->scp->put('/remote/forbidden-dir', $localFile);
    }

    public function testPutThrowsWhenLocalPathIsADirectoryWithoutRecursive()
    {
        $localDir = $this->tmpDir . '/upload-dir';
        mkdir($localDir);

        $this->transport->queueStatus(1, SftpPacket::STATUS_NO_SUCH_FILE);

        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\ScpException');

        $this->scp->put('/remote/upload-dir', $localDir);
    }

    public function testPutRecursiveUploadsDirectoryTree()
    {
        $localDir = $this->tmpDir . '/tree';
        mkdir($localDir, 0777, true);
        file_put_contents($localDir . '/file.txt', 'hi');

        $this->transport->queueStatus(1, SftpPacket::STATUS_NO_SUCH_FILE);    // target STAT -> not a dir yet
        $this->transport->queueStatus(2, SftpPacket::STATUS_NO_SUCH_FILE);    // exists('/remote/tree') -> LSTAT
        $this->transport->queueStatus(3, SftpPacket::STATUS_OK);              // mkdir('/remote/tree')
        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 4, SftpPacket::packString('h1'));
        $this->transport->queueStatus(5, SftpPacket::STATUS_OK);
        $this->transport->queueStatus(6, SftpPacket::STATUS_OK);

        $this->scp->put('/remote/tree', $localDir, true);

        $this->assertNextRequestIs(SftpPacket::TYPE_STAT);
        $this->assertNextRequestIs(SftpPacket::TYPE_LSTAT);
        $this->assertNextRequestIs(SftpPacket::TYPE_MKDIR);
        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_OPEN);
        $offset = 0;
        $this->assertSame('/remote/tree/file.txt', SftpPacket::unpackString($payload, $offset));
    }

    public function testGetDownloadsFileViaChannel()
    {
        $localFile = $this->tmpDir . '/downloaded.txt';

        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 1, SftpPacket::encodeAttrs(array('permissions' => 0100644)));
        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 2, SftpPacket::packString('h1'));
        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 3, SftpPacket::encodeAttrs(array('size' => 5)));
        $this->transport->queueResponse(SftpPacket::TYPE_DATA, 4, SftpPacket::packString('hello'));
        $this->transport->queueStatus(5, SftpPacket::STATUS_OK);

        $this->scp->get('/remote/path/file.log', $localFile);

        $this->assertSame('hello', file_get_contents($localFile));
        $this->assertNextRequestIs(SftpPacket::TYPE_STAT);
        $this->assertNextRequestIs(SftpPacket::TYPE_OPEN);
    }

    public function testGetAppendsBasenameWhenLocalTargetIsADirectory()
    {
        $localDir = $this->tmpDir . '/download-dir';
        mkdir($localDir);

        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 1, SftpPacket::encodeAttrs(array('permissions' => 0100644)));
        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 2, SftpPacket::packString('h1'));
        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 3, SftpPacket::encodeAttrs(array('size' => 5)));
        $this->transport->queueResponse(SftpPacket::TYPE_DATA, 4, SftpPacket::packString('hello'));
        $this->transport->queueStatus(5, SftpPacket::STATUS_OK);

        $this->scp->get('/remote/path/file.log', $localDir);

        $this->assertSame('hello', file_get_contents($localDir . DIRECTORY_SEPARATOR . 'file.log'));
    }

    public function testGetThrowsWhenRemoteIsADirectoryWithoutRecursive()
    {
        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 1, SftpPacket::encodeAttrs(array('permissions' => Scp::S_IFDIR | 0755)));

        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\ScpException');

        $this->scp->get('/remote/a-directory', $this->tmpDir . '/local.txt');
    }

    public function testGetRecursiveDownloadsDirectoryTree()
    {
        $localDir = $this->tmpDir . '/download-tree';

        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 1, SftpPacket::encodeAttrs(array('permissions' => Scp::S_IFDIR | 0755)));

        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 2, SftpPacket::packString('d1'));
        $entries = SftpPacket::uint32ToBytes(1)
            . SftpPacket::packString('file.txt') . SftpPacket::packString('file.txt') . SftpPacket::uint32ToBytes(0);
        $this->transport->queueResponse(SftpPacket::TYPE_NAME, 3, $entries);
        $this->transport->queueStatus(4, SftpPacket::STATUS_EOF);
        $this->transport->queueStatus(5, SftpPacket::STATUS_OK);

        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 6, SftpPacket::encodeAttrs(array('permissions' => 0100644, 'size' => 2)));
        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 7, SftpPacket::packString('h1'));
        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 8, SftpPacket::encodeAttrs(array('size' => 2)));
        $this->transport->queueResponse(SftpPacket::TYPE_DATA, 9, SftpPacket::packString('hi'));
        $this->transport->queueStatus(10, SftpPacket::STATUS_OK);

        $this->scp->get('/remote/tree', $localDir, true);

        $this->assertSame('hi', file_get_contents($localDir . DIRECTORY_SEPARATOR . 'file.txt'));
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
     * escapeshellarg() quotes with '...' on Unix and "..." on Windows —
     * hardcoding either one makes a test fail on the other platform.
     * Building the expected fragment through the same function
     * buildCommandLine() itself uses keeps the assertion correct on both.
     *
     * @param string $value
     *
     * @return string
     */
    protected function quotedArg($value)
    {
        return escapeshellarg($value);
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
