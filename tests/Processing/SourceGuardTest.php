<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Processing\SourceGuard;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Gif;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Png;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\StallingStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(SourceGuard::class)]
final class SourceGuardTest
{
    private Psr17Factory $streams;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->streams = new Psr17Factory();
    }

    public function readsTheWholeStream(): void
    {
        Assert::same(SourceGuard::readBounded($this->streams->createStream('abc'), maxBytes: 0), 'abc');
    }

    /**
     * A source larger than one read chunk is the normal case for real
     * photos: every non-empty read with EOF still ahead has to be appended,
     * not treated as a stall.
     */
    public function readsStreamsLargerThanOneChunk(): void
    {
        $first = str_repeat('a', 262_144);
        $second = str_repeat('b', 16);

        $bytes = SourceGuard::readBounded($this->streams->createStream($first . $second), maxBytes: 0);

        Assert::same(\strlen($bytes), 262_160);
        Assert::same($bytes[0], 'a');
        Assert::same($bytes[262_144], 'b');
    }

    /**
     * Single-pixel sides are dimensions, not failures — contain into a tiny
     * box legitimately produces 1×N and N×1 geometry.
     */
    public function acceptsSinglePixelSides(): void
    {
        Assert::same(SourceGuard::dimensions(Png::header(width: 1, height: 5), maxPixels: 0), [1, 5]);
        Assert::same(SourceGuard::dimensions(Png::header(width: 5, height: 1), maxPixels: 0), [5, 1]);
    }

    public function acceptsExactlyTheCap(): void
    {
        Assert::same(SourceGuard::readBounded($this->streams->createStream('12345678'), maxBytes: 8), '12345678');
    }

    public function rejectsOneByteOverTheCap(): void
    {
        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('8-byte processing cap');

        SourceGuard::readBounded($this->streams->createStream('123456789'), maxBytes: 8);
    }

    public function rejectsAnEmptyStream(): void
    {
        Expect::exception(ProcessingFailedException::class)->withMessageContaining('empty');

        SourceGuard::readBounded($this->streams->createStream(''), maxBytes: 0);
    }

    public function rejectsAStalledStream(): void
    {
        Expect::exception(ProcessingFailedException::class)->withMessageContaining('stalled');

        SourceGuard::readBounded(new StallingStream(), maxBytes: 0);
    }

    /** A stalled stream says nothing about the file: transient, never remembered (review 1.0 pass 2, N8). */
    public function aStalledStreamIsTransient(): void
    {
        try {
            SourceGuard::readBounded(new StallingStream(), maxBytes: 0);
            Assert::fail('a stalled stream must fail');
        } catch (ProcessingFailedException $e) {
            Assert::true($e->isTransient());
        }
    }

    /** An empty source is a property of the file. */
    public function anEmptySourceIsNotTransient(): void
    {
        try {
            SourceGuard::readBounded((new Psr17Factory())->createStream(''), maxBytes: 0);
            Assert::fail('an empty source must fail');
        } catch (ProcessingFailedException $e) {
            Assert::false($e->isTransient());
        }
    }

    public function readsDimensionsFromTheHeaderAlone(): void
    {
        // The bytes carry no pixel data at all — dimensions still resolve,
        // which is the property the guard depends on.
        Assert::same(SourceGuard::dimensions(Png::header(width: 640, height: 480), maxPixels: 0), [640, 480]);
    }

    public function acceptsExactlyThePixelCap(): void
    {
        Assert::same(
            SourceGuard::dimensions(Png::header(width: 100, height: 100), maxPixels: 10_000),
            [100, 100],
        );
    }

    public function rejectsAHeaderDeclaringMorePixelsThanTheCap(): void
    {
        Expect::exception(UnsupportedSourceException::class)
            ->withMessageContaining('Source is 101x100 = 10100 pixels, over the 10000-pixel processing cap');

        SourceGuard::dimensions(Png::header(width: 101, height: 100), maxPixels: 10_000);
    }

    public function rejectsADecompressionBombHeaderWithoutAllocating(): void
    {
        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('100000x100000');

        SourceGuard::dimensions(Png::header(width: 100_000, height: 100_000), maxPixels: 40_000_000);
    }

    public function rejectsBytesWithNoImageHeader(): void
    {
        Expect::exception(ProcessingFailedException::class)->withMessageContaining('could not be established');

        SourceGuard::dimensions('this is not an image', maxPixels: 0);
    }

    /**
     * Core review pass 2, N1, in this package: `getimagesizefromstring()`
     * reports a GIF's logical screen, and every frame carries its own size —
     * the one a decoder allocates. A 1x1 screen passed a 1 MP cap with an
     * 8000x8000 frame behind it. A frame that does not fit inside its screen
     * is refused, so the screen bounds every frame and the cap holds.
     */
    public function aGifFrameLargerThanItsScreenIsRefused(): void
    {
        Expect::exception(UnsupportedSourceException::class)
            ->withMessageContaining('GIF frame 8000x8000 at (0,0) does not fit its 1x1 logical screen');

        SourceGuard::dimensions(Gif::screen1x1Frame64mp(), maxPixels: 1_000_000);
    }

    /**
     * @return iterable<string, array{array{int, int, int, int}}>
     */
    public static function frameOutsideTheScreenProvider(): iterable
    {
        yield 'wider' => [[0, 0, 101, 10]];
        yield 'taller' => [[0, 0, 10, 101]];
        yield 'pushed right' => [[95, 0, 6, 10]];
        yield 'pushed down' => [[0, 95, 10, 6]];
    }

    #[DataProvider('frameOutsideTheScreenProvider')]
    public function aGifFrameOutsideItsScreenIsRefused(array $frame): void
    {
        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('100x100 logical screen');

        SourceGuard::dimensions(Gif::of(100, 100, [[0, 0, 100, 100], $frame]), maxPixels: 0);
    }

    public function aGifWhoseFramesFitIsMeasuredByItsScreen(): void
    {
        $gif = Gif::of(100, 80, [
            "!\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00",
            [0, 0, 100, 80],
            [10, 10, 90, 70],
            "!\xFE\x05hello\x00",
            [99, 79, 1, 1],
        ]);

        Assert::same(SourceGuard::dimensions($gif, maxPixels: 8000), [100, 80]);
    }

    /**
     * ImageMagick and libgd read a byte that starts no block, skip it and
     * read the next: a frame hidden behind such a byte is still decoded, so
     * the walk goes on to it the same way.
     */
    public function aFrameBehindAStrayByteIsStillChecked(): void
    {
        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('4000x4000');

        SourceGuard::dimensions(Gif::of(10, 10, [[0, 0, 10, 10], "\x00\x07", [0, 0, 4000, 4000]]), maxPixels: 0);
    }

    /**
     * A file cut short after a frame that fits decodes as far as it goes;
     * so does the walk.
     */
    public function aGifCutShortAfterAFrameIsMeasured(): void
    {
        $gif = substr(Gif::of(20, 20, [[0, 0, 20, 20], [0, 0, 20, 20]], trailer: ''), 0, -3);

        Assert::same(SourceGuard::dimensions($gif, maxPixels: 0), [20, 20]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unmeasurableGifProvider(): iterable
    {
        yield 'no frame' => [Gif::of(20, 20, [])];
        yield 'a zero-width frame' => [Gif::of(20, 20, [[0, 0, 0, 20]])];
        yield 'a frame descriptor cut short' => [Gif::of(20, 20, [",\x00\x00\x00"], trailer: '')];
    }

    #[DataProvider('unmeasurableGifProvider')]
    public function aGifWithoutAMeasurableFrameIsRefused(string $gif): void
    {
        Expect::exception(ProcessingFailedException::class)->withMessageContaining('could not be established');

        SourceGuard::dimensions($gif, maxPixels: 0);
    }
}
