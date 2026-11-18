<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_banco.php';

final class SnapshotFalhasTest extends TestCase
{
    private string $dir = '';
    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/snapshot-banco-' . bin2hex(random_bytes(6));
        mkdir($base, 0o700, true);
        $this->dir = $base;
    }

    protected function tearDown(): void
    {
        $this->limpar($this->dir);
    }

    private function limpar(string $diretorio): void
    {
        if (!is_dir($diretorio)) {
            return;
        }
        @chmod($diretorio, 0o700);
        $subdiretorios = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $diretorio,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($subdiretorios as $info) {
            if ($info instanceof \SplFileInfo && !$info->isLink() && $info->isDir()) {
                @chmod($info->getPathname(), 0o700);
            }
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $diretorio,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $info) {
            if (!$info instanceof \SplFileInfo) {
                continue;
            }
            $caminho = $info->getPathname();
            if ($info->isLink() || !$info->isDir()) {
                @unlink($caminho);
            } else {
                @rmdir($caminho);
            }
        }
        @rmdir($diretorio);
    }

    public function testNomeCarregaADataEmFormatoQueOrdenaComoOTempo(): void
    {
        $antes = nomeDoSnapshotBanco(1_770_000_000);
        $depois = nomeDoSnapshotBanco(1_770_086_400);

        $this->assertSame('bot-2026-02-02-024000.dump', $antes);
        $this->assertLessThan(0, strcmp($antes, $depois), 'a ordem lexicográfica É a ordem cronológica');
    }

    public function testFalhaSemRemoverMetadadoSimbolicoPreexistente(): void
    {
        $origem = $this->bancoDeOrigem();
        $destino = $this->dir . '/bot-2026-01-01-000000.dump';
        $sha = $destino . '.sha256';
        $this->assertTrue(symlink($this->dir . '/alvo-inexistente', $sha));

        $this->expectException(RuntimeException::class);
        try {
            gravarSnapshotBanco(\BancoTeste::dsn(), $destino);
        } finally {
            $this->assertTrue(is_link($sha));
            $this->assertFileDoesNotExist($destino);
            $this->assertTrue(unlink($sha));
        }
    }

    public function testRotacaoPreservaDumpsRecemPublicadosMesmoComDataAntiga(): void
    {
        foreach (['bot-2026-01-01-000000.dump', 'bot-2026-01-02-000000.dump', 'bot-2026-01-03-000000.dump', 'bot-2026-01-04-000000.dump'] as $nome) {
            $arquivo = $this->dir . '/' . $nome;
            $this->assertNotFalse(file_put_contents($arquivo, 'snapshot completo'));
            gravarMetadadosSnapshotBanco('origem.dump', $arquivo);
        }

        $removidos = rotacionarSnapshotsBanco($this->dir, 2);

        $this->assertSame([], $removidos);
        $this->assertCount(4, listarSnapshotsBanco($this->dir));
        $this->assertFileExists($this->dir . '/bot-2026-01-01-000000.dump.sha256');
        $this->assertFileExists($this->dir . '/bot-2026-01-01-000000.dump.json');
        $this->assertFileExists($this->dir . '/bot-2026-01-03-000000.dump.sha256');
    }

    public function testRotacaoIgnoraPublicacaoIncompletaSemContarNoLimite(): void
    {
        foreach (['bot-2026-01-01-000000.dump', 'bot-2026-01-02-000000.dump'] as $nome) {
            $arquivo = $this->dir . '/' . $nome;
            $this->assertNotFalse(file_put_contents($arquivo, 'snapshot completo'));
            gravarMetadadosSnapshotBanco('origem.dump', $arquivo);
        }

        // Simula SIGKILL depois da publicação do banco e do primeiro sidecar.
        $incompleto = $this->dir . '/bot-2026-01-03-000000.dump';
        $this->assertNotFalse(file_put_contents($incompleto, 'publicacao interrompida'));
        $this->assertNotFalse(file_put_contents($incompleto . '.sha256', 'incompleto'));
        $incoerente = $this->dir . '/bot-2026-01-04-000000.dump';
        $this->assertNotFalse(file_put_contents($incoerente, 'manifesto incoerente'));
        $hashIncoerente = hash_file('sha256', $incoerente);
        $this->assertIsString($hashIncoerente);
        $this->assertNotFalse(file_put_contents($incoerente . '.sha256', $hashIncoerente . '  ' . basename($incoerente) . PHP_EOL));
        $this->assertNotFalse(file_put_contents($incoerente . '.json', '{}'));

        $this->assertSame(
            ['bot-2026-01-01-000000.dump', 'bot-2026-01-02-000000.dump'],
            listarSnapshotsBanco($this->dir)
        );

        $removidos = rotacionarSnapshotsBanco($this->dir, 2);

        $this->assertSame([], $removidos);
        $this->assertSame(
            ['bot-2026-01-01-000000.dump', 'bot-2026-01-02-000000.dump'],
            listarSnapshotsBanco($this->dir)
        );
        $this->assertFileExists($incompleto);
        $this->assertFileExists($incompleto . '.sha256');
        $this->assertFileDoesNotExist($incompleto . '.json');
        $this->assertFileExists($incoerente);
        $this->assertFileExists($incoerente . '.sha256');
        $this->assertFileExists($incoerente . '.json');
    }

    public function testRotacaoNaoToleraManterZeroOuNegativo(): void
    {
        file_put_contents($this->dir . '/bot-2026-01-01-000000.dump', 'x');

        $this->expectException(RuntimeException::class);

        try {
            rotacionarSnapshotsBanco($this->dir, 0);
        } finally {
            $this->assertFileExists($this->dir . '/bot-2026-01-01-000000.dump');
        }
    }

    public function testRotacaoIgnoraArquivoQueNaoEhSnapshotDesteFormato(): void
    {
        // O diretório é do operador também: dump manual, backup de migração e
        // o próprio banco podem estar ali, e a rotação não pode encostar neles.
        $estranhos = ['bot.dump', 'bot.dump.backup-pre-migracao-2026-07-22', 'bot-2026-01-01.dump', 'anotacoes.txt'];
        foreach ($estranhos as $nome) {
            file_put_contents($this->dir . '/' . $nome, 'x');
        }
        foreach (['bot-2026-01-01-000000.dump', 'bot-2026-01-02-000000.dump'] as $nome) {
            $arquivo = $this->dir . '/' . $nome;
            $this->assertNotFalse(file_put_contents($arquivo, 'snapshot'));
            gravarMetadadosSnapshotBanco('origem.dump', $arquivo);
        }

        $removidos = rotacionarSnapshotsBanco($this->dir, 1);

        $this->assertSame([], $removidos);
        foreach ($estranhos as $nome) {
            $this->assertFileExists($this->dir . '/' . $nome);
        }
    }

    public function testSnapshotInvalidoNaoEhConsideradoIntegro(): void
    {
        $invalido = $this->dir . '/bot-2026-01-01-000000.dump';
        file_put_contents($invalido, 'arquivo truncado');

        self::assertFalse(snapshotBancoIntegro($invalido));
    }

    public function testSnapshotAusenteNaoEhConsideradoIntegro(): void
    {
        self::assertFalse(snapshotBancoIntegro($this->dir . '/nao-existe.dump'));
    }

    public function testListaEIntegridadeRecusamSymlinkMesmoComBarraFinal(): void
    {
        $origem = $this->bancoDeOrigem();
        $nome = $this->dir . '/bot-2026-01-01-000000.dump';
        $this->assertTrue(symlink($origem, $nome));
        $this->assertSame([], listarSnapshotsBanco($this->dir));
        $this->assertFalse(snapshotBancoIntegro($nome . '/'));

        $diretorioLink = $this->dir . '-link';
        $this->assertTrue(symlink($this->dir, $diretorioLink));
        try {
            $this->assertSame([], listarSnapshotsBanco($diretorioLink . '/'));
            $this->expectException(RuntimeException::class);
            executarSnapshotBanco(\BancoTeste::dsn(), $diretorioLink . '/', 1, static fn (): int => 1_770_000_000);
        } finally {
            $this->assertTrue(unlink($diretorioLink));
        }
    }

    public function testEscritaRecusaParentOuComponenteSymlink(): void
    {
        $origem = $this->bancoDeOrigem();
        $diretorioLink = $this->dir . '/diretorio-link';
        $this->assertTrue(symlink($this->dir, $diretorioLink));

        $this->expectException(RuntimeException::class);
        gravarSnapshotBanco(\BancoTeste::dsn(), $diretorioLink . '/../bot-2026-01-01-000000.dump');
    }

    public function testMetadadosRecusamCaminhoQueNaoEhArquivo(): void
    {
        $naoArquivo = $this->dir . '/nao-e-arquivo';
        $this->assertTrue(mkdir($naoArquivo, 0700));

        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(RuntimeException::class);
            gravarMetadadosSnapshotBanco('origem.dump', $naoArquivo);
        } finally {
            restore_error_handler();
            $this->assertTrue(rmdir($naoArquivo));
        }
    }

    public function testMetadadosRecusamOrigemComUtf8Invalido(): void
    {
        $arquivo = $this->dir . '/bot-2026-01-01-000000.dump';
        $this->assertNotFalse(file_put_contents($arquivo, 'snapshot'));

        $this->expectException(RuntimeException::class);
        gravarMetadadosSnapshotBanco("origem-\xFF.dump", $arquivo);
    }

    public function testMetadadosFalhamQuandoTemporarioNaoPodeSerCriadoEDeixamDiretorioLimpo(): void
    {
        $diretorio = $this->dir . '/nome-no-limite';
        $this->assertTrue(mkdir($diretorio, 0700));
        $prefixo = 'bot-2026-01-01-000000';
        $nome = $prefixo . str_repeat('x', 255 - strlen($prefixo) - strlen('.dump')) . '.dump';
        $this->assertSame(255, strlen($nome));
        $arquivo = $diretorio . '/' . $nome;
        $this->assertNotFalse(file_put_contents($arquivo, 'snapshot'));

        $excecao = null;
        try {
            gravarMetadadosSnapshotBanco('origem.dump', $arquivo);
        } catch (RuntimeException $e) {
            $excecao = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $excecao);
        $this->assertStringContainsString('Não foi possível preparar os metadados do snapshot', $excecao->getMessage());
        $this->assertSame([$nome], array_values(array_diff((array) scandir($diretorio), ['.', '..'])));
    }

    public function testFalhaAoPublicarManifestoDesfazOManifestoDeSoma(): void
    {
        $arquivo = $this->dir . '/bot-2026-01-01-000000.dump';
        $json = $arquivo . '.json';
        $this->assertNotFalse(file_put_contents($arquivo, 'snapshot'));
        $this->assertNotFalse(file_put_contents($json, 'manifesto anterior'));

        $this->expectException(RuntimeException::class);
        try {
            gravarMetadadosSnapshotBanco('origem.dump', $arquivo);
        } finally {
            $this->assertFileDoesNotExist($arquivo . '.sha256');
            $this->assertSame('manifesto anterior', file_get_contents($json));
        }
    }

    public function testExecutarFalhaQuandoDiretorioDeSnapshotsNaoPodeSerCriado(): void
    {
        $bloqueio = $this->dir . '/nao-diretorio';
        $this->assertNotFalse(file_put_contents($bloqueio, 'arquivo'));

        $this->expectException(RuntimeException::class);
        executarSnapshotBanco(
            $this->dir . '/origem-nao-existe.dump',
            $bloqueio . '/subdiretorio',
            1,
        );
    }

    public function testFalhaDoDumpNaoApagaSnapshotAntigo(): void
    {
        // A rotação roda DEPOIS do snapshot novo: se o banco de origem sumiu
        // entre duas execuções do cron, o histórico tem que sobreviver intacto.
        foreach (['bot-2026-01-01-000000.dump', 'bot-2026-01-02-000000.dump'] as $nome) {
            $arquivo = $this->dir . '/' . $nome;
            $this->assertNotFalse(file_put_contents($arquivo, 'snapshot anterior'));
            gravarMetadadosSnapshotBanco('origem.dump', $arquivo);
        }

        try {
            executarSnapshotBanco($this->dir . '/nao-existe.dump', $this->dir, 1);
            $this->fail('esperava RuntimeException');
        } catch (RuntimeException) {
            $this->assertSame(
                ['bot-2026-01-01-000000.dump', 'bot-2026-01-02-000000.dump'],
                listarSnapshotsBanco($this->dir)
            );
        }
    }

    public function testDiretorioInexistenteEIlegivelDevolveListaVazia(): void
    {
        $this->assertSame([], listarSnapshotsBanco($this->dir . '/nunca-criado'));
    }

    private function bancoDeOrigem(): string
    {
        $arquivo = $this->dir . '/sentinela.bin';
        file_put_contents($arquivo, 'arquivo sentinela');
        return $arquivo;
    }

    public function testMetadadosInvalidosNaoAutorizamPublicacao(): void
    {
        $arquivo = $this->dir . '/bot-2026-01-01-000000.dump';
        file_put_contents($arquivo, 'conteudo');
        $hash = hash_file('sha256', $arquivo);
        self::assertIsString($hash);
        file_put_contents($arquivo . '.sha256', $hash . '  ' . basename($arquivo));
        foreach (['json invalido', 'null', '123', '{}'] as $json) {
            file_put_contents($arquivo . '.json', $json);
            self::assertFalse(snapshotBancoMetadadosConferem($arquivo));
        }
        file_put_contents($arquivo . '.json', '{}');
        foreach (['', 'duas' . "\n" . 'linhas', 'hash arquivo', $hash . '  outro.dump'] as $sha) {
            file_put_contents($arquivo . '.sha256', $sha);
            self::assertNull(lerMetadadosSnapshotBanco($arquivo));
        }
    }

    public function testCaminhoInvalidoNaoViraDiretorioDeBackup(): void
    {
        self::assertNull(caminhoSnapshotResolvido(''));
        self::assertNull(caminhoSnapshotResolvido($this->dir . '/../fora'));
        self::assertFalse(caminhoFinalSnapshotEhSymlink('/'));
    }
    public function testRotacaoRecusaCaminhosInvalidosESymlinkQuebrado(): void
    {
        $link = $this->dir . '/quebrado';
        self::assertTrue(symlink($this->dir . '/ausente', $link));
        self::assertNull(caminhoSnapshotResolvido($link));
        self::assertSame([], rotacionarSnapshotsBanco($link, 1));
        self::assertSame([], rotacionarSnapshotsBanco($this->dir . '/ausente', 1));
        self::assertTrue(is_link($link));
    }

    public function testClientePostgresRecusaCredenciaisNoDsn(): void
    {
        $this->expectException(RuntimeException::class);
        parametrosLibpqDoBot('pgsql:dbname=chatbots_test_fake;password=segredo-sintetico');
    }

    public function testClientePostgresExigeUsuarioExplicito(): void
    {
        $anterior = getenv('DEALNI_DB_USER');
        try {
            putenv('DEALNI_DB_USER');
            $this->expectException(RuntimeException::class);
            executarUtilitarioPostgres(['pg_dump'], 'pgsql:dbname=chatbots_test_fake');
        } finally {
            putenv($anterior === false ? 'DEALNI_DB_USER' : 'DEALNI_DB_USER=' . $anterior);
        }
    }

    public function testValidacaoRecusaCaminhoComParentMesmoQuandoArquivoExiste(): void
    {
        $arquivo = $this->dir . '/arquivo.dump';
        file_put_contents($arquivo, 'preservar');
        mkdir($this->dir . '/sub');
        $caminho = $this->dir . '/sub/../arquivo.dump';
        self::assertFalse(snapshotBancoIntegro($caminho));
        $this->expectException(RuntimeException::class);
        gravarMetadadosSnapshotBanco('postgresql', $caminho);
    }

    public function testMetadadosSemDumpNaoProvamIntegridade(): void
    {
        $arquivo = $this->dir . '/sem-dump.dump';
        file_put_contents($arquivo . '.sha256', str_repeat('0', 64) . '  sem-dump.dump');
        file_put_contents($arquivo . '.json', '{}');
        self::assertNull(@lerMetadadosSnapshotBanco($arquivo));
    }

}
