<?php 

/**
*  Corresponding Class to test MPQArchive class
*
*  @author TriggerHappy
*/

class MPQReaderTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\MPQReader("");
        $this->assertTrue(is_object($var));
        unset($var);
    }
}