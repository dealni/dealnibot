<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Contrato do isolamento criado pelo bootstrap da suíte.
 *
 * O run id atravessa processos; os diretórios são próprios de cada processo,
 * salvo quando um filho pede explicitamente para preservar os diretórios
 * herdados. O teste executa o bootstrap real em subprocessos para também
 * verificar o cleanup de encerramento.
 */
final class BootstrapIsolamentoContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    /** Prefixo das variáveis de ambiente da suíte (<PREFIXO>_TEST_RUN_ID etc.). */
    private const PREFIXO_DO_AMBIENTE = 'DEALNI';

    /** Prefixo dos diretórios temporários criados pelo bootstrap. */
    private const PREFIXO_DOS_DIRETORIOS_TEMPORARIOS = 'dealnibot-testes-';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

    private const VARIAVEL_RUN_ID = self::PREFIXO_DO_AMBIENTE . '_TEST_RUN_ID';
    private const VARIAVEL_PRESERVAR = self::PREFIXO_DO_AMBIENTE . '_TEST_PRESERVE_DIRS';
    private const VARIAVEL_LOGS = self::PREFIXO_DO_AMBIENTE . '_LOGS_DIR';
    private const VARIAVEL_UPDATES = self::PREFIXO_DO_AMBIENTE . '_UPDATES_DIR';

    public function testFilhoHerdaRunIdMasRecebeDiretoriosPropriosEFilhoDiretoHerdaAmbiente(): void
    {
        $runId = getenv(self::VARIAVEL_RUN_ID);
        $logsPai = getenv(self::VARIAVEL_LOGS);
        $updatesPai = getenv(self::VARIAVEL_UPDATES);
        $this->assertIsString($runId);
        $this->assertIsString($logsPai);
        $this->assertIsString($updatesPai);
        $this->assertNotSame('', $runId);
        $this->assertNotSame('', $logsPai);
        $this->assertNotSame('', $updatesPai);

        $filhoDireto = $this->executarPhp(self::scriptDoFilho(false));
        $this->assertSame(0, $filhoDireto['status'], $filhoDireto['stderr']);
        $ambienteDireto = $this->decodificar($filhoDireto['stdout']);
        $this->assertSame($runId, $ambienteDireto['run_id']);
        $this->assertSame($logsPai, $ambienteDireto['logs']);
        $this->assertSame($updatesPai, $ambienteDireto['updates']);

        $scriptBootstrap = self::scriptDoFilho(true);
        $filho = null;

        try {
            $filho = $this->executarPhp($scriptBootstrap, $this->ambienteDoFilho(false));
            $this->assertSame(0, $filho['status'], $filho['stderr']);
            $ambienteFilho = $this->decodificar($filho['stdout']);
            $this->assertSame($runId, $ambienteFilho['run_id']);
            $this->assertNotSame($logsPai, $ambienteFilho['logs']);
            $this->assertNotSame($updatesPai, $ambienteFilho['updates']);
            $pidFilho = $this->campoString($ambienteFilho, 'pid');
            $logsFilho = $this->campoString($ambienteFilho, 'logs');
            $updatesFilho = $this->campoString($ambienteFilho, 'updates');
            $this->assertStringContainsString(
                '/' . self::PREFIXO_DOS_DIRETORIOS_TEMPORARIOS . 'logs-' . $runId . '-' . $pidFilho . '-',
                $logsFilho,
            );
            $this->assertStringContainsString(
                '/' . self::PREFIXO_DOS_DIRETORIOS_TEMPORARIOS . 'updates-' . $runId . '-' . $pidFilho . '-',
                $updatesFilho,
            );

            // O filho deve remover somente os dois diretórios que criou; os
            // diretórios do pai continuam disponíveis para outros filhos.
            $this->assertFalse(is_dir($logsFilho));
            $this->assertFalse(is_dir($updatesFilho));
            $this->assertTrue(is_dir($logsPai));
            $this->assertTrue(is_dir($updatesPai));
        } finally {
            if (is_array($filho)) {
                $ambienteFilho = json_decode(trim($filho['stdout']), true);
                if (is_array($ambienteFilho)) {
                    $this->apagarDiretorioExato($ambienteFilho['logs'] ?? null);
                    $this->apagarDiretorioExato($ambienteFilho['updates'] ?? null);
                }
            }
        }
    }

    public function testFilhoPodePreservarExplicitamenteDiretoriosHerdados(): void
    {
        $runId = getenv(self::VARIAVEL_RUN_ID);
        $logsPai = getenv(self::VARIAVEL_LOGS);
        $updatesPai = getenv(self::VARIAVEL_UPDATES);
        $this->assertIsString($runId);
        $this->assertIsString($logsPai);
        $this->assertIsString($updatesPai);

        $saida = $this->executarPhp(self::scriptDoFilho(true), $this->ambienteDoFilho(true));

        $this->assertSame(0, $saida['status'], $saida['stderr']);
        $ambiente = $this->decodificar($saida['stdout']);
        $this->assertSame($runId, $ambiente['run_id']);
        $this->assertSame($logsPai, $ambiente['logs']);
        $this->assertSame($updatesPai, $ambiente['updates']);
        $this->assertTrue(is_dir($logsPai));
        $this->assertTrue(is_dir($updatesPai));
    }

    public function testBootstrapDeclaraRunIdPreservacaoECleanupPorProcesso(): void
    {
        $bootstrap = file_get_contents(self::RAIZ . '/tests/bootstrap.php');
        $this->assertIsString($bootstrap);
        $this->assertStringContainsString("\$prefixoDoAmbiente = '" . self::PREFIXO_DO_AMBIENTE . "';", $bootstrap);
        $this->assertStringContainsString(
            "\$prefixoDosDiretoriosTemporarios = '" . self::PREFIXO_DOS_DIRETORIOS_TEMPORARIOS . "';",
            $bootstrap,
        );
        $this->assertStringContainsString("'_TEST_RUN_ID'", $bootstrap);
        $this->assertStringContainsString("'_TEST_PRESERVE_DIRS'", $bootstrap);
        $this->assertStringContainsString('register_shutdown_function', $bootstrap);
        $this->assertStringContainsString('nonceProcesso', $bootstrap);
        $this->assertStringContainsString("mkdir(\$caminho, 0700)", $bootstrap);
    }

    /**
     * @param array<string, string>|null $ambiente
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function executarPhp(string $script, ?array $ambiente = null): array
    {
        $processo = null;
        $pipes = [];
        try {
            $comando = [PHP_BINARY, '-r', $script, self::RAIZ];
            $processo = $ambiente === null
                ? proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ)
                : proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, $ambiente);
            if (!is_resource($processo)) {
                throw new RuntimeException('Não foi possível iniciar o subprocesso do contrato do bootstrap.');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($processo);
            $processo = null;

            return [
                'status' => $status,
                'stdout' => is_string($stdout) ? $stdout : '',
                'stderr' => is_string($stderr) ? $stderr : '',
            ];
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($processo)) {
                $status = proc_get_status($processo);
                if ($status['running']) {
                    proc_terminate($processo);
                }
                @proc_close($processo);
            }
        }
    }

    /** Script do subprocesso: imprime o ambiente da suíte, com ou sem o bootstrap. */
    private static function scriptDoFilho(bool $comBootstrap): string
    {
        $script = $comBootstrap ? "require \$argv[1] . '/tests/bootstrap.php';\n" : '';
        $script .= "echo json_encode([\n";
        $script .= "    'run_id' => getenv('" . self::VARIAVEL_RUN_ID . "'),\n";
        if ($comBootstrap) {
            $script .= "    'pid' => (string) getmypid(),\n";
        }
        $script .= "    'logs' => getenv('" . self::VARIAVEL_LOGS . "'),\n";
        $script .= "    'updates' => getenv('" . self::VARIAVEL_UPDATES . "'),\n";
        $script .= "], JSON_UNESCAPED_SLASHES), \"\\n\";\n";

        return $script;
    }

    /**
     * @return array<string, string>
     */
    private function ambienteDoFilho(bool $preservarDiretorios): array
    {
        return [
            'APP_ENV' => 'test',
            'CHATBOTS_PG_TEST_DSN' => (string) getenv('CHATBOTS_PG_TEST_DSN'),
            self::PREFIXO_DO_AMBIENTE . '_DB_USER' => (string) getenv(self::PREFIXO_DO_AMBIENTE . '_DB_USER'),
            self::PREFIXO_DO_AMBIENTE . '_DB_PASSWORD' => (string) getenv(self::PREFIXO_DO_AMBIENTE . '_DB_PASSWORD'),
            'CHATBOTS_TESTING' => '1',
            'CHATBOTS_NETWORK_DISABLED' => '1',
            'CHATBOTS_OS_NETWORK_SANDBOX' => '1',
            self::VARIAVEL_RUN_ID => (string) getenv(self::VARIAVEL_RUN_ID),
            self::VARIAVEL_PRESERVAR => $preservarDiretorios ? '1' : '0',
            self::VARIAVEL_LOGS => (string) getenv(self::VARIAVEL_LOGS),
            self::VARIAVEL_UPDATES => (string) getenv(self::VARIAVEL_UPDATES),
            'PATH' => (string) getenv('PATH'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodificar(string $stdout): array
    {
        $valor = json_decode(trim($stdout), true);
        $this->assertIsArray($valor, 'O subprocesso deveria imprimir JSON: ' . $stdout);

        $resultado = [];
        foreach ($valor as $chave => $campo) {
            if (is_string($chave)) {
                $resultado[$chave] = $campo;
            }
        }

        return $resultado;
    }

    /**
     * @param array<string, mixed> $ambiente
     */
    private function campoString(array $ambiente, string $campo): string
    {
        $valor = $ambiente[$campo] ?? null;
        $this->assertIsString($valor, "O campo {$campo} deveria ser string.");

        return $valor;
    }

    private function apagarDiretorioExato(mixed $caminho): void
    {
        if (!is_string($caminho) || $caminho === '' || !str_contains($caminho, sys_get_temp_dir() . '/')) {
            return;
        }
        if (is_link($caminho) || is_file($caminho)) {
            @unlink($caminho);

            return;
        }
        if (!is_dir($caminho)) {
            return;
        }
        $entradas = @scandir($caminho);
        foreach ($entradas === false ? [] : $entradas as $entrada) {
            if ($entrada !== '.' && $entrada !== '..') {
                $this->apagarDiretorioExato($caminho . '/' . $entrada);
            }
        }
        @rmdir($caminho);
    }
}
