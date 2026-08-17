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
   under dedup it lives in the shared blob directory.
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
`composer:2` container because the base image has no coverage driver.
`make test-images` additionally compiles **gd + imagick** into the container:
the processor tests throw `SkipTest` without them, so plain `make test` is
green but incomplete. Run `make test-images` whenever a processor changes.

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
- The generation lock name is `filestorage-images:` + sha256 of
  `BlobId->key()` + descriptor filename. Keyed by *physical* identity on
  purpose: deduplicated logical rows must share the lock.
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
