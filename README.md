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
  ghostscript delegate only. The byte cap is the one guard that runs before
  ImageMagick parses the document; the page geometry is then pinged
  (metadata, no rasterisation), the render DPI is computed from it and the
  pixel cap, and a page over the cap at the 72-DPI baseline is refused before
  a pixel is allocated — the read itself selects page 0, so a hundred-page
  PDF costs one render, not a hundred. ImageMagick takes the page box from a
  scan of the file's raw text: it reports the *largest* direct box in the
  document (one oversized page refuses the preview) and cannot see a box
  given by an indirect reference, inside a compressed object stream, or
  scaled by `/UserUnit`. The ping is therefore not trusted as a bound on its
  own: ghostscript renders onto a fixed media of the pinged size
  (`pdf:fit-page`, page scaled to fit), so the raster never exceeds the
  geometry the DPI was chosen for, and a render that still came out larger
  is refused before anything is encoded. A page whose real box differs from
  the pinged one (a mixed-size document, a hidden box) is scaled into it and
  letterboxed. A `policy.xml` that forbids the PDF
  coder (Debian's default) surfaces as `UnsupportedSourceException`. Video posters stay
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
  it beside the original where the store's layout puts it (no metadata rows),
  and every later request is a plain read.
  Under the default `DirectoryLayout` that is `<key>/original.jpg` +
  `<key>/thumb-v1.webp`; under core's opt-in `FlatSiblingLayout` it is
  `blobs/ab/cd/<sha>.jpg` + `blobs/ab/cd/<sha>-thumb-v1.webp`. The layout is
  chosen once per store, in core; this package never builds a path itself. New presets work
  retroactively with no backfill. `filestorage:warm` pre-generates through the
  exact same locked path when eager is wanted.
- **A lock keyed by the physical rendition.** Under deduplication many `File`
  rows share one stored object; the generation lock is keyed by the store name
  plus the rendition path the store's layout names
  (`ObjectLayoutInterface::derivativePath()`), so concurrent requests —
  including requests for different logical files sharing bytes — generate
  once.
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

- PHP 8.3 – 8.6, `ext-dom`, `ext-libxml`
- `rasuvaeff/yii3-filestorage` ^0.10
- A processor extension: `ext-gd` and/or `ext-imagick` (Imagick is preferred
  when both are loaded — it applies EXIF orientation and handles more formats)
- A `yiisoft/mutex` driver for the generation lock (e.g. `yiisoft/mutex-file`)
- Optional: a PSR-16 cache (`Psr\SimpleCache\CacheInterface`, e.g.
  `yiisoft/cache`) — when the application binds one, failed generations are
  remembered (see below)

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

The store must implement the core `DerivativeAwareStoreInterface`. Core's
filesystem stores (`FileSystemStore`, `PublicFileSystemStore`) do;
**`FlysystemStore` (`-flysystem`, S3 and friends) does not** — on such a store
every rendition fails with `DerivativeGenerationException`, which
`yii3-filestorage-web` answers with 404. Keep image groups on a filesystem
store, or bind a store of your own that implements the interface.

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
        // A permanent public URL when the group's delivery policy allows
        // direct public delivery, otherwise a store-native expiring URL (both
        // generate the rendition first), otherwise the application's signed
        // proxy route when yii3-filestorage-web is installed — minted without
        // generating: the proxy generates on its first request. Null only
        // when neither exists — serve the stream yourself in that case.
        return $this->derivatives->urlFor($file, 'thumb');
    }
}
```

| Method | What it does |
|---|---|
| `ensure(File $file, string $preset, ?int $lockTimeoutSeconds = null): bool` | Guarantees the rendition exists; generates under the stampede lock (waiting `lockTimeout`, or the given seconds). Returns `true` when this call generated it |
| `stream(File $file, string $preset): ?StreamInterface` | The rendition's bytes, generating first if needed |
| `urlFor(File $file, string $preset, ?DateTimeImmutable $expiresAt = null): ?string` | Policy-gated URL: direct public → store-native temporary → signed proxy (`-web`) → `null`. Generates only for a store URL; still refuses what can never be generated (unsupported source, over the byte cap, a remembered failure) |
| `exists(File $file, string $preset): bool` | Pure probe, never generates |
| `descriptor(string $preset): DerivativeDescriptor` | The physical identity (`thumb-v1.webp`) a store addresses the rendition by |
| `withProxyUrls(?ProxyUrlGeneratorInterface $proxyUrls): self` | The same facade minting proxy URLs through another generator — e.g. one pinned to a tenant with `-web`'s `forScope()` for URLs minted outside its request |

The watermark recipe ships as an example (`examples/watermark-decorator.php`):
the mark belongs to the *original*, stamped at upload — every derivative
carries it by construction, and the "rendition = pure function of bytes +
preset version" invariant stays intact.

`Srcset::for($derivatives, $presets, $file)` turns the whitelist into an
`srcset` attribute value — `url 200w, url 800w`. The width descriptor is the
rendition's real width when the row carries `width`/`height` metadata
(`ImageMetadataStorage`) — a `contain` preset that does not upscale renders a
300 px source at 300, not at the preset's 800 — and the preset's width
otherwise; two candidates with one width keep the first. Height-only presets
have no descriptor and are skipped, a preset that cannot be produced for the
file is left out, and the answer is null when nothing yields a URL, because an
`srcset` with nothing in it is no attribute at all. `filestorage:warm` now ends with a note for every preset
above version 1: older renditions remain valid and become orphans —
`filestorage:gc --orphans` is the cleaner.

The facade implements core's `DerivativeResolverInterface` — that contract is
what `yii3-filestorage-web`'s download action consumes to serve variant
tokens, so installing both packages is the whole wiring: `urlFor()` starts
returning signed proxy URLs for a private filesystem store, and those URLs
resolve (`yii3-filestorage-web` 0.7+ on core ^0.10). `UnknownPresetException` extends core's
`UnresolvableVariantException`, the "map it to 404" signal.

Failure vocabulary: `UnknownPresetException` (not in the whitelist — map it to
404), `UnsupportedSourceException` (this installation cannot process the
combination — a PDF in a raster pipeline, a source over a guard),
`ProcessingFailedException` (the bytes do not decode),
`DerivativeGenerationException` (store lacks the capability, original bytes
missing, lock timeout, output over the preset's `maxBytes` once remembered).
A file whose path does not fit its store's layout (a
`FlatSiblingLayout` store holding `photo-1.jpg`) surfaces core's
`InvalidArgumentException` from the layout (or a `StoreException` from the
store): the store is misconfigured, not the request. Malformed preset config throws
`InvalidPresetException` at container build time, naming the offending key.

**URLs minted outside the tenant's request.** The signed proxy URL carries
the scope the bound generator reads from the *current* request. A URL minted
in a queue job or a mail, for a tenant's file, needs a generator pinned to
that tenant: `$derivatives->withProxyUrls($hmacGenerator->forScope($tenantId))`
(`-web`'s `HmacProxyUrlGenerator::forScope()`) returns the same facade minting
through it, and `Srcset::for()` over that facade does the same. Core's
`Storage::urlFor()` has no such hook: for originals, enter the tenant's
context (see `-web`'s README) or call the pinned generator directly.

### Failed generations are remembered

A source that always fails — it does not decode, it is over a guard, its
output is over the preset's `maxBytes` — used to be read and decoded again on
every request, each one queueing behind the generation lock. When the
application binds a PSR-16 `CacheInterface`, the failure is remembered for
`failureTtl` (default `PT5M`): later requests answer from the cache without the
lock, the read or the processor, and a request that waited for the lock sees
the winner's failure instead of decoding the same bytes again. The entry is
keyed by the physical rendition and the original's version (`updatedAt`,
content hash), so a re-upload is tried at once. A missing original, a store
error or a transient processing failure is never remembered — it may be gone
a second later: a stalled source stream, a temporary file that could not be
written, ImageMagick out of a resource under load. Only ImageMagick's
CorruptImage, Coder and Delegate errors (ghostscript refusing a broken PDF —
not remembering it would re-run ghostscript on every request), GD's refusal to
decode, the byte/pixel guards and an output over `maxBytes` are verdicts on
the file (`ProcessingFailedException::isTransient()` tells them apart). The
cache is optional in the strong sense: one that throws (Redis down) is a miss
on read and a no-op on write, never the reason a rendition is not served.
Keys are 61 characters of `[a-z0-9.]`, inside what PSR-16 guarantees. Without
a bound cache nothing is remembered.

A request waits `lockTimeout` (default 10 s) for another one's generation of
the same rendition — it holds a worker meanwhile; `filestorage:warm` waits
`warmLockTimeout` (default 60 s).

### Warm ahead of traffic

```bash
filestorage:warm                       # every preset, every file, resumable
filestorage:warm --preset=thumb --limit=1000 --after=<file-id>
```

Additive and idempotent — existing renditions are counted, not regenerated.
Unsupported files (non-images in a mixed bucket) are skips, not failures; any
other exception of one rendition — a layout's, a mutex driver's — is one
failure and the run goes on. `Last id:` is printed even when the run dies, so
`--after` can resume; it names the last file whose presets were all
attempted, so a run that dies between one file's presets resumes at that
file.
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
| Animated GIF | flattened to the first frame | first frame only — `[0]` is selected at read time, later frames are never decoded |
| Animated WebP | not decoded (libgd reads stills only) — `ProcessingFailedException` | first frame only — cut out of the container as a still before decoding (ImageMagick's WebP coder ignores `[0]`) |
| Metadata in output | stripped by construction | stripped explicitly |
| Format support | compile-time (`imagetypes()` is asked per call) | build-dependent (`queryFormats()` is asked per call) |

Both enforce the same guards before decoding: a source byte cap and a pixel
cap read from the image header — a 200-byte PNG is allowed to *claim*
100000×100000 pixels, and the allocation happens at decode time. Imagick adds
three of its own: the first frame is pinged and both the frame and its canvas
are checked (a GIF's header reports the logical screen, the decoder allocates
the frame — a 1×1 screen over a 4000×4000 frame passed the header guard);
the coder is the detected media type's, never ImageMagick's guess from the
bytes; and ImageMagick's width/height/list-length limits are narrowed to the
pinged frame for the read and put back after it (process-wide state, only
ever lowered). Those limits are shared by every thread of a process, so under
a threaded (ZTS) runtime — FrankenPHP, a threaded RoadRunner plugin — the
narrowing is skipped, rather than let one thread's read refuse another's; the
first-frame selection and the ping guards still apply. Animated AVIF
sequences are untested.

## Security

- Preset names are validated (`[a-z0-9][a-z0-9_-]{0,47}`) and become filenames
  only through the core's `DerivativeDescriptor`, which re-validates. A caller
  cannot smuggle a slash or choose an output type independently.
- Free-form transform parameters are not accepted anywhere, by design.
- Decode guards run on the header before any pixel allocation
  (`maxSourcePixels`, default 40 MP — per frame and canvas under Imagick, only
  frame 0 is decoded) and on the byte stream while reading
  (`maxSourceBytes`, default 64 MiB).
- PDF previews run ghostscript on untrusted input. Keep it current, keep
  ImageMagick's `-dSAFER` delegate, and forbid the PDF coder in `policy.xml`
  if you do not need previews — the processor then reports it as unsupported.
- A derivative must be a pure function of the original bytes plus the preset
  version: under deduplication renditions live beside the shared stored object.
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
