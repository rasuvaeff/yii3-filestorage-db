<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Bench\BenchmarkPlugin;

// Command tests assert phrases of SymfonyStyle blocks, which wrap at the
// terminal width: a narrower console split one across two lines. One fixed
// width for every run, whatever runs it.
putenv('COLUMNS=120');

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: new FinderConfig(include: ['tests'], exclude: ['tests/Integration']),
        ),
        new SuiteConfig(
            name: 'Integration',
            location: new FinderConfig(include: ['tests/Integration']),
        ),
        new SuiteConfig(
            name: 'Benchmarks',
            location: new FinderConfig(include: ['benchmarks']),
            plugins: [new BenchmarkPlugin()],
        ),
    ],
);
