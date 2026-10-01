<?php

/**
 * Minimal SFTPv3 server for testing ProcessTransport/SftpChannel's real
 * pipe I/O without needing a real sshd or the `sftp` binary. Launched
 * directly as the channel's subprocess — the same trick `sftp -D <command>`
 * uses to bypass ssh entirely (sftp.c) — by
 * tests/Integration/SftpPipeIntegrationTest.php, on every CI platform
 * including Windows, where standing up a real OpenSSH server is slow and
 * unreliable.
 *
 * Operates on the real local filesystem rooted at argv[1] (a scratch
 * directory the test owns) — remote paths are just paths under that root.
 * Deliberately not a from-scratch virtual filesystem: using the real one
 * keeps this server's own code simple and trustworthy, which matters more
 * here than it would in the thing actually under test.
 *
 * Speaks a practical subset of SFTPv3 (RFC draft-ietf-secsh-filexfer-02):
 * INIT/VERSION (advertising posix-rename@openssh.com), OPEN/CLOSE/READ/
 * WRITE/FSTAT, OPENDIR/READDIR, REMOVE, MKDIR/RMDIR, RENAME (plus
 * posix-rename@openssh.com), SETSTAT, STAT/LSTAT. Enough to round-trip
 * everything Sftp's public API does; nowhere near a complete SFTP server.
 */

// Deliberately not vendor/autoload.php: this process has no business
// depending on the dev toolchain (phpunit/mockery) at all, only on the
// one pure-codec class it actually uses.
require __DIR__ . '/../../src/Support/SftpPacket.php';

use Wilkques\Ssh\Support\SftpPacket;

/**
 * @param resource $stream
 * @param int $length
 *
 * @return string
 */
function fakeSftpServerReadExact($stream, $length)
{
    $data = '';

    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));

        if ($chunk === false || ($chunk === '' && feof($stream))) {
            // The channel closed its end (disconnect()) — exit quietly,
            // same as a real sftp-server does when its parent ssh exits.
            exit(0);
        }

        $data .= $chunk;
    }

    return $data;
}

/**
 * @param resource $stream
 * @param string $bytes
 *
 * @return void
 */
function fakeSftpServerWriteExact($stream, $bytes)
{
    $written = 0;
    $length = strlen($bytes);

    while ($written < $length) {
        $chunk = fwrite($stream, substr($bytes, $written));

        if ($chunk === false) {
            exit(1);
        }

        $written += $chunk;
    }
}

/**
 * @param resource $stream
 * @param int $type
 * @param int|null $id null for SSH_FXP_VERSION, which carries no id
 * @param string $payload
 *
 * @return void
 */
function fakeSftpServerSend($stream, $type, $id, $payload)
{
    if ($id === null) {
        fakeSftpServerWriteExact($stream, SftpPacket::encode($type, $payload));

        return;
    }

    fakeSftpServerWriteExact($stream, SftpPacket::encode($type, SftpPacket::uint32ToBytes($id) . $payload));
}

/**
 * @param resource $stream
 * @param int $id
 * @param int $code
 * @param string $message
 *
 * @return void
 */
function fakeSftpServerSendStatus($stream, $id, $code, $message = '')
{
    $payload = SftpPacket::uint32ToBytes($code) . SftpPacket::packString($message) . SftpPacket::packString('en');

    fakeSftpServerSend($stream, SftpPacket::TYPE_STATUS, $id, $payload);
}

/**
 * @param string $root
 * @param string $remotePath
 *
 * @return string
 */
function fakeSftpServerResolvePath($root, $remotePath)
{
    return $root . '/' . ltrim($remotePath, '/');
}

$root = rtrim($argv[1], '/');

if (!is_dir($root)) {
    fwrite(STDERR, "fake-sftp-server: root directory does not exist: $root\n");
    exit(1);
}

$stdin = STDIN;
$stdout = STDOUT;

$handles = array();
$nextHandleId = 1;

// Handshake: read SSH_FXP_INIT, reply SSH_FXP_VERSION.
$length = SftpPacket::bytesToUint32(fakeSftpServerReadExact($stdin, 4));
$frameBody = fakeSftpServerReadExact($stdin, $length);
list($initType, ) = SftpPacket::decodeHeader($frameBody);

if ($initType !== SftpPacket::TYPE_INIT) {
    exit(1);
}

$versionPayload = SftpPacket::uint32ToBytes(SftpPacket::SSH2_FILEXFER_VERSION)
    . SftpPacket::packString('posix-rename@openssh.com') . SftpPacket::packString('1');

fakeSftpServerSend($stdout, SftpPacket::TYPE_VERSION, null, $versionPayload);

