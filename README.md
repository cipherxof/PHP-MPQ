# PHP-MPQ
Handle the MPQ (MoPaQ) format natively from PHP.

Currently supported:
* MPQ Archives (v.1.0)
* Warcraft III Maps
* Starcraft II Maps

Basic Example
==========

```php
<?php
// include the mpq library
require 'src/mpq.php';

// open the archive
$mpq = new MPQArchive("wc3map.w3x");

// set our file we want to extract
$file_to_extract = "war3map.j";

// check if the archive contains the file
if ($mpq->hasFile($file_to_extract))
    
    // if it does, print it to the screen.
    echo $mpq->readFile($file_to_extract);
?>
```

Advanced Example
==========

```php
<?php

require 'src/mpq.php';

// open the archive and catch any errors
try{
    $mpq = new MPQArchive("wc3map.w3x");
}
catch(MPQException $error){
    die(nl2br("<strong>Error:</strong> " . $error->getMessage() . "\n\n" . $error));
}

// process our archive according to what type it is
switch($mpq->getType())
{   
    // Warcraft III
    case MPQArchive::TYPE_WC3MAP:

        // maps can have their scripts in one of two places, so we check which
        $file = ($mpq->hasFile("Scripts\\War3map.j") ? "Scripts\\War3map.j" : "war3map.j");

        // try to extract the script
        $result = $mpq->readFile($file);

        if (!$result)
            die("Failed to extract $file.\n");

        break;

    // Starcraft II
    case MPQArchive::TYPE_SC2MAP:

        // the script file for sc2 maps
        $file = "MapScript.galaxy";
        $result = $mpq->readFile($file);

        if (!$result)
            die("Failed to extract $file.\n");

        break;

    default:

        $file = "Scripts\\common.j";
        $result = $mpq->readFile($file);

        if (!$result)
            die("Failed to extract $file.\n");

        break;
}

// check if any files were extracted
if ($result != false)
{
    // write our extracted file to disk
    file_put_contents(basename($file), $result);

    // check if the archive is a game
    $map = $mpq->getGameData();

    if ($map != null)
    {
        // print some details about the game
        echo $map->getName() . "<br/>";
    }

    // show the extracted file
    echo nl2br("$file extracted.\n\n");
    echo nl2br($result);
}

?>
```
Based on https://code.google.com/archive/p/phpsc2replay/
