<?php 

/**
*  Corresponding Class to test MPQEncryption class
*
*  @author TriggerHappy
*/

class MPQEncryptionTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\Encryption\MPQEncryption();
        $this->assertTrue(is_object($var));
        unset($var);
    }
}