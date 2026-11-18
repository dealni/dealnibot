<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCurlIsolado;

/** O subprocesso substitui cURL e herda o bloqueio de rede da suíte. */
final class ConfiguracaoWebhookTransporteTest extends TestCase
{
    private const CONFIGURACAO = ['DEALNI_PUBLIC_BASE_URL' => 'https://bot.example.com/'];

    /** @return array<string, array{string}> */
    public static function scripts(): array
    {
        return ['registro' => ['config_webhook'], 'remoção' => ['config_remover']];
    }
    #[DataProvider('scripts')]
    public function testConfiguracaoUsaPostTlsETimeouts(string $script): void
    {
        $r = ExecutorCurlIsolado::executar(dirname(__DIR__, 2) . '/config/' . $script . '.php', ['ok' => true, 'result' => true], 200, self::CONFIGURACAO);
        self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertCount(1, $r['requisicoes']);
        $opcoes = $r['requisicoes'][0];
        self::assertIsArray($opcoes);
        self::assertTrue($opcoes[CURLOPT_POST]);
        self::assertFalse($opcoes[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($opcoes[CURLOPT_RETURNTRANSFER]);
        self::assertTrue($opcoes[CURLOPT_NOSIGNAL]);
        self::assertTrue($opcoes[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $opcoes[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(5, $opcoes[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(15, $opcoes[CURLOPT_TIMEOUT]);
        if ($script === 'config_webhook') {
            $campos = $opcoes[CURLOPT_POSTFIELDS];
            self::assertIsArray($campos);
            self::assertSame('https://bot.example.com/bot_web.php', $campos['url']);
            self::assertIsString($campos['secret_token']);
            self::assertGreaterThanOrEqual(32, strlen($campos['secret_token']));
        } else {
            self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $opcoes);
        }
    }
    #[DataProvider('scripts')]
    public function testRecusaDoTelegramRetornaFalhaSemAnunciarSucesso(string $script): void
    {
        $r = ExecutorCurlIsolado::executar(dirname(__DIR__, 2) . '/config/' . $script . '.php', ['ok' => false, 'description' => 'token inválido'], 401, self::CONFIGURACAO);
        self::assertSame(1, $r['codigo']);
        self::assertStringContainsString('token inválido', $r['stderr']);
        self::assertStringNotContainsString('sucesso', $r['stdout']);
        self::assertCount(1, $r['requisicoes']);
    }

    public function testRegistroSemUrlPublicaNaoChamaOTelegram(): void
    {
        $r = ExecutorCurlIsolado::executar(
            dirname(__DIR__, 2) . '/config/config_webhook.php',
            ['ok' => true, 'result' => true],
            200,
            ['DEALNI_PUBLIC_BASE_URL' => false]
        );
        self::assertSame(1, $r['codigo']);
        self::assertStringContainsString('Configure DEALNI_PUBLIC_BASE_URL', $r['stderr']);
        self::assertSame([], $r['requisicoes']);
    }
}
