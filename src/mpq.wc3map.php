<?php

class WC3Map extends MPQArchive
{
    protected $name;
    protected $flags;
    protected $maxPlayers;
    protected $archive;

    private $author, $desc;
    private $parsed;
    private $wts;

    function __construct($mpq) 
    {
    	if (!is_a($mpq, "MPQArchive"))
    		throw new MPQException("__construct in the WC3Map class must use a valid MPQArchive handle.");

        $this->archive 	= $mpq;
        $this->name 	= "";
        $this->parsed 	= 0;
    }

    public function getParseStatus(){ return $this->parse; } // 1 == success
    public function getName(){ return $this->name; }
    public function getPlayerCount(){ return $this->maxPlayers; }
    public function getFlags(){ return $this->flags; }

    public function getAuthor()
    { 
    	if ($this->parsed != 1)
    		return false;

    	return $this->author; 
	}

    public function getDescription()
    { 
    	if ($this->parsed != 1)
    		return false;

    	return $this->desc; 
	}

  	protected function parseData()
  	{
  		if (!$this->archive->hasFile('war3map.w3i'))
  		{
  			$this->parsed = 2;

  			return false;
  		}

  		$info = $this->archive->readFile("war3map.w3i");
	    $fp   = 12;
	    $data = array('', '', '', '');

	    for ($i=0; $i < 4; $i++)
	    {
		    while ( ($s = MPQReader::byte($info, $fp)) != 0)
		    {
		    	$data[$i] .= chr($s);
		    }
		}

	    $this->name = $this->readTriggerString($data[0]);
	    $this->author = $this->readTriggerString($data[1]);
	    $this->desc = $this->readTriggerString($data[2]);
	    $this->maxPlayers = $this->readTriggerString($data[3]);

	    $this->parsed = 1;
  	}

    public function readTriggerString($source)
    {
    	if (strpos($source, "TRIGSTR_") === false)
    		return $source;

    	if (!isset($this->wts))
    	{
    		if (!$this->archive->hasFile('war3map.wts'))
    			return false;

    		$this->wts = $this->archive->readFile('war3map.wts');
    	}

	    $num = explode("TRIGSTR_", $source);

	    if (count($num) < 1)
	        return false;

	    $num = intval($num[1]);

	    $split = explode("STRING " . $num, $this->wts);

	    if (count($split) < 1)
	        return false;

	    $split = explode("{", $split[1]);

	    if (count($split) < 1)
	        return false;

	    $split = explode("}", $split[1]);

	    if (count($split) < 1)
	        return false;

	    return trim($split[0]);
    }

}


?>