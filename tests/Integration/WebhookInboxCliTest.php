<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/funcoes_webhook_inbox.php';

final class WebhookInboxCliTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function cenarios(): array
    {
        return ['sucesso direto' => ['', false], 'falha direta' => ['roteamento', false],
            'sucesso via aviso' => ['', true], 'falha via aviso' => ['roteamento', true],
            'reserva direta' => ['reserva', false], 'reserva via aviso' => ['reserva', true],
            'recuperação direta' => ['recuperacao', false], 'recuperação via aviso' => ['recuperacao', true]];
    }

    #[DataProvider('cenarios')]
    public function testEntrypointPropagaFalhaEMantemRetryEResumo(string $falha, bool $wrapper): void
    {
        $dsn = \BancoTeste::dsn();
        $pdo = \BancoTeste::conectar($dsn);
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));
        $falhar = $falha !== '';
        if ($falha === 'roteamento') {
            $pdo->exec(<<<'SQL'
                CREATE FUNCTION falhar_fixture() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN RAISE EXCEPTION 'Falha operacional da fixture'; END $$;
                CREATE TRIGGER falha_fixture BEFORE INSERT ON usuarios
                    FOR EACH ROW EXECUTE FUNCTION falhar_fixture();
                SQL);
        }
        $bd = new \bdTelegram($dsn);
        $payload = $falha === 'roteamento' ? ['update_id' => 1, 'message' => ['message_id' => 1,
            'from' => ['id' => 777], 'chat' => ['id' => 777, 'type' => 'private'], 'text' => 'fixture']]
            : ['update_id' => 1];
        self::assertTrue(registrarUpdateWebhook($bd, $payload, 1));
        // Mantém a fixture fora da retenção sem depender do relógio real.
        $pdo->exec('UPDATE webhook_inbox SET recebido_em = 2000000000');
        // A limpeza usa a retenção normal, inclusive com UPDATE da fila falhando.
        $pdo->exec(<<<'SQL'
            INSERT INTO webhook_inbox (update_id,payload_json,status,recebido_em,disponivel_em,processado_em)
                VALUES (999,'{"update_id":999}','processado',1,1,1);
            SQL);
        if ($falha === 'recuperacao') {
            $pdo->exec("UPDATE webhook_inbox SET status = 'processando', tentativa_em = 1, tentativas = 1 WHERE update_id = 1");
        }
        if ($falha === 'reserva' || $falha === 'recuperacao') {
            $pdo->exec(<<<'SQL'
                CREATE FUNCTION falhar_update_fixture() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN RAISE EXCEPTION 'Falha de UPDATE da fixture'; END $$;
                SQL);
            $pdo->exec($falha === 'reserva'
                ? "CREATE TRIGGER falha_reserva BEFORE UPDATE ON webhook_inbox FOR EACH ROW WHEN (NEW.status = 'processando') EXECUTE FUNCTION falhar_update_fixture()"
                : "CREATE TRIGGER falha_recuperacao BEFORE UPDATE ON webhook_inbox FOR EACH ROW WHEN (NEW.status = 'erro') EXECUTE FUNCTION falhar_update_fixture()");
        }
        $raiz = dirname(__DIR__, 2);
        $php = realpath(PHP_BINARY);
        self::assertIsString($php);
        $comando = $wrapper
            ? ['/bin/bash', $raiz . '/scripts/operacao/avisar_se_falhar.sh', 'webhook-inbox', '--php', $raiz . '/processos/processar_webhook_inbox.php']
            : [$php, $raiz . '/processos/processar_webhook_inbox.php'];
        $ambiente = ['PATH' => '/usr/bin:/bin', 'APP_ENV' => 'test', 'CHATBOTS_TESTING' => '1',
            'TEST_DATABASE_DSN' => $dsn, 'DEALNI_DB_USER' => (string) getenv('DEALNI_DB_USER'),
            'DEALNI_DB_PASSWORD' => '', 'PHP85_BIN' => $php, 'DEALNI_ALERTA_CHAT_ID' => '',
            'REDIS_HOST' => 'unix://' . (string) getenv('CHATBOTS_REDIS_TEST_SOCKET'), 'REDIS_PORT' => '1',
            'DEALNI_LOGS_DIR' => (string) getenv('DEALNI_LOGS_DIR')];
        $pipes = [];
        $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz, $ambiente);
        self::assertIsResource($processo);
        $saida = stream_get_contents($pipes[1]);
        $erro = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigo = proc_close($processo);
        self::assertSame($falhar ? 1 : 0, $codigo, (string) $erro);
        self::assertStringContainsString($falhar ? 'Inbox: 0 processado(s), 1 falha(s);' : 'Inbox: 1 processado(s), 0 falha(s);', (string) $saida);
        self::assertStringContainsString('retenção: 1 processado(s), 0 falho(s).', (string) $saida);
        $consulta = $pdo->query('SELECT status, tentativas, disponivel_em, tentativa_em FROM webhook_inbox WHERE update_id = 1');
        self::assertInstanceOf(\PDOStatement::class, $consulta);
        $linha = $consulta->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($linha);
        $status = match ($falha) {
            'reserva' => 'pendente', 'recuperacao' => 'processando', 'roteamento' => 'erro', default => 'processado'
        };
        self::assertSame($status, $linha['status']);
        self::assertSame($falha === 'reserva' ? 0 : 1, $linha['tentativas']);
        if ($falhar) {
            if ($falha === 'roteamento') {
                self::assertGreaterThan($linha['tentativa_em'], $linha['disponivel_em']);
            }
            self::assertStringContainsString('retentativas preservadas', (string) $erro);
            if ($wrapper) {
                self::assertStringContainsString('Aviso de webhook-inbox desligado', (string) $erro);
            }
        } else {
            self::assertStringNotContainsString('Aviso de webhook-inbox desligado', (string) $erro);
        }
    }
}
