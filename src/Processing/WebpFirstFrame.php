<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;

/**
 * The first frame of an animated WebP, cut out of the RIFF container as a
 * still WebP of its own.
 *
 * ImageMagick's WebP coder ignores the `[0]` scene selector: it decodes every
 * frame of an animation and only then keeps the first (review 1.0 pass 2,
 * N2). Narrowing its list-length limit refused every animated WebP; leaving
 * it open let a thousand small frames through a cap that measures one. The
 * container is simple enough to walk without decoding anything: the first
 * `ANMF` chunk carries the frame's own bitstream (`VP8 `/`VP8L`, with an
 * optional `ALPH`), which is wrapped into a still and decoded alone. Only
 * chunk headers are read — no pixel data is touched here.
 *
 * @internal
 */
final readonly class WebpFirstFrame
{
    private const int ANIMATION_FLAG = 0x02;

    private const int ALPHA_FLAG = 0x10;

    /**
     * @param non-empty-string $still A still WebP of the first frame.
     * @param positive-int $canvasWidth
     * @param positive-int $canvasHeight
     * @param int<0, max> $x The frame's offset on the canvas.
     * @param int<0, max> $y
     */
    private function __construct(
        public string $still,
        public int $canvasWidth,
        public int $canvasHeight,
        public int $x,
        public int $y,
    ) {}

    /**
     * Null for anything that is not an animated WebP — a still WebP, or
     * other bytes altogether, go to the decoder as they are.
     *
     * @throws ProcessingFailedException An animated WebP whose container is malformed.
     */
    public static function of(string $bytes): ?self
    {
        if (\strlen($bytes) < 30
            || !str_starts_with($bytes, 'RIFF')
            || substr($bytes, 8, 8) !== 'WEBPVP8X'
            || (\ord($bytes[20]) & self::ANIMATION_FLAG) === 0
        ) {
            return null;
        }

        $canvasWidth = self::uint24($bytes, 24) + 1;
        $canvasHeight = self::uint24($bytes, 27) + 1;

        foreach (self::chunks($bytes, 12, \strlen($bytes)) as [$type, $offset, $size]) {
            if ($type !== 'ANMF') {
                continue;
            }

            if ($size < 16) {
                throw new ProcessingFailedException('Animated WebP frame header is truncated');
            }

            $x = self::uint24($bytes, $offset) * 2;
            $y = self::uint24($bytes, $offset + 3) * 2;
            $width = self::uint24($bytes, $offset + 6) + 1;
            $height = self::uint24($bytes, $offset + 9) + 1;
            if ($x + $width > $canvasWidth || $y + $height > $canvasHeight) {
                throw new ProcessingFailedException('Animated WebP frame lies outside its canvas');
            }

            return new self(
                still: self::still(
                    self::bitstream($bytes, $offset + 16, $offset + $size),
                    $width,
                    $height,
                ),
                canvasWidth: $canvasWidth,
                canvasHeight: $canvasHeight,
                x: $x,
                y: $y,
            );
        }

        throw new ProcessingFailedException('Animated WebP has no frame');
    }

    /**
     * The frame data sub-chunks: an optional `ALPH` and the `VP8 `/`VP8L`
     * image itself.
     *
     * @return array{?string, string}
     */
    private static function bitstream(string $bytes, int $from, int $to): array
    {
        $alpha = null;
        foreach (self::chunks($bytes, $from, $to) as [$type, $offset, $size]) {
            $chunk = substr($bytes, $offset - 8, 8 + $size + ($size & 1));
            if ($type === 'ALPH') {
                $alpha = $chunk;
            } elseif ($type === 'VP8 ' || $type === 'VP8L') {
                return [$type === 'VP8 ' ? $alpha : null, $chunk];
            }
        }

        throw new ProcessingFailedException('Animated WebP frame carries no image');
    }

    /**
     * @param array{?string, string} $bitstream
     *
     * @return non-empty-string
     */
    private static function still(array $bitstream, int $width, int $height): string
    {
        [$alpha, $image] = $bitstream;
        $body = $image;
        if ($alpha !== null) {
            // A lossy frame with a separate alpha plane needs the extended
            // header to say so; VP8L carries its own alpha.
            $body = 'VP8X' . pack('V', 10) . \chr(self::ALPHA_FLAG) . "\0\0\0"
                . self::pack24($width - 1) . self::pack24($height - 1)
                . $alpha . $image;
        }

        return 'RIFF' . pack('V', 4 + \strlen($body)) . 'WEBP' . $body;
    }

    /**
     * Chunk headers between two offsets: type, payload offset, payload size.
     *
     * @return \Generator<int, array{string, int, int}>
     */
    private static function chunks(string $bytes, int $from, int $to): \Generator
    {
        $to = min($to, \strlen($bytes));
        $offset = $from;
        while ($offset + 8 <= $to) {
            $type = substr($bytes, $offset, 4);
            /** @var array{1: int} $size */
            $size = unpack('V', $bytes, $offset + 4);
            $payload = $offset + 8;
            if ($payload + $size[1] > $to) {
                throw new ProcessingFailedException("WebP chunk \"{$type}\" runs past the end of its container");
            }

            yield [$type, $payload, $size[1]];

            $offset = $payload + $size[1] + ($size[1] & 1);
        }
    }

    /**
     * @return int<0, 16777215>
     */
    private static function uint24(string $bytes, int $offset): int
    {
        return \ord($bytes[$offset]) | (\ord($bytes[$offset + 1]) << 8) | (\ord($bytes[$offset + 2]) << 16);
    }

    private static function pack24(int $value): string
    {
        return \chr($value & 0xFF) . \chr(($value >> 8) & 0xFF) . \chr(($value >> 16) & 0xFF);
    }
}
