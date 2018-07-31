<?php

namespace TriggerHappy\MPQ\Compression;

use TriggerHappy\MPQ\Stream\ByteBuffer;

class ADPCM 
{
	const INITIAL_ADPCM_STEP_INDEX = 0x2C;

	const CHANGE_TABLE = array
	(
	    -1, 0, -1, 4, -1, 2, -1, 6,
	    -1, 1, -1, 5, -1, 3, -1, 7,
	    -1, 1, -1, 5, -1, 3, -1, 7,
	    -1, 2, -1, 4, -1, 6, -1, 8
	);

	const STEP_TABLE = array
	(
		7,     8,     9,    10,     11,    12,    13,    14,
		16,    17,    19,    21,     23,    25,    28,    31,
		34,    37,    41,    45,     50,    55,    60,    66,
		73,    80,    88,    97,    107,   118,   130,   143,
		157,   173,   190,   209,    230,   253,   279,   307,
		337,   371,   408,   449,    494,   544,   598,   658,
		724,   796,   876,   963,   1060,  1166,  1282,  1411,
		1552,  1707,  1878,  2066,   2272,  2499,  2749,  3024,
		3327,  3660,  4026,  4428,   4871,  5358,  5894,  6484,
		7132,  7845,  8630,  9493,  10442, 11487, 12635, 13899,
		15289, 16818, 18500, 20350, 22385, 24623, 27086, 29794,
		32767
	);

    const STEP_TABLE_LENGTH = 89;

	private $state;

    function __construct($channelmax) 
    {
    	for ($i = 0; $i < $channelmax; $i += 1) 
    		$this->state[$i] = new ADPCMChannel();
    }

    public function decompress($in, $channeln) 
    {
        $in = new ByteBuffer($in);
    	$output = '';
        $stepshift = unsignedRightShift($in->getShort(), 8);

        // initialize channels
        for ($i = 0; $i < $channeln; $i += 1) 
        {
            $chan = $this->state[$i];
            $chan->stepIndex = ADPCM::INITIAL_ADPCM_STEP_INDEX;
            $chan->sampleValue = $in->getShort();

            $output .= pack("s", $chan->sampleValue);
        }

        $current = 0;

        // decompress
        while ($in->canRead()) 
        {
            $op = $in->get();
        	$chan = $this->state[$current];

        	if (($op & 0x80) != 0) 
        	{
        		switch ($op & 0x7F) 
        		{
        			// write current value
        			case 0:
	                    if ($chan->stepIndex != 0)
	                        $chan->stepIndex -= 1;
	                    $output .= pack("s", $chan->sampleValue);

	                    $current = ($current + 1) % $channeln;
	                    break;

	                // increment period
	                case 1:
	                    $chan->stepIndex += 8;
	                    if ($chan->stepIndex >= ADPCM::STEP_TABLE_LENGTH)
	                        $chan->stepIndex = (ADPCM::STEP_TABLE_LENGTH - 1);

	                    break;

	                // skip channel (unused?)
	                case 2:
	                    $current = ($current + 1) % $channeln;
	                    break;

	                // all other values (unused?)
        			default:
	                    $chan->stepIndex -= 8;
	                    if ($chan->stepIndex < 0)
	                        $chan->stepIndex = 0;

        				break;
        		}
        	}
        	else
        	{
                // adjust value
                $stepbase = ADPCM::STEP_TABLE[$chan->stepIndex];
                $step = unsignedRightShift($stepbase, $stepshift);

                for ($i = 0; $i < 6; $i += 1) 
                {
                    if (($op & 1 << $i) != 0)
                    {
                        $step += unsignedRightShift($stepbase, $i);
                    }
                }

                if (($op & 0x40) != 0)
                    $chan->sampleValue = max((int) $chan->sampleValue - $step, -32768);
                else
                    $chan->sampleValue = min((int) $chan->sampleValue + $step, 32767);

                $output .= pack("s", $chan->sampleValue);

                $chan->stepIndex += ADPCM::CHANGE_TABLE[$op & 0x1F];
                if ($chan->stepIndex < 0)
                    $chan->stepIndex = 0;
                else if ($chan->stepIndex >= ADPCM::STEP_TABLE_LENGTH)
                    $chan->stepIndex = (ADPCM::STEP_TABLE_LENGTH - 1);

                $current = ($current + 1) % $channeln;
        	}
        }

        return $output;
    }
}

?>