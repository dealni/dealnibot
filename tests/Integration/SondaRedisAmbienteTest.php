<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SondaRedisAmbienteTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    /** Porta padrão do Redis deste bot (PORTA_PADRAO_DO_REDIS_DA_SONDA). */
    private const PORTA_PADRAO = 6380;
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    /** @return iterable<string, array{string, bool}> */
    public static function cenarios(): iterable
    {
        foreach (['sucesso', 'conexao_false', 'conexao_excecao', 'ping_false', 'ping_excecao', 'auth_false', 'option_serializer_false', 'option_retries_false'] as $cenario) {
            foreach ([false, true] as $padrao) {
                yield $cenario . ($padrao ? ' sem configuração' : ' configurado') => [$cenario, $padrao];
            }
        }
    }

    #[DataProvider('cenarios')]
    public function testProntidaoValidaDependenciaSemLerCredenciaisDoBot(string $cenario, bool $padrao): void
    {
        $script = tempnam(sys_get_temp_dir(), 'sonda_redis_');
        self::assertIsString($script);
        file_put_contents($script, <<<'RUNNER'
<?php
$raiz = $argv[1];
spl_autoload_register(static function (string $classe) use ($raiz): void {
    if (str_starts_with($classe, 'App\\')) { require $raiz . '/src/' . str_replace('\\', '/', substr($classe, 4)) . '.php'; }
});
class Redis {
    public const OPT_SERIALIZER = 1;
    public const OPT_MAX_RETRIES = 11;
    public const SERIALIZER_NONE = 0;
    public function connect(...$args): bool {
        $GLOBALS['enderecoSonda'] = [$args[0], $args[1], $args[2], $args[5]];
        if ($GLOBALS['argv'][2] === 'conexao_excecao') { throw new RuntimeException('conexão da fixture'); }
        return $GLOBALS['argv'][2] !== 'conexao_false';
    }
    public function auth($senha): bool { return $GLOBALS['argv'][2] !== 'auth_false'; }
    public function setOption($opcao, $valor): bool {
        return !(($opcao === self::OPT_SERIALIZER && $GLOBALS['argv'][2] === 'option_serializer_false') || ($opcao === self::OPT_MAX_RETRIES && $GLOBALS['argv'][2] === 'option_retries_false'));
    }
    public function ping(): bool {
        if ($GLOBALS['argv'][2] === 'ping_excecao') { throw new RuntimeException('ping da fixture'); }
        return $GLOBALS['argv'][2] !== 'ping_false';
    }
    public function close(): bool { $GLOBALS['sondaFechada'] = true; return true; }
}
$GLOBALS['sondaFechada'] = false;
foreach (['REDIS_HOST' => 'fixture.invalid', 'REDIS_PORT' => '6400', 'REDIS_PASSWORD' => 'senha-da-fixture'] as $nome => $valor) { putenv($argv[3] === '1' ? $nome : $nome . '=' . $valor); }
require $raiz . '/inclusos/funcoes_sondas.php';
$resposta = App\Support\Sonda::pronto(['redis' => sondaRedisResponde(...)]);
echo json_encode(['status' => $resposta['status'], 'checks' => $resposta['corpo']['checks'], 'endereco' => $GLOBALS['enderecoSonda'], 'fechado' => $GLOBALS['sondaFechada'], 'tokenCarregado' => defined('TOKEN_BOT')]);
RUNNER);
        try {
            $probe = proc_open([PHP_BINARY, '-n', '-r', 'exit(extension_loaded("ctype") ? 0 : 1);'], [1 => ['file', '/dev/null', 'w']], $pipes);
            self::assertIsResource($probe);
            $ctype = proc_close($probe) === 0 ? [] : ['-d', 'extension=ctype'];
            $comando = [PHP_BINARY, '-n', ...$ctype, $script, dirname(__DIR__, 2), $cenario, $padrao ? '1' : '0'];
            $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($processo);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($processo), $stdout . $stderr);
            self::assertSame('', $stderr);
            $ok = $cenario === 'sucesso' || ($padrao && $cenario === 'auth_false');
            self::assertSame(['status' => $ok ? 200 : 503, 'checks' => ['redis' => $ok ? 'ok' : 'erro'],
                'endereco' => [$padrao ? '127.0.0.1' : 'fixture.invalid', $padrao ? self::PORTA_PADRAO : 6400, 2, 2],
                'fechado' => true, 'tokenCarregado' => false], json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            unlink($script);
        }
    }
}
