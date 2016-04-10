<?php

require 'mpq.crypto.php';
require 'mpq.reader.php';
require 'mpq.gamedata.php';

define("MPQ_HASH_TABLE_OFFSET", 0);
define("MPQ_HASH_NAME_A", 1);
define("MPQ_HASH_NAME_B", 2);
define("MPQ_HASH_FILE_KEY", 3);
define("MPQ_HASH_ENTRY_EMPTY", -1);
define("MPQ_HASH_ENTRY_DELETED", -2);

class MPQArchive 
{
	const TYPE_UNKNOWN = 0;
	const TYPE_WC3MAP = 1;
	const TYPE_SC2MAP = 2;

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
	private $type;

	private $initialized = false;
	private $map = null;

	private $fileData;
	private $archiveSize;

	private $hashtable, $blocktable = NULL;
	private $hashTableSize, $blockTableSize = 0;
	private $hashTableOffset, $blockTableOffset, $headerOffset = 0;
	private $sectorSize = 0;
	
	public $debug;

	function __construct($filename, $debug=false) 
	{
		$this->filename = $filename;
		$this->debug = $debug;

		if (!MPQCrypto::$table)
			MPQCrypto::initCryptTable();
		
		if (file_exists($filename)) 
		{
			$fp = fopen($filename, 'rb');
			$contents = fread($fp, filesize($filename));

			if ($contents === false) 
				$this->debug("Error opening file $filename for reading");
			elseif ($contents !== false)
				$this->fileData = $contents;

			fclose($fp);
		}

		$this->parseHeader();
	}

	function __destruct() 
	{
		if ($this->fp != null) 
			fclose($this->fp);
	}

	function getType() { return $this->type; }
	function getFilename() { return $this->filename; }
	function getHashTable() { return $this->hashtable; }
	function getBlockTable() { return $this->blocktable; }
	function gameData(){ return $this->map; }
	function isinitialized() { return $this->initialized === true; }

	function hasFile($filename)
	{ 
		return $this->getFileSize($filename) > 0;
	}

