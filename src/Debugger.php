<?php
namespace TriggerHappy\MPQ;

class Debugger
{   
    private $mpq;

    public function __construct($mpq)
    {
        $this->mpq=$mpq;
    }

    public function write($message) 
    { 
        if ($this->mpq->debug) 
            if(strpos($message, '<pre>')!==FALSE||strpos($message, '<br/>')!==FALSE) 
                echo $message; else echo $message."<br/>\n";
    }

    function hashTable()
    {
        if (!$this->mpq->debug || !MPQArchive::$DebugShowTables)
            return;

        $this->write("DEBUG: Hash table\n");
        $this->write("HashA, HashB, Language+platform, Fileblockindex\n");

        for ($i = 0; $i < $this->mpq->hashTableSize; $i++) 
        {
            $filehash_a = $this->mpq->readHashtable($i*4);
            $filehash_b = $this->mpq->readHashtable($i*4 +1);
            $lanplat = $this->mpq->readHashtable($i*4 +2);
            $blockindex = $this->mpq->readHashtable($i*4 +3);
            $this->write(sprintf("<pre>%08X %08X %08X %08X</pre>",$filehash_a, $filehash_b, $lanplat, $blockindex));
        }
    }

    function blockTable()
    {
        if (!$this->mpq->debug || !MPQArchive::$DebugShowTables)
            return;

        $this->write("DEBUG: Block table\n");
        $this->write("Offset, Blocksize, Filesize, flags\n");

        for ($i = 0;$i < $this->mpq->blockTableSize;$i++) 
        {   
            $block_index = $i * 4;
            $block_offset = $this->mpq->readBlocktable($block_index) + $this->mpq->getHeaderOffset();
            $block_size = $this->mpq->readBlocktable($block_index + 1);
            $filesize = $this->mpq->readBlocktable($block_index + 2);
            $flags = $this->mpq->readBlocktable($block_index + 3);
            $this->write(sprintf("<pre>%08X %8d %8d %08X</pre>",$block_offset, $block_size, $filesize, $flags));
        }
    }

    // prints block table or hash table, $data is the data in an array of UInt32s
    public function printTable($data) 
    {
        if (!$this->mpq->debug || !MPQArchive::$DebugShowTables)
            return;

        $this->write("Hash table: HashA, HashB, Language+platform, Fileblockindex\n");
        $this->write("Block table: Offset, Blocksize, Filesize, flags\n");

        $entries = count($data) / 4;

        for ($i = 0; $i < $entries; $i++) 
        {
            $block_index = $i * 4;
            $block_offset = $data[$block_index] + $this->mpq->getHeaderOffset();
            $block_size = $data[$block_index + 1];
            $filesize = $data[$block_index + 2];
            $flags = $data[$block_index + 3];
            $this->write(sprintf("<pre>%08X %08X %08X %08X</pre>",$block_offset, $block_size, $filesize, $flags));
        }
    }
}

?>