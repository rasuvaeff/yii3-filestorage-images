<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages;

use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3FilestorageImages\Exception\InvalidPresetException;

/**
 * A named, versioned recipe for one derivative.
 *
 * Named presets, never free-form dimensions: the URL carries `thumb`, not
 * `w=200&h=200`. Free-form parameters — even HMAC-signed — turn one uploaded
 * image into an unbounded set of addressable derivatives, so anyone who can
 * obtain signed URLs can force unbounded CPU and storage. A whitelist of
 * presets in `params` makes the derivative set finite by construction.
 *
 * The version is part of the physical name ({@see descriptor()}): changing a
 * preset's processing settings under the same name would silently keep serving
 * stale objects generated with the old settings, because a derivative that
 * exists is never regenerated. Bumping `version` changes the object name
 * instead — old renditions stay valid for old URLs until their file is
 * removed, new requests generate the new recipe.
 *
 * @api
 */
final readonly class Preset
{
    /**
     * 48 rather than the descriptor's 64: `-v<version>` must still fit.
     */
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,47}\z/';

    /**
     * Output pixels are allocated before encoding, so a preset is a memory
     * grant. 16384² stays within what a decode guard tolerates while leaving
     * no room for a misconfigured "resize to a billion pixels".
     */
    public const int MAX_DIMENSION = 16_384;

    private const int MAX_VERSION = 9999;

    private const array KNOWN_KEYS = [
        'version', 'width', 'height', 'fit', 'format', 'quality', 'upscale', 'maxBytes',
    ];

    /** @var non-empty-string */
    public string $name;

    /** @var int<1, max> */
    public int $version;

    /** @var int<1, max>|null */
    public ?int $width;

    /** @var int<1, max>|null */
    public ?int $height;

    /** @var int<1, 100> */
    public int $quality;

    /** @var int<0, max> Byte cap on the encoded derivative; zero means no cap. */
    public int $maxBytes;

    /**
     * @throws InvalidPresetException
     */
    public function __construct(
        string $name,
        public Format $format,
        ?int $width = null,
        ?int $height = null,
        public Fit $fit = Fit::Contain,
        int $quality = 82,
        int $version = 1,
        public bool $upscale = false,
        int $maxBytes = 0,
    ) {
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidPresetException("Invalid preset name \"{$name}\"");
        }
        if ($version < 1 || $version > self::MAX_VERSION) {
            throw new InvalidPresetException("Preset \"{$name}\": version must be between 1 and " . self::MAX_VERSION);
        }
        foreach (['width' => $width, 'height' => $height] as $side => $value) {
            if ($value !== null && ($value < 1 || $value > self::MAX_DIMENSION)) {
                throw new InvalidPresetException(
                    "Preset \"{$name}\": {$side} must be between 1 and " . self::MAX_DIMENSION,
                );
            }
        }
        if ($width === null && $height === null) {
            throw new InvalidPresetException("Preset \"{$name}\": at least one of width and height is required");
        }
        if ($fit === Fit::Cover && ($width === null || $height === null)) {
            throw new InvalidPresetException("Preset \"{$name}\": fit \"cover\" requires both width and height");
        }
        if ($quality < 1 || $quality > 100) {
            throw new InvalidPresetException("Preset \"{$name}\": quality must be between 1 and 100");
        }
        if ($maxBytes < 0) {
            throw new InvalidPresetException("Preset \"{$name}\": maxBytes must not be negative");
        }

        // The range checks above ran inside a foreach, which psalm does not
        // carry back to the individual variables.
        \assert($width === null || $width >= 1);
        \assert($height === null || $height >= 1);

        $this->name = $name;
        $this->version = $version;
        $this->width = $width;
        $this->height = $height;
        $this->quality = $quality;
        $this->maxBytes = $maxBytes;
    }

    /**
     * Builds a preset from one `params` entry.
     *
     * Unknown keys are an error, not a warning nobody reads: a typo like
     * `widht` would otherwise produce a preset that quietly ignores the one
     * thing it was configured to do.
     *
     * @param non-empty-string $name
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidPresetException
     */
    public static function fromArray(string $name, array $config): self
    {
        $unknown = array_diff(array_keys($config), self::KNOWN_KEYS);
        if ($unknown !== []) {
            throw new InvalidPresetException(
                "Preset \"{$name}\": unknown option \"" . implode('", "', array_map(strval(...), $unknown))
                . '" — known options are "' . implode('", "', self::KNOWN_KEYS) . '"',
            );
        }

        $format = $config['format'] ?? null;
        if (!\is_string($format) || Format::tryFrom($format) === null) {
            throw new InvalidPresetException(
                "Preset \"{$name}\": format must be one of \""
                . implode('", "', array_column(Format::cases(), 'value')) . '"',
            );
        }

        $fit = $config['fit'] ?? Fit::Contain->value;
        if (!\is_string($fit) || Fit::tryFrom($fit) === null) {
            throw new InvalidPresetException(
                "Preset \"{$name}\": fit must be one of \""
                . implode('", "', array_column(Fit::cases(), 'value')) . '"',
            );
        }

        return new self(
            name: $name,
            format: Format::from($format),
            width: self::intOption($name, $config, key: 'width'),
            height: self::intOption($name, $config, key: 'height'),
            fit: Fit::from($fit),
            quality: self::intOption($name, $config, key: 'quality') ?? 82,
            version: self::intOption($name, $config, key: 'version') ?? 1,
            upscale: self::boolOption($name, $config, key: 'upscale'),
            maxBytes: self::intOption($name, $config, key: 'maxBytes') ?? 0,
        );
    }

    /**
     * The physical identity of this recipe inside a file's directory:
     * `thumb-v2.webp`. Everything a store needs, nothing it must trust — the
     * descriptor re-validates name, extension and media type on construction.
     */
    public function descriptor(): DerivativeDescriptor
    {
        return new DerivativeDescriptor(
            name: "{$this->name}-v{$this->version}",
            extension: $this->format->extension(),
            mediaType: $this->format->mediaType(),
        );
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $config
     * @param non-empty-string $key
     *
     * @throws InvalidPresetException
     */
    private static function intOption(string $name, array $config, string $key): ?int
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }
        if (!\is_int($config[$key])) {
            throw new InvalidPresetException("Preset \"{$name}\": {$key} must be an integer");
        }

        return $config[$key];
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $config
     * @param non-empty-string $key
     *
     * @throws InvalidPresetException
     */
    private static function boolOption(string $name, array $config, string $key): bool
    {
        if (!\array_key_exists($key, $config)) {
            return false;
        }
        if (!\is_bool($config[$key])) {
            throw new InvalidPresetException("Preset \"{$name}\": {$key} must be a boolean");
        }

        return $config[$key];
    }
}
