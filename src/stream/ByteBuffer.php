<?php
namespace TriggerHappy\MPQ\Stream;

class ByteBuffer
{
    public $fp;
    private $data;
    public $datalen;

    function __construct($data) 
    {
        $this->fp = 0;
        $this->data = $data;
        $this->datalen = strlen($data);
    }

    public function get() 
    {
        return unpack("c", substr($this->data, $this->fp++, 1))[1];
    }

    public function getShort() 
    {
        $out = unpack("s", substr($this->data, $this->fp, 2))[1];
        $this->fp+=2;
        return $out;
    }

    public function canRead()
    {
        return $this->fp < $this->datalen;
    }
}

?>