# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- `EnshrinedSvgSanitizer`: enshrined/svg-sanitize plus byte cap, element-count
  cap and a post-pass removing `foreignObject`/`animate*`/`set`/`mpath`.
- `filestorage:warm` console command: resumable eager generation through the
  same locked path as lazy.
- Re-encode-on-upload privacy decorator shipped as an executable example.
