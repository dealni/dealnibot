<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/inclusos/ConsultaBanco.php';
require_once dirname(__DIR__, 2) . '/src/Support/RuntimeEnvironment.php';

// Fixture Redis descartável, criada antes do sandbox que bloqueia a rede dos testes.
if (getenv('CHATBOTS_REDIS_TEST_SOCKET') === false || getenv('CHATBOTS_REDIS_TEST_SOCKET') === '') {
    $comandoRedis = ['bash', dirname(__DIR__, 2) . '/tests/Support/com_redis_local.sh', PHP_BINARY, __FILE__, ...array_slice(ConsultaBanco::argumentos(), 1)];
    passthru(implode(' ', array_map(escapeshellarg(...), $comandoRedis)), $statusRedis);
    exit($statusRedis);
}

// A infraestrutura nasce fora do sandbox; os testes só alcançam o socket local.
if (getenv('CHATBOTS_PG_TEST_DSN') === false || getenv('CHATBOTS_PG_TEST_DSN') === '') {
    $comando = ['bash', __DIR__ . '/with_postgres.sh', PHP_BINARY, __FILE__, ...array_slice(ConsultaBanco::argumentos(), 1)];
    passthru(implode(' ', array_map(escapeshellarg(...), $comando)), $statusPostgres);
    exit($statusPostgres);
}

$projectRoot = dirname(__DIR__, 2);
$phpunit = $projectRoot . '/vendor/bin/phpunit';
$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments)) {
    fwrite(STDERR, "Não foi possível ler os argumentos do PHPUnit.\n");
    exit(2);
}

$phpunitArguments = escapeshellarg($phpunit);
foreach (array_slice($arguments, 1) as $argument) {
    if (!is_string($argument)) {
        fwrite(STDERR, "Argumento inválido para o PHPUnit.\n");
        exit(2);
    }

    $phpunitArguments .= ' ' . escapeshellarg($argument);
}

// As diretivas passadas com `-d` valem só para ESTE processo: o filho abaixo
// nasce com o php.ini da máquina. O CI chama este script com
// `-d error_reporting=E_ALL -d display_errors=1 -d display_startup_errors=1`
// justamente para o gate de depreciação enxergar o que a suíte emite, e sem o
// repasse abaixo o gate mediria o php.ini do runner em vez do que foi pedido.
$iniHerdadas = '';
foreach (['error_reporting', 'display_errors', 'display_startup_errors'] as $diretiva) {
    $valor = ini_get($diretiva);
    if (is_string($valor)) {
        $iniHerdadas .= ' -d ' . escapeshellarg($diretiva . '=' . $valor);
    }
}

$command = '';
if (PHP_OS_FAMILY === 'Darwin') {
    $sandbox = '/usr/bin/sandbox-exec';
    if (!is_executable($sandbox)) {
        fwrite(STDERR, "sandbox-exec é necessário para executar testes com a rede isolada.\n");
        exit(2);
    }

    // O runtime é o `php` comum, não mais phpdbg: o PHPUnit 10+ monta o comando
    // do processo-filho de @runInSeparateProcess com um `-qrr s=` que o phpdbg
    // interpreta como nome de arquivo, e o filho morre antes de iniciar. Quem
    // prova o isolamento agora é RuntimeEnvironment::hasNetworkIsolation(),
    // sondando sockets de verdade em vez de olhar o PHP_SAPI.
    putenv('CHATBOTS_OS_NETWORK_SANDBOX=1');
    $profile = '(version 1)(allow default)(deny network*)(allow network-outbound (remote unix-socket))';
    $command = escapeshellarg($sandbox)
        . ' -p ' . escapeshellarg($profile)
        . ' ' . escapeshellarg(PHP_BINARY)
        . $iniHerdadas
        . ' ' . $phpunitArguments;
} elseif (PHP_OS_FAMILY === 'Linux') {
    if (\App\Support\RuntimeEnvironment::hasNetworkIsolation()) {
        $command = escapeshellarg(PHP_BINARY) . $iniHerdadas . ' ' . $phpunitArguments;
    } else {
        $unshare = '/usr/bin/unshare';
        if (!is_executable($unshare)) {
            fwrite(STDERR, "Execute os testes em uma namespace de rede isolada com unshare --net.\n");
            exit(2);
        }

        $command = escapeshellarg($unshare)
            . ' --user --map-root-user --net -- ' . escapeshellarg(PHP_BINARY)
            . $iniHerdadas
            . ' ' . $phpunitArguments;
    }
} else {
    fwrite(STDERR, "Este ambiente não possui um sandbox de rede suportado para os testes.\n");
    exit(2);
}

passthru($command, $status);
exit($status);
