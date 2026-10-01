<?php

namespace Wilkques\Ssh\Tests\Support;

/**
 * A stream wrapper that behaves like a contended OS pipe: each
 * stream_read() hands back at most $chunkSize bytes regardless of how many
 * were requested, and each stream_write() accepts at most $chunkSize bytes
 * of what it's given. Real pipes can do exactly this — a single fread()/
 * fwrite() returning less than asked is routine, not an error — but it's
 * not something you can reliably force to happen on demand with a real
 * proc_open pipe in a test. This makes it deterministic, so
 * ProcessTransport's read-exactly-N / write-until-done loops can be proven
 * correct instead of just trusted.
 *
 * Usage:
 *   ChunkedStream::register(1);           // force 1-byte-at-a-time I/O
 *   ChunkedStream::seed('stdout', $bytes); // pre-fill a buffer to read from
 *   $stdout = fopen('chunked://stdout', 'r');
 *   $stdin  = fopen('chunked://stdin', 'w'); // writes accumulate, inspect via ChunkedStream::buffer('stdin')
 *   ...
 *   ChunkedStream::unregister();          // tear down in tearDown()
 */
class ChunkedStream
{
    /** @var array<string,string> */
    protected static $buffers = array();

    /** @var bool */
    protected static $registered = false;

    /** @var int */
    protected static $chunkSize = 1;

    /** @var resource */
    public $context;

    /** @var string */
    protected $key;

    /** @var int */
    protected $position = 0;

    /**
     * @param int $chunkSize max bytes any single stream_read()/stream_write() call will move
     *
     * @return void
     */
    public static function register($chunkSize = 1)
    {
        self::$chunkSize = $chunkSize;

        if (!self::$registered) {
            stream_wrapper_register('chunked', __CLASS__);
            self::$registered = true;
        }
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        if (self::$registered) {
            stream_wrapper_unregister('chunked');
            self::$registered = false;
        }

        self::$buffers = array();
    }

    /**
     * Pre-fill a named buffer for a stream to read from.
     *
     * @param string $key
     * @param string $bytes
     *
     * @return void
     */
    public static function seed($key, $bytes)
    {
        self::$buffers[$key] = $bytes;
    }

    /**
     * @param string $key
     *
     * @return string everything written to (or seeded into and not yet read from) this buffer
     */
    public static function buffer($key)
    {
        return isset(self::$buffers[$key]) ? self::$buffers[$key] : '';
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
        $this->key = substr($path, strlen('chunked://'));

        if (!isset(self::$buffers[$this->key])) {
            self::$buffers[$this->key] = '';
        }

        return true;
    }

    /**
     * @param int $count
     *
     * @return string
     */
    public function stream_read($count)
    {
        $remaining = substr(self::$buffers[$this->key], $this->position);

        if ($remaining === '') {
            return '';
        }

        $chunk = substr($remaining, 0, min($count, self::$chunkSize));
        $this->position += strlen($chunk);

        return $chunk;
    }

    /**
     * @param string $data
     *
     * @return int
     */
    public function stream_write($data)
    {
        $chunk = substr($data, 0, self::$chunkSize);

        self::$buffers[$this->key] .= $chunk;

        return strlen($chunk);
    }

    /**
     * @return bool
     */
    public function stream_eof()
    {
        return $this->position >= strlen(self::$buffers[$this->key]);
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

    /**
     * @param int $option
     * @param int $arg1
     * @param int $arg2
     *
     * @return bool
     */
    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }
}
