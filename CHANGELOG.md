# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
