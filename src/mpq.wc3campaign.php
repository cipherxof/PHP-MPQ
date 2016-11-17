<?php

class WC3Campaign extends WC3Map
{
    private $mapCount, $mapList;

    function __construct($wc3map)
    {
    	if (!is_a($wc3map, "WC3Map"))
    		throw new MPQException("__construct in the WC3Campaign class must use a valid WC3Map handle.");

        $this->archive 	= $wc3map->archive;
        $this->name 	= "";
        $this->parsed 	= 0;
    }

    public function getPlayerCount(){ return 1; }
    public function getMaxPlayers(){ return 1; }
    public function getAuthor(){ return ($this->checkParsed() ? $this->author : $this->parsed); }
    public function getDescription(){ return ($this->checkParsed() ? $this->desc : $this->parsed); }
    public function getMapCount(){ return ($this->checkParsed() ? $this->mapCount : $this->parsed); }
    public function getMapInfo(){ return $this->mapList; } 

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
        if (!$this->archive->hasFile('war3campaign.w3f'))
        {
            $this->parsed = 2;

            return false;
        }

        $info = $this->archive->readFile("war3campaign.w3f");
        $fp   = 0;

        // parse header
        $this->formatVersion = MPQReader::UInt32($info, $fp);
        $this->saveCount     = MPQReader::UInt32($info, $fp);
        $this->editorVer     = MPQReader::UInt32($info, $fp);
        $this->name          = $this->readTriggerString(MPQReader::String($info, $fp));
        $this->difficulty    = $this->readTriggerString(MPQReader::String($info, $fp));
        $this->author        = $this->readTriggerString(MPQReader::String($info, $fp));
        $this->desc          = $this->readTriggerString(MPQReader::String($info, $fp));

        $difficulty_flag = MPQReader::UInt32($info, $fp);
        $screen_index    = MPQReader::UInt32($info, $fp);
        MPQReader::String($info, $fp); // custom bg screen
        MPQReader::String($info, $fp); // minimap path
        MPQReader::UInt8($info, $fp);  // sound index
        MPQReader::String($info, $fp); // sound path
        MPQReader::UInt8($info, $fp);  // terrain fog

        // skip
        $fp += 23;
        while ( (MPQReader::byte($info, $fp)) == 0){
        }
        $fp -= 1;

        // number of maps in the campaign
        $this->mapCount = MPQReader::UInt32($info, $fp);
        $this->mapList = array();

        // loop through each map
        for($mapId=0; $mapId < $this->mapCount; $mapId++)
        {
            $map = array();//$this->mapList[$mapId];

            MPQReader::UInt32($info, $fp);  // map visible
            $map['chapter'] = $this->readTriggerString(MPQReader::String($info, $fp));
            $map['name']    = $this->readTriggerString(MPQReader::String($info, $fp));
            $map['path']    = MPQReader::String($info, $fp);

            $this->mapList[$mapId] = $map;
        }
        
        $this->parsed = 1;

        return true;
    }

}


?>