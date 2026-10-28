<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages;

use Rasuvaeff\Yii3Filestorage\File;

/**
 * Builds an `srcset` attribute value from the presets this installation
 * already whitelists — the list is the whitelist, so no second place to keep
 * in sync.
 *
 * ```php
 * <img src="<?= $derivatives->urlFor($file, 'thumb') ?>"
 *      srcset="<?= Srcset::for($derivatives, $file) ?>">
 * ```
 *
 * Widths come from the presets (`width` — a height-only preset has no
 * descriptor and is skipped; the preset itself rejects non-positive widths): `url-a 200w, url-b 800w`. When no preset of
 * the list yields a URL, the result is an empty string — an `srcset` with
 * nothing in it is no attribute at all, not a broken one.
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
     */
    public static function for(
        Derivatives $derivatives,
        PresetRegistry $presets,
        File $file,
        ?array $presetNames = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): ?string {
        $candidates = $presetNames ?? $presets->names();

        $entries = [];
        foreach ($candidates as $name) {
            $preset = $presets->get($name);
            if ($preset->width === null) {
                continue;
            }

            $url = $derivatives->urlFor($file, $name, $expiresAt);
            if ($url === null) {
                continue;
            }

            $entries[] = $url . ' ' . $preset->width . 'w';
        }

        return $entries === [] ? null : implode(', ', $entries);
    }
}
