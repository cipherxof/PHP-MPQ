<?php

class WC3Map
{
    private $name;
    private $flags;
    private $maxPlayers;

    function __construct($name, $flags, $players) 
    {
        $this->name=$name;
        $this->flags=$flags;
        $this->maxPlayers=$players;
    }

    function getName(){ return $this->name; }
    function getAuthor(){ return ''; }
    function getDescription(){ return '';}
    function getPlayerCount(){ return $this->maxPlayers; }
    function getFlags(){ return $this->flags; }
}

class SC2Map
{
    private $author;
    private $name;
    private $description;
    private $shortDescription;

    private $verMajor;
    private $build;
    private $gameLen;
    private $versionString;

    function __construct() 
    {
    }

    function getAuthor(){ return $this->author; }
    function getName(){ return $this->name; }
    function getDescription(){ return $this->description; }
    function getShortDescription(){ return $this->shortDescription; }
    function getVersionString(){ return $this->versionString; }

    function storeSerializedData($data)
    {
        $this->verMajor = $data[1][1];
        $this->build = $data[1][4];
        $this->gameLen = ceil($data[3] / 16);
        $this->versionString = sprintf("%d.%d.%d.%d",$this->verMajor, $data[1][2], $data[1][3], $this->build);
    }

    function parseDocumentHeader($string) {
        $num_byte = 44; // skip header and unknown stuff
        $num_deps = MPQReader::byte($string,$num_byte); // uncertain that this is the number of dependencies, might also be uint32 if it is
        $num_byte += 3;

        while ($num_deps > 0) {
            while (MPQReader::byte($string,$num_byte) !== 0);
            $num_deps--;
        }

        $num_attribs = MPQReader::UInt32($string,$num_byte);
        $attribs = array();

        while ($num_attribs > 0) {
            $keyLen = MPQReader::UInt16($string,$num_byte);
            $key = MPQReader::bytes($string,$num_byte,$keyLen);
            $num_byte += 4; // always seems to be followed by ascii SUne
            $value_len = MPQReader::UInt16($string,$num_byte);
            $value = MPQReader::bytes($string,$num_byte,$value_len);
            $attribs[$key] = $value;
            $num_attribs--;
        }

        $this->author = (!empty($attribs["DocInfo/Author"]) ? $attribs["DocInfo/Author"] : '');
        $this->name = (!empty($attribs["DocInfo/Name"]) ? $attribs["DocInfo/Name"] : '');
        $this->description = (!empty($attribs["DocInfo/DescLong"]) ? $attribs["DocInfo/DescLong"] : '');
        $this->shortDescription = (!empty($attribs["DocInfo/DescShort"])? $attribs["DocInfo/DescShort"] : '');

        return (!empty($this->author) || !empty($this->name) || !empty($this->description) || !empty($this->shortDescription));
    }

    static function parseSerializedData($string, &$num_byte) 
    {
        $data_type = MPQReader::byte($string,$numByte);

        switch ($data_type) 
        {
            case 0x02: // binary data
                $data_len = MPQReader::VLFNumber($string,$num_byte);
                return MPQReader::bytes($string,$num_byte,$data_len);
                break;
            case 0x04: // simple array
                $array = array();
                $num_byte += 2; // skip 01 00
                $num_elements = MPQReader::VLFNumber($string,$num_byte);

                while ($num_elements > 0) 
                {
                    $array[] = self::parseSerializedData($string,$num_byte);
                    $num_elements--;
                }

                return $array;

                break;
            case 0x05: // array with keys
                $array = array();
                $num_elements = MPQReader::VLFNumber($string,$num_byte);

                while ($numElements > 0) 
                {
                    $index = MPQReader::VLFNumber($string,$num_byte);
                    $array[$index] = self::parseSerializedData($string,$num_byte);
                    $num_elements--;
                }          

                return $array;
                
                break;
            case 0x06: // number of one byte
                return MPQReader::byte($string,$num_byte);
                break;
            case 0x07: // number of four bytes
                return MPQReader::UInt32($string,$num_byte);
                break;
            case 0x09: // number in VLF
                return MPQReader::VLFNumber($string,$num_byte);
                break;
            default:
                return false;
        }
    }
}

?>