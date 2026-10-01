<?php

namespace Wilkques\Ssh\Support;

/**
 * SFTPv3 protocol engine, driven over an SftpTransport. This is the thing
 * that replaces shelling out to `sftp -q -b <batchfile>`: PHP speaks the
 * file-transfer packet layer directly against the pipes of a persistent
 * `ssh -s host sftp` subprocess, while OpenSSH still does every bit of
 * crypto, authentication, known_hosts checking, ProxyJump, and
 * ControlMaster reuse exactly as it always has.
 *
 * Request pipelining and its in-flight cap exist because of one hard
 * constraint: this channel holds both the write side (stdin) and the read
 * side (stdout) of the same pipe pair open at once, and PHP cannot poll
 * either non-blockingly on Windows (no stream_select() on pipes). Deadlock
 * needs both ends blocked on a write at the same time — us blocked writing
 * a request because the server isn't reading, and the server blocked
 * writing a response because our read side isn't being drained. A
 * well-behaved sftp-server is a read -> process -> write loop, so once its
 * write blocks it stops reading too, which is exactly that trap. The
 * invariant that avoids it: un-drained bytes in each direction must stay
 * under one pipe buffer. PHP creates Windows pipes via
 * `CreatePipe(..., 0)` (ext/standard/proc_open.c), and an nSize of 0 means
 * the OS default — commonly 4096 bytes, and only advisory at that — versus
 * ~64KB on Linux/macOS. See maxInFlight() for the numbers this produces.
 *
 * @see https://datatracker.ietf.org/doc/html/draft-ietf-secsh-filexfer-02 (SFTPv3, what OpenSSH's sftp-server speaks)
 */
class SftpChannel
{
    /**
     * Outstanding-request cap on Windows. Upload (WRITE request ~32KB out,
     * STATUS ack in — worst case ~200B for an error reply with message +
     * language tag) and download (READ request ~50B out, DATA response up
     * to ~32KB in) both stay comfortably under a 4KB pipe buffer at this
     * depth (worst case ~3.2KB of un-drained bytes in the constrained
     * direction); 64 would not (~12.8KB on the upload-ack side alone).
     */
    const MAX_IN_FLIGHT_WINDOWS = 16;

    /**
     * Outstanding-request cap everywhere else, matching OpenSSH's own
     * `sftp -R` default of 64 — Linux/macOS pipe buffers (~64KB) have
     * ample headroom at this depth.
     */
    const MAX_IN_FLIGHT_DEFAULT = 64;

    /** @var SftpTransport */
    protected $transport;

    /**
     * Builds the exception to throw for any channel failure — protocol
     * errors, SSH_FX_* status codes, transport I/O failures. Passed in
     * rather than hardcoded so the same engine throws SftpException when
     * driven by Sftp and ScpException when driven by Scp: a callable
     * `array($owner, 'credentialException')`, matching the existing
     * per-component exception factory every component in this package
     * already implements.
     *
     * @var callable function($message, $code = 0): \Wilkques\Ssh\Exceptions\SshException
     */
    protected $exceptionFactory;

    /** @var int */
    protected $maxInFlight;

    /** @var int */
    protected $nextId = 1;

    /** @var int|null */
    protected $serverVersion;

    /** @var array<string,string> */
    protected $extensions = array();

    /**
     * @param SftpTransport $transport
     * @param callable $exceptionFactory
     * @param int $maxInFlight see MAX_IN_FLIGHT_WINDOWS / MAX_IN_FLIGHT_DEFAULT
     */
    public function __construct(SftpTransport $transport, $exceptionFactory, $maxInFlight = self::MAX_IN_FLIGHT_DEFAULT)
    {
        $this->transport = $transport;
        $this->exceptionFactory = $exceptionFactory;
        $this->maxInFlight = $maxInFlight;
    }

