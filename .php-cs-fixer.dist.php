<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->exclude(['Fixtures']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP83Migration' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => false,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'phpdoc_to_comment' => false,
        'yoda_style' => ['equal' => true, 'identical' => true, 'less_and_greater' => null],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache');
