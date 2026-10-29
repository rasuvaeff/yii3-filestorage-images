# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
