<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;

/**
 * The two checks every processor runs before letting a decoder near the bytes.
 *
 * Both exist because decoding allocates by what the *header* claims, not by
 * what was read: a kilobyte of PNG is allowed to declare 100000×100000 pixels,
 * and the crash happens inside the decoder, after the size checks that only
 * looked at bytes. The byte cap bounds the read; the pixel cap bounds the
 * decode.
 *
 * @internal Shared by the processors; not part of the public API.
 */
final readonly class SourceGuard
{
    private const int CHUNK = 262_144;

    /**
     * @param int<0, max> $maxBytes Zero means no cap.
     *
     * @return non-empty-string
     *
     * @throws UnsupportedSourceException When the stream exceeds the cap.
     * @throws ProcessingFailedException When the stream errors or is empty.
     */
    public static function readBounded(StreamInterface $source, int $maxBytes): string
    {
        $bytes = '';
        while (!$source->eof()) {
            $chunk = $source->read(self::CHUNK);
            if ($chunk === '') {
                if ($source->eof()) {
                    break;
                }

                throw new ProcessingFailedException('Source stream stalled before EOF');
            }
            $bytes .= $chunk;
            if ($maxBytes > 0 && \strlen($bytes) > $maxBytes) {
                throw new UnsupportedSourceException(
                    "Source exceeds the {$maxBytes}-byte processing cap",
                );
            }
        }

        if ($bytes === '') {
            throw new ProcessingFailedException('Source stream is empty');
        }

        return $bytes;
    }

    /**
     * @param non-empty-string $bytes
     * @param int<0, max> $maxPixels Zero means no cap.
     *
     * @return array{int<1, max>, int<1, max>} Width and height as the header
     *         declares them.
     *
     * @throws UnsupportedSourceException When the declared pixel count is over
     *         the cap.
     * @throws ProcessingFailedException When no dimensions can be established.
     */
    public static function dimensions(string $bytes, int $maxPixels): array
    {
        // Header parsing only — getimagesizefromstring() never decodes pixel
        // data, which is the whole point of asking it first.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new ProcessingFailedException('Source image dimensions could not be established');
        }

        [$width, $height] = $info;
        if ($maxPixels > 0 && $width * $height > $maxPixels) {
            throw new UnsupportedSourceException(
                "Source is {$width}x{$height} = " . ($width * $height)
                . " pixels, over the {$maxPixels}-pixel processing cap",
            );
        }

        return [$width, $height];
    }
}
