<?php

$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                         => true,
        'strict_param'                   => true,
        'declare_strict_types'           => true,
        'array_syntax'                   => ['syntax' => 'short'],
        'no_unused_imports'              => true,
        'ordered_imports'                => true,
        'trailing_comma_in_multiline'    => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