    /**
     * Send SSH_FXP_INIT and process the SSH_FXP_VERSION reply. Must be
     * called once before any request() call.
     *
     * @return static
     */
    public function connect()
    {
        $this->writeFrame(SftpPacket::encode(
            SftpPacket::TYPE_INIT,
            SftpPacket::uint32ToBytes(SftpPacket::SSH2_FILEXFER_VERSION)
        ));

        list($type, $payload) = SftpPacket::decodeHeader($this->readFrame());

        if ($type !== SftpPacket::TYPE_VERSION) {
            $this->raise(sprintf(
                'SFTP handshake failed: expected SSH_FXP_VERSION (packet type %d), got packet type %d.',
                SftpPacket::TYPE_VERSION,
                $type
            ));
        }

        $version = SftpPacket::decodeVersion($payload);

        if ($version['version'] < SftpPacket::SSH2_FILEXFER_VERSION) {
            $this->raise(sprintf(
                'SFTP handshake failed: server only supports protocol version %d; this client requires version %d.',
                $version['version'],
                SftpPacket::SSH2_FILEXFER_VERSION
            ));
        }

        $this->serverVersion = $version['version'];
        $this->extensions = $version['extensions'];

        return $this;
    }

    /**
     * @return int the negotiated protocol version (always 3 against a v3 server; a
     *             newer server may report higher but this client always speaks v3)
     */
    public function serverVersion()
    {
        return $this->serverVersion;
    }

    /**
     * @param string $name e.g. 'posix-rename@openssh.com'
     * @param string|null $version require this exact advertised version string, or null to accept any
     *
     * @return bool
     */
    public function hasExtension($name, $version = null)
    {
        if (!isset($this->extensions[$name])) {
            return false;
        }

        return $version === null || $this->extensions[$name] === (string) $version;
    }

    /**
     * @return int the outstanding-request cap this channel was built with
     */
    public function maxInFlight()
    {
        return $this->maxInFlight;
    }

    /**
     * Allocate the next request id. Ids are never reused within a channel's
     * lifetime, which is the simplest way to guarantee a stale response
     * can never be mistaken for a current one.
     *
     * @return int
     */
    public function allocateId()
    {
        return $this->nextId++;
    }

    /**
     * Send one request and return its id, without waiting for a response —
     * the building block pipeline() and request() are both written on top
     * of.
     *
     * @param int $type
     * @param string $payload
     *
     * @return int the allocated request id
     */
    public function sendRequest($type, $payload)
    {
        $id = $this->allocateId();

        $this->writeFrame(SftpPacket::encodeRequest($type, $id, $payload));

        return $id;
    }

    /**
     * Read and decode exactly one response frame, whatever its id is —
     * the caller is responsible for matching it to a pending request.
     *
     * @return array array(int $type, int|float $id, string $payload)
     */
    public function receiveResponse()
    {
        return SftpPacket::decodeResponse($this->readFrame());
    }

    /**
     * Send one request and block for its response. Only safe to use when
     * exactly one request is outstanding at a time (every Sftp operation
     * except put()/get(), which use pipeline() instead) — the response id
     * is checked against the request id specifically to catch a pipelining
     * bug elsewhere turning into silent data corruption instead of a loud
     * failure here.
     *
     * @param int $type
     * @param string $payload
     *
     * @return array array(int $responseType, string $responsePayload)
     */
    public function request($type, $payload)
    {
        $id = $this->sendRequest($type, $payload);

        list($responseType, $responseId, $responsePayload) = $this->receiveResponse();

        if ($responseId !== $id) {
            $this->raise(sprintf('SFTP protocol desync: expected response id %d, got %d.', $id, $responseId));
        }

        return array($responseType, $responsePayload);
    }

    /**
     * Run a pipelined series of up to $total requests, keeping at most
     * maxInFlight() unanswered at once. This is the mechanism put()/get()
     * use to keep a transfer saturated without the deadlock risk described
     * in this class's docblock.
     *
     * The SFTP spec does not guarantee responses arrive in the order
     * requests were sent (OpenSSH's own client tracks them by id for the
     * same reason), so responses are matched back to the index they
     * belong to by id, buffered if they arrive early, and $onResponse
     * always fires in request order regardless of arrival order.
     *
     * @param int $total number of requests to issue
     * @param callable $makeRequest function($index): array($type, $payload) — called with
     *                              indices 0..$total-1, in order, exactly once each
     * @param callable $onResponse function($index, $type, $payload): void — called once
     *                             per response, strictly in request order
     *
     * @return void
     */
    public function pipeline($total, $makeRequest, $onResponse)
    {
        $pendingIndexById = array();
        $buffered = array();
        $nextToSend = 0;
        $nextToDeliver = 0;

        while ($nextToDeliver < $total) {
            while ($nextToSend < $total && count($pendingIndexById) < $this->maxInFlight) {
                list($type, $payload) = call_user_func($makeRequest, $nextToSend);

                $id = $this->sendRequest($type, $payload);
                $pendingIndexById[$id] = $nextToSend;
                $nextToSend++;
            }

            list($responseType, $responseId, $responsePayload) = $this->receiveResponse();

            if (!isset($pendingIndexById[$responseId])) {
                $this->raise(sprintf('SFTP protocol desync: received response id %d with no matching outstanding request.', $responseId));
            }

            $index = $pendingIndexById[$responseId];
            unset($pendingIndexById[$responseId]);
            $buffered[$index] = array($responseType, $responsePayload);

            while (isset($buffered[$nextToDeliver])) {
                list($bufferedType, $bufferedPayload) = $buffered[$nextToDeliver];
                unset($buffered[$nextToDeliver]);

                call_user_func($onResponse, $nextToDeliver, $bufferedType, $bufferedPayload);

                $nextToDeliver++;
            }
        }
    }

