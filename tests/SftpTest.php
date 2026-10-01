<?php

namespace Wilkques\Ssh\Tests;

use Wilkques\Ssh\Exceptions\SftpException;
use Wilkques\Ssh\Sftp;
use Wilkques\Ssh\Support\SftpChannel;
use Wilkques\Ssh\Support\SftpPacket;
use Wilkques\Ssh\Tests\Support\FakeTransport;

class SftpTest extends TestCase
{
    /** @var Sftp */
    protected $sftp;

    /** @var FakeTransport */
    protected $transport;

    /**
     * Byte offset into $transport->sentBytes() where the next
     * application request starts — i.e. just past the SSH_FXP_INIT frame
     * connect() sends during setup, which carries no request id and isn't
     * part of what any individual test is asserting on.
     *
     * @var int
     */
    protected $requestCursor;

    protected function additionalSetUp()
    {
        $this->sftp = new Sftp();
        $this->sftp->setSshIp('10.10.2.58')->setUser('deploy');

        $this->useChannelWithExtensions(array());
    }

    /**
     * (Re)builds the fake transport/channel pair backing $this->sftp, with
     * the given extensions advertised in the SSH_FXP_VERSION handshake.
     * Needed by the rename() tests, which behave differently depending on
     * whether posix-rename@openssh.com was advertised.
     *
     * @param array<string,string> $extensions
     *
     * @return void
     */
    protected function useChannelWithExtensions(array $extensions)
    {
        $this->transport = new FakeTransport();
        $this->transport->queueVersion(3, $extensions);

        $channel = new SftpChannel($this->transport, array($this->sftp, 'credentialException'));
        $channel->connect();

        $this->sftp->setChannel($channel);

        $this->requestCursor = strlen($this->transport->sentBytes());
    }

