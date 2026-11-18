<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisSemExtensaoTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function cenarios(): iterable
    {
        foreach (['sucesso', 'conexao_false', 'conexao_excecao', 'ping_false', 'ping_excecao', 'auth_false'] as $cenario) {
            foreach ([false, true] as $persistente) {
                yield $cenario . ($persistente ? ' persistente' : ' normal') => [$cenario, $persistente];
            }
        }
    }

    #[DataProvider('cenarios')]
    public function testClienteNativoAplicaAutenticacaoEInterrompeFalhas(string $cenario, bool $persistente): void
    {
        $script = tempnam(sys_get_temp_dir(), 'redis_sem_ext_');
        self::assertIsString($script);
        file_put_contents($script, <<<'RUNNER'
<?php
$raiz = $argv[1];
spl_autoload_register(static function (string $classe) use ($raiz): void {
    foreach (['App\\' => '/src/', 'Monolog\\' => '/vendor/monolog/monolog/src/Monolog/', 'Psr\\Log\\' => '/vendor/psr/log/src/'] as $prefixo => $pasta) {
        if (str_starts_with($classe, $prefixo)) { require $raiz . $pasta . str_replace('\\', '/', substr($classe, strlen($prefixo))) . '.php'; return; }
    }
});
class Redis {
    public array $chamadas = [];
    public function connect(...$args): bool {
        $this->chamadas[] = 'connect';
        if ($GLOBALS['argv'][2] === 'conexao_excecao') { throw new RuntimeException('conexão da fixture'); }
        return $GLOBALS['argv'][2] !== 'conexao_false';
    }
    public function pconnect(...$args): bool { $r = $this->connect(...$args); $this->chamadas[0] = 'pconnect'; return $r; }
    public function auth($senha): bool { $this->chamadas[] = 'auth'; return $GLOBALS['argv'][2] !== 'auth_false'; }
    public function ping(): bool {
        $this->chamadas[] = 'ping';
        if ($GLOBALS['argv'][2] === 'ping_excecao') { throw new RuntimeException('ping da fixture'); }
        return $GLOBALS['argv'][2] !== 'ping_false';
    }
}
putenv('REDIS_PASSWORD=segredo-sintetico');
require $raiz . '/inclusos/funcoes.php';
iniciarLoggerCli();
$redis = conectarRedis($argv[3] === '1');
echo json_encode(['trabalho' => true, 'chamadas' => $redis->chamadas]);
RUNNER);
        try {
            $probe = proc_open([PHP_BINARY, '-n', '-r', 'echo json_encode(get_loaded_extensions());'], [1 => ['pipe', 'w']], $pipes);
            self::assertIsResource($probe);
            $carregadas = json_decode((string) stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            fclose($pipes[1]);
            self::assertSame(0, proc_close($probe));
            self::assertIsArray($carregadas);
            $extras = [];
            foreach (['ctype', 'mbstring', 'json'] as $extensao) {
                if (!in_array($extensao, $carregadas, true)) {
                    $extras[] = '-d';
                    $extras[] = 'extension=' . $extensao;
                }
            }
            $processo = proc_open([PHP_BINARY, '-n', ...$extras, $script, dirname(__DIR__, 2), $cenario, $persistente ? '1' : '0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($processo);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($processo);
            self::assertSame($cenario === 'sucesso' ? 0 : 1, $status, $stdout . $stderr);
            self::assertStringNotContainsString('segredo-sintetico', $stdout . $stderr);
            if ($cenario === 'sucesso') {
                self::assertSame(['trabalho' => true, 'chamadas' => [$persistente ? 'pconnect' : 'connect', 'auth', 'ping']], json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
            } else {
                self::assertStringContainsString('Serviço Redis não está', $stdout);
                self::assertStringNotContainsString('"trabalho"', $stdout);
                self::assertStringContainsString('erros_redis', $stderr);
                $evento = json_decode(trim($stderr), true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($evento);
                self::assertSame('error', $evento['level']);
                self::assertIsArray($evento['context']);
                $fase = str_starts_with($cenario, 'ping') ? 'ping' : ($cenario === 'auth_false' ? 'auth' : 'connect');
                self::assertSame($fase, $evento['context']['phase']);
            }
        } finally {
            unlink($script);
        }
    }
}
