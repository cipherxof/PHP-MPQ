<?php

class SC2Map extends MPQArchive
{
    private $author;
    private $name;
    private $description;
    private $shortDescription;

    private $verMajor;
    private $build;
    private $gameLen;
    private $versionString;

    private $playerCount;

    protected $archive;

    function __construct($mpq) 
    {
        if (!is_a($mpq, "MPQArchive"))
            throw new MPQException("__construct in the WC3Map class must use a valid MPQArchive handle.");

        $this->archive = $mpq;
    }

    public function getAuthor(){ return $this->author; }
    public function getName(){ return $this->name; }
    public function getDescription(){ return $this->description; }
    public function getShortDescription(){ return $this->shortDescription; }
    public function getVersionString(){ return $this->versionString; }
    public function getPlayerCount(){ return $this->playerCount; }

    public function parseData()
    {
        if ($this->archive->hasFile("DocumentHeader"))
        {
            $file = $this->archive->readFile("DocumentHeader");
            return strlen($file) > 0 && $this->parseDocumentHeader($file);
        }

        return false;
    }

    protected function storeSerializedData($data)
    {
        $this->verMajor = $data[1][1];
        $this->build = $data[1][4];
        $this->gameLen = ceil($data[3] / 16);
        $this->versionString = sprintf("%d.%d.%d.%d",$this->verMajor, $data[1][2], $data[1][3], $this->build);
    }

    protected function parseDocumentHeader($string) {
        $num_byte = 44; // skip header and unknown stuff
        $num_deps = MPQReader::byte_str($string,$num_byte); // uncertain that this is the number of dependencies, might also be uint32 if it is
        $num_byte += 3;

        while ($num_deps > 0) 
        {
            while (MPQReader::byte_str($string,$num_byte) !== 0);
            $num_deps--;
        }

        $num_attribs = MPQReader::UInt32_str($string,$num_byte);
        $attribs = array();

        while ($num_attribs > 0) 
        {
            $keyLen = MPQReader::UInt16_str($string,$num_byte);
            $key = MPQReader::bytes_str($string,$num_byte,$keyLen);
            $num_byte += 4; // always seems to be followed by ascii SUne
            $value_len = MPQReader::UInt16_str($string,$num_byte);
            $value = MPQReader::bytes_str($string,$num_byte,$value_len);
            $attribs[$key] = $value;
            $num_attribs--;
        }


        $this->author = (!empty($attribs["DocInfo/Author"]) ? $attribs["DocInfo/Author"] : '');
        $this->name = (!empty($attribs["DocInfo/Name"]) ? $attribs["DocInfo/Name"] : '');
        $this->description = (!empty($attribs["DocInfo/DescLong"]) ? $attribs["DocInfo/DescLong"] : '');
        $this->shortDescription = (!empty($attribs["DocInfo/DescShort"])? $attribs["DocInfo/DescShort"] : '');

        // count players
        $this->playerCount = 0;
        for($i=0; $i<20; $i++)
        {
            if ($i < 10)
                $id = "0$i";
            else
                $id = "$i";

            if (isset($attribs["MapInfo/Player$id/Name"]))
                $this->playerCount++;
        }

        return (!empty($this->author) || !empty($this->name) || !empty($this->description) || !empty($this->shortDescription));
    }

    protected static function parseSerializedData($string, &$num_byte) 
    {
        $data_type = MPQReader::byte_str($string,$numByte);

        switch ($data_type) 
        {
            case 0x02: // binary data
                $data_len = MPQReader::VLFNumber($string,$num_byte);
                return MPQReader::bytes_str($string,$num_byte,$data_len);
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
                return MPQReader::byte_str($string,$num_byte);
                break;
            case 0x07: // number of four bytes
                return MPQReader::UInt32_str($string,$num_byte);
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