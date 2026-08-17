<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

use Override;
use Yiisoft\Mutex\MutexFactory;
use Yiisoft\Mutex\MutexInterface;

/**
 * @internal
 */
final class PredictableMutexFactory extends MutexFactory
{
    /** @var list<string> Every lock name requested. */
    public array $created = [];

    /** @var int<0, max> */
    public int $released = 0;

    public function __construct(
        private readonly bool $acquirable = true,
    ) {}

    #[Override]
    public function create(string $name): MutexInterface
    {
        $this->created[] = $name;
        $factory = $this;

        return new readonly class ($this->acquirable, $factory) implements MutexInterface {
            public function __construct(
                private bool $acquirable,
                private PredictableMutexFactory $factory,
            ) {}

            #[Override]
            public function acquire(int $timeout = 0): bool
            {
                return $this->acquirable;
            }

            #[Override]
            public function release(): void
            {
                $this->factory->released++;
            }
        };
    }
}
