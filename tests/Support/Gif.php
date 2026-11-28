<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

/**
 * Builds GIF block structure: a logical screen and frames placed on it, with
 * a two-colour global table and one byte of image data per frame. The data
 * decodes to nothing meaningful; what the tests need is the structure a
 * block walk reads — and that `getimagesizefromstring()` reports the screen
 * alone, whatever the frames say.
 *
 * @internal
 */
final class Gif
{
    /** A graphic control extension, the block every animation frame carries. */
    public const string CONTROL = "!\xF9\x04\x00\x0A\x00\x00\x00";

    /** One frame's image data: LZW minimum code size, one sub-block, terminator. */
    private const string DATA = "\x02\x02\x44\x01\x00";

    /**
     * @param list<array{int, int, int, int}|string> $blocks Frames as
     *        `[left, top, width, height]`, or raw bytes inserted as they are.
     */
    public static function of(int $screenWidth, int $screenHeight, array $blocks, string $trailer = ';'): string
    {
        $gif = 'GIF89a' . pack('vvCCC', $screenWidth, $screenHeight, 0x80, 0, 0) . "\x00\x00\x00\xFF\xFF\xFF";
        foreach ($blocks as $block) {
            $gif .= \is_string($block)
                ? $block
                : self::CONTROL . ',' . pack('vvvvC', $block[0], $block[1], $block[2], $block[3], 0) . self::DATA;
        }

        return $gif . $trailer;
    }

    /**
     * A GIF assembled from its parts as they are: the screen descriptor's
     * packed flags, background index and aspect byte, the global colour
     * table bytes and the blocks — nothing is derived, so a test can make
     * the table say whatever a walk that misreads its size would trip on.
     */
    public static function raw(
        int $screenWidth,
        int $screenHeight,
        int $flags,
        string $globalTable,
        string $blocks,
        int $background = 0,
        int $aspect = 0,
    ): string {
        return 'GIF89a' . pack('vvCCC', $screenWidth, $screenHeight, $flags, $background, $aspect)
            . $globalTable . $blocks;
    }

    /**
     * One image block: the descriptor with its own packed flags, the local
     * colour table bytes as given, and one byte of image data.
     */
    public static function frame(
        int $left,
        int $top,
        int $width,
        int $height,
        int $flags = 0,
        string $localTable = '',
    ): string {
        return ',' . pack('vvvvC', $left, $top, $width, $height, $flags) . $localTable . self::DATA;
    }

    /**
     * The review's probe (core pass 2, N1): a 1x1 logical screen over one
     * 8000x8000 frame — 64 MP behind a header that says one pixel.
     */
    public static function screen1x1Frame64mp(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/screen1x1-64mp.gif');
    }
}
