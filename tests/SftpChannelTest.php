<?php

namespace Wilkques\Ssh\Tests;

use Wilkques\Ssh\Exceptions\SftpException;
use Wilkques\Ssh\Support\SftpChannel;
use Wilkques\Ssh\Support\SftpPacket;
use Wilkques\Ssh\Tests\Support\CountingFakeTransport;
use Wilkques\Ssh\Tests\Support\FakeTransport;

class SftpChannelTest extends TestCase
{
    /**
     * @param \Wilkques\Ssh\Support\SftpTransport $transport
     * @param int $maxInFlight
     *
     * @return SftpChannel
     */
    protected function makeChannel($transport, $maxInFlight = SftpChannel::MAX_IN_FLIGHT_DEFAULT)
    {
        // PHP 5.3 closures don't auto-bind $this, so this deliberately only
        // builds a plain value object — no $this->assert*()/$this->method()
        // calls could leak into it even if someone tried.
        $factory = function ($message, $code = 0) {
            return new SftpException($message, $code);
        };

        return new SftpChannel($transport, $factory, $maxInFlight);
    }

    public function testConnectNegotiatesVersion()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $this->assertSame(3, $channel->serverVersion());
    }

    public function testConnectSendsInitWithThisClientsVersion()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);

        $this->makeChannel($transport)->connect();

        $expected = SftpPacket::encode(SftpPacket::TYPE_INIT, SftpPacket::uint32ToBytes(3));

        $this->assertSame($expected, $transport->sentBytes());
    }

    public function testConnectCapturesAdvertisedExtensions()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3, array('posix-rename@openssh.com' => '1', 'limits@openssh.com' => '1'));

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $this->assertTrue($channel->hasExtension('posix-rename@openssh.com'));
        $this->assertTrue($channel->hasExtension('posix-rename@openssh.com', '1'));
        $this->assertFalse($channel->hasExtension('posix-rename@openssh.com', '2'));
        $this->assertFalse($channel->hasExtension('not-advertised@openssh.com'));
    }

    public function testConnectThrowsWhenServerRepliesWithTheWrongPacketType()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $transport = new FakeTransport();
        // SSH_FXP_STATUS where SSH_FXP_VERSION was expected.
        $transport->queueResponse(SftpPacket::TYPE_STATUS, 0, SftpPacket::uint32ToBytes(SftpPacket::STATUS_FAILURE));

        $this->makeChannel($transport)->connect();
    }

    public function testConnectThrowsWhenServerVersionIsBelowThree()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $transport = new FakeTransport();
        $transport->queueVersion(2);

        $this->makeChannel($transport)->connect();
    }

    public function testChannelFailureMessageIncludesStderrOutput()
    {
        $transport = new FakeTransport();
        $transport->setErrorOutput('Permission denied (publickey).');
        // Nothing queued at all, so the very first read() fails.

        try {
            $this->makeChannel($transport)->connect();
            $this->fail('expected connect() to throw');
        } catch (SftpException $e) {
            $this->assertStringContainsStringCompat('Permission denied (publickey).', $e->getMessage());
        }
    }

    public function testRequestReturnsResponseTypeAndPayload()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueResponse(SftpPacket::TYPE_HANDLE, 1, SftpPacket::packString('opaque-handle'));

        $channel = $this->makeChannel($transport);
        $channel->connect();

        list($type, $payload) = $channel->request(SftpPacket::TYPE_OPEN, 'open-payload');

        $this->assertSame(SftpPacket::TYPE_HANDLE, $type);
        $this->assertSame('opaque-handle', SftpPacket::decodeHandle($payload));
    }

    public function testRequestThrowsOnMismatchedResponseId()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $transport = new FakeTransport();
        $transport->queueVersion(3);
        // The channel will allocate id 1 for the next request; respond with a
        // different id to simulate a desynced/corrupted stream.
        $transport->queueStatus(99, SftpPacket::STATUS_OK);

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $channel->request(SftpPacket::TYPE_REMOVE, 'payload');
    }

    public function testExpectStatusOkSucceedsOnStatusOk()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueStatus(1, SftpPacket::STATUS_OK);

        $channel = $this->makeChannel($transport);
        $channel->connect();

        // Success is "didn't throw" plus the documented void-ish null return.
        $this->assertNull($channel->expectStatusOk(SftpPacket::TYPE_RMDIR, 'payload', "rmdir('/x')"));
    }

    public function testExpectStatusOkThrowsWithTheStatusCodeAsTheExceptionCode()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueStatus(1, SftpPacket::STATUS_NO_SUCH_FILE, 'No such file');

        $channel = $this->makeChannel($transport);
        $channel->connect();

        try {
            $channel->expectStatusOk(SftpPacket::TYPE_RMDIR, 'payload', "rmdir('/missing')");
            $this->fail('expected an exception');
        } catch (SftpException $e) {
            $this->assertSame(SftpPacket::STATUS_NO_SUCH_FILE, $e->getCode());
            $this->assertStringContainsStringCompat("rmdir('/missing')", $e->getMessage());
            $this->assertStringContainsStringCompat('No such file', $e->getMessage());
        }
    }

    public function testExpectHandleReturnsTheHandle()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueResponse(SftpPacket::TYPE_HANDLE, 1, SftpPacket::packString('h1'));

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $this->assertSame('h1', $channel->expectHandle(SftpPacket::TYPE_OPEN, 'payload', "open('/x')"));
    }

    public function testExpectHandleThrowsOnStatusError()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueStatus(1, SftpPacket::STATUS_PERMISSION_DENIED, 'Permission denied');

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $channel->expectHandle(SftpPacket::TYPE_OPEN, 'payload', "open('/x')");
    }

    public function testExpectNameListReturnsEntries()
    {
        $entries = SftpPacket::uint32ToBytes(1)
            . SftpPacket::packString('file.txt') . SftpPacket::packString('-rw-r--r-- file.txt') . SftpPacket::uint32ToBytes(0);

        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueResponse(SftpPacket::TYPE_NAME, 1, $entries);

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $result = $channel->expectNameList(SftpPacket::TYPE_READDIR, 'payload', "nlist('/x')");

        $this->assertCount(1, $result);
        $this->assertSame('file.txt', $result[0]['filename']);
    }

    public function testExpectNameListReturnsNullAtEndOfFileStatus()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueStatus(1, SftpPacket::STATUS_EOF);

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $this->assertNull($channel->expectNameList(SftpPacket::TYPE_READDIR, 'payload', "nlist('/x')"));
    }

    public function testExpectNameListThrowsOnNonEofStatusError()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueStatus(1, SftpPacket::STATUS_NO_SUCH_FILE, 'No such file');

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $channel->expectNameList(SftpPacket::TYPE_READDIR, 'payload', "nlist('/missing')");
    }

    public function testExpectAttrsReturnsDecodedAttrs()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueResponse(SftpPacket::TYPE_ATTRS, 1, SftpPacket::encodeAttrs(array('size' => 42)));

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $attrs = $channel->expectAttrs(SftpPacket::TYPE_STAT, 'payload', "stat('/x')");

        $this->assertEquals(42, $attrs['size']);
    }

    public function testExpectAttrsThrowsOnStatusError()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueStatus(1, SftpPacket::STATUS_NO_SUCH_FILE, 'No such file');

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $channel->expectAttrs(SftpPacket::TYPE_STAT, 'payload', "stat('/missing')");
    }

    public function testMaxInFlightReturnsTheConfiguredValue()
    {
        $channel = $this->makeChannel(new FakeTransport(), 16);

        $this->assertSame(16, $channel->maxInFlight());
    }

    /**
     * The SFTP spec doesn't guarantee responses arrive in request order
     * (OpenSSH's own client tracks them by id for the same reason), so
     * responses are queued in a deliberately shuffled order here; pipeline()
     * must still deliver onResponse() callbacks strictly in request order.
     */
    public function testPipelineDeliversInRequestOrderEvenWhenResponsesArriveOutOfOrder()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);

        // Requests for indices 0..3 will be allocated ids 1..4 (in that
        // order, since allocateId() is a simple incrementing counter).
        // Queue their STATUS responses out of order: 2, 1, 4, 3.
        $transport->queueStatus(2, SftpPacket::STATUS_OK, 'for-id-2');
        $transport->queueStatus(1, SftpPacket::STATUS_OK, 'for-id-1');
        $transport->queueStatus(4, SftpPacket::STATUS_OK, 'for-id-4');
        $transport->queueStatus(3, SftpPacket::STATUS_OK, 'for-id-3');

        $channel = $this->makeChannel($transport, 2); // force at least one "wait for a slot" cycle

        $channel->connect();

        $delivered = array();

        $channel->pipeline(
            4,
            function ($index) {
                return array(SftpPacket::TYPE_READ, 'req-' . $index);
            },
            function ($index, $type, $payload) use (&$delivered) {
                $status = SftpPacket::decodeStatus($payload);
                $delivered[] = array($index, $status['message']);
            }
        );

        $this->assertSame(array(
            array(0, 'for-id-1'),
            array(1, 'for-id-2'),
            array(2, 'for-id-3'),
            array(3, 'for-id-4'),
        ), $delivered);
    }

    public function testPipelineNeverExceedsTheConfiguredInFlightCap()
    {
        $transport = new CountingFakeTransport();
        $transport->queueVersion(3);

        for ($id = 1; $id <= 5; $id++) {
            $transport->queueStatus($id, SftpPacket::STATUS_OK);
        }

        $channel = $this->makeChannel($transport, 2);
        $channel->connect();

        $transport->startMeasuring();

        $channel->pipeline(5, function ($index) {
            // Fixed-length payload so every request frame is the same,
            // known size: 4 (length) + 1 (type) + 4 (id) + 4 (payload) = 13 bytes.
            return array(SftpPacket::TYPE_READ, 'AAAA');
        }, function ($index, $type, $payload) {
            // not under test here
        });

        $frameSize = 13;

        $this->assertSame(2 * $frameSize, $transport->sentBytesSinceBaselineAtFirstRead());
    }

    public function testPipelineThrowsOnUnmatchedResponseId()
    {
        $this->expectExceptionCompat('Wilkques\\Ssh\\Exceptions\\SftpException');

        $transport = new FakeTransport();
        $transport->queueVersion(3);
        $transport->queueStatus(99, SftpPacket::STATUS_OK);

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $channel->pipeline(1, function ($index) {
            return array(SftpPacket::TYPE_READ, 'req');
        }, function ($index, $type, $payload) {
        });
    }

    public function testDisconnectClosesTheTransport()
    {
        $transport = new FakeTransport();
        $transport->queueVersion(3);

        $channel = $this->makeChannel($transport);
        $channel->connect();

        $channel->disconnect();

        $this->assertSame(1, $transport->closeCallCount());
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
