<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

final class RedisFalhasInjetadasTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function falhas(): array
    {
        $casos = [];
        foreach ([false, true] as $persistente) {
            foreach (['conexao_false', 'conexao_excecao', 'ping_false', 'ping_excecao'] as $falha) {
                $casos[$falha . ($persistente ? ' persistente' : ' normal')] = [$falha, $persistente];
            }
        }
        return $casos;
    }

    #[DataProvider('falhas')]
    public function testFalhaNoClienteInjetadoEncerraAntesDoTrabalhoDeNegocio(string $falha, bool $persistente): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'redis_falha_injetada_');
        file_put_contents($script, <<<'RUNNER'
<?php
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/inclusos/funcoes.php';
iniciarLoggerCli();
$cliente = new class extends Redis {
    public function connect(...$args): bool {
        if (getenv('CHATBOTS_REDIS_CASE') === 'conexao_excecao') { throw new RuntimeException('conexão da fixture'); }
        return getenv('CHATBOTS_REDIS_CASE') !== 'conexao_false';
    }
    public function pconnect(...$args): bool { return $this->connect(...$args); }
    public function auth(...$args): bool { return true; }
    public function ping(...$args): bool {
        if (getenv('CHATBOTS_REDIS_CASE') === 'ping_excecao') { throw new RuntimeException('ping da fixture'); }
        return false;
    }
};
conectarRedis($argv[2] === '1', $cliente);
echo 'TRABALHO_ALCANCADO';
RUNNER);
        try {
            $r = ExecutorCli::executar($script, [dirname(__DIR__, 2), $persistente ? '1' : '0'], ['CHATBOTS_REDIS_CASE' => $falha]);
            self::assertSame(1, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertStringContainsString('Serviço Redis não está', $r['stdout']);
            self::assertStringNotContainsString('TRABALHO_ALCANCADO', $r['stdout']);
            self::assertStringNotContainsString('Fatal error', $r['stderr']);
            self::assertStringContainsString(str_starts_with($falha, 'ping') ? 'Redis não está respondendo' : ($falha === 'conexao_excecao' ? 'Falha de conexão Redis' : 'Redis recusou a operação'), $r['stderr']);
        } finally {
            unlink($script);
        }
    }
}
