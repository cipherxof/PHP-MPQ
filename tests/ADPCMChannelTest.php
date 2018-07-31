<?php 

/**
*  Corresponding Class to test DPCMChannel class
*
*  @author TriggerHappy
*/

class ADPCMChannelTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\Compression\ADPCMChannel();
        $this->assertTrue(is_object($var));
        unset($var);
    }
}