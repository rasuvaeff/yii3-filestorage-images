<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages;

use Rasuvaeff\Yii3FilestorageImages\Exception\InvalidPresetException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnknownPresetException;

/**
 * The whitelist of every derivative this installation can produce.
 *
 * @api
 */
final readonly class PresetRegistry
{
    /** @var array<non-empty-string, Preset> */
    private array $presets;

    /**
     * @param list<Preset> $presets
     *
     * @throws InvalidPresetException
     */
    public function __construct(array $presets = [])
    {
        $byName = [];
        foreach ($presets as $preset) {
            if (isset($byName[$preset->name])) {
                throw new InvalidPresetException("Duplicate preset \"{$preset->name}\"");
            }
            $byName[$preset->name] = $preset;
        }

        $this->presets = $byName;
    }

    /**
     * @param array<array-key, mixed> $config Preset name => options, the
     *        shape `params.php` documents.
     *
     * @throws InvalidPresetException
     */
    public static function fromArray(array $config): self
    {
        $presets = [];
        foreach ($config as $name => $options) {
            if (!\is_string($name) || $name === '') {
                throw new InvalidPresetException('Preset names must be non-empty strings');
            }
            if (!\is_array($options)) {
                throw new InvalidPresetException("Preset \"{$name}\": options must be an array");
            }
            $presets[] = Preset::fromArray($name, $options);
        }

        return new self($presets);
    }

    /**
     * @param non-empty-string $name
     *
     * @throws UnknownPresetException
     */
    public function get(string $name): Preset
    {
        // The known names are part of the message because the reader of this
        // exception is someone whose next action is fixing a config key or a
        // call site, and "unknown preset" alone sends them grepping.
        return $this->presets[$name] ?? throw new UnknownPresetException(
            "Unknown preset \"{$name}\""
            . ($this->presets === []
                ? ' — no presets are configured'
                : ' — configured presets are "' . implode('", "', array_keys($this->presets)) . '"'),
        );
    }

    public function has(string $name): bool
    {
        return isset($this->presets[$name]);
    }

    /**
     * @return list<non-empty-string>
     */
    public function names(): array
    {
        return array_keys($this->presets);
    }
}
