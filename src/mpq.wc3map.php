<?php

class WC3Map extends MPQArchive
{
    protected $name;
    protected $flags;
    protected $playerRec;
    protected $archive;

    private $parsed;

    // war3map.w3i
    private $author, $desc, $tileset, $width, $height, $formatVersion, $loadScreen, $maxPlayers;
    
    // war3map.wts
    private $wts;

    function __construct($mpq) 
    {
    	if (!is_a($mpq, "MPQArchive"))
    		throw new MPQException("__construct in the WC3Map class must use a valid MPQArchive handle.");

        $this->archive 	= $mpq;
        $this->name 	= "";
        $this->parsed 	= 0;
    }

    public function getParseStatus(){ return $this->parse; } // 1 = success
    public function getVersion(){ return $this->formatVersion; } // 1 = TFT, 0 = ROC
    public function getName(){ return $this->name; }
    public function getFlags(){ return $this->flags; }
    public function getSuggestedPlayers(){ return $this->playerRec; }
    public function getPlayerCount(){ return $this->maxPlayers; }
    public function getPlayableArea(){ return array('width'=>$this->width, 'height'=>$this->height); }
    public function getTileset(){ return $this->tileset; }

    public function getAuthor()
    {
        $result = $this->checkParsed();

    	return ($result == true ? $this->author : $result);
	}

    public function getDescription()
    { 
        $result = $this->checkParsed();

        return ($result == true ? $this->desc : $result);
	}

  	public function parseData()
  	{
  		if (!$this->archive->hasFile('war3map.w3i') || !$this->archive->hasFile('war3map.wts'))
  		{
  			$this->parsed = 2;

  			return false;
  		}

  		$info = $this->archive->readFile("war3map.w3i");

        // parse header
        $this->formatVersion = MPQReader::UInt16($info, $fp);

        // name, author, description, suggested players
	    $fp   = 12;
	    $data = array('','','','');

	    for ($i=0; $i < 4; $i++)
            $data[$i] = MPQReader::String($info, $fp);

        $this->name = $this->readTriggerString($data[0]);
        $this->author = $this->readTriggerString($data[1]);
        $this->desc = $this->readTriggerString($data[2]);
        $this->playerRec = $this->readTriggerString($data[3]);

        // map playable area
        $fp+=40;
        $bounds = array('','','','');

        for($i=0; $i<4; $i++)
            $bounds[$i] = MPQReader::UInt16($info, $fp);

        $this->width  = MPQReader::UInt32($info, $fp);
        $this->height = MPQReader::UInt32($info, $fp);

        $fp += 4;

        // tileset
        $ground_type   = MPQReader::byte($info, $fp);
        $this->tileset = MPQGameData::getWar3Tileset(chr($ground_type));

        // loadscreen data
        $this->loadScreen['index'] = MPQReader::UInt8($info, $fp);
        $data = array('', '' ,'' ,'');

        for ($i=0; $i < 4; $i++)
        {
            $data[$i] = MPQReader::String($info, $fp);

            if ($i == 0)
                $fp+=8;
            
        }

        $this->loadScreen['path']     = $this->readTriggerString($data[0]);
        $this->loadScreen['text']     = $this->readTriggerString($data[1]);
        $this->loadScreen['title']    = $this->readTriggerString($data[2]);
        $this->loadScreen['subtitle'] = $this->readTriggerString($data[3]);
        
        $uses_terrain_fog = MPQReader::UInt32($info, $fp);
        /*$weather_id = MPQReader::UInt8($info, $fp);

        $sound_env = "";

        while ( ($s = MPQReader::byte($info, $fp)) != 0)
            $sound_env .= chr($s);

        $this->maxPlayers = MPQReader::UInt8($info, $fp);*/

	    $this->parsed = 1;

        return true;
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

	    if (count($num) <= 1)
	        return false;

	    $num   = intval($num[1]);
        $split = strstr($this->wts, "STRING " . $num);

        if (!$split) return false;
	    $split = substr(strstr($split, "{"), 1);
	    if (!$split) return false;
        $split = strstr($split, "}", true);
        if (!$split) return false;

	    return trim($split);
    }

    private function checkParsed()
    {
        if ($this->parsed == 0)
            throw new MPQException($this->archive,'Must call $mpq->getGameData()->parseData() before using this method.');

        if ($this->parsed != 1)
            return false;

        return true;
    }

}


?>