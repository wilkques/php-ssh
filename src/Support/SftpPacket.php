<?php

namespace Wilkques\Ssh\Support;

/**
 * Pure SFTPv3 wire-format codec (RFC draft-ietf-secsh-filexfer-02, the
 * version OpenSSH's `sftp-server` speaks). No I/O of any kind lives here —
 * everything is byte strings in, byte strings (or arrays) out — specifically
 * so it can be unit-tested without a process, a pipe, or a server.
 *
 * All multi-byte integers on the wire are big-endian. PHP 5.3 has no native
 * 64-bit integer type (on a 32-bit build `zend_long` is 32 bits), so every
 * integer here is read/written as a PHP int-or-float via plain arithmetic
 * (`ord()`/`chr()`, not `pack()`/`unpack()`) rather than relying on how
 * `pack('N', ...)` happens to behave once a value exceeds PHP_INT_MAX on a
 * given build. That keeps values exact up to 2^53 (float's integer
 * precision) on every platform, which covers 64-bit SFTP offsets/sizes for
 * any file under 8 PiB.
 */
class SftpPacket
{
    const SSH2_FILEXFER_VERSION = 3;

    // Client -> server request types.
    const TYPE_INIT = 1;
    const TYPE_OPEN = 3;
    const TYPE_CLOSE = 4;
    const TYPE_READ = 5;
    const TYPE_WRITE = 6;
    const TYPE_LSTAT = 7;
    const TYPE_FSTAT = 8;
    const TYPE_SETSTAT = 9;
    const TYPE_FSETSTAT = 10;
    const TYPE_OPENDIR = 11;
    const TYPE_READDIR = 12;
    const TYPE_REMOVE = 13;
    const TYPE_MKDIR = 14;
    const TYPE_RMDIR = 15;
    const TYPE_REALPATH = 16;
    const TYPE_STAT = 17;
    const TYPE_RENAME = 18;
    const TYPE_READLINK = 19;
    const TYPE_SYMLINK = 20;

    // Server -> client response types.
    const TYPE_VERSION = 2;
    const TYPE_STATUS = 101;
    const TYPE_HANDLE = 102;
    const TYPE_DATA = 103;
    const TYPE_NAME = 104;
    const TYPE_ATTRS = 105;

    // SSH_FXP_EXTENDED / SSH_FXP_EXTENDED_REPLY, used for e.g.
    // posix-rename@openssh.com and limits@openssh.com.
    const TYPE_EXTENDED = 200;
    const TYPE_EXTENDED_REPLY = 201;

    // SSH_FILEXFER_ATTR_* — which optional ATTRS fields are present.
    const ATTR_SIZE = 0x00000001;
    const ATTR_UIDGID = 0x00000002;
    const ATTR_PERMISSIONS = 0x00000004;
    const ATTR_ACMODTIME = 0x00000008;
    const ATTR_EXTENDED = 0x80000000;

    // SSH_FXF_* — SSH_FXP_OPEN pflags.
    const FXF_READ = 0x00000001;
    const FXF_WRITE = 0x00000002;
    const FXF_APPEND = 0x00000004;
    const FXF_CREAT = 0x00000008;
    const FXF_TRUNC = 0x00000010;
    const FXF_EXCL = 0x00000020;

    // SSH_FX_* — SSH_FXP_STATUS codes.
    const STATUS_OK = 0;
    const STATUS_EOF = 1;
    const STATUS_NO_SUCH_FILE = 2;
    const STATUS_PERMISSION_DENIED = 3;
    const STATUS_FAILURE = 4;
    const STATUS_BAD_MESSAGE = 5;
    const STATUS_NO_CONNECTION = 6;
    const STATUS_CONNECTION_LOST = 7;
    const STATUS_OP_UNSUPPORTED = 8;

