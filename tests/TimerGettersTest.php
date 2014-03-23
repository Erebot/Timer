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

class TimerGettersTest extends PHPUnit_Framework_TestCase
{
    protected $flag;

    public function helper(\Erebot\Timer\Timer $timer, $foo, $bar)
    {
        $this->assertNotEquals('bar', $foo);
        $this->assertEquals('bar', $bar);
        $this->flag = TRUE;
    }

    /**
     * @covers \Erebot\Timer\Timer::getArgs
     * @covers \Erebot\Timer\Timer::getCallback
     * @covers \Erebot\Timer\Timer::getDelay
     * @covers \Erebot\Timer\Timer::getRepetition
     * @covers \Erebot\Timer\Timer::__construct
     * @covers \Erebot\Timer\Timer::__destruct
     */
    public function testGetters()
    {
        $callback   = new \Erebot\CallableWrapper\Main(array($this, 'helper'));
        $args       = array('foo', 'bar');
        $timer      = new \Erebot\Timer\Timer($callback, 4.2, 42, $args);
        $this->assertEquals($args, $timer->getArgs());
        $this->assertEquals($callback, $timer->getCallback());
        $this->assertEquals(4.2, $timer->getDelay());
        $this->assertEquals(42, $timer->getRepetition());
    }

    /**
     * @covers \Erebot\Timer\Timer::setRepetition
     * @covers \Erebot\Timer\Timer::getRepetition
     */
    public function testRepetition()
    {
        $callback   = new \Erebot\CallableWrapper\Main(array($this, 'helper'));
        $args       = array('foo', 'bar');
        $timer      = new \Erebot\Timer\Timer($callback, 42, FALSE, $args);

        $this->assertEquals(1, $timer->getRepetition());
        $timer->setRepetition(TRUE);
        $this->assertEquals(-1, $timer->getRepetition());
        $timer->setRepetition(2);
        $this->assertEquals(2, $timer->getRepetition());
    }
}
