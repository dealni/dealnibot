<?php

declare(strict_types=1);

require_once __DIR__ . '/caminhos.php';
require_once __DIR__ . '/ConsultaBanco.php';
require_once __DIR__ . '/SqlPostgres.php';
if (extension_loaded('pdo_pgsql')) {
    require_once __DIR__ . '/BancoPostgres.php';
}

function dsnBancoDoBot(): string
{
    if (getenv('CHATBOTS_TESTING') === '1' && getenv('APP_ENV') === 'test') {
        $teste = getenv('TEST_DATABASE_DSN');
        if (is_string($teste) && str_starts_with($teste, 'pgsql:')) {
            return $teste;
        }
    }
    $dsn = getenv('DEALNI_DB_DSN');
    if (!is_string($dsn) || trim($dsn) === '') {
        throw new RuntimeException('Defina DEALNI_DB_DSN com uma conexão PostgreSQL.');
    }
    if (!str_starts_with($dsn, 'pgsql:') || preg_match('/(?:user|password)\s*=/i', $dsn) === 1) {
        throw new RuntimeException('DEALNI_DB_DSN exige pgsql: e credenciais separadas.');
    }
    return $dsn;
}

function bancoUsaPostgres(): bool
{
    return str_starts_with(dsnBancoDoBot(), 'pgsql:');
}

function conectarBancoDoBot(?string $dsn = null, int $timeout = 5): PDO
{
    $dsn ??= dsnBancoDoBot();
    if (!str_starts_with($dsn, 'pgsql:') || preg_match('/(?:user|password)\s*=/i', $dsn) === 1) {
        throw new RuntimeException('DEALNI_DB_DSN exige pgsql: e credenciais separadas.');
    }

    if (!extension_loaded('pdo_pgsql')) {
        throw new RuntimeException('Instale a extensão pdo_pgsql do PHP 8.5.');
    }
    $usuario = getenv('DEALNI_DB_USER');
    $senha = getenv('DEALNI_DB_PASSWORD');
    if (getenv('APP_ENV') !== 'test' && (!is_string($usuario) || trim($usuario) === '')) {
        throw new RuntimeException('Defina DEALNI_DB_USER para PostgreSQL.');
    }
    try {
        return new BancoPostgres($dsn . ';connect_timeout=' . max(1, min(30, $timeout)), is_string($usuario) ? $usuario : null, is_string($senha) ? $senha : null);
    } catch (PDOException) {
        // DSN, senha e detalhes do servidor não vão para logs/Telegram.
        throw new RuntimeException('Não foi possível conectar ao PostgreSQL deste bot.');
    }
}
