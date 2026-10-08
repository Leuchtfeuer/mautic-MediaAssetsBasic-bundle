<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('vendor')
    ->append([__DIR__.'/.php-cs-fixer.php']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony'               => true,
        'binary_operator_spaces' => [
            'operators' => [
                '=>' => 'align',
                '='  => 'align',
            ],
        ],
        'phpdoc_to_comment' => false,
        'ordered_imports'   => true,
        'array_syntax'      => [
            'syntax' => 'short',
        ],
        'no_unused_imports'     => true,
        'no_alternative_syntax' => false,
        'header_comment'        => [
            'header' => '',
        ],
        'multiline_whitespace_before_semicolons'           => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'new_with_parentheses'                             => [
            'anonymous_class' => false,
        ],
        'no_superfluous_phpdoc_tags'                       => [
            'allow_mixed' => true,
        ],
    ])
    ->setFinder($finder);
