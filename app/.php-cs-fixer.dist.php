<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/migrations')
    ->exclude('var')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony'                       => true,
        '@Symfony:risky'                 => true,
        '@PHP83Migration'                => true,
        '@PHP80Migration:risky'          => true,

        // Imports
        'ordered_imports'                => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'              => true,
        'global_namespace_import'        => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],

        // Arrays
        'array_syntax'                   => ['syntax' => 'short'],
        'trailing_comma_in_multiline'    => ['elements' => ['arrays', 'arguments', 'parameters']],

        // Strings
        'explicit_string_variable'       => true,

        // Classes / methods
        'final_class'                    => false,
        'self_accessor'                  => true,
        'no_superfluous_phpdoc_tags'     => ['allow_mixed' => true],
        'phpdoc_align'                   => false,

        // Strict
        'declare_strict_types'           => true,
        'strict_param'                   => true,
        'strict_comparison'              => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;
