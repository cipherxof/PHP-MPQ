<?php 

/**
*  Corresponding Class to test ByteBuffer class
*
*  @author TriggerHappy
*/

class DebuggerTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\Debugger(null);
        $this->assertTrue(is_object($var));
        unset($var);
    }
}