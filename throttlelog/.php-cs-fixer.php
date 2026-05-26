<?php

$finder = PhpCsFixer\Finder::create()
    ->in(['src', 'tests']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
