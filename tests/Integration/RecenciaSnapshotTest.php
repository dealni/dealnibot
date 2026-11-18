<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/inclusos/caminhos.php';
require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_banco.php';
require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_recencia.php';

final class RecenciaSnapshotTest extends TestCase
{
    private string $diretorio;

    protected function setUp(): void
    {
        $this->diretorio = sys_get_temp_dir() . '/snapshot-recencia-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->diretorio, 0700));
        $real = realpath($this->diretorio);
        self::assertIsString($real);
        $this->diretorio = $real;
    }

    protected function tearDown(): void
    {
        $arquivos = glob($this->diretorio . '/*');
        foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->diretorio);
    }

    public function testDumpRealRecenteEValidoMasTouchNaoRenovaCopiaAntiga(): void
    {
        $agora = 1_800_000_000;
        $arquivo = $this->criarSnapshot($agora - 129_600);
        self::assertSame($arquivo, localizarSnapshotDiarioRecente($this->diretorio, $agora));
        self::assertTrue(touch($arquivo, $agora + 1));
        self::assertNull(localizarSnapshotDiarioRecente($this->diretorio, $agora + 1));
        self::assertNull(localizarSnapshotDiarioRecente($this->diretorio, $agora - 129_601));
    }

    public function testRecusaDumpComSidecarsAusentesOuConteudoCorrompido(): void
    {
        $agora = 1_800_000_000;
        $arquivo = $this->criarSnapshot($agora);
        self::assertTrue(unlink($arquivo . '.sha256'));
        self::assertNull(localizarSnapshotDiarioRecente($this->diretorio, $agora));
        self::assertTrue(unlink($arquivo . '.json'));
        self::assertNotFalse(file_put_contents($arquivo, 'dump interrompido'));
        gravarMetadadosSnapshotBanco('postgresql', $arquivo);
        // O hash bate, mas o pg_restore precisa recusar o conteúdo inválido.
        self::assertTrue(snapshotBancoMetadadosConferem($arquivo));
        self::assertNull(localizarSnapshotDiarioRecente($this->diretorio, $agora));
    }

    public function testBackupPreDeployNaoSubstituiPeriodicidadeDiaria(): void
    {
        $agora = 1_800_000_000;
        $this->criarSnapshot($agora, PREFIXO_SNAPSHOT_PRE_DEPLOY);
        self::assertNull(localizarSnapshotDiarioRecente($this->diretorio, $agora));
        self::assertNull(localizarSnapshotDiarioRecente($this->diretorio, 0));
        self::assertNull(localizarSnapshotDiarioRecente($this->diretorio, $agora, 0));
    }

    public function testFallbackParaDiarioIntegroQuandoMaisNovoEstaIncompleto(): void
    {
        $agora = 1_800_000_000;
        $arquivo = $this->criarSnapshot($agora - 60);
        $incompleto = $this->diretorio . '/' . nomeDoSnapshotBanco($agora);
        self::assertNotFalse(file_put_contents($incompleto, 'parcial'));
        self::assertSame($arquivo, localizarSnapshotDiarioRecente($this->diretorio, $agora));
    }

    public function testCliVerificaDumpSemCarregarBancoOuTokens(): void
    {
        $ambiente = getenv();
        $ambiente[VARIAVEL_DO_DIRETORIO_DE_SNAPSHOTS] = $this->diretorio;
        $pipes = [];
        $executar = static function () use ($ambiente, &$pipes): int {
            $processo = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/operacao/verificar_snapshot_recente.php'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                $ambiente,
            );
            self::assertIsResource($processo);
            return proc_close($processo);
        };
        self::assertSame(1, $executar());
        $this->criarSnapshot(time());
        self::assertSame(0, $executar());
    }

    private function criarSnapshot(int $instante, string $prefixo = PREFIXO_SNAPSHOT_DIARIO): string
    {
        $dsn = \BancoTeste::dsn();
        $pdo = \BancoTeste::conectar($dsn);
        $pdo->exec('CREATE TABLE exemplo (id BIGINT PRIMARY KEY, texto TEXT)');
        $pdo->exec("INSERT INTO exemplo VALUES (1, 'ação 🚀')");
        $resultado = executarSnapshotBanco($dsn, $this->diretorio, 7, static fn (): int => $instante, $prefixo);
        return $resultado['arquivo'];
    }
}
