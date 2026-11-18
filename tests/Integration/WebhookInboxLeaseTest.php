<?php

declare(strict_types=1);

namespace Tests;

use App\Support\LeaseWebhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/bdTelegram.php';
require_once __DIR__ . '/../../inclusos/Telegram.php';
require_once __DIR__ . '/../../inclusos/funcoes_webhook_inbox.php';
require_once __DIR__ . '/../../comandos/funcoes_ver_audio.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WebhookInboxLeaseTest extends TestCase
{
    private \bdTelegram $bd;

    protected function setUp(): void
    {
        $dsn = \BancoTeste::dsn();
        $pdo = \BancoTeste::conectar($dsn);
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));
        $this->bd = new \bdTelegram($dsn);
        require_once __DIR__ . '/../../inclusos/limites_requisicoes.php';
        require_once __DIR__ . '/../../inclusos/config_loader.php';
        carregarConfiguracaoSensivel();
    }

    /** @return array<string, array{bool}> */
    public static function ordemDasRecusas(): array
    {
        return ['429 antes de formato' => [false], 'formato antes de 429' => [true]];
    }

    #[DataProvider('ordemDasRecusas')]
    public function testAudioCompletoRenovaLeaseEReagenda429SemReexecutarComando(bool $formatoPrimeiro): void
    {
        // Executa o comando real: cada transporte avança o relógio pelo seu
        // teto; 429 libera a inbox e só o envio volta depois do retry_after.
        $timeoutChat = (new \ReflectionFunction('executarRequisicaoOpenAi'))->getParameters()[4]->getDefaultValue();
        self::assertIsInt($timeoutChat);
        $fonteTranscricao = (string) file_get_contents(__DIR__ . '/../../comandos/funcoes_transcricao_audio.php');
        self::assertSame(1, preg_match("/'audio.transcriptions',\\s*(\\d+)/", $fonteTranscricao, $timeout));
        $timeoutTranscricao = (int) $timeout[1];
        $momento = 100;
        $fases = [];
        $avancar = function (string $fase, int $segundos) use (&$momento, &$fases): void {
            $momento += $segundos;
            $fases[] = $fase;
            self::assertNull(pegarProximoUpdateWebhook($this->bd, $momento), $fase);
        };
        $telegram = $this->telegram($avancar, $formatoPrimeiro);
        $payload = ['update_id' => 91, 'message' => [
            'message_id' => 7, 'from' => ['id' => 777],
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
            'voice' => ['file_id' => 'fixture', 'duration' => 3],
        ]];
        self::assertTrue(registrarUpdateWebhook($this->bd, $payload, $momento));
        $resultado = processarProximoUpdateWebhook(
            $this->bd,
            $telegram,
            new Support\RedisFalsoComLua(),
            $momento,
            static function () use ($telegram, $avancar, $timeoutChat, $timeoutTranscricao): void {
                executarComandoVerAudio(
                    $telegram,
                    new Support\RedisFalsoComLua(),
                    static function () use ($avancar, $timeoutTranscricao): array {
                        $avancar('transcrição', $timeoutTranscricao);
                        return ['corpo' => '{"text":"Oi"}', 'http' => 200];
                    },
                    static function () use ($avancar, $timeoutChat): string {
                        $avancar('chat', $timeoutChat);
                        return '{"choices":[{"message":{"content":"Oi *amigo*"}}]}';
                    },
                    static function () use ($avancar): string {
                        $avancar('download', segundosDeDownloadDoTelegram());
                        return 'audio-local';
                    },
                    '01/01/2026 12:00:00',
                    esperar: static function (int $segundos): void {
                        self::fail('Inbox durável não deve esperar no FPM.');
                    }
                );
            },
            relogio: static function () use (&$momento): int {
                return $momento;
            }
        );
        self::assertSame('falha', $resultado, 'A entrega fica reagendada, não concluída.');
        self::assertSame($formatoPrimeiro ? 390 : 375, $momento);
        self::assertNull(pegarProximoUpdateWebhook($this->bd, $momento + 59));
        $momento += 60;
        self::assertSame('processado', processarProximoUpdateWebhook(
            $this->bd,
            $telegram,
            new Support\RedisFalsoComLua(),
            $momento,
            static fn () => self::fail('O replay não pode gerar IA nem consumir cota.'),
            relogio: static function () use (&$momento): int {
                return $momento;
            }
        ));
        $envios = ['sendMessage', 'sendMessage', 'sendMessage'];
        self::assertSame(['getFile', 'download', 'sendChatAction', 'transcrição', 'chat', ...$envios], $fases);
        self::assertNull(pegarProximoUpdateWebhook($this->bd, 1000));
        // O escopo foi limpo: um job/polling seguinte não herda esta reserva.
        LeaseWebhook::renovar();
    }

    /** @param callable(string, int): void $avancar */
    private function telegram(callable $avancar, bool $formatoPrimeiro): \Telegram
    {
        return new class ($avancar, $formatoPrimeiro) extends \Telegram {
            private int $envios = 0;
            /** @param callable(string, int): void $avancar */
            public function __construct(private $avancar, private bool $formatoPrimeiro)
            {
                parent::__construct('000000:TEST_TOKEN', false);
            }
            /** @param array<string, mixed> $content */
            protected function sendAPIRequest(string $url, array $content, bool $post = true): string
            {
                $metodo = basename((string) parse_url($url, PHP_URL_PATH));
                ($this->avancar)($metodo, self::REQUEST_TIMEOUT_SECONDS);
                if ($metodo === 'getFile') {
                    return '{"ok":true,"result":{"file_path":"voice/fixture.oga"}}';
                }
                if ($metodo === 'sendMessage') {
                    $formato = '{"ok":false,"error_code":400,"description":"Bad Request: can\'t parse entities"}';
                    $limite = '{"ok":false,"error_code":429,"parameters":{"retry_after":60}}';
                    return match (++$this->envios) {
                        1 => $this->formatoPrimeiro ? $formato : $limite,
                        2 => $this->formatoPrimeiro ? $limite : $formato,
                        default => '{"ok":true,"result":{"message_id":42}}',
                    };
                }
                return '{"ok":true}';
            }
        };
    }

    public function testRenovacaoExpiradaOuDeTentativaAntigaNaoReviveReserva(): void
    {
        self::assertTrue(registrarUpdateWebhook($this->bd, ['update_id' => 1], 100));
        self::assertNotNull(pegarProximoUpdateWebhook($this->bd, 100));
        self::assertTrue(renovarUpdateWebhook($this->bd, 1, 1, 459));
        self::assertNull(pegarProximoUpdateWebhook($this->bd, 818));
        self::assertFalse(renovarUpdateWebhook($this->bd, 1, 1, 819));
        $nova = pegarProximoUpdateWebhook($this->bd, 819);
        self::assertNotNull($nova);
        self::assertSame(2, $nova['tentativa']);
        self::assertFalse(renovarUpdateWebhook($this->bd, 1, 1, 820));
        self::assertFalse(marcarUpdateWebhookProcessado($this->bd, 1, 820, 1));
        self::assertFalse(marcarUpdateWebhookFalho($this->bd, 1, 'antiga', 820, 1));
        self::assertTrue(marcarUpdateWebhookProcessado($this->bd, 1, 820, 2));
    }

    public function testPerdaDaPosseInterrompeAntesDaProximaChamadaPaga(): void
    {
        $momento = 100;
        self::assertTrue(registrarUpdateWebhook($this->bd, ['update_id' => 2], 100));
        $pagas = 0;
        $nova = null;
        $resultado = processarProximoUpdateWebhook(
            $this->bd,
            $this->createStub(\Telegram::class),
            $this->createStub(\Redis::class),
            100,
            function () use (&$momento, &$nova, &$pagas): void {
                $momento = 460; // Falta de progresso além da margem por fase.
                $nova = pegarProximoUpdateWebhook($this->bd, $momento);
                executarChatCompletionsOpenAi('fixture', 'test-only', '{}', 'fixture', static function () use (&$pagas): string {
                    $pagas++;
                    return '{}';
                });
            },
            relogio: static function () use (&$momento): int {
                return $momento;
            }
        );
        self::assertSame('falha', $resultado);
        self::assertSame(0, $pagas);
        self::assertNotNull($nova);
        self::assertTrue(marcarUpdateWebhookProcessado($this->bd, 2, $momento, $nova['tentativa']));
    }
}
