<?php 

/**
*  Corresponding Class to test Huffman class
*
*  @author TriggerHappy
*/

class HuffmanTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\Compression\Huffman();
        $this->assertTrue(is_object($var));
        unset($var);
    }
}