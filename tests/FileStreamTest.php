<?php 

/**
*  Corresponding Class to test FileStream class
*
*  @author TriggerHappy
*/

class FileStreamTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\Stream\FileStream("");
        $this->assertTrue(is_object($var));
        unset($var);
    }
}