<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages;

use DateInterval;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeAwareStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3FilestorageImages\Exception\DerivativeGenerationException;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnknownPresetException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImageProcessorInterface;
use Yiisoft\Mutex\MutexFactoryInterface;

/**
 * The facade: lazily generates and hands out named renditions of stored files.
 *
 * Lazy with cache is the default strategy — the first request for a variant
 * generates it once, stores it beside the original, and every later request is
 * a plain read. New presets therefore work retroactively with no backfill;
 * storage grows only with what is actually requested. `filestorage:warm`
 * drives {@see ensure()} over existing files when eager generation is wanted —
 * same locked path, no second code path to diverge.
 *
 * The generation lock is keyed by *physical* blob identity plus the versioned
 * derivative name, not by logical file id: under deduplication many `File`
 * rows share one object directory, and two of them generating `thumb-v1`
 * concurrently would race on the same physical path. A consequence the caller
 * accepts by using blob-scoped derivatives: a rendition must be a pure
 * function of the original bytes and the preset version. Per-owner watermarks
 * or redaction do not belong in a shared directory.
 *
 * @api
 */
final readonly class Derivatives implements DerivativeResolverInterface
{
    /**
     * @param int<0, max> $lockTimeoutSeconds How long a second requester waits
     *        for the first one's generation before giving up.
     * @param int<0, max> $maxSourceBytes Refuse to process originals over this
     *        size; zero means no cap. Checked against the stored row's size —
     *        the processors re-enforce their own byte cap while reading, so a
     *        row that lies about its size does not bypass the guard.
     * @param ProxyUrlGeneratorInterface|null $proxyUrls The signed proxy route
     *        through the application — bound by installing
     *        `rasuvaeff/yii3-filestorage-web` — and the last resort of
     *        {@see urlFor()} when the store has no URL capability of its own,
     *        exactly as core's `Storage::urlFor()` treats it.
     */
    public function __construct(
        private StoreRegistry $stores,
        private PresetRegistry $presets,
        private ImageProcessorInterface $processor,
        private MutexFactoryInterface $mutexes,
        private DeliveryPolicyRegistry $deliveryPolicies,
        private ClockInterface $clock,
        private DateInterval $defaultUrlTtl = new DateInterval('PT1H'),
        private int $lockTimeoutSeconds = 30,
        private int $maxSourceBytes = 67_108_864,
        private ?ProxyUrlGeneratorInterface $proxyUrls = null,
    ) {}

    /**
     * The physical identity a preset name resolves to. What `-web` and the
     * stores address a rendition by; application code rarely needs it.
     *
     * @param non-empty-string $presetName
     *
     * @throws UnknownPresetException
     */
    #[Override]
    public function descriptor(string $presetName): DerivativeDescriptor
    {
        return $this->presets->get($presetName)->descriptor();
    }

    /**
     * Whether the rendition already exists — a pure probe, never a generator.
     *
     * @param non-empty-string $presetName
     *
     * @throws UnknownPresetException
     */
    public function exists(File $file, string $presetName): bool
    {
        $store = $this->stores->get($file->storeName);
        $descriptor = $this->presets->get($presetName)->descriptor();

        return $store instanceof DerivativeAwareStoreInterface
            && $store->hasDerivative($file, $descriptor);
    }

    /**
     * Guarantees the rendition exists, generating it under the stampede lock
     * if it does not.
     *
     * @param non-empty-string $presetName
     *
     * @return bool True when this call generated the object, false when it
     *         already existed — `filestorage:warm` reports the difference.
     *
     * @throws UnknownPresetException
     * @throws UnsupportedSourceException When this installation cannot process
     *         the source into the preset's format, or the source is over a guard.
     * @throws ProcessingFailedException When the source bytes do not decode.
     * @throws DerivativeGenerationException When the store cannot hold
     *         derivatives, the original's bytes are gone, or the lock could
     *         not be acquired and nobody else generated the object either.
     */
    public function ensure(File $file, string $presetName): bool
    {
        $preset = $this->presets->get($presetName);
        $descriptor = $preset->descriptor();
        $store = $this->derivativeStore($file);

        if ($store->hasDerivative($file, $descriptor)) {
            return false;
        }

        // The cheap refusals run before the lock: a PDF in a raster-only
        // installation should not queue behind someone else's generation to
        // find out it was never going to work.
        $sourceMediaType = $file->mimeType
            ?? throw new UnsupportedSourceException(
                "File \"{$file->id}\" has no detected media type to process from",
            );
        if (!$this->processor->supports($sourceMediaType, $preset->format)) {
            throw new UnsupportedSourceException(
                "No available processor turns \"{$sourceMediaType}\" into \"{$preset->format->value}\"",
            );
        }
        if ($this->maxSourceBytes > 0 && $file->size > $this->maxSourceBytes) {
            throw new UnsupportedSourceException(
                "File \"{$file->id}\" is {$file->size} bytes, over the {$this->maxSourceBytes}-byte processing cap",
            );
        }

        $mutex = $this->mutexes->create($this->lockName($file, $descriptor));
        if (!$mutex->acquire($this->lockTimeoutSeconds)) {
            // Losing the lock usually means winning the race: whoever held it
            // was generating this very object. One last probe tells a timeout
            // apart from a success someone else paid for.
            if ($store->hasDerivative($file, $descriptor)) {
                return false;
            }

            throw new DerivativeGenerationException(
                "Could not acquire the generation lock for \"{$descriptor->fileName()}\""
                . " of file \"{$file->id}\" within {$this->lockTimeoutSeconds}s",
            );
        }

        try {
            // Re-checked under the lock: the winner of the race generated it
            // while this caller was waiting.
            if ($store->hasDerivative($file, $descriptor)) {
                return false;
            }

            $source = $store->stream($file)
                ?? throw new DerivativeGenerationException(
                    "Original bytes of file \"{$file->id}\" are missing from store \"{$file->storeName}\"",
                );

            $processed = $this->processor->process($source, $sourceMediaType, $preset);
            $store->writeDerivative($file, $descriptor, $processed->stream, $preset->maxBytes);

            return true;
        } finally {
            $mutex->release();
        }
    }

    /**
     * The rendition's bytes, generating it first if needed.
     *
     * @param non-empty-string $presetName
     *
     * @return StreamInterface|null Null only when the object vanished between
     *         generation and read — the caller treats it like any missing file.
     *
     * @throws UnknownPresetException
     * @throws UnsupportedSourceException
     * @throws ProcessingFailedException
     * @throws DerivativeGenerationException
     */
    #[Override]
    public function stream(File $file, string $presetName): ?StreamInterface
    {
        $this->ensure($file, $presetName);

        return $this->derivativeStore($file)->derivativeStream(
            $file,
            $this->presets->get($presetName)->descriptor(),
        );
    }

    /**
     * A URL for the rendition, policy-gated exactly like `Storage::urlFor()`
     * for originals: a permanent public URL only when the group's delivery
     * policy allows direct public delivery, then a store-native expiring URL,
     * and — as the last resort — the application's signed proxy route,
     * {@see DerivativeResolverInterface} makes this facade consumable by
     * `-web` directly.
     *
     * @param non-empty-string $presetName
     *
     * @return non-empty-string|null
     *
     * @throws UnknownPresetException
     * @throws UnsupportedSourceException
     * @throws ProcessingFailedException
     * @throws DerivativeGenerationException
     */
    public function urlFor(File $file, string $presetName, ?DateTimeImmutable $expiresAt = null): ?string
    {
        // Generated before minting, not after a 404: a URL to an object that
        // does not exist yet is only useful if something generates on read,
        // and a CDN-backed public prefix does not.
        $this->ensure($file, $presetName);

        $expires = $expiresAt ?? $this->clock->now()->add($this->defaultUrlTtl);
        $store = $this->derivativeStore($file);

        if ($store instanceof DerivativeUrlProviderInterface) {
            $descriptor = $this->presets->get($presetName)->descriptor();

            if ($this->deliveryPolicies->for($file->groupName)->allowDirectPublicUrl) {
                $public = $store->publicDerivativeUrl($file, $descriptor);
                if ($public !== null) {
                    return $public;
                }
            }

            $temporary = $store->temporaryDerivativeUrl($file, $descriptor, $expires);
            if ($temporary !== null) {
                return $temporary;
            }
        }

        // The store has nothing to offer: the signed proxy route through the
        // application is the last resort, exactly as core's `Storage::urlFor()`
        // treats it for originals. The variant travels inside the HMAC, so a
        // thumbnail URL cannot be replayed for the full-resolution file.
        return $this->proxyUrls?->variantUrl($file, $presetName, $expires);
    }

    /**
     * @throws DerivativeGenerationException
     */
    private function derivativeStore(File $file): DerivativeAwareStoreInterface
    {
        $store = $this->stores->get($file->storeName);
        if (!$store instanceof DerivativeAwareStoreInterface) {
            throw new DerivativeGenerationException(
                "Store \"{$file->storeName}\" cannot hold derivatives — it does not implement "
                . DerivativeAwareStoreInterface::class,
            );
        }

        return $store;
    }

    /**
     * @return non-empty-string
     */
    private function lockName(File $file, DerivativeDescriptor $descriptor): string
    {
        // Physical identity, hashed only to bound the name for mutex drivers
        // with key-length limits. The `filestorage-images:` prefix keeps the
        // key from colliding with anything else sharing the mutex backend.
        return 'filestorage-images:' . hash(
            'sha256',
            BlobId::create($file->storeName, $file->relativePath)->key() . '/' . $descriptor->fileName(),
        );
    }
}
