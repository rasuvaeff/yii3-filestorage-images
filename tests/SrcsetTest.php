<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use DateTimeImmutable;
use Psr\Http\Message\StreamInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3FilestorageImages\Derivatives;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImageProcessorInterface;
use Rasuvaeff\Yii3FilestorageImages\Processing\ProcessedImage;
use Rasuvaeff\Yii3FilestorageImages\Srcset;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\DerivativeAwareMemoryStore;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Doubles;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\PredictableMutexFactory;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\SignedProxyUrlGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\when;

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
        Assert::false(str_contains((string) $srcset, 'poster'));
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

    /**
     * Review 1.0 #12. The `w` descriptor was the preset's box width, but a
     * `contain` preset that does not upscale renders a 300 px source at 300,
     * not 800 — and the browser picked a candidate by a width it does not
     * have. When the row carries its dimensions (`ImageMetadataStorage`), the
     * descriptor is the rendition's real width.
     */
    public function theDescriptorIsTheRenditionsRealWidthWhenTheRowKnowsIt(): void
    {
        $file = Fixtures::file(metadata: ['width' => 300, 'height' => 200]);

        Assert::same(
            Srcset::for($this->derivatives(new SignedProxyUrlGenerator()), $this->presets(), $file),
            'https://app.example.test/files/file-1/thumb/1767229200 100w'
                . ', https://app.example.test/files/file-1/wide/1767229200 300w',
        );
    }

    /**
     * Two candidates with one width are invalid `srcset`; the first stays.
     */
    public function candidatesLandingOnOneWidthKeepTheFirst(): void
    {
        $file = Fixtures::file(metadata: ['width' => 60, 'height' => 60]);

        Assert::same(
            Srcset::for($this->derivatives(new SignedProxyUrlGenerator()), $this->presets(), $file),
            'https://app.example.test/files/file-1/thumb/1767229200 60w',
        );
    }

    /**
     * One preset this installation cannot produce used to throw out of the
     * whole attribute — and the page render with it. It is left out instead.
     */
    public function aPresetThatCannotBeProducedIsLeftOut(): void
    {
        $presets = new PresetRegistry([
            new Preset(name: 'thumb', format: Format::Webp, width: 100),
            new Preset(name: 'modern', format: Format::Avif, width: 400),
        ]);
        $processor = new class implements ImageProcessorInterface {
            #[\Override]
            public function supports(string $sourceMediaType, Format $output): bool
            {
                return $output !== Format::Avif;
            }

            #[\Override]
            public function process(StreamInterface $source, string $sourceMediaType, Preset $preset): ProcessedImage
            {
                throw new \LogicException('the proxy path never generates');
            }
        };

        Assert::same(
            Srcset::for(
                $this->derivatives(new SignedProxyUrlGenerator(), $presets, $processor),
                $presets,
                Fixtures::file(),
            ),
            'https://app.example.test/files/file-1/thumb/1767229200 100w',
        );
    }

    /**
     * Review 1.0 pass 2, N10: a srcset built in a job for a tenant's file
     * mints through the generator `withProxyUrls()` pinned, not the shared one.
     */
    public function aFacadeWithAnotherGeneratorMintsThroughIt(): void
    {
        $shared = new SignedProxyUrlGenerator();
        $pinned = new SignedProxyUrlGenerator();

        $srcset = Srcset::for(
            $this->derivatives($shared)->withProxyUrls($pinned),
            $this->presets(),
            Fixtures::file(),
        );

        Assert::notNull($srcset);
        Assert::notNull($pinned->lastVariant);
        Assert::null($shared->lastVariant);
    }

    /**
     * The rendition's width comes from both source sides: a box preset
     * limited by its height scales a 300x200 source to 150 wide.
     */
    public function theRealWidthAccountsForTheSourceHeight(): void
    {
        $presets = new PresetRegistry([new Preset(name: 'band', format: Format::Webp, width: 400, height: 100)]);
        $file = Fixtures::file(metadata: ['width' => 300, 'height' => 200]);

        Assert::same(
            Srcset::for($this->derivatives(new SignedProxyUrlGenerator(), $presets), $presets, $file),
            'https://app.example.test/files/file-1/band/1767229200 150w',
        );
    }

    /**
     * A repeated width drops that one candidate, not the rest of the list.
     */
    public function candidatesAfterARepeatedWidthStillCount(): void
    {
        $presets = new PresetRegistry([
            new Preset(name: 'thumb', format: Format::Webp, width: 100),
            new Preset(name: 'wide', format: Format::Webp, width: 800),
            new Preset(name: 'huge', format: Format::Webp, width: 1000, upscale: true),
        ]);
        $file = Fixtures::file(metadata: ['width' => 60, 'height' => 60]);

        Assert::same(
            Srcset::for($this->derivatives(new SignedProxyUrlGenerator(), $presets), $presets, $file),
            'https://app.example.test/files/file-1/thumb/1767229200 60w'
                . ', https://app.example.test/files/file-1/huge/1767229200 1000w',
        );
    }

    /**
     * A preset left out — one that cannot be produced, or one the proxy
     * mints no URL for — does not end the list.
     */
    public function candidatesAfterALeftOutPresetStillCount(): void
    {
        $presets = new PresetRegistry([
            new Preset(name: 'modern', format: Format::Avif, width: 400),
            new Preset(name: 'unsigned', format: Format::Webp, width: 200),
            new Preset(name: 'thumb', format: Format::Webp, width: 100),
        ]);
        $proxy = new class implements ProxyUrlGeneratorInterface {
            #[\Override]
            public function url(File $file, DateTimeImmutable $expiresAt): ?string
            {
                return null;
            }

            #[\Override]
            public function variantUrl(File $file, string $variant, DateTimeImmutable $expiresAt): ?string
            {
                return $variant === 'unsigned' ? null : "https://app.example.test/{$variant}";
            }
        };
        $processor = new class implements ImageProcessorInterface {
            #[\Override]
            public function supports(string $sourceMediaType, Format $output): bool
            {
                return $output !== Format::Avif;
            }

            #[\Override]
            public function process(StreamInterface $source, string $sourceMediaType, Preset $preset): ProcessedImage
            {
                throw new \LogicException('the proxy path never generates');
            }
        };

        Assert::same(
            Srcset::for($this->derivatives($proxy, $presets, $processor), $presets, Fixtures::file()),
            'https://app.example.test/thumb 100w',
        );
    }

    /**
     * A store that cannot hold derivatives at all, or a failure remembered
     * for every rendition: the attribute is absent, the page still renders.
     */
    public function presetsThatCannotBeServedLeaveNoAttribute(): void
    {
        $plain = new StoreRegistry([new InMemoryStore('memory', Fixtures::factory())]);
        Assert::null(Srcset::for($this->derivatives(new SignedProxyUrlGenerator(), stores: $plain), $this->presets(), Fixtures::file()));

        $remembered = Understudy::for(CacheInterface::class);
        when(fn() => $remembered->get(Arg::any(), Arg::any()))->returns(['kind' => 'failed', 'message' => 'broken']);
        Assert::null(Srcset::for(
            $this->derivatives(new SignedProxyUrlGenerator(), failures: $remembered),
            $this->presets(),
            Fixtures::file(),
        ));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableDimensionsProvider(): iterable
    {
        yield 'a width that is not an int' => [['width' => '300', 'height' => 200]];
        yield 'a zero width' => [['width' => 0, 'height' => 200]];
        yield 'a zero height' => [['width' => 300, 'height' => 0]];
    }

    /**
     * Dimensions the row cannot vouch for are no dimensions: the
     * descriptors fall back to the presets' box widths.
     *
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('unusableDimensionsProvider')]
    public function unusableRowDimensionsFallBackToTheBoxWidths(array $metadata): void
    {
        Assert::same(
            Srcset::for(
                $this->derivatives(new SignedProxyUrlGenerator()),
                $this->presets(),
                Fixtures::file(metadata: $metadata),
            ),
            'https://app.example.test/files/file-1/thumb/1767229200 100w'
                . ', https://app.example.test/files/file-1/wide/1767229200 800w',
        );
    }

    private function presets(): PresetRegistry
    {
        return new PresetRegistry([
            new Preset(name: 'thumb', format: Format::Webp, width: 100),
            new Preset(name: 'poster', format: Format::Webp, height: 300),
            new Preset(name: 'wide', format: Format::Webp, width: 800),
        ]);
    }

    private function derivatives(
        ?ProxyUrlGeneratorInterface $proxy,
        ?PresetRegistry $presets = null,
        ?ImageProcessorInterface $processor = null,
        ?StoreRegistry $stores = null,
        ?CacheInterface $failures = null,
    ): Derivatives {
        $store = new DerivativeAwareMemoryStore();
        $file = Fixtures::file();
        $store->setObject($file->relativePath, 'original bytes');

        return new Derivatives(
            stores: $stores ?? new StoreRegistry([$store]),
            presets: $presets ?? $this->presets(),
            processor: $processor ?? Doubles::processor(),
            mutexes: new PredictableMutexFactory(),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            clock: new StaticClock(Fixtures::now()),
            proxyUrls: $proxy,
            failures: $failures,
        );
    }
}
