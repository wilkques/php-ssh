<?php

namespace Wilkques\Ssh\Tests\Support;

/**
 * A stream wrapper that behaves like an already-broken pipe: every write is
 * refused (stream_write() returns 0, as a real closed pipe's write(2)
 * eventually does) and the stream reports EOF immediately. Used to
 * deterministically test ProcessTransport::write()'s failure path, which a
 * real pipe can't be coerced into hitting on demand.
 */
class BrokenPipeStream
{
    /** @var bool */
    protected static $registered = false;

    /** @var resource */
    public $context;

    /**
     * @return void
     */
    public static function register()
    {
        if (!self::$registered) {
            stream_wrapper_register('broken-pipe', __CLASS__);
            self::$registered = true;
        }
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        if (self::$registered) {
            stream_wrapper_unregister('broken-pipe');
            self::$registered = false;
        }
    }

    /**
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string|null $openedPath
     *
     * @return bool
     */
    public function stream_open($path, $mode, $options, &$openedPath)
    {
        return true;
    }

    /**
     * @param string $data
     *
     * @return int always 0 — nothing is ever accepted
     */
    public function stream_write($data)
    {
        return 0;
    }

    /**
     * @param int $count
     *
     * @return string always empty — nothing is ever available
     */
    public function stream_read($count)
    {
        return '';
    }

    /**
     * @return bool always true — the peer is gone
     */
    public function stream_eof()
    {
        return true;
    }

    /**
     * @return array
     */
    public function stream_stat()
    {
        return array();
    }

    /**
     * @return void
     */
    public function stream_close()
    {
    }
}
