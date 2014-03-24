<?php
/*
    This file is part of Erebot.

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

class TimerTest extends PHPUnit_Framework_TestCase
{
    private $delay = 2.5;
    private $min   = 2.5;
    private $max   = 10.0;
    private $timer;

    protected $flag;

    public function helper(\Erebot\Timer $timer, $foo, $bar)
    {
        $this->assertNotEquals('bar', $foo);
        $this->assertEquals('bar', $bar);
        $this->flag = TRUE;
    }

    /**
     * Nominal case for timers.
     *
     * We create a timer set to go off twice with a delay of 2.5 seconds.
     * We check that each parameter is correctly set before each run.
     *
     * @covers \Erebot\Timer::reset
     * @covers \Erebot\Timer::getStream
     * @covers \Erebot\Timer::activate
     * @covers \Erebot\Timer::__construct
     * @covers \Erebot\Timer::__destruct
     */
    public function testNominalCase()
    {
        $this->timer = new \Erebot\Timer(
            new \Erebot\CallableWrapper(array($this, 'helper')),
            $this->delay,
            2,
            array('foo', 'bar')
        );

        // Do a first pass : after that,
        // we expect exactly 1 repetition left.
        $this->check();
        $this->assertEquals(1, $this->timer->getRepetition());

        // Do a second pass : after that,
        // we expect exactly 0 repetitions left.
        $this->check();
        $this->assertEquals(0, $this->timer->getRepetition());

        // Trying to reset the timer MUST fail
        // because there are no more repetitions left.
        $this->assertFalse($this->timer->reset());
    }

    protected function check()
    {
        $this->flag = FALSE;

        // Resetting the timer decrements
        // the number of repetitions.
        $this->assertTrue($this->timer->reset());

        // Do the actual select() and do a rough check
        // on the duration it took to complete it.
        $start = microtime(TRUE);
        list($nb, $read) = self::_select();
        $duration = microtime(TRUE) - $start;
        $this->assertGreaterThanOrEqual($this->min, $duration);
        $this->assertLessThanOrEqual($this->max, $duration);

        // Make sure select() returned exactly
        // one stream : our timer.
        $this->assertEquals(1, $nb);
        $this->assertSame($this->timer->getStream(), $read);

        // The flag MUST NOT be set before the timer
        // has been activated, but MUST be set afterward.
        $this->assertFalse($this->flag);
        $this->timer->activate();
        $this->assertTrue($this->flag);
    }

    protected function _select()
    {
        $start  = microtime(TRUE);
        $stream = $this->timer->getStream();
        do {
            $read = array($stream);
            $null = array();
            $wait = $this->max - (microtime(TRUE) - $start);
            if ($wait <= 0)
                return array(0, NULL);

            /** The silencer is required to avoid PHPUnit choking
             *  when the syscall is interrupted by a signal and
             *  this function displays a warning as a result. */
            $nb   = @stream_select(
                $read,
                $null,
                $null,
                intval($wait),
                ((int) ($wait * 100000)) % 100000
            );
        } while ($nb === FALSE);
        if (!$nb)
            return array(0, NULL);
        return array($nb, $read[0]);
    }
}
