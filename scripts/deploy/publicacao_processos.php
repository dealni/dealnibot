<?php

declare(strict_types=1);

// Inspeção somente leitura de /proc: nunca imprime PID, argumentos ou conteúdo.
$argumentos = $_SERVER['argv'] ?? [];
if (PHP_SAPI !== 'cli' || !is_array($argumentos) || count($argumentos) !== 2
    || !is_string($argumentos[1] ?? null) || !is_dir('/proc/self')) {
    fwrite(STDERR, "A drenagem exige Linux e o caminho do checkout.\n");
    exit(2);
}
$raiz = realpath($argumentos[1]);
if (!is_string($raiz) || $raiz === '/') {
    exit(2);
}
$ancestrais = [];
$pid = getmypid();
while (is_int($pid) && $pid > 1 && !isset($ancestrais[$pid])) {
    $ancestrais[$pid] = true;
    $status = @file_get_contents('/proc/' . $pid . '/status');
    $pid = is_string($status) && preg_match('/^PPid:\s+(\d+)$/m', $status, $match) === 1 ? (int) $match[1] : 0;
}
$quantidade = 0;
$processos = glob('/proc/[0-9]*', GLOB_ONLYDIR);
if (!is_array($processos)) {
    fwrite(STDERR, "Não foi possível inspecionar os processos.\n");
    exit(1);
}
foreach ($processos as $processo) {
    if (isset($ancestrais[(int) basename($processo)])) {
        continue;
    }
    $cwd = @readlink($processo . '/cwd');
    $argumentos = @file_get_contents($processo . '/cmdline');
    if ((is_string($cwd) && ($cwd === $raiz || str_starts_with($cwd, $raiz . '/')))
        || (is_string($argumentos) && str_contains($argumentos, $raiz . '/'))) {
        ++$quantidade;
    }
}
echo $quantidade . PHP_EOL;
