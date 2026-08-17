<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use DateInterval;
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
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\PredictableMutexFactory;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\RecordingProcessor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(Derivatives::class)]
final class DerivativesTest
{
    private DerivativeAwareMemoryStore $store;
    private RecordingProcessor $processor;
    private PredictableMutexFactory $mutexes;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->store = new DerivativeAwareMemoryStore();
        $this->processor = new RecordingProcessor();
        $this->mutexes = new PredictableMutexFactory();
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
        Assert::same(\count($this->processor->calls), 1);
        Assert::same(\count($this->store->derivativeWrites), 1);
        Assert::same($this->store->derivativeBytes($file, $derivatives->descriptor('thumb')), 'processed:thumb-v1');
    }

    public function generationRunsUnderTheBlobScopedLockAndReleasesIt(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $derivatives = $this->derivatives();

        $derivatives->ensure($file, 'thumb');

        Assert::same(\count($this->mutexes->created), 1);
        Assert::string($this->mutexes->created[0])->contains('filestorage-images:');
        Assert::same($this->mutexes->released, 1);

        // Under dedup a second logical row shares the physical path — and
        // therefore the rendition and the lock name, keyed by BlobId.
        $twin = Fixtures::file(id: 'file-2', relativePath: $file->relativePath);
        Assert::true($this->store->hasDerivative($twin, $derivatives->descriptor('thumb')));
        $derivatives->ensure($twin, 'thumb');
        Assert::same(\count($this->processor->calls), 1);
    }

    public function deniedLockFallsBackToAnObjectSomeoneElseGenerated(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $this->mutexes = new PredictableMutexFactory(acquirable: false);
        $derivatives = $this->derivatives();

        // The winner's output appears between the first probe and the lock.
        $this->store->putDerivative($file, $derivatives->descriptor('thumb'), 'their bytes');

        Assert::false($derivatives->ensure($file, 'thumb'));
        Assert::same($this->processor->calls, []);
    }

    public function deniedLockWithNothingGeneratedIsAnError(): void
    {
        $file = Fixtures::file();
        $this->store->setObject($file->relativePath, 'original bytes');
        $this->mutexes = new PredictableMutexFactory(acquirable: false);

        Expect::exception(DerivativeGenerationException::class)
            ->withMessageContaining('Could not acquire the generation lock');

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

        Expect::exception(DerivativeGenerationException::class)->withMessageContaining('cannot hold derivatives');

        $this->derivatives(stores: $stores)->ensure(Fixtures::file(), 'thumb');
    }

    public function refusesAFileWithoutADetectedMediaType(): void
    {
        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('no detected media type');

        $this->derivatives()->ensure(Fixtures::file(mimeType: null), 'thumb');
    }

    public function refusesACombinationNoProcessorSupports(): void
    {
        $this->processor = new RecordingProcessor(supportsEverything: false);

        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('No available processor');

        $this->derivatives()->ensure(Fixtures::file(), 'thumb');
    }

    public function refusesASourceOverTheByteCap(): void
    {
        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('processing cap');

        $this->derivatives(maxSourceBytes: 512)->ensure(Fixtures::file(size: 513), 'thumb');
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
