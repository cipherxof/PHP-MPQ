<?php 

/**
*  Corresponding Class to test ADPCM class
*
*  @author TriggerHappy
*/

class ADPCMTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\Compression\ADPCM(2);
        $this->assertTrue(is_object($var));
        unset($var);
    }
}