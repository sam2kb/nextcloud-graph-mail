<?php

declare(strict_types=1);

$root = dirname(__DIR__);
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules(['@PSR12' => true, 'no_multiple_statements_per_line' => true])
    ->setCacheFile($root . '/.cache/php-cs-fixer.cache')
    ->setFinder(PhpCsFixer\Finder::create()->in([$root . '/lib', $root . '/tests', $root . '/appinfo']));
