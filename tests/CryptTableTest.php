<?php 

/**
*  Corresponding Class to test ByteBuffer class
*
*  @author TriggerHappy
*/

class CryptTableTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\CryptTable();
        $this->assertTrue(is_object($var));
        unset($var);
    }
}