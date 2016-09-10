<?php

require __DIR__ . '/mpq.wc3map.php';
require __DIR__ . '/mpq.sc2map.php';

class GameData
{
    public static function imageCreateFromTGA ( $filename, $return_array = 0 )
    {
        // Example usage: imagejpeg (imageCreateFromTGA('file.tga'), 'out.jpg', 100);
        
        $handle = fopen ( $filename, 'rb' );
        $data = fread ( $handle, filesize( $filename ) );
        fclose ( $handle );
        
        $pointer = 18;
        $x = 0;
        $y = 0;
        $w = base_convert ( bin2hex ( strrev ( substr ( $data, 12, 2 ) ) ), 16, 10 );
        $h = base_convert ( bin2hex ( strrev ( substr ( $data, 14, 2 ) ) ), 16, 10 );
        $img = imagecreatetruecolor( $w, $h );

        while ( $pointer < strlen ( $data ) )
        {
            imagesetpixel ( $img, $x, $y, base_convert ( bin2hex ( strrev ( substr ( $data, $pointer, 3 ) ) ), 16, 10 ) );
            $x++;

            if ($x == $w)
            {
                $y++;
                $x=0;
            }

            $pointer += 3;
        }
        
        if ( $return_array )
            return array ( $img, $w, $h );
        else
            return $img;
    }
}

?>