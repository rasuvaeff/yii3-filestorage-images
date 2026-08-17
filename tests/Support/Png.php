<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

/**
 * Builds PNG *headers* — bytes whose IHDR declares whatever dimensions a test
 * needs, without allocating a single pixel. This is exactly the decompression
 * bomb shape: `getimagesizefromstring()` believes the header, a decoder would
 * believe it too, and only the guard between them stops the allocation.
 *
 * @internal
 */
final class Png
{
    /**
     * @param int<0, max> $width
     * @param int<0, max> $height
     */
    public static function header(int $width, int $height): string
    {
        $ihdr = pack('N', $width) . pack('N', $height) . "\x08\x06\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . $ihdr
            . pack('N', crc32('IHDR' . $ihdr));
    }
}
