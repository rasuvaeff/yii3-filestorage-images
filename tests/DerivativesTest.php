<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\File;
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
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
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
use Yiisoft\Test\Support\SimpleCache\Action;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;
use Yiisoft\Test\Support\SimpleCache\SimpleCacheActionLogger;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

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
            ->withMessageContaining('Could not acquire the generation lock for "thumb-v1.webp" of file "file-1" within 10s');

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
        // Nothing generated either: no URL comes of it, and the caller's
        // fallback — serving `stream()` — generates on its own.
        Assert::false($this->derivatives()->exists($file, 'thumb'));
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

    /**
     * Review 1.0 #10. A source that always fails — a decode error, a guard,
     * output over the preset's cap — was re-read and re-decoded on every
     * request, each one queueing behind the lock. With a cache bound the
     * failure is remembered: the second request answers from it without the
     * lock, the read or the processor.
     */
    public function aFailedGenerationIsRememberedAndNotRetried(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $this->processor = Doubles::failingProcessor(new ProcessingFailedException('does not decode'));
        $derivatives = $this->derivatives(failures: new MemorySimpleCache());

        foreach ([1, 2] as $attempt) {
            try {
                $derivatives->ensure($file, 'thumb');
                Assert::fail("attempt {$attempt} must fail");
            } catch (ProcessingFailedException $e) {
                Assert::string($e->getMessage())->contains('does not decode');
            }
        }

        verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), times: 1);
        verify(fn() => $this->mutexes->create(Arg::any()), times: 1);
    }

    /**
     * Review 1.0 pass 2, N7: the failure cache is optional. A cache that
     * throws (Redis down) is a miss on read and a no-op on write — the
     * rendition is generated and served, never a 500.
     */
    public function aBrokenFailureCacheIsNoCache(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        $this->derivatives(failures: $this->brokenCache())->ensure($file, 'thumb');

        verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), times: 1);
    }

    /** N7: a cache that fails on write does not replace the generation's own failure. */
    public function aBrokenFailureCacheKeepsTheRealFailure(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        Expect::exception(ProcessingFailedException::class)->withMessageContaining('does not decode');

        $this->derivatives(
            processor: Doubles::failingProcessor(new ProcessingFailedException('does not decode')),
            failures: $this->brokenCache(),
        )->ensure($file, 'thumb');
    }

    /** N7: `urlFor()` consults the failure cache on its proxy path; a broken one is a miss there too. */
    public function aBrokenFailureCacheStillMintsTheProxyUrl(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        $url = $this->derivatives(proxyUrls: new SignedProxyUrlGenerator(), failures: $this->brokenCache())
            ->urlFor($file, 'thumb');

        Assert::notNull($url);
    }

    /**
     * Review 1.0 pass 2, N8: a failure that says nothing about the file — a
     * stalled stream, a full disk, ImageMagick out of resources under load —
     * is not remembered; the next request tries again.
     */
    public function aTransientFailureIsNotRemembered(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $this->processor = Doubles::failingProcessor(ProcessingFailedException::transient('stream stalled'));
        $derivatives = $this->derivatives(failures: new MemorySimpleCache());

        foreach ([1, 2] as $attempt) {
            try {
                $derivatives->ensure($file, 'thumb');
                Assert::fail("attempt {$attempt} must fail");
            } catch (ProcessingFailedException $e) {
                Assert::true($e->isTransient(), "attempt {$attempt}");
            }
        }

        verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), times: 2);
    }

    /**
     * Each verdict comes back as its own class: `warm` counts an unsupported
     * source as a skip, and that must not turn into a failure on replay.
     */
    #[DataProvider('rememberedFailureProvider')]
    public function aRememberedFailureKeepsItsKind(\Throwable $failure, string $replayed): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $this->processor = Doubles::failingProcessor($failure);
        $derivatives = $this->derivatives(failures: new MemorySimpleCache());

        foreach ([$failure::class, $replayed] as $attempt => $expected) {
            try {
                $derivatives->ensure($file, 'thumb');
                Assert::fail("attempt {$attempt} must fail");
            } catch (\Throwable $e) {
                Assert::same($e::class, $expected, "attempt {$attempt}");
            }
        }
        verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), times: 1);
    }

    /**
     * @return iterable<string, array{\Throwable, class-string}>
     */
    public static function rememberedFailureProvider(): iterable
    {
        yield 'a guard refusal' => [new UnsupportedSourceException('over the cap'), UnsupportedSourceException::class];
        yield 'a decode failure' => [new ProcessingFailedException('broken'), ProcessingFailedException::class];
        // The store's own class cannot be rebuilt from a cache entry; the
        // replay is the package's generation failure (still a 404 in -web,
        // still "failed" in warm).
        yield 'output over the preset cap' => [
            new UploadSizeException('rendition over 10 bytes'),
            DerivativeGenerationException::class,
        ];
    }

    /**
     * A request that waited for the lock while the winner failed must not
     * decode the same bytes again once it gets the lock.
     */
    public function aWaiterSeesTheWinnersFailureUnderTheLock(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $cache = new MemorySimpleCache();
        $winner = $this->derivatives(
            processor: Doubles::failingProcessor(new ProcessingFailedException('broken')),
            failures: $cache,
        );
        $this->mutexes = Doubles::mutexesThatRunFirst(static function () use ($winner, $file): bool {
            try {
                $winner->ensure($file, 'thumb');
            } catch (ProcessingFailedException) {
            }

            return true;
        });

        Expect::exception(ProcessingFailedException::class)->withMessageContaining('broken');

        try {
            $this->derivatives(failures: $cache)->ensure($file, 'thumb');
        } finally {
            verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), never: true);
        }
    }

    /**
     * A waiter that gives up on the lock reports the winner's failure, not a
     * lock timeout that sends an operator looking at the mutex backend.
     */
    public function aWaiterThatLostTheLockReportsTheWinnersFailure(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $cache = new MemorySimpleCache();
        $winner = $this->derivatives(
            processor: Doubles::failingProcessor(new ProcessingFailedException('broken')),
            failures: $cache,
        );
        $this->mutexes = Doubles::mutexesThatRunFirst(static function () use ($winner, $file): bool {
            try {
                $winner->ensure($file, 'thumb');
            } catch (ProcessingFailedException) {
            }

            return false;
        });

        Expect::exception(ProcessingFailedException::class)->withMessageContaining('broken');

        $this->derivatives(failures: $cache)->ensure($file, 'thumb');
    }

    /**
     * Only verdicts about the bytes are remembered. A missing original or a
     * store error may be gone a second later; caching it would turn a blip
     * into a TTL of 404s for every thumbnail.
     */
    public function aMissingOriginalIsNotRemembered(): void
    {
        $file = Fixtures::file();
        $cache = new MemorySimpleCache();
        $derivatives = $this->derivatives(failures: $cache);

        try {
            $derivatives->ensure($file, 'thumb');
            Assert::fail('the original is missing');
        } catch (DerivativeGenerationException) {
        }

        $this->store->setObject($file->relativePath, 'original bytes');

        Assert::true($derivatives->ensure($file, 'thumb'));
    }

    /**
     * The entry names the original's version: a re-upload under the same
     * path (a new `updatedAt`) is tried at once, not after the TTL.
     */
    public function aReplacedOriginalIsTriedAgainAtOnce(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $cache = new MemorySimpleCache();

        try {
            $this->derivatives(
                processor: Doubles::failingProcessor(new ProcessingFailedException('broken')),
                failures: $cache,
            )->ensure($file, 'thumb');
        } catch (ProcessingFailedException) {
        }

        $replaced = File::create(
            id: $file->id,
            storeName: $file->storeName,
            groupName: $file->groupName,
            relativePath: $file->relativePath,
            originalName: $file->originalName,
            size: $file->size,
            createdAt: $file->createdAt,
            mimeType: $file->mimeType,
            updatedAt: $file->createdAt->modify('+1 minute'),
        );

        Assert::true($this->derivatives(failures: $cache)->ensure($replaced, 'thumb'));
    }

    /**
     * The entry carries the configured TTL — remembered, not forever.
     */
    public function aRememberedFailureCarriesTheTtl(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $cache = new SimpleCacheActionLogger(new MemorySimpleCache());

        try {
            $this->derivatives(
                processor: Doubles::failingProcessor(new ProcessingFailedException('broken')),
                failures: $cache,
                failureTtl: new DateInterval('PT2M'),
            )->ensure($file, 'thumb');
        } catch (ProcessingFailedException) {
        }

        $sets = array_values(array_filter(
            $cache->getActions(),
            static fn(Action $action): bool => $action->getAction() === Action::SET,
        ));
        Assert::same(\count($sets), 1);
        Assert::instanceOf($sets[0]->getTtl(), DateInterval::class);
        Assert::same($sets[0]->getTtl()->i, 2);
        Assert::string((string) $sets[0]->getKey())->notContains(':');
        // PSR-16 guarantees keys of up to 64 characters from A-Z a-z 0-9 _ .
        // (review 1.0 pass 2, N15).
        Assert::true(
            preg_match('/^[A-Za-z0-9_.]{1,64}$/D', (string) $sets[0]->getKey()) === 1,
            'not a portable PSR-16 key: ' . $sets[0]->getKey(),
        );
    }

    /**
     * The web path and `warm` wait differently: a request should not hold a
     * worker for the batch job's patience.
     */
    public function ensureCanWaitLongerThanTheFacadesDefault(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $derivatives = $this->derivatives();

        $derivatives->ensure($file, 'thumb', lockTimeoutSeconds: 120);

        $waits = Arg::captor();
        verify(fn() => $this->mutex->acquire($waits->capture()), times: 1);
        Assert::same($waits->last(), 120);
    }

    /**
     * Review 1.0 #12. The proxy route generates on its own first request, so
     * minting one must not generate synchronously: a page of N images with M
     * presets rendered N×M renditions before its first byte.
     */
    public function theProxyUrlIsMintedWithoutGenerating(): void
    {
        $proxy = new SignedProxyUrlGenerator();
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');

        $url = $this->derivatives(proxyUrls: $proxy)->urlFor($file, 'thumb');

        Assert::string((string) $url)->contains("https://app.example.test/files/{$file->id}/thumb/");
        verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), never: true);
        Assert::false($this->derivatives()->exists($file, 'thumb'));
    }

    /**
     * Review 1.0 pass 2, N10: a URL minted outside the tenant's request (a
     * job, a mail) needs a generator pinned to that tenant — `-web`'s
     * `HmacProxyUrlGenerator::forScope()`. `withProxyUrls()` hands it to
     * `urlFor()` (and so to `Srcset`) without touching the shared facade.
     */
    public function withProxyUrlsMintsThroughTheGivenGenerator(): void
    {
        $shared = new SignedProxyUrlGenerator();
        $pinned = new SignedProxyUrlGenerator();
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $derivatives = $this->derivatives(proxyUrls: $shared);

        $url = $derivatives->withProxyUrls($pinned)->urlFor($file, 'thumb');

        Assert::notNull($url);
        Assert::same($pinned->lastVariant, 'thumb');
        Assert::null($shared->lastVariant);

        $derivatives->urlFor($file, 'thumb');
        Assert::same($shared->lastVariant, 'thumb');
    }

    /**
     * What can never be generated still throws instead of minting a URL
     * that 404s: the cheap refusals run on the proxy path too.
     */
    public function theProxyPathStillRefusesWhatCanNeverBeGenerated(): void
    {
        $this->processor = Doubles::processor(supports: false);

        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('No available processor');

        $this->derivatives(proxyUrls: new SignedProxyUrlGenerator())->urlFor(Fixtures::file(), 'thumb');
    }

    /**
     * A remembered failure is a refusal too.
     */
    public function theProxyPathReportsARememberedFailure(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $cache = new MemorySimpleCache();

        try {
            $this->derivatives(
                processor: Doubles::failingProcessor(new ProcessingFailedException('broken')),
                failures: $cache,
            )->ensure($file, 'thumb');
        } catch (ProcessingFailedException) {
        }

        Expect::exception(ProcessingFailedException::class);

        $this->derivatives(proxyUrls: new SignedProxyUrlGenerator(), failures: $cache)->urlFor($file, 'thumb');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedEntryProvider(): iterable
    {
        yield 'not an array' => ['unsupported'];
        yield 'kind is not a string' => [['kind' => 1, 'message' => 'broken']];
        yield 'message is not a string' => [['kind' => 'failed', 'message' => 1]];
    }

    /**
     * Whatever else shares the cache, an entry that is not one this facade
     * wrote is a miss: the rendition is generated, not refused.
     */
    #[DataProvider('malformedEntryProvider')]
    public function aMalformedCacheEntryIsAMiss(mixed $entry): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $cache = Understudy::for(CacheInterface::class);
        when(fn() => $cache->get(Arg::any(), Arg::any()))->returns($entry);

        Assert::true($this->derivatives(failures: $cache)->ensure($file, 'thumb'));
    }

    private function brokenCache(): CacheInterface
    {
        $cache = Understudy::for(CacheInterface::class);
        when(fn() => $cache->get(Arg::any(), Arg::any()))->throws(new \RuntimeException('cache is down'));
        when(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()))->throws(new \RuntimeException('cache is down'));

        return $cache;
    }

    /**
     * An existing rendition is served whatever this installation could
     * generate today: no processor is consulted, no lock taken.
     */
    public function anExistingRenditionIsServedWithoutAProcessorForIt(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $this->processor = Doubles::processor(supports: false);
        $derivatives = $this->derivatives();
        $this->store->putDerivative($file, $derivatives->descriptor('thumb'), 'generated earlier');

        Assert::false($derivatives->ensure($file, 'thumb'));
        verify(fn() => $this->mutexes->create(Arg::any()), never: true);
    }

    /**
     * A store-native URL points at an object, it does not generate one: the
     * rendition exists before the URL is handed out.
     */
    public function aStoreUrlIsMintedForAGeneratedRendition(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $derivatives = $this->derivatives(stores: new StoreRegistry([new DerivativeUrlMemoryStore($this->store)]));

        Assert::string((string) $derivatives->urlFor($file, 'thumb'))->contains('signed.example.test');
        Assert::true($derivatives->exists($file, 'thumb'));
    }

    /**
     * The documented key: `fsimg.failed.` and 48 hex of the sha256 over the
     * store, the rendition path, the original's `updatedAt` and its content
     * hash — an operator can compute it to clear one entry.
     */
    public function theFailureKeyNamesTheRenditionAndTheOriginalsVersion(): void
    {
        $file = File::create(
            id: 'file-1',
            storeName: 'memory',
            groupName: 'common',
            relativePath: 'common/ab/cd/file-1/original.png',
            originalName: 'photo.png',
            size: 1024,
            createdAt: Fixtures::now(),
            mimeType: 'image/png',
            contentHash: self::SHA,
        );
        $this->store->setObject($file->relativePath, 'original bytes');
        $cache = new SimpleCacheActionLogger(new MemorySimpleCache());
        $derivatives = $this->derivatives(
            processor: Doubles::failingProcessor(new ProcessingFailedException('broken')),
            failures: $cache,
        );

        try {
            $derivatives->ensure($file, 'thumb');
        } catch (ProcessingFailedException) {
        }

        $sets = array_values(array_filter(
            $cache->getActions(),
            static fn(Action $action): bool => $action->getAction() === Action::SET,
        ));
        $expected = 'fsimg.failed.' . substr(hash('sha256', implode("\0", [
            'memory',
            $this->store->layout()->derivativePath($file, $derivatives->descriptor('thumb')),
            $file->updatedAt->format('U.u'),
            self::SHA,
        ])), 0, 48);
        Assert::same(\count($sets), 1);
        Assert::same($sets[0]->getKey(), $expected);
    }

    private function derivatives(
        ?StoreRegistry $stores = null,
        ?DeliveryPolicyRegistry $deliveryPolicies = null,
        ?ImageProcessorInterface $processor = null,
        int $maxSourceBytes = 67_108_864,
        ?ProxyUrlGeneratorInterface $proxyUrls = null,
        ?CacheInterface $failures = null,
        DateInterval $failureTtl = new DateInterval('PT5M'),
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
            failures: $failures,
            failureTtl: $failureTtl,
        );
    }
}
