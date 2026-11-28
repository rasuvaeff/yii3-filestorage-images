# AGENTS.md — yii3-filestorage-images

Guidance for AI agents working on this package. Read before changing code.

## What this is

Named-preset image derivatives (thumbnails, previews, posters) for
`rasuvaeff/yii3-filestorage`. Public API: the `Derivatives` facade
(`ensure` / `stream` / `urlFor` / `exists` / `descriptor`), `Preset` +
`PresetRegistry` + `Format` + `Fit`, `ImageProcessorInterface` with GD /
Imagick / composite implementations, `SvgSanitizerInterface` with
`EnshrinedSvgSanitizer`, and the `filestorage:warm` console command.
Namespace `Rasuvaeff\Yii3FilestorageImages`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Derivatives are named, versioned presets — never free-form dimensions or
   caller-supplied paths.** A preset name reaches the filesystem only through
   the core `DerivativeDescriptor`, the preset `version` is part of the
   physical name (changing options without bumping `version` serves stale
   objects), and every decode is preceded by the byte + header-pixel guards.
   A rendition must stay a pure function of original bytes + preset version:
   under dedup it lives beside the shared stored object. Never build a
   derivative path here: the store's layout (`layout()->derivativePath()`)
   names it, and the generation lock is keyed by that path.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-images
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver;
both additionally compile **gd + imagick** (like `make test-images`),
because the processor tests throw `SkipTest` without them — a coverage
or mutation run that skips them measures a different package than CI
does. Run `make test-images` whenever a processor changes.

## Invariants & gotchas

- The composer:2 image ships neither gd nor imagick. `psalm.xml` therefore
  loads `stubs/gd.phpstub` and `stubs/imagick.phpstub`; if you call a new
  Imagick method, add it to the stub. Never reference `IMG_*` constants in a
  class-constant initializer — they exist only when ext-gd is loaded, and the
  class would fatal on `supports()` (that is why `GdImageProcessor::typeBit()`
  is a method).
- `Imagick::autoOrientImage()` does not exist in every imagick build —
  orientation is applied manually via `getImageOrientation()` +
  `flopImage()`/`rotateImage()`. Do not "simplify" it back.
- The generation lock name is `filestorage-images:` + sha256 of the store name
  + the rendition path from `layout()->derivativePath($file, $descriptor)`.
  Keyed by the *physical* rendition on purpose: deduplicated logical rows, and
  flat-layout rows whose originals differ only in extension, write one object
  and must share one lock. Never key it by `BlobId` + descriptor filename.
- **Imagick reads one frame, by filename selector.** `setFilename('<CODER>:source[0]')`
  before `pingImageBlob()`/`readImageBlob()`; the blob API's second argument
  ignores `[0]` (measured: 30 frames decoded). Reset the filename right after
  the read — the coder prefix otherwise wins over `setImageFormat()` and every
  rendition comes out in the source's format. The ping checks frame *and*
  canvas (review 1.0 A4: GIF 1x1 screen over a 4000x4000 frame). In front of
  it `SourceGuard::dimensions()` walks GIF blocks (core pass 2 N1): a frame
  outside the logical screen is refused for both processors, unknown bytes are
  skipped as IM/libgd skip them; the ping's frame branch is now unreachable for
  GIF (kept as defence in depth). GD sizes from `imagesx()/imagesy()`. Resource
  limits are process-wide: only lowered, only around the read, restored in
  `finally`; AREA/MEMORY are not used — past them IM 7 spills to disk instead
  of refusing. LISTLENGTH must be 2, not 1 (IM refuses a list reaching it).
  The narrowing is skipped under ZTS (`PHP_ZTS`): the limits are shared by
  every thread (review 1.0 pass 2, N9).
- **ImageMagick's WebP coder ignores `[0]`** and decodes every frame
  (measured, pass 2 N2). An animated WebP is cut down to its first `ANMF`
  frame by `Processing\WebpFirstFrame` (container walk, no decoding) and
  decoded as a still, then placed on its canvas. Never "fix" it by raising
  LISTLENGTH: that decodes the whole animation under a cap that measures one
  frame.
- **ImageMagick `PolicyError` (code 499) is `UnsupportedSourceException`**
  (`Processing\ImagickFailure`), tested in a child process with
  `MAGICK_CONFIGURE_PATH` (`tests/Support/ImagickPolicy.php`): the policy is
  read once at start-up.
- **The PDF ping is not a bound.** ImageMagick reads the page box from the
  file's raw text: the largest direct MediaBox (pinned by
  `theRenderGeometryIsTheLargestPageBoxInTheDocument`), blind to an indirect
  `/MediaBox`, compressed object streams and `/UserUnit` (pass 2 N1: a 6000 pt
  page pinged as letter, OOM-killed a 1 GB worker). The bound is
  `setOption('pdf:fit-page', '<ping w>x<ping h>')` (geometry in points; works
  on IM 6.9 and 7) plus `assertWithin()` after the read. Never drop either.
- **Failure cache.** Only `UnsupportedSourceException`, a non-transient
  `ProcessingFailedException` and core's `SizeLimitExceededException` from
  `process()`/`writeDerivative()` are remembered — never a missing original, a
  `StoreException` or `ProcessingFailedException::transient()` (stalled
  stream, spool, GD allocation/encode, ImageMagick codes other than
  CorruptImage/Coder/Delegate 325/350/415/425/450 — `ImagickFailure`; 415 is
  gs refusing a broken PDF, remembered so gs is not re-run per request). Checked before the
  lock, after losing it, and under it. Every cache call is wrapped: a throwing
  cache is a miss / no-op (pass 2 N7). Key: `fsimg.failed.` + 48 hex of sha256
  of store, rendition path, `updatedAt`, content hash — 61 chars, PSR-16
  portable (≤ 64, no `:`, no `-`).
- `filestorage:warm` sets `Last id` only after all presets of a file were
  attempted (pass 2 N16).
- `FlysystemStore` does not implement `DerivativeAwareStoreInterface` — keep
  every doc saying so (README EN/RU, llms.txt, SKILL.md; review 1.0 A6).
- `ThrowWithPreviousExceptionRector` is skipped in `rector.php`: it adds
  `code: $e->getCode()` (an int|string psalm rejects and a behaviour change).
  Same skip as the `*-db` packages.
- ext-gd / ext-imagick are `suggest`, not `require`; the symbols they provide
  are whitelisted in `composer-require-checker.json`.
- `config/di.php` deliberately does not bind `MutexFactoryInterface` — the
  application picks the mutex driver, like it picks the store backend.
  `ConfigWiringTest` guards the "binds only its own services" rule.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` and `make test-images`; if the change affects public
  API or release safety, also run `make release-check`. Paste the output.
