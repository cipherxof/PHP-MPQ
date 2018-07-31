<?php
/*
    Created by TriggerHappy
*/

namespace TriggerHappy\MPQ;

use TriggerHappy\MPQ\Debugger;
use TriggerHappy\MPQ\Stream\FileStream;
use TriggerHappy\MPQ\Encryption\MPQEncryption;
use TriggerHappy\MPQ\Compression\Huffman;
use TriggerHappy\MPQ\Compression\ADPCM;

const MPQ_HASH_TABLE_OFFSET     = 0;
const MPQ_HASH_NAME_A           = 1;
const MPQ_HASH_NAME_B           = 2;
const MPQ_HASH_FILE_KEY         = 3;
const MPQ_HASH_ENTRY_EMPTY      = -1;
const MPQ_HASH_ENTRY_DELETED    = -2;

const MPQ_HEADER_SIZE_V1        = 0x20;

const MPQ_FLAG_FILE         = 0x80000000;
const MPQ_FLAG_CHECKSUM     = 0x04000000;
const MPQ_FLAG_DELETED      = 0x02000000;
const MPQ_FLAG_SINGLEUNIT   = 0x01000000;
const MPQ_FILE_FIX_KEY      = 0x00020000;
const MPQ_FLAG_ENCRYPTED    = 0x00010000;
const MPQ_FLAG_COMPRESSED   = 0x00000200;
const MPQ_FLAG_IMPLODED     = 0x00000100;

const MPQ_COMPRESSION_HUFFMANN      = 0x01;
const MPQ_COMPRESSION_ZLIB          = 0x02;
const MPQ_COMPRESSION_PKWARE        = 0x08;
const MPQ_COMPRESSION_BZIP2         = 0x10;
const MPQ_COMPRESSION_SPARSE        = 0x20;
const MPQ_COMPRESSION_ADPCM_MONO    = 0x40;
const MPQ_COMPRESSION_ADPCM_STEREO  = 0x80;
const MPQ_COMPRESSION_LZMA          = 0x12;

const BLOCK_INDEX_MASK = 0xFFFFFFFF;

class MPQArchive 
{
    private $filename, $filesize;
    private $fp;

    private $initialized = false;
    private $typeParsed = false;

    private $file;
    private $formatVersion;
    private $archiveSize, $headerSize;

    protected $hashtableSize, $blocktableSize = 0;
    protected $hashtableOffset, $blocktableOffset = 0;
    protected $headerOffset = 0;
    protected $htFile, $htFname, $htEnd, $htKey, $btFile, $btFname, $btEnd, $btkey;

    private $sectorSize = 0;
    private $stream;

    protected $debugger;
    public $debug;

    public static $DebugShowTables = false;

    function __construct($filename, $debug=false) 
    {
        $this->debug = $debug;
        $this->debugger = new Debugger($this);

        if (!file_exists($filename)) 
            throw new \Exception("$filename doesn't exist.");

        $this->filename = $filename;

        // Initialize the cryptography table.
        // This runs only once per session.
        MPQEncryption::InitCryptTable();

        // Read the archive in binary and store the contents.
        $this->file = fopen($filename, 'rb');
        $this->filesize = filesize($filename);
        $this->stream = new FileStream($this->file);

        // The filesize must be at least the minimum header size.
        if ($this->filesize < MPQ_HEADER_SIZE_V1)
            throw new \Exception("$filename is too small.");

        $this->parseHeader();
    }

    function __destruct() 
    {   
        $this->close();
    }

    function close() 
    {
        if ($this->btFile && get_resource_type($this->btFile) == 'stream')
        {
            fclose($this->btFile); 
            unlink($this->btFname);
        }

        if ($this->htFile && get_resource_type($this->htFile) == 'stream')
        {
            fclose($this->htFile); 
            unlink($this->htFname);
        }

        if ($this->file && get_resource_type($this->file) == 'stream') fclose($this->file); 
    }

    public function isInitialized() { return $this->initialized == true; }
    public function getHeaderOffset() { return $this->headerOffset; }
    public function getFilename() { return $this->filename; }
    public function getFilesize($filename) { $r=self::getFileInfo($filename); return $r['filesize']; }
    public function hasFile($filename) { $r=self::getFileInfo($filename); return $r['filesize'] > 0; }

