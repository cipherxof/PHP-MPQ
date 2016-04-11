<?php
class MPQDebugger extends MPQArchive
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
                echo $message; else echo $message.'<br/>';
    }

    function hashTable()
    {
        if (!$this->mpq->debug)
            return;

        $this->write("DEBUG: Hash table\n");
        $this->write("HashA, HashB, Language+platform, Fileblockindex\n");

        for ($i = 0; $i < $this->mpq->hashTableSize; $i++) 
        {
            $filehashA = $this->mpq->hashtable[$i*4];
            $filehashB = $this->mpq->hashtable[$i*4 +1];
            $lanplat = $this->mpq->hashtable[$i*4 +2];
            $blockindex = $this->mpq->hashtable[$i*4 +3];
            $this->write(sprintf("<pre>%08X %08X %08X %08X</pre>",$filehashA, $filehashB, $lanplat, $blockindex));
        }
    }

    function blockTable()
    {
        if (!$this->mpq->debug)
            return;

        $this->write("DEBUG: Block table\n");
        $this->write("Offset, Blocksize, Filesize, flags\n");

        for ($i = 0;$i < $this->mpq->blockTableSize;$i++) 
        {
            $blockIndex = $i * 4;
            $blockOffset = $this->mpq->blocktable[$blockIndex] + $this->mpq->headerOffset;
            $blockSize = $this->mpq->blocktable[$blockIndex + 1];
            $fileSize = $this->mpq->blocktable[$blockIndex + 2];
            $flags = $this->mpq->blocktable[$blockIndex + 3];
            $this->write(sprintf("<pre>%08X %8d %8d %08X</pre>",$blockOffset, $blockSize, $fileSize, $flags));
        }
    }

    // prints block table or hash table, $data is the data in an array of UInt32s
    public function printTable($data) 
    {
        if (!$this->mpq->debug)
            return;

        $this->write("Hash table: HashA, HashB, Language+platform, Fileblockindex\n");
        $this->write("Block table: Offset, Blocksize, Filesize, flags\n");

        $entries = count($data) / 4;

        for ($i = 0; $i < $entries; $i++) 
        {
            $blockIndex = $i * 4;
            $blockOffset = $data[$blockIndex] + $this->mpq->headerOffset;
            $blockSize = $data[$blockIndex + 1];
            $fileSize = $data[$blockIndex + 2];
            $flags = $data[$blockIndex + 3];
            $this->write(sprintf("<pre>%08X %08X %08X %08X</pre>",$blockOffset, $blockSize, $fileSize, $flags));
        }
    }
}

class MPQException extends Exception 
{ 
    public function __construct($mpq, $message = null, $code = 0)
    {
        $mpq->debugger->write($message);
        parent::__construct($message, $code);
    }
}
?>