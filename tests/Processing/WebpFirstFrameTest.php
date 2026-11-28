<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Processing\WebpFirstFrame;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Webp;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WebpFirstFrame::class)]
final class WebpFirstFrameTest
{
    public function theFirstFrameBecomesAStillOfItsOwn(): void
    {
        $first = Webp::chunk('VP8L', 'first-frame');
        $bytes = Webp::animation(300, 200, [
            [$first, 100, 50, 40, 30],
            [Webp::chunk('VP8L', 'second'), 0, 0, 300, 200],
        ]);

        $frame = WebpFirstFrame::of($bytes);

        Assert::notNull($frame);
        Assert::same($frame->still, Webp::riff($first));
        Assert::same([$frame->canvasWidth, $frame->canvasHeight, $frame->x, $frame->y], [300, 200, 100, 50]);
    }

    /** A lossy frame with a separate alpha plane keeps it, under an extended header of the frame's size. */
    public function aLossyFrameKeepsItsAlphaPlane(): void
    {
        $alpha = Webp::chunk('ALPH', 'alpha');
        $image = Webp::chunk('VP8 ', 'lossy!');
        $bytes = Webp::animation(64, 64, [[$alpha . $image, 0, 0, 40, 30]]);

        $frame = WebpFirstFrame::of($bytes);

        Assert::notNull($frame);
        Assert::same(
            $frame->still,
            Webp::riff(Webp::chunk('VP8X', "\x10\0\0\0" . "\x27\0\0" . "\x1d\0\0") . $alpha . $image),
        );
    }

    /** An `ALPH` belongs to a lossy frame only; VP8L carries its own alpha. */
    public function aLosslessFrameDropsAStrayAlphaPlane(): void
    {
        $image = Webp::chunk('VP8L', 'lossless');
        $bytes = Webp::animation(64, 64, [[Webp::chunk('ALPH', 'stray') . $image, 0, 0, 64, 64]]);

        Assert::same(WebpFirstFrame::of($bytes)?->still, Webp::riff($image));
    }

    /** Unknown chunks before the first frame, and inside it, are skipped. */
    public function unknownChunksAreSkipped(): void
    {
        $image = Webp::chunk('VP8L', 'odd');
        $animation = Webp::animation(64, 64, [[Webp::chunk('XYZW', 'x') . $image, 0, 0, 64, 64]]);
        $bytes = Webp::riff(substr($animation, 12, 18) . Webp::chunk('ICCP', 'icc') . substr($animation, 30));

        Assert::same(WebpFirstFrame::of($bytes)?->still, Webp::riff($image));
    }

    #[DataProvider('notAnimatedProvider')]
    public function anythingButAnAnimatedWebpIsLeftAlone(string $bytes): void
    {
        Assert::null(WebpFirstFrame::of($bytes));
    }

    public static function notAnimatedProvider(): iterable
    {
        yield 'still lossless' => [Webp::riff(Webp::chunk('VP8L', str_repeat('x', 20)))];
        yield 'extended still' => [Webp::riff(Webp::chunk('VP8X', "\x10\0\0\0\1\0\0\1\0\0") . Webp::chunk('VP8L', 'x'))];
        yield 'not riff' => [str_repeat("\x89PNG", 10)];
        yield 'riff, not webp' => ['RIFF' . pack('V', 30) . 'WAVEfmt ' . str_repeat("\0", 30)];
        // The byte where a VP8X header keeps its animation flag, set: a
        // RIFF of another kind is still not a WebP.
        yield 'riff, not webp, flag byte set' => ['RIFF' . pack('V', 38) . 'WAVEfmt ' . "\x10\0\0\0\x02" . str_repeat("\0", 25)];
        // Every byte of an animated VP8X header but the RIFF signature.
        yield 'animated header without the riff signature' => ['RIFX' . substr(Webp::animation(10, 10, []), 4)];
        yield 'too short' => ['RIFF'];
    }

    #[DataProvider('malformedProvider')]
    public function aMalformedAnimationIsAProcessingFailure(string $bytes, string $message): void
    {
        Expect::exception(ProcessingFailedException::class)->withMessageContaining($message);

        WebpFirstFrame::of($bytes);
    }

    public static function malformedProvider(): iterable
    {
        $image = Webp::chunk('VP8L', 'img');

        yield 'no frame' => [Webp::animation(10, 10, []), 'has no frame'];
        yield 'frame outside the canvas' => [Webp::animation(10, 10, [[$image, 4, 0, 8, 8]]), 'outside its canvas'];
        yield 'frame taller than the canvas' => [Webp::animation(10, 10, [[$image, 0, 4, 8, 8]]), 'outside its canvas'];
        yield 'frame without an image' => [Webp::animation(10, 10, [[Webp::chunk('ALPH', 'a'), 0, 0, 8, 8]]), 'carries no image'];
        yield 'truncated frame header' => [
            Webp::riff(substr(Webp::animation(10, 10, []), 12) . Webp::chunk('ANMF', str_repeat("\0", 15))),
            'truncated',
        ];
        yield 'an empty frame chunk ending the file' => [
            Webp::riff(substr(Webp::animation(10, 10, []), 12) . 'ANMF' . pack('V', 0)),
            'truncated',
        ];
        yield 'a frame header with no frame data' => [
            Webp::riff(substr(Webp::animation(10, 10, []), 12) . Webp::chunk('ANMF', str_repeat("\0", 16))),
            'carries no image',
        ];
        yield 'a bare animated header' => [Webp::riff(Webp::chunk('VP8X', "\x02\0\0\0\x09\0\0\x09\0\0")), 'has no frame'];
        yield 'trailing bytes too short for a chunk header' => [
            Webp::riff(substr(Webp::animation(10, 10, []), 12)) . str_repeat("\0", 7),
            'has no frame',
        ];
        yield 'chunk past the end' => [
            Webp::riff(substr(Webp::animation(10, 10, []), 12) . 'ANMF' . pack('V', 1000) . 'short'),
            'runs past the end',
        ];
    }

    /** A frame exactly filling the canvas is inside it. */
    public function aFrameFillingTheCanvasIsAccepted(): void
    {
        $image = Webp::chunk('VP8L', 'img');

        Assert::same(WebpFirstFrame::of(Webp::animation(10, 10, [[$image, 2, 2, 8, 8]]))?->still, Webp::riff($image));
    }

    /**
     * Sizes are 24-bit little-endian: canvas and frame sides past 65536
     * read every byte, and the still's extended header writes every byte
     * of the frame's size back.
     */
    public function sidesUseAllThreeBytes(): void
    {
        $alpha = Webp::chunk('ALPH', 'alpha');
        $image = Webp::chunk('VP8 ', 'lossy!');
        // 0x012346 = 74566, minus one on the wire: 45 23 01.
        $bytes = Webp::animation(0x012346 + 4, 0x012346 + 2, [[$alpha . $image, 2, 0, 0x012346, 0x012346]]);

        $frame = WebpFirstFrame::of($bytes);

        Assert::notNull($frame);
        Assert::same([$frame->canvasWidth, $frame->canvasHeight, $frame->x, $frame->y], [74570, 74568, 2, 0]);
        Assert::same(
            $frame->still,
            Webp::riff(Webp::chunk('VP8X', "\x10\0\0\0" . "\x45\x23\x01" . "\x45\x23\x01") . $alpha . $image),
        );
    }
}
