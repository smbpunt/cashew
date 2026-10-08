<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
        // Gérés par les recipes Symfony : éviter les diffs à chaque recipe:update
        'public/index.php',
        'tests/bootstrap.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PHP8x4Migration' => true,
        '@PHP8x4Migration:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;
