<?php

class MPQReader
{
    static function byte(&$string, &$numByte) 
    {
        if ($numByte >= strlen($string))
            return false;

        $tmp = unpack("C",substr($string,$numByte,1));
        $numByte++;

        return $tmp[1];
    }

    static function bytes($string, &$numByte, $length) 
    {
        if (strlen($string) - $numByte - $length < 0) 
            return false;

        $tmp = substr($string,$numByte,$length);
        $numByte += $length;

        return $tmp;
    }

    static function UInt8($string, &$numByte) 
    {
        if (strlen($string) - $numByte - 1 < 0)
            return false;

        $tmp = unpack("c",substr($string,$numByte));
        $numByte += 1;

        return $tmp[1];
    }

    static function UInt16($string, &$numByte) 
    {
        if (strlen($string) - $numByte - 2 < 0)
            return false;

        $tmp = unpack("v",substr($string,$numByte,2));
        $numByte += 2;

        return $tmp[1];
    }

    static function UInt32($string, &$numByte) 
    {
        if (strlen($string) - $numByte - 4 < 0)
            return false;

        $tmp = unpack("V",substr($string,$numByte,4));
        $numByte += 4;

        return $tmp[1];
    }

    static function VLFNumber($string, &$numByte) 
    {
        $number = 0;
        $first = true;
        $multiplier = 1;

        for ($i = self::byte($string,$numByte),$bytes = 0; true; $i = self::byte($string,$numByte), $bytes++) 
        {
            $number += ($i & 0x7F) * pow(2,$bytes * 7);

            if ($first)
             {
                if ($number & 1) 
                {
                    $multiplier = -1;
                    $number--;
                }
                $first = false;
            }

            if (($i & 0x80) == 0) break;
        }

        $number *= $multiplier;
        $number /= 2; // can't use right-shift because the datatype will be float for large values on 32-bit systems
        return $number;
    }
}

?>