    public function parseHeader() 
    {
        $header_parsed = false;
        $end_of_search = $this->filesize;
        $isWar3        = false;

        $this->stream->setPosition(0);

        // Limit the header search size to 130MB
        if ($end_of_search > 0x08000000)
            $end_of_search = 0x08000000;

        // Check if the file is a Warcraft III map.
        $buffer = $this->stream->readBytes(4);
        $isWar3 = ($buffer == 'HM3W');

        // Reset buffer to begin searching for the MPQ header
        $this->stream->setPosition(0);

        // Find and parse the MPQ header.
        while (!$header_parsed && $this->stream->fp < $end_of_search)
        {
            $fp_start = $this->stream->fp;
            $buffer = $this->stream->readBytes(3);

            if ($buffer != "MPQ")
            {
                $this->stream->setPosition($fp_start + 0x200);
                continue;
            }

            $buffer[3] = $this->stream->readByte();

            if (!$isWar3 && ord($buffer[3]) == 0x1B) // user data block (1Bh)
            {
                $udata_start = $this->stream->fp-4;

                $this->debugger->write(sprintf("Found user data block at %08X", $udata_start));

                $udata_max_size = $this->stream->readUInt32();
                $header_offset  = $this->stream->readUInt32();
                $udata_size     = $this->stream->readUInt32();

                $this->stream->setPosition($udata_start+4);
            }
            elseif (ord($buffer[3]) == 0x1A) // header (1Ah)
            {

                $this->headerOffset = $this->stream->fp - 4;
            
                $this->debugger->write(sprintf("Found header at %08X", $this->headerOffset));

                $this->headerSize       = $this->stream->readUInt32();
                $this->archiveSize      = $this->stream->readUInt32();
                $this->formatVersion    = $this->stream->readUInt16();

                $this->sectorSize       = 512 * (1 << $this->stream->readUInt16());
                
                $this->hashTableOffset  = $this->stream->readUInt32() + $this->headerOffset;
                $this->blockTableOffset = $this->stream->readUInt32() + $this->headerOffset; 
                $this->hashTableSize    = ($this->stream->readUInt32() & BLOCK_INDEX_MASK);
                $this->blockTableSize   = ($this->stream->readUInt32() & BLOCK_INDEX_MASK);

                $this->hashTableOffset  = ($this->hashTableOffset & BLOCK_INDEX_MASK);
                $this->blockTableOffset = ($this->blockTableOffset & BLOCK_INDEX_MASK);

                // Check if the block size is bigger than the file
                if (($this->blockTableOffset + ($this->blockTableSize*4)) > $this->filesize)
                    $this->blockTableSize = (($this->filesize - $this->blockTableOffset) / 4) / 4;

                $valid_header = ($this->hashTableOffset <= $this->filesize) && ($this->blockTableOffset <= $this->filesize);
                $valid_header = ($valid_header) && ($this->hashTableOffset > 0) && ($this->blockTableOffset > 0);

                if ($valid_header && $this->headerSize >= MPQ_HEADER_SIZE_V1)
                    $header_parsed = true;

                $this->stream->setPosition($this->headerOffset + 4);

            }

            $this->stream->setPosition($fp_start + 0x200);
        }

        if (!$header_parsed)
            throw new \Exception("Unable to read the archive header.");

        // Limit the table sizes to prevent memory overflow.
        $this->hashTableSize = ($this->hashTableSize & BLOCK_INDEX_MASK);
        $this->blockTableSize = ($this->blockTableSize & BLOCK_INDEX_MASK);

        // Write the decrypted hashtable to disk to reduce memory usage.
        $this->htFname = tempnam(sys_get_temp_dir(), "ht");
        file_put_contents($this->htFname, ""); // clear file
        $this->htKey = MPQEncryption::HashString("(hash table)", MPQ_HASH_FILE_KEY);
        $this->htFile = fopen($this->htFname, "a+");
        $this->stream->setPosition($this->hashTableOffset);
        MPQEncryption::DecryptStream($this->stream, $this->hashTableSize * 4, $this->htKey, $this->htFile);
        $this->debugger->hashTable();

        // and blocktable
        $this->btFname = tempnam(sys_get_temp_dir(), "bt");
        file_put_contents($this->btFname, ""); // clear file
        $this->btKey = MPQEncryption::HashString("(block table)", MPQ_HASH_FILE_KEY);
        $this->btFile = fopen($this->btFname, "a+");
        $this->stream->setPosition($this->blockTableOffset);
        MPQEncryption::DecryptStream($this->stream, $this->blockTableSize * 4, $this->btKey, $this->btFile);
        $this->debugger->blockTable();

        // The archive is ready.
        $this->debugger->write(sprintf("Hash table offset: %08X, Block table offset: %08X", $this->hashTableOffset, $this->blockTableOffset));

        $this->initialized = true;
        
        return true;
    }

