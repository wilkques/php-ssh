<?php

namespace Wilkques\Ssh\Tests\Support;

use Wilkques\Ssh\Support\ProgressReporter;

/**
 * ProgressReporter with now() replaced by a controllable fake clock, so
 * ProgressReporterTest can exercise time-based throttling deterministically
 * instead of depending on real elapsed wall-clock time (sleep()s would make
 * the suite slow and occasionally flaky under load).
 */
class FakeClockProgressReporter extends ProgressReporter
{
    /** @var float */
    protected $fakeNow = 0.0;

    /**
     * @param float $value
     *
     * @return static
     */
    public function setFakeNow($value)
    {
        $this->fakeNow = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function now()
    {
        return $this->fakeNow;
    }
}