    /**
     * request() + assert the response is SSH_FXP_STATUS with code
     * SSH_FX_OK. For the operations (REMOVE/MKDIR/RMDIR/RENAME/SETSTAT/
     * CLOSE/SYMLINK) where that is the only successful response shape.
     *
     * @param int $type
     * @param string $payload
     * @param string $context prefixed to the exception message on failure, e.g. "mkdir('/x')"
     *
     * @return void
     */
    public function expectStatusOk($type, $payload, $context)
    {
        list($responseType, $responsePayload) = $this->request($type, $payload);

        $this->assertStatusOk($responseType, $responsePayload, $context);
    }

    /**
     * request() + assert the response is SSH_FXP_HANDLE (the shape
     * SSH_FXP_OPEN/SSH_FXP_OPENDIR succeed with), mapping an
     * SSH_FXP_STATUS error response to an exception instead.
     *
     * @param int $type
     * @param string $payload
     * @param string $context
     *
     * @return string the opaque handle
     */
    public function expectHandle($type, $payload, $context)
    {
        list($responseType, $responsePayload) = $this->request($type, $payload);

        $this->raiseIfStatusError($responseType, $responsePayload, $context);

        if ($responseType !== SftpPacket::TYPE_HANDLE) {
            $this->raise($this->unexpectedTypeMessage($context, SftpPacket::TYPE_HANDLE, $responseType));
        }

        return SftpPacket::decodeHandle($responsePayload);
    }

    /**
     * request() + decode an SSH_FXP_NAME response (SSH_FXP_READDIR/
     * SSH_FXP_REALPATH). An SSH_FX_EOF status is READDIR's normal
     * end-of-directory signal, not an error, so it's returned as null
     * rather than raised.
     *
     * @param int $type
     * @param string $payload
     * @param string $context
     *
     * @return array|null list of array('filename', 'longname', 'attrs'), or null at SSH_FX_EOF
     */
    public function expectNameList($type, $payload, $context)
    {
        list($responseType, $responsePayload) = $this->request($type, $payload);

        if ($responseType === SftpPacket::TYPE_STATUS) {
            $status = SftpPacket::decodeStatus($responsePayload);

            if ($status['code'] === SftpPacket::STATUS_EOF) {
                return null;
            }

            $this->raiseStatus($context, $status);
        }

        if ($responseType !== SftpPacket::TYPE_NAME) {
            $this->raise($this->unexpectedTypeMessage($context, SftpPacket::TYPE_NAME, $responseType));
        }

        return SftpPacket::decodeNameList($responsePayload);
    }

    /**
     * request() + decode an SSH_FXP_ATTRS response (SSH_FXP_STAT/LSTAT/FSTAT).
     *
     * @param int $type
     * @param string $payload
     * @param string $context
     *
     * @return array
     */
    public function expectAttrs($type, $payload, $context)
    {
        list($responseType, $responsePayload) = $this->request($type, $payload);

        $this->raiseIfStatusError($responseType, $responsePayload, $context);

        if ($responseType !== SftpPacket::TYPE_ATTRS) {
            $this->raise($this->unexpectedTypeMessage($context, SftpPacket::TYPE_ATTRS, $responseType));
        }

        $offset = 0;

        return SftpPacket::decodeAttrs($responsePayload, $offset);
    }

    /**
     * Tear down the underlying transport. Safe to call more than once.
     *
     * @return void
     */
    public function disconnect()
    {
        $this->transport->close();
    }

