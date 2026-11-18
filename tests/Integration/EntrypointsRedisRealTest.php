<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCurlIsolado;

final class EntrypointsRedisRealTest extends TestCase
{
    private string $banco;
    private string $diretorio;
    private string $socket;
    protected function setUp(): void
    {
        $this->diretorio = sys_get_temp_dir() . '/entrada_redis_' . bin2hex(random_bytes(8));
        mkdir($this->diretorio, 0700);
        $this->banco = $this->diretorio . '/banco';
        \BancoTeste::conectar($this->banco)->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $socket = getenv('CHATBOTS_REDIS_TEST_SOCKET');
        self::assertIsString($socket, 'Use tests/Support/com_redis_local.sh para preparar a infraestrutura descartável.');
        self::assertStringStartsWith('/tmp/chatbots-redis-teste.', $socket);
        $this->socket = $socket;
        $redis = new \Redis();
        self::assertTrue($redis->connect($socket));
        self::assertTrue($redis->flushDB());
        $redis->close();
    }
    protected function tearDown(): void
    {
        $arquivos = glob($this->diretorio . '/*');
        foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->diretorio);
    }
    /** @return array<string, string> */
    private function ambiente(): array
    {
        return ['REDIS_HOST' => 'unix://' . $this->socket, 'REDIS_PORT' => '1', 'REDIS_PASSWORD' => '', 'TEST_DATABASE_DSN' => \BancoTeste::dsn($this->banco), 'CHATBOTS_ENTRY_ROOT' => dirname(__DIR__, 2)];
    }
    /** @return array<string, mixed> */
    private function update(): array
    {
        return ['update_id' => 901, 'message' => ['message_id' => 10, 'from' => ['id' => 4321, 'first_name' => 'Pessoa Teste'], 'chat' => ['id' => 4321, 'type' => 'private'], 'text' => '/start']];
    }
    /**
     * @param array<string, mixed> $payload
     * @return array{codigo: int, stdout: string, stderr: string, requisicoes: array<mixed>, http: int}
     */
    private function webhook(array $payload): array
    {
        $script = $this->diretorio . '/webhook.php';
        $status = $this->diretorio . '/status';
        file_put_contents($script, <<<'RUNNER'
<?php
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = getenv('TELEGRAM_WEBHOOK_SECRET');
http_response_code(200);
register_shutdown_function(static function (): void { file_put_contents(getenv('CHATBOTS_HTTP_STATUS'), (string) http_response_code()); });
require getenv('CHATBOTS_ENTRY_ROOT') . '/bot_web.php';
RUNNER);
        $r = ExecutorCurlIsolado::executar($script, ['ok' => true, 'result' => ['message_id' => 20]], 200, $this->ambiente() + [
            'CHATBOTS_HTTP_TEST_BODY' => json_encode($payload, JSON_THROW_ON_ERROR), 'CHATBOTS_HTTP_STATUS' => $status,
            'TELEGRAM_WEBHOOK_SECRET' => 'segredo-webhook-falso-0123456789abcd',
        ]);
        $bruto = file_get_contents($status);
        self::assertIsString($bruto);
        return [...$r, 'http' => (int) $bruto];
    }
    public function testWebhookAceitoPersisteAntesDoAckEDeduplicaReentrega(): void
    {
        $primeiro = $this->webhook($this->update());
        self::assertSame(0, $primeiro['codigo'], $primeiro['stdout'] . $primeiro['stderr']);
        self::assertSame(200, $primeiro['http']);
        self::assertCount(1, $primeiro['requisicoes']);
        $segundo = $this->webhook($this->update());
        self::assertSame(0, $segundo['codigo'], $segundo['stdout'] . $segundo['stderr']);
        self::assertSame(200, $segundo['http']);
        self::assertSame([], $segundo['requisicoes']);
        $pdo = \BancoTeste::conectar($this->banco);
        $stmt = $pdo->query('SELECT COUNT(*) FROM usuarios WHERE id_telegram = 4321');
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        self::assertSame(1, $stmt->fetchColumn());
        $stmt = $pdo->query('SELECT status FROM webhook_inbox WHERE update_id = 901');
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        self::assertSame('processado', $stmt->fetchColumn());
    }
    public function testWebhookSemIdRecusaEInboxIndisponivelNaoConfirmaRecebimento(): void
    {
        $r = $this->webhook([]);
        self::assertSame(400, $r['http']);
        self::assertSame([], $r['requisicoes']);
        \BancoTeste::conectar($this->banco)->exec('DROP TABLE webhook_inbox');
        $r = $this->webhook($this->update());
        self::assertSame(503, $r['http']);
        self::assertSame([], $r['requisicoes']);
    }
    public function testPollingRoteiaUpdateAntesDeConfirmarOffset(): void
    {
        $r = ExecutorCurlIsolado::executar(dirname(__DIR__, 2) . '/bot.php', [], 200, $this->ambiente() + [
            'CHATBOTS_CURL_TEST_RESPONSES' => json_encode([
                ['ok' => true, 'result' => [$this->update()]],
                ['ok' => true, 'result' => ['message_id' => 20]],
                ['encerrar' => true],
                ['ok' => true, 'result' => []],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertCount(4, $r['requisicoes']);
        $opcoes = $r['requisicoes'][2];
        self::assertIsArray($opcoes);
        $parametros = $opcoes[CURLOPT_POSTFIELDS];
        self::assertIsArray($parametros);
        self::assertSame(902, $parametros['offset']);
    }
    public function testPollingDeixaExcecaoForaDoBancoSairComRastroSemConfirmarOffset(): void
    {
        // O atendimento do privado lança antes de terminar, e a exceção não é falha de banco:
        // ela sai sem tratamento, com o rastro, e o offset do update continua
        // pendente.
        // Sem resposta simulada para o envio, o transporte lança.
        $update = $this->update();
        $r = ExecutorCurlIsolado::executar(dirname(__DIR__, 2) . '/bot.php', [], 200, $this->ambiente() + [
            'CHATBOTS_CURL_TEST_RESPONSES' => json_encode([['ok' => true, 'result' => [$update]]], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(255, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertStringContainsString('PHP Fatal error:  Uncaught ', $r['stderr']);
        self::assertStringContainsString('/roteador.php(', $r['stderr']);
        self::assertNotEmpty($r['requisicoes']);
        foreach ($r['requisicoes'] as $opcoes) {
            self::assertIsArray($opcoes);
            $parametros = $opcoes[CURLOPT_POSTFIELDS] ?? [];
            self::assertNotSame(902, is_array($parametros) ? ($parametros['offset'] ?? null) : null);
        }
    }
    public function testWorkerDaInboxVaziaPreservaBancoESinalizaZeroProcessados(): void
    {
        $r = ExecutorCurlIsolado::executar(dirname(__DIR__, 2) . '/processos/processar_webhook_inbox.php', ['ok' => true], 200, $this->ambiente());
        self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertStringContainsString('Inbox: 0 processado(s), 0 falha(s)', $r['stdout']);
        self::assertSame([], $r['requisicoes']);
    }

    /** @return array<string, array{string}> */
    public static function entradasComBanco(): array
    {
        return ['polling' => ['bot.php'], 'worker' => ['processos/processar_webhook_inbox.php']];
    }

    #[DataProvider('entradasComBanco')]
    public function testFalhaDeBancoEncerraEntradaSemRespostaAoUsuario(string $entrada): void
    {
        $r = ExecutorCurlIsolado::executar(dirname(__DIR__, 2) . '/' . $entrada, ['ok' => true], 200, array_replace($this->ambiente(), [
            'TEST_DATABASE_DSN' => 'pgsql:host=/banco-inexistente-da-fixture;dbname=chatbots_test_ausente',
            'CHATBOTS_CURL_TEST_RESPONSES' => json_encode([['ok' => true, 'result' => [$this->update()]], ['ok' => true, 'result' => []]], JSON_THROW_ON_ERROR),
        ]));
        self::assertSame(1, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertStringContainsString('Erro ao conectar ao banco de dados', $r['stdout']);
        if ($entrada !== 'bot.php') {
            self::assertSame([], $r['requisicoes']);

            return;
        }
        // A falha do banco atravessa o helper de polling antes de
        // ecoarEEncerrar(), então o shutdown não confirma o offset 902: a
        // única requisição é o getUpdates que trouxe o update, ainda no
        // offset 0, e o Telegram o reentrega quando o processo voltar.
        self::assertCount(1, $r['requisicoes']);
        $opcoes = $r['requisicoes'][0];
        self::assertIsArray($opcoes);
        $parametros = $opcoes[CURLOPT_POSTFIELDS];
        self::assertIsArray($parametros);
        self::assertSame(0, $parametros['offset']);
    }
}
