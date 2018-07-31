<?php 

/**
*  Corresponding Class to test HuffmanNode class
*
*  @author TriggerHappy
*/

class HuffmanNodeTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\Compression\HuffmanNode();
        $this->assertTrue(is_object($var));
        unset($var);
    }
}