<?php

declare(strict_types=1);

namespace Tests;

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCurlIsolado;

final class WebhookFastcgiTest extends TestCase
{
    public function testFastcgiTerminaRespostaDepoisDaPersistenciaEAntesDoProcessamento(): void
    {
        $base = sys_get_temp_dir() . '/webhook_fpm_' . bin2hex(random_bytes(8));
        mkdir($base, 0700);
        $banco = $base . '/banco';
        \BancoTeste::conectar($banco)->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $socket = getenv('CHATBOTS_REDIS_TEST_SOCKET');
        self::assertIsString($socket);
        $script = $base . '/entrada.php';
        file_put_contents($script, <<<'RUNNER'
<?php
require getenv('CHATBOTS_ENTRY_ROOT') . '/vendor/autoload.php';
require getenv('CHATBOTS_ENTRY_ROOT') . '/tests/Support/BancoTeste.php';
function fastcgi_finish_request(): bool {
    $pdo = BancoTeste::conectar(getenv('CHATBOTS_FPM_BANK'));
    $consulta = $pdo->query('SELECT status FROM webhook_inbox WHERE update_id = 991');
    file_put_contents(getenv('CHATBOTS_FPM_MARKER'), $consulta->fetchColumn());
    return true;
}
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = getenv('TELEGRAM_WEBHOOK_SECRET');
require getenv('CHATBOTS_ENTRY_ROOT') . '/bot_web.php';
RUNNER);
        try {
            $r = ExecutorCurlIsolado::executar($script, ['ok' => true], 200, [
                'CHATBOTS_ENTRY_ROOT' => dirname(__DIR__, 2), 'CHATBOTS_FPM_BANK' => $banco,
                'CHATBOTS_FPM_MARKER' => $base . '/ack', 'TEST_DATABASE_DSN' => \BancoTeste::dsn($banco),
                'REDIS_HOST' => 'unix://' . $socket, 'REDIS_PORT' => '1', 'REDIS_PASSWORD' => '',
                'TELEGRAM_WEBHOOK_SECRET' => 'segredo-webhook-falso-0123456789abcd',
                'CHATBOTS_HTTP_TEST_BODY' => json_encode(['update_id' => 991, 'channel_post' => ['chat' => ['id' => -991, 'type' => 'channel'], 'text' => 'aviso']], JSON_THROW_ON_ERROR),
            ]);
            self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertSame('pendente', file_get_contents($base . '/ack'));
            self::assertSame([], $r['requisicoes']);
            $consulta = \BancoTeste::conectar($banco)->query('SELECT status FROM webhook_inbox WHERE update_id = 991');
            self::assertInstanceOf(PDOStatement::class, $consulta);
            self::assertSame('processado', $consulta->fetchColumn());
        } finally {
            $arquivos = glob($base . '/*');
            foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
                unlink($arquivo);
            }
            rmdir($base);
        }
    }
}
