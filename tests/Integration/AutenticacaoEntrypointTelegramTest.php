<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorHttpIsolado;

final class AutenticacaoEntrypointTelegramTest extends TestCase
{
    /** @return array<string, array{array<string, string>}> */
    public static function origensInvalidas(): array
    {
        return ['sem segredo' => [[]], 'segredo incorreto' => [['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'incorreto']]];
    }
    /** @param array<string, string> $servidor */
    #[DataProvider('origensInvalidas')]
    public function testRecusaAntesDeAbrirBancoOuRotearComando(array $servidor): void
    {
        $r = ExecutorHttpIsolado::executar(dirname(__DIR__, 2) . '/bot_web.php', '{"update_id":1,"message":{"text":"/start"}}', $servidor, [
            'TELEGRAM_WEBHOOK_SECRET' => 'segredo-webhook-falso-0123456789abcd',
            'TEST_DATABASE_DSN' => 'pgsql:host=/nao-existe;dbname=chatbots_test_inexistente',
        ]);
        self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertSame(403, $r['status']);
        self::assertSame('', $r['stdout']);
        self::assertStringNotContainsString('banco', strtolower($r['stderr']));
    }
}
