# PHP-MPQ
Handle the MPQ (MoPaQ) format natively from PHP with support for Warcraft III &amp; Starcraft II Maps.

```php
<?php

require 'src/mpq.php';

try{
    $mpq = new MPQArchive("wc3map.w3x", /*debug=*/true);
}
catch(MPQException $error){
    die(nl2br("<strong>Error:</strong> " . $error->getMessage() . "\n\n" . $error));
}

switch($mpq->getType())
{
    case MPQArchive::TYPE_WC3MAP:
        $file = ($mpq->hasFile("war3map.j") ? "war3map.j" : "Scripts\\war3map.j");
        $result = $mpq->readFile($file);

        if (!$result)
            die("Failed to extract $file.");

        file_put_contents(basename($file), $result);

        echo "$file extracted.<br/>";

        break;
    case MPQArchive::TYPE_SC2MAP:
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
Based on https://code.google.com/archive/p/phpsc2replay/
