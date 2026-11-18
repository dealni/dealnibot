<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/postgres_migrate.php';

try {
    exit(migrarPostgres(array_slice(ConsultaBanco::argumentos(), 1)));
} catch (Throwable $erro) {
    fwrite(STDERR, $erro->getMessage() . PHP_EOL);
    exit(1);
}
