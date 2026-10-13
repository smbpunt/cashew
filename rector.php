<?php

use Rector\Config\RectorConfig;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;

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
        // Des services que le container peut rendre lazy : un proxy lazy ne peut pas étendre une classe readonly
        ReadOnlyClassRector::class => [__DIR__.'/src/Twig/Components'],
    ])
    ->withPhpSets()
    ->withPreparedSets(symfonyCodeQuality: true)
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withAttributesSets(all: true)
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withCodeQualityLevel(0);
