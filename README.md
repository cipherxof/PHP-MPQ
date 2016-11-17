# PHP-MPQ
Handle the MPQ (MoPaQ) format natively from PHP.

Currently supported:
* MPQ Archives (v1.0)
* Warcraft III Maps
* Warcraft III Campaigns
* Starcraft II Maps

Basic Example
==========

```php
<?php
require 'src/mpq.php';

// view hash/block tables
MPQArchive::$debugShowTables = false;

// open the archive and catch any errors
try
{
    $mpq = new MPQArchive("maps/wc3map.w3x", /*debug=*/true);
    $map = null;

    // process our archive according to what type it is
    switch($mpq->getType())
    {   
        // Warcraft III
        case MPQArchive::TYPE_WC3MAP:
            $file = ($mpq->hasFile("war3map.j") ? "war3map.j" : "Scripts\\war3map.j");
            $map  = $mpq->getGameData();

            break;

        case MPQArchive::TYPE_WC3CAMPAIGN:
            $file = 'war3campaign.w3f';
            $map  = $mpq->getGameData();

            break;

        // Starcraft II
        case MPQArchive::TYPE_SC2MAP:
            $file = "MapScript.galaxy";
            $map  = $mpq->getGameData();

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
    if ($map != null && $map->parseData())
    {
        // get some details about the game
        $output .= sprintf("(%d) %s\nby %s\n\n", $map->getPlayerCount(), $map->getName(), $map->getAuthor());
        $output .= sprintf("[Description]\n%s\n\n", $map->getDescription());
    }

    $output .= "$file extracted.\n\n$result";

    // print the output
    echo (php_sapi_name() == 'cli' ? $output : nl2br($output));
}
catch(MPQException $error)
{
    die(nl2br("<strong>Error:</strong> " . $error->getMessage() . "\n\n" . $error));
}

?>
```
Based on https://code.google.com/archive/p/phpsc2replay/ and StormLib.