	function parseHeader() 
	{
		$fp = 0;
		$headerParsed = false;
		$headerOffset = 0;

		while (!$headerParsed) 
		{
			$byte = unpack("c4", MPQReader::bytes($this->fileData,$fp, 4));

			if (($byte[1] == 0x48) || ($byte[2] == 0x4D) || ($byte[3] == 0x33)) // WC3
			{
				// store the type of archive
				$this->type=self::TYPE_WC3MAP;

				// find the name of the map
				$mapname = "";
				$fp+=4;

				// read until we find zero
				while ( ($s = MPQReader::byte($this->fileData,$fp)) != 0)
				{
					$mapname .= chr($s);
				}

				// store map data (name, flags, playercount)
				$this->map = new WC3Map($mapname, MPQReader::UInt32($this->fileData, $fp), MPQReader::UInt32($this->fileData, $fp));

				// find the header section
				$headerOffset = $fp;
				$foundHeader = false;
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

				if (!$foundHeader)
					$fp=0x200; // guess
				else
					$this->debug(sprintf("Found header at %08X", $fp));

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
			elseif ((($byte[1] == 0x4D) || ($byte[2] == 0x50) || ($byte[3] == 0x51))) // SC2
			{
				$this->type = self::TYPE_SC2MAP;

				if ($byte[4] == 27) 
				{ // user data block (1Bh)
					$this->debug(sprintf("Found user data block at %08X", $fp));

					$uDataMaxSize = MPQReader::UInt32($this->fileData, $fp);
					$headerOffset = MPQReader::UInt32($this->fileData, $fp);
					$this->headerOffset = $headerOffset;
					$uDataSize = MPQReader::UInt32($this->fileData, $fp);
					$uDataStart = $fp;

					$this->map = new SC2Map(MPQArchive::parseSerializedData($this->fileData,$fp));
					
					$fp = $headerOffset;
				}
				else if ($byte[4] == 26) 
				{ // header (1Ah)
					$this->debug(sprintf("Found header at %08X", $fp));

					$headerSize = MPQReader::UInt32($this->fileData, $fp);
					$this->archiveSize = MPQReader::UInt32($this->fileData, $fp);
					$formatVersion = MPQReader::UInt16($this->fileData, $fp);
					$sectorSizeShift = MPQReader::byte($this->fileData, $fp);
					$sectorSize = 512 * pow(2,$sectorSizeShift);
					$this->sectorSize = $sectorSize;

					$fp++;
					$this->hashTableOffset = MPQReader::UInt32($this->fileData, $fp) + $headerOffset;
					$this->blockTableOffset = MPQReader::UInt32($this->fileData, $fp) + $headerOffset; 
					$this->hashTableSize = MPQReader::UInt32($this->fileData, $fp);
					$this->blockTableSize = MPQReader::UInt32($this->fileData, $fp);
					
					$headerParsed = true;
				}
				else 
				{
					$this->debug("Could not find MPQ header");
					return false;
				}
			}
			else
			{
				$this->initialized = false; 
				return false;
			}
		}

		$this->debug(sprintf("Hash table offset: %08X, Block table offset: %08X", $this->hashTableOffset, $this->blockTableOffset));

		// read and decode the hash table
		$fp = $this->hashTableOffset;
		$hashSize = $this->hashTableSize * 4; // hash table size in 4-byte chunks
		$data = array();

		for ($i = 0;$i < $hashSize;$i++)
			$data[$i] = MPQReader::UInt32($this->fileData, $fp);

		if ($this->debug) 
		{
			$this->debug("Encrypted hash table:");
			$this->printTable($data);
		}

		$this->hashtable = MPQCrypto::decrypt($data, MPQCrypto::hashString("(hash table)", MPQ_HASH_FILE_KEY));
		$this->debugHashTable();

		// read and decode the block table
		$fp = $this->blockTableOffset;
		$blockSize = $this->blockTableSize * 4; // block table size in 4-byte chunks
		$data = array();

		for ($i = 0;$i < $blockSize;$i++)
			$data[$i] = MPQReader::UInt32($this->fileData, $fp);

		if ($this->debug) 
		{
			$this->debug("Encrypted block table:");
			$this->printTable($data);
		}

		$this->blocktable = MPQCrypto::decrypt($data, MPQCrypto::hashString("(block table)", MPQ_HASH_FILE_KEY));
		$this->debugBlockTable();

		$this->initialized = true;
		
		return true;
	}
	
	function readFile($filename) 
	{
		if (!$this->initialized) 
		{
			$this->debug("Tried to use getFile without initializing");
			return false;
		}

		$hashA = MPQCrypto::hashString($filename, MPQ_HASH_NAME_A);
		$hashB = MPQCrypto::hashString($filename, MPQ_HASH_NAME_B);
		$hashStart = MPQCrypto::hashString($filename, MPQ_HASH_TABLE_OFFSET) & ($this->hashTableSize - 1);
		
		// search the hashtable for the file
		$blockSize = -1;
		$x = $hashStart;
		do 
		{
			if (($this->hashtable[$x*4 + 3] == MPQ_HASH_ENTRY_DELETED) || ($this->hashtable[$x*4 + 3] == MPQ_HASH_ENTRY_EMPTY)) 
				return false;

			if (($this->hashtable[$x*4] == $hashA) && ($this->hashtable[$x*4 + 1] == $hashB)) { // found file
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
			if ($this->debug) $this->debug("Did not find file $filename in archive");
			return false;
		}

		// set flags
		$flag_file       = $flags & self::FLAG_FILE;
		$flag_checksums  = $flags & self::FLAG_CHECKSUMS;
		$flag_deleted    = $flags & self::FLAG_DELETED;
		$flag_singleunit = $flags & self::FLAG_SINGLEUNIT;
		$flag_hEncrypted = $flags & self::FLAG_HENCRYPTED;
		$flag_encrypted  = $flags & self::FLAG_ENCRYPTED;
		$flag_compressed = $flags & self::FLAG_COMPRESSED;
		$flag_imploded   = $flags & self::FLAG_IMPLODED;
		
		$this->debug(sprintf("Found $filename with flags %08X, block offset %08X, block size %d and filesize %d", $flags, $blockOffset,$blockSize,$fileSize));
		
		if (!$flag_file) return false;

		// generate encryption key if the file is encrpyted
		if ($flag_encrypted)
		{
			$filename = basename($filename);
			$cryptKey = MPQCrypto::hashString($filename, MPQ_HASH_FILE_KEY);

			// adjusted encryption key
			if ($flag_hEncrypted)
			{
				$cryptKey = (($cryptKey + $blockOffset) ^ $fileSize);
			}
		}

		// sector data offset
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

		for ($i = 0; $i < $sector_count; $i++) 
		{
			$sectorLen = $sectors[$i + 1] - $sectors[$i];

			if ($sectorLen == 0)
				$sectorLen = $blockSize;

			if ($sectorLen == 0) break;

			$fp = ($blockOffset + $offset) + $sectors[$i];

			if ($flag_encrypted) 
			{
				$sectorBytes = array();
				$sectorLen >>= 2;

				if ($sectorLen > $fileSize)
					return false;

				for($x=0; $x<=$sectorLen; $x++)
					$sectorBytes[] = MPQReader::UInt32($this->fileData, $fp);

				$sectorBytes = MPQCrypto::decrypt($sectorBytes, (int)($cryptKey + $i));

				for($x=0; $x<count($sectorBytes); $x++)
					$sectorBytes[$x] = pack("V", $sectorBytes[$x]);

				$sectorData = implode($sectorBytes);

			}
			else
			{
				$sectorData = MPQReader::bytes($this->fileData, $fp, $sectorLen);
			}

			$this->debug(sprintf("Got %d bytes of sector data", strlen($sectorData)));

			if ($flag_compressed)
			{
				$numByte = 0;

				$compressionType = MPQReader::byte($sectorData, $numByte);
				$sectorData = substr($sectorData,1);

				switch ($compressionType) 
				{
					case 0x02:
						$this->debug(sprintf("Found compresstion type: %d (gzlib)", $compressionType));

						$decompressed = gzinflate(substr($sectorData, 2, strlen($sectorData) - 2));

						if (!$decompressed)
						{
							$this->debug(sprintf("Failed to decompress with compression type: %d", $compressionType));
							$output .= $sectorData;
							break;
						}

						$output .= $decompressed;

						break;
					case 0x10:                     
						$output .= bzdecompress($sectorData);
						break;
					default:
						$this->debug(sprintf("Unknown compression type: %d", $compressionType));
						break;
				}
			}
			else $output .= $sectorData;
		}

		if (strlen($output) != $fileSize) 
		{
			$this->debug(sprintf("Decrypted/uncompressed file size(%d) does not match original file size(%d)", strlen($output),$fileSize));
			return $output;
		}

		return $output;
	}

	function getFileSize($filename) 
	{
		if (!$this->initialized) 
		{
			$this->debug("Tried to use getFileSize without initializing\n");
			return false;
		}

		$hashA = MPQCrypto::hashString($filename, MPQ_HASH_NAME_A);
		$hashB = MPQCrypto::hashString($filename, MPQ_HASH_NAME_B);
		$hashStart = MPQCrypto::hashString($filename, MPQ_HASH_TABLE_OFFSET) & ($this->hashTableSize - 1);
		$tmp = $hashStart;

		do
		{
			if (($this->hashtable[$tmp*4 + 3] == MPQ_HASH_ENTRY_DELETED) || ($this->hashtable[$tmp*4 + 3] == MPQ_HASH_ENTRY_EMPTY)) return false;

			if (($this->hashtable[$tmp*4] == $hashA) && ($this->hashtable[$tmp*4 + 1] == $hashB)) // found file
			{
				$blockIndex = ($this->hashtable[($tmp *4) + 3]) *4;
				$fileSize = $this->blocktable[$blockIndex + 2];
				return $fileSize;
			}
			$tmp = ($tmp + 1) % $this->hashTableSize;
		} while($tmp != $hashStart);

		$this->debug("Did not find file $filename in archive\n");

		return false;
	}

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
					$array[] = MPQReader::parseSerializedData($string,$numByte);
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
					$array[$index] = MPQReader::parseSerializedData($string,$numByte);
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

	private function debug($message) 
	{ 
		if ($this->debug) if(strpos($message, '<pre>')!==FALSE||strpos($message, '<br/>')!==FALSE) echo $message; else echo $message.'<br/>';
	}

	private function debugHashTable()
	{
		$this->debug("DEBUG: Hash table\n");
		$this->debug("HashA, HashB, Language+platform, Fileblockindex\n");

		for ($i = 0; $i < $this->hashTableSize; $i++) 
		{
			$filehashA = $this->hashtable[$i*4];
			$filehashB = $this->hashtable[$i*4 +1];
			$lanplat = $this->hashtable[$i*4 +2];
			$blockindex = $this->hashtable[$i*4 +3];
			$this->debug(sprintf("<pre>%08X %08X %08X %08X</pre>",$filehashA, $filehashB, $lanplat, $blockindex));
		}
	}

	private function debugBlockTable()
	{
		$this->debug("DEBUG: Block table\n");
		$this->debug("Offset, Blocksize, Filesize, flags\n");

		for ($i = 0;$i < $this->blockTableSize;$i++) 
		{
			$blockIndex = $i * 4;
			$blockOffset = $this->blocktable[$blockIndex] + $this->headerOffset;
			$blockSize = $this->blocktable[$blockIndex + 1];
			$fileSize = $this->blocktable[$blockIndex + 2];
			$flags = $this->blocktable[$blockIndex + 3];
			$this->debug(sprintf("<pre>%08X %8d %8d %08X</pre>",$blockOffset, $blockSize, $fileSize, $flags));
		}
	}

	// prints block table or hash table, $data is the data in an array of UInt32s
	function printTable($data) 
	{
		$this->debug("Hash table: HashA, HashB, Language+platform, Fileblockindex\n");
		$this->debug("Block table: Offset, Blocksize, Filesize, flags\n");
		$entries = count($data) / 4;

		for ($i = 0;$i < $entries;$i++) 
		{
			$blockIndex = $i * 4;
			$blockOffset = $data[$blockIndex] + $this->headerOffset;
			$blockSize = $data[$blockIndex + 1];
			$fileSize = $data[$blockIndex + 2];
			$flags = $data[$blockIndex + 3];
			$this->debug(sprintf("<pre>%08X %08X %08X %08X</pre>",$blockOffset, $blockSize, $fileSize, $flags));
		}
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