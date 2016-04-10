# PHP-MPQ
Handle the MPQ (MoPaQ) format natively from PHP with support for Warcraft III &amp; Starcraft II Maps.

```php
<?php

require 'src/mpq.php';

//$mpq = new MPQArchive("test.w3x");
//$mpq = new MPQArchive("test.SC2Map");
$mpq = new MPQArchive("War3Patch.mpq");

if (!$mpq->isInitialized())
    die("Failed to open archive.");

switch($mpq->getType())
{
    case MPQArchive::TYPE_WC3MAP:
        echo $mpq->getGameData()->getName() . '<br/>';

        if ($mpq->hasFile("war3map.j"))
            $file = "war3map.j";
        else
            $file = "Scripts\\war3map.j";

        $result = $mpq->readFile($file);

        if (!$result)
            die("Failed to extract $file.");

        file_put_contents(basename($file), $result);

        echo "$file extracted.<br/>";

        break;
    case MPQArchive::TYPE_SC2MAP:
        echo $mpq->getFilename() . '<br/> v' . $mpq->getGameData()->getVersionString() . '<br/>';

        $file = "MapScript.galaxy";
        $result = $mpq->readFile($file);

        if (!$result)
            die("Failed to extract $file.");

        file_put_contents(basename($file), $result);

        echo "$file extracted.<br/>";

        break;
    default:
        $file = "Scripts\\common.j";
        $result = $mpq->readFile($file);

        if (!$result)
            die("Failed to extract $file.");

        file_put_contents(basename($file), $result);

        echo "$file extracted.<br/>";
        break;
}

?>
```
