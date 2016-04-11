<?php

require __DIR__ . '/mpq.debugger.php';
require __DIR__ . '/mpq.reader.php';
require __DIR__ . '/mpq.crypto.php';
require __DIR__ . '/mpq.gamedata.php';

define("MPQ_HASH_TABLE_OFFSET", 0);
define("MPQ_HASH_NAME_A", 1);
define("MPQ_HASH_NAME_B", 2);
define("MPQ_HASH_FILE_KEY", 3);
define("MPQ_HASH_ENTRY_EMPTY", -1);
define("MPQ_HASH_ENTRY_DELETED", -2);

class MPQArchive 
{
    const TYPE_DEFAULT = 0;
    const TYPE_WC3MAP  = 1;
    const TYPE_SC2MAP  = 2;

    const FLAG_FILE       = 0x80000000;
    const FLAG_CHECKSUMS  = 0x04000000;
    const FLAG_DELETED    = 0x02000000;
    const FLAG_SINGLEUNIT = 0x01000000;
    const FLAG_HENCRYPTED = 0x00020000;
    const FLAG_ENCRYPTED  = 0x00010000;
    const FLAG_COMPRESSED = 0x00000200;
    const FLAG_IMPLODED   = 0x00000100;

    private $filename;
    private $fp;
    private $type = self::TYPE_DEFAULT;

    private $initialized = false;
    private $map = null;

    private $fileData;
    private $archiveSize;

    protected $hashtable, $blocktable = NULL;
    protected $hashTableSize, $blockTableSize = 0;
    protected $hashTableOffset, $blockTableOffset = 0;
    protected $headerOffset = 0;

    private $sectorSize = 0;

    public $debugger;
    public $debug;

    function __construct($filename, $debug=false) 
    {
        $this->debug = $debug;
        $this->debugger = new MPQDebugger($this);

        if (!file_exists($filename)) 
            throw new MPQException($this, "$filename doesn't exist.");

        $this->filename = $filename;

        if (!MPQCrypto::$table)
            MPQCrypto::initCryptTable();

        $fp = fopen($filename, 'rb');
        $contents = fread($fp, filesize($filename));

        if ($contents === false) 
            throw new MPQException($this, "Error opening file $filename for reading");
        elseif ($contents !== false)
            $this->fileData = $contents;

        fclose($fp);

        $this->parseHeader();
    }

    function __destruct() 
    {
        if ($this->fp != null) 
            fclose($this->fp);
    }

    function isInitialized() { return $this->initialized === true; }
    function getType() { return $this->type; }
    function getFilename() { return $this->filename; }
    function getHashTable() { return $this->hashtable; }
    function getBlockTable() { return $this->blocktable; }
    function getGameData(){ return $this->map; }
    function getFileSize($filename) { $r=self::getFileInfo($filename); return $r['filesize']; }
    function hasFile($filename) { $r=self::getFileInfo($filename); return $r['filesize'] > 0; }