while (true) {
    $length = SftpPacket::bytesToUint32(fakeSftpServerReadExact($stdin, 4));
    $frameBody = fakeSftpServerReadExact($stdin, $length);

    list($type, $id, $rest) = SftpPacket::decodeResponse($frameBody);

    $offset = 0;

    switch ($type) {
        case SftpPacket::TYPE_OPEN:
            $path = SftpPacket::unpackString($rest, $offset);
            $pflags = SftpPacket::bytesToUint32(substr($rest, $offset, 4));
            $offset += 4;

            $localPath = fakeSftpServerResolvePath($root, $path);
            $mode = ($pflags & SftpPacket::FXF_WRITE) ? 'c+b' : 'rb';

            $fp = @fopen($localPath, $mode);

            if ($fp === false) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_NO_SUCH_FILE, 'open failed');
                break;
            }

            if (($pflags & SftpPacket::FXF_TRUNC) && ($pflags & SftpPacket::FXF_WRITE)) {
                ftruncate($fp, 0);
            }

            $handleId = 'h' . $nextHandleId++;
            $handles[$handleId] = array('type' => 'file', 'fp' => $fp, 'path' => $localPath);

            fakeSftpServerSend($stdout, SftpPacket::TYPE_HANDLE, $id, SftpPacket::packString($handleId));
            break;

        case SftpPacket::TYPE_CLOSE:
            $handleId = SftpPacket::unpackString($rest, $offset);

            if (isset($handles[$handleId])) {
                if ($handles[$handleId]['type'] === 'file') {
                    fclose($handles[$handleId]['fp']);
                }

                unset($handles[$handleId]);
            }

            fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OK);
            break;

        case SftpPacket::TYPE_WRITE:
            $handleId = SftpPacket::unpackString($rest, $offset);
            $writeOffset = SftpPacket::bytesToUint64(substr($rest, $offset, 8));
            $offset += 8;
            $data = SftpPacket::unpackString($rest, $offset);

            if (!isset($handles[$handleId])) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_FAILURE, 'bad handle');
                break;
            }

            fseek($handles[$handleId]['fp'], $writeOffset);
            fwrite($handles[$handleId]['fp'], $data);

            fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OK);
            break;

        case SftpPacket::TYPE_READ:
            $handleId = SftpPacket::unpackString($rest, $offset);
            $readOffset = SftpPacket::bytesToUint64(substr($rest, $offset, 8));
            $offset += 8;
            $readLength = SftpPacket::bytesToUint32(substr($rest, $offset, 4));

            if (!isset($handles[$handleId])) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_FAILURE, 'bad handle');
                break;
            }

            fseek($handles[$handleId]['fp'], $readOffset);
            $data = fread($handles[$handleId]['fp'], $readLength);

            if ($data === '' || $data === false) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_EOF, 'EOF');
            } else {
                fakeSftpServerSend($stdout, SftpPacket::TYPE_DATA, $id, SftpPacket::packString($data));
            }
            break;

        case SftpPacket::TYPE_FSTAT:
        case SftpPacket::TYPE_STAT:
        case SftpPacket::TYPE_LSTAT:
            if ($type === SftpPacket::TYPE_FSTAT) {
                $handleId = SftpPacket::unpackString($rest, $offset);
                $localPath = isset($handles[$handleId]) ? $handles[$handleId]['path'] : null;
            } else {
                $path = SftpPacket::unpackString($rest, $offset);
                $localPath = fakeSftpServerResolvePath($root, $path);
            }

            if ($localPath !== null) {
                // PHP caches stat() results per path for the life of the
                // process; this server is long-lived (one process serves
                // every request over the channel's lifetime), so without
                // this, an FSTAT/STAT/LSTAT of a path this same process
                // already wrote to earlier (e.g. a resumed transfer:
                // put() writes & closes a handle, then a later get() on
                // the same path FSTATs a *new* handle) can see a stale
                // size left over from before that write.
                clearstatcache(true, $localPath);
            }

            if ($localPath === null || !file_exists($localPath)) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_NO_SUCH_FILE, 'No such file');
                break;
            }

            $size = is_file($localPath) ? filesize($localPath) : 0;
            // Deliberately NOT masked to the low 9 rwx bits: SFTPv3's
            // `permissions` field is the full POSIX st_mode, file-type bits
            // (S_IFDIR/S_IFREG/...) included — real OpenSSH reports it the
            // same way, and Sftp::isDirectoryMode() depends on that for
            // recursive put()/get() to tell files from directories.
            $attrsPayload = SftpPacket::encodeAttrs(array(
                'size' => $size,
                'permissions' => fileperms($localPath),
            ));

            fakeSftpServerSend($stdout, SftpPacket::TYPE_ATTRS, $id, $attrsPayload);
            break;

        case SftpPacket::TYPE_SETSTAT:
            $path = SftpPacket::unpackString($rest, $offset);
            $attrs = SftpPacket::decodeAttrs($rest, $offset);
            $localPath = fakeSftpServerResolvePath($root, $path);

            if (isset($attrs['permissions'])) {
                @chmod($localPath, $attrs['permissions']);
            }

            fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OK);
            break;

        case SftpPacket::TYPE_REMOVE:
            $path = SftpPacket::unpackString($rest, $offset);
            $localPath = fakeSftpServerResolvePath($root, $path);

            if (@unlink($localPath)) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OK);
            } else {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_NO_SUCH_FILE, 'remove failed');
            }
            break;

        case SftpPacket::TYPE_MKDIR:
            $path = SftpPacket::unpackString($rest, $offset);
            $localPath = fakeSftpServerResolvePath($root, $path);

            if (@mkdir($localPath, 0777, true)) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OK);
            } else {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_FAILURE, 'mkdir failed');
            }
            break;

        case SftpPacket::TYPE_RMDIR:
            $path = SftpPacket::unpackString($rest, $offset);
            $localPath = fakeSftpServerResolvePath($root, $path);

            if (@rmdir($localPath)) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OK);
            } else {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_FAILURE, 'rmdir failed');
            }
            break;

        case SftpPacket::TYPE_RENAME:
            $from = SftpPacket::unpackString($rest, $offset);
            $to = SftpPacket::unpackString($rest, $offset);
            $localFrom = fakeSftpServerResolvePath($root, $from);
            $localTo = fakeSftpServerResolvePath($root, $to);

            if (file_exists($localTo)) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_FAILURE, 'target exists');
            } elseif (@rename($localFrom, $localTo)) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OK);
            } else {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_FAILURE, 'rename failed');
            }
            break;

        case SftpPacket::TYPE_REALPATH:
            $path = SftpPacket::unpackString($rest, $offset);

            // Lexical-only normalization (collapse '.'/'..'/duplicate
            // slashes) — real OpenSSH also resolves against the server's
            // cwd and doesn't require the path to exist; this is enough to
            // exercise Sftp::realpath()'s request/response handling.
            $segments = array();

            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }

                if ($segment === '..') {
                    array_pop($segments);
                    continue;
                }

                $segments[] = $segment;
            }

            $resolved = '/' . implode('/', $segments);

            $namePayload = SftpPacket::uint32ToBytes(1)
                . SftpPacket::packString($resolved) . SftpPacket::packString($resolved) . SftpPacket::uint32ToBytes(0);

            fakeSftpServerSend($stdout, SftpPacket::TYPE_NAME, $id, $namePayload);
            break;

        case SftpPacket::TYPE_OPENDIR:
            $path = SftpPacket::unpackString($rest, $offset);
            $localPath = fakeSftpServerResolvePath($root, $path);

            if (!is_dir($localPath)) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_NO_SUCH_FILE, 'No such directory');
                break;
            }

            $handleId = 'd' . $nextHandleId++;
            $handles[$handleId] = array('type' => 'dir', 'entries' => scandir($localPath), 'cursor' => 0);

            fakeSftpServerSend($stdout, SftpPacket::TYPE_HANDLE, $id, SftpPacket::packString($handleId));
            break;

        case SftpPacket::TYPE_READDIR:
            $handleId = SftpPacket::unpackString($rest, $offset);

            if (!isset($handles[$handleId]) || $handles[$handleId]['cursor'] >= count($handles[$handleId]['entries'])) {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_EOF, 'EOF');
                break;
            }

            // One entry per READDIR call, for simplicity — a real server
            // batches many; returning one at a time is still spec-legal.
            $name = $handles[$handleId]['entries'][$handles[$handleId]['cursor']];
            $handles[$handleId]['cursor']++;

            $namePayload = SftpPacket::uint32ToBytes(1)
                . SftpPacket::packString($name) . SftpPacket::packString($name) . SftpPacket::uint32ToBytes(0);

            fakeSftpServerSend($stdout, SftpPacket::TYPE_NAME, $id, $namePayload);
            break;

        case SftpPacket::TYPE_EXTENDED:
            $extensionName = SftpPacket::unpackString($rest, $offset);

            if ($extensionName === 'posix-rename@openssh.com') {
                $from = SftpPacket::unpackString($rest, $offset);
                $to = SftpPacket::unpackString($rest, $offset);
                $localFrom = fakeSftpServerResolvePath($root, $from);
                $localTo = fakeSftpServerResolvePath($root, $to);

                @unlink($localTo); // posix-rename overwrites an existing target

                if (@rename($localFrom, $localTo)) {
                    fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OK);
                } else {
                    fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_FAILURE, 'rename failed');
                }
            } else {
                fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OP_UNSUPPORTED, 'unsupported extension');
            }
            break;

        default:
            fakeSftpServerSendStatus($stdout, $id, SftpPacket::STATUS_OP_UNSUPPORTED, 'unsupported request type');
            break;
    }
}
