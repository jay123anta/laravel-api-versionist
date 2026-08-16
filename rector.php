<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Rules target PHP 8.1 — the package minimum while Laravel 10 is supported.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withPhpSets(php81: true)
    ->withDeadCodeLevel(10);
