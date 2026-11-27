<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use DateInterval;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3FilestorageImages\Derivatives;
use Rasuvaeff\Yii3FilestorageImages\Exception\DerivativeGenerationException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnknownPresetException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImageProcessorInterface;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\DerivativeAwareMemoryStore;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\DerivativeUrlMemoryStore;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Doubles;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\PredictableMutexFactory;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\SignedProxyUrlGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;
use Yiisoft\Mutex\MutexFactoryInterface;
use Yiisoft\Mutex\MutexInterface;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\verify;

#[Test]
#[Covers(Derivatives::class)]
final class DerivativesTest
{
    private const string SHA = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    private DerivativeAwareMemoryStore $store;
    private ImageProcessorInterface $processor;
    private MutexFactoryInterface $mutexes;
    private MutexInterface $mutex;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->store = new DerivativeAwareMemoryStore();
        $this->processor = Doubles::processor();
        [$this->mutexes, $this->mutex] = Doubles::mutexes();
    }

    public function generatesOnceAndReusesAfterwards(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $derivatives = $this->derivatives();

        Assert::false($derivatives->exists($file, 'thumb'));
        Assert::true($derivatives->ensure($file, 'thumb'));
        Assert::true($derivatives->exists($file, 'thumb'));

        // The second call is a plain read: no new processing, no new write.
        Assert::false($derivatives->ensure($file, 'thumb'));
        verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), times: 1);
        Assert::same(\count($this->store->derivativeWrites), 1);
        Assert::same($this->store->derivativeBytes($file, $derivatives->descriptor('thumb')), 'processed:thumb-v1');
    }

    public function generationRunsUnderTheBlobScopedLockAndReleasesIt(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $derivatives = $this->derivatives();

        $derivatives->ensure($file, 'thumb');

        $names = Arg::captor();
        verify(fn() => $this->mutexes->create($names->capture()), times: 1);
        // The exact physical identity: prefix + sha256(store + ':' + the path
        // the store's layout names for the rendition), so deduplicated rows
        // share a lock and two presets never collide on one key.
        Assert::same(
            $names->last(),
            'filestorage-images:' . \hash('sha256', 'memory:common/ab/cd/file-1/thumb-v1.webp'),
        );
        verify(fn() => $this->mutex->release(), times: 1);

        // Under dedup a second logical row shares the physical path — and
        // therefore the rendition and the lock name, keyed by BlobId.
        $twin = Fixtures::file(id: 'file-2', relativePath: $file->relativePath);
        Assert::true($this->store->hasDerivative($twin, $derivatives->descriptor('thumb')));
        $derivatives->ensure($twin, 'thumb');
        verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), times: 1);
    }

    public function flatLayoutRowsNamingOneRenditionShareOneLock(): void
    {
        // Under FlatSiblingLayout `<sha>.png` and `<sha>.jpg` both own
        // `<sha>-thumb-v1.webp`: one physical object, so one lock — a key built
        // from the original's path would let two writers race on it.
        $png = Fixtures::file(relativePath: 'blobs/ab/cd/' . self::SHA . '.png');
        $jpg = Fixtures::file(id: 'file-2', relativePath: 'blobs/ab/cd/' . self::SHA . '.jpg');
        $mutexes = new PredictableMutexFactory();
        $this->mutexes = $mutexes;

        foreach ([$png, $jpg] as $file) {
            $this->store = new DerivativeAwareMemoryStore(layout: new FlatSiblingLayout());
            $this->store->setObject($file->relativePath, 'original bytes');
            Assert::true($this->derivatives()->ensure($file, 'thumb'));
        }

        $expected = 'filestorage-images:' . \hash('sha256', 'memory:blobs/ab/cd/' . self::SHA . '-thumb-v1.webp');
        Assert::same($mutexes->created, [$expected, $expected]);
    }

    #[DataProvider('layoutsProvider')]
    public function renditionLandsWhereTheStoreLayoutNamesIt(
        ?ObjectLayoutInterface $layout,
        string $original,
        string $rendition,
    ): void {
        $root = \sys_get_temp_dir() . '/filestorage-images-' . \bin2hex(\random_bytes(6));
        $store = new FileSystemStore(
            name: 'memory',
            rootPath: $root,
            streamFactory: Fixtures::factory(),
            layout: $layout,
        );
        \mkdir(\dirname("{$root}/{$original}"), 0o777, recursive: true);
        \file_put_contents("{$root}/{$original}", 'original bytes');
        $file = Fixtures::file(relativePath: $original);

        try {
            Assert::true($this->derivatives(stores: new StoreRegistry([$store]))->ensure($file, 'thumb'));
            Assert::same(\file_get_contents("{$root}/{$rendition}"), 'processed:thumb-v1');

            // Deleting the file takes the rendition with it, whatever the layout.
            $store->delete($file);
            Assert::false(\file_exists("{$root}/{$rendition}"));
        } finally {
            FileHelper::removeDirectory($root);
        }
    }

    public static function layoutsProvider(): iterable
    {
        yield 'default directory layout' => [
            null,
            'common/ab/cd/file-1/original.png',
            'common/ab/cd/file-1/thumb-v1.webp',
        ];
        yield 'flat sibling layout' => [
            new FlatSiblingLayout(),
            'blobs/ab/cd/' . self::SHA . '.png',
            'blobs/ab/cd/' . self::SHA . '-thumb-v1.webp',
        ];
    }

    public function deniedLockFallsBackToAnObjectSomeoneElseGenerated(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        [$this->mutexes, $this->mutex] = Doubles::mutexes(acquirable: false);
        $derivatives = $this->derivatives();

        // The winner's output appears between the first probe and the lock.
        $this->store->putDerivative($file, $derivatives->descriptor('thumb'), 'their bytes');

        Assert::false($derivatives->ensure($file, 'thumb'));
        verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public function deniedLockWithNothingGeneratedIsAnError(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        [$this->mutexes, $this->mutex] = Doubles::mutexes(acquirable: false);

        Expect::exception(DerivativeGenerationException::class)
            ->withMessageContaining('Could not acquire the generation lock for "thumb-v1.webp" of file "file-1" within 30s');

        $this->derivatives()->ensure($file, 'thumb');
    }

    public function unknownPresetNeverReachesTheProcessor(): void
    {
        Expect::exception(UnknownPresetException::class)->withMessageContaining('Unknown preset "poster"');

        $this->derivatives()->ensure(Fixtures::file(), 'poster');
    }

    public function refusesAStoreWithoutTheDerivativeCapability(): void
    {
        $stores = new StoreRegistry([new InMemoryStore('memory', Fixtures::factory(), new StaticClock(Fixtures::now()))]);

        Expect::exception(DerivativeGenerationException::class)->withMessageContaining(
            'Store "memory" cannot hold derivatives — it does not implement '
                . \Rasuvaeff\Yii3Filestorage\Store\DerivativeAwareStoreInterface::class,
        );

        $this->derivatives(stores: $stores)->ensure(Fixtures::file(), 'thumb');
    }

    public function refusesAFileWithoutADetectedMediaType(): void
    {
        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('no detected media type');

        $this->derivatives()->ensure(Fixtures::file(mimeType: null), 'thumb');
    }

    public function refusesACombinationNoProcessorSupports(): void
    {
        $this->processor = Doubles::processor(supports: false);

        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('No available processor');

        $this->derivatives()->ensure(Fixtures::file(), 'thumb');
    }

    public function refusesASourceOverTheByteCap(): void
    {
        Expect::exception(UnsupportedSourceException::class)->withMessageContaining(
            'File "file-1" is 513 bytes, over the 512-byte processing cap',
        );

        $this->derivatives(maxSourceBytes: 512)->ensure(Fixtures::file(size: 513), 'thumb');
    }

    /**
     * The cap is inclusive at the boundary and disabled at zero — a file of
     * exactly the capped size processes, and a zero cap is not a zero budget.
     */
    public function acceptsASourceExactlyAtTheByteCapAndWithNoCapAtAll(): void
    {
        $file = Fixtures::file(size: 512);
        $this->store->setObject($file->relativePath, 'original bytes');

        Assert::true($this->derivatives(maxSourceBytes: 512)->ensure($file, 'thumb'));

        $huge = Fixtures::file(id: 'huge-file', size: 5_000_000_000);
        $this->store->setObject($huge->relativePath, 'original bytes');

        Assert::true($this->derivatives(maxSourceBytes: 0)->ensure($huge, 'thumb'));
    }

    public function missingOriginalBytesAreAnError(): void
    {
        Expect::exception(DerivativeGenerationException::class)->withMessageContaining('missing from store');

        $this->derivatives()->ensure(Fixtures::file(), 'thumb');
    }

    public function streamGeneratesLazilyAndReturnsTheBytes(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        $stream = $this->derivatives()->stream($file, 'thumb');

        Assert::same((string) $stream, 'processed:thumb-v1');
    }

    public function urlForIsNullWithoutTheUrlCapability(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        Assert::null($this->derivatives()->urlFor($file, 'thumb'));
        // But the rendition was still generated — the caller's fallback is
        // serving the stream itself.
        Assert::true($this->derivatives()->exists($file, 'thumb'));
    }

    /**
     * The facade *is* the resolver `-web` consumes: same methods, same
     * semantics — the contract only formalises what already existed.
     */
    public function implementsTheCoreResolverContract(): void
    {
        Assert::instanceOf($this->derivatives(), DerivativeResolverInterface::class);
    }

    /**
     * A private filesystem store has no URL capability of its own — most
     * installations — so the signed proxy route through the application is
     * the last resort, exactly as core's `Storage::urlFor()` treats it.
     */
    public function urlForFallsBackToTheSignedProxyWhenTheStoreHasNoUrls(): void
    {
        $proxy = new SignedProxyUrlGenerator();
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        $url = $this->derivatives(proxyUrls: $proxy)->urlFor($file, 'thumb');

        Assert::string((string) $url)->contains("https://app.example.test/files/{$file->id}/thumb/");
        Assert::same($proxy->lastVariant, 'thumb');
        // The default TTL applies to the proxy URL as it would to a store one.
        Assert::same(
            $proxy->lastExpiresAt?->format('U.u'),
            Fixtures::now()->add(new DateInterval('PT1H'))->format('U.u'),
        );
    }

    /**
     * An explicit expiry reaches the proxy token too, not only store-native
     * URLs.
     */
    public function theProxyFallbackHonorsAnExplicitExpiry(): void
    {
        $proxy = new SignedProxyUrlGenerator();
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $expires = Fixtures::now()->modify('+2 hours');

        $url = $this->derivatives(proxyUrls: $proxy)->urlFor($file, 'thumb', $expires);

        Assert::string((string) $url)->contains('/' . $expires->getTimestamp());
    }

    /**
     * The proxy is genuinely the *last* resort: a store that can mint URLs
     * but declines (its URLs cannot carry the policy) wins nothing — the
     * proxy stands in.
     */
    public function theProxyIsUsedWhenTheStoreDeclinesToMintOne(): void
    {
        $urlStore = new DerivativeUrlMemoryStore($this->store, publicUrls: false, temporaryUrls: false);
        $proxy = new SignedProxyUrlGenerator();
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        $url = $this->derivatives(
            stores: new StoreRegistry([$urlStore]),
            proxyUrls: $proxy,
        )->urlFor($file, 'thumb');

        Assert::string((string) $url)->contains("https://app.example.test/files/{$file->id}/thumb/");
    }

    public function urlForPrefersPublicOnlyWhenThePolicyAllowsIt(): void
    {
        $urlStore = new DerivativeUrlMemoryStore($this->store);
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $stores = new StoreRegistry([$urlStore]);

        $locked = $this->derivatives(stores: $stores);
        Assert::string((string) $locked->urlFor($file, 'thumb'))->contains('signed.example.test');

        $open = $this->derivatives(
            stores: $stores,
            deliveryPolicies: DeliveryPolicyRegistry::fromArray(['*' => ['allowDirectPublicUrl' => true]]),
        );
        Assert::string((string) $open->urlFor($file, 'thumb'))->contains('cdn.example.test');
    }

    public function urlForFallsBackToTemporaryWhenPublicIsUnavailable(): void
    {
        $urlStore = new DerivativeUrlMemoryStore($this->store, publicUrls: false);
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        $url = $this->derivatives(
            stores: new StoreRegistry([$urlStore]),
            deliveryPolicies: DeliveryPolicyRegistry::fromArray(['*' => ['allowDirectPublicUrl' => true]]),
        )->urlFor($file, 'thumb');

        Assert::string((string) $url)->contains('signed.example.test');
    }

    public function urlForUsesTheClockAndTtlWhenNoExpiryIsGiven(): void
    {
        $urlStore = new DerivativeUrlMemoryStore($this->store);
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        $url = $this->derivatives(stores: new StoreRegistry([$urlStore]))->urlFor($file, 'thumb');

        Assert::string((string) $url)->contains(
            'expires=' . Fixtures::now()->add(new DateInterval('PT1H'))->getTimestamp(),
        );
    }

    public function urlForHonorsAnExplicitExpiryOverTheDefaultTtl(): void
    {
        $urlStore = new DerivativeUrlMemoryStore($this->store);
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        $expiresAt = Fixtures::now()->add(new DateInterval('PT2H'));
        $url = $this->derivatives(stores: new StoreRegistry([$urlStore]))->urlFor($file, 'thumb', $expiresAt);

        Assert::string((string) $url)->contains('expires=' . $expiresAt->getTimestamp());
    }

    public function descriptorExposesThePhysicalIdentity(): void
    {
        Assert::same($this->derivatives()->descriptor('thumb')->fileName(), 'thumb-v1.webp');
    }

    private function derivatives(
        ?StoreRegistry $stores = null,
        ?DeliveryPolicyRegistry $deliveryPolicies = null,
        ?ImageProcessorInterface $processor = null,
        int $maxSourceBytes = 67_108_864,
        ?ProxyUrlGeneratorInterface $proxyUrls = null,
    ): Derivatives {
        return new Derivatives(
            stores: $stores ?? new StoreRegistry([$this->store]),
            presets: new PresetRegistry([new Preset(name: 'thumb', format: Format::Webp, width: 100)]),
            processor: $processor ?? $this->processor,
            mutexes: $this->mutexes,
            deliveryPolicies: $deliveryPolicies ?? new DeliveryPolicyRegistry(),
            clock: new StaticClock(Fixtures::now()),
            maxSourceBytes: $maxSourceBytes,
            proxyUrls: $proxyUrls,
        );
    }
}
