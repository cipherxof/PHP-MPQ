<?php
/*
    Created by TriggerHappy
*/

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
    private $formatVersion;
    private $archiveSize, $headerSize;

    protected $hashtable, $blocktable = NULL;
    protected $hashTableSize, $blockTableSize = 0;
    protected $hashTableOffset, $blockTableOffset = 0;
    protected $headerOffset = 0;

    private $sectorSize = 0;

    public $debugger;
    public $debug;

    public static $debugShowTables = true;

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
    function getfilesize($filename) { $r=self::getFileInfo($filename); return $r['filesize']; }
    function hasFile($filename) { $r=self::getFileInfo($filename); return $r['filesize'] > 0; }

    function parseHeader() 
    {
        $header_parsed = false;
        $fp = 0;

        while (!$header_parsed)
        {
            $magic = MPQReader::bytes($this->fileData, $fp, 4);

            if (strlen($magic[1]) == 0)
                throw new MPQException($this, "Unable to read the MPQ header.");

            $byte = unpack("c4", $magic);

            if (($byte[1] == 0x48) || ($byte[2] == 0x4D) || ($byte[3] == 0x33)) // Warcraft III
            {
                $this->type=self::TYPE_WC3MAP;

                // TODO: Add support for spazzler.
                $offset   = 0x600;
                $spazzler = MPQReader::bytes($this->fileData, $offset, 12);

                if (strrpos($spazzler, "SPAZZLER") !== false)
                    throw new MPQException($this, "No support for archives with spazzler protection, yet.");

                $fp+=4;

                // Read and store information about the map.
                $map_name = "";

                while ( ($s = MPQReader::byte($this->fileData, $fp)) != 0)
                    $map_name .= chr($s);

                $flags   = MPQReader::UInt32($this->fileData, $fp);
                $players = MPQReader::UInt32($this->fileData, $fp);

                $this->map = new WC3Map($map_name, $flags, $players);

                // Search the first 512 bytes for the start of the header,
                // which should begin with "MPQ" in ASCII.
                $this->headerOffset = $fp;

                for($i=0; $i<0x200; $i++)
                {
                    if (MPQReader::byte($this->fileData, $fp) == 77)
                    {
                        $this->headerOffset = $i;
                        $found_header = true;

                        $fp--;
                        break;
                    }
                }

                if (!$found_header)
                    $fp = 0x200;

                $this->debugger->write(sprintf( ($found_header ? "Found header at %08X" : "Could not find header, defaulting to %08X"), $fp) );

                $fp+=4;

                // Finish storing the header data.
                $this->headerSize       = MPQReader::UInt32($this->fileData, $fp);
                $this->archiveSize      = MPQReader::UInt32($this->fileData, $fp);
                $this->formatVersion    = MPQReader::UInt16($this->fileData, $fp);
                $this->sectorSize       = 512 * (1 << MPQReader::UInt16($this->fileData, $fp));
                $this->hashTableOffset  = MPQReader::UInt32($this->fileData, $fp) + 0x200;
                $this->blockTableOffset = MPQReader::UInt32($this->fileData, $fp) + 0x200;
                $this->hashTableSize    = MPQReader::UInt32($this->fileData, $fp);
                $this->blockTableSize   = MPQReader::UInt32($this->fileData, $fp);

                $header_parsed = true;
            }
            elseif ((($byte[1] == 0x4D) || ($byte[2] == 0x50) || ($byte[3] == 0x51)))
            {
                if ($byte[4] == 27) // user data block (1Bh)
                {
                    $this->debugger->write(sprintf("Found user data block at %08X", $fp));

                    $udata_max_size     = MPQReader::UInt32($this->fileData, $fp);
                    $this->headerOffset = MPQReader::UInt32($this->fileData, $fp);
                    $udata_size         = MPQReader::UInt32($this->fileData, $fp);
                    $udata_start        = $fp;

                    // Check if it's a SC2 map.
                    $this->map = new SC2Map(SC2Map::parseSerializedData($this->fileData,$fp));

                    if ($this->map->getVersionString() != null)
                        $this->type = self::TYPE_SC2MAP;
                    else
                        $this->map = null;
                    
                    $fp = $this->headerOffset;
                }
                else if ($byte[4] == 26) // header (1Ah)
                {
                    $this->debugger->write(sprintf("Found header at %08X", $fp));

                    $this->headerSize    = MPQReader::UInt32($this->fileData, $fp);
                    $this->archiveSize   = MPQReader::UInt32($this->fileData, $fp);
                    $this->formatVersion = MPQReader::UInt16($this->fileData, $fp);
                    $this->sectorSize    = 512 * pow(2, MPQReader::byte($this->fileData, $fp));
                    
                    $fp++;
                    $this->hashTableOffset  = MPQReader::UInt32($this->fileData, $fp) + $this->headerOffset;
                    $this->blockTableOffset = MPQReader::UInt32($this->fileData, $fp) + $this->headerOffset; 
                    $this->hashTableSize    = MPQReader::UInt32($this->fileData, $fp);
                    $this->blockTableSize   = MPQReader::UInt32($this->fileData, $fp);

                    $header_parsed = true;
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

        // Read and decrypt the hash table in 4-byte chunks.
        $fp         = $this->hashTableOffset;
        $hashSize   = $this->hashTableSize * 4;
        $data       = array();

        for ($i = 0;$i < $hashSize; $i++)
            $data[$i] = MPQReader::UInt32($this->fileData, $fp);

        if (MPQArchive::$debugShowTables)
        {
            $this->debugger->write("Encrypted hash table:");
            $this->debugger->printTable($data);
        }

        $this->hashtable = MPQCrypto::decrypt($data, MPQCrypto::hashString("(hash table)", MPQ_HASH_FILE_KEY));
        $this->debugger->hashTable();

        // Read and decrypt the block table in 4-byte chunks.
        $fp         = $this->blockTableOffset;
        $blockSize  = $this->blockTableSize * 4;
        $data       = array();

        for ($i = 0;$i < $blockSize;$i++)
            $data[$i] = MPQReader::UInt32($this->fileData, $fp);

        if (MPQArchive::$debugShowTables)
        {
            $this->debugger->write("Encrypted block table:");
            $this->debugger->printTable($data);
        }

        $this->blocktable = MPQCrypto::decrypt($data, MPQCrypto::hashString("(block table)", MPQ_HASH_FILE_KEY));
        $this->debugger->blockTable();

        $this->initialized = true;
        
        // One last check to see if the archive is a SC2Map.
        if ($this->type == self::TYPE_DEFAULT && $this->hasFile("DocumentHeader"))
        {
            $file = $this->readFile("DocumentHeader");

            $this->map = new SC2Map();

            if (strlen($file) > 0 && $this->map->parseDocumentHeader($file))
                $this->type = self::TYPE_SC2MAP;
            else
                $this->map = null;
        }

        return true;
    }
    
    function getFileInfo($filename)
    {
        if (!$this->initialized) 
        {
            $this->debugger->write("Archive has not yet been successfully initialized.");
            return false;
        }

        $hash_a     = MPQCrypto::hashString($filename, MPQ_HASH_NAME_A);
        $hash_b     = MPQCrypto::hashString($filename, MPQ_HASH_NAME_B);
        $hash_start = MPQCrypto::hashString($filename, MPQ_HASH_TABLE_OFFSET) & ($this->hashTableSize - 1);
        $block_size = -1;

        $x = $hash_start;
        do 
        {
            if (($this->hashtable[$x*4 + 3] == MPQ_HASH_ENTRY_DELETED) || ($this->hashtable[$x*4 + 3] == MPQ_HASH_ENTRY_EMPTY)) 
                return false;

            if (($this->hashtable[$x*4] == $hash_a) && ($this->hashtable[$x*4 + 1] == $hash_b)) // found file
            {
                $block_index    = ($this->hashtable[($x *4) + 3]) *4;
                $block_offset   = $this->blocktable[$block_index];
                $block_size     = $this->blocktable[$block_index + 1];
                $filesize       = $this->blocktable[$block_index + 2];
                $flags          = $this->blocktable[$block_index + 3];

                break;
            }

            $x = ($x + 1) % $this->hashTableSize;
        } while ($x != $hash_start);

        if ($block_size == -1) 
        {
            $this->debugger->write("Did not find file $filename in archive");
            return false;
        }

        return array('size'=>$block_size, 'index'=>$block_index, 'offset'=>$block_offset, 'filesize'=>$filesize, 'flags'=>$flags);
    }

    function readFile($filename) 
    {
        $file_info = self::getFileInfo($filename);

        if ($file_info == false) 
            return false;

        $block_size   = $file_info['size'];
        $block_index  = $file_info['index'];
        $block_offset = $file_info['offset'];
        $filesize     = $file_info['filesize'];
        $flags        = $file_info['flags'];

        $flag_file       = $flags & self::FLAG_FILE;
        $flag_checksums  = $flags & self::FLAG_CHECKSUMS;
        $flag_deleted    = $flags & self::FLAG_DELETED;
        $flag_singleunit = $flags & self::FLAG_SINGLEUNIT;
        $flag_hEncrypted = $flags & self::FLAG_HENCRYPTED;
        $flag_encrypted  = $flags & self::FLAG_ENCRYPTED;
        $flag_compressed = $flags & self::FLAG_COMPRESSED;
        $flag_imploded   = $flags & self::FLAG_IMPLODED;
        
        $this->debugger->write(sprintf("Found $filename with flags %08X, block offset %08X, block size %d and filesize %d", $flags, $block_offset,$block_size,$filesize));
        
        if (!$flag_file) 
            return false;

        // Generate an encryption key if the file is encrpyted.
        if ($flag_encrypted)
        {
            $filename = basename(str_replace('\\', '/', $filename));
            $crypt_key = MPQCrypto::hashString($filename, MPQ_HASH_FILE_KEY);

            if ($flag_hEncrypted)
                $crypt_key = (($crypt_key + $block_offset) ^ $filesize);
        }

        $offset = $this->headerOffset;

        // set the file position
        $fp = $block_offset + $offset;

        // find the sector offsets
        if ($flag_checksums || !$flag_singleunit)
        {
            // calculate how many sectors there are
            $sector_count=ceil($filesize / $this->sectorSize);

            // add each offset to the array
            for ($i = 0; $i <= $sector_count; $i++) 
            {
                $sectors[$i] = MPQReader::UInt32($this->fileData, $fp);
                $block_size -= 4;
            }
        }
        else 
        {
            $sectors[] = 0;
            $sectors[] = $block_size;
            $sector_count=count($sectors)-1;
        }

        // decrypt offsets if required
        if ($flag_encrypted)
            $sectors = MPQCrypto::decrypt($sectors, uPlus($crypt_key, -1));

        $output = "";

        // loop through each sector
        for ($i = 0; $i < $sector_count; $i++) 
        {
            $sector_len = $sectors[$i + 1] - $sectors[$i];

            if ($sector_len == 0)
                $sector_len = $blockSize;

            if ($sector_len == 0) 
                break;

            // calculate the sector position
            $fp = ($block_offset + $offset) + $sectors[$i];

            // decrypt if necessary
            if ($flag_encrypted) 
            {
                $sector = array();
                $sector_len >>= 2;

                if ($sector_len > $filesize)
                    return false;

                // unpack and read the encrypted sector
                for($x=0; $x<=$sector_len; $x++)
                    $sector[] = MPQReader::UInt32($this->fileData, $fp); // store it

                // decrypt the array
                $sector = MPQCrypto::decrypt($sector, (int)($crypt_key + $i));

                // pack the decrypted sector data
                for($x=0; $x<count($sector); $x++)
                    $sector[$x] = pack("V", $sector[$x]);

                $sector_data = implode($sector);

            }
            else
            {
                $sector_data = MPQReader::bytes($this->fileData, $fp, $sector_len);
            }

            $this->debugger->write(sprintf("Got %d bytes of sector data", strlen($sector_data)));

            if ($flag_compressed)
            {
                $num_byte = 0;
                $compression_type = MPQReader::byte($sector_data, $num_byte);
                
                switch ($compression_type) 
                {
                    case 0x02:
                        $sector_data = substr($sector_data,1);

                        $this->debugger->write(sprintf("Found compresstion type: %d (gzlib)", $compression_type));

                        $decompressed = gzinflate(substr($sector_data, 2, strlen($sector_data) - 2));

                        if (!$decompressed)
                        {
                            $this->debugger->write(sprintf("Failed to decompress with compression type: %d", $compression_type));
                            $output .= $sector_data;
                            break;
                        }

                        $output .= $decompressed;

                        break;
                    case 0x10:
                        $sector_data = substr($sector_data,1);              
                        $output .= bzdecompress($sector_data);

                        break;
                    default:
                        $output .= $sector_data; // sector is uncompressed

                        break;
                }
            }
            else $output .= $sector_data;
        }

        if (strlen($output) != $filesize) 
        {
            $err = sprintf("Decrypted/uncompressed filesize(%d) does not match original file size(%d)",strlen($output),$filesize);
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