    /**
     * Encode a 32-bit unsigned big-endian integer. Accepts an int or a
     * float (for values between PHP_INT_MAX and 2^32-1 on a 32-bit build);
     * values outside the unsigned 32-bit range wrap, matching two's
     * complement truncation.
     *
     * @param int|float $value
     *
     * @return string 4 raw bytes
     */
    public static function uint32ToBytes($value)
    {
        $value = fmod($value, 4294967296);

        if ($value < 0) {
            $value += 4294967296;
        }

        $bytes = '';

        for ($shift = 24; $shift >= 0; $shift -= 8) {
            $bytes .= chr((int) fmod(floor($value / pow(2, $shift)), 256));
        }

        return $bytes;
    }

    /**
     * Decode a 32-bit unsigned big-endian integer.
     *
     * @param string $bytes at least 4 bytes
     *
     * @return int|float int when it fits a native int, float otherwise
     *                    (only possible on a 32-bit PHP build)
     */
    public static function bytesToUint32($bytes)
    {
        $value = 0;

        for ($i = 0; $i < 4; $i++) {
            $value = $value * 256 + ord($bytes[$i]);
        }

        return $value;
    }

    /**
     * Encode a 64-bit unsigned big-endian integer as two uint32 halves.
     *
     * @param int|float $value
     *
     * @return string 8 raw bytes
     */
    public static function uint64ToBytes($value)
    {
        $hi = floor($value / 4294967296);
        $lo = $value - ($hi * 4294967296);

        return self::uint32ToBytes($hi) . self::uint32ToBytes($lo);
    }

    /**
     * Decode a 64-bit unsigned big-endian integer from two uint32 halves.
     * Exact up to 2^53 (float precision); larger values lose low-order
     * precision, which in practice means files beyond 8 PiB.
     *
     * @param string $bytes at least 8 bytes
     *
     * @return int|float
     */
    public static function bytesToUint64($bytes)
    {
        $hi = self::bytesToUint32(substr($bytes, 0, 4));
        $lo = self::bytesToUint32(substr($bytes, 4, 4));

        return $hi * 4294967296 + $lo;
    }

    /**
     * Encode an SSH "string": a uint32 length prefix followed by the raw
     * (not NUL-terminated, not necessarily UTF-8) bytes.
     *
     * @param string $value
     *
     * @return string
     */
    public static function packString($value)
    {
        return self::uint32ToBytes(strlen($value)) . $value;
    }

    /**
     * Decode one SSH "string" starting at $offset, advancing $offset past
     * it so callers can decode a run of fields without tracking positions
     * by hand.
     *
     * @param string $bytes
     * @param int $offset (by reference)
     *
     * @return string
     */
    public static function unpackString($bytes, &$offset)
    {
        $length = self::bytesToUint32(substr($bytes, $offset, 4));

        $offset += 4;

        // On PHP < 8.0, substr($str, $start, $length) returns false (not
        // '') whenever $start >= strlen($str) — which a zero-length string
        // positioned exactly at the end of the buffer hits every time (a
        // common shape: an empty filename/message/language-tag as the last
        // field). Short-circuiting the zero-length case sidesteps that
        // entirely rather than depending on buffer-bounds arithmetic.
        // Confirmed directly against real PHP 5.3.29: substr('abcd', 4, 0)
        // is bool(false), not string(0) "".
        $value = $length > 0 ? substr($bytes, $offset, $length) : '';

        $offset += $length;

        return $value;
    }

    /**
     * Frame a packet for the wire: a uint32 length (counting the type byte
     * and payload, not the length field itself) followed by the type byte
     * and payload.
     *
     * @param int $type one of the TYPE_* constants
     * @param string $payload
     *
     * @return string
     */
    public static function encode($type, $payload)
    {
        return self::uint32ToBytes(1 + strlen($payload)) . chr($type) . $payload;
    }

