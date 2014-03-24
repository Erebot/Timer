<?php
/*
    This file is part of Erebot, a modular IRC bot written in PHP.

    Copyright © 2010 François Poirotte

    Erebot is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Erebot is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Erebot.  If not, see <http://www.gnu.org/licenses/>.
*/

namespace Erebot;

/**
 * \brief
 *      An implementation of timers.
 */
class Timer implements \Erebot\TimerInterface
{
    /// A file descriptor which is used to implement timers.
    protected $handle;

    /// Internal resource used to implement timers.
    protected $resource;

    /// Function or method to call when the timer expires.
    protected $callback;

    /// Delay after which the timer will expire.
    protected $delay;

    /// Number of times the timer will be reset.
    protected $repeat;

    /// Additional arguments to call the callback function with.
    protected $args;

    /// Path to the PHP binary to use to launch timers.
    static protected $binary = null;

    /// Activate a special strategy for Windows.
    static protected $windowsStrategy = 0;

    /**
     * Creates a new timer, set off to call the given callback
     * (optionally, repeatedly) when the associated delay passed.
     *
     * \param callback $callback
     *      The callback to call when the timer expires.
     *      See http://php.net/manual/en/language.pseudo-types.php
     *      for acceptable callback values.
     *
     * \param number $delay
     *      The number of seconds to wait for before calling the
     *      callback. This may be a float/double or an int, but
     *      the implementation may choose to round it up to the
     *      nearest integer if sub-second precision is impossible
     *      to get (eg. on Windows).
     *
     * \param bool|int $repeat
     *      Either a boolean indicating whether the callback should
     *      be called repeatedly every $delay seconds or just once,
     *      or an integer specifying the exact number of times the
     *      callback will be called.
     *
     * \param array $args
     *      (optional) Additional arguments to pass to the callback
     *      when it is called.
     */
    public function __construct(
        callable $callback,
        $delay,
        $repeat,
        $args = array()
    ) {
        if (self::$binary === null) {
            if (defined('PHP_BINARY')) {
                $binary = PHP_BINARY;
            } else {
                $binary = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' .
                          ((!strncasecmp(PHP_OS, 'WIN', 3)) ? '.exe' : '');
            }

            if (!strncasecmp(PHP_OS, 'WIN', 3)) {
                self::$windowsStrategy = 1 + (
                    (int) version_compare(PHP_VERSION, '5.3.0', '>=')
                );
            }
            self::$binary = $binary;
        }

        $this->delay    = $delay;
        $this->handle   = null;
        $this->resource = null;
        $this->setCallback($callback);
        $this->setRepetition($repeat);
        $this->setArgs($args);
    }

    /// Destroys the timer.
    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Performs cleanup duties so that no traces
     * of this timer having ever been used remain.
     */
    protected function cleanup()
    {
        if ($this->resource) {
            proc_terminate($this->resource);
        }

        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle   = null;
        $this->resource = null;
    }

    public function setCallback(callable $callback)
    {
        $this->callback = $callback;
    }

    public function getCallback()
    {
        return $this->callback;
    }

    public function setArgs(array $args)
    {
        $this->args = $args;
    }

    public function getArgs()
    {
        return $this->args;
    }

    public function getDelay()
    {
        return $this->delay;
    }

    public function getRepetition()
    {
        return $this->repeat;
    }

    public function setRepetition($repeat)
    {
        // If repeat = false, then repeat = 1 (once)
        // If repeat = true, then repeat = -1 (forever)
        if (is_bool($repeat)) {
            $repeat = (-intval($repeat)) * 2 + 1;
        }

        // If repeat = null, return current value with no modification.
        // If repeat > 0, the timer will be triggered 'repeat' times.
        if (!is_int($repeat) && $repeat !== null) {
            throw new \InvalidArgumentException('Invalid repetition');
        }

        $this->repeat = $repeat;
    }

    public function getStream()
    {
        return $this->handle;
    }

    public function reset()
    {
        if ($this->repeat > 0) {
            $this->repeat--;
        } elseif (!$this->repeat) {
            return false;
        }

        $this->cleanup();

        if (self::$windowsStrategy == 1) {
            // We create a temporary file to which the subprocess will write to.
            // This makes it possible to wait for the delay to pass by using
            // select() on this file descriptor.
            // Simpler approaches don't work on Windows because the underlying
            // php_select() implementation doesn't seem to support pipes.
            // Note:    this does not work anymore (tested with PHP 5.3.16),
            //          hence the second strategy below (for PHP >= 5.3.0).
            $this->handle = tmpfile();
            $descriptors = $this->handle;
        } elseif (self::$windowsStrategy == 2) {
            // Create a pair of interconnected sockets to implement the timer.
            // Windows' firewall will throw a popup (once),
            // but it's still better than no timers at all!
            $pair           = stream_socket_pair(
                STREAM_PF_INET,
                STREAM_SOCK_STREAM,
                0
            );
            $descriptors    = $pair[0];
            $this->handle   = $pair[1];
        } else {
            // On other OSes, we just use a pipe to communicate.
            $descriptors = array('pipe', 'w');
        }

        // Build the command that will be executed by the subprocess.
        $command = self::$binary . ' -n -d detect_unicode=Off ' .
            '-d display_errors=Off -d display_startup_errors=Off ' .
            '-r "usleep('. ((int) ($this->delay * 1000000)). '); ' .
            'var_dump(42); ' .  // Required to make the subprocess send
                                // a completion notification back to us.
            // We add the name of the callback (useful when debugging).
            '// '.addslashes($this->callback).'"';

        $this->resource = proc_open(
            $command,
            array(1 => $descriptors),
            $pipes,
            null,
            null,
            array('bypass_shell' => true)
        );

        if (self::$windowsStrategy == 1) {
            // Required to remove the "read-ready" flag from the fd.
            // The call will always return false since no data has
            // been written to the temporary file yet.
            fgets($this->handle);
        } elseif (self::$windowsStrategy == 2) {
            // Close the second socket as we have no real use for it.
            fclose($pair[0]);
        } else {
            $this->handle = $pipes[1];
        }

        return true;
    }

    public function activate()
    {
        $this->cleanup();
        $args = array_merge(array(&$this), $this->args);
        return (bool) $this->callback->invokeArgs($args);
    }
}
