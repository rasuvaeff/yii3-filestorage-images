<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages;

use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3FilestorageImages\Exception\DerivativeGenerationException;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Processing\TargetSize;

/**
 * Builds an `srcset` attribute value from the presets this installation
 * already whitelists — the list is the whitelist, so no second place to keep
 * in sync.
 *
 * ```php
 * <?php $srcset = Srcset::for($derivatives, $presets, $file); ?>
 * <img src="<?= $derivatives->urlFor($file, 'thumb') ?>"
 *      <?= $srcset === null ? '' : 'srcset="' . $srcset . '"' ?>>
 * ```
 *
 * Only presets with a `width` are candidates (a height-only preset has no
 * width descriptor): `url-a 200w, url-b 800w`. The descriptor is the
 * rendition's real width when the row carries its source dimensions
 * (`width`/`height` metadata, as `ImageMetadataStorage` records them) — a
 * `contain` preset that does not upscale renders a 300 px source at 300, not
 * at the preset's 800 — and the preset's width otherwise. Two candidates
 * landing on one width keep the first. A preset that cannot be produced for
 * this file is left out rather than failing the whole attribute; an unknown
 * preset name is still a programming error and throws. When no candidate
 * yields a URL the result is null — no attribute at all, not an empty one.
 *
 * The recorded dimensions are the stored ones: for a JPEG whose EXIF
 * orientation swaps the sides, the descriptor is computed on the unrotated
 * size.
 *
 * @api
 */
final readonly class Srcset
{
    /**
     * Every configured preset that has a width.
     *
     * @param list<non-empty-string>|null $presetNames Null means "all
     *        configured"; a list is an intersection with the whitelist.
     *
     * @return non-empty-string|null
     *
     * @throws Exception\UnknownPresetException
     */
    public static function for(
        Derivatives $derivatives,
        PresetRegistry $presets,
        File $file,
        ?array $presetNames = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): ?string {
        $candidates = $presetNames ?? $presets->names();
        $source = self::sourceSize($file);

        $entries = [];
        foreach ($candidates as $name) {
            $preset = $presets->get($name);
            if ($preset->width === null) {
                continue;
            }

            $width = $source === null
                ? $preset->width
                : TargetSize::calculate($source[0], $source[1], $preset)->width;
            if (isset($entries[$width])) {
                continue;
            }

            try {
                $url = $derivatives->urlFor($file, $name, $expiresAt);
            } catch (UnsupportedSourceException|ProcessingFailedException|DerivativeGenerationException) {
                continue;
            }
            if ($url === null) {
                continue;
            }

            $entries[$width] = $url . ' ' . $width . 'w';
        }

        return $entries === [] ? null : implode(', ', $entries);
    }

    /**
     * @return array{positive-int, positive-int}|null
     */
    private static function sourceSize(File $file): ?array
    {
        $width = $file->metadata['width'] ?? null;
        $height = $file->metadata['height'] ?? null;

        return \is_int($width) && \is_int($height) && $width > 0 && $height > 0 ? [$width, $height] : null;
    }
}
