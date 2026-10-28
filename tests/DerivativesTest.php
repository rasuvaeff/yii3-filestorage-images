<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use DateInterval;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
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
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Mutex\MutexFactoryInterface;
use Yiisoft\Mutex\MutexInterface;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\verify;

#[Test]
#[Covers(Derivatives::class)]
final class DerivativesTest
{
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
        // The exact physical identity: prefix + sha256(blob key + '/' + the
        // versioned derivative filename), so deduplicated rows share a lock
        // and two presets never collide on one key.
        Assert::same(
            $names->last(),
            'filestorage-images:' . \hash(
                'sha256',
                \Rasuvaeff\Yii3Filestorage\Store\BlobId::create($file->storeName, $file->relativePath)->key()
                    . '/thumb-v1.webp',
            ),
        );
        verify(fn() => $this->mutex->release(), times: 1);

        // Under dedup a second logical row shares the physical path — and
        // therefore the rendition and the lock name, keyed by BlobId.
        $twin = Fixtures::file(id: 'file-2', relativePath: $file->relativePath);
        Assert::true($this->store->hasDerivative($twin, $derivatives->descriptor('thumb')));
        $derivatives->ensure($twin, 'thumb');
        verify(fn() => $this->processor->process(Arg::any(), Arg::any(), Arg::any()), times: 1);
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
    ): Derivatives {
        return new Derivatives(
            stores: $stores ?? new StoreRegistry([$this->store]),
            presets: new PresetRegistry([new Preset(name: 'thumb', format: Format::Webp, width: 100)]),
            processor: $processor ?? $this->processor,
            mutexes: $this->mutexes,
            deliveryPolicies: $deliveryPolicies ?? new DeliveryPolicyRegistry(),
            clock: new StaticClock(Fixtures::now()),
            maxSourceBytes: $maxSourceBytes,
        );
    }
}
