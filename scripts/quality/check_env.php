<?php

declare(strict_types=1);

require_once __DIR__ . '/env_schema.php';

$root = dirname(__DIR__, 2);
$file = $root . '/.env.example';
$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments)) {
    $arguments = [];
}
foreach (array_slice($arguments, 1) as $argument) {
    if (!is_string($argument)) {
        fwrite(STDERR, "Uso: php scripts/quality/check_env.php [--file=/caminho/environment]\n");
        exit(2);
    }
    if (!str_starts_with($argument, '--file=') || strlen($argument) === strlen('--file=')) {
        fwrite(STDERR, "Uso: php scripts/quality/check_env.php [--file=/caminho/environment]\n");
        exit(2);
    }
    $file = substr($argument, strlen('--file='));
}
$contents = file_get_contents($file);
$schema = require $root . '/config/env.schema.php';

try {
    if (!is_string($contents) || !is_array($schema)) {
        throw new RuntimeException("Não foi possível carregar {$file} e seu schema.");
    }
    $values = parseEnvExample($contents);
    $errors = validateEnvExample($values, $schema);
    if ($errors !== []) {
        throw new RuntimeException(implode(PHP_EOL, $errors));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Contrato de ambiente inválido: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo $file . ' válido: ' . count($values) . ' variável(is).' . PHP_EOL;