    public function readHashtable($index)
    {
        fseek($this->htFile, $index*4);
        $val = fread($this->htFile, 4);

        return (strlen($val) != 4 ? $val : unpack("V", $val)[1]);
    }

    public function readBlocktable($index)
    {
        fseek($this->btFile, $index*4);
        $val = fread($this->btFile, 4);

        return (strlen($val) != 4 ? $val : unpack("V", $val)[1]);
    }

    public function getFileInfo($filename)
    {
        if (!$this->initialized) 
        {
            $this->debugger->write("Archive has not yet been successfully initialized.");
            return false;
        }

        $hash_a     = MPQEncryption::HashString($filename, MPQ_HASH_NAME_A);
        $hash_b     = MPQEncryption::HashString($filename, MPQ_HASH_NAME_B);
        $hash_start = MPQEncryption::HashString($filename, MPQ_HASH_TABLE_OFFSET) & ($this->hashTableSize - 1);
        $block_size = -1;

        $x = $hash_start;

        do 
        {
            if (($this->readHashtable($x*4 + 3) == MPQ_HASH_ENTRY_DELETED) || ($this->readHashtable($x*4 + 3) == MPQ_HASH_ENTRY_EMPTY)) 
            {
                return false;
            }

            if ($this->readHashtable($x*4) == $hash_a && $this->readHashtable($x*4 + 1) == $hash_b) // found file
            {   
                $block_index    = (($this->readHashtable(($x *4) + 3)) *4);

                $block_offset   = $this->readBlocktable($block_index);
                $block_size     = $this->readBlocktable($block_index + 1);
                $filesize       = $this->readBlocktable($block_index + 2);
                $flags          = $this->readBlocktable($block_index + 3);

                break;
            }

            $x = ($x + 1) % $this->hashTableSize;
            $fp = $this->hashTableOffset + $x;

        } while ($x != $hash_start);

        if ($block_size == -1) 
        {
            $this->debugger->write("Did not find file $filename in the archive");
            return false;
        }

        return array('size'=>$block_size, 'index'=>$block_index, 'offset'=>$block_offset, 'filesize'=>$filesize, 'flags'=>$flags);
    }

