<?php 

/**
*  Corresponding Class to test MPQArchive class
*
*  @author TriggerHappy
*/

class MPQArchiveTest extends PHPUnit_Framework_TestCase
{
    public function testForSyntaxError()
    {
        $var = new TriggerHappy\MPQ\MPQArchive("tests\\maps\\wavTest.w3x");
        $this->assertTrue(is_object($var));
        unset($var);
    }

    public function testExtractAudio()
    {
        $var = new TriggerHappy\MPQ\MPQArchive("tests\\maps\\wavTest.w3x");
        $this->assertTrue($var->readFile("Abilities\\Spells\\NightElf\\ReviveNightElf\\ReviveNightElf.wav") !== false);
        unset($var);
    }
}