<?php

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/config/bundles.php',
        __DIR__.'/config/reference.php',
        // Géré par la recipe phpunit/phpunit : éviter les diffs à chaque recipe:update
        __DIR__.'/tests/bootstrap.php',
    ])
    ->withPhpSets()
    ->withPreparedSets(symfonyCodeQuality: true)
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withAttributesSets(all: true)
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withCodeQualityLevel(0);
