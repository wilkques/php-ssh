<?php

namespace Wilkques\Ssh\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Wilkques\Ssh\Sftp;
use Wilkques\Ssh\Tests\TestCase;

/**
 * Runs a real SFTPv3 protocol channel (`ssh -s host sftp`, PHP speaking the
 * wire protocol directly — see Wilkques\Ssh\Support\SftpChannel) against a
 * real sshd. Only meaningful with a reachable test sshd (see the dedicated
 * `integration-ssh` CI job, which stands one up on localhost with a
 * throwaway keypair) — skipped otherwise. Unit tests cover the protocol
 * logic itself against a FakeTransport; this is what proves the real thing
 * — handshake, request/response framing, pipelining — actually works
 * end to end against genuine OpenSSH.
 *
 * @group integration
 *
 * (also tagged with the #[Group] attribute below — see
 * ExecIntegrationTest's docblock for why both forms are needed)
 */
#[Group('integration')]
class SftpIntegrationTest extends TestCase
{
    /** @var Sftp */
    protected $sftp;

    protected function additionalSetUp()
    {
        if (getenv('SSH_SFTP_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set SSH_SFTP_INTEGRATION=1 (and SSH_INTEGRATION_HOST/USER/KEY) to run this test.');
        }

        $host = getenv('SSH_INTEGRATION_HOST');
        $user = getenv('SSH_INTEGRATION_USER');
        $key = getenv('SSH_INTEGRATION_KEY');

        if (!$host || !$user || !$key) {
            $this->markTestSkipped('SSH_INTEGRATION_HOST/USER/KEY must all be set to run this test.');
        }

        $this->sftp = new Sftp();
        $this->sftp->setSshIp($host)
            ->setUser($user)
            ->setIdRsaPath($key);
    }

    public function testPutGetAndNlistRoundTrip()
    {
        $localUpload = $this->putFile('upload.txt', "hello from wilkques/ssh\n");
        $remotePath = '/tmp/wilkques-ssh-integration-' . uniqid() . '.txt';
        $localDownload = $this->tmpDir . '/download.txt';

        $this->sftp->put($remotePath, $localUpload);

        $names = $this->sftp->nlist('/tmp');
        $this->assertContains(basename($remotePath), $names);

        $this->sftp->get($remotePath, $localDownload);

        $this->assertSame("hello from wilkques/ssh\n", file_get_contents($localDownload));
    }

    /**
     * @param string $relative
     * @param string $contents
     *
     * @return string
     */
    protected function putFile($relative, $contents)
    {
        $path = $this->tmpDir . '/' . $relative;

        file_put_contents($path, $contents);

        return $path;
    }
}