    /**
     * Decode the next not-yet-inspected request frame written by $this->sftp.
     *
     * @return array array(int $type, int $id, string $payload)
     */
    protected function nextRequest()
    {
        $bytes = $this->transport->sentBytes();

        $length = SftpPacket::bytesToUint32(substr($bytes, $this->requestCursor, 4));
        $this->requestCursor += 4;

        $frameBody = substr($bytes, $this->requestCursor, $length);
        $this->requestCursor += $length;

        // Request framing (type + id + payload) is byte-identical to
        // response framing, so the same decoder works for both.
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

    /**
     * @return bool whether every byte written has now been inspected
     */
    protected function noMoreRequests()
    {
        return $this->requestCursor === strlen($this->transport->sentBytes());
    }

    public function testPutSendsOpenWriteAndCloseAndUploadsFileContents()
    {
        $localFile = $this->tmpDir . '/upload.txt';
        file_put_contents($localFile, 'hello world');

        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 1, SftpPacket::packString('h1'));
        $this->transport->queueStatus(2, SftpPacket::STATUS_OK);
        $this->transport->queueStatus(3, SftpPacket::STATUS_OK);

        $this->sftp->put('/remote/path/upload.txt', $localFile);

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_OPEN);
        $offset = 0;
        $this->assertSame('/remote/path/upload.txt', SftpPacket::unpackString($payload, $offset));
        $pflags = SftpPacket::bytesToUint32(substr($payload, $offset, 4));
        $this->assertSame(SftpPacket::FXF_WRITE | SftpPacket::FXF_CREAT | SftpPacket::FXF_TRUNC, $pflags);

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_WRITE);
        $offset = 0;
        $this->assertSame('h1', SftpPacket::unpackString($payload, $offset));
        $writeOffset = SftpPacket::bytesToUint64(substr($payload, $offset, 8));
        $offset += 8;
        $this->assertEquals(0, $writeOffset);
        $this->assertSame('hello world', SftpPacket::unpackString($payload, $offset));

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_CLOSE);
        $offset = 0;
        $this->assertSame('h1', SftpPacket::unpackString($payload, $offset));

        $this->assertTrue($this->noMoreRequests());
    }

    public function testPutSplitsLargeFilesAcrossMultipleWriteRequests()
    {
        $localFile = $this->tmpDir . '/big.bin';
        // Two full 32768-byte chunks plus one partial 100-byte chunk.
        file_put_contents($localFile, str_repeat('A', Sftp::CHUNK_SIZE) . str_repeat('B', Sftp::CHUNK_SIZE) . str_repeat('C', 100));

        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 1, SftpPacket::packString('h1'));
        $this->transport->queueStatus(2, SftpPacket::STATUS_OK);
        $this->transport->queueStatus(3, SftpPacket::STATUS_OK);
        $this->transport->queueStatus(4, SftpPacket::STATUS_OK);
        $this->transport->queueStatus(5, SftpPacket::STATUS_OK);

        $this->sftp->put('/remote/big.bin', $localFile);

        $this->assertNextRequestIs(SftpPacket::TYPE_OPEN);

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_WRITE);
        $offset = 0;
        SftpPacket::unpackString($payload, $offset);
        $this->assertEquals(0, SftpPacket::bytesToUint64(substr($payload, $offset, 8)));
        $offset += 8;
        $this->assertSame(str_repeat('A', Sftp::CHUNK_SIZE), SftpPacket::unpackString($payload, $offset));

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_WRITE);
        $offset = 0;
        SftpPacket::unpackString($payload, $offset);
        $this->assertEquals(Sftp::CHUNK_SIZE, SftpPacket::bytesToUint64(substr($payload, $offset, 8)));
        $offset += 8;
        $this->assertSame(str_repeat('B', Sftp::CHUNK_SIZE), SftpPacket::unpackString($payload, $offset));

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_WRITE);
        $offset = 0;
        SftpPacket::unpackString($payload, $offset);
        $this->assertEquals(2 * Sftp::CHUNK_SIZE, SftpPacket::bytesToUint64(substr($payload, $offset, 8)));
        $offset += 8;
        $this->assertSame(str_repeat('C', 100), SftpPacket::unpackString($payload, $offset));

        $this->assertNextRequestIs(SftpPacket::TYPE_CLOSE);
        $this->assertTrue($this->noMoreRequests());
    }

    public function testPutThrowsWhenLocalFileDoesNotExist()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $this->sftp->put('/remote/path/x.txt', $this->tmpDir . '/does-not-exist.txt');
    }

    /**
     * A failed WRITE must still result in the remote handle being closed
     * (so a half-written file's handle doesn't linger server-side), with
     * the original error — not a cleanup error — being what's thrown.
     */
    public function testPutClosesTheHandleAndRethrowsWhenAWriteFails()
    {
        $localFile = $this->tmpDir . '/upload.txt';
        file_put_contents($localFile, 'hello world');

        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 1, SftpPacket::packString('h1'));
        $this->transport->queueStatus(2, SftpPacket::STATUS_PERMISSION_DENIED, 'Permission denied');
        $this->transport->queueStatus(3, SftpPacket::STATUS_OK);

        try {
            $this->sftp->put('/remote/path/upload.txt', $localFile);
            $this->fail('expected an exception');
        } catch (SftpException $e) {
            $this->assertSame(SftpPacket::STATUS_PERMISSION_DENIED, $e->getCode());
        }

        $this->assertNextRequestIs(SftpPacket::TYPE_OPEN);
        $this->assertNextRequestIs(SftpPacket::TYPE_WRITE);
        $this->assertNextRequestIs(SftpPacket::TYPE_CLOSE);
        $this->assertTrue($this->noMoreRequests());
    }

    public function testGetSendsOpenFstatReadAndCloseAndWritesLocalFile()
    {
        $localFile = $this->tmpDir . '/downloaded.txt';

        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 1, SftpPacket::packString('h1'));
        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 2, SftpPacket::encodeAttrs(array('size' => 11)));
        $this->transport->queueResponse(SftpPacket::TYPE_DATA, 3, SftpPacket::packString('hello world'));
        $this->transport->queueStatus(4, SftpPacket::STATUS_OK);

        $this->sftp->get('/remote/path/file.log', $localFile);

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_OPEN);
        $offset = 0;
        $this->assertSame('/remote/path/file.log', SftpPacket::unpackString($payload, $offset));
        $this->assertSame(SftpPacket::FXF_READ, SftpPacket::bytesToUint32(substr($payload, $offset, 4)));

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_FSTAT);
        $offset = 0;
        $this->assertSame('h1', SftpPacket::unpackString($payload, $offset));

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_READ);
        $offset = 0;
        $this->assertSame('h1', SftpPacket::unpackString($payload, $offset));
        $this->assertEquals(0, SftpPacket::bytesToUint64(substr($payload, $offset, 8)));
        $offset += 8;
        $this->assertEquals(Sftp::CHUNK_SIZE, SftpPacket::bytesToUint32(substr($payload, $offset, 4)));

        $this->assertNextRequestIs(SftpPacket::TYPE_CLOSE);
        $this->assertTrue($this->noMoreRequests());

        $this->assertSame('hello world', file_get_contents($localFile));
    }

    public function testGetWritesAnEmptyLocalFileForAZeroByteRemoteFile()
    {
        $localFile = $this->tmpDir . '/empty.txt';

        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 1, SftpPacket::packString('h1'));
        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 2, SftpPacket::encodeAttrs(array('size' => 0)));
        $this->transport->queueStatus(3, SftpPacket::STATUS_OK);

        $this->sftp->get('/remote/empty.log', $localFile);

        $this->assertSame('', file_get_contents($localFile));

        $this->assertNextRequestIs(SftpPacket::TYPE_OPEN);
        $this->assertNextRequestIs(SftpPacket::TYPE_FSTAT);
        // No READ at all — pipeline() with a total of 0 issues nothing.
        $this->assertNextRequestIs(SftpPacket::TYPE_CLOSE);
        $this->assertTrue($this->noMoreRequests());
    }

    public function testGetThrowsWithTheServersMessageAndStatusCodeOnError()
    {
        $this->transport->queueStatus(1, SftpPacket::STATUS_NO_SUCH_FILE, 'No such file');

        try {
            $this->sftp->get('/remote/missing.log', $this->tmpDir . '/local.log');
            $this->fail('expected an exception');
        } catch (SftpException $e) {
            $this->assertSame(SftpPacket::STATUS_NO_SUCH_FILE, $e->getCode());
            $this->assertStringContainsStringCompat('No such file', $e->getMessage());
        }
    }

    public function testNlistReturnsFilenamesAndSkipsDotAndDotDot()
    {
        $this->transport->queueResponse(SftpPacket::TYPE_HANDLE, 1, SftpPacket::packString('d1'));

        $entries = SftpPacket::uint32ToBytes(4)
            . SftpPacket::packString('.') . SftpPacket::packString('.') . SftpPacket::uint32ToBytes(0)
            . SftpPacket::packString('..') . SftpPacket::packString('..') . SftpPacket::uint32ToBytes(0)
            . SftpPacket::packString('access.log') . SftpPacket::packString('access.log') . SftpPacket::uint32ToBytes(0)
            . SftpPacket::packString('error.log') . SftpPacket::packString('error.log') . SftpPacket::uint32ToBytes(0);
        $this->transport->queueResponse(SftpPacket::TYPE_NAME, 2, $entries);

        $this->transport->queueStatus(3, SftpPacket::STATUS_EOF);
        $this->transport->queueStatus(4, SftpPacket::STATUS_OK);

        $result = $this->sftp->nlist('/var/log');

        $this->assertSame(array('access.log', 'error.log'), $result);

        $this->assertNextRequestIs(SftpPacket::TYPE_OPENDIR);
        $this->assertNextRequestIs(SftpPacket::TYPE_READDIR);
        $this->assertNextRequestIs(SftpPacket::TYPE_READDIR);
        $this->assertNextRequestIs(SftpPacket::TYPE_CLOSE);
        $this->assertTrue($this->noMoreRequests());
    }

    public function testDeleteSendsRemove()
    {
        $this->transport->queueStatus(1, SftpPacket::STATUS_OK);

        $this->sftp->delete('/remote/path/file.log');

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_REMOVE);
        $offset = 0;
        $this->assertSame('/remote/path/file.log', SftpPacket::unpackString($payload, $offset));
    }

    public function testMkdirSendsMkdir()
    {
        $this->transport->queueStatus(1, SftpPacket::STATUS_OK);

        $this->sftp->mkdir('/remote/path/newdir');

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_MKDIR);
        $offset = 0;
        $this->assertSame('/remote/path/newdir', SftpPacket::unpackString($payload, $offset));
    }

    public function testRmdirSendsRmdir()
    {
        $this->transport->queueStatus(1, SftpPacket::STATUS_OK);

        $this->sftp->rmdir('/remote/path/olddir');

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_RMDIR);
        $offset = 0;
        $this->assertSame('/remote/path/olddir', SftpPacket::unpackString($payload, $offset));
    }

    public function testRenameFallsBackToPlainRenameWhenExtensionNotAdvertised()
    {
        $this->transport->queueStatus(1, SftpPacket::STATUS_OK);

        $this->sftp->rename('/remote/old.log', '/remote/new.log');

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_RENAME);
        $offset = 0;
        $this->assertSame('/remote/old.log', SftpPacket::unpackString($payload, $offset));
        $this->assertSame('/remote/new.log', SftpPacket::unpackString($payload, $offset));
    }

    public function testRenameUsesPosixRenameExtensionWhenAdvertised()
    {
        $this->useChannelWithExtensions(array('posix-rename@openssh.com' => '1'));

        $this->transport->queueStatus(1, SftpPacket::STATUS_OK);

        $this->sftp->rename('/remote/old.log', '/remote/new.log');

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_EXTENDED);
        $offset = 0;
        $this->assertSame('posix-rename@openssh.com', SftpPacket::unpackString($payload, $offset));
        $this->assertSame('/remote/old.log', SftpPacket::unpackString($payload, $offset));
        $this->assertSame('/remote/new.log', SftpPacket::unpackString($payload, $offset));
    }

    public function testChmodAcceptsOctalIntLiteral()
    {
        $this->transport->queueStatus(1, SftpPacket::STATUS_OK);

        $this->sftp->chmod('/remote/path/file.log', 0644);

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_SETSTAT);
        $offset = 0;
        $this->assertSame('/remote/path/file.log', SftpPacket::unpackString($payload, $offset));
        $attrs = SftpPacket::decodeAttrs($payload, $offset);
        $this->assertSame(0644, $attrs['permissions']);
    }

    public function testChmodAcceptsStringMode()
    {
        $this->transport->queueStatus(1, SftpPacket::STATUS_OK);

        $this->sftp->chmod('/remote/path/file.log', '755');

        $payload = $this->assertNextRequestIs(SftpPacket::TYPE_SETSTAT);
        $offset = 0;
        SftpPacket::unpackString($payload, $offset);
        $attrs = SftpPacket::decodeAttrs($payload, $offset);
        $this->assertSame(0755, $attrs['permissions']);
    }

    public function testExistsReturnsTrueWhenLstatSucceeds()
    {
        $this->transport->queueResponse(SftpPacket::TYPE_ATTRS, 1, SftpPacket::encodeAttrs(array('size' => 10)));

        $this->assertTrue($this->sftp->exists('/remote/path/file.log'));

        $this->assertNextRequestIs(SftpPacket::TYPE_LSTAT);
    }

    public function testExistsReturnsFalseOnNoSuchFile()
    {
        $this->transport->queueStatus(1, SftpPacket::STATUS_NO_SUCH_FILE, 'No such file');

        $this->assertFalse($this->sftp->exists('/remote/path/missing.log'));
    }

    /**
     * A permission error is not the same thing as "doesn't exist" — only
     * SSH_FX_NO_SUCH_FILE should make exists() return false; anything else
     * must still raise, unlike the old `ls`-exit-code-based implementation
     * (which treated any failure as "false").
     */
    public function testExistsRethrowsOnPermissionDenied()
    {
        $this->transport->queueStatus(1, SftpPacket::STATUS_PERMISSION_DENIED, 'Permission denied');

        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $this->sftp->exists('/remote/path/forbidden.log');
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