    /**
     * Frame a request packet: every request type except SSH_FXP_INIT
     * carries a request id immediately after the type byte, used to match
     * up pipelined responses.
     *
     * @param int $type
     * @param int $id
     * @param string $payload
     *
     * @return string
     */
    public static function encodeRequest($type, $id, $payload)
    {
        return self::encode($type, self::uint32ToBytes($id) . $payload);
    }

    /**
     * Split a frame body (the bytes after the 4-byte length prefix — i.e.
     * what's left once a transport has read exactly `length` bytes) into
     * its type byte and the remaining payload.
     *
     * @param string $frameBody
     *
     * @return array array(int $type, string $payload)
     */
    public static function decodeHeader($frameBody)
    {
        // Same PHP < 8.0 substr()-returns-false-at-end-of-string pitfall as
        // unpackString() — a type byte with a genuinely empty payload would
        // otherwise come back as `false` instead of `''`.
        $payload = strlen($frameBody) > 1 ? substr($frameBody, 1) : '';

        return array(ord($frameBody[0]), $payload);
    }

    /**
     * Split a response frame body into type, request id, and payload.
     * Only valid for response types that carry an id — i.e. everything
     * except SSH_FXP_VERSION (see decodeVersion()).
     *
     * @param string $frameBody
     *
     * @return array array(int $type, int|float $id, string $payload)
     */
    public static function decodeResponse($frameBody)
    {
        list($type, $rest) = self::decodeHeader($frameBody);

        $id = self::bytesToUint32(substr($rest, 0, 4));
        // Same PHP < 8.0 substr()-at-end-of-string pitfall again — a
        // response with no payload beyond its id (none of this package's
        // own usages produce one, but this is a general-purpose codec).
        $payload = strlen($rest) > 4 ? substr($rest, 4) : '';

        return array($type, $id, $payload);
    }

    /**
     * Decode an SSH_FXP_VERSION payload (no request id — it's a reply to
     * SSH_FXP_INIT, which precedes request/response pairing entirely).
     *
     * @param string $payload
     *
     * @return array array('version' => int, 'extensions' => array<string,string>)
     */
    public static function decodeVersion($payload)
    {
        $offset = 0;
        $version = self::bytesToUint32(substr($payload, 0, 4));
        $offset += 4;

        $extensions = array();
        $length = strlen($payload);

        while ($offset < $length) {
            $name = self::unpackString($payload, $offset);
            $data = self::unpackString($payload, $offset);

            $extensions[$name] = $data;
        }

        return array('version' => $version, 'extensions' => $extensions);
    }

    /**
     * Decode an SSH_FXP_STATUS payload.
     *
     * @param string $payload
     *
     * @return array array('code' => int, 'message' => string, 'language' => string)
     */
    public static function decodeStatus($payload)
    {
        $offset = 0;
        $code = self::bytesToUint32(substr($payload, 0, 4));
        $offset += 4;

        // v3 always sends message + language; be lenient in case a
        // nonstandard server omits them.
        $message = $offset < strlen($payload) ? self::unpackString($payload, $offset) : '';
        $language = $offset < strlen($payload) ? self::unpackString($payload, $offset) : '';

        return array('code' => $code, 'message' => $message, 'language' => $language);
    }

    /**
     * Decode an SSH_FXP_HANDLE payload.
     *
     * @param string $payload
     *
     * @return string the opaque handle
     */
    public static function decodeHandle($payload)
    {
        $offset = 0;

        return self::unpackString($payload, $offset);
    }

    /**
     * Decode an SSH_FXP_DATA payload.
     *
     * @param string $payload
     *
     * @return string
     */
    public static function decodeData($payload)
    {
        $offset = 0;

        return self::unpackString($payload, $offset);
    }

