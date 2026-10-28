<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([AddNameToLiteralArgumentRector::class])
    ->withSkip([
        // Every throw here already passes `previous:`; what the rule adds on
        // top is `code: $e->getCode()` — a behaviour change (ImagickException
        // codes leaking into ProcessingFailedException) that psalm also
        // rejects, since getCode() is int|string. Same skip as the *-db
        // packages, for the same reason.
        ThrowWithPreviousExceptionRector::class,
    ]);
