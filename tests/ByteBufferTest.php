<?php 

/**
*  Corresponding Class to test ByteBuffer class
*
*  @author TriggerHappy
*/

class ByteBufferTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\Stream\ByteBuffer(pack("s", 123));
        $this->assertTrue(is_object($var));
        unset($var);
    }

    public function testRead()
    {
        $var = new TriggerHappy\MPQ\Stream\ByteBuffer(pack("s", 123));
        $this->assertTrue($var->getShort() == 123);
        unset($var);
    }
}