# PHP-MPQ
Handle the MPQ (MoPaQ) format natively from PHP.

Supported Archives:
* MPQ Archives (v1.0)
* Warcraft III Maps
* Warcraft III Campaigns
* Starcraft II Maps

Supported Compressions:
* Gzip
* Bzip2
* IMA ADPCM (Mono/Stereo)
* Huffman

Demo: https://www.wc3maps.com/

Installation
==========

Requires: [Composer](https://getcomposer.org/download/)

Clone the repository and run ``composer install``

Example
==========
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use TriggerHappy\MPQ\MPQArchive;

$mpq = new MPQArchive("wc3map.w3x");
echo $mpq->readFile("war3map.j");
?>
```