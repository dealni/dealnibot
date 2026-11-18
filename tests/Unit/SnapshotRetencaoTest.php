<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_banco.php';

final class SnapshotRetencaoTest extends TestCase
{
    private string $diretorio;

    protected function setUp(): void
    {
        $this->diretorio = sys_get_temp_dir() . '/retencao-' . bin2hex(random_bytes(8));
        mkdir($this->diretorio, 0700);
    }

    protected function tearDown(): void
    {
        $arquivos = glob($this->diretorio . '/*');
        foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->diretorio);
    }

    private function publicar(int $instante, ?int $mtime = null, string $prefixo = PREFIXO_SNAPSHOT_DIARIO): string
    {
        $nome = nomeDoSnapshotBanco($instante, $prefixo);
        $arquivo = $this->diretorio . '/' . $nome;
        file_put_contents($arquivo, 'dump completo');
        gravarMetadadosSnapshotBanco('postgresql', $arquivo);
        touch($arquivo, $mtime ?? $instante);

        return $nome;
    }

    public function testExtrasNoMesmoDiaNaoEncurtamSeteDiasERetencaoIncluiOLimite(): void
    {
        $agora = 1_800_000_000;
        $antigo = $this->publicar($agora - 8 * 86400);
        $limite = $this->publicar($agora - 7 * 86400);
        $semana = $this->publicar($agora - 6 * 86400);
        for ($i = 0; $i < 12; ++$i) {
            $this->publicar($agora - $i * 60);
        }
        $preDeploy = $this->publicar($agora - 30 * 86400, null, PREFIXO_SNAPSHOT_PRE_DEPLOY);
        $parcial = $this->diretorio . '/' . nomeDoSnapshotBanco($agora - 40 * 86400);
        file_put_contents($parcial, 'interrompido');

        self::assertSame([$antigo], rotacionarSnapshotsBanco($this->diretorio, 7, PREFIXO_SNAPSHOT_DIARIO, $agora));
        self::assertFileExists($this->diretorio . '/' . $limite);
        self::assertFileExists($this->diretorio . '/' . $semana);
        self::assertFileExists($this->diretorio . '/' . $preDeploy);
        self::assertFileExists($parcial);
        self::assertCount(14, listarSnapshotsBanco($this->diretorio));
    }

    public function testFalhaProlongadaPreservaTresUltimosCompletos(): void
    {
        $agora = 1_800_000_000;
        $nomes = [];
        for ($i = 12; $i >= 8; --$i) {
            $nomes[] = $this->publicar($agora - $i * 86400);
        }

        self::assertSame(array_slice($nomes, 0, 2), rotacionarSnapshotsBanco($this->diretorio, 7, PREFIXO_SNAPSHOT_DIARIO, $agora));
        self::assertSame(array_slice($nomes, 2), listarSnapshotsBanco($this->diretorio));
    }

    public function testMtimeRecenteNomeFuturoEDatasInvalidasNaoAutorizamExclusao(): void
    {
        $agora = 1_800_000_000;
        $copiado = $this->publicar($agora - 50 * 86400, $agora);
        $futuro = $this->publicar($agora + 86400, $agora - 50 * 86400);
        $invalido = $this->diretorio . '/bot-2026-02-30-000000.dump';
        file_put_contents($invalido, 'metadados validos mas data impossivel');
        gravarMetadadosSnapshotBanco('postgresql', $invalido);
        touch($invalido, $agora - 50 * 86400);
        for ($i = 12; $i >= 8; --$i) {
            $this->publicar($agora - $i * 86400);
        }
        $removidos = rotacionarSnapshotsBanco($this->diretorio, 7, PREFIXO_SNAPSHOT_DIARIO, $agora);

        self::assertCount(3, $removidos);
        self::assertFileExists($this->diretorio . '/' . $copiado);
        self::assertFileExists($this->diretorio . '/' . $futuro);
        self::assertFileExists($invalido);
    }
}
