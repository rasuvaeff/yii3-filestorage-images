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
use Testo\Data\DataProvider;
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
        // An unmapped source is not "unknown", it is refused — even when the
        // output format is fine.
        Assert::false($processor->supports('image/x-ms-bmp', Format::Png));
    }

    #[DataProvider('outputFormatProvider')]
    public function encodesIntoEverySupportedOutputFormat(Format $format): void
    {
        $this->requireExtension();
        if ((\imagetypes() & $this->formatBit($format)) !== $this->formatBit($format)) {
            throw new SkipTest('this gd build cannot encode ' . $format->mediaType());
        }

        $processed = (new GdImageProcessor(new Psr17Factory()))->process(
            $this->pngSource(width: 40, height: 20),
            'image/png',
            new Preset(name: 'out', format: $format, width: 20),
        );

        $info = getimagesizefromstring((string) $processed->stream);
        Assert::same($info['mime'], $format->mediaType());
        Assert::same([$info[0], $info[1]], [20, 10]);
    }

    public static function outputFormatProvider(): iterable
    {
        yield 'jpeg' => [Format::Jpeg];
        yield 'png' => [Format::Png];
        yield 'webp' => [Format::Webp];
        yield 'gif' => [Format::Gif];
        yield 'avif' => [Format::Avif];
    }

    /**
     * The encoded stream is rewound: reading from the current position — not
     * the PSR-7 __toString() that rewinds on its own — yields the image.
     */
    public function theEncodedStreamIsRewound(): void
    {
        $this->requireExtension();

        $processed = (new GdImageProcessor(new Psr17Factory()))->process(
            $this->pngSource(width: 40, height: 20),
            'image/png',
            new Preset(name: 'out', format: Format::Png, width: 20),
        );

        Assert::true(str_starts_with($processed->stream->getContents(), "\x89PNG"));
    }

    /**
     * A 1:1 copy (source 4×4 into a width-4 contain) must read from the
     * source origin: the leftmost column and the top row are painted a
     * different colour so a one-pixel shift shows at [0,0].
     */
    public function oneToOneCopyReadsFromTheSourceOrigin(): void
    {
        $this->requireExtension();

        $source = imagecreatetruecolor(4, 4);
        imagefill($source, 0, 0, (int) imagecolorallocate($source, 255, 0, 0));
        imagefilledrectangle($source, 1, 0, 3, 3, (int) imagecolorallocate($source, 0, 0, 255));

        ob_start();
        imagepng($source);

        $processed = (new GdImageProcessor(new Psr17Factory()))->process(
            (new Psr17Factory())->createStream((string) ob_get_clean()),
            'image/png',
            new Preset(name: 'copy', format: Format::Png, width: 4, height: 4),
        );

        $out = imagecreatefromstring((string) $processed->stream);
        $corner = imagecolorsforindex($out, imagecolorat($out, 0, 0));
        $inside = imagecolorsforindex($out, imagecolorat($out, 3, 3));

        Assert::same([$corner['red'], $corner['green'], $corner['blue']], [255, 0, 0]);
        Assert::same([$inside['red'], $inside['green'], $inside['blue']], [0, 0, 255]);
    }

    public function transparentSourcesKeepTheirAlphaInAlphaFormats(): void
    {
        $this->requireExtension();

        $processed = (new GdImageProcessor(new Psr17Factory()))->process(
            $this->transparentPngSource(width: 16, height: 16),
            'image/png',
            new Preset(name: 'alpha', format: Format::Png, width: 8),
        );

        $out = imagecreatefromstring((string) $processed->stream);
        $corner = imagecolorsforindex($out, imagecolorat($out, 0, 0));

        Assert::same($corner['alpha'], 127);
    }

    /**
     * JPEG has no alpha: a transparent source composites onto white — not
     * onto black, and not onto a near-white off-by-one.
     */
    public function transparentSourcesFlattenOntoExactWhiteForJpeg(): void
    {
        $this->requireExtension();

        $processed = (new GdImageProcessor(new Psr17Factory()))->process(
            $this->transparentPngSource(width: 16, height: 16),
            'image/png',
            new Preset(name: 'flat', format: Format::Jpeg, width: 16, quality: 100),
        );

        $out = imagecreatefromstring((string) $processed->stream);
        $corner = imagecolorsforindex($out, imagecolorat($out, 0, 0));

        Assert::same([$corner['red'], $corner['green'], $corner['blue']], [255, 255, 255]);
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

    /**
     * The IMG_* bit for an output format, guarded by the caller's
     * requireExtension() — the constants only exist with ext-gd loaded.
     */
    private function formatBit(Format $format): int
    {
        return match ($format->mediaType()) {
            'image/jpeg' => \IMG_JPG,
            'image/png' => \IMG_PNG,
            'image/webp' => \IMG_WEBP,
            'image/gif' => \IMG_GIF,
            'image/avif' => \IMG_AVIF,
        };
    }

    private function pngSource(int $width, int $height): \Psr\Http\Message\StreamInterface
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 100, 50));

        ob_start();
        imagepng($image);

        return (new Psr17Factory())->createStream((string) ob_get_clean());
    }

    private function transparentPngSource(int $width, int $height): \Psr\Http\Message\StreamInterface
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        ob_start();
        imagepng($image);

        return (new Psr17Factory())->createStream((string) ob_get_clean());
    }
}
