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
   allocates. A 200-byte PNG may claim 100000×100000 pixels.

4. **Blob-scoped purity.** Under dedup, renditions live in the shared blob
   directory and the generation lock is keyed by `BlobId` + descriptor. A
   derivative must be a pure function of original bytes + preset version —
   no per-owner watermarks or redaction through this package.

5. **SVG is a document, not a raster.** Processors refuse it; the sanitizer
   (library pass + `foreignObject`/`animate*`/`set`/`mpath` post-pass + byte
   and element caps) throws instead of best-effort. Sanitized SVG still ships
   as attachment unless served from a cookie-less asset origin.

## Wiring the application owns

- `Yiisoft\Mutex\MutexFactoryInterface` — the generation-lock driver.
- The store must implement `DerivativeAwareStoreInterface`
  (core filesystem stores and FlysystemStore qualify);
  URL results additionally need `DerivativeUrlProviderInterface`.
- `filestorage:warm` needs `MaintenanceRepositoryInterface` (the `-db`
  repository).

## Serving

`urlFor()` = direct public URL (only under `allowDirectPublicUrl`) →
store-native temporary URL → `null`, in which case the application serves
`stream()` itself. Signed proxy delivery of variants through
`yii3-filestorage-web` is not wired yet — its download action refuses tokens
carrying a variant until it integrates with this package.
