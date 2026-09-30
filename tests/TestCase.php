<?php

namespace Wilkques\Ssh\Tests;

// Whether TestCase::setUp()/tearDown() must repeat a ": void" return type
// depends on the PHPUnit version composer resolved for the PHP version
// this suite happens to be running under (see tests/bootstrap.php) —
// that's compile-time syntax, so it has to be two actual files, not a
// runtime branch inside one.
if (WILKQUES_SSH_TESTS_SETUP_NEEDS_VOID) {
    require __DIR__ . '/Compat/TestCaseWithVoid.php';
} else {
    require __DIR__ . '/Compat/TestCaseWithoutVoid.php';
}
