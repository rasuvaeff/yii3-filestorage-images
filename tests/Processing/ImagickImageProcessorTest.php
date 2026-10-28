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
use Testo\Data\DataProvider;
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

    public function refusesMediaTypesOutsideTheRasterMap(): void
    {
        $this->requireExtension();

        $processor = new ImagickImageProcessor(new Psr17Factory());

        Assert::false($processor->supports('image/svg+xml', Format::Png));
        Assert::false($processor->supports('application/pdf', Format::Png));
        Assert::false($processor->supports('image/x-ms-bmp', Format::Png));
    }

    /**
     * An animated source yields a still rendition of its first frame — one
     * image in the output, and the geometry of frame one (20×10), not of the
     * larger second frame (30×15).
     */
    public function anAnimatedSourceYieldsASingleFirstFrameStill(): void
    {
        $this->requireExtension();

        $frames = new \Imagick();
        $frames->newImage(20, 10, new \ImagickPixel('red'), 'GIF');
        $frames->newImage(30, 15, new \ImagickPixel('blue'), 'GIF');

        $processed = (new ImagickImageProcessor(new Psr17Factory()))->process(
            (new Psr17Factory())->createStream($frames->getImageBlob()),
            'image/gif',
            new Preset(name: 'still', format: Format::Png, width: 10),
        );

        $out = new \Imagick();
        $out->readImageBlob((string) $processed->stream);

        Assert::same($out->getNumberImages(), 1);
        Assert::same([$processed->width, $processed->height], [10, 5]);
    }

    #[DataProvider('orientationSwapsDimensionsProvider')]
    public function exifOrientationIsAppliedBeforeTheGeometry(int $orientation): void
    {
        $this->requireExtension();

        // 40×20 upright becomes 20×40 once the rotation is applied; contain
        // by width then produces a portrait output.
        $processed = (new ImagickImageProcessor(new Psr17Factory()))->process(
            $this->orientedJpegSource(width: 40, height: 20, orientation: $orientation),
            'image/jpeg',
            new Preset(name: 'oriented', format: Format::Png, width: 20),
        );

        Assert::same([$processed->width, $processed->height], [20, 40]);
    }

    public static function orientationSwapsDimensionsProvider(): iterable
    {
        // EXIF orientation tags as literal ints: the provider must not touch
        // \Imagick constants, which only exist with the extension loaded.
        yield 'left-top (transpose)' => [5];
        yield 'right-top (rotate 90)' => [6];
        yield 'right-bottom (flop + rotate 90)' => [7];
        yield 'left-bottom (rotate -90)' => [8];
    }

    /**
     * The three orientations that keep the dimensions are distinguished by
     * pixels: the top-left quadrant colour after each transform is unique,
     * so a dropped or swapped arm cannot pass as another one.
     */
    #[DataProvider('orientationQuadrantProvider')]
    public function mirroringOrientationsMoveTheTopLeftQuadrant(int $orientation, string $topLeft): void
    {
        $this->requireExtension();

        $processed = (new ImagickImageProcessor(new Psr17Factory()))->process(
            $this->quadrantJpegSource(width: 40, height: 20, orientation: $orientation),
            'image/jpeg',
            new Preset(name: 'oriented', format: Format::Png, width: 40),
        );

        $out = new \Imagick();
        $out->readImageBlob((string) $processed->stream);
        $pixel = $out->getImagePixelColor(5, 2)->getColor();

        // JPEG never comes back bit-exact even at quality 100 — one unit of
        // chroma drift is fine. A dropped or swapped orientation arm puts a
        // different quadrant on this corner, which is hundreds of units away.
        $actual = [$pixel['r'], $pixel['g'], $pixel['b']];
        $expected = $this->rgb($topLeft);
        foreach ($actual as $index => $channel) {
            Assert::true(\abs($channel - $expected[$index]) <= 2);
        }
    }

    public static function orientationQuadrantProvider(): iterable
    {
        yield 'top-right flops' => [2, 'blue'];
        yield 'bottom-right rotates 180' => [3, 'yellow'];
        yield 'bottom-left flops and rotates' => [4, 'green'];
    }

    /**
     * JPEG has no alpha channel: a transparent source composites onto white,
     * not onto black and not onto whatever the encoder defaults to.
     */
    public function transparentSourcesFlattenOntoExactWhiteForJpeg(): void
    {
        $this->requireExtension();

        $source = new \Imagick();
        $source->newImage(16, 16, new \ImagickPixel('transparent'), 'PNG');

        $processed = (new ImagickImageProcessor(new Psr17Factory()))->process(
            (new Psr17Factory())->createStream($source->getImageBlob()),
            'image/png',
            new Preset(name: 'flat', format: Format::Jpeg, width: 16, quality: 100),
        );

        $out = new \Imagick();
        $out->readImageBlob((string) $processed->stream);
        $pixel = $out->getImagePixelColor(0, 0)->getColor();

        Assert::same([$pixel['r'], $pixel['g'], $pixel['b']], [255, 255, 255]);
    }

    /**
     * Cover crops the widest colour band away: with four vertical bands the
     * 1:1 window keeps the middle two, so the output's tenth pixel is blue —
     * a processor that resizes without cropping would show red there.
     */
    public function coverCropsBeforeItResizes(): void
    {
        $this->requireExtension();

        $processed = (new ImagickImageProcessor(new Psr17Factory()))->process(
            $this->bandPngSource(width: 400, height: 200),
            'image/png',
            new Preset(name: 'sq', format: Format::Png, width: 100, height: 100, fit: Fit::Cover),
        );

        $out = new \Imagick();
        $out->readImageBlob((string) $processed->stream);
        $pixel = $out->getImagePixelColor(10, 50)->getColor();

        Assert::same([$pixel['r'], $pixel['g'], $pixel['b']], $this->rgb('blue'));
    }

    /**
     * The crop's canvas geometry is forgotten before the resize — a GIF that
     * still carries the pre-crop page offsets replays them on every player.
     */
    public function aCroppedGifCarriesNoStalePageGeometry(): void
    {
        $this->requireExtension();

        $processed = (new ImagickImageProcessor(new Psr17Factory()))->process(
            $this->bandPngSource(width: 400, height: 200),
            'image/png',
            new Preset(name: 'sq', format: Format::Gif, width: 100, height: 100, fit: Fit::Cover),
        );

        $out = new \Imagick();
        $out->readImageBlob((string) $processed->stream);

        Assert::same($out->getImagePage(), ['width' => 100, 'height' => 100, 'x' => 0, 'y' => 0]);
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

    /**
     * @return array{int, int, int}
     */
    private function rgb(string $name): array
    {
        return match ($name) {
            'red' => [255, 0, 0],
            'green' => [0, 128, 0],
            'blue' => [0, 0, 255],
            'yellow' => [255, 255, 0],
        };
    }

    /**
     * A JPEG carrying an EXIF orientation tag, quality 100 so flat areas
     * survive the round-trip exactly. The tag is injected as a hand-built
     * APP1 segment: this imagick build writes no EXIF profile of its own,
     * and the fixture must not depend on how the extension was compiled.
     */
    private function orientedJpegSource(int $width, int $height, int $orientation): \Psr\Http\Message\StreamInterface
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('orange'), 'JPEG');
        $image->setImageCompressionQuality(100);

        return (new Psr17Factory())->createStream(
            $this->withExifOrientation($image->getImageBlob(), $orientation),
        );
    }

    /**
     * Top-left red, top-right blue, bottom-left green, bottom-right yellow —
     * every orientation maps a distinct quadrant onto the top-left corner.
     */
    private function quadrantJpegSource(int $width, int $height, int $orientation): \Psr\Http\Message\StreamInterface
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('red'), 'JPEG');

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel('rgb(0,0,255)'));
        $draw->rectangle((int) ($width / 2), 0, $width - 1, (int) ($height / 2) - 1);
        $draw->setFillColor(new \ImagickPixel('rgb(0,128,0)'));
        $draw->rectangle(0, (int) ($height / 2), (int) ($width / 2) - 1, $height - 1);
        $draw->setFillColor(new \ImagickPixel('rgb(255,255,0)'));
        $draw->rectangle((int) ($width / 2), (int) ($height / 2), $width - 1, $height - 1);
        $image->drawImage($draw);

        $image->setImageCompressionQuality(100);

        return (new Psr17Factory())->createStream(
            $this->withExifOrientation($image->getImageBlob(), $orientation),
        );
    }

    /**
     * A minimal big-endian EXIF APP1 segment carrying only Orientation,
     * spliced in right after the SOI marker.
     *
     * @param non-empty-string $blob
     *
     * @return non-empty-string
     */
    private function withExifOrientation(string $blob, int $orientation): string
    {
        $entry = \pack('n', 0x0112) . \pack('n', 3) . \pack('N', 1) . \pack('n', $orientation) . "\x00\x00";
        $ifd = \pack('n', 1) . $entry . \pack('N', 0);
        $payload = "Exif\x00\x00" . "MM\x00\x2A" . \pack('N', 8) . $ifd;
        $app1 = "\xFF\xE1" . \pack('n', \strlen($payload) + 2) . $payload;

        return substr($blob, 0, 2) . $app1 . substr($blob, 2);
    }

    /**
     * Four equal vertical bands: red | blue | green | yellow.
     */
    private function bandPngSource(int $width, int $height): \Psr\Http\Message\StreamInterface
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('red'), 'PNG');

        $draw = new \ImagickDraw();
        $band = (int) ($width / 4);
        $draw->setFillColor(new \ImagickPixel('rgb(0,0,255)'));
        $draw->rectangle($band, 0, 2 * $band - 1, $height - 1);
        $draw->setFillColor(new \ImagickPixel('rgb(0,128,0)'));
        $draw->rectangle(2 * $band, 0, 3 * $band - 1, $height - 1);
        $draw->setFillColor(new \ImagickPixel('rgb(255,255,0)'));
        $draw->rectangle(3 * $band, 0, $width - 1, $height - 1);
        $image->drawImage($draw);

        return (new Psr17Factory())->createStream($image->getImageBlob());
    }

    private function pngSource(int $width, int $height): \Psr\Http\Message\StreamInterface
    {
        $image = new \Imagick();
        $image->newImage($width, $height, 'orange');
        $image->setImageFormat('PNG');

        return (new Psr17Factory())->createStream($image->getImageBlob());
    }
}