    function parseHeader() 
    {
        $fp = 0;
        $headerParsed = false;
        $foundHeader = false;

        while (!$headerParsed) 
        {
            $magic = MPQReader::bytes($this->fileData, $fp, 4);

            // check if the header is something we can read
            if (strlen($magic[1]) == 0)
                throw new MPQException($this, "No support for corrupted headers, yet.");

            $byte = unpack("c4", $magic);

            if (($byte[1] == 0x48) || ($byte[2] == 0x4D) || ($byte[3] == 0x33)) 
            {
                // store the archive type
                $this->type=self::TYPE_WC3MAP;

                // TODO: support for spazzler
                $offset=0x600;
                $spazzler = MPQReader::bytes($this->fileData, $offset, 12);

                if (strrpos($spazzler, "SPAZZLER") !== false)
                    throw new MPQException($this, "No support for archives with spazzler protection, yet.");

                $fp+=4;

                // find the name of the map
                $mapname = "";
                while ( ($s = MPQReader::byte($this->fileData, $fp)) != 0) // read until we find zero
                    $mapname .= chr($s);

                // store map data (name, flags, playercount)
                $this->map = new WC3Map($mapname, MPQReader::UInt32($this->fileData, $fp), MPQReader::UInt32($this->fileData, $fp));

                // find the header section
                $headerOffset = $fp;
                for($i=0; $i<0x200; $i++)
                {
                    if (MPQReader::byte($this->fileData, $fp) == 77)
                    {
                        $headerOffset=$i;
                        $fp--;
                        $foundHeader=true;
                        break;
                    }
                }

                if (!$foundHeader) $fp=0x200; else $this->debugger->write(sprintf("Found header at %08X", $fp));

                $fp+=8;
                $this->archiveSize = MPQReader::UInt32($this->fileData, $fp);
                $formatVersion = MPQReader::UInt16($this->fileData, $fp);
                $sectorSizeShift = MPQReader::UInt16($this->fileData, $fp);
                $this->sectorSize = 512 * (1 << $sectorSizeShift);
                $this->hashTableOffset = MPQReader::UInt32($this->fileData, $fp) + 0x200;
                $this->blockTableOffset = MPQReader::UInt32($this->fileData, $fp) + 0x200;
                $this->hashTableSize = MPQReader::UInt32($this->fileData, $fp);
                $this->blockTableSize = MPQReader::UInt32($this->fileData, $fp);

                $headerParsed = true;
            }
            elseif ((($byte[1] == 0x4D) || ($byte[2] == 0x50) || ($byte[3] == 0x51)))
            {
                if ($byte[4] == 27) // user data block (1Bh)
                {
                    $this->debugger->write(sprintf("Found user data block at %08X", $fp));

                    $uDataMaxSize = MPQReader::UInt32($this->fileData, $fp);
                    $headerOffset = MPQReader::UInt32($this->fileData, $fp);
                    $this->headerOffset = $headerOffset;
                    $uDataSize = MPQReader::UInt32($this->fileData, $fp);
                    $uDataStart = $fp;

                    // Check if it's a SC2 map
                    $this->map = new SC2Map(SC2Map::parseSerializedData($this->fileData,$fp));

                    if ($this->map->getVersionString() != null)
                        $this->type = self::TYPE_SC2MAP;
                    else
                        $this->map = null;
                    
                    $fp = $headerOffset;
                }
                else if ($byte[4] == 26) // header (1Ah)
                {
                    $this->debugger->write(sprintf("Found header at %08X", $fp));

                    $headerSize = MPQReader::UInt32($this->fileData, $fp);
                    $this->archiveSize = MPQReader::UInt32($this->fileData, $fp);
                    $formatVersion = MPQReader::UInt16($this->fileData, $fp);
                    $sectorSizeShift = MPQReader::byte($this->fileData, $fp);
                    $sectorSize = 512 * pow(2,$sectorSizeShift);
                    $this->sectorSize = $sectorSize;

                    if (!isset($headerOffset))
                        $headerOffset=$this->headerOffset;
                    
                    $fp++;
                    $this->hashTableOffset = MPQReader::UInt32($this->fileData, $fp) + $headerOffset;
                    $this->blockTableOffset = MPQReader::UInt32($this->fileData, $fp) + $headerOffset; 
                    $this->hashTableSize = MPQReader::UInt32($this->fileData, $fp);
                    $this->blockTableSize = MPQReader::UInt32($this->fileData, $fp);
                    
                    $headerParsed = true;
                }
                else 
                {
                    throw new MPQException($this, "Could not find MPQ header");
                }
            }
            else
            {
                $this->initialized = false; 
                throw new MPQException($this, "Unable to parse header.");
            }
        }

        $this->debugger->write(sprintf("Hash table offset: %08X, Block table offset: %08X", $this->hashTableOffset, $this->blockTableOffset));

        // read and decode the hash table
        $fp = $this->hashTableOffset;
        $hashSize = $this->hashTableSize * 4; // hash table size in 4-byte chunks
        $data = array();

        for ($i = 0;$i < $hashSize; $i++)
            $data[$i] = MPQReader::UInt32($this->fileData, $fp);

        $this->debugger->write("Encrypted hash table:");
        $this->debugger->printTable($data);

        $this->hashtable = MPQCrypto::decrypt($data, MPQCrypto::hashString("(hash table)", MPQ_HASH_FILE_KEY));
        $this->debugger->hashTable();

        // read and decode the block table
        $fp = $this->blockTableOffset;
        $blockSize = $this->blockTableSize * 4; // block table size in 4-byte chunks
        $data = array();

        for ($i = 0;$i < $blockSize;$i++)
            $data[$i] = MPQReader::UInt32($this->fileData, $fp);

        $this->debugger->write("Encrypted block table:");
        $this->debugger->printTable($data);

        $this->blocktable = MPQCrypto::decrypt($data, MPQCrypto::hashString("(block table)", MPQ_HASH_FILE_KEY));
        $this->debugger->blockTable();

        $this->initialized = true;
        
        return true;
    }
    
