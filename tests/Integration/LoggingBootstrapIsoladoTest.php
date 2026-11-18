<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

final class LoggingBootstrapIsoladoTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function entradas(): array
    {
        return ['CLI' => ['cli'], 'log forçado' => ['forcado'], 'Telegram' => ['telegram'], 'Telegram antes da configuração' => ['telegram_precoce']];
    }

    #[DataProvider('entradas')]
    public function testEntradaCarregaLoggerQuandoAutoloadAindaNaoFoiRegistrado(string $entrada): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'logger_bootstrap_');
        file_put_contents($script, <<<'RUNNER'
<?php
$raiz = $argv[1];
if ($argv[2] !== 'telegram_precoce') { define('LOGS_ATIVO', true); }
if (str_starts_with($argv[2], 'telegram')) {
    require $raiz . '/inclusos/TelegramErrorLogger.php';
    if (!defined('LOGS_ATIVO')) { define('LOGS_ATIVO', true); }
    TelegramErrorLogger::log(['ok' => false, 'description' => ['inválida'], 'error_code' => ['inválido']]);
} else {
    require $raiz . '/inclusos/funcoes.php';
    if ($argv[2] === 'cli') { iniciarLoggerCli(); }
    else { escreverLog('bootstrap isolado', 'teste_bootstrap', true); }
}
echo class_exists(App\Support\LoggerFactory::class, false) ? 'carregado' : 'ausente';
RUNNER);
        try {
            $r = ExecutorCli::executar($script, [dirname(__DIR__, 2), $entrada]);
            self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertSame('carregado', $r['stdout']);
            self::assertStringNotContainsString('Fatal error', $r['stderr']);
        } finally {
            unlink($script);
        }
    }

    public function testLoggerTelegramSemVendorNaoDerrubaQuemChamou(): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'logger_vendor_');
        file_put_contents($script, <<<'RUNNER'
<?php
function is_file(string $arquivo): bool {
    return str_ends_with($arquivo, '/vendor/autoload.php') ? false : (new SplFileInfo($arquivo))->isFile();
}
define('LOGS_ATIVO', true);
require $argv[1] . '/inclusos/TelegramErrorLogger.php';
TelegramErrorLogger::log(['ok' => false, 'description' => 'erro da fixture', 'error_code' => 500]);
echo class_exists(App\Support\LoggerFactory::class, false) ? 'carregado' : 'retornou sem logger';
RUNNER);
        try {
            $r = ExecutorCli::executar($script, [dirname(__DIR__, 2)], [], ['disable_functions' => 'is_file']);
            self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertSame('retornou sem logger', $r['stdout']);
            self::assertSame('', $r['stderr']);
        } finally {
            unlink($script);
        }
    }
}
