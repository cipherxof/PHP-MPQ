<?php

class MPQReader
{
    static function byte($file, &$fp) 
    {
        $fp++; 
        return fread($file, 1);
    }

    static function bytes($file, &$fp, $length) 
    {
        $fp+=$length;
        return fread($file, $length);
    }

    static function UInt8($file, &$fp) 
    {
        $string = fread($file, 1);

        $tmp = unpack("c",$string);
        $fp += 1;

        return $tmp[1];
    }

    static function UInt16($file, &$fp) 
    {
        $string = fread($file, 2);

        $tmp = unpack("v",$string);
        $fp += 2;

        return $tmp[1];
    }

    static function UInt32($file, &$fp) 
    {
        $string = fread($file, 4);

        $tmp = unpack("V",$string);
        $fp += 4;

        return $tmp[1];
    }   

    static function String($file, &$fp) 
    {
        $output = "";

        while ( ord($s = MPQReader::byte($file, $fp)) != 0)
            $output .= $s;

        return $output;
    }

    static function byte_str(&$string, &$num_byte) 
    {
        if ($num_byte >= strlen($string))
            return false;
        $tmp = unpack("C",substr($string,$num_byte,1));
        $num_byte++;
        return $tmp[1];
    }
    static function bytes_str($string, &$num_byte, $length) 
    {
        if (strlen($string) - $num_byte - $length < 0) 
            return false;
        $tmp = substr($string,$num_byte,$length);
        $num_byte += $length;
        return $tmp;
    }
    static function UInt8_str($string, &$num_byte) 
    {
        if (strlen($string) - $num_byte - 1 < 0)
            return false;
        $tmp = unpack("c",substr($string,$num_byte));
        $num_byte += 1;
        return $tmp[1];
    }
    static function UInt16_str($string, &$num_byte) 
    {
        if (strlen($string) - $num_byte - 2 < 0)
            return false;
        $tmp = unpack("v",substr($string,$num_byte,2));
        $num_byte += 2;
        return $tmp[1];
    }
    static function UInt32_str($string, &$num_byte) 
    {
        if (strlen($string) - $num_byte - 4 < 0)
            return false;
        $tmp = unpack("V",substr($string,$num_byte,4));
        $num_byte += 4;
        return $tmp[1];
    }
    static function String_str($string, &$num_byte) 
    {
        $out = "";
        while ( ($s = MPQReader::byte_str($string, $num_byte)) != 0)
            $out .= chr($s);
        return $out;
    }

    static function VLFNumber($string, &$num_byte) 
    {
        $number = 0;
        $first = true;
        $multiplier = 1;

        for ($i = self::byte($string,$num_byte),$bytes = 0; true; $i = self::byte($string,$num_byte), $bytes++) 
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