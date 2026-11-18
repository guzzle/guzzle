<?php

declare(strict_types=1);

$config = (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP71Migration:risky' => true,
        '@PHPUnit75Migration:risky' => true,
        '@PSR12:risky' => true,
        '@Symfony' => true,
        'global_namespace_import' => false,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
        ],
        'phpdoc_annotation_without_dot' => false,
        'phpdoc_summary' => false,
        'phpdoc_to_comment' => false,
        'single_line_throw' => false,
        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => ['array_destructuring', 'arrays', 'match'],
        ],
        'void_return' => false,
        'yoda_style' => false,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(__DIR__.'/src')
            ->in(__DIR__.'/tests')
            ->name('*.php')
            ->append([__FILE__])
    )
;

return $config;
