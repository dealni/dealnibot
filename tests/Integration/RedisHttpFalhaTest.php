<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisHttpFalhaTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function falhasRedis(): array
    {
        return [
            'conexao falsa' => ['return false;', 'return true;'],
            'conexao lança' => ['throw new \\RuntimeException("conexao");', 'return true;'],
            'ping falso' => ['return true;', 'return false;'],
            'ping lança' => ['return true;', 'throw new \\RuntimeException("ping");'],
        ];
    }

    #[DataProvider('falhasRedis')]
    public function testFalhaAntesDaPersistenciaRecusaEntregaHttp(string $conexao, string $ping): void
    {
        // Subprocesso sem ext-redis, com logger inerte e rede isolada pelo runner.
        // O status inicial representa a resposta padrão do webhook; exit(1)
        // sozinho não o altera, embora sinalize erro corretamente ao cron.
        $script = <<<'PHP'
<?php
namespace App\Support {
    class LoggerFactory {
        public static function channel(string $canal, bool $forcar): object {
            return new class () { public function log(string $nivel, string $mensagem, array $contexto = []): void {} };
        }
    }
}
namespace {
    class Redis {
        public function connect(...$args): bool { CONEXAO }
        public function auth($senha): bool { return true; }
        public function ping(): bool { PING }
    }
    require CAMINHO;
    http_response_code(200);
    register_shutdown_function(static function (): void {
        echo "\nHTTP=" . http_response_code();
    });
    conectarRedis();
    echo "PERSISTENCIA_ALCANCADA";
}
PHP;
        $script = strtr($script, [
            'CONEXAO' => $conexao,
            'PING' => $ping,
            'CAMINHO' => var_export(dirname(__DIR__, 2) . '/inclusos/funcoes.php', true),
        ]);
        $arquivo = tempnam(sys_get_temp_dir(), 'dealni_redis_http_');
        self::assertIsString($arquivo);

        try {
            self::assertNotFalse(file_put_contents($arquivo, $script));
            // Algumas distribuições carregam ctype dinamicamente. Apenas ela
            // é necessária ao helper; ext-redis continua fora do subprocesso.
            exec(escapeshellarg(PHP_BINARY) . ' -n -r '
                . escapeshellarg('echo extension_loaded("ctype") ? "sim" : "nao";'), $ctype, $statusCtype);
            self::assertSame(0, $statusCtype);
            $extensao = $ctype === ['sim'] ? '' : ' -d extension=ctype';
            exec(escapeshellarg(PHP_BINARY) . ' -n' . $extensao . ' '
                . escapeshellarg($arquivo) . ' 2>&1', $linhas, $codigo);
        } finally {
            unlink($arquivo);
        }

        $saida = implode("\n", $linhas);
        self::assertSame(1, $codigo, $saida);
        self::assertStringContainsString('HTTP=503', $saida);
        self::assertStringNotContainsString('PERSISTENCIA_ALCANCADA', $saida);
    }
}
