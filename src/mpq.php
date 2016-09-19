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
define("MPQ_HEADER_SIZE_V1", 0x20);

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

    const BLOCK_INDEX_MASK = 0x000FFFFF;

    private $filename, $filesize;
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

    protected $debugger;
    protected $debug;

    public static $debugShowTables = false;

    function __construct($filename, $debug=false) 
    {
        $this->debug = $debug;
        $this->debugger = new MPQDebugger($this);

        if (!file_exists($filename)) 
            throw new MPQException($this, "$filename doesn't exist.");

        $this->filename = $filename;

        // Initialize the cryptography table.
        // This runs only once per session.
        if (!MPQCrypto::$table)
            MPQCrypto::initCryptTable();

        // Read the archive in binary and store the contents.
        $this->fp = fopen($filename, 'rb');
        $this->filesize = filesize($filename);

        // The filesize must be at least the minimum header size.
        if ($this->filesize < MPQ_HEADER_SIZE_V1)
            throw new MPQException($this, "$filename is too small.");

        $contents = fread($this->fp, $this->filesize);

        if ($contents === false) 
            throw new MPQException($this, "Error opening file $filename for reading.");
        elseif ($contents !== false)
            $this->fileData = $contents;

        fclose($this->fp);

        $this->parseHeader();
    }

    function __destruct() 
    {
        if ($this->fp != null && get_resource_type($this->fp) == 'file') 
            fclose($this->fp);
    }

    public function isInitialized() { return $this->initialized === true; }
    public function getType() { return $this->type; }
    public function getFilename() { return $this->filename; }
    public function getHashTable() { return $this->hashtable; }
    public function getBlockTable() { return $this->blocktable; }
    public function getGameData(){ return $this->map; }
    public function getfilesize($filename) { $r=self::getFileInfo($filename); return $r['filesize']; }
    public function hasFile($filename) { $r=self::getFileInfo($filename); return $r['filesize'] > 0; }

    public function parseHeader() 
    {
        $header_parsed = false;
        $fp            = 0;
        $end_of_search = $this->filesize;
        $isWar3        = false;

        // Limit the header size to 130 MB.
        if ($end_of_search > 0x08000000)
            $end_of_search = 0x08000000;

        while (!$header_parsed && $fp < $end_of_search)
        {
            // Buffer the first 4 bytes.
           for($i=0;$i<4;$i++)
                $byte[$i] = MPQReader::byte($this->fileData, $fp);

            // Check if the file is a Warcraft III map.
            if ($fp == 4 && ($byte[0] == 0x48) && ($byte[1] == 0x4D) && ($byte[2] == 0x33))
            {
                $this->type = self::TYPE_WC3MAP;
                $fp+=4;

                // Store some information about the map.
                $this->map = new WC3Map($this);
                $this->map->name      = MPQReader::String($this->fileData, $fp);
                $this->map->flags     = MPQReader::UInt32($this->fileData, $fp);
                $this->map->playerRec = MPQReader::UInt32($this->fileData, $fp);
                $isWar3 = true;
                $fp = 4;
            }

            if ($byte[0] == 0x4D && $byte[1] == 0x50 && $byte[2] == 0x51)
            {
                if (!$isWar3 && $byte[3] == 0x1B) // user data block (1Bh)
                {
                    $this->debugger->write(sprintf("Found user data block at %08X", $fp));
                    $udata_max_size = MPQReader::UInt32($this->fileData, $fp);
                    $headerOffset   = MPQReader::UInt32($this->fileData, $fp);
                    $udata_size     = MPQReader::UInt32($this->fileData, $fp);
                    $udata_start    = $fp;
                    $this->map = new SC2Map($this);
                    $data = SC2Map::parseSerializedData($this->fileData, $fp);
                    if ($data != false && $this->map->getVersionString() != null)
                        $this->map->storeSerializedData($data);
                    else
                        $this->map = null;
                    
                    $fp = $headerOffset;
                }
                elseif ($byte[3] == 0x1A) // header (1Ah)
                {
                    $this->headerOffset = $fp - 4;
                    $this->debugger->write(sprintf("Found header at %08X", $fp));
                    $this->headerSize    = MPQReader::UInt32($this->fileData, $fp);
                    $this->archiveSize   = MPQReader::UInt32($this->fileData, $fp);
                    $this->formatVersion = MPQReader::UInt16($this->fileData, $fp);
                    $this->sectorSize    = 512 * (1 << MPQReader::UInt16($this->fileData, $fp));
                    $this->hashTableOffset  = MPQReader::UInt32($this->fileData, $fp) + $this->headerOffset;
                    $this->blockTableOffset = MPQReader::UInt32($this->fileData, $fp) + $this->headerOffset; 
                    $this->hashTableSize    = MPQReader::UInt32($this->fileData, $fp);
                    $this->blockTableSize   = MPQReader::UInt32($this->fileData, $fp);

                    $header_parsed = true;
                }
            }

            // Skip 0x200 bytes for performance
            $fp+=0x200-4;
        }

        if (!$header_parsed)
            throw new MPQException($this, "Unable to read the archive header.");

        // Overflow protection (Spazzler).
        $this->hashTableSize    = ($this->hashTableSize & self::BLOCK_INDEX_MASK);
        $this->blockTableSize   = ($this->blockTableSize & self::BLOCK_INDEX_MASK);

        $this->debugger->write(sprintf("Hash table offset: %08X, Block table offset: %08X", $this->hashTableOffset, $this->blockTableOffset));

        // Read and decrypt the hash table in 4-byte chunks.
        $fp         = $this->hashTableOffset;
        $hash_size  = $this->hashTableSize * 4;
        $data       = array();
        
        for ($i = 0;$i < $hash_size; $i++)
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
        $block_size = $this->blockTableSize * 4;
        $data       = array();

        for ($i = 0;$i < $block_size; $i++)
            $data[$i] = MPQReader::UInt32($this->fileData, $fp);

        if (MPQArchive::$debugShowTables)
        {
            $this->debugger->write("Encrypted block table:");
            $this->debugger->printTable($data);
        }

        $this->blocktable = MPQCrypto::decrypt($data, MPQCrypto::hashString("(block table)", MPQ_HASH_FILE_KEY));
        $this->debugger->blockTable();

        // The archive is ready to be read from
        $this->initialized = true;
        
        // Check to see if it's a Starcraft II map.
        if ($this->type == self::TYPE_DEFAULT)
        {
            if (!isset($this->map))
                $this->map = new SC2Map($this);

            if ($this->map->parseData())
                $this->type = self::TYPE_SC2MAP;
            else
                $this->map = null;
        }

        return true;
    }
    
    public function getFileInfo($filename)
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
            {
                return false;
            }

            if (($this->hashtable[$x*4] == $hash_a) && ($this->hashtable[$x*4 + 1] == $hash_b)) // found file
            {
                $block_index    = (($this->hashtable[($x *4) + 3]) *4);
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

    public function readFile($filename) 
    {
        // Look for the file in the archive
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

        // Set the offset to the files position in the block table.
        $offset = $this->headerOffset;
        $fp = $block_offset + $offset;

        // Find the sector offsets.
        if ($flag_checksums || !$flag_singleunit)
        {
            $sector_count=ceil($filesize / $this->sectorSize);

            for ($i = 0; $i <= $sector_count; $i++) 
            {
                $sector_offsets[$i] = MPQReader::UInt32($this->fileData, $fp);
                $block_size -= 4;
            }
        }
        else 
        {
            $sector_offsets[] = 0;
            $sector_offsets[] = $block_size;
            $sector_count     = count($sector_offsets)-1;
        }

        // Decrypt the offsets if they are encrypted.
        if ($flag_encrypted)
            $sector_offsets = MPQCrypto::decrypt($sector_offsets, uPlus($crypt_key, -1));

        $output = "";

        // Loop through all of the sectors.
        for ($i = 0; $i < $sector_count; $i++) 
        {
            $sector_len = $sector_offsets[$i + 1] - $sector_offsets[$i];

            if ($sector_len == 0)
                $sector_len = $blockSize;

            if ($sector_len == 0) 
                break;

            // Find the sector's position in the block table.
            $fp = ($block_offset + $offset) + $sector_offsets[$i];

            // Decrypt the sector if it has the encrypted flag.
            if ($flag_encrypted) 
            {
                $sector = array();
                $sector_len >>= 2;

                if ($sector_len > $filesize)
                    return false;

                // Unpack and store the encrypted sector data.
                for($x=0; $x<=$sector_len; $x++)
                    $sector[] = MPQReader::UInt32($this->fileData, $fp); // store it

                // Decrypt the sector data and re-pack it.
                $sector = MPQCrypto::decrypt($sector, (int)($crypt_key + $i));

                for($x=0; $x<count($sector); $x++)
                    $sector[$x] = pack("V", $sector[$x]);

                $sector_data = implode($sector);

            }
            else
            {
                $sector_data = MPQReader::bytes($this->fileData, $fp, $sector_len);
            }

            $this->debugger->write(sprintf("Got %d bytes of sector data", strlen($sector_data)));

            // Decompress the sector data, if necessary.
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
    public function saveAs($filename, $overwrite = false) 
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