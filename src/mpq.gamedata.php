<?php

class WC3Map
{
	private $name;
	private $flags;
	private $maxplayers;

	function __construct($name, $flags, $players) 
	{
		$this->name=$name;
		$this->flags=$flags;
		$this->maxplayers=$players;
	}

	function getName(){ return $this->name; }
	function getPlayerCount(){ return $this->maxplayers; }
	function getFlags(){ return $this->flags; }
}

class SC2Map
{
	private $verMajor;
	private $build;
	private $gameLen;
	private $versionString;

	function __construct($data) 
	{
		$this->verMajor = $data[1][1];
		$this->build = $data[1][4];
		$this->gameLen = ceil($data[3] / 16);
		$this->versionString = sprintf("%d.%d.%d.%d",$this->verMajor, $data[1][2], $data[1][3], $this->build);
	}

	function getVersionString(){ return $this->versionString; }
}

?>