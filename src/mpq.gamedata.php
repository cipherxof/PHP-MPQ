<?php

class WC3Map
{
    private $name;
    private $flags;
    private $maxplayers;

    function __construct($name, $flags, $players) 
    {
        $this->name=$name;
        $this->flags=$flags;
        $this->maxplayers=$players;
    }

    function getName(){ return $this->name; }
    function getPlayerCount(){ return $this->maxplayers; }
    function getFlags(){ return $this->flags; }
}

class SC2Map
{
    private $verMajor;
    private $build;
    private $gameLen;
    private $versionString;

    function __construct($data) 
    {
        $this->verMajor = $data[1][1];
        $this->build = $data[1][4];
        $this->gameLen = ceil($data[3] / 16);
        $this->versionString = sprintf("%d.%d.%d.%d",$this->verMajor, $data[1][2], $data[1][3], $this->build);
    }

    function getVersionString(){ return $this->versionString; }

    static function parseSerializedData($string, &$numByte) 
    {
        $dataType = MPQReader::byte($string,$numByte);
        switch ($dataType) 
        {
            case 0x02: // binary data
                $dataLen = MPQReader::VLFNumber($string,$numByte);
                return MPQReader::bytes($string,$numByte,$dataLen);
                break;
            case 0x04: // simple array
                $array = array();
                $numByte += 2; // skip 01 00
                $numElements = MPQReader::VLFNumber($string,$numByte);
                while ($numElements > 0) 
                {
                    $array[] = self::parseSerializedData($string,$numByte);
                    $numElements--;
                }
                return $array;
                break;
            case 0x05: // array with keys
                $array = array();
                $numElements = MPQReader::VLFNumber($string,$numByte);
                while ($numElements > 0) 
                {
                    $index = MPQReader::VLFNumber($string,$numByte);
                    $array[$index] = self::parseSerializedData($string,$numByte);
                    $numElements--;
                }               
                return $array;
                break;
            case 0x06: // number of one byte
                return MPQReader::byte($string,$numByte);
                break;
            case 0x07: // number of four bytes
                return MPQReader::UInt32($string,$numByte);
                break;
            case 0x09: // number in VLF
                return MPQReader::VLFNumber($string,$numByte);
                break;
            default:
                return false;
        }
    }
}

?>