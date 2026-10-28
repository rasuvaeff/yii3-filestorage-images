<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3FilestorageImages\Exception\InvalidPresetException;
use Rasuvaeff\Yii3FilestorageImages\Fit;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Preset::class)]
final class PresetTest
{
    public function descriptorCarriesVersionedNameAndFormatIdentity(): void
    {
        $descriptor = (new Preset(name: 'thumb', format: Format::Webp, width: 200, version: 3))->descriptor();

        Assert::same($descriptor->name, 'thumb-v3');
        Assert::same($descriptor->extension, 'webp');
        Assert::same($descriptor->mediaType, 'image/webp');
        Assert::same($descriptor->fileName(), 'thumb-v3.webp');
    }

    public function versionBumpChangesThePhysicalName(): void
    {
        $v1 = new Preset(name: 'thumb', format: Format::Webp, width: 200, version: 1);
        $v2 = new Preset(name: 'thumb', format: Format::Webp, width: 200, version: 2);

        Assert::true($v1->descriptor()->fileName() !== $v2->descriptor()->fileName());
    }

    #[DataProvider('invalidConstructionProvider')]
    public function rejectsInvalidConstruction(callable $construct, string $messagePart): void
    {
        Expect::exception(InvalidPresetException::class)->withMessageContaining($messagePart);

        $construct();
    }

    public static function invalidConstructionProvider(): iterable
    {
        yield 'empty name' => [
            static fn(): Preset => new Preset(name: '', format: Format::Webp, width: 1),
            'Invalid preset name',
        ];
        yield 'uppercase name' => [
            static fn(): Preset => new Preset(name: 'Thumb', format: Format::Webp, width: 1),
            'Invalid preset name',
        ];
        yield 'name too long for the descriptor suffix' => [
            static fn(): Preset => new Preset(name: str_repeat('a', 49), format: Format::Webp, width: 1),
            'Invalid preset name',
        ];
        yield 'version zero' => [
            static fn(): Preset => new Preset(name: 'thumb', format: Format::Webp, width: 1, version: 0),
            'version must be between 1 and 9999',
        ];
        yield 'version over cap' => [
            static fn(): Preset => new Preset(name: 'thumb', format: Format::Webp, width: 1, version: 10_000),
            'version must be between 1 and 9999',
        ];
        yield 'zero width' => [
            static fn(): Preset => new Preset(name: 'thumb', format: Format::Webp, width: 0),
            'width must be between 1 and 16384',
        ];
        yield 'height over cap' => [
            static fn(): Preset => new Preset(name: 'thumb', format: Format::Webp, height: 16_385),
            'height must be between 1 and 16384',
        ];
        yield 'no dimensions at all' => [
            static fn(): Preset => new Preset(name: 'thumb', format: Format::Webp),
            'at least one of width and height',
        ];
        yield 'cover with one side' => [
            static fn(): Preset => new Preset(name: 'thumb', format: Format::Webp, width: 200, fit: Fit::Cover),
            'requires both width and height',
        ];
        yield 'quality zero' => [
            static fn(): Preset => new Preset(name: 'thumb', format: Format::Webp, width: 1, quality: 0),
            'quality must be between 1 and 100',
        ];
        yield 'quality over 100' => [
            static fn(): Preset => new Preset(name: 'thumb', format: Format::Webp, width: 1, quality: 101),
            'quality must be between 1 and 100',
        ];
        yield 'negative maxBytes' => [
            static fn(): Preset => new Preset(name: 'thumb', format: Format::Webp, width: 1, maxBytes: -1),
            'maxBytes must not be negative',
        ];
    }

    /**
     * Every range check is inclusive at both ends: version 9999, dimensions
     * at the 16384 cap and quality at 1 and 100 are all valid presets. A
     * strict comparison here would silently reject the boundary the message
     * promises.
     */
    public function boundariesAreValidOnBothEnds(): void
    {
        $maxed = new Preset(
            name: 'max',
            format: Format::Webp,
            width: Preset::MAX_DIMENSION,
            height: Preset::MAX_DIMENSION,
            quality: 100,
            version: 9999,
        );
        $minimal = new Preset(name: 'min', format: Format::Webp, width: 1, height: 1, quality: 1);

        Assert::same($maxed->version, 9999);
        Assert::same([$maxed->width, $maxed->height], [16_384, 16_384]);
        Assert::same($maxed->quality, 100);
        Assert::same([$minimal->width, $minimal->height], [1, 1]);
        Assert::same($minimal->quality, 1);
    }

    /**
     * A width-only preset leaves height null (and vice versa) — the state the
     * constructor's narrowing assertions have to tolerate.
     */
    public function oneSidedPresetLeavesTheOtherSideNull(): void
    {
        $widthOnly = new Preset(name: 'wide', format: Format::Webp, width: 100);
        $heightOnly = new Preset(name: 'tall', format: Format::Webp, height: 100);

        Assert::null($widthOnly->height);
        Assert::null($heightOnly->width);
    }

    public function fromArrayBuildsTheFullShape(): void
    {
        $preset = Preset::fromArray('avatar', [
            'version' => 2,
            'width' => 128,
            'height' => 128,
            'fit' => 'cover',
            'format' => 'avif',
            'quality' => 60,
            'upscale' => true,
            'maxBytes' => 100_000,
        ]);

        Assert::same($preset->name, 'avatar');
        Assert::same($preset->version, 2);
        Assert::same($preset->width, 128);
        Assert::same($preset->height, 128);
        Assert::same($preset->fit, Fit::Cover);
        Assert::same($preset->format, Format::Avif);
        Assert::same($preset->quality, 60);
        Assert::true($preset->upscale);
        Assert::same($preset->maxBytes, 100_000);
    }

    public function fromArrayDefaultsMatchTheConstructor(): void
    {
        $preset = Preset::fromArray('thumb', ['width' => 200, 'format' => 'webp']);

        Assert::same($preset->version, 1);
        Assert::same($preset->fit, Fit::Contain);
        Assert::same($preset->quality, 82);
        Assert::false($preset->upscale);
        Assert::same($preset->maxBytes, 0);
        Assert::null($preset->height);
    }

    #[DataProvider('invalidArrayProvider')]
    public function fromArrayRejectsBadConfig(array $config, string $messagePart): void
    {
        Expect::exception(InvalidPresetException::class)->withMessageContaining($messagePart);

        Preset::fromArray('thumb', $config);
    }

    public static function invalidArrayProvider(): iterable
    {
        yield 'unknown key names itself' => [
            ['widht' => 200, 'format' => 'webp'],
            'unknown option "widht" — known options are "version", "width", "height", "fit", "format", "quality", "upscale", "maxBytes"',
        ];
        yield 'missing format' => [
            ['width' => 200],
            'format must be one of "jpeg", "png", "webp", "gif", "avif"',
        ];
        yield 'format not in the enum' => [
            ['width' => 200, 'format' => 'bmp'],
            'format must be one of "jpeg", "png", "webp", "gif", "avif"',
        ];
        yield 'fit not in the enum' => [
            ['width' => 200, 'format' => 'webp', 'fit' => 'stretch'],
            'fit must be one of "contain", "cover"',
        ];
        yield 'width as string' => [
            ['width' => '200', 'format' => 'webp'],
            'width must be an integer',
        ];
        yield 'upscale as int' => [
            ['width' => 200, 'format' => 'webp', 'upscale' => 1],
            'upscale must be a boolean',
        ];
    }

    /**
     * The name pattern is a security boundary — the name becomes a filename
     * inside the file's directory — so acceptance is checked the way the
     * regex-format rule prescribes: generate strings from the full alphabet,
     * assert construction succeeds exactly when the anchored pattern matches.
     */
    #[Property(runs: 300, timeoutMs: 2_000)]
    public function nameAcceptanceMatchesTheAnchoredPattern(string $name): void
    {
        $matches = preg_match(pattern: '/^[a-z0-9][a-z0-9_-]{0,47}\z/', subject: $name) === 1;

        try {
            new Preset(name: $name, format: Format::Webp, width: 1);
            Assert::true($matches);
        } catch (InvalidPresetException) {
            Assert::false($matches);
        }
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function nameAcceptanceMatchesTheAnchoredPatternGenerators(): array
    {
        return [
            'name' => Gen::stringFrom('abz09_-AZ.é/ ', maxLength: 52),
        ];
    }

    public static function nameAcceptanceMatchesTheAnchoredPatternExamples(): iterable
    {
        yield 'single char' => ['a'];
        yield 'exactly 48 chars' => [str_repeat('a', 48)];
        yield '49 chars is too long' => [str_repeat('a', 49)];
        yield 'leading dash' => ['-thumb'];
        yield 'leading underscore' => ['_thumb'];
        yield 'trailing newline sneaks past a $ anchor' => ["thumb\n"];
        yield 'empty' => [''];
    }
}
