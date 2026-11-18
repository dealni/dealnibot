<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisPhpRedisTest extends TestCase
{
    /** @return array<string, array{bool}> */
    public static function conexoes(): array
    {
        return ['normal' => [false], 'persistente' => [true]];
    }

    #[DataProvider('conexoes')]
    public function testAutenticaAntesDoPingSemAlterarASenha(bool $persistente): void
    {
        $original = getenv('REDIS_PASSWORD');
        $senha = ' senha-artificial $literal ';
        putenv('REDIS_PASSWORD=' . $senha);
        $redis = $this->createMock(Redis::class);
        $autenticado = false;
        $redis->expects(self::once())->method($persistente ? 'pconnect' : 'connect')->willReturn(true);
        $redis->expects(self::once())->method('auth')->with($senha)->willReturnCallback(
            static function () use (&$autenticado): bool {
                $autenticado = true;
                return true;
            }
        );
        $redis->expects(self::once())->method('ping')->willReturnCallback(
            static function () use (&$autenticado): bool {
                self::assertTrue($autenticado);
                return true;
            }
        );
        try {
            self::assertSame($redis, \conectarRedis($persistente, $redis));
        } finally {
            putenv($original === false ? 'REDIS_PASSWORD' : 'REDIS_PASSWORD=' . $original);
        }
    }
}
