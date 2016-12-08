<?php

class WC3Map extends MPQArchive
{
    protected $name;
    protected $flags;
    protected $playerRec;
    protected $archive;

    private $parsed;

    // war3map.w3i
    private $author, $desc, $tileset, $width, $height, $formatVersion, $saveCount, $ediRtorVer, $loadScreen, $maxPlayers, $playerCount;
    
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
    public function getVersion(){ return $this->formatVersion; } // 25 = TFT, 18 = ROC
    public function getName(){ return $this->name; }
    public function getFlags(){ return $this->flags; }
    public function getSuggestedPlayers(){ return $this->playerRec; }
    public function getPlayerCount(){ return $this->playerCount; }
    public function getMaxPlayers(){ return $this->maxPlayers; }
    public function getPlayableArea(){ return array('width'=>$this->width, 'height'=>$this->height); }
    public function getTileset(){ return $this->tileset; }
    public function getLoadscreen(){ return $this->loadScreen; }
    public function getAuthor(){ return ($this->checkParsed() ? $this->author : $this->parsed); }
    public function getDescription(){ return ($this->checkParsed() ? $this->desc : $this->parsed); }

    private function checkParsed()
    {
        if ($this->parsed == 0)
            throw new MPQException($this->archive,'Must call $mpq->getGameData()->parseData() before using this method.');

        if ($this->parsed != 1)
            return false;

        return true;
    }

    public function parseData()
    {
        if (!$this->archive->hasFile('war3map.w3i'))
        {
            $this->parsed = 2;

            return false;
        }

        $info = $this->archive->readFile("war3map.w3i");
        $fp   = 0;

        // parse header
        $this->formatVersion = MPQReader::UInt32($info, $fp);
        $this->saveCount     = MPQReader::UInt32($info, $fp);
        $this->editorVer     = MPQReader::UInt32($info, $fp);

        $this->name      = $this->readTriggerString(MPQReader::String($info, $fp));
        $this->author    = $this->readTriggerString(MPQReader::String($info, $fp));
        $this->desc      = $this->readTriggerString(MPQReader::String($info, $fp));
        $this->playerRec = $this->readTriggerString(MPQReader::String($info, $fp));

        // map playable area
        $fp += 40;
        $bounds = array();

        for($i=0; $i<4; $i++)
            $complements[$i] = MPQReader::UInt16($info, $fp);

        $this->width  = MPQReader::UInt32($info, $fp);
        $this->height = MPQReader::UInt32($info, $fp);

        $fp += 4;

        // tileset
        $this->tileset = MPQGameData::getWar3Tileset(chr(MPQReader::byte($info, $fp)));

        $fp+=1;

        // loadscreen data
        $this->loadScreen['index'] = MPQReader::UInt8($info, $fp);
        $data = array_fill(0, 4, 0);

        $fp+=2;

        for ($i=0; $i < 4; $i++)
        {
            $data[$i] = MPQReader::String($info, $fp);
        }

        $this->loadScreen['path']     = $this->readTriggerString($data[0]);
        $this->loadScreen['text']     = $this->readTriggerString($data[1]);
        $this->loadScreen['title']    = $this->readTriggerString($data[2]);
        $this->loadScreen['subtitle'] = $this->readTriggerString($data[3]);

        $type = array('', 'Human', 'Computer', 'Neutral', 'Rescuable');

        switch($this->formatVersion)
        {
            case 18:
                for ($i=0; $i < 2; $i++)
                    $data[$i] = MPQReader::String($info, $fp);

                $this->maxPlayers = MPQReader::UInt8($info, $fp);
 
                break;

            case 25:
                $gamedataset = MPQReader::UInt8($info, $fp);
                $fp += 4;

                for ($i=0; $i < 4; $i++)
                    $data[$i] = MPQReader::String($info, $fp);

                $terrain_fog        = MPQReader::UInt8($info, $fp);
                $fp += 22;
                $sound_env          = MPQReader::String($info, $fp);
                $fp += 5;
                $this->maxPlayers   = MPQReader::UInt8($info, $fp);
                $this->playerCount  = 0;

                break;
        }

        $fp += 3;

        // loop through each player
        for($player=0; $player < $this->maxPlayers; $player++)
        {
            $pnum  = MPQReader::UInt8($info, $fp);
            $fp += 3;
            $ptype = MPQReader::UInt8($info, $fp);
            $fp += 3;
            $prace = MPQReader::UInt8($info, $fp);
            $fp += 3;
            $stpos = MPQReader::UInt8($info, $fp);
            $fp += 3;
            $name  = $this->readTriggerString(MPQReader::String($info, $fp));

            if ($ptype == 1)
                $this->playerCount++;

            $fp += 16;
        }

        $this->parsed = 1;

        return true;
    }

    public function readTriggerString($source)
    {
        $file = ($this->archive->getType() == MPQArchive::TYPE_WC3CAMPAIGN ? 'war3campaign.wts' : 'war3map.wts');

        if (!$this->archive->hasFile($file))
            return $source;

        if (strpos($source, "TRIGSTR_") === false)
            return $source;

        if (!isset($this->wts))
            $this->wts = $this->archive->readFile($file);

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

}


?>