<?php

namespace Wilkques\Ssh\Support;

/**
 * Holds a user-supplied progress callback plus throttle state, and decides
 * whether a given update is actually worth invoking it for.
 *
 * Deliberately decoupled from the transfer loop itself (Sftp::put()/get()):
 * the transfer's chunk size — how often there IS a new byte count to report
 * — stays independent of how often the user's callback actually fires. A
 * callback invoked once per 32KB chunk on a multi-GB transfer would make a
 * slow callback (writing to a DB, flushing to a browser, ...) dominate the
 * whole transfer's wall-clock time; throttling by both time and progress
 * delta means a fast transfer still reports often enough to look smooth,
 * and a slow one doesn't spam a callback that can't keep up.
 */
class ProgressReporter
{
    const DEFAULT_MIN_INTERVAL = 0.2;
    const DEFAULT_MIN_DELTA = 0.01;

    /** @var callable */
    protected $callback;

    /** @var float seconds between reports, at minimum */
    protected $minInterval;

    /** @var float fraction of $total (0..1) between reports, at minimum */
    protected $minDelta;

    /** @var float|null */
    protected $lastReportedAt;

    /** @var int|float */
    protected $lastReportedTransferred = 0;

    /**
     * @param callable $callback function($transferred, $total, $path)
     * @param array $options 'interval' => seconds (default 0.2), 'minDelta' => 0..1 fraction (default 0.01)
     */
    public function __construct($callback, array $options = array())
    {
        $this->callback = $callback;
        $this->minInterval = isset($options['interval']) ? $options['interval'] : self::DEFAULT_MIN_INTERVAL;
        $this->minDelta = isset($options['minDelta']) ? $options['minDelta'] : self::DEFAULT_MIN_DELTA;
    }

    /**
     * Report progress, invoking the callback only if enough time or enough
     * delta has passed since the last call that actually fired. Completion
     * ($transferred >= $total) always fires regardless of throttling, so a
     * caller can rely on the last report always being the final one.
     *
     * @param int|float $transferred
     * @param int|float $total
     * @param string $path
     *
     * @return void
     */
    public function report($transferred, $total, $path)
    {
        $isComplete = $total > 0 ? $transferred >= $total : true;

        if ($this->lastReportedAt !== null && !$isComplete) {
            $elapsed = $this->now() - $this->lastReportedAt;
            $delta = $total > 0 ? ($transferred - $this->lastReportedTransferred) / $total : 0;

            if ($elapsed < $this->minInterval && $delta < $this->minDelta) {
                return;
            }
        }

        $this->lastReportedAt = $this->now();
        $this->lastReportedTransferred = $transferred;

        call_user_func($this->callback, $transferred, $total, $path);
    }

    /**
     * Clears throttle state so the next report() call — the first one of a
     * fresh transfer — always fires immediately, rather than inheriting
     * stale timing from whatever this reporter last reported on.
     *
     * @return void
     */
    public function reset()
    {
        $this->lastReportedAt = null;
        $this->lastReportedTransferred = 0;
    }

    /**
     * Isolated into its own method (rather than calling microtime(true)
     * directly in report()) purely so tests can override it with a
     * controllable fake clock instead of depending on real elapsed time.
     *
     * @return float
     */
    protected function now()
    {
        return microtime(true);
    }
}
