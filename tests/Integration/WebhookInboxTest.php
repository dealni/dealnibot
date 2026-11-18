<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/funcoes_webhook_inbox.php';
require_once __DIR__ . '/../../processos/funcoes_processar_webhook_inbox.php';

final class WebhookInboxTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    /** Prefixo do arquivo temporário que guarda o banco desta suíte. */
    private const PREFIXO_BANCO = 'dealni_inbox_';

    /**
     * Fiação do roteamento deste bot: não há callback. O require do roteador
     * acontece dentro da própria função de processamento, que já carrega o
     * escopo esperado pelos comandos.
     */
    private function asseverarFiacaoDoBot(string $fonte): void
    {
        $this->assertStringNotContainsString('rotear:', $fonte);
        $this->assertStringContainsString('updateId: $updateIdWebhook', $fonte);
    }
    // --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

    private string $banco;
    private \bdTelegram $bd;

    protected function setUp(): void
    {
        $this->banco = (string) tempnam(sys_get_temp_dir(), self::PREFIXO_BANCO);
        $pdo = \BancoTeste::conectar($this->banco, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->bd = new \bdTelegram(\BancoTeste::dsn($this->banco));
    }

    protected function tearDown(): void
    {
        unset($this->bd);
        // O SQLite deixa companheiros do arquivo principal conforme o modo de
        // journal; nenhum deles pode sobrar no diretório temporário.
        foreach (['', '-wal', '-shm', '-journal'] as $sufixo) {
            if (is_file($this->banco . $sufixo)) {
                unlink($this->banco . $sufixo);
            }
        }
    }

    public function testPersisteAntesDoAckDeduplicaEConfirma(): void
    {
        $payload = ['update_id' => 77, 'message' => ['text' => '/start']];
        $this->assertTrue(registrarUpdateWebhook($this->bd, $payload, 100));
        $this->assertTrue(registrarUpdateWebhook($this->bd, $payload, 101));

        $telegram = $this->createMock(\Telegram::class);
        $telegram->expects($this->once())->method('setData')->with($payload);
        $this->assertSame('processado', processarProximoUpdateWebhook(
            $this->bd,
            $telegram,
            $this->createStub(\Redis::class),
            101,
            static function (): void {
            },
            77
        ));
        // Update já confirmado não volta para o roteador nem quando o id é
        // pedido de novo: a reserva por id exige status reaproveitável.
        $this->assertSame('vazio', processarProximoUpdateWebhook(
            $this->bd,
            $telegram,
            $this->createStub(\Redis::class),
            102,
            static function (): void {
            },
            77
        ));
    }

    public function testExcecaoReagendaComBackoffEWorkerRetoma(): void
    {
        $this->assertTrue(registrarUpdateWebhook($this->bd, ['update_id' => 88], 100));
        $telegram = $this->createStub(\Telegram::class);
        $redis = $this->createStub(\Redis::class);
        $this->assertSame('falha', processarProximoUpdateWebhook(
            $this->bd,
            $telegram,
            $redis,
            100,
            static function (): void {
                throw new \RuntimeException('falha controlada');
            },
            relogio: static fn (): int => 100
        ));
        $this->assertNull(pegarProximoUpdateWebhook($this->bd, 101));
        $this->assertSame('processado', processarProximoUpdateWebhook(
            $this->bd,
            $telegram,
            $redis,
            102,
            static function (): void {
            }
        ));
    }

    public function testCadaTentativaRegistraReservaERestauraContextoDoWorker(): void
    {
        \App\Support\ContextoDeExecucao::reset();
        $job = \App\Support\ContextoDeExecucao::requestId();
        $logger = \App\Support\LoggerFactory::channel('webhook_inbox');
        self::assertInstanceOf(\Monolog\Logger::class, $logger);
        $handler = new \Monolog\Handler\TestHandler();
        $logger->pushHandler($handler);
        try {
            foreach ([false, true] as $falhar) {
                $id = $falhar ? 881 : 880;
                self::assertTrue(registrarUpdateWebhook($this->bd, ['update_id' => $id, 'message' => ['text' => 'conteudo privado']], 100));
                $resultado = processarProximoUpdateWebhook(
                    $this->bd,
                    $this->createStub(\Telegram::class),
                    $this->createStub(\Redis::class),
                    100,
                    function () use ($job, $id, $falhar): void {
                        self::assertNotSame($job, \App\Support\ContextoDeExecucao::requestId());
                        self::assertSame(['update_id' => $id, 'attempt' => 1, 'job_id' => $job], \App\Support\ContextoDeExecucao::item());
                        if ($falhar) {
                            throw new \RuntimeException('falha controlada');
                        }
                    }
                );
                self::assertSame($falhar ? 'falha' : 'processado', $resultado);
                self::assertSame($job, \App\Support\ContextoDeExecucao::requestId());
                self::assertSame([], \App\Support\ContextoDeExecucao::item());
            }
            $registros = $handler->getRecords();
            self::assertCount(2, $registros);
            self::assertSame(['update_id' => 880, 'attempt' => 1, 'job_id' => $job], $registros[0]->context);
            self::assertSame(['update_id' => 881, 'attempt' => 1, 'job_id' => $job], $registros[1]->context);
        } finally {
            $logger->popHandler();
            \App\Support\ContextoDeExecucao::reset();
        }
    }

    public function testEntradaInvalidaLeaseEPoliticaDeRetencao(): void
    {
        $this->assertFalse(registrarUpdateWebhook($this->bd, [], 100));
        $this->assertFalse(registrarUpdateWebhook($this->bd, ['update_id' => '1'], 100));
        $this->assertFalse(registrarUpdateWebhook($this->bd, ['update_id' => 1], 0));
        $this->assertTrue(erroFatalNoDesligamento(['type' => E_ERROR]));
        $this->assertFalse(erroFatalNoDesligamento(['type' => E_WARNING]));

        $this->assertTrue(registrarUpdateWebhook($this->bd, ['update_id' => 90], 100));
        $this->assertNotNull(pegarProximoUpdateWebhook($this->bd, 100));
        $this->assertTrue(marcarUpdateWebhookProcessado($this->bd, 90, 100, 1));
        $this->assertSame(['processados' => 1, 'falhas' => 0], limparInboxWebhookRetida(
            $this->bd,
            100 + 8 * 86400
        ));
    }

    public function testWorkerRespeitaLimitesEContaResultados(): void
    {
        $resultados = ['processado', 'falha', 'vazio'];
        $indice = 0;
        $resultado = drenarInboxWebhook(
            $this->bd,
            $this->createStub(\Telegram::class),
            $this->createStub(\Redis::class),
            50,
            100,
            static fn (): int => 100,
            static function () use (&$resultados, &$indice): string {
                return $resultados[$indice++];
            }
        );
        $this->assertSame(['processados' => 1, 'falhas' => 1], $resultado);
    }

    public function testPayloadInvalidoEhReagendadoSemPrenderALease(): void
    {
        $recursivo = ['update_id' => 89];
        $recursivo['self'] = &$recursivo;
        $this->assertFalse(registrarUpdateWebhook($this->bd, $recursivo, 100));

        $pdo = \BancoTeste::conectar($this->banco, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $inserir = $pdo->prepare(
            "INSERT INTO webhook_inbox
            (update_id, payload_json, status, recebido_em, tentativa_em, disponivel_em, tentativas)
            VALUES (:id, :payload, 'processando', 100, 100, 100, 1)"
        );
        $inserir->execute([':id' => 501, ':payload' => '{']);
        $invalido = $pdo->query('SELECT update_id, payload_json, tentativas FROM webhook_inbox WHERE update_id = 501');
        $this->assertInstanceOf(PDOStatement::class, $invalido);
        $this->assertNull(decodificarUpdateWebhook($this->bd, $invalido, 100));

        $inserir->execute([':id' => 502, ':payload' => '{"update_id":999}']);
        $divergente = $pdo->query('SELECT update_id, payload_json, tentativas FROM webhook_inbox WHERE update_id = 502');
        $this->assertInstanceOf(PDOStatement::class, $divergente);
        $this->assertNull(decodificarUpdateWebhook($this->bd, $divergente, 100));

        $malformado = $pdo->query("SELECT 'x' AS update_id, '{}' AS payload_json");
        $this->assertInstanceOf(PDOStatement::class, $malformado);
        $this->assertNull(decodificarUpdateWebhook($this->bd, $malformado, 100));
        $falhos = $pdo->query("SELECT COUNT(*) FROM webhook_inbox WHERE status = 'erro'");
        $this->assertInstanceOf(PDOStatement::class, $falhos);
        $this->assertSame(2, (int) $falhos->fetchColumn());
        $falhos->closeCursor();

        logarFalhaDaInbox('falha coberta', 502);
        $bdFalho = $this->createMock(\bdTelegram::class);
        $bdFalho->expects($this->once())->method('queryExec')->willReturn(false);
        $this->assertFalse(marcarUpdateWebhookFalho($bdFalho, 502, 'sem banco', 100, 1));
        $bdSemConfirmacao = $this->createMock(\bdTelegram::class);
        $bdSemConfirmacao->expects($this->once())->method('queryExec')->willReturn(false);
        finalizarUpdateWebhookNoDesligamento($bdSemConfirmacao, 502, 100, null, 1);

        $this->assertTrue(registrarUpdateWebhook($this->bd, ['update_id' => 601], 100));
        $this->assertNotNull(pegarUpdateWebhookPorId($this->bd, 601, 100));
        finalizarUpdateWebhookNoDesligamento($this->bd, 601, 100, null, 1);
        $processado = $pdo->query('SELECT status FROM webhook_inbox WHERE update_id = 601');
        $this->assertInstanceOf(PDOStatement::class, $processado);
        $this->assertSame('processado', $processado->fetchColumn());
        $processado->closeCursor();

        $this->assertTrue(registrarUpdateWebhook($this->bd, ['update_id' => 602], 100));
        $this->assertNotNull(pegarUpdateWebhookPorId($this->bd, 602, 100));
        finalizarUpdateWebhookNoDesligamento($this->bd, 602, 100, ['type' => E_ERROR], 1);
        $reagendado = $pdo->query('SELECT status FROM webhook_inbox WHERE update_id = 602');
        $this->assertInstanceOf(PDOStatement::class, $reagendado);
        $this->assertSame('erro', $reagendado->fetchColumn());
        $reagendado->closeCursor();
    }

    public function testWebhookGravaAntesDoAckEProcessaDepois(): void
    {
        $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/bot_web.php');
        $persistencia = strpos($fonte, 'registrarUpdateWebhook(');
        $ack = strpos($fonte, '$telegram->respondSuccess()');
        $processamento = strrpos($fonte, 'processarProximoUpdateWebhook(');
        $this->assertIsInt($persistencia);
        $this->assertIsInt($ack);
        $this->assertIsInt($processamento);
        $this->assertLessThan($ack, $persistencia);
        $this->assertLessThan($processamento, $ack);
        $this->asseverarFiacaoDoBot($fonte);
    }

    public function testTentativaExpiradaNaoConfirmaNemReagendaOutraReserva(): void
    {
        $this->assertTrue(registrarUpdateWebhook($this->bd, ['update_id' => 700], 100));
        $antiga = pegarProximoUpdateWebhook($this->bd, 100);
        $nova = pegarProximoUpdateWebhook($this->bd, 461);
        $this->assertNotNull($antiga);
        $this->assertNotNull($nova);
        $this->assertNotSame($antiga['tentativa'], $nova['tentativa']);
        $this->assertFalse(marcarUpdateWebhookProcessado($this->bd, 700, 462, $antiga['tentativa']));
        $this->assertFalse(marcarUpdateWebhookFalho($this->bd, 700, 'atrasada', 462, $antiga['tentativa']));
        $this->assertTrue(marcarUpdateWebhookFalho($this->bd, 700, 'retentar', 463, $nova['tentativa']));
    }

    public function testProcessadorAntigoNaoFinalizaReservaRetomada(): void
    {
        foreach ([false, true] as $falhar) {
            $id = $falhar ? 711 : 710;
            self::assertTrue(registrarUpdateWebhook($this->bd, ['update_id' => $id], 100));
            $nova = null;
            $resultado = processarProximoUpdateWebhook(
                $this->bd,
                $this->createStub(\Telegram::class),
                $this->createStub(\Redis::class),
                100,
                function () use (&$nova, $falhar): void {
                    $nova = pegarProximoUpdateWebhook($this->bd, 461);
                    if ($falhar) {
                        throw new \RuntimeException('worker antigo falhou');
                    }
                },
                $id
            );
            self::assertSame('falha', $resultado);
            self::assertNotNull($nova);
            self::assertSame(2, $nova['tentativa']);
            self::assertTrue(marcarUpdateWebhookProcessado($this->bd, $id, 462, $nova['tentativa']));
        }
    }
}
