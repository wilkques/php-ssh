<?php

namespace Wilkques\Ssh\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Wilkques\Ssh\Exceptions\ScpException;
use Wilkques\Ssh\Exceptions\SftpException;
use Wilkques\Ssh\Scp;
use Wilkques\Ssh\Sftp;
use Wilkques\Ssh\Support\ProcessRunner;
use Wilkques\Ssh\Support\ProcessTransport;
use Wilkques\Ssh\Support\SftpChannel;
use Wilkques\Ssh\Support\SftpPacket;
use Wilkques\Ssh\Tests\TestCase;

/**
 * Exercises SftpChannel/ProcessTransport's real proc_open pipe I/O end to
 * end — the handshake, request/response framing, pipelined transfers —
 * against a minimal fake SFTP server (tests/fixtures/fake-sftp-server.php)
 * launched directly as the channel's subprocess, the same trick
 * `sftp -D <command>` uses to bypass ssh entirely (sftp.c).
 *
 * Needs no sshd, no network, no authentication, no SSH_* environment
 * variables, so it runs on every CI platform including Windows — which is
 * the whole reason it exists: the riskiest code in this package is
 * bidirectional pipe I/O on Windows (no non-blocking reads, no
 * stream_select() on pipes, a ~4KB default pipe buffer to deadlock
 * inside), and every other test that touches a real process
 * (SftpIntegrationTest) only runs against a real sshd, which CI only
 * stands up on Linux.
 *
 * @group integration
 *
 * (also tagged with the #[Group] attribute below — see
 * ExecIntegrationTest's docblock for why both forms are needed)
 */
#[Group('integration')]
class SftpPipeIntegrationTest extends TestCase
{
    /** @var SftpChannel */
    protected $channel;

    /** @var string */
    protected $serverRoot;

    /** @var bool */
    protected $isWindows;

    protected function additionalSetUp()
    {
        $this->serverRoot = $this->tmpDir . '/server-root';
        mkdir($this->serverRoot, 0777, true);

        // PHP_BINARY only exists since PHP 5.4; this package supports PHP
        // 5.3, where referencing it would silently evaluate to the literal
        // string "PHP_BINARY" instead of a real path — same fallback as
        // TunnelIntegrationTest, and for the same reason it has to be the
        // bare command name 'php' rather than a manually-built
        // PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' path: Windows'
        // CreateProcess (invoked here via bypass_shell => true) only
        // appends the .exe extension itself when resolving a bare name via
        // its own PATH search — an explicit path without the extension
        // (what the manual version produces on Windows) fails outright
        // with "CreateProcess failed, error code - 2", confirmed on real
        // Windows CI running PHP 5.3 (PHP_BINARY undefined there).
        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $script = __DIR__ . '/../fixtures/fake-sftp-server.php';

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->serverRoot);

        $runner = new ProcessRunner();
        $stderrFile = $this->tmpDir . '/server-stderr.log';
        $pipes = null;
        $process = $runner->openChannel($cmd, $stderrFile, $pipes);

        if (!is_resource($process)) {
            $this->fail('Failed to launch the fake SFTP server process.');
        }

        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $transport = new ProcessTransport($runner, $process, $pipes, $stderrFile, $this->isWindows);

        // PHP 5.3 closures don't auto-bind $this, so this deliberately only
        // builds a plain value object — no $this->assert*()/$this->method()
        // calls inside it.
        $factory = function ($message, $code = 0) {
            return new SftpException($message, $code);
        };

