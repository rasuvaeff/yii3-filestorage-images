---
name: rasuvaeff-yii3-filestorage-images
description: >-
  Named-preset image derivatives for Yii3 Filestorage with
  rasuvaeff/yii3-filestorage-images — the Derivatives facade
  (ensure/stream/urlFor/exists/descriptor), Preset/PresetRegistry with
  versioned recipes, Format and Fit enums, GD/Imagick/composite image
  processors with decompression-bomb guards, the EnshrinedSvgSanitizer, and
  the filestorage:warm console command. Use when writing, reviewing or
  debugging thumbnail/preview/derivative or SVG-upload code in a project that
  has this package installed.
---

# rasuvaeff/yii3-filestorage-images

Named-preset derivatives over the `yii3-filestorage` derivative-object
capability. Namespace `Rasuvaeff\Yii3FilestorageImages\`. Full API reference:
`llms.txt`.

## Safety rules — verify these on every change

1. **Named presets only.** A variant is addressed by a whitelisted name
   (`thumb`), never by dimensions from the request. Free-form `w=&h=`
   parameters — even signed — make the derivative set unbounded. Unknown
   preset → `UnknownPresetException` → 404.

2. **Version is identity.** `thumb-v2.webp` — changing any preset option
   without bumping `version` silently serves objects generated with the old
   recipe. Existing derivatives are never regenerated.

3. **Guards precede every decode.** `maxSourceBytes` bounds the read;
   `maxSourcePixels` is checked against the *header* before the decoder
   allocates. A 200-byte PNG may claim 100000×100000 pixels. Under Imagick a
   GIF header reports the logical screen, not the frame: ping frame `[0]`
   (filename selector — `readImageBlob()`'s second argument ignores it), check
   frame and canvas, force the coder from the detected media type, decode only
   `[0]`, and narrow IM's resource limits only for the read.

4. **Blob-scoped purity.** Under dedup, renditions live beside the shared
   stored object, where the store's layout puts them (`DirectoryLayout`:
   `<key>/thumb-v1.webp`; `FlatSiblingLayout`: `<dir>/<sha>-thumb-v1.webp`),
   and the generation lock is keyed by the store name + that layout path. A
   derivative must be a pure function of original bytes + preset version —
   no per-owner watermarks or redaction through this package.

5. **SVG is a document, not a raster.** Processors refuse it; the sanitizer
   (library pass + `foreignObject`/`animate*`/`set`/`mpath` post-pass + byte
   and element caps) throws instead of best-effort. Sanitized SVG still ships
   as attachment unless served from a cookie-less asset origin.

## Wiring the application owns

- `Yiisoft\Mutex\MutexFactoryInterface` — the generation-lock driver.
- The store must implement `DerivativeAwareStoreInterface`
  (core `FileSystemStore`/`PublicFileSystemStore` qualify; `FlysystemStore`
  does not — every rendition on S3 fails, `-web` answers 404);
  URL results additionally need `DerivativeUrlProviderInterface`.
- `filestorage:warm` needs `MaintenanceRepositoryInterface` (the `-db`
  repository).
- Optional `Psr\SimpleCache\CacheInterface` — remembers failed generations
  for `failureTtl`; without it a source that always fails is decoded again on
  every request. Never remember a missing original, a store error or a
  transient `ProcessingFailedException` (`isTransient()`: stalled stream,
  spool, ImageMagick resource/cache errors). A cache that throws is no
  cache — never a 500.

## Serving

`urlFor()` = direct public URL (only under `allowDirectPublicUrl`) →
store-native temporary URL → signed proxy URL carrying the variant (when
`yii3-filestorage-web` binds `ProxyUrlGeneratorInterface`) → `null`, in which
case the application serves `stream()` itself. Only the store-URL branches
generate; the proxy URL is minted without generating (the proxy generates on
its first request), but what can never be generated still throws. The `-web` download action
serves variant tokens through `DerivativeResolverInterface`, which this package
binds to `Derivatives`. A URL minted outside the tenant's request (job, mail)
goes through `$derivatives->withProxyUrls($hmacGenerator->forScope($tenantId))`
— `Srcset::for()` over that facade too.
