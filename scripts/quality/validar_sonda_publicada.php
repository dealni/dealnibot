<?php

declare(strict_types=1);

// Valida a resposta HTTP obtida pelo verificador, sem abrir banco ou rede.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$argumentos = is_array($_SERVER['argv'] ?? null)
    ? array_values(array_filter($_SERVER['argv'], 'is_string')) : [];
if (count($argumentos) !== 5 || !in_array($argumentos[4], ['health', 'ready'], true)) {
    fwrite(STDERR, "Informe arquivo, serviço, versão e tipo da sonda.\n");
    exit(2);
}
try {
    $corpo = file_get_contents($argumentos[1]);
    $dados = is_string($corpo) ? json_decode($corpo, true, 32, JSON_THROW_ON_ERROR) : null;
    if (!is_array($dados) || ($dados['status'] ?? null) !== 'ok'
        || ($dados['service'] ?? null) !== $argumentos[2]
        || ($dados['version'] ?? null) !== $argumentos[3]) {
        throw new RuntimeException('Identidade, versão ou estado da sonda divergente.');
    }
    if ($argumentos[4] === 'ready') {
        $checks = $dados['checks'] ?? null;
        if (!is_array($checks) || ($checks['postgresql'] ?? null) !== 'ok'
            || ($checks['redis'] ?? null) !== 'ok'
            || count($checks) !== 2) {
            throw new RuntimeException('Prontidão não confirmou PostgreSQL e Redis.');
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "Resposta da sonda inválida, divergente ou sem dependências prontas.\n");
    exit(1);
}
