# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Changed (BC break, pre-1.0):** requires `rasuvaeff/yii3-filestorage` `^0.10`; serving variant tokens takes `rasuvaeff/yii3-filestorage-web` 0.7+. This release no longer installs with core 0.9; stay on 0.8.0 for it. New runtime dependency `psr/simple-cache` (interface only).
- **Security (review 1.0 A4):** `ImagickImageProcessor` no longer decodes every frame of an animation before keeping the first, and no longer trusts the header size of a GIF whose logical screen is smaller than its frame. The first frame is pinged with the coder of the detected media type (never ImageMagick's own sniffing) and both the frame and its canvas are checked against `maxSourcePixels`; only frame `[0]` is read, with ImageMagick's width/height/list-length limits narrowed to the pinged frame for the read and restored after it. A GIF with a 1×1 screen over a 4000×4000 frame, and a 30-frame 1 MP animation under a 2 MP cap, are now refused / decoded as one frame. A source whose bytes are another format than its media type is a `ProcessingFailedException`.
- **Fixed (docs, review 1.0 A6):** `FlysystemStore` does **not** implement `DerivativeAwareStoreInterface`; README EN/RU and llms.txt said it did. On such a store every rendition is a `DerivativeGenerationException` (404 in `-web`).
- **Added (review 1.0 #10):** failed generations are remembered when a PSR-16 `CacheInterface` is bound (optional `Derivatives` parameter `failures`, `failureTtl` default `PT5M`, params `failureTtl`). Only verdicts about the bytes are remembered (guard refusal, decode failure, output over the preset's cap), keyed by the rendition path and the original's `updatedAt`/content hash; a request that waited for the lock sees the winner's failure instead of decoding again. A remembered "output over `maxBytes`" replays as `DerivativeGenerationException`.
- **Changed:** `lockTimeout` default 30 → **10** s (a request holds a worker while it waits); `ensure()` takes `?int $lockTimeoutSeconds`; `filestorage:warm` waits `warmLockTimeout` (new param, default 60 s; `WarmCommand(lockTimeoutSeconds:)`).
- **Changed (review 1.0 #12):** `urlFor()` mints the signed proxy URL without generating (the proxy generates on its first request); it still generates before returning a store URL, and still throws for an unsupported source, a source over the byte cap or a remembered failure. With neither a store URL nor a proxy it returns null without generating.
- **Fixed (review 1.0 #12):** `Srcset::for()` uses the rendition's real width when the row carries `width`/`height` metadata (a `contain` preset without upscale on a smaller source), keeps one candidate per width, leaves out a preset that cannot be produced instead of failing the whole attribute, and its docblock matches the code (null, not an empty string; the example passes `$presets`).
- **Fixed (review 1.0 #13):** `PdfPreviewProcessor` no longer uses `assert()` for the temporary file (a `ProcessingFailedException` now), spools before measuring and pings page `[0]`; the measured geometry is documented as the largest page box in the document (ImageMagick scans the whole file). An ImageMagick `policy.xml` refusal (`PolicyError`) is an `UnsupportedSourceException` in both Imagick processors — `warm` counts a skip. Ghostscript hardening is named under Security.
- **Fixed (review 1.0 #17):** `filestorage:warm` counts any `Exception` of one rendition (a layout's `InvalidArgumentException`, a mutex driver's `RuntimeException`) as one failure and goes on, and prints `Last id:` even when the run dies. `suggest` text for `-web` updated. Added `.github/CODEOWNERS`.
- **Security (review 1.0 pass 2, N1):** `PdfPreviewProcessor` bounds the render itself. ImageMagick's ping reads the page box from the file's raw text and misses an indirect `/MediaBox`, a box in a compressed object stream and `/UserUnit` — a 6000-point page pinged as letter size, was rendered at 150 DPI and killed the worker. Ghostscript now renders onto a fixed media of the pinged size (`pdf:fit-page`; the page is scaled to fit and letterboxed when its real box differs, e.g. in mixed-size documents), and a raster larger than the pinged geometry at the chosen DPI is an `UnsupportedSourceException`.
- **Fixed (review 1.0 pass 2, N2):** animated WebP works again under Imagick. ImageMagick's WebP coder ignores the `[0]` selector, so the list-length limit refused every animated WebP (a regression of A4), and builds without that limit decoded every frame. The first frame is now cut out of the RIFF container as a still (`Processing\WebpFirstFrame`, `@internal`), decoded alone and placed on its canvas; a malformed animated container is a `ProcessingFailedException`.
- **Added (review 1.0 pass 2, N8):** `ProcessingFailedException::transient()` / `isTransient()`. A stalled source stream, a failed PDF spool, GD allocation/encode failures and every ImageMagick error other than CorruptImage/Coder/Delegate (resource limits, cache, file-open) are transient and never remembered by the failure cache — a load spike no longer turns into five minutes of 404 per thumbnail.
- **Fixed (review 1.0 pass 2, N7):** a failure cache that throws (Redis down) is a miss on read and a no-op on write; it no longer turns every `ensure()`/`urlFor()`/`Srcset` into an exception (a 500 in `-web`), and no longer replaces the generation's own failure.
- **Fixed (review 1.0 pass 2, N15):** the failure-cache key is 61 characters of `[a-z0-9.]` (`fsimg.failed.` + 192 bits of sha256), inside what PSR-16 guarantees; it was 90. Entries remembered under the old key are simply not found.
- **Changed (review 1.0 pass 2, N9):** under a threaded (ZTS) runtime `ImagickImageProcessor` no longer narrows ImageMagick's process-wide width/height/list-length limits around a read — one thread's narrowing refused another thread's legitimate read.
- **Added (review 1.0 pass 2, N10):** `Derivatives::withProxyUrls()` — the same facade minting proxy URLs through another generator, e.g. `-web`'s `HmacProxyUrlGenerator::forScope($tenantId)` for URLs minted in a job or a mail; `Srcset::for()` over it does the same.
- **Fixed (review 1.0 pass 2, N16):** `filestorage:warm` prints as `Last id:` the last file whose presets were all attempted; a run that died between one file's presets named that file, and `--after` skipped its remaining presets.

## 0.8.0 — 2026-11-27

- **Changed:** requires `rasuvaeff/yii3-filestorage` `^0.9` (store layouts, two-phase `gc`). This release no longer installs with core 0.8; stay on 0.7.1 for it.
- Enh #21: renditions follow the store's object layout. Stores already place them through `StoreInterface::layout()`; the generation lock is now keyed by the store name plus the path `ObjectLayoutInterface::derivativePath()` names, instead of the original's path plus the descriptor filename, so under core's `FlatSiblingLayout` (`<sha>.jpg` → `<sha>-thumb-v1.webp`) two rows naming one rendition share one lock. During a rolling deploy old and new processes use different lock names for the same rendition; the worst case is one duplicate generation, since derivative writes are atomic.
- Docs: README, llms.txt and the agent skill describe the flat-layout naming; the `suggest` entry for `rasuvaeff/yii3-filestorage-web` says what it is — variant tokens are served there (0.5.2+) through `DerivativeResolverInterface`.

## 0.7.1 — 2026-11-27

- Docs: the agent skill no longer says `FlysystemStore` supports derivatives, and documents the signed proxy URL step of `urlFor()` and the `-web` variant delivery that are already wired.

## 0.7.0 — 2026-11-27

- **Changed:** requires `rasuvaeff/yii3-filestorage` `^0.8.2`, so the whole family installs on one line of the core. This release no longer installs with core 0.3, 0.8.0 or 0.8.1; stay on 0.6.0 for those.

## 0.6.0 — 2026-11-24

- Enh #15: Add PHP 8.6 support. The constraint is now `8.3 - 8.6`, and
  `rasuvaeff/yii3-filestorage` is accepted on the `^0.8` line (released with
  `8.3 - 8.6`). Dev-only: `vimeo/psalm` `^6.16` -> `^7.0`.

## 0.5.0 — 2026-10-29

- `PdfPreviewProcessor`: the first page of a PDF as the preset's image —
  non-image previews per the processing contract's "keyed by source MIME"
  shape. Imagick + the ghostscript delegate; render DPI computed from the
  pinged page geometry and the pixel cap. Mutation gate recalibrated 85 -> 83
  (the encode-path equivalence class; see infection.json5).

## 0.4.1 — 2026-10-29

- `examples/watermark-decorator.php`: the "pre-process at upload" watermark
  recipe — the mark belongs to the original, not to a preset. #8
  (Merged after the 0.4.0 tag was pushed.)

## 0.4.0 — 2026-10-29

- `Srcset::for()`: the preset whitelist as an `srcset` attribute value. #8
- `filestorage:warm` notes presets above version 1 — older renditions stay
  valid and become orphans; `gc --orphans` is the cleaner. #8

## 0.3.0 — 2026-10-28

- `ImageMetadataStorage`: optional upload-time decorator recording
  `width`/`height` into `File::$metadata` from the bounded image header — the
  NG1 room, finally written to. #5
- Requires `rasuvaeff/yii3-filestorage: ^0.3`.

## 0.2.0 — 2026-10-28

- `Derivatives` implements core's `Store\DerivativeResolverInterface` —
  the contract `-web` consumes to serve variant tokens. #2
- `urlFor()` falls back to the signed proxy route: direct public →
  store-native temporary → `ProxyUrlGeneratorInterface::variantUrl()` (bound
  by installing `rasuvaeff/yii3-filestorage-web`) → null. A private
  filesystem store now gets URLs instead of "write your own controller". #2
- `UnknownPresetException` extends core's `UnresolvableVariantException`. #2
- Requires `rasuvaeff/yii3-filestorage: ^0.2`.

## 0.1.0 — 2026-10-28

First release. Tracks `rasuvaeff/yii3-filestorage` `0.x`: the API settles
together with core's while the family is built out.

- Initial release: named, versioned derivative presets (`Preset`,
  `PresetRegistry`, `Format`, `Fit`) over the core derivative-object
  capability.
- `Derivatives` facade: lazy generation under a blob-scoped stampede lock
  (`ensure`), `stream`, policy-gated `urlFor`, `exists`, `descriptor`.
- `GdImageProcessor` and `ImagickImageProcessor` behind
  `ImageProcessorInterface`, with shared pure geometry (`TargetSize`) and
  byte/pixel decompression-bomb guards before every decode;
  `CompositeImageProcessor::fromAvailable()` picks by loaded extensions.
- `EnshrinedSvgSanitizer`: enshrined/svg-sanitize (`^1.1.1` — `<=0.22.0`
  carries unpatched DTD-entity XSS and `<use>` nesting-DoS CVEs) plus byte
  cap, element-count cap and a post-pass removing
  `foreignObject`/`animate*`/`set`/`mpath`.
- Contained output never exceeds `Preset::MAX_DIMENSION` on a computed
  side: a one-sided upscale preset or a source wider than the cap shrinks
  the whole box proportionally instead of handing the processors a canvas
  outside the memory grant.
- PNG `quality` maps onto zlib effort in the same direction as the lossy
  formats: a higher quality is a smaller file, matching Imagick's
  compression-quality semantics for one preset across processors.
- `filestorage:warm` console command: resumable eager generation through the
  same locked path as lazy.
- Re-encode-on-upload privacy decorator shipped as an executable example.
