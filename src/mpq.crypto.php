<?php

class MPQCrypto
{
    public static $table;

    static function initCryptTable() 
    {
        if (!self::$table)
            self::$table = array();

        $seed = 0x00100001;
        $index1 = 0;
        $index2 = 0;
        
        for ($index1 = 0; $index1 < 0x100; $index1++) 
        {
            for ($index2 = $index1, $i = 0; $i < 5; $i++, $index2 += 0x100) 
            {
                $seed = (uPlus($seed * 125,3)) % 0x2AAAAB;
                $temp1 = ($seed & 0xFFFF) << 0x10;
                
                $seed = (uPlus($seed * 125,3)) % 0x2AAAAB;
                $temp2 = ($seed & 0xFFFF);
                
                self::$table[$index2] = ($temp1 | $temp2);
            }
        }
    }

    static function decrypt($data, $key) 
    {
        $seed = ((0xEEEE << 16) | 0xEEEE);

        $datalen = count($data);

        for($i = 0;$i < $datalen;$i++) 
        {
            $seed = uPlus($seed, self::$table[0x400 + ($key & 0xFF)]);
            $ch = $data[$i] ^ (uPlus($key,$seed));

            $key = (uPlus(((~$key) << 0x15), 0x11111111)) | (rShift($key,0x0B));
            $seed = uPlus(uPlus(uPlus($ch,$seed),($seed << 5)),3);
            $data[$i] = $ch & ((0xFFFF << 16) | 0xFFFF);
        }

        return $data;
    }

    static function encrypt($data, $key) 
    {
        $seed = ((0xEEEE << 16) | 0xEEEE);
        $datalen = count($data);
        for($i = 0;$i < $datalen;$i++) 
        {
            $seed = uPlus($seed, self::$table[0x400 + ($key & 0xFF)]);
            $ch = $data[$i] ^ (uPlus($key,$seed));

            $key = (uPlus(((~$key) << 0x15), 0x11111111)) | (rShift($key,0x0B));
            $seed = uPlus(uPlus(uPlus($data[$i],$seed),($seed << 5)),3);
            $data[$i] = $ch & ((0xFFFF << 16) | 0xFFFF);            
        }
        return $data;
    }

    static function hashString($string, $hashType) 
    {
        $seed1 = 0x7FED7FED;
        $seed2 = ((0xEEEE << 16) | 0xEEEE);
        $strLen = strlen($string);
        
        for ($i = 0;$i < $strLen;$i++) 
        {
            $next = ord(strtoupper(substr($string, $i, 1)));

            $seed1 = self::$table[($hashType << 8) + $next] ^ (uPlus($seed1,$seed2));
            $seed2 = uPlus(uPlus(uPlus(uPlus($next,$seed1),$seed2),$seed2 << 5),3);
        }
        return $seed1;
    }
}

// function that adds up two integers without allowing them to overflow to floats
function uPlus($o1, $o2) 
{
    $o1h = ($o1 >> 16) & 0xFFFF;
    $o1l = $o1 & 0xFFFF;
    
    $o2h = ($o2 >> 16) & 0xFFFF;
    $o2l = $o2 & 0xFFFF;    

    $ol = $o1l + $o2l;
    $oh = $o1h + $o2h;

    if ($ol > 0xFFFF)
        $oh += (($ol >> 16) & 0xFFFF);
    
    return ((($oh << 16) & (0xFFFF << 16)) | ($ol & 0xFFFF));
}

// right shift without preserving the sign(leftmost) bit
function rShift($num,$bits) { return (($num >> 1) & 0x7FFFFFFF) >> ($bits - 1); }

?>