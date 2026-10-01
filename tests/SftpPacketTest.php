<?php

namespace Wilkques\Ssh\Tests;

use Wilkques\Ssh\Support\SftpPacket;

class SftpPacketTest extends TestCase
{
    public function testUint32RoundTrip()
    {
        $this->assertSame("\x00\x00\x00\x00", SftpPacket::uint32ToBytes(0));
        $this->assertSame("\x00\x00\x00\x01", SftpPacket::uint32ToBytes(1));
        $this->assertSame("\xff\xff\xff\xff", SftpPacket::uint32ToBytes(4294967295));

        $this->assertSame(0, SftpPacket::bytesToUint32("\x00\x00\x00\x00"));
        $this->assertSame(1, SftpPacket::bytesToUint32("\x00\x00\x00\x01"));
        $this->assertEquals(4294967295, SftpPacket::bytesToUint32("\xff\xff\xff\xff"));
    }

    public function testUint32WrapsValuesOutsideUnsignedRange()
    {
        // 4294967296 === 2^32, so it wraps to 0, the same way a C uint32_t would.
        $this->assertSame("\x00\x00\x00\x00", SftpPacket::uint32ToBytes(4294967296));
    }

    /**
     * SFTP offsets/sizes are uint64 on the wire; PHP 5.3 has no native
     * 64-bit int, so this must round-trip exactly via float arithmetic up
     * to 2^53. 5GB is comfortably past the 32-bit boundary this guards
     * against regressing.
     */
    public function testUint64RoundTripBeyond32Bits()
    {
        $fiveGigabytes = 5 * 1024 * 1024 * 1024; // 5368709120

        $bytes = SftpPacket::uint64ToBytes($fiveGigabytes);

        $this->assertSame(8, strlen($bytes));
        $this->assertEquals($fiveGigabytes, SftpPacket::bytesToUint64($bytes));
    }

    public function testUint64RoundTripZeroAndSmallValues()
    {
        $this->assertEquals(0, SftpPacket::bytesToUint64(SftpPacket::uint64ToBytes(0)));
        $this->assertEquals(42, SftpPacket::bytesToUint64(SftpPacket::uint64ToBytes(42)));
    }

    public function testStringRoundTrip()
    {
        $packed = SftpPacket::packString('hello');

        $this->assertSame("\x00\x00\x00\x05hello", $packed);

        $offset = 0;
        $this->assertSame('hello', SftpPacket::unpackString($packed, $offset));
        $this->assertSame(9, $offset);
    }

    public function testStringRoundTripEmpty()
    {
        $packed = SftpPacket::packString('');

        $this->assertSame("\x00\x00\x00\x00", $packed);

        $offset = 0;
        $this->assertSame('', SftpPacket::unpackString($packed, $offset));
        $this->assertSame(4, $offset);
    }

    public function testUnpackStringAdvancesOffsetAcrossMultipleFields()
    {
        $bytes = SftpPacket::packString('one') . SftpPacket::packString('two');

        $offset = 0;
        $this->assertSame('one', SftpPacket::unpackString($bytes, $offset));
        $this->assertSame('two', SftpPacket::unpackString($bytes, $offset));
        $this->assertSame(strlen($bytes), $offset);
    }

    public function testEncodeFramesTypeAndPayloadWithLengthPrefix()
    {
        $frame = SftpPacket::encode(SftpPacket::TYPE_INIT, "\x00\x00\x00\x03");

        // length = 1 (type byte) + 4 (payload) = 5
        $this->assertSame("\x00\x00\x00\x05" . chr(SftpPacket::TYPE_INIT) . "\x00\x00\x00\x03", $frame);
    }

    public function testEncodeRequestInsertsIdAfterType()
    {
        $frame = SftpPacket::encode(SftpPacket::TYPE_OPEN, SftpPacket::uint32ToBytes(7) . 'rest-of-payload');
        $requestFrame = SftpPacket::encodeRequest(SftpPacket::TYPE_OPEN, 7, 'rest-of-payload');

        $this->assertSame($frame, $requestFrame);
    }

    public function testDecodeHeaderSplitsTypeAndPayload()
    {
        list($type, $payload) = SftpPacket::decodeHeader(chr(SftpPacket::TYPE_STATUS) . 'payload-bytes');

        $this->assertSame(SftpPacket::TYPE_STATUS, $type);
        $this->assertSame('payload-bytes', $payload);
    }

    public function testDecodeResponseSplitsTypeIdAndPayload()
    {
        $frameBody = chr(SftpPacket::TYPE_HANDLE) . SftpPacket::uint32ToBytes(123) . 'handle-bytes';

        list($type, $id, $payload) = SftpPacket::decodeResponse($frameBody);

        $this->assertSame(SftpPacket::TYPE_HANDLE, $type);
        $this->assertSame(123, $id);
        $this->assertSame('handle-bytes', $payload);
    }

    public function testDecodeVersionWithNoExtensions()
    {
        $payload = SftpPacket::uint32ToBytes(3);

        $decoded = SftpPacket::decodeVersion($payload);

        $this->assertSame(3, $decoded['version']);
        $this->assertSame(array(), $decoded['extensions']);
    }

    public function testDecodeVersionWithExtensions()
    {
        $payload = SftpPacket::uint32ToBytes(3)
            . SftpPacket::packString('posix-rename@openssh.com') . SftpPacket::packString('1')
            . SftpPacket::packString('limits@openssh.com') . SftpPacket::packString('1');

        $decoded = SftpPacket::decodeVersion($payload);

        $this->assertSame(3, $decoded['version']);
        $this->assertSame(array(
            'posix-rename@openssh.com' => '1',
            'limits@openssh.com' => '1',
        ), $decoded['extensions']);
    }

