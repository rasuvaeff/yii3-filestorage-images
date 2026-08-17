<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Processing\SourceGuard;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Png;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\StallingStream;
use Testo\Assert;
use Testo\Codecov\Covers;
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
            ->withMessageContaining('over the 10000-pixel processing cap');

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
}
