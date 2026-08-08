<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSkip([
        // Rewrites `$x === null` into `!$x instanceof Foo`, dragging
        // fully-qualified vendor names into code that already reads clearly.
        FlipTypeControlToUseExclusiveTypeRector::class,
    ]);
