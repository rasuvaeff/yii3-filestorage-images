<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Exception;

use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProcessingFailedException::class)]
final class ProcessingFailedExceptionTest
{
    public function aFailureIsAboutTheFileByDefault(): void
    {
        Assert::false((new ProcessingFailedException('broken'))->isTransient());
    }

    public function aTransientFailureKeepsItsMessageAndCause(): void
    {
        $cause = new \RuntimeException('disk full');

        $failure = ProcessingFailedException::transient('could not spool', $cause);

        Assert::true($failure->isTransient());
        Assert::same($failure->getMessage(), 'could not spool');
        Assert::same($failure->getPrevious(), $cause);
    }
}