    /**
     * Decode an SSH_FXP_NAME payload (used by SSH_FXP_READDIR and
     * SSH_FXP_REALPATH responses).
     *
     * @param string $payload
     *
     * @return array list of array('filename' => string, 'longname' => string, 'attrs' => array)
     */
    public static function decodeNameList($payload)
    {
        $offset = 0;
        $count = self::bytesToUint32(substr($payload, 0, 4));
        $offset += 4;

        $entries = array();

        for ($i = 0; $i < $count; $i++) {
            $filename = self::unpackString($payload, $offset);
            $longname = self::unpackString($payload, $offset);
            $attrs = self::decodeAttrs($payload, $offset);

            $entries[] = array('filename' => $filename, 'longname' => $longname, 'attrs' => $attrs);
        }

        return $entries;
    }

    /**
     * Decode one ATTRS structure starting at $offset, advancing $offset
     * past it. Only the fields whose flag bit is set are present on the
     * wire, so absent fields are simply not present in the returned array.
     *
     * @param string $bytes
     * @param int $offset (by reference)
     *
     * @return array
     */
    public static function decodeAttrs($bytes, &$offset)
    {
        $flags = self::bytesToUint32(substr($bytes, $offset, 4));
        $offset += 4;

        $attrs = array('flags' => $flags);

        if ($flags & self::ATTR_SIZE) {
            $attrs['size'] = self::bytesToUint64(substr($bytes, $offset, 8));
            $offset += 8;
        }

        if ($flags & self::ATTR_UIDGID) {
            $attrs['uid'] = self::bytesToUint32(substr($bytes, $offset, 4));
            $offset += 4;
            $attrs['gid'] = self::bytesToUint32(substr($bytes, $offset, 4));
            $offset += 4;
        }

        if ($flags & self::ATTR_PERMISSIONS) {
            $attrs['permissions'] = self::bytesToUint32(substr($bytes, $offset, 4));
            $offset += 4;
        }

        if ($flags & self::ATTR_ACMODTIME) {
            $attrs['atime'] = self::bytesToUint32(substr($bytes, $offset, 4));
            $offset += 4;
            $attrs['mtime'] = self::bytesToUint32(substr($bytes, $offset, 4));
            $offset += 4;
        }

        if ($flags & self::ATTR_EXTENDED) {
            $extendedCount = self::bytesToUint32(substr($bytes, $offset, 4));
            $offset += 4;

            $attrs['extended'] = array();

            for ($i = 0; $i < $extendedCount; $i++) {
                $extType = self::unpackString($bytes, $offset);
                $extData = self::unpackString($bytes, $offset);

                $attrs['extended'][] = array('type' => $extType, 'data' => $extData);
            }
        }

        return $attrs;
    }

    /**
     * Encode an ATTRS structure for SSH_FXP_OPEN/SETSTAT/MKDIR. Only keys
     * actually present in $attrs are written, with the matching flag bits
     * set — this is also how, e.g., chmod() sends just `permissions`
     * without needing to know the file's size/uid/gid/times.
     *
     * @param array $attrs subset of: size, uid, gid, permissions, atime, mtime
     *
     * @return string
     */
    public static function encodeAttrs(array $attrs)
    {
        $flags = 0;
        $body = '';

        if (isset($attrs['size'])) {
            $flags |= self::ATTR_SIZE;
            $body .= self::uint64ToBytes($attrs['size']);
        }

        if (isset($attrs['uid']) && isset($attrs['gid'])) {
            $flags |= self::ATTR_UIDGID;
            $body .= self::uint32ToBytes($attrs['uid']);
            $body .= self::uint32ToBytes($attrs['gid']);
        }

        if (isset($attrs['permissions'])) {
            $flags |= self::ATTR_PERMISSIONS;
            $body .= self::uint32ToBytes($attrs['permissions']);
        }

        if (isset($attrs['atime']) && isset($attrs['mtime'])) {
            $flags |= self::ATTR_ACMODTIME;
            $body .= self::uint32ToBytes($attrs['atime']);
            $body .= self::uint32ToBytes($attrs['mtime']);
        }

        return self::uint32ToBytes($flags) . $body;
    }
}
