<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';
require_once __DIR__ . '/../../inclusos/funcoes_polling.php';

final class PollingLoopTest extends TestCase
{
    /**
     * @param array<int, array{ok: bool, result?: mixed, description?: string, error_code?: int, parameters?: array<string, mixed>}> $respostas
     */
    private function duble(array $respostas): TelegramDublePolling
    {
        $telegram = new TelegramDublePolling('123456:FAKE_TOKEN', false);
        $telegram->respostas = $respostas;

        return $telegram;
    }

    public function testBotUsaLoopContinuoEmVezDaConfirmacaoAntecipadaLegada(): void
    {
        $codigo = file_get_contents(__DIR__ . '/../../bot.php');
        $this->assertIsString($codigo);

        $this->assertStringContainsString('executarLongPollingTelegram(', $codigo);
        $this->assertStringNotContainsString('$telegram->getUpdates();', $codigo);
    }

    public function testOffsetSoAvancaNaRequisicaoSeguinteDepoisDeTodoOLote(): void
    {
        $telegram = $this->duble([
            [
                'ok' => true,
                'result' => [
                    ['update_id' => 10, 'message' => ['text' => 'a']],
                    ['update_id' => 11, 'message' => ['text' => 'b']],
                ],
            ],
            ['ok' => true, 'result' => []],
        ]);
        $processados = [];
        $chamadasAoProcessar = [];

        executarLongPollingTelegram(
            $telegram,
            static function (Telegram $update) use (&$processados, &$chamadasAoProcessar, $telegram): void {
                $processados[] = $update->UpdateID();
                $chamadasAoProcessar[] = count($telegram->chamadas);
            },
            static function (int $_segundos): void {
            },
            2,
            30
        );

        $this->assertSame([10, 11], $processados);
        // Durante os dois callbacks só a primeira busca aconteceu.
        $this->assertSame([1, 1], $chamadasAoProcessar);
        $this->assertSame([
            ['offset' => 0, 'limit' => 100, 'timeout' => 30],
            ['offset' => 12, 'limit' => 100, 'timeout' => 30],
        ], array_column($telegram->chamadas, 'content'));
    }

    public function testExcecaoNoMeioDoLoteNaoEnviaOffsetDeConfirmacao(): void
    {
        $telegram = $this->duble([
            [
                'ok' => true,
                'result' => [
                    ['update_id' => 20, 'message' => ['text' => 'a']],
                    ['update_id' => 21, 'message' => ['text' => 'b']],
                ],
            ],
        ]);
        $processados = [];

        try {
            executarLongPollingTelegram(
                $telegram,
                static function (Telegram $update) use (&$processados): void {
                    $processados[] = $update->UpdateID();
                    if ($update->UpdateID() === 21) {
                        throw new RuntimeException('falha no roteador');
                    }
                },
                static function (int $_segundos): void {
                },
                2
            );
            $this->fail('A exceção do processamento deveria escapar.');
        } catch (RuntimeException $e) {
            $this->assertSame('falha no roteador', $e->getMessage());
        }

        $this->assertSame([20, 21], $processados);
        $this->assertCount(1, $telegram->chamadas);
        $this->assertSame(0, $telegram->chamadas[0]['content']['offset']);
    }

    /**
     * Guarda de sanidade do próprio helper: `maxCiclos` zero ou negativo (só
     * usado pelos testes) devolve o controle sem nenhuma chamada à Bot API.
     */
    public function testMaxCiclosNaoPositivoNaoChamaAApi(): void
    {
        $telegram = $this->duble([['ok' => true, 'result' => []]]);

        executarLongPollingTelegram(
            $telegram,
            static function (Telegram $_update): void {
            },
            static function (int $_segundos): void {
            },
            0
        );

        $this->assertSame([], $telegram->chamadas);
    }

    /**
     * Update sem `update_id` é pulado sem processar e sem envenenar o offset:
     * confirmar um lote a partir de um id ausente descartaria mensagens
     * legítimas que ainda nem chegaram.
     */
    public function testUpdateSemIdEhPuladoSemAvancarOOffset(): void
    {
        $telegram = $this->duble([
            [
                'ok' => true,
                'result' => [
                    ['message' => ['text' => 'sem update_id']],
                ],
            ],
            ['ok' => true, 'result' => []],
        ]);
        $processados = 0;

        executarLongPollingTelegram(
            $telegram,
            static function (Telegram $_update) use (&$processados): void {
                $processados++;
            },
            static function (int $_segundos): void {
            },
            2
        );

        $this->assertSame(0, $processados);
        $this->assertSame(
            [0, 0],
            array_column(array_column($telegram->chamadas, 'content'), 'offset')
        );
    }

    public function testFalhaDaApiEsperaERetentaSemAvancarOffset(): void
    {
        $telegram = $this->duble([
            ['ok' => false, 'description' => 'temporariamente indisponível'],
            ['ok' => true, 'result' => []],
        ]);
        $esperas = [];

        executarLongPollingTelegram(
            $telegram,
            static function (Telegram $_update): void {
            },
            static function (int $segundos) use (&$esperas): void {
                $esperas[] = $segundos;
            },
            2
        );

        $this->assertSame([1], $esperas);
        $this->assertSame([0, 0], array_column(array_column($telegram->chamadas, 'content'), 'offset'));
    }

