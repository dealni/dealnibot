<?php

declare(strict_types=1);

namespace Tests;

use App\Support\EntregaIaDuravel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppLogReader;

require_once __DIR__ . '/../../inclusos/funcoes_webhook_inbox.php';
require_once __DIR__ . '/../../comandos/funcoes_ia_comuns.php';

final class EntregaIaDuravelTest extends TestCase
{
    private \bdTelegram $bd;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $dsn = \BancoTeste::dsn();
        $this->pdo = \BancoTeste::conectar($dsn);
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->bd = new \bdTelegram($dsn);
        \registrarUpdateWebhook($this->bd, ['update_id' => 71, 'message' => ['text' => 'pergunta']], 100);
    }

    public function testSegundoLimitePreservaRespostaEReplayNaoExecutaIaNovamente(): void
    {
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(-100123);
        $telegram->method('MessageID')->willReturn(10);
        $telegram->expects(self::once())->method('sendMessage')->willReturn(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 59]]);
        $redis = $this->createStub(\Redis::class);
        self::assertSame('falha', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 100, static function () use ($telegram, $redis): void {
            \enviarRespostaIa($telegram, $redis, 'resposta já gerada', static function (int $segundos): void {
                self::fail('Inbox não espera pelo limite; reagenda imediatamente.');
            });
        }, 71, static fn (): int => 100));
        $row = $this->linha();
        self::assertSame('erro', $row['status']);
        self::assertSame('pendente', $row['entrega_ia_estado']);
        self::assertSame(159, $row['disponivel_em']);
        self::assertIsString($row['resposta_ia_json']);
        $snapshot = json_decode($row['resposta_ia_json'], true);
        self::assertIsArray($snapshot);
        self::assertIsArray($snapshot['conteudo']);
        self::assertSame('resposta já gerada', $snapshot['conteudo']['text']);

        $replay = $this->createMock(\Telegram::class);
        $replay->expects(self::once())->method('sendMessage')->with(self::callback(static fn (array $conteudo): bool => $conteudo['text'] === 'resposta já gerada' && $conteudo['chat_id'] === -100123))->willReturn(['ok' => true]);
        self::assertSame('vazio', \processarProximoUpdateWebhook($this->bd, $replay, $redis, 158, static fn () => self::fail('IA não deve ser chamada'), 71, static fn (): int => 158));
        self::assertSame('processado', \processarProximoUpdateWebhook($this->bd, $replay, $redis, 159, static fn () => self::fail('IA não deve ser chamada'), 71, static fn (): int => 159));
        self::assertSame('entregue', $this->linha()['entrega_ia_estado']);
        self::assertNull($this->linha()['resposta_ia_json']);
    }

    public function testLimiteLongoERepetidoNaoBloqueiaNemPerdeCheckpointNaRetencao(): void
    {
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(-100123);
        $telegram->method('MessageID')->willReturn(10);
        $telegram->expects(self::once())->method('sendMessage')->willReturn(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 3600]]);
        $redis = $this->createStub(\Redis::class);
        self::assertSame('falha', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 100, static function () use ($telegram, $redis): void {
            \enviarRespostaIa($telegram, $redis, 'persistida', static fn () => self::fail('Limite longo deve ser reagendado'));
        }, 71, static fn (): int => 100));
        self::assertSame(3700, $this->linha()['disponivel_em']);
        \limparInboxWebhookRetida($this->bd, 100 + 31 * 86400);
        self::assertSame('pendente', $this->linha()['entrega_ia_estado']);
    }

    public function testCrashDuranteEnvioNaoReenviaResultadoAmbiguoNemRegeneraIa(): void
    {
        $telegram = $this->createMock(\Telegram::class);
        $telegram->expects(self::never())->method('sendMessage');
        $redis = $this->createStub(\Redis::class);
        self::assertSame('falha', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 100, static function (): void {
            EntregaIaDuravel::preparar(['chat_id' => -100123, 'text' => 'resposta']);
            throw new \RuntimeException('Simula interrupção depois de preparar envio');
        }, 71, static fn (): int => 100));
        self::assertSame('processado', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 102, static fn () => self::fail('Não deve regenerar'), 71, static fn (): int => 102));
        self::assertSame('indeterminada', $this->linha()['entrega_ia_estado']);
    }

    public function testCrashDepoisDaConfirmacaoNaoDuplicaResposta(): void
    {
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(-100123);
        $telegram->method('MessageID')->willReturn(10);
        $telegram->expects(self::once())->method('sendMessage')->willReturn(['ok' => true]);
        $redis = $this->createStub(\Redis::class);
        self::assertSame('falha', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 100, static function () use ($telegram, $redis): void {
            self::assertTrue(\enviarRespostaIa($telegram, $redis, 'resposta'));
            throw new \RuntimeException('Simula falha antes de confirmar update');
        }, 71, static fn (): int => 100));
        self::assertSame('processado', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 102, static fn () => self::fail('Não deve regenerar'), 71, static fn (): int => 102));
        self::assertSame('entregue', $this->linha()['entrega_ia_estado']);
    }

    public function testRepetidosLimitesPreservamMemoriaAteEntregaSemConsumirNovamente(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(-100123);
        $telegram->method('MessageID')->willReturn(10);
        $telegram->expects(self::exactly(3))->method('sendMessage')->willReturnOnConsecutiveCalls(
            ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 24]],
            ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 59]],
            ['ok' => true],
        );
        self::assertSame('falha', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 100, static function () use ($telegram, $redis): void {
            EntregaIaDuravel::memorizar('memoria', [], 'pergunta', 'resposta', 'espelho');
            \enviarRespostaIa($telegram, $redis, 'resposta');
        }, 71, static fn (): int => 100));
        self::assertFalse($redis->get('memoria'));
        self::assertSame('falha', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 124, static fn () => self::fail('Não deve gerar novamente'), 71, static fn (): int => 124));
        self::assertSame(183, $this->linha()['disponivel_em']);
        self::assertFalse($redis->get('memoria'));
        self::assertSame('processado', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 183, static fn () => self::fail('Não deve gerar novamente'), 71, static fn (): int => 183));
        self::assertSame([['role' => 'user', 'content' => 'pergunta'], ['role' => 'assistant', 'content' => 'resposta']], \lerHistoricoMemoria($redis, 'memoria'));
        self::assertSame('resposta', $redis->get('espelho'));
    }

    public function testLeasePerdidaRecusaCheckpointAntesDoEnvio(): void
    {
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(-100123);
        $telegram->method('MessageID')->willReturn(10);
        $telegram->expects(self::never())->method('sendMessage');
        $redis = $this->createStub(\Redis::class);
        self::assertSame('falha', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 100, function () use ($telegram, $redis): void {
            $this->pdo->exec('UPDATE webhook_inbox SET tentativas = tentativas + 1 WHERE update_id = 71');
            \enviarRespostaIa($telegram, $redis, 'resposta');
        }, 71, static fn (): int => 100));
        self::assertNull($this->linha()['entrega_ia_estado']);
    }

    public function testMigrationAditivaIdempotentePreservaUpdatesAntigos(): void
    {
        $this->pdo->exec('ALTER TABLE webhook_inbox DROP COLUMN resposta_ia_json, DROP COLUMN entrega_ia_estado');
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/postgresql/005_entrega_resposta_ia.sql');
        self::assertIsString($sql);
        $this->pdo->exec($sql);
        $this->pdo->exec($sql);
        self::assertSame('pendente', $this->linha()['status']);
        self::assertNull($this->linha()['resposta_ia_json']);
        self::assertNull($this->linha()['entrega_ia_estado']);
    }

    /** @return array<string, array{array<string, mixed>, array<string, int>}> */
    public static function respostasAmbiguas(): array
    {
        return [
            'timeout' => [['ok' => false, 'error_code' => 28, 'description' => 'token=SEGREDO'], ['codigo_erro' => 28]],
            'timeout com http observado' => [['ok' => false, 'error_code' => 28, 'curl_http_status' => 200, 'curl_primeiro_byte_ms' => 12], ['codigo_erro' => 28]],
            'erro interno' => [['ok' => false, 'error_code' => 500, 'result' => ['chat_id' => -100123]], ['codigo_erro' => 500]],
            'codigo textual' => [['ok' => false, 'error_code' => 'token=SEGREDO'], []],
            'identificador numerico' => [['ok' => false, 'error_code' => 123456789], []],
            'sem diagnostico' => [['ok' => false], []],
        ];
    }

    /** @param array<string, mixed> $resposta
     * @param array<string, int> $diagnostico */
    #[DataProvider('respostasAmbiguas')]
    public function testResultadoAmbiguoPreservaSnapshotSemReenvio(array $resposta, array $diagnostico): void
    {
        $inicioDoLog = AppLogReader::tamanhoAtual();
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(-100123);
        $telegram->method('MessageID')->willReturn(10);
        $telegram->expects(self::once())->method('sendMessage')->willReturn($resposta);
        $redis = $this->createStub(\Redis::class);
        self::assertSame('processado', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 100, static function () use ($telegram, $redis): void {
            self::assertFalse(\enviarRespostaIa($telegram, $redis, 'resposta incerta'));
        }, 71, static fn (): int => 100));
        self::assertSame('indeterminada', $this->linha()['entrega_ia_estado']);
        self::assertIsString($this->linha()['resposta_ia_json']);
        $log = AppLogReader::conteudoDesde($inicioDoLog);
        self::assertStringContainsString('Entrega de IA com resultado indeterminado; não reenviada', $log);
        self::assertStringNotContainsString('resposta incerta', $log);
        self::assertStringNotContainsString('-100123', $log);
        self::assertStringNotContainsString('SEGREDO', $log);
        self::assertStringNotContainsString('123456789', $log);
        $avisos = array_values(array_filter(AppLogReader::linhasJson($log), static fn (array $linha): bool => ($linha['msg'] ?? null) === 'Entrega de IA com resultado indeterminado; não reenviada'));
        self::assertCount(1, $avisos);
        self::assertSame(['update_id' => 71, 'attempt' => 1] + $diagnostico, $avisos[0]['context']);
        self::assertSame('vazio', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 101, static fn () => self::fail('Não deve repetir'), 71, static fn (): int => 101));
        \limparInboxWebhookRetida($this->bd, 100 + 8 * 86400);
        self::assertSame(0, \ConsultaBanco::coluna($this->pdo, 'SELECT count(*) FROM webhook_inbox'));
    }

    public function testLimiteSemRetryAfterPreservaRespostaComEsperaPadrao(): void
    {
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(-100123);
        $telegram->method('MessageID')->willReturn(10);
        $telegram->expects(self::once())->method('sendMessage')->willReturn(['ok' => false, 'error_code' => 429]);
        $redis = $this->createStub(\Redis::class);
        self::assertSame('falha', \processarProximoUpdateWebhook($this->bd, $telegram, $redis, 100, static function () use ($telegram, $redis): void {
            \enviarRespostaIa($telegram, $redis, 'resposta preservada');
        }, 71, static fn (): int => 100));
        self::assertSame(130, $this->linha()['disponivel_em']);
        self::assertSame('pendente', $this->linha()['entrega_ia_estado']);
    }

    /** @return array<string, mixed> */
    private function linha(): array
    {
        $consulta = $this->pdo->query('SELECT * FROM webhook_inbox WHERE update_id = 71');
        self::assertInstanceOf(\PDOStatement::class, $consulta);
        $row = $consulta->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }
}
