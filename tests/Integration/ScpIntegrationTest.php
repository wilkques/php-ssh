<?php

namespace Wilkques\Ssh\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Wilkques\Ssh\Scp;
use Wilkques\Ssh\Tests\TestCase;

/**
 * Runs Scp against a real sshd in both its default channel mode (the same
 * SftpChannel as Sftp) and legacy mode (setLegacy(true), the real
 * `scp -O ...` binary) — proving both facades over the real engine, plus
 * the one real behavioral difference between them (directory-target
 * semantics), work end to end against genuine OpenSSH. Gated the same way
 * as SftpIntegrationTest — see its docblock; reuses the same
 * SSH_SFTP_INTEGRATION env var rather than adding a new one, since this is
 * the same sshd/credentials, just a different component.
 *
 * Note: `-O` is only recognized by OpenSSH 9.0+ clients (it was added
 * specifically to request the pre-9.0 default back); testLegacyMode* here
 * assumes the CI runner's `ssh`/`scp` client is that recent.
 *
 * @group integration
 *
 * (also tagged with the #[Group] attribute below — see
 * ExecIntegrationTest's docblock for why both forms are needed)
 */
#[Group('integration')]
class ScpIntegrationTest extends TestCase
{
    /** @var Scp */
    protected $scp;

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

        $this->scp = new Scp();
        $this->scp->setSshIp($host)
            ->setUser($user)
            ->setIdRsaPath($key);
    }

    public function testChannelModePutAndGetRoundTrip()
    {
        $localUpload = $this->putLocalFile('upload.txt', "hello from wilkques/ssh scp\n");
        $remotePath = '/tmp/wilkques-ssh-scp-integration-' . uniqid() . '.txt';
        $localDownload = $this->tmpDir . '/download.txt';

        $this->scp->put($remotePath, $localUpload);
        $this->scp->get($remotePath, $localDownload);

        $this->assertSame("hello from wilkques/ssh scp\n", file_get_contents($localDownload));
    }

    /**
     * Covers both of scp's own semantics this package preserves: an
     * existing directory target means "copy into it" (the second put()
     * below, onto the directory the first one already created), and
     * recursive get() mirrors the whole tree back out.
     */
    public function testChannelModeDirectoryTargetAndRecursiveRoundTrip()
    {
        $localDir = $this->tmpDir . '/upload-dir';
        mkdir($localDir);
        file_put_contents($localDir . '/a.txt', 'alpha');

        $remoteDir = '/tmp/wilkques-ssh-scp-dir-' . uniqid();

        $this->scp->put($remoteDir, $localDir, true);

        $localFileB = $this->putLocalFile('b.txt', 'beta');
        $this->scp->put($remoteDir, $localFileB);

        $downloadDir = $this->tmpDir . '/download-dir';
        $this->scp->get($remoteDir, $downloadDir, true);

        $this->assertSame('alpha', file_get_contents($downloadDir . '/a.txt'));
        $this->assertSame('beta', file_get_contents($downloadDir . '/b.txt'));
    }

    public function testLegacyModePutAndGetRoundTrip()
    {
        $this->scp->setLegacy(true);

        $localUpload = $this->putLocalFile('legacy-upload.txt', "hello from legacy scp\n");
        $remotePath = '/tmp/wilkques-ssh-scp-legacy-' . uniqid() . '.txt';
        $localDownload = $this->tmpDir . '/legacy-download.txt';

        $this->scp->put($remotePath, $localUpload);
        $this->scp->get($remotePath, $localDownload);

        $this->assertSame("hello from legacy scp\n", file_get_contents($localDownload));
    }

    public function testLegacyModeRecursivePutAndGetRoundTrip()
    {
        $this->scp->setLegacy(true);

        $localDir = $this->tmpDir . '/legacy-upload-dir';
        mkdir($localDir);
        file_put_contents($localDir . '/a.txt', 'alpha');

        $remoteDir = '/tmp/wilkques-ssh-scp-legacy-dir-' . uniqid();

        $this->scp->put($remoteDir, $localDir, true);

        $downloadDir = $this->tmpDir . '/legacy-download-dir';
        $this->scp->get($remoteDir, $downloadDir, true);

        $this->assertSame('alpha', file_get_contents($downloadDir . '/a.txt'));
    }

    /**
     * @param string $relative
     * @param string $contents
     *
     * @return string
     */
    protected function putLocalFile($relative, $contents)
    {
        $path = $this->tmpDir . '/' . $relative;

        file_put_contents($path, $contents);

        return $path;
    }
}
