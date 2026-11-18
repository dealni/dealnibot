<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorFalhaDeArquivo;

require_once __DIR__ . '/../../inclusos/funcoes_snapshot_banco.php';

final class SnapshotFalhasIoTest extends TestCase
{
    private string $diretorio;
    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/snapshot_io_' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $resolvido = realpath($dir);
        self::assertIsString($resolvido);
        $this->diretorio = $resolvido;
    }
    protected function tearDown(): void
    {
        $arquivos = glob($this->diretorio . '/*');
        foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->diretorio);
    }
    /** @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: string, 5?: string}> */
    public static function falhas(): array
    {
        return [
            'realpath falha depois da existência' => ['realpath', 'caminho', 'snapshot_io_', 1, 'null'],
            'metadados não resolvem o arquivo' => ['realpath', 'metadados', '.dump', 1, 'não pôde ser resolvido'],
            'scandir falha depois da legibilidade' => ['scandir', 'listar', 'snapshot_io_', 1, 'lista'],
            'sidecar deixa de poder ser lido' => ['file_get_contents', 'ler', '.sha256', 1, 'null'],
            'manifesto deixa de poder ser lido' => ['file_get_contents', 'ler', '.json', 1, 'null'],
            'metadados sem tamanho legível' => ['filesize', 'metadados', '.dump', 1, 'calcular os metadados'],
            'escrita curta dos metadados' => ['fwrite', 'metadados', '.tmp-', 1, 'preparar os metadados'],
            'falha no fechamento dos metadados' => ['fclose', 'metadados', '.tmp-', 1, 'preparar os metadados'],
            'chmod de metadados recusado' => ['chmod', 'metadados', '.tmp-', 1, 'preparar os metadados'],
            'limpeza do temporário falha uma vez' => ['unlink', 'metadados', '.tmp-', 1, 'limpar o temporário'],
            'reserva do dump recusada' => ['fopen', 'snapshot', '.tmp-', 1, 'reservar o snapshot'],
            'chmod do dump recusado' => ['chmod', 'snapshot', '.tmp-', 1, 'restringir as permissões'],
            'publicação do dump recusada' => ['link', 'snapshot', '.dump', 1, 'publicar o snapshot'],
            'chmod do diretório recusado' => ['chmod', 'executar', 'snapshot_io_', 1, 'restringir o diretório'],
            'permissões não correspondem ao solicitado' => ['fileperms', 'executar', 'snapshot_io_', 1, 'não ficou restrito', 'modo'],
            'tamanho final temporariamente ilegível' => ['filesize', 'executar', '.dump', 2, 'bytes_zero'],
            'candidato muda depois da enumeração' => ['is_file', 'rotacionar', 'bot-2023-11-14-221320.dump', 4, 'lista'],
            'permissões não podem ser conferidas' => ['fileperms', 'executar', 'snapshot_io_', 1, 'não ficou restrito'],
        ];
    }
    #[DataProvider('falhas')]
    public function testFalhaDeIoNaoPublicaSnapshotParcial(string $funcao, string $acao, string $alvo, int $chamada, string $esperado, string $retorno = 'false'): void
    {
        $arquivo = $this->diretorio . '/bot-2023-11-14-221320.dump';
        if (in_array($acao, ['ler', 'metadados', 'caminho'], true)) {
            file_put_contents($arquivo, 'fixture de metadados');
        }
        if ($acao === 'rotacionar') {
            foreach (['bot-2023-11-14-221320.dump', 'bot-2023-11-15-221320.dump'] as $nome) {
                file_put_contents($this->diretorio . '/' . $nome, 'fixture');
                gravarMetadadosSnapshotBanco('postgresql', $this->diretorio . '/' . $nome);
            }
        }
        if ($acao === 'ler') {
            gravarMetadadosSnapshotBanco('postgresql', $arquivo);
        }
        $r = ExecutorFalhaDeArquivo::executar([
            'raiz' => dirname(__DIR__, 2), 'diretorio' => $this->diretorio, 'arquivo' => $arquivo,
            'dsn' => \BancoTeste::dsn(), 'funcao' => $funcao, 'acao' => $acao, 'alvo' => $alvo, 'chamada' => $chamada, 'retorno' => $retorno,
        ]);
        self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertSame('', $r['stderr']);
        $resultado = json_decode($r['stdout'], true);
        self::assertIsArray($resultado);
        self::assertGreaterThanOrEqual(1, $resultado['falhas']);
        if ($esperado === 'null') {
            self::assertNull($resultado['resultado']);
        } elseif ($esperado === 'lista') {
            self::assertSame([], $resultado['resultado']);
            if ($acao === 'rotacionar') {
                self::assertFileExists($arquivo);
            }
        } elseif ($esperado === 'bytes_zero') {
            self::assertIsArray($resultado['resultado']);
            self::assertSame(0, $resultado['resultado']['bytes']);
            self::assertFileExists($arquivo);
            self::assertTrue(snapshotBancoMetadadosConferem($arquivo));
        } else {
            self::assertIsString($resultado['erro']);
            self::assertStringContainsString($esperado, $resultado['erro']);
            if ($acao === 'metadados') {
                self::assertFileDoesNotExist($arquivo . '.json');
                self::assertFileDoesNotExist($arquivo . '.sha256');
                self::assertSame([], glob($this->diretorio . '/*.tmp-*'));
            }
            if (in_array($acao, ['snapshot', 'executar'], true)) {
                self::assertFileDoesNotExist($arquivo);
            }
        }
    }
    public function testCaminhoRelativoPreservaPastaExistenteEComplementoNovo(): void
    {
        $antes = getcwd();
        self::assertIsString($antes);
        chdir($this->diretorio);
        try {
            mkdir('existente', 0700);
            self::assertSame($this->diretorio . '/existente/novo/arquivo', caminhoSnapshotResolvido('./existente/novo/arquivo'));
            self::assertSame('novo/arquivo', caminhoSnapshotResolvido('novo/arquivo'));
        } finally {
            rmdir('existente');
            chdir($antes);
        }
    }
    public function testCaminhoVazioNaoViraDiretorioAtual(): void
    {
        self::assertNull(caminhoSnapshotResolvido(''));
    }
    public function testArquivoNoLugarDoDiretorioImpedeCriacaoDoSnapshot(): void
    {
        $bloqueio = $this->diretorio . '/bloqueio';
        file_put_contents($bloqueio, 'preservar');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Não foi possível criar o diretório/');
        executarSnapshotBanco('origem não utilizada', $bloqueio . '/novo', 1);
    }

}
