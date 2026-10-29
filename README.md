# rasuvaeff/yii3-filestorage-images

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage-images/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-images)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage-images/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-images)
[![Build](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage-images/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-images)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

Named-preset image derivatives — thumbnails, previews, posters — for
[rasuvaeff/yii3-filestorage](https://github.com/rasuvaeff/yii3-filestorage).
Lazy generation under a stampede lock, GD and Imagick processors behind one
interface, decompression-bomb guards before every decode, and an SVG sanitizer
for the one image format that is also a program.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

**Status: `0.x`.** The API may still change while the `-web` variant-serving
integration is exercised against it.

## What it does

- **Non-image previews.** `PdfPreviewProcessor` renders the first page of a
  PDF as the preset's image — the "different processor keyed by source MIME"
  shape the processing contract was drawn around. Imagick with the
  ghostscript delegate only; the render DPI is computed from the pinged page
  geometry and the pixel cap, so a decompression bomb has to get past the
  byte cap and the geometry check to reach ImageMagick. Video posters stay
  out: no ffmpeg in the dependency graph, by the same honesty.
- **Presets, never free-form dimensions.** Every derivative this installation
  can produce is declared in `params` under a name. `w=200&h=200` in a URL —
  even signed — turns one uploaded image into an unbounded set of addressable
  derivatives; a whitelist makes the set finite by construction.
- **Versioned recipes.** The preset version is part of the physical object
  name (`thumb-v2.webp`). Changing a preset's settings under the same name
  would silently keep serving objects generated with the old settings; bumping
  `version` creates a new object instead.
- **Lazy with cache.** The first request generates the rendition once, stores
  it beside the original (`<key>/original.jpg` + `<key>/thumb-v1.webp`, no
  metadata rows), and every later request is a plain read. New presets work
  retroactively with no backfill. `filestorage:warm` pre-generates through the
  exact same locked path when eager is wanted.
- **A lock keyed by physical blob identity.** Under deduplication many `File`
  rows share one object directory; the generation lock is keyed by
  `BlobId` + versioned derivative name, so concurrent requests — including
  requests for different logical files sharing bytes — generate once.
- **Dimensions into metadata at upload time** (`ImageMetadataStorage`). An
  optional decorator over the storage facade: on `add()` it reads the image
  header (bounded prefix, never a decode — the upload policy's own recipe)
  and records `width`/`height` into `File::$metadata`, so an image consumer
  learns the size from the row instead of decoding the object. Wire it where
  you wire the facade:

  ```php
  StorageInterface::class => static fn (Storage $storage): StorageInterface
      => new ImageMetadataStorage($storage),
  ```

## Requirements

- PHP 8.3 – 8.5, `ext-dom`, `ext-libxml`
- `rasuvaeff/yii3-filestorage` ^0.3
- A processor extension: `ext-gd` and/or `ext-imagick` (Imagick is preferred
  when both are loaded — it applies EXIF orientation and handles more formats)
- A `yiisoft/mutex` driver for the generation lock (e.g. `yiisoft/mutex-file`)

## Installation

```bash
composer require rasuvaeff/yii3-filestorage-images
```

With `yiisoft/config` the package wires itself. Two things stay yours to bind,
deliberately:

```php
// config/common/di/mutex.php — pick the driver that matches the deployment:
// file on one host, db/redis across a fleet.
use Yiisoft\Mutex\File\FileMutexFactory;
use Yiisoft\Mutex\MutexFactoryInterface;

return [
    MutexFactoryInterface::class => static fn (): MutexFactoryInterface
        => new FileMutexFactory(mutexPath: '/path/to/runtime/mutex'),
];
```

The store comes from the storage family (`-flysystem`, or a filesystem store
bound by the application) and must implement the core
`DerivativeAwareStoreInterface` — both shipped filesystem stores and
`FlysystemStore` do.

## Usage

### Declare presets

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage-images' => [
        'presets' => [
            'thumb' => [
                'version' => 1,
                'width' => 200,
                'height' => 200,
                'fit' => 'cover',      // 'contain' (default) or 'cover'
                'format' => 'webp',    // jpeg | png | webp | gif | avif
                'quality' => 82,       // 1-100
            ],
            'poster' => [
                'width' => 1280,       // one side is enough for 'contain'
                'format' => 'jpeg',
            ],
        ],
    ],
];
```

### Generate and serve

```php
use Rasuvaeff\Yii3FilestorageImages\Derivatives;

final readonly class AvatarPresenter
{
    public function __construct(private Derivatives $derivatives) {}

    public function thumbUrl(File $file): ?string
    {
        // Generates lazily if missing, then: a permanent public URL when the
        // group's delivery policy allows direct public delivery, otherwise a
        // store-native expiring URL, otherwise the application's signed proxy
        // route when yii3-filestorage-web is installed. Null only when
        // neither exists — serve the stream yourself in that case.
        return $this->derivatives->urlFor($file, 'thumb');
    }
}
```

| Method | What it does |
|---|---|
| `ensure(File $file, string $preset): bool` | Guarantees the rendition exists; generates under the stampede lock. Returns `true` when this call generated it |
| `stream(File $file, string $preset): ?StreamInterface` | The rendition's bytes, generating first if needed |
| `urlFor(File $file, string $preset, ?DateTimeImmutable $expiresAt = null): ?string` | Policy-gated URL: direct public → store-native temporary → signed proxy (`-web`) → `null` |
| `exists(File $file, string $preset): bool` | Pure probe, never generates |
| `descriptor(string $preset): DerivativeDescriptor` | The physical identity (`thumb-v1.webp`) a store addresses the rendition by |

The watermark recipe ships as an example (`examples/watermark-decorator.php`):
the mark belongs to the *original*, stamped at upload — every derivative
carries it by construction, and the "rendition = pure function of bytes +
preset version" invariant stays intact.

`Srcset::for($derivatives, $presets, $file)` turns the whitelist into an
`srcset` attribute value — `url 200w, url 800w` — from the presets' widths
(height-only presets have no descriptor and are skipped); it answers null
when nothing yields a URL, because an `srcset` with nothing in it is no
attribute at all. `filestorage:warm` now ends with a note for every preset
above version 1: older renditions remain valid and become orphans —
`filestorage:gc --orphans` is the cleaner.

The facade implements core's `DerivativeResolverInterface` — that contract is
what `yii3-filestorage-web`'s download action consumes to serve variant
tokens, so installing both packages is the whole wiring: `urlFor()` starts
returning signed proxy URLs for a private filesystem store, and those URLs
resolve (`yii3-filestorage-web` 0.3.0+ — earlier releases require an older
core and do not serve variant tokens). `UnknownPresetException` extends core's
`UnresolvableVariantException`, the "map it to 404" signal.

Failure vocabulary: `UnknownPresetException` (not in the whitelist — map it to
404), `UnsupportedSourceException` (this installation cannot process the
combination — a PDF in a raster pipeline, a source over a guard),
`ProcessingFailedException` (the bytes do not decode),
`DerivativeGenerationException` (store lacks the capability, original bytes
missing, lock timeout). Malformed preset config throws
`InvalidPresetException` at container build time, naming the offending key.

### Warm ahead of traffic

```bash
filestorage:warm                       # every preset, every file, resumable
filestorage:warm --preset=thumb --limit=1000 --after=<file-id>
```

Additive and idempotent — existing renditions are counted, not regenerated.
Unsupported files (non-images in a mixed bucket) are skips, not failures.
Requires the backend to implement the core `MaintenanceRepositoryInterface`
(the `-db` repository does).

### Sanitize SVG

SVG is not handled by the processors — it is not a raster format, it is a
document that can carry scripts, remote references and animation. What this
package offers instead is an opt-in sanitizer to run at upload time:

```php
use Rasuvaeff\Yii3FilestorageImages\Svg\SvgSanitizerInterface;

$safe = $sanitizer->sanitize($svgBytes); // throws SvgSanitizationException
```

`EnshrinedSvgSanitizer` = [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer)
with remote references removed, plus the checks the library does not make: a
byte cap before parsing, a post-pass stripping `foreignObject` / `animate*` /
`set` / `mpath`, and an element-count cap. Even sanitized, serve SVG as an
attachment unless it comes from a dedicated cookie-less origin — see the core
package's delivery policy documentation.

### Re-encode on upload (privacy)

Phone photos carry GPS coordinates and camera serial numbers in EXIF, and
nobody expects an avatar upload to publish their home address. The Imagick
processor strips metadata from every derivative; the original keeps its bytes
by design. `examples/reencode-decorator.php` shows a storage decorator that
re-encodes originals on `add()` — stripping EXIF and defeating image/script
polyglots at the cost of not preserving the uploaded bytes.

## Processor differences

| | GD | Imagick |
|---|---|---|
| EXIF auto-orientation | no — a sideways phone photo stays sideways | yes, before geometry |
| Animated GIF | flattened to the first frame | first frame |
| Metadata in output | stripped by construction | stripped explicitly |
| Format support | compile-time (`imagetypes()` is asked per call) | build-dependent (`queryFormats()` is asked per call) |

Both enforce the same guards before decoding: a source byte cap and a pixel
cap read from the image header — a 200-byte PNG is allowed to *claim*
100000×100000 pixels, and the allocation happens at decode time.

## Security

- Preset names are validated (`[a-z0-9][a-z0-9_-]{0,47}`) and become filenames
  only through the core's `DerivativeDescriptor`, which re-validates. A caller
  cannot smuggle a slash or choose an output type independently.
- Free-form transform parameters are not accepted anywhere, by design.
- Decode guards run on the header before any pixel allocation
  (`maxSourcePixels`, default 40 MP) and on the byte stream while reading
  (`maxSourceBytes`, default 64 MiB).
- A derivative must be a pure function of the original bytes plus the preset
  version: under deduplication renditions live in the shared blob directory.
  Per-owner watermarks or redaction do not belong in this package's model.
- SVG output of the sanitizer is safe*r*, not safe to inline from your app
  origin — active-content delivery rules from the core package still apply.

## Examples

Executable scripts live in [`examples/`](examples/README.md).

## Development

```bash
make build        # validate + normalize + require-checker + cs + psalm + tests
make test-images  # tests with gd+imagick compiled into the container (no skips)
make mutation     # infection with pcov bootstrapped
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
