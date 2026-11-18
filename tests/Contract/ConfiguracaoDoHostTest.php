<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ConfiguracaoDoHostTest extends TestCase
{
    private const BOT = 'dealnibot';
    private const PORTA = 6380;

    public function testClienteExemploEPoolUsamAMesmaInstancia(): void
    {
        $original = getenv('REDIS_PORT');
        try {
            putenv('REDIS_PORT');
            self::assertSame(self::PORTA, \enderecoDoRedis()['port']);
        } finally {
            putenv($original === false ? 'REDIS_PORT' : 'REDIS_PORT=' . $original);
        }

        $raiz = dirname(__DIR__, 2);
        $exemplo = file_get_contents($raiz . '/.env.example');
        self::assertIsString($exemplo);
        self::assertMatchesRegularExpression('/^REDIS_PORT=' . self::PORTA . '$/m', $exemplo);
        $pools = parse_ini_file($raiz . '/deploy/php-fpm/' . self::BOT . '.conf', true, INI_SCANNER_RAW);
        self::assertIsArray($pools);
        $pool = $pools[self::BOT];
        self::assertIsArray($pool);
        self::assertSame(self::BOT, $pool['user']);
        self::assertSame(self::BOT, $pool['group']);
        self::assertSame('yes', $pool['clear_env']);
        $ambiente = $pool['env'];
        self::assertIsArray($ambiente);
        self::assertSame('$DEALNI_FPM_REDIS_PORT', $ambiente['REDIS_PORT']);
    }
}