    public function readFile($filename) 
    {
        // Look for the file in the archive
        $file_info = self::getFileInfo($filename);

        if ($file_info == false) {
            $this->debugger->write("Could not find \"$filename\" in the archive");
            return false;
        }

        $block_size   = $file_info['size'];
        $block_index  = $file_info['index'];
        $block_offset = $file_info['offset'];
        $filesize     = $file_info['filesize'];
        $flags        = $file_info['flags'];

        $flag_file       = $flags & MPQ_FLAG_FILE;
        $flag_checksums  = $flags & MPQ_FLAG_CHECKSUM;
        $flag_deleted    = $flags & MPQ_FLAG_DELETED;
        $flag_singleunit = $flags & MPQ_FLAG_SINGLEUNIT;
        $flag_hEncrypted = $flags & MPQ_FILE_FIX_KEY;
        $flag_encrypted  = $flags & MPQ_FLAG_ENCRYPTED;
        $flag_compressed = $flags & MPQ_FLAG_COMPRESSED;
        $flag_imploded   = $flags & MPQ_FLAG_IMPLODED;

        $this->debugger->write(sprintf("Found $filename with flags %08X, block offset %08X, block size %d and filesize %d", $flags, $block_offset,$block_size,$filesize));

        if (!$flag_file) 
            return false;

        // Generate an encryption key if the file is encrpyted.
        if ($flag_encrypted)
        {
            $filename = basename(str_replace('\\', '/', $filename));
            $crypt_key = MPQEncryption::HashString($filename, MPQ_HASH_FILE_KEY);

            // Fix the decryption key
            if ($flag_hEncrypted)
                $crypt_key = (($crypt_key + $block_offset) ^ $filesize);
        }

        // Set the offset to the files position in the block table.
        $offset = $this->headerOffset;
        $fp = $block_offset + $offset;
        $this->stream->setPosition($fp);

        // Find the sector offsets.
        if ($flag_singleunit != true && ($flag_checksums || $flag_compressed))
        {
            $sector_count = ceil((double)$filesize / (double)$this->sectorSize);

            for ($i = 0; $i <= $sector_count; $i++) 
            {
                $sector_offsets[$i] = $this->stream->readUInt32($this->file, $fp);
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
            $sector_offsets = MPQEncryption::Decrypt($sector_offsets, $crypt_key - 1);

        $output = "";

        // Loop through all of the sectors.
        for ($i = 0; $i < $sector_count; $i++) 
        {
            $sector_len = $sector_offsets[$i + 1] - $sector_offsets[$i];

            if ($sector_len == 0)
                $sector_len = $block_size;

            if ($sector_len == 0) 
                break;

            // Find the sector's position in the block table.
            $fp = ($block_offset + $offset) + $sector_offsets[$i];

            $this->stream->setPosition($fp);

            // Decrypt the sector if it has the encrypted flag.
            if ($flag_encrypted) 
            {
                $sector = array();
                $sector_len >>= 2;

                if ($sector_len > $filesize)
                    return false;

                // Unpack and store the encrypted sector data.
                for($x=0; $x<=$sector_len; $x++)
                    $sector[] = $this->stream->readUInt32($this->file, $fp); // store it

                // Decrypt the sector data and re-pack it.
                $sector = MPQEncryption::Decrypt($sector, (int)($crypt_key + $i));

                for($x=0; $x<count($sector); $x++)
                    $sector[$x] = pack("V", $sector[$x]);

                $sector_data = implode($sector);
            }
            else
            {
                $sector_data = $this->stream->readBytes($sector_len);
            }

            $len = strlen($sector_data);
            $this->debugger->write(sprintf("Got %d bytes of sector data", $len));

            if ($len <= 0)
                continue;

            // Decompress the sector data if the compressed flag is found.
            if ($flag_compressed)
            {
                $this->debugger->write("Decompressing sector...");

                $compression_type = unpack("C", substr($sector_data, 0, 1))[1];  

                switch ($compression_type) 
                {
                    case MPQ_COMPRESSION_ZLIB:
                        $decompressed = ($len < 3 ? false : @gzinflate(substr($sector_data, 3, $len - 2)));

                        if ($len >= strlen($decompressed) || !$decompressed)
                        {
                            $this->debugger->write("Failed to decompress with gzip");
                            $output .= $sector_data;
                        }
                        else
                        {
                            $this->debugger->write("Decompressed with gzip!");
                            $output .= $decompressed;
                        }

                        break;

                    case MPQ_COMPRESSION_BZIP2:
                        $decompressed = @bzdecompress(substr($sector_data, 1));      

                        if ($len >= strlen($decompressed) || $decompressed < 0)
                        {
                            $this->debugger->write("Failed to decompress with bzip2");
                            $output .= $sector_data;
                        }
                        else
                        {
                            $this->debugger->write("Decompressed with bzip2!");
                            $output .= $decompressed;
                        }

                        break;

                    case MPQ_COMPRESSION_ADPCM_MONO | MPQ_COMPRESSION_HUFFMANN:
                        $data = (new Huffman())->decompress(substr($sector_data, 1));
                        $data = (new ADPCM(2))->decompress($data, 1);

                        $output .= $data;

                        $this->debugger->write(sprintf("Decompressed with ADPCM/Huffman (Mono)", $compression_type));

                        break;

                    case MPQ_COMPRESSION_ADPCM_STEREO | MPQ_COMPRESSION_HUFFMANN:
                        $data = (new Huffman())->decompress(substr($sector_data, 1));
                        $data = (new ADPCM(2))->decompress($data, 2);
 
                        $output .= $data;

                        $this->debugger->write(sprintf("Decompressed with ADPCM/Huffman (Stereo)", $compression_type));

                        break;

                    case MPQ_COMPRESSION_PKWARE:
                        $this->debugger->write(sprintf("Unsupported compression type: %d (PKWARE)", $compression_type));
                        $output .= $sector_data;

                        break;

                    case MPQ_COMPRESSION_LZMA:
                        $this->debugger->write(sprintf("Unsupported compression type (LZMA): %d", $compression_type));
                        $output .= $sector_data;

                        break;

                    case MPQ_COMPRESSION_SPARSE:
                    case MPQ_COMPRESSION_SPARSE | MPQ_COMPRESSION_ZLIB:
                    case MPQ_COMPRESSION_SPARSE | MPQ_COMPRESSION_BZIP2:
                        $this->debugger->write(sprintf("Unsupported compression type (Sparse): %d", $compression_type));
                        $output .= $sector_data;

                        break;

                    default:
                        $this->debugger->write(sprintf("Unsupported compression type: %d", $compression_type));
                        $output .= $sector_data;

                        break;

                }
            }
            else 
            {
                $output .= $sector_data;
            }
        }

        if (strlen($output) != $filesize) 
        {
            $this->debugger->write(sprintf("Decrypted/uncompressed filesize(%d) does not match original file size(%d)", strlen($output), $filesize));
        }

        return $output;
    }

}

?>
