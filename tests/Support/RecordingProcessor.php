<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImageProcessorInterface;
use Rasuvaeff\Yii3FilestorageImages\Processing\ProcessedImage;

/**
 * @internal
 */
final class RecordingProcessor implements ImageProcessorInterface
{
    /** @var list<array{string, string}> Source media type + preset name per call. */
    public array $calls = [];

    /** @var list<string> What each call was fed. */
    public array $consumed = [];

    public function __construct(
        private readonly bool $supportsEverything = true,
        private readonly Psr17Factory $streams = new Psr17Factory(),
    ) {}

    #[Override]
    public function supports(string $sourceMediaType, Format $output): bool
    {
        return $this->supportsEverything;
    }

    #[Override]
    public function process(StreamInterface $source, string $sourceMediaType, Preset $preset): ProcessedImage
    {
        $this->calls[] = [$sourceMediaType, $preset->name];
        $this->consumed[] = (string) $source;

        return new ProcessedImage(
            stream: $this->streams->createStream("processed:{$preset->name}-v{$preset->version}"),
            width: 10,
            height: 5,
        );
    }
}
