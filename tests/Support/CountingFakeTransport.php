<?php

namespace Wilkques\Ssh\Tests\Support;

/**
 * A FakeTransport that can measure how many bytes a caller wrote before its
 * first read() call after startMeasuring() — used to prove a pipelined
 * caller never has more than its configured in-flight cap worth of
 * requests outstanding before consuming any response.
 */
class CountingFakeTransport extends FakeTransport
{
    /** @var bool */
    protected $measuring = false;

    /** @var int */
    protected $baselineSentLength = 0;

    /** @var int|null */
    protected $sentBytesSinceBaselineAtFirstRead;

    /**
     * Arm measurement starting from the current amount already written
     * (so a prior connect() handshake isn't counted).
     *
     * @return void
     */
    public function startMeasuring()
    {
        $this->measuring = true;
        $this->baselineSentLength = strlen($this->sentBytes());
        $this->sentBytesSinceBaselineAtFirstRead = null;
    }

    /**
     * {@inheritdoc}
     */
    public function read($length)
    {
        if ($this->measuring && $this->sentBytesSinceBaselineAtFirstRead === null) {
            $this->sentBytesSinceBaselineAtFirstRead = strlen($this->sentBytes()) - $this->baselineSentLength;
        }

        return parent::read($length);
    }

    /**
     * @return int|null bytes written since startMeasuring() as of the first read() call since, or null if none yet
     */
    public function sentBytesSinceBaselineAtFirstRead()
    {
        return $this->sentBytesSinceBaselineAtFirstRead;
    }
}
