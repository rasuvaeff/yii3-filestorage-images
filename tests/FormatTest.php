<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use Rasuvaeff\Yii3FilestorageImages\Format;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Format::class)]
final class FormatTest
{
    #[DataProvider('formatProvider')]
    public function mapsEveryCaseToExtensionAndMediaType(Format $format, string $extension, string $mediaType): void
    {
        Assert::same($format->extension(), $extension);
        Assert::same($format->mediaType(), $mediaType);
    }

    public static function formatProvider(): iterable
    {
        yield 'jpeg' => [Format::Jpeg, 'jpg', 'image/jpeg'];
        yield 'png' => [Format::Png, 'png', 'image/png'];
        yield 'webp' => [Format::Webp, 'webp', 'image/webp'];
        yield 'gif' => [Format::Gif, 'gif', 'image/gif'];
        yield 'avif' => [Format::Avif, 'avif', 'image/avif'];
    }

    public function onlyJpegLacksAlpha(): void
    {
        foreach (Format::cases() as $format) {
            Assert::same($format->supportsAlpha(), $format !== Format::Jpeg);
        }
    }
}