    public function testDecodeStatus()
    {
        $payload = SftpPacket::uint32ToBytes(SftpPacket::STATUS_NO_SUCH_FILE)
            . SftpPacket::packString('No such file')
            . SftpPacket::packString('en');

        $status = SftpPacket::decodeStatus($payload);

        $this->assertSame(SftpPacket::STATUS_NO_SUCH_FILE, $status['code']);
        $this->assertSame('No such file', $status['message']);
        $this->assertSame('en', $status['language']);
    }

    public function testDecodeStatusToleratesMissingMessageAndLanguage()
    {
        $payload = SftpPacket::uint32ToBytes(SftpPacket::STATUS_OK);

        $status = SftpPacket::decodeStatus($payload);

        $this->assertSame(SftpPacket::STATUS_OK, $status['code']);
        $this->assertSame('', $status['message']);
        $this->assertSame('', $status['language']);
    }

    public function testDecodeHandle()
    {
        $payload = SftpPacket::packString('opaque-handle-bytes');

        $this->assertSame('opaque-handle-bytes', SftpPacket::decodeHandle($payload));
    }

    public function testDecodeData()
    {
        $payload = SftpPacket::packString('binary file contents');

        $this->assertSame('binary file contents', SftpPacket::decodeData($payload));
    }

    public function testDecodeNameListWithMultipleEntries()
    {
        $attrs = SftpPacket::uint32ToBytes(0); // no optional fields present

        $payload = SftpPacket::uint32ToBytes(2)
            . SftpPacket::packString('access.log') . SftpPacket::packString('-rw-r--r-- access.log') . $attrs
            . SftpPacket::packString('error.log') . SftpPacket::packString('-rw-r--r-- error.log') . $attrs;

        $entries = SftpPacket::decodeNameList($payload);

        $this->assertCount(2, $entries);
        $this->assertSame('access.log', $entries[0]['filename']);
        $this->assertSame('-rw-r--r-- access.log', $entries[0]['longname']);
        $this->assertSame(array('flags' => 0), $entries[0]['attrs']);
        $this->assertSame('error.log', $entries[1]['filename']);
    }

    public function testDecodeNameListEmpty()
    {
        $payload = SftpPacket::uint32ToBytes(0);

        $this->assertSame(array(), SftpPacket::decodeNameList($payload));
    }

    public function testAttrsRoundTripSizeOnly()
    {
        $encoded = SftpPacket::encodeAttrs(array('size' => 123456789));

        $offset = 0;
        $decoded = SftpPacket::decodeAttrs($encoded, $offset);

        $this->assertSame(SftpPacket::ATTR_SIZE, $decoded['flags']);
        $this->assertEquals(123456789, $decoded['size']);
        $this->assertArrayNotHasKey('permissions', $decoded);
        $this->assertSame(strlen($encoded), $offset);
    }

    public function testAttrsRoundTripPermissionsOnlyForChmod()
    {
        $encoded = SftpPacket::encodeAttrs(array('permissions' => 0644));

        $offset = 0;
        $decoded = SftpPacket::decodeAttrs($encoded, $offset);

        $this->assertSame(SftpPacket::ATTR_PERMISSIONS, $decoded['flags']);
        $this->assertSame(0644, $decoded['permissions']);
        $this->assertArrayNotHasKey('size', $decoded);
    }

    public function testAttrsRoundTripAllFields()
    {
        $encoded = SftpPacket::encodeAttrs(array(
            'size' => 42,
            'uid' => 1000,
            'gid' => 1000,
            'permissions' => 0755,
            'atime' => 1700000000,
            'mtime' => 1700000001,
        ));

        $offset = 0;
        $decoded = SftpPacket::decodeAttrs($encoded, $offset);

        $this->assertSame(42, $decoded['size']);
        $this->assertSame(1000, $decoded['uid']);
        $this->assertSame(1000, $decoded['gid']);
        $this->assertSame(0755, $decoded['permissions']);
        $this->assertSame(1700000000, $decoded['atime']);
        $this->assertSame(1700000001, $decoded['mtime']);
        $this->assertSame(strlen($encoded), $offset);
    }

    public function testAttrsWithNoFieldsEncodesJustZeroFlags()
    {
        $this->assertSame("\x00\x00\x00\x00", SftpPacket::encodeAttrs(array()));
    }

    public function testDecodeAttrsAdvancesOffsetWithinALargerBuffer()
    {
        // Mirrors how decodeNameList() calls this: ATTRS embedded after
        // other fields, with more bytes following it in the same buffer.
        $attrs = SftpPacket::encodeAttrs(array('size' => 10));
        $buffer = 'leading-bytes' . $attrs . 'trailing-bytes';

        $offset = 13;
        $decoded = SftpPacket::decodeAttrs($buffer, $offset);

        $this->assertEquals(10, $decoded['size']);
        $this->assertSame(13 + strlen($attrs), $offset);
        $this->assertSame('trailing-bytes', substr($buffer, $offset));
    }

    public function testDecodeAttrsWithExtendedData()
    {
        $extended = SftpPacket::uint32ToBytes(1)
            . SftpPacket::packString('ext-type')
            . SftpPacket::packString('ext-data');

        $flags = SftpPacket::ATTR_PERMISSIONS | SftpPacket::ATTR_EXTENDED;
        $buffer = SftpPacket::uint32ToBytes($flags)
            . SftpPacket::uint32ToBytes(0755)
            . $extended;

        $offset = 0;
        $decoded = SftpPacket::decodeAttrs($buffer, $offset);

        $this->assertSame(0755, $decoded['permissions']);
        $this->assertSame(array(array('type' => 'ext-type', 'data' => 'ext-data')), $decoded['extended']);
        $this->assertSame(strlen($buffer), $offset);
    }
}
