<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCgi;

final class EntradasCgiTest extends TestCase
{
    public function testBackupViaHttpRecusaAntesDeLerConfiguracaoOuCriarArquivo(): void
    {
        $r = ExecutorCgi::executar(dirname(__DIR__, 2) . '/scripts/deploy/db_backup.php');
        self::assertSame(1, $r['codigo']);
        self::assertStringContainsString('Status: 404', $r['stdout']);
        self::assertSame('', $r['stderr']);
    }
    public function testPaginaPublicaRedirecionaParaOUsuarioConfiguradoDoBot(): void
    {
        $r = ExecutorCgi::executar(dirname(__DIR__, 2) . '/index.php', ['DEALNI_BOT_USERNAME' => 'MeuProjetoBot']);
        self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertSame('', $r['stderr']);
        self::assertStringContainsString('Status: 302', $r['stdout']);
        self::assertStringContainsString('Location: https://t.me/MeuProjetoBot', $r['stdout']);
    }
    public function testPaginaPublicaRecusaUsuarioAusenteOuInvalido(): void
    {
        foreach ([false, '@MeuProjetoBot', 'outro/path'] as $username) {
            $r = ExecutorCgi::executar(dirname(__DIR__, 2) . '/index.php', ['DEALNI_BOT_USERNAME' => $username]);
            self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertSame('', $r['stderr']);
            self::assertStringContainsString('Status: 404', $r['stdout']);
            self::assertStringNotContainsString('Location:', $r['stdout']);
        }
    }
    public function testCicloWebAbreAutoloadEDevolveCorrelacaoSemBanco(): void
    {
        $r = ExecutorCgi::executar(dirname(__DIR__, 2) . '/inclusos/ciclo_web.php', ['HTTP_X_REQUEST_ID' => 'request-fixo-do-teste']);
        self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertSame('', $r['stderr']);
        self::assertStringContainsString('X-Request-Id: request-fixo-do-teste', $r['stdout']);
    }
    public function testFalhaDeBancoNoWebhookProduzErroHttpSemIniciarRoteamento(): void
    {
        $r = ExecutorCgi::executar(dirname(__DIR__, 2) . '/bot_web.php', [
            'TELEGRAM_WEBHOOK_SECRET' => 'segredo-webhook-falso-0123456789abcd',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'segredo-webhook-falso-0123456789abcd',
            'TEST_DATABASE_DSN' => 'pgsql:host=/caminho-inexistente-do-teste;dbname=chatbots_test_ausente',
        ]);
        self::assertSame(1, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertStringContainsString('Status: 500', $r['stdout']);
        self::assertStringContainsString('Erro ao conectar ao banco de dados', $r['stdout']);
        self::assertStringNotContainsString('Fatal error', $r['stdout']);
    }
    public function testRedisIndisponivelNoWebhookResponde503AntesDeAceitarUpdate(): void
    {
        $banco = (string) tempnam(sys_get_temp_dir(), 'cgi_redis_');
        try {
            \BancoTeste::conectar($banco)->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
            $segredo = 'segredo-webhook-falso-0123456789abcd';
            $r = ExecutorCgi::executar(dirname(__DIR__, 2) . '/bot_web.php', [
                'TELEGRAM_WEBHOOK_SECRET' => $segredo, 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $segredo,
                'TEST_DATABASE_DSN' => \BancoTeste::dsn($banco), 'REDIS_HOST' => '/redis-inexistente-da-fixture', 'REDIS_PORT' => '1',
            ]);
            self::assertSame(1, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertStringContainsString('Status: 503', $r['stdout']);
            self::assertStringContainsString('Serviço Redis não está disponível', $r['stdout']);
        } finally {
            unlink($banco);
        }
    }

}
