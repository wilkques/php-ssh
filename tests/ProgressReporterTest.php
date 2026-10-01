<?php

namespace Wilkques\Ssh\Tests;

use Wilkques\Ssh\Tests\Support\FakeClockProgressReporter;

class ProgressReporterTest extends TestCase
{
    /**
     * @param array $options
     * @param array|null $calls (by reference, out param) each call recorded as array($transferred, $total, $path)
     *
     * @return FakeClockProgressReporter
     */
    protected function makeReporter(array $options, &$calls)
    {
        $calls = array();

        // PHP 5.3 closures don't auto-bind $this, so this deliberately only
        // captures a plain local variable by reference — no $this-> calls.
        return new FakeClockProgressReporter(function ($transferred, $total, $path) use (&$calls) {
            $calls[] = array($transferred, $total, $path);
        }, $options);
    }

    public function testFirstReportAlwaysFires()
    {
        $reporter = $this->makeReporter(array(), $calls);
        $reporter->setFakeNow(100.0);

        $reporter->report(10, 100, '/remote/file');

        $this->assertSame(array(array(10, 100, '/remote/file')), $calls);
    }

    public function testReportIsSuppressedWithinTheThrottleWindow()
    {
        $reporter = $this->makeReporter(array('interval' => 1.0, 'minDelta' => 0.5), $calls);

        $reporter->setFakeNow(0.0);
        $reporter->report(1, 1000, '/x'); // first call always fires

        $reporter->setFakeNow(0.1); // well under the 1.0s interval
        $reporter->report(2, 1000, '/x'); // delta is 0.001, well under 0.5

        $this->assertCount(1, $calls);
    }

    public function testReportFiresOnceTheMinIntervalHasElapsed()
    {
        $reporter = $this->makeReporter(array('interval' => 1.0, 'minDelta' => 0.5), $calls);

        $reporter->setFakeNow(0.0);
        $reporter->report(1, 1000, '/x');

        $reporter->setFakeNow(1.5); // past the 1.0s interval, even though delta is tiny
        $reporter->report(2, 1000, '/x');

        $this->assertCount(2, $calls);
    }

    public function testReportFiresWhenDeltaExceedsMinDeltaEvenBeforeTheIntervalElapses()
    {
        $reporter = $this->makeReporter(array('interval' => 10.0, 'minDelta' => 0.1), $calls);

        $reporter->setFakeNow(0.0);
        $reporter->report(0, 1000, '/x');

        $reporter->setFakeNow(0.01); // interval nowhere near elapsed
        $reporter->report(500, 1000, '/x'); // but delta (0.5) is well past minDelta (0.1)

        $this->assertCount(2, $calls);
    }

    public function testCompletionAlwaysFiresRegardlessOfThrottle()
    {
        $reporter = $this->makeReporter(array('interval' => 1000.0, 'minDelta' => 1.0), $calls);

        $reporter->setFakeNow(0.0);
        $reporter->report(1, 100, '/x');

        $reporter->setFakeNow(0.001); // throttle window nowhere near satisfied
        $reporter->report(100, 100, '/x'); // but transferred === total

        $this->assertCount(2, $calls);
        $this->assertSame(array(100, 100, '/x'), $calls[1]);
    }

    /**
     * A download of a genuinely empty remote file reports $total === 0 —
     * that must count as "complete" immediately, not get stuck throttled
     * forever since delta-as-a-fraction-of-zero is undefined.
     */
    public function testZeroTotalIsAlwaysTreatedAsComplete()
    {
        $reporter = $this->makeReporter(array('interval' => 1000.0), $calls);

        $reporter->setFakeNow(0.0);
        $reporter->report(0, 0, '/empty');

        $reporter->setFakeNow(0.001);
        $reporter->report(0, 0, '/empty');

        $this->assertCount(2, $calls);
    }

    public function testResetAllowsAnImmediateReportAfterPreviousThrottling()
    {
        $reporter = $this->makeReporter(array('interval' => 1000.0, 'minDelta' => 1.0), $calls);

        $reporter->setFakeNow(0.0);
        $reporter->report(1, 100, '/x');

        $reporter->reset();

        $reporter->setFakeNow(0.001); // would be throttled if reset() hadn't cleared state
        $reporter->report(2, 100, '/x');

        $this->assertCount(2, $calls);
    }

    public function testDefaultOptionsThrottleByTimeOrOnePercent()
    {
        $reporter = $this->makeReporter(array(), $calls);

        $reporter->setFakeNow(0.0);
        $reporter->report(0, 1000, '/x');

        $reporter->setFakeNow(0.01); // well under the 0.2s default interval
        $reporter->report(1, 1000, '/x'); // 0.1% delta, under the 1% default

        $this->assertCount(1, $calls);

        $reporter->setFakeNow(0.01);
        $reporter->report(20, 1000, '/x'); // 2% delta, over the 1% default

        $this->assertCount(2, $calls);
    }
}
