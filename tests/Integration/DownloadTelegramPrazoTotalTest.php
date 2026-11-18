<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCurlIsolado;

final class DownloadTelegramPrazoTotalTest extends TestCase
{
    /** @return array<string, array{int, int, int|null, string|false}> */
    public static function respostas(): array
    {
        return [
            'arquivo íntegro' => [200, 0, null, 'abcdef'],
            'teto de bytes' => [200, 0, 4, 'abcd'],
            'teto na fronteira' => [200, 0, 3, 'abc'],
            'arquivo menor que teto' => [200, 0, 10, 'abcdef'],
            'corpo de erro HTTP' => [404, 0, null, false],
            'redirecionamento recusado' => [302, 0, null, false],
            'deadline da transferência' => [200, 28, null, false],
            'timeout após corpo parcial' => [200, -28, 10, false],
            'conexão fechada antes do fim' => [200, -18, 10, false],
        ];
    }

    #[DataProvider('respostas')]
    public function testDownloadTemPrazoTotalERecusaCorpoDeErro(int $http, int $errno, ?int $teto, string|false $esperado): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'dealnibot_download_');
        file_put_contents($script, <<<'RUNNER'
<?php
require getenv('CHATBOTS_ENTRY_ROOT') . '/comandos/funcoes_ia_comuns.php';
$teto = getenv('CHATBOTS_DOWNLOAD_TEST_MAX');
echo json_encode(baixarArquivoDoTelegram('https://api.telegram.org/file/bot123:SEGREDO_TESTE/foto.jpg', $teto === '' ? null : (int) $teto));
RUNNER);
        try {
            $r = ExecutorCurlIsolado::executar($script, [], $http, [
                'CHATBOTS_ENTRY_ROOT' => dirname(__DIR__, 2),
                'CHATBOTS_CURL_TEST_ERRNO' => (string) max(0, $errno),
                'CHATBOTS_CURL_TEST_ERRNO_AFTER_CHUNKS' => (string) max(0, -$errno),
                'CHATBOTS_CURL_TEST_DOWNLOAD_CHUNKS' => '["abc","def"]',
                'CHATBOTS_DOWNLOAD_TEST_MAX' => $teto === null ? '' : (string) $teto,
            ]);
            self::assertSame(0, $r['codigo'], $r['stderr']);
            self::assertSame($esperado, json_decode($r['stdout'], true));
            self::assertStringNotContainsString('SEGREDO_TESTE', $r['stderr']);
            self::assertCount(1, $r['requisicoes']);
            $opcoes = $r['requisicoes'][0];
            self::assertIsArray($opcoes);
            self::assertSame(20, $opcoes[CURLOPT_TIMEOUT]);
            self::assertSame(10, $opcoes[CURLOPT_CONNECTTIMEOUT]);
            self::assertSame(CURL_IPRESOLVE_V4, $opcoes[CURLOPT_IPRESOLVE]);
            self::assertTrue($opcoes[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, $opcoes[CURLOPT_SSL_VERIFYHOST]);
            self::assertFalse($opcoes[CURLOPT_FOLLOWLOCATION]);
        } finally {
            unlink($script);
        }
    }
    /** @return array<string, array{int|null, array<string, string>}> */
    public static function falhasAntesDaTransferencia(): array
    {
        return [
            'teto zero' => [0, []],
            'teto negativo' => [-1, []],
            'inicialização recusada' => [null, ['CHATBOTS_CURL_TEST_INIT_FAIL' => '1']],
            'opções recusadas' => [null, ['CHATBOTS_CURL_TEST_ARRAY_OPTION_FAIL' => '1']],
        ];
    }

    /** @param array<string, string> $ambiente */
    #[DataProvider('falhasAntesDaTransferencia')]
    public function testFalhaDePreparacaoNaoExecutaDownload(?int $teto, array $ambiente): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'dealnibot_download_falha_');
        file_put_contents($script, <<<'RUNNER'
<?php
require getenv('CHATBOTS_ENTRY_ROOT') . '/comandos/funcoes_ia_comuns.php';
$teto = getenv('CHATBOTS_DOWNLOAD_TEST_MAX');
echo json_encode(baixarArquivoTelegramComPrazoTotal('https://api.telegram.org/file/bot123:TESTE/foto.jpg', $teto === '' ? null : (int) $teto, 20));
RUNNER);
        try {
            $resultado = ExecutorCurlIsolado::executar($script, [], 200, [
                ...$ambiente,
                'CHATBOTS_ENTRY_ROOT' => dirname(__DIR__, 2),
                'CHATBOTS_DOWNLOAD_TEST_MAX' => $teto === null ? '' : (string) $teto,
            ]);
            self::assertSame(0, $resultado['codigo'], $resultado['stderr']);
            self::assertSame('false', $resultado['stdout']);
            self::assertSame([], $resultado['requisicoes']);
        } finally {
            unlink($script);
        }
    }

}
