<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages;

/**
 * Output formats a preset may produce.
 *
 * A closed set on purpose: the derivative's extension and media type are
 * derived from this enum, land inside {@see Preset::descriptor()} and from
 * there inside a signed URL — so "any string the config author typed" is not
 * an acceptable domain. A format the processors cannot encode fails at
 * generation time with the processor named, not at config time: whether AVIF
 * is available is a property of the runtime, not of the preset.
 *
 * @api
 */
enum Format: string
{
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Webp = 'webp';
    case Gif = 'gif';
    case Avif = 'avif';

    /**
     * @return non-empty-string Without a leading dot.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Gif => 'gif',
            self::Avif => 'avif',
        };
    }

    /**
     * @return non-empty-string
     */
    public function mediaType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Webp => 'image/webp',
            self::Gif => 'image/gif',
            self::Avif => 'image/avif',
        };
    }

    /**
     * Whether the encoded output can carry an alpha channel. JPEG cannot, so
     * a processor composites transparent sources onto a solid background
     * instead of letting the encoder pick an arbitrary one.
     */
    public function supportsAlpha(): bool
    {
        return $this !== self::Jpeg;
    }
}