    public function testExitDeScriptRoteadoConfirmaOOffsetDoUpdate(): void
    {
        $telegram = $this->duble([
            ['ok' => true, 'result' => [['update_id' => 10, 'message' => ['text' => 'a']]]],
            ['ok' => true, 'result' => []],
        ]);

        // Portador tipado, e não `use (&$var)`: a análise estática congela o
        // tipo inicial de uma variável por referência e passa a tratar a
        // chamada abaixo como código morto.
        $capturado = new class () {
            /** @var (callable(): void)|null */
            public $encerramento = null;

            /** @var array<int, array{api: string, content: array<string, mixed>, post: bool}>|null */
            public ?array $chamadasNoExit = null;
        };

        executarLongPollingTelegram(
            $telegram,
            static function (Telegram $_update) use ($capturado, $telegram): void {
                // Simula o `exit` de um comando roteado: na produção o processo
                // morreria aqui e só o encerramento ainda rodaria.
                if ($capturado->encerramento !== null) {
                    ($capturado->encerramento)();
                }
                $capturado->chamadasNoExit = $telegram->chamadas;
            },
            static function (int $_segundos): void {
            },
            1,
            30,
            static function (callable $encerramento) use ($capturado): void {
                $capturado->encerramento = $encerramento;
            }
        );

        // Sem esta confirmação o Telegram reentrega o update 10 no restart e o
        // efeito colateral do comando acontece de novo, ciclo após ciclo.
        $this->assertIsArray($capturado->chamadasNoExit);
        $this->assertCount(2, $capturado->chamadasNoExit);
        $this->assertSame(
            ['offset' => 11, 'limit' => 1, 'timeout' => 0],
            $capturado->chamadasNoExit[1]['content']
        );
    }

    public function testRetornoNormalNaoConfirmaNoEncerramento(): void
    {
        $telegram = $this->duble([
            ['ok' => true, 'result' => [['update_id' => 30, 'message' => ['text' => 'a']]]],
        ]);

        $capturado = new class () {
            /** @var (callable(): void)|null */
            public $encerramento = null;
        };

        executarLongPollingTelegram(
            $telegram,
            static function (Telegram $_update): void {
            },
            static function (int $_segundos): void {
            },
            1,
            30,
            static function (callable $encerramento) use ($capturado): void {
                $capturado->encerramento = $encerramento;
            }
        );

        $this->assertNotNull($capturado->encerramento);
        $chamadasAntes = count($telegram->chamadas);
        if ($capturado->encerramento !== null) {
            ($capturado->encerramento)();
        }

        // O `finally` do laço desligou o encerramento: retorno normal não
        // confirma por fora — quem confirma é o getUpdates seguinte.
        $this->assertCount($chamadasAntes, $telegram->chamadas);
    }

    public function testEncerramentoSoConfirmaQuandoNaoHouveErroFatal(): void
    {
        $this->assertFalse(pollingDeveConfirmarNoEncerramento(null, null));
        $this->assertTrue(pollingDeveConfirmarNoEncerramento(11, null));

        // Aviso antigo não é queda: `error_get_last()` guarda o último erro de
        // qualquer nível, e só o tipo separa saída limpa de morte no meio.
        $this->assertTrue(pollingDeveConfirmarNoEncerramento(11, ['type' => E_WARNING]));
        $this->assertTrue(pollingDeveConfirmarNoEncerramento(11, ['type' => E_NOTICE]));
        $this->assertTrue(pollingDeveConfirmarNoEncerramento(11, ['type' => E_DEPRECATED]));

        $this->assertFalse(pollingDeveConfirmarNoEncerramento(11, ['type' => E_ERROR]));
        $this->assertFalse(pollingDeveConfirmarNoEncerramento(11, ['type' => E_PARSE]));
        $this->assertFalse(pollingDeveConfirmarNoEncerramento(11, ['type' => E_CORE_ERROR]));
        $this->assertFalse(pollingDeveConfirmarNoEncerramento(11, ['type' => E_COMPILE_ERROR]));
        $this->assertFalse(pollingDeveConfirmarNoEncerramento(11, ['type' => E_USER_ERROR]));
        $this->assertFalse(pollingDeveConfirmarNoEncerramento(11, ['type' => E_RECOVERABLE_ERROR]));
    }

    public function testRoteadorNaoEncerraOProcesso(): void
    {
        // Tokeniza, não executa: incluir o roteador dispararia efeito real.
        $codigo = file_get_contents(__DIR__ . '/../../roteador.php');
        $this->assertIsString($codigo);

        $encerramentos = [];
        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && $token[0] === T_EXIT) {
                $encerramentos[] = $token[2];
            }
        }

        $this->assertSame(
            [],
            $encerramentos,
            'roteador.php não pode chamar exit/die: sob polling o processo é '
            . 'reaproveitado entre updates, e sair aqui deixa o update sem '
            . 'confirmação — o Telegram reentrega o mesmo update no restart.'
        );
    }
}

/**
 * @phpstan-import-type TelegramResponse from \Telegram
 */
final class TelegramDublePolling extends Telegram
{
    /** @var array<int, TelegramResponse> */
    public array $respostas = [];

    /** @var array<int, array{api: string, content: array<string, mixed>, post: bool}> */
    public array $chamadas = [];

    /**
     * @param string $api
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function endpoint(string $api, array $content, bool $post = true): array
    {
        $this->chamadas[] = ['api' => $api, 'content' => $content, 'post' => $post];
        $resposta = array_shift($this->respostas);

        return $resposta ?? ['ok' => true, 'result' => []];
    }
}
