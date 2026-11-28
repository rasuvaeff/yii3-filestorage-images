<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages;

use DateInterval;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3Filestorage\Exception\SizeLimitExceededException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
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
 * The generation lock is keyed by the *physical* object the rendition is
 * written to — the store's name plus the path its layout names
 * ({@see \Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface::derivativePath()}),
 * not by logical file id: under deduplication many `File` rows share one
 * stored object, and two of them generating `thumb-v1` concurrently would race
 * on the same physical path. A consequence the caller accepts by using
 * blob-scoped derivatives: a rendition must be a pure function of the
 * original bytes and the preset version. Per-owner watermarks or redaction do
 * not belong beside a shared object.
 *
 * A failed generation is remembered when a PSR-16 cache is given: only the
 * verdicts about the bytes (a guard refusal, a decode failure, output over
 * the preset's cap), keyed by the physical rendition and the original's
 * version, for `failureTtl`. Without it a source that always fails was read
 * and decoded again on every request, each one queueing behind the lock — a
 * DoS amplifier (review 1.0 #10). A missing original, a store error or a
 * transient processing failure (a stalled stream, ImageMagick out of
 * resources — {@see ProcessingFailedException::isTransient()}) is never
 * remembered: it may be gone a second later. A cache that throws is treated
 * as no cache — a miss on read, nothing on write.
 *
 * @api
 */
final readonly class Derivatives implements DerivativeResolverInterface
{
    /**
     * @param int<0, max> $lockTimeoutSeconds How long a second requester waits
     *        for the first one's generation before giving up — a request
     *        holds a worker for that long, so keep it short; `ensure()` takes
     *        a longer wait for batch work (`filestorage:warm`).
     * @param int<0, max> $maxSourceBytes Refuse to process originals over this
     *        size; zero means no cap. Checked against the stored row's size —
     *        the processors re-enforce their own byte cap while reading, so a
     *        row that lies about its size does not bypass the guard.
     * @param ProxyUrlGeneratorInterface|null $proxyUrls The signed proxy route
     *        through the application — bound by installing
     *        `rasuvaeff/yii3-filestorage-web` — and the last resort of
     *        {@see urlFor()} when the store has no URL capability of its own,
     *        exactly as core's `Storage::urlFor()` treats it.
     * @param CacheInterface|null $failures Where failed generations are
     *        remembered; null remembers nothing.
     * @param DateInterval $failureTtl How long a remembered failure stands
     *        before the next request tries again.
     */
    public function __construct(
        private StoreRegistry $stores,
        private PresetRegistry $presets,
        private ImageProcessorInterface $processor,
        private MutexFactoryInterface $mutexes,
        private DeliveryPolicyRegistry $deliveryPolicies,
        private ClockInterface $clock,
        private DateInterval $defaultUrlTtl = new DateInterval('PT1H'),
        private int $lockTimeoutSeconds = 10,
        private int $maxSourceBytes = 67_108_864,
        private ?ProxyUrlGeneratorInterface $proxyUrls = null,
        private ?CacheInterface $failures = null,
        private DateInterval $failureTtl = new DateInterval('PT5M'),
    ) {}

    /**
     * The same facade minting proxy URLs through another generator. The
     * shared one reads the tenant from the current request; a URL minted
     * outside it — a queue job, a mail — needs one pinned to the tenant the
     * file belongs to, e.g. `-web`'s `HmacProxyUrlGenerator::forScope()`
     * (review 1.0 pass 2, N10). `Srcset::for()` over the returned facade
     * mints its candidates the same way.
     */
    public function withProxyUrls(?ProxyUrlGeneratorInterface $proxyUrls): self
    {
        return new self(
            stores: $this->stores,
            presets: $this->presets,
            processor: $this->processor,
            mutexes: $this->mutexes,
            deliveryPolicies: $this->deliveryPolicies,
            clock: $this->clock,
            defaultUrlTtl: $this->defaultUrlTtl,
            lockTimeoutSeconds: $this->lockTimeoutSeconds,
            maxSourceBytes: $this->maxSourceBytes,
            proxyUrls: $proxyUrls,
            failures: $this->failures,
            failureTtl: $this->failureTtl,
        );
    }

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
     * @param int<0, max>|null $lockTimeoutSeconds Overrides the facade's wait
     *        for another requester's generation — batch work can afford more.
     *
     * @return bool True when this call generated the object, false when it
     *         already existed — `filestorage:warm` reports the difference.
     *
     * @throws UnknownPresetException
     * @throws UnsupportedSourceException When this installation cannot process
     *         the source into the preset's format, or the source is over a guard.
     * @throws ProcessingFailedException When the source bytes do not decode.
     * @throws DerivativeGenerationException When the store cannot hold
     *         derivatives, the original's bytes are gone, the lock could not be
     *         acquired and nobody else generated the object either, or the
     *         output was over the preset's cap (remembered).
     */
    public function ensure(File $file, string $presetName, ?int $lockTimeoutSeconds = null): bool
    {
        $descriptor = $this->presets->get($presetName)->descriptor();
        if ($this->derivativeStore($file)->hasDerivative($file, $descriptor)) {
            // An existing rendition is served whatever this installation
            // could generate today.
            return false;
        }

        [$preset, , $store, $sourceMediaType] = $this->preflight($file, $presetName);

        $failureKey = $this->failureKey($store, $file, $descriptor);
        $this->rethrowRemembered($failureKey);

        $wait = $lockTimeoutSeconds ?? $this->lockTimeoutSeconds;
        $mutex = $this->mutexes->create($this->lockName($store, $file, $descriptor));
        if (!$mutex->acquire($wait)) {
            // Losing the lock usually means winning the race: whoever held it
            // was generating this very object. One last probe tells a timeout
            // apart from a success someone else paid for — or a failure they
            // already found.
            if ($store->hasDerivative($file, $descriptor)) {
                return false;
            }
            $this->rethrowRemembered($failureKey);

            throw new DerivativeGenerationException(
                "Could not acquire the generation lock for \"{$descriptor->fileName()}\""
                . " of file \"{$file->id}\" within {$wait}s",
            );
        }

        try {
            // Re-checked under the lock: the winner of the race generated it
            // — or failed at it — while this caller was waiting.
            if ($store->hasDerivative($file, $descriptor)) {
                return false;
            }
            $this->rethrowRemembered($failureKey);

            $source = $store->stream($file)
                ?? throw new DerivativeGenerationException(
                    "Original bytes of file \"{$file->id}\" are missing from store \"{$file->storeName}\"",
                );

            try {
                $processed = $this->processor->process($source, $sourceMediaType, $preset);
                $store->writeDerivative($file, $descriptor, $processed->stream, $preset->maxBytes);
            } catch (UnsupportedSourceException|ProcessingFailedException|SizeLimitExceededException $failure) {
                $this->remember($failureKey, $failure);

                throw $failure;
            }

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
        // What can never be generated is refused here as well, rather than
        // minted into a URL that 404s.
        [, $descriptor, $store] = $this->preflight($file, $presetName);
        $this->rethrowRemembered($this->failureKey($store, $file, $descriptor));

        $expires = $expiresAt ?? $this->clock->now()->add($this->defaultUrlTtl);

        if ($store instanceof DerivativeUrlProviderInterface) {
            // Generated before minting a store URL, not after a 404: a CDN-
            // backed public prefix or a presigned URL generates nothing on
            // read. The proxy below does, on its own first request — so it is
            // minted without generating (review 1.0 #12: a page of N images
            // and M presets rendered N×M renditions before its first byte).
            $this->ensure($file, $presetName);

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
     * The refusals that need no lock and no read: unknown preset, a store
     * without derivatives, no media type, no processor for it, over the byte
     * cap. A PDF in a raster-only installation should not queue behind someone
     * else's generation to find out it was never going to work.
     *
     * @param non-empty-string $presetName
     *
     * @return array{Preset, DerivativeDescriptor, DerivativeAwareStoreInterface, non-empty-string}
     *
     * @throws UnknownPresetException
     * @throws UnsupportedSourceException
     * @throws DerivativeGenerationException
     */
    private function preflight(File $file, string $presetName): array
    {
        $preset = $this->presets->get($presetName);
        $store = $this->derivativeStore($file);

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

        return [$preset, $preset->descriptor(), $store, $sourceMediaType];
    }

    /**
     * The physical rendition plus the original's version: a re-upload under
     * the same path is tried at once, not after the TTL. Hex only — PSR-16
     * reserves `{}()/\@:`.
     *
     * @return non-empty-string
     */
    private function failureKey(
        DerivativeAwareStoreInterface $store,
        File $file,
        DerivativeDescriptor $descriptor,
    ): string {
        // PSR-16 guarantees keys of up to 64 characters from A-Z a-z 0-9 _ .
        // only (review 1.0 pass 2, N15): a 13-character prefix and 192 bits
        // of the hash.
        return 'fsimg.failed.' . substr(hash('sha256', implode("\0", [
            $file->storeName,
            $store->layout()->derivativePath($file, $descriptor),
            $file->updatedAt->format('U.u'),
            $file->contentHash ?? '',
        ])), 0, 48);
    }

    /**
     * @throws UnsupportedSourceException
     * @throws ProcessingFailedException
     * @throws DerivativeGenerationException
     */
    private function rethrowRemembered(string $key): void
    {
        if (!$this->failures instanceof CacheInterface) {
            return;
        }

        try {
            $entry = $this->failures->get($key);
        } catch (\Throwable) {
            // The cache is optional: one that is down (Redis gone) is a miss,
            // never the reason a rendition is not served (review 1.0 pass 2,
            // N7).
            return;
        }
        if (!\is_array($entry) || !\is_string($entry['kind'] ?? null) || !\is_string($entry['message'] ?? null)) {
            return;
        }

        $message = "{$entry['message']} (a remembered failure; not retried before it expires)";

        throw match ($entry['kind']) {
            'unsupported' => new UnsupportedSourceException($message),
            'failed' => new ProcessingFailedException($message),
            default => new DerivativeGenerationException($message),
        };
    }

    private function remember(
        string $key,
        UnsupportedSourceException|ProcessingFailedException|SizeLimitExceededException $failure,
    ): void {
        if (!$this->failures instanceof CacheInterface
            || ($failure instanceof ProcessingFailedException && $failure->isTransient())
        ) {
            return;
        }

        try {
            $this->failures->set($key, [
                'kind' => match (true) {
                    $failure instanceof UnsupportedSourceException => 'unsupported',
                    $failure instanceof ProcessingFailedException => 'failed',
                    default => 'size',
                },
                'message' => $failure->getMessage(),
            ], $this->failureTtl);
        } catch (\Throwable) {
            // A cache that cannot be written must not replace the
            // generation's own failure, which the caller is about to get.
        }
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
    private function lockName(
        DerivativeAwareStoreInterface $store,
        File $file,
        DerivativeDescriptor $descriptor,
    ): string {
        // The physical object, as the store's layout names it — not the
        // original's path plus a filename: under a flat layout two rows can
        // name one derivative object, and they must share one lock. Hashed
        // only to bound the name for mutex drivers with key-length limits; the
        // `filestorage-images:` prefix keeps the key from colliding with
        // anything else sharing the mutex backend.
        return 'filestorage-images:' . hash(
            'sha256',
            $file->storeName . ':' . $store->layout()->derivativePath($file, $descriptor),
        );
    }
}
