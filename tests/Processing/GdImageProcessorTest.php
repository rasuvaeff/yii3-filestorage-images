<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Fit;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\Processing\GdImageProcessor;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Png;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Test;

/**
 * Every test bails out when ext-gd is absent (the composer:2 image ships
 * without it); CI installs the extension and runs them all.
 */
#[Test]
#[Covers(GdImageProcessor::class)]
final class GdImageProcessorTest
{
    public function refusesEverythingWithoutTheExtensionEverSayingSo(): void
    {
        if (\extension_loaded('gd')) {
            Assert::true((new GdImageProcessor(new Psr17Factory()))->supports('image/png', Format::Png));

            return;
        }

        Assert::false((new GdImageProcessor(new Psr17Factory()))->supports('image/png', Format::Png));
    }

    public function refusesMediaTypesOutsideTheRasterMap(): void
    {
        $this->requireExtension();

        $processor = new GdImageProcessor(new Psr17Factory());

        Assert::false($processor->supports('image/svg+xml', Format::Png));
        Assert::false($processor->supports('application/pdf', Format::Png));
    }

    public function resizesContainAndReencodes(): void
    {
        $this->requireExtension();

        $processed = (new GdImageProcessor(new Psr17Factory()))->process(
            $this->pngSource(width: 400, height: 200),
            'image/png',
            new Preset(name: 'thumb', format: Format::Jpeg, width: 100, height: 100),
        );

        Assert::same([$processed->width, $processed->height], [100, 50]);

        // The output really is what the preset promised — decode it back.
        $info = getimagesizefromstring((string) $processed->stream);
        Assert::same([$info[0], $info[1]], [100, 50]);
        Assert::same($info['mime'], 'image/jpeg');
    }

    public function coverProducesTheExactBox(): void
    {
        $this->requireExtension();

        $processed = (new GdImageProcessor(new Psr17Factory()))->process(
            $this->pngSource(width: 400, height: 200),
            'image/png',
            new Preset(name: 'sq', format: Format::Png, width: 100, height: 100, fit: Fit::Cover),
        );

        $info = getimagesizefromstring((string) $processed->stream);
        Assert::same([$info[0], $info[1]], [100, 100]);
        Assert::same($info['mime'], 'image/png');
    }

    public function rejectsADecompressionBombHeaderBeforeDecoding(): void
    {
        $this->requireExtension();

        $processor = new GdImageProcessor(new Psr17Factory(), maxSourcePixels: 40_000_000);

        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('pixel processing cap');

        $processor->process(
            (new Psr17Factory())->createStream(Png::header(width: 100_000, height: 100_000)),
            'image/png',
            new Preset(name: 'thumb', format: Format::Png, width: 100),
        );
    }

    public function rejectsASourceOverTheByteCap(): void
    {
        $this->requireExtension();

        $processor = new GdImageProcessor(new Psr17Factory(), maxSourceBytes: 16);

        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('byte processing cap');

        $processor->process(
            $this->pngSource(width: 4, height: 4),
            'image/png',
            new Preset(name: 'thumb', format: Format::Png, width: 2),
        );
    }

    public function failsOnBytesThatDoNotDecode(): void
    {
        $this->requireExtension();

        // A truthful header on a body that is not a PNG: past the guard,
        // dead at the decoder.
        Expect::exception(ProcessingFailedException::class)->withMessageContaining('could not decode');

        (new GdImageProcessor(new Psr17Factory()))->process(
            (new Psr17Factory())->createStream(Png::header(width: 4, height: 4) . 'garbage'),
            'image/png',
            new Preset(name: 'thumb', format: Format::Png, width: 2),
        );
    }

    public function throwsInsteadOfGuessingOnAnUnsupportedPair(): void
    {
        $this->requireExtension();

        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('GD cannot process');

        (new GdImageProcessor(new Psr17Factory()))->process(
            $this->pngSource(width: 4, height: 4),
            'image/svg+xml',
            new Preset(name: 'thumb', format: Format::Png, width: 2),
        );
    }


    private function requireExtension(): void
    {
        if (!\extension_loaded('gd')) {
            throw new SkipTest('ext-gd is not loaded');
        }
    }

    private function pngSource(int $width, int $height): \Psr\Http\Message\StreamInterface
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 100, 50));

        ob_start();
        imagepng($image);

        return (new Psr17Factory())->createStream((string) ob_get_clean());
    }
}
