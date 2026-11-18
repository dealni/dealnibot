<?php

declare(strict_types=1);

$cacheDirectory = __DIR__ . '/.artifacts/quality/cache';
if (!is_dir($cacheDirectory) && !mkdir($cacheDirectory, 0777, true) && !is_dir($cacheDirectory)) {
    throw new RuntimeException("Não foi possível criar {$cacheDirectory}.");
}

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    // tools/quality/semgrep/project/fixtures guarda violações de propósito, uma por regra do
    // Semgrep; formatá-las não muda nada e mexer nelas quebraria o autoteste
    // das regras. tools/phpcpd/vendor é vendor de ferramenta, como o outro.
    //
    // .artifacts contém caches e relatórios gerados, fora do código formatado.
    ->exclude([
        'vendor',
        'logs',
        'notas_emitidas',
        'tools/quality/semgrep/project/fixtures',
        'tools/phpcpd/vendor',
        '.artifacts',
    ])
    // config.php é um arquivo local ignorado pelo Git que contém credenciais;
    // `exclude` só cobre diretórios, então arquivos sensíveis entram em
    // `notPath` para nunca serem lidos pelo fixer nem aparecerem em diffs.
    ->notPath('inclusos/config.php')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setCacheFile($cacheDirectory . '/php-cs-fixer.cache')
    ->setParallelConfig(new PhpCsFixer\Runner\Parallel\ParallelConfig(2))
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
    ])
    ->setFinder($finder);
