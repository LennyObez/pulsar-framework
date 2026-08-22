<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/config')
    ->in(__DIR__ . '/extensions')
    // These three were outside the finder entirely, so the framework's own tooling
    // and benchmarks had never been held to PER-CS while everything they check was.
    ->in(__DIR__ . '/tools')
    ->in(__DIR__ . '/scripts')
    ->in(__DIR__ . '/benchmarks')
    // Declaration files: their shape mirrors the extensions they describe, and
    // reformatting them would obscure the diff against upstream.
    ->exclude('php/stubs')
    ->exclude('cache')
    ->exclude('Unit/Integrity/Fixture')
    ->exclude('dev')
    ->exclude('frontend')
    ->notPath('vendor');

return (new PhpCsFixer\Config)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        // PHP 8.5 migration ruleset (nests @PHP8x4Migration and every earlier one,
        // so listing the older sets would be redundant). The un-suffixed
        // @PHP85Migration/@PHP84Migration aliases are deprecated for removal in 4.0.
        '@PHP8x5Migration' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => true, 'import_constants' => true],
    ])
    ->setFinder($finder);
