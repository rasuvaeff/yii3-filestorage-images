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

                throw ProcessingFailedException::transient('Source stream stalled before EOF');
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
     *         declares them — for a GIF, its logical screen, after every frame
     *         was found to fit inside it.
     *
     * @throws UnsupportedSourceException When the declared pixel count is over
     *         the cap, or a GIF frame does not fit its logical screen.
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
        if ($info[2] === \IMAGETYPE_GIF) {
            self::gifFramesFit($bytes, $width, $height);
        }

        if ($maxPixels > 0 && $width * $height > $maxPixels) {
            throw new UnsupportedSourceException(
                "Source is {$width}x{$height} = " . ($width * $height)
                . " pixels, over the {$maxPixels}-pixel processing cap",
            );
        }

        return [$width, $height];
    }

    /**
     * `getimagesizefromstring()` reports a GIF's logical screen, while every
     * frame carries its own size — the one a decoder allocates. A 1x1 screen
     * over an 8000x8000 frame passed any cap (core review 1.0 pass 2, N1). The
     * blocks are walked as ImageMagick and libgd walk them, a byte that
     * starts no block skipped, so a frame not fitting inside the screen is
     * refused and the screen bounds every frame the cap is checked against.
     * A file cut short after a frame is measured by what was read, as the
     * decoders decode it.
     *
     * @throws UnsupportedSourceException
     * @throws ProcessingFailedException When no frame can be read.
     */
    private static function gifFramesFit(string $bytes, int $screenWidth, int $screenHeight): void
    {
        $length = \strlen($bytes);
        if ($length < 13) {
            throw new ProcessingFailedException('Source image dimensions could not be established');
        }

        $flags = \ord($bytes[10]);
        $position = 13 + (($flags & 0x80) !== 0 ? 3 << (($flags & 0x07) + 1) : 0);
        $frames = 0;
        while ($position < $length) {
            $introducer = \ord($bytes[$position++]);
            if ($introducer === 0x3B) {
                break;
            }
            if ($introducer === 0x21) {
                // The extension label, then its sub-blocks.
                $position = self::afterSubBlocks($bytes, $position + 1);

                continue;
            }
            if ($introducer !== 0x2C) {
                continue;
            }
            if ($position + 9 > $length) {
                break;
            }

            /** @var array{x: int, y: int, w: int, h: int, flags: int} $frame */
            $frame = unpack('vx/vy/vw/vh/Cflags', $bytes, $position);
            if ($frame['w'] === 0 || $frame['h'] === 0) {
                throw new ProcessingFailedException('Source image dimensions could not be established');
            }
            if ($frame['x'] + $frame['w'] > $screenWidth || $frame['y'] + $frame['h'] > $screenHeight) {
                throw new UnsupportedSourceException(
                    "GIF frame {$frame['w']}x{$frame['h']} at ({$frame['x']},{$frame['y']}) does not fit its "
                    . "{$screenWidth}x{$screenHeight} logical screen",
                );
            }

            ++$frames;
            $localTable = ($frame['flags'] & 0x80) !== 0 ? 3 << (($frame['flags'] & 0x07) + 1) : 0;
            // The descriptor, the local colour table, the LZW minimum code size.
            $position = self::afterSubBlocks($bytes, $position + 9 + $localTable + 1);
        }

        if ($frames === 0) {
            throw new ProcessingFailedException('Source image dimensions could not be established');
        }
    }

    /**
     * The offset after a sub-block chain's zero terminator, or past the end
     * when the chain is cut short.
     */
    private static function afterSubBlocks(string $bytes, int $position): int
    {
        $length = \strlen($bytes);
        while ($position < $length) {
            $size = \ord($bytes[$position]);
            $position += $size + 1;
            if ($size === 0) {
                break;
            }
        }

        return $position;
    }
}
