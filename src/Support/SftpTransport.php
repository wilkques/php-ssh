<?php

namespace Wilkques\Ssh\Support;

/**
 * Byte-oriented transport for SftpChannel's SFTP protocol traffic. The only
 * implementation used in production is ProcessTransport — a real
 * `ssh -s host sftp` subsystem over proc_open pipes. Tests use a fake
 * implementation fed canned bytes instead, so SftpChannel's protocol logic
 * (framing, request/response matching, pipelining, error mapping) can be
 * exercised without a process, a pipe, or a network.
 */
interface SftpTransport
{
    /**
     * Write every byte given. Implementations must loop on partial writes
     * themselves (a single fwrite() can write less than asked) and throw
     * on failure rather than returning a status the caller has to check.
     *
     * @param string $bytes
     *
     * @return void
     */
    public function write($bytes);

    /**
     * Read exactly $length bytes. Implementations must loop on short reads
     * themselves (a single fread() can return less than asked, which is
     * routine on a pipe) and throw if the peer closes before $length bytes
     * become available — callers never see a partial result.
     *
     * @param int $length
     *
     * @return string exactly $length bytes
     */
    public function read($length);

    /**
     * @return bool whether the peer has closed its end
     */
    public function isEof();

    /**
     * @return string whatever the peer has written to stderr so far,
     *                 for folding into exception messages
     */
    public function errorOutput();

    /**
     * Tear down the underlying process/connection. Safe to call more than
     * once.
     *
     * @return void
     */
    public function close();
}
