<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class LoggerResilienciaTest extends TestCase
{
    public function testFalhaNaAberturaDoArquivoNaoInterrompeEPermiteRecuperacao(): void
    {
        // O subprocesso captura o STDERR da contingência sem misturá-lo à
        // saída da suíte e permite conferir a recuperação do logger.
        $this->executarCenario(<<<'CODIGO'
$arquivo = $base . '/app-' . date('Y-m-d') . '.log';
if (!mkdir($arquivo)) { exit(3); }
$logger = \App\Support\LoggerFactory::channel('regressao', true);
$logger->error('Regressão sintética: arquivo de log indisponível.');
if (!is_dir($arquivo) || !rmdir($arquivo)) { exit(4); }
$logger->info('destino recuperado');
if (!is_file($arquivo) || !str_contains((string) file_get_contents($arquivo), 'destino recuperado')) { exit(5); }
unlink($arquivo);
echo 'destino recuperado';
CODIGO, false, 'destino recuperado', ['Regressão sintética']);
    }

    public function testLogsAtivosSemDiretorioTambemUsamStderr(): void
    {
        $this->executarCenario(<<<'CODIGO'
file_put_contents($base . '/bloqueio', 'arquivo');
putenv('DEALNI_LOGS_DIR=' . $base . '/bloqueio/subdiretorio');
define('LOGS_ATIVO', true);
$logger = \App\Support\LoggerFactory::channel('regressao');
$logger->error('falha original do Telegram', ['token' => 'segredo-nao-imprimir']);
echo 'destino recuperado';
unlink($base . '/bloqueio');
CODIGO, false, 'destino recuperado', ['falha original do Telegram']);
    }

    public function testProducaoComoRootNaoCriaNemAlteraArquivoDiario(): void
    {
        $this->executarCenario(<<<'CODIGO'
function posix_geteuid(): int { return 0; }
putenv('APP_ENV=production');
putenv('CHATBOTS_TESTING');
$arquivo = $base . '/app-' . date('Y-m-d') . '.log';
file_put_contents($arquivo, 'preservado');
$logger = \App\Support\LoggerFactory::channel('regressao', true);
$logger->error('evento administrativo', ['token' => 'segredo-nao-imprimir']);
if (file_get_contents($arquivo) !== 'preservado') { exit(7); }
unlink($arquivo);
$logger->error('segundo evento administrativo');
if (file_exists($arquivo)) { exit(8); }
echo 'root sem arquivo';
CODIGO, true, 'root sem arquivo', ['evento administrativo', 'segundo evento administrativo']);
    }

    /** @param list<string> $errosEsperados */
    private function executarCenario(string $cenario, bool $simularRoot, string $saidaEsperada, array $errosEsperados): void
    {
        $base = sys_get_temp_dir() . '/logger-resiliencia-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0700));
        $codigo = <<<'CODIGO'
require $argv[1] . '/vendor/autoload.php';
$base = $argv[2];
putenv('DEALNI_LOGS_DIR=' . $base);
CODIGO;
        $comando = [PHP_BINARY];
        if ($simularRoot) {
            $comando[] = '-d';
            $comando[] = 'disable_functions=posix_geteuid';
        }
        array_push($comando, '-r', $codigo . "\n" . $cenario, dirname(__DIR__, 2), $base);
        try {
            $processo = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($processo);
            fclose($pipes[0]);
            $saida = stream_get_contents($pipes[1]);
            $erro = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($processo), (string) $erro);
            self::assertIsString($saida);
            self::assertIsString($erro);
            self::assertStringNotContainsString('segredo-nao-imprimir', $erro);
            self::assertStringContainsString($saidaEsperada, $saida);
            foreach ($errosEsperados as $esperado) {
                self::assertStringContainsString($esperado, $erro);
            }
            self::assertSame([], glob($base . '/*'));
        } finally {
            $arquivos = glob($base . '/*');
            foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
                if (is_dir($arquivo)) {
                    rmdir($arquivo);
                } else {
                    unlink($arquivo);
                }
            }
            rmdir($base);
        }
    }
}
