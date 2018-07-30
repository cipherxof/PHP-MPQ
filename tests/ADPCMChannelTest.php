<?php 

/**
*  Corresponding Class to test ByteBuffer class
*
*  @author TriggerHappy
*/

class ADPCMChannelTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\ADPCMChannel();
        $this->assertTrue(is_object($var));
        unset($var);
    }
}