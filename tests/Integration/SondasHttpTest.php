<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

/**
 * Os dois entrypoints das sondas, executados de verdade.
 *
 * `health.php` e `ready.php` são a superfície HTTP publicada pelo Nginx; o que
 * este arquivo prova é o contrato do CORPO e a diferença de comportamento entre
 * os dois — a decisão de status já é exercitada, sem I/O, em
 * `SondaSaudeEProntidaoTest`, e as dependências reais em
 * `SondasDeDependenciaTest`.
 */
final class SondasHttpTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const NOME_DO_SERVICO = 'dealnibot';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHealthContinuaVivoSemBanco(): void
    {
        putenv('TEST_DATABASE_DSN=' . sys_get_temp_dir() . '/inexistente-' . uniqid() . '.db');
        $corpo = $this->executar('health.php');

        $this->assertSame('ok', $corpo['status']);
        $this->assertSame(self::NOME_DO_SERVICO, $corpo['service']);
        $this->assertArrayNotHasKey('checks', $corpo);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReadyReprovaDependenciasIndisponiveis(): void
    {
        putenv('TEST_DATABASE_DSN=' . sys_get_temp_dir() . '/inexistente-' . uniqid() . '.db');
        $corpo = $this->executar('ready.php');

        $this->assertSame('erro', $corpo['status']);
        $this->assertSame(['postgresql' => 'erro', 'redis' => 'erro'], $corpo['checks']);
    }

    /** @return iterable<string, array{bool}> */
    public static function cabecalhos(): iterable
    {
        yield 'resposta antes da saída' => [false];
        yield 'saída iniciada' => [true];
    }

    /**
     * `Sonda::responder()` precisa entregar JSON válido mesmo quando a saída já
     * começou (o status HTTP não pode mais ser trocado) e mesmo com UTF-8
     * inválido no corpo — o substituto U+FFFD sai no lugar, nunca uma resposta
     * vazia.
     */
    #[DataProvider('cabecalhos')]
    public function testRespostaPreservaJsonMesmoAposEnvioDeCabecalho(bool $saidaIniciada): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'sonda_resposta_');
        file_put_contents($script, <<<'RUNNER'
<?php
require $argv[1] . '/vendor/autoload.php';
if ($argv[2] === '1') { echo "prefixo\n"; }
App\Support\Sonda::responder(['status' => 503, 'corpo' => ['status' => 'erro', 'checks' => ['redis' => 'erro'], 'nome' => "invalido\xff"]]);
fwrite(STDERR, json_encode(http_response_code()));
RUNNER);
        try {
            $r = ExecutorCli::executar($script, [dirname(__DIR__, 2), $saidaIniciada ? '1' : '0']);
            self::assertSame(0, $r['codigo']);
            self::assertSame($saidaIniciada ? 'false' : '503', $r['stderr']);
            $corpo = $saidaIniciada ? substr($r['stdout'], strlen("prefixo\n")) : $r['stdout'];
            self::assertSame(['status' => 'erro', 'checks' => ['redis' => 'erro'], 'nome' => "invalido\u{FFFD}"], json_decode($corpo, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            unlink($script);
        }
    }

    /** @return array<string, mixed> */
    private function executar(string $arquivo): array
    {
        ob_start();
        require dirname(__DIR__, 2) . '/' . $arquivo;
        $saida = ob_get_clean();
        $this->assertIsString($saida);
        $corpo = json_decode($saida, true);
        $this->assertIsArray($corpo);

        $normalizado = [];
        foreach ($corpo as $chave => $valor) {
            $this->assertIsString($chave);
            $normalizado[$chave] = $valor;
        }

        return $normalizado;
    }
}
