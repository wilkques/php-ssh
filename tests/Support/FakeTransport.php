<?php

namespace Wilkques\Ssh\Tests\Support;

use Wilkques\Ssh\Support\SftpPacket;
use Wilkques\Ssh\Support\SftpTransport;

/**
 * In-memory SftpTransport double. Queue up response frames with
 * queueResponse()/queueVersion(), then let SftpChannel read through them;
 * everything written is captured for assertions via sentBytes(). No
 * process, no pipe, no network — this is what makes SftpChannel's protocol
 * logic (framing, request/response matching, pipelining, error mapping)
 * unit-testable on its own.
 */
class FakeTransport implements SftpTransport
{
    /** @var string */
    protected $incoming = '';

    /** @var string */
    protected $outgoing = '';

    /** @var string */
    protected $stderr = '';

    /** @var bool */
    protected $eof = false;

    /** @var int */
    protected $readCalls = 0;

    /** @var int */
    protected $closeCalls = 0;

    /**
     * Queue a complete, already-length-prefixed frame to be handed back by
     * future read() calls.
     *
     * @param string $frame
     *
     * @return static
     */
    public function queueFrame($frame)
    {
        $this->incoming .= $frame;

        return $this;
    }

    /**
     * Queue a response frame built from a type/id/payload triple.
     *
     * @param int $type
     * @param int $id
     * @param string $payload
     *
     * @return static
     */
    public function queueResponse($type, $id, $payload)
    {
        return $this->queueFrame(SftpPacket::encode($type, SftpPacket::uint32ToBytes($id) . $payload));
    }

    /**
     * Queue an SSH_FXP_VERSION response (no request id — it answers
     * SSH_FXP_INIT, which precedes request/response pairing entirely).
     *
     * @param int $version
     * @param array<string,string> $extensions
     *
     * @return static
     */
    public function queueVersion($version, array $extensions = array())
    {
        $payload = SftpPacket::uint32ToBytes($version);

        foreach ($extensions as $name => $data) {
            $payload .= SftpPacket::packString($name) . SftpPacket::packString($data);
        }

        return $this->queueFrame(SftpPacket::encode(SftpPacket::TYPE_VERSION, $payload));
    }

    /**
     * Queue an SSH_FXP_STATUS response.
     *
     * @param int $id
     * @param int $code
     * @param string $message
     *
     * @return static
     */
    public function queueStatus($id, $code, $message = '')
    {
        $payload = SftpPacket::uint32ToBytes($code)
            . SftpPacket::packString($message)
            . SftpPacket::packString('en');

        return $this->queueResponse(SftpPacket::TYPE_STATUS, $id, $payload);
    }

    /**
     * {@inheritdoc}
     */
    public function write($bytes)
    {
        $this->outgoing .= $bytes;
    }

    /**
     * {@inheritdoc}
     */
    public function read($length)
    {
        $this->readCalls++;

        if (strlen($this->incoming) < $length) {
            $this->eof = true;

            throw new \RuntimeException(sprintf(
                'FakeTransport: not enough queued bytes to satisfy read(%d) (only %d available)',
                $length,
                strlen($this->incoming)
            ));
        }

        $data = substr($this->incoming, 0, $length);
        $this->incoming = substr($this->incoming, $length);

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function isEof()
    {
        return $this->eof;
    }

    /**
     * {@inheritdoc}
     */
    public function errorOutput()
    {
        return $this->stderr;
    }

    /**
     * @param string $stderr
     *
     * @return static
     */
    public function setErrorOutput($stderr)
    {
        $this->stderr = $stderr;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->closeCalls++;
    }

    /**
     * @return string everything written via write(), in order
     */
    public function sentBytes()
    {
        return $this->outgoing;
    }

    /**
     * @return int how many bytes are still queued and unread
     */
    public function remainingBytes()
    {
        return strlen($this->incoming);
    }

    /**
     * @return int how many separate read() calls have been made
     */
    public function readCallCount()
    {
        return $this->readCalls;
    }

    /**
     * @return int how many times close() has been called
     */
    public function closeCallCount()
    {
        return $this->closeCalls;
    }
}