    function getFileInfo($filename)
    {
        if (!$this->initialized) 
        {
            $this->debugger->write("Archive has not yet been successfully initialized.");
            return false;
        }

        $hashA = MPQCrypto::hashString($filename, MPQ_HASH_NAME_A);
        $hashB = MPQCrypto::hashString($filename, MPQ_HASH_NAME_B);
        $hashStart = MPQCrypto::hashString($filename, MPQ_HASH_TABLE_OFFSET) & ($this->hashTableSize - 1);

        $blockSize = -1;
        $x = $hashStart;
        do 
        {
            if (($this->hashtable[$x*4 + 3] == MPQ_HASH_ENTRY_DELETED) || ($this->hashtable[$x*4 + 3] == MPQ_HASH_ENTRY_EMPTY)) 
                return false;

            if (($this->hashtable[$x*4] == $hashA) && ($this->hashtable[$x*4 + 1] == $hashB)) // found file
            {
                $blockIndex = ($this->hashtable[($x *4) + 3]) *4;
                $blockOffset = $this->blocktable[$blockIndex] + $this->headerOffset;
                $blockSize = $this->blocktable[$blockIndex + 1];
                $fileSize = $this->blocktable[$blockIndex + 2];
                $flags = $this->blocktable[$blockIndex + 3];
                break;
            }

            $x = ($x + 1) % $this->hashTableSize;
        } while ($x != $hashStart);

        if ($blockSize == -1) 
        {
            $this->debugger->write("Did not find file $filename in archive");
            return false;
        }

        return array('size'=>$blockSize, 'index'=>$blockIndex, 'offset'=>$blockOffset, 'filesize'=>$fileSize, 'flags'=>$flags);
    }

