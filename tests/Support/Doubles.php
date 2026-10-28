<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImageProcessorInterface;
use Rasuvaeff\Yii3FilestorageImages\Processing\ProcessedImage;
use Yiisoft\Mutex\MutexFactoryInterface;
use Yiisoft\Mutex\MutexInterface;

use function Rasuvaeff\Understudy\when;

/**
 * Understudy doubles shared by the Derivatives, Command and Processing suites.
 *
 * @internal
 */
final class Doubles
{
    /**
     * A processor whose answer encodes the preset, so a rendition's stored
     * bytes identify the recipe that produced them ("processed:thumb-v1").
     */
    public static function processor(bool $supports = true): ImageProcessorInterface
    {
        $processor = Understudy::for(ImageProcessorInterface::class);
        when(fn() => $processor->supports(Arg::any(), Arg::any()))->returns($supports);
        when(fn() => $processor->process(Arg::any(), Arg::any(), Arg::any()))
            ->answers(static function (Invocation $call): ProcessedImage {
                $preset = $call->arg('preset');

                if (!$preset instanceof Preset) {
                    throw new \RuntimeException('the process() double expected a Preset');
                }

                return new ProcessedImage(
                    stream: (new Psr17Factory())->createStream("processed:{$preset->name}-v{$preset->version}"),
                    width: 10,
                    height: 5,
                );
            });

        return $processor;
    }

    /**
     * @return array{MutexFactoryInterface, MutexInterface} The factory and the
     *         mutex every create() hands out, so a test can verify releases.
     */
    public static function mutexes(bool $acquirable = true): array
    {
        $mutex = Understudy::for(MutexInterface::class);
        when(fn() => $mutex->acquire(Arg::any()))->returns($acquirable);

        $factory = Understudy::for(MutexFactoryInterface::class);
        when(fn() => $factory->create(Arg::any()))->returns($mutex);

        return [$factory, $mutex];
    }
}
