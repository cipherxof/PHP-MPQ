# PHP-MPQ
Handle the MPQ (MoPaQ) format natively from PHP.

Currently supported:
* MPQ Archives (v.1.0)
* Warcraft III Maps
* Starcraft II Maps

Demo: http://185.92.220.118/mapdb/

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
    
// display the file
echo $mpq->readFile($file_to_extract);
?>
```

Advanced Example
==========

```php
<?php
require 'src/mpq.php';

MPQArchive::$debugShowTables = false;

// open the archive and catch any errors
try{
    $mpq = new MPQArchive("maps/tkok.w3x", /*debug=*/false);
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

        break;

    // Starcraft II
    case MPQArchive::TYPE_SC2MAP:
        $file = "MapScript.galaxy";

        break;

    // MPQ
    default:
        $file = "(listfile)";

        break;
}

// try to extract the script
$result = $mpq->readFile($file);

// check if any files were extracted
if (!$result)
    die("Failed to extract $file.\n");

$output = "";

// write our extracted file to disk
file_put_contents(basename($file), $result);

// check if the archive is a game
$map = $mpq->getGameData();

if ($map != null)
{
    // get some details about the game
    $output .= $map->getName() . "\n";
    $output .= "by " . $map->getAuthor() . "\n";
    $output .= "\n" . $map->getDescription() . "\n\n";
}

$output .= "$file extracted.\n\n$result";

// print the output
echo (php_sapi_name() == 'cli' ? $output : nl2br($output));

?>
```
Based on https://code.google.com/archive/p/phpsc2replay/ and StormLib.