    function readFile($filename) 
    {
        $fileInfo = self::getFileInfo($filename);

        if (!$fileInfo) 
            return false;

        $blockSize = $fileInfo['size'];
        $blockIndex = $fileInfo['index'];
        $blockOffset = $fileInfo['offset'];
        $fileSize = $fileInfo['filesize'];
        $flags = $fileInfo['flags'];

        // set flags
        $flag_file       = $flags & self::FLAG_FILE;
        $flag_checksums  = $flags & self::FLAG_CHECKSUMS;
        $flag_deleted    = $flags & self::FLAG_DELETED;
        $flag_singleunit = $flags & self::FLAG_SINGLEUNIT;
        $flag_hEncrypted = $flags & self::FLAG_HENCRYPTED;
        $flag_encrypted  = $flags & self::FLAG_ENCRYPTED;
        $flag_compressed = $flags & self::FLAG_COMPRESSED;
        $flag_imploded   = $flags & self::FLAG_IMPLODED;
        
        $this->debugger->write(sprintf("Found $filename with flags %08X, block offset %08X, block size %d and filesize %d", $flags, $blockOffset,$blockSize,$fileSize));
        
        if (!$flag_file) return false;

        // generate encryption key if the file is encrpyted
        if ($flag_encrypted)
        {
            $filename = basename($filename);
            $cryptKey = MPQCrypto::hashString($filename, MPQ_HASH_FILE_KEY);

            // adjusted encryption key
            if ($flag_hEncrypted)
                $cryptKey = (($cryptKey + $blockOffset) ^ $fileSize);
        }

        $offset = 0;
        if ($this->type == self::TYPE_WC3MAP)
            $offset=0x200;

        // set the file position
        $fp = $blockOffset + $offset;

        // find the sector offsets
        if ($flag_checksums || !$flag_singleunit)
        {
            // calculate how many sectors there are
            $sector_count=ceil($fileSize / $this->sectorSize);

            // add each offset to the array
            for ($i = 0; $i <= $sector_count; $i++) 
            {
                $sectors[$i] = MPQReader::UInt32($this->fileData, $fp);
                $blockSize -= 4;
            }
        }
        else 
        {
            $sectors[] = 0;
            $sectors[] = $blockSize;
            $sector_count=count($sectors)-1;
        }

        // decrypt offsets if required
        if ($flag_encrypted)
            $sectors = MPQCrypto::decrypt($sectors, uPlus($cryptKey, -1));

        $output = "";

        // loop through each sector
        for ($i = 0; $i < $sector_count; $i++) 
        {
            $sectorLen = $sectors[$i + 1] - $sectors[$i];

            if ($sectorLen == 0)
                $sectorLen = $blockSize;

            if ($sectorLen == 0) break;

            // calculate the sector position
            $fp = ($blockOffset + $offset) + $sectors[$i];

            // decrypt if necessary
            if ($flag_encrypted) 
            {
                $sector = array();
                $sectorLen >>= 2;

                if ($sectorLen > $fileSize)
                    return false;

                // unpack and read the encrypted sector
                for($x=0; $x<=$sectorLen; $x++)
                    $sector[] = MPQReader::UInt32($this->fileData, $fp); // store it

                // decrypt the array
                $sector = MPQCrypto::decrypt($sector, (int)($cryptKey + $i));

                // pack the decrypted sector data
                for($x=0; $x<count($sector); $x++)
                    $sector[$x] = pack("V", $sector[$x]);

                $sectorData = implode($sector);

            }
            else
            {
                $sectorData = MPQReader::bytes($this->fileData, $fp, $sectorLen);
            }

            $this->debugger->write(sprintf("Got %d bytes of sector data", strlen($sectorData)));

            if ($flag_compressed)
            {
                $numByte = 0;
                $compressionType = MPQReader::byte($sectorData, $numByte);

                switch ($compressionType) 
                {
                    case 0x02:
                        $sectorData = substr($sectorData,1);

                        $this->debugger->write(sprintf("Found compresstion type: %d (gzlib)", $compressionType));

                        $decompressed = gzinflate(substr($sectorData, 2, strlen($sectorData) - 2));

                        if (!$decompressed)
                        {
                            $this->debugger->write(sprintf("Failed to decompress with compression type: %d", $compressionType));
                            $output .= $sectorData;
                            break;
                        }

                        $output .= $decompressed;

                        break;
                    case 0x10:
                        $sectorData = substr($sectorData,1);              
                        $output .= bzdecompress($sectorData);
                        break;
                    default:
                        $output .= $sectorData; // sector is uncompressed
                        break;
                }
            }
            else $output .= $sectorData;
        }

        if (strlen($output) != $fileSize) 
        {
            $err = sprintf("Decrypted/uncompressed filesize(%d) does not match original file size(%d)",strlen($output),$fileSize);
            $err .= "<br/>$output";
            $this->debugger->write($err);
            return false;
        }

        return $output;
    }

    // saves the mpq data as a file.
    function saveAs($filename, $overwrite = false) 
    {
        if (file_exists($filename) && !$overwrite) return false;
        $fp = fopen($filename, "wb");
        if (!$fp) return false;
        $result = fwrite($fp,$this->fileData);
        if (!$result) return false;
        fclose($fp);
        return true;
    }

}

?>