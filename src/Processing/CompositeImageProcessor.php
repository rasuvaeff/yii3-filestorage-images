<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;

/**
 * Tries each configured processor in order and uses the first that supports
 * the source/output pair.
 *
 * The order is a preference, not a race: {@see fromAvailable()} puts Imagick
 * first because it auto-orients and handles more of the format matrix, with GD
 * as the fallback that is compiled into almost every PHP.
 *
 * @api
 */
final readonly class CompositeImageProcessor implements ImageProcessorInterface
{
    /**
     * @param list<ImageProcessorInterface> $processors
     */
    public function __construct(
        private array $processors,
    ) {}

    /**
     * Builds the composite from whatever image extensions this runtime loaded.
     * An empty result is legal — it supports nothing and says so — because
     * "install the package first, add ext-gd when the first preset appears"
     * is a normal adoption order.
     *
     * @param int<0, max> $maxSourceBytes Zero means no cap.
     * @param int<0, max> $maxSourcePixels Zero means no cap.
     */
    public static function fromAvailable(
        StreamFactoryInterface $streams,
        int $maxSourceBytes = 67_108_864,
        int $maxSourcePixels = 40_000_000,
    ): self {
        $processors = [];
        if (\extension_loaded('imagick')) {
            $processors[] = new ImagickImageProcessor(
                streams: $streams,
                maxSourceBytes: $maxSourceBytes,
                maxSourcePixels: $maxSourcePixels,
            );
        }
        if (\extension_loaded('gd')) {
            $processors[] = new GdImageProcessor(
                streams: $streams,
                maxSourceBytes: $maxSourceBytes,
                maxSourcePixels: $maxSourcePixels,
            );
        }

        return new self($processors);
    }

    #[Override]
    public function supports(string $sourceMediaType, Format $output): bool
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($sourceMediaType, $output)) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function process(StreamInterface $source, string $sourceMediaType, Preset $preset): ProcessedImage
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($sourceMediaType, $preset->format)) {
                return $processor->process($source, $sourceMediaType, $preset);
            }
        }

        throw new UnsupportedSourceException(
            $this->processors === []
                ? 'No image processor is available — load ext-imagick or ext-gd'
                : "No available image processor can turn \"{$sourceMediaType}\" into \"{$preset->format->value}\"",
        );
    }
}
