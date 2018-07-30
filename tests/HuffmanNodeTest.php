<?php 

/**
*  Corresponding Class to test ByteBuffer class
*
*  @author TriggerHappy
*/

class HuffmanNodeTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\HuffmanNode();
        $this->assertTrue(is_object($var));
        unset($var);
    }
}