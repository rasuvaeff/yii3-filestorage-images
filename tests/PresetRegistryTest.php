<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use Rasuvaeff\Yii3FilestorageImages\Exception\InvalidPresetException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnknownPresetException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PresetRegistry::class)]
final class PresetRegistryTest
{
    public function findsWhatItHolds(): void
    {
        $thumb = new Preset(name: 'thumb', format: Format::Webp, width: 200);
        $registry = new PresetRegistry([$thumb]);

        Assert::same($registry->get('thumb'), $thumb);
        Assert::true($registry->has('thumb'));
        Assert::false($registry->has('poster'));
        Assert::same($registry->names(), ['thumb']);
    }

    public function unknownPresetNamesTheConfiguredOnes(): void
    {
        $registry = new PresetRegistry([new Preset(name: 'thumb', format: Format::Webp, width: 200)]);

        Expect::exception(UnknownPresetException::class)
            ->withMessageContaining('Unknown preset "poster" — configured presets are "thumb"');

        $registry->get('poster');
    }

    public function unknownPresetOnAnEmptyRegistrySaysSo(): void
    {
        Expect::exception(UnknownPresetException::class)->withMessageContaining('no presets are configured');

        (new PresetRegistry())->get('thumb');
    }

    public function rejectsDuplicateNames(): void
    {
        Expect::exception(InvalidPresetException::class)->withMessageContaining('Duplicate preset "thumb"');

        new PresetRegistry([
            new Preset(name: 'thumb', format: Format::Webp, width: 200),
            new Preset(name: 'thumb', format: Format::Jpeg, width: 100),
        ]);
    }

    public function fromArrayBuildsEveryEntry(): void
    {
        $registry = PresetRegistry::fromArray([
            'thumb' => ['width' => 200, 'height' => 200, 'fit' => 'cover', 'format' => 'webp'],
            'poster' => ['width' => 1280, 'format' => 'jpeg'],
        ]);

        Assert::same($registry->names(), ['thumb', 'poster']);
        Assert::same($registry->get('poster')->format, Format::Jpeg);
    }

    public function fromArrayRejectsNonStringNames(): void
    {
        Expect::exception(InvalidPresetException::class)->withMessageContaining('non-empty strings');

        PresetRegistry::fromArray([['width' => 200, 'format' => 'webp']]);
    }

    public function fromArrayRejectsNonArrayOptions(): void
    {
        Expect::exception(InvalidPresetException::class)->withMessageContaining('options must be an array');

        PresetRegistry::fromArray(['thumb' => 'webp']);
    }

    public function fromArrayOfNothingIsAValidEmptyWhitelist(): void
    {
        Assert::same(PresetRegistry::fromArray([])->names(), []);
    }
}