    /**
     * @param int $responseType
     * @param string $responsePayload
     * @param string $context
     *
     * @return void
     */
    protected function assertStatusOk($responseType, $responsePayload, $context)
    {
        $this->raiseIfStatusError($responseType, $responsePayload, $context);

        if ($responseType !== SftpPacket::TYPE_STATUS) {
            $this->raise($this->unexpectedTypeMessage($context, SftpPacket::TYPE_STATUS, $responseType));
        }
    }

    /**
     * If $responseType is SSH_FXP_STATUS, raise unless its code is
     * SSH_FX_OK. No-op for any other response type (the caller checks
     * those against what it actually expected).
     *
     * @param int $responseType
     * @param string $responsePayload
     * @param string $context
     *
     * @return void
     */
    protected function raiseIfStatusError($responseType, $responsePayload, $context)
    {
        if ($responseType !== SftpPacket::TYPE_STATUS) {
            return;
        }

        $status = SftpPacket::decodeStatus($responsePayload);

        if ($status['code'] !== SftpPacket::STATUS_OK) {
            $this->raiseStatus($context, $status);
        }
    }

    /**
     * @param string $context
     * @param array $status array('code', 'message', 'language') from SftpPacket::decodeStatus()
     *
     * @return void raises; never returns
     */
    protected function raiseStatus($context, array $status)
    {
        $message = $status['message'] !== '' ? $status['message'] : $this->statusCodeName($status['code']);

        $this->raise(sprintf('%s: %s', $context, $message), $status['code']);
    }

    /**
     * @param int $code one of the SftpPacket::STATUS_* constants
     *
     * @return string
     */
    protected function statusCodeName($code)
    {
        $names = array(
            SftpPacket::STATUS_OK => 'OK',
            SftpPacket::STATUS_EOF => 'End of file',
            SftpPacket::STATUS_NO_SUCH_FILE => 'No such file',
            SftpPacket::STATUS_PERMISSION_DENIED => 'Permission denied',
            SftpPacket::STATUS_FAILURE => 'Failure',
            SftpPacket::STATUS_BAD_MESSAGE => 'Bad message',
            SftpPacket::STATUS_NO_CONNECTION => 'No connection',
            SftpPacket::STATUS_CONNECTION_LOST => 'Connection lost',
            SftpPacket::STATUS_OP_UNSUPPORTED => 'Operation unsupported',
        );

        return isset($names[$code]) ? $names[$code] : sprintf('Unknown SFTP status code %d', $code);
    }

    /**
     * @param string $context
     * @param int $expectedType
     * @param int $actualType
     *
     * @return string
     */
    protected function unexpectedTypeMessage($context, $expectedType, $actualType)
    {
        return sprintf('%s: expected packet type %d, got packet type %d.', $context, $expectedType, $actualType);
    }

    /**
     * Read one length-prefixed frame off the transport: 4 bytes of length,
     * then that many bytes of type+payload. Transport I/O failures are
     * caught here and re-raised through the exception factory (with
     * whatever the peer has written to stderr folded in, if any) so every
     * failure mode of using this channel surfaces as the same exception
     * type, not a mix of SshException and component-specific exceptions.
     *
     * @return string the frame body (type byte + payload)
     */
    protected function readFrame()
    {
        try {
            $length = SftpPacket::bytesToUint32($this->transport->read(4));

            return $this->transport->read($length);
        } catch (\Exception $e) {
            $this->raise('SFTP channel read failed: ' . $e->getMessage() . $this->describeStderr());
        }
    }

    /**
     * @param string $frame
     *
     * @return void
     */
    protected function writeFrame($frame)
    {
        try {
            $this->transport->write($frame);
        } catch (\Exception $e) {
            $this->raise('SFTP channel write failed: ' . $e->getMessage() . $this->describeStderr());
        }
    }

    /**
     * @return string ' (stderr: ...)' if the peer has written anything to stderr, else ''
     */
    protected function describeStderr()
    {
        $stderr = trim($this->transport->errorOutput());

        return $stderr !== '' ? ' (stderr: ' . $stderr . ')' : '';
    }

    /**
     * @param string $message
     * @param int $code
     *
     * @return void raises; never returns
     */
    protected function raise($message, $code = 0)
    {
        throw call_user_func($this->exceptionFactory, $message, $code);
    }
}
