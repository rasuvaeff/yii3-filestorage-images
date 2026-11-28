<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

/**
 * Animated WebP containers built by hand: ImageMagick coalesces every frame
 * to the full canvas when it writes one, so a first frame at an offset — or a
 * malformed container — has to be assembled from chunks.
 *
 * @internal
 */
final class Webp
{
    /**
     * @param list<array{string, int, int, int, int}> $frames Each: frame data
     *        (sub-chunks), x, y, width, height. Offsets must be even.
     */
    public static function animation(int $canvasWidth, int $canvasHeight, array $frames): string
    {
        $body = self::chunk('VP8X', \chr(0x02 | 0x10) . "\0\0\0" . self::u24($canvasWidth - 1) . self::u24($canvasHeight - 1))
            . self::chunk('ANIM', "\0\0\0\0\0\0");
        foreach ($frames as [$data, $x, $y, $width, $height]) {
            $body .= self::chunk(
                'ANMF',
                self::u24(intdiv($x, 2)) . self::u24(intdiv($y, 2)) . self::u24($width - 1) . self::u24($height - 1)
                    . self::u24(100) . "\0" . $data,
            );
        }

        return self::riff($body);
    }

    /**
     * The sub-chunks of a still WebP (everything after `RIFF....WEBP`) — the
     * frame data of an `ANMF`.
     */
    public static function frameData(string $still): string
    {
        return substr($still, 12);
    }

    public static function chunk(string $type, string $payload): string
    {
        return $type . pack('V', \strlen($payload)) . $payload . (\strlen($payload) % 2 === 1 ? "\0" : '');
    }

    public static function riff(string $body): string
    {
        return 'RIFF' . pack('V', 4 + \strlen($body)) . 'WEBP' . $body;
    }

    private static function u24(int $value): string
    {
        return \chr($value & 0xFF) . \chr(($value >> 8) & 0xFF) . \chr(($value >> 16) & 0xFF);
    }
}