        $this->channel = new SftpChannel($transport, $factory);
        $this->channel->connect();
    }

    protected function additionalTearDown()
    {
        if ($this->channel) {
            $this->channel->disconnect();
        }
    }

    public function testFullRoundTripOverRealPipes()
    {
        $localUpload = $this->tmpDir . '/upload.txt';
        file_put_contents($localUpload, 'hello from a real pipe');

        $handle = $this->channel->expectHandle(
            SftpPacket::TYPE_OPEN,
            SftpPacket::packString('/upload.txt')
                . SftpPacket::uint32ToBytes(SftpPacket::FXF_WRITE | SftpPacket::FXF_CREAT | SftpPacket::FXF_TRUNC)
                . SftpPacket::encodeAttrs(array()),
            'open for write'
        );

        $this->channel->expectStatusOk(
            SftpPacket::TYPE_WRITE,
            SftpPacket::packString($handle) . SftpPacket::uint64ToBytes(0) . SftpPacket::packString(file_get_contents($localUpload)),
            'write'
        );

        $this->channel->expectStatusOk(SftpPacket::TYPE_CLOSE, SftpPacket::packString($handle), 'close after write');

        $this->assertSame('hello from a real pipe', file_get_contents($this->serverRoot . '/upload.txt'));

        $handle = $this->channel->expectHandle(
            SftpPacket::TYPE_OPEN,
            SftpPacket::packString('/upload.txt') . SftpPacket::uint32ToBytes(SftpPacket::FXF_READ) . SftpPacket::encodeAttrs(array()),
            'open for read'
        );

        list($type, $payload) = $this->channel->request(
            SftpPacket::TYPE_READ,
            SftpPacket::packString($handle) . SftpPacket::uint64ToBytes(0) . SftpPacket::uint32ToBytes(65536)
        );

        $this->assertSame(SftpPacket::TYPE_DATA, $type);
        $this->assertSame('hello from a real pipe', SftpPacket::decodeData($payload));

        $this->channel->expectStatusOk(SftpPacket::TYPE_CLOSE, SftpPacket::packString($handle), 'close after read');

        $dirHandle = $this->channel->expectHandle(SftpPacket::TYPE_OPENDIR, SftpPacket::packString('/'), 'opendir');

        $names = array();

        while (true) {
            $entries = $this->channel->expectNameList(SftpPacket::TYPE_READDIR, SftpPacket::packString($dirHandle), 'readdir');

            if ($entries === null) {
                break;
            }

            foreach ($entries as $entry) {
                $names[] = $entry['filename'];
            }
        }

        $this->channel->expectStatusOk(SftpPacket::TYPE_CLOSE, SftpPacket::packString($dirHandle), 'close dir');

        $this->assertContains('upload.txt', $names);

        $this->channel->expectStatusOk(
            SftpPacket::TYPE_RENAME,
            SftpPacket::packString('/upload.txt') . SftpPacket::packString('/renamed.txt'),
            'rename'
        );

        $this->channel->expectStatusOk(
            SftpPacket::TYPE_SETSTAT,
            SftpPacket::packString('/renamed.txt') . SftpPacket::encodeAttrs(array('permissions' => 0600)),
            'chmod'
        );

        $attrs = $this->channel->expectAttrs(SftpPacket::TYPE_LSTAT, SftpPacket::packString('/renamed.txt'), 'lstat');

        if (!$this->isWindows) {
            // Windows' chmod() can't produce POSIX-exact permission bits —
            // NTFS has no real rwxrwxrwx model, so PHP's chmod() there only
            // toggles the read-only DOS attribute and ignores the rest of
            // the requested mode (well-documented PHP/Windows behavior, and
            // confirmed directly: real Windows CI reported 0666 back
            // for a requested 0600). The SETSTAT/LSTAT wire exchange above
            // already proves the protocol round trip itself worked — this
            // byte-exact check only adds anything on a real POSIX filesystem.
            $this->assertSame(0600, $attrs['permissions'] & 0777);
        }

        $this->channel->expectStatusOk(
            SftpPacket::TYPE_MKDIR,
            SftpPacket::packString('/subdir') . SftpPacket::encodeAttrs(array()),
            'mkdir'
        );
        $this->channel->expectStatusOk(SftpPacket::TYPE_RMDIR, SftpPacket::packString('/subdir'), 'rmdir');
        $this->channel->expectStatusOk(SftpPacket::TYPE_REMOVE, SftpPacket::packString('/renamed.txt'), 'delete');
    }

    /**
     * Proves the in-flight cap / pipeline() reordering logic against a
     * real process over real pipes, not just FakeTransport. 300KB at the
     * 32KB chunk size is ~10 WRITE requests — comfortably enough to
     * guarantee at least one "wait for a slot to free up" cycle without
     * needing to exceed either platform's in-flight cap (16 or 64) outright.
     */
    public function testLargeUploadSpanningManyPipelinedWriteRequests()
    {
        $size = 300 * 1024;
        $payload = '';

        for ($i = 0; $i < $size; $i++) {
            $payload .= chr($i % 256);
        }

        $handle = $this->channel->expectHandle(
            SftpPacket::TYPE_OPEN,
            SftpPacket::packString('/big.bin')
                . SftpPacket::uint32ToBytes(SftpPacket::FXF_WRITE | SftpPacket::FXF_CREAT | SftpPacket::FXF_TRUNC)
                . SftpPacket::encodeAttrs(array()),
            'open for write'
        );

        $chunkSize = 32768;
        $total = (int) ceil($size / $chunkSize);

        $this->channel->pipeline(
            $total,
            function ($index) use ($handle, $payload, $chunkSize) {
                $offset = $index * $chunkSize;
                $chunk = substr($payload, $offset, $chunkSize);

                return array(
                    SftpPacket::TYPE_WRITE,
                    SftpPacket::packString($handle) . SftpPacket::uint64ToBytes($offset) . SftpPacket::packString($chunk),
                );
            },
            function ($index, $type, $responsePayload) {
                $status = SftpPacket::decodeStatus($responsePayload);

                if ($status['code'] !== SftpPacket::STATUS_OK) {
                    throw new \RuntimeException('write failed: ' . $status['message']);
                }
            }
        );

        $this->channel->expectStatusOk(SftpPacket::TYPE_CLOSE, SftpPacket::packString($handle), 'close');

        $this->assertSame($payload, file_get_contents($this->serverRoot . '/big.bin'));
    }

    /**
     * Drives the real Sftp class (not raw SftpChannel calls, like the tests
     * above) against the same real-pipe channel, via the setChannel() seam
     * unit tests also use — proving recursive put()/get() work end to end
     * over a real process, not just against FakeTransport.
     */
    public function testSftpRecursivePutAndGetRoundTripOverRealPipes()
    {
        $sftp = new Sftp();
        $sftp->setChannel($this->channel);

        $localTree = $this->tmpDir . '/tree';
        mkdir($localTree . '/sub', 0777, true);
        file_put_contents($localTree . '/a.txt', 'alpha');
        file_put_contents($localTree . '/sub/b.txt', 'beta');

        $sftp->put('/tree', $localTree, array('recursive' => true));

        $this->assertSame('alpha', file_get_contents($this->serverRoot . '/tree/a.txt'));
        $this->assertSame('beta', file_get_contents($this->serverRoot . '/tree/sub/b.txt'));

        $downloadTree = $this->tmpDir . '/downloaded-tree';
        $sftp->get('/tree', $downloadTree, array('recursive' => true));

        $this->assertSame('alpha', file_get_contents($downloadTree . '/a.txt'));
        $this->assertSame('beta', file_get_contents($downloadTree . '/sub/b.txt'));
    }

    public function testSftpResumeOverRealPipes()
    {
        $sftp = new Sftp();
        $sftp->setChannel($this->channel);

        // Simulate a previous, interrupted upload having already landed the
        // first half of the file server-side.
        file_put_contents($this->serverRoot . '/resume.txt', '01234');

        $localFile = $this->tmpDir . '/resume.txt';
        file_put_contents($localFile, '0123456789');

        $sftp->put('/resume.txt', $localFile, array('resume' => true));

        $this->assertSame('0123456789', file_get_contents($this->serverRoot . '/resume.txt'));

        // Simulate a previously interrupted download the same way, then
        // resume it back from the (now complete) remote file.
        $localDownload = $this->tmpDir . '/resume-download.txt';
        file_put_contents($localDownload, '01234');

        $sftp->get('/resume.txt', $localDownload, array('resume' => true));

        $this->assertSame('0123456789', file_get_contents($localDownload));
    }

    public function testSftpStatAndRealpathOverRealPipes()
    {
        $sftp = new Sftp();
        $sftp->setChannel($this->channel);

        file_put_contents($this->serverRoot . '/file.txt', 'hello');

        $stat = $sftp->stat('/file.txt');
        $this->assertEquals(5, $stat['size']);

        $this->assertSame('/a/b/file.txt', $sftp->realpath('/a/b/c/../file.txt'));
    }

    /**
     * Scp shares the exact same channel as Sftp ("one engine, two
     * facades") — this proves its directory-target append-basename
     * behavior and non-recursive-against-a-directory guard over a real
     * process, not just FakeTransport.
     */
    public function testScpDirectoryTargetAndRecursiveGuardOverRealPipes()
    {
        $scp = new Scp();
        $scp->setChannel($this->channel);

        mkdir($this->serverRoot . '/existing-dir');

        $localFile = $this->tmpDir . '/upload.txt';
        file_put_contents($localFile, 'hello');

        // scp put into an existing remote directory copies INTO it.
        $scp->put('/existing-dir', $localFile);
        $this->assertSame('hello', file_get_contents($this->serverRoot . '/existing-dir/upload.txt'));

        // Non-recursive get() against a remote directory must fail clearly.
        try {
            $scp->get('/existing-dir', $this->tmpDir . '/should-not-exist.txt');
            $this->fail('expected a ScpException');
        } catch (ScpException $e) {
            // expected
        }

        // Recursive get() of that same directory must succeed.
        $downloadDir = $this->tmpDir . '/downloaded-existing-dir';
        $scp->get('/existing-dir', $downloadDir, true);
        $this->assertSame('hello', file_get_contents($downloadDir . '/upload.txt'));
    }
}
