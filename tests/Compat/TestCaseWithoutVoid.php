<?php

namespace Wilkques\Ssh\Tests;

use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @var string
     */
    protected $tmpDir;

    protected function setUp()
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/wilkques-ssh-tests-' . uniqid();

        mkdir($this->tmpDir, 0777, true);

        $this->additionalSetUp();
    }

    protected function tearDown()
    {
        $this->additionalTearDown();

        $this->removeDirectory($this->tmpDir);

        if (class_exists('Mockery')) {
            Mockery::close();
        }

        parent::tearDown();
    }

    /**
     * Hook for subclasses that need extra per-test setup. Deliberately NOT
     * named setUp(): PHPUnit enforces return-type covariance on setUp()/
     * tearDown() overrides, and that return type is compile-time syntax
     * that differs across the PHPUnit versions this suite runs under (see
     * tests/bootstrap.php) — a plain, un-typed hook method has no such
     * constraint.
     */
    protected function additionalSetUp()
    {
    }

    /**
     * @see additionalSetUp()
     */
    protected function additionalTearDown()
    {
    }

    /**
     * Recursively delete a directory tree.
     *
     * @param string $dir
     *
     * @return void
     */
    protected function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \FilesystemIterator($dir);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * Read a protected/private property off of an object (or a static
     * property off of a class) via reflection.
     *
     * @param object|string $objectOrClass
     * @param string        $property
     *
     * @return mixed
     */
    protected function peek($objectOrClass, $property)
    {
        $reflection = new \ReflectionClass($objectOrClass);

        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        if (is_object($objectOrClass)) {
            return $prop->getValue($objectOrClass);
        }

        return $prop->getValue();
    }

    /**
     * PHPUnit assertion/expectation method names that have been renamed
     * across the major versions this suite runs under (see
     * tests/bootstrap.php re: "phpunit/phpunit": "*").
     *
     * @param string $class
     *
     * @return void
     */
    protected function expectExceptionCompat($class)
    {
        if (method_exists($this, 'expectException')) {
            // PHPUnit >= 5.2
            $this->expectException($class);

            return;
        }

        // PHPUnit 4.x: no expectException() at all.
        $this->setExpectedException($class);
    }
}
