<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3FilestorageImages\Derivatives;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Rasuvaeff\Yii3FilestorageImages\Srcset;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\DerivativeAwareMemoryStore;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Doubles;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\PredictableMutexFactory;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\SignedProxyUrlGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(Srcset::class)]
final class SrcsetTest
{
    public function listsEveryWidthBearingPreset(): void
    {
        $srcset = Srcset::for($this->derivatives(new SignedProxyUrlGenerator()), $this->presets(), Fixtures::file());

        Assert::same(
            $srcset,
            'https://app.example.test/files/file-1/thumb/1767229200 100w'
                . ', https://app.example.test/files/file-1/wide/1767229200 800w',
        );
    }

    /** A height-only preset has no descriptor — skipped, not guessed. */
    public function heightOnlyPresetsAreSkipped(): void
    {
        $srcset = Srcset::for($this->derivatives(new SignedProxyUrlGenerator()), $this->presets(), Fixtures::file());

        Assert::string($srcset)->contains('wide/1767229200 800w');
        Assert::false((bool) str_contains((string) $srcset, 'poster'));
    }

    /** A chosen subset is an intersection with the whitelist, in the given order. */
    public function aSubsetKeepsTheGivenOrder(): void
    {
        $srcset = Srcset::for(
            $this->derivatives(new SignedProxyUrlGenerator()),
            $this->presets(),
            Fixtures::file(),
            ['wide', 'thumb'],
        );

        Assert::same(
            $srcset,
            'https://app.example.test/files/file-1/wide/1767229200 800w'
                . ', https://app.example.test/files/file-1/thumb/1767229200 100w',
        );
    }

    /** No URL from any preset: no attribute at all, not an empty one. */
    public function nothingYieldableIsNull(): void
    {
        // No proxy bound and the memory store mints no URLs of its own.
        Assert::null(Srcset::for($this->derivatives(null), $this->presets(), Fixtures::file()));
    }

    private function presets(): PresetRegistry
    {
        return new PresetRegistry([
            new Preset(name: 'thumb', format: Format::Webp, width: 100),
            new Preset(name: 'poster', format: Format::Webp, height: 300),
            new Preset(name: 'wide', format: Format::Webp, width: 800),
        ]);
    }

    private function derivatives(?SignedProxyUrlGenerator $proxy): Derivatives
    {
        $store = new DerivativeAwareMemoryStore();
        $file = Fixtures::file();
        $store->setObject($file->relativePath, 'original bytes');

        return new Derivatives(
            stores: new StoreRegistry([$store]),
            presets: $this->presets(),
            processor: Doubles::processor(),
            mutexes: new PredictableMutexFactory(),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            clock: new StaticClock(Fixtures::now()),
            proxyUrls: $proxy,
        );
    }
}
