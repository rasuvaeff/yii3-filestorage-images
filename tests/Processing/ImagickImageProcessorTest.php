<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Fit;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImagickImageProcessor;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Png;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Test;

/**
 * Every test bails out when ext-imagick is absent; CI installs the extension
 * and runs them all.
 */
#[Test]
#[Covers(ImagickImageProcessor::class)]
final class ImagickImageProcessorTest
{
    public function refusesEverythingWithoutTheExtension(): void
    {
        if (\extension_loaded('imagick')) {
            Assert::true((new ImagickImageProcessor(new Psr17Factory()))->supports('image/png', Format::Png));

            return;
        }

        Assert::false((new ImagickImageProcessor(new Psr17Factory()))->supports('image/png', Format::Png));
    }

    public function resizesContainAndReencodes(): void
    {
        $this->requireExtension();

        $processed = (new ImagickImageProcessor(new Psr17Factory()))->process(
            $this->pngSource(width: 400, height: 200),
            'image/png',
            new Preset(name: 'thumb', format: Format::Jpeg, width: 100, height: 100),
        );

        Assert::same([$processed->width, $processed->height], [100, 50]);

        $info = getimagesizefromstring((string) $processed->stream);
        Assert::same([$info[0], $info[1]], [100, 50]);
        Assert::same($info['mime'], 'image/jpeg');
    }

    public function coverProducesTheExactBox(): void
    {
        $this->requireExtension();

        $processed = (new ImagickImageProcessor(new Psr17Factory()))->process(
            $this->pngSource(width: 400, height: 200),
            'image/png',
            new Preset(name: 'sq', format: Format::Png, width: 100, height: 100, fit: Fit::Cover),
        );

        $info = getimagesizefromstring((string) $processed->stream);
        Assert::same([$info[0], $info[1]], [100, 100]);
    }

    public function rejectsADecompressionBombHeaderBeforeDecoding(): void
    {
        $this->requireExtension();

        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('pixel processing cap');

        (new ImagickImageProcessor(new Psr17Factory()))->process(
            (new Psr17Factory())->createStream(Png::header(width: 100_000, height: 100_000)),
            'image/png',
            new Preset(name: 'thumb', format: Format::Png, width: 100),
        );
    }

    public function failsOnBytesThatDoNotDecode(): void
    {
        $this->requireExtension();

        Expect::exception(ProcessingFailedException::class)->withMessageContaining('Imagick could not process');

        (new ImagickImageProcessor(new Psr17Factory()))->process(
            (new Psr17Factory())->createStream(Png::header(width: 4, height: 4) . 'garbage'),
            'image/png',
            new Preset(name: 'thumb', format: Format::Png, width: 2),
        );
    }


    private function requireExtension(): void
    {
        if (!\extension_loaded('imagick')) {
            throw new SkipTest('ext-imagick is not loaded');
        }
    }

    private function pngSource(int $width, int $height): \Psr\Http\Message\StreamInterface
    {
        $image = new \Imagick();
        $image->newImage($width, $height, 'orange');
        $image->setImageFormat('PNG');

        return (new Psr17Factory())->createStream($image->getImageBlob());
    }
}
