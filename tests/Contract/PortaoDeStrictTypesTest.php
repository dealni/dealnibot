<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function Quality\declaraStrictTypes;
use function Quality\executarProcesso;
use function Quality\mainStrictTypes;

require_once __DIR__ . '/../../scripts/quality/check_strict_types.php';

/**
 * O ratchet de `declare(strict_types=1)`, provado pelos jeitos de burlá-lo.
 *
 * A árvore é um repositório git descartável com a própria baseline: a de
 * verdade (`tools/quality/strict-types-baseline.json`) não pode ser escrita por
 * teste, senão o portão passaria a aprovar o que ele mesmo acabou de gravar.
 */
final class PortaoDeStrictTypesTest extends TestCase
{
    private const COM = "<?php\n\ndeclare(strict_types=1);\n\nfunction f(): void\n{\n}\n";

    private const SEM = "<?php\n\nfunction f(): void\n{\n}\n";

    private string $raiz = '';

    private string $mensagemDeErro = '';

    protected function setUp(): void
    {
        $this->raiz = (string) tempnam(sys_get_temp_dir(), 'strict_types_');
        unlink($this->raiz);
        self::assertTrue(mkdir($this->raiz . '/tools/quality', 0777, true));
        $git = executarProcesso(['git', 'init', '--quiet'], $this->raiz);
        self::assertSame(0, $git['status'], $git['stderr']);
        putenv('CI');
    }

    protected function tearDown(): void
    {
        putenv('CI');
        $this->apagar($this->raiz);
    }

    private function apagar(string $caminho): void
    {
        if (is_file($caminho) || is_link($caminho)) {
            unlink($caminho);

            return;
        }
        if (!is_dir($caminho)) {
            return;
        }
        foreach ((array) scandir($caminho) as $entrada) {
            if (is_string($entrada) && $entrada !== '.' && $entrada !== '..') {
                $this->apagar($caminho . '/' . $entrada);
            }
        }
        rmdir($caminho);
    }

    private function escrever(string $relativo, string $conteudo): void
    {
        $caminho = $this->raiz . '/' . $relativo;
        if (!is_dir(dirname($caminho))) {
            self::assertTrue(mkdir(dirname($caminho), 0777, true));
        }
        self::assertNotFalse(file_put_contents($caminho, $conteudo));
    }

    private function rodar(): int
    {
        $destino = fopen('php://memory', 'w+');
        self::assertIsResource($destino);
        ob_start();
        try {
            $codigo = mainStrictTypes(['check_strict_types.php', '--raiz=' . $this->raiz], $destino);
        } finally {
            ob_end_clean();
        }
        rewind($destino);
        $this->mensagemDeErro = (string) stream_get_contents($destino);
        fclose($destino);

        return $codigo;
    }

    /** @return list<string> */
    private function baseline(): array
    {
        $dados = json_decode((string) file_get_contents($this->raiz . '/tools/quality/strict-types-baseline.json'), true);
        self::assertIsArray($dados);
        $lista = $dados['sem_strict_types'] ?? null;
        self::assertIsArray($lista);

        /** @var list<string> $lista */
        return $lista;
    }

    /** @param list<string> $arquivos */
    private function gravarBaseline(array $arquivos): void
    {
        $this->escrever(
            'tools/quality/strict-types-baseline.json',
            json_encode(['sem_strict_types' => $arquivos], JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function codigos(): array
    {
        return [
            'declaração no topo' => [self::COM, true],
            'declaração na mesma linha da tag' => ["<?php declare(strict_types=1);\n", true],
            'docblock do arquivo antes' => ["<?php\n\n/** Arquivo. */\n\ndeclare(strict_types=1);\n", true],
            'shebang antes da tag' => ["#!/usr/bin/env php\n<?php\n\ndeclare(strict_types=1);\n", true],
            'espaços dentro do declare' => ["<?php\ndeclare( strict_types = 1 );\n", true],
            'sem declaração' => [self::SEM, false],
            'strict_types desligado' => ["<?php\n\ndeclare(strict_types=0);\n", false],
            'outro declare' => ["<?php\n\ndeclare(ticks=1);\n", false],
            'só no comentário' => ["<?php\n\n// declare(strict_types=1);\nfunction f(): void\n{\n}\n", false],
            'HTML antes da tag' => ["<p>oi</p>\n<?php\n\ndeclare(strict_types=1);\n", false],
            'tag curta de eco' => ["<?= 1 ?>\n", false],
            'arquivo vazio' => ['', false],
        ];
    }

    #[DataProvider('codigos')]
    public function testReconheceADeclaracaoSoComoPrimeiraInstrucao(string $codigo, bool $esperado): void
    {
        self::assertSame($esperado, declaraStrictTypes($codigo));
    }

    public function testPrimeiraExecucaoGravaABaselineComOsArquivosSemDeclaracao(): void
    {
        $this->escrever('src/Com.php', self::COM);
        $this->escrever('legado/b.php', self::SEM);
        $this->escrever('legado/a.php', self::SEM);

        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
        self::assertSame(['legado/a.php', 'legado/b.php'], $this->baseline());
    }

    public function testComCiABaselineAusenteReprovaSemSerCriada(): void
    {
        $this->escrever('legado/a.php', self::SEM);
        putenv('CI=1');

        self::assertSame(2, $this->rodar());
        self::assertStringContainsString('obrigatória', $this->mensagemDeErro);
        self::assertFileDoesNotExist($this->raiz . '/tools/quality/strict-types-baseline.json');
    }

    public function testArquivoNovoSemDeclaracaoReprovaSemEntrarNaBaseline(): void
    {
        $this->escrever('legado/a.php', self::SEM);
        $this->escrever('src/Novo.php', self::SEM);
        $this->gravarBaseline(['legado/a.php']);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('src/Novo.php', $this->mensagemDeErro);
        self::assertStringNotContainsString('legado/a.php', $this->mensagemDeErro);
        self::assertSame(['legado/a.php'], $this->baseline());
    }

    public function testArquivoRenomeadoNaoHerdaOLugarNaBaseline(): void
    {
        $this->escrever('legado/novo-nome.php', self::SEM);
        $this->gravarBaseline(['legado/nome-antigo.php']);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('legado/novo-nome.php', $this->mensagemDeErro);
    }

    public function testArquivoQueGanhouADeclaracaoSaiDaBaselineForaDoCi(): void
    {
        $this->escrever('legado/a.php', self::COM);
        $this->escrever('legado/b.php', self::SEM);
        $this->gravarBaseline(['legado/a.php', 'legado/b.php', 'legado/apagado.php']);

        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
        self::assertSame(['legado/b.php'], $this->baseline());
    }

    public function testComCiABaselineNaoEncolheSozinha(): void
    {
        $this->escrever('legado/a.php', self::COM);
        $this->gravarBaseline(['legado/a.php']);
        putenv('CI=1');

        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
        self::assertSame(['legado/a.php'], $this->baseline());
    }

    public function testArquivoIgnoradoFicaForaEArquivoNovoNaoRastreadoEntra(): void
    {
        $this->escrever('.gitignore', "inclusos/config.php\n");
        $this->escrever('inclusos/config.php', self::SEM);
        $this->escrever('inclusos/novo.php', self::SEM);
        $this->gravarBaseline([]);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('inclusos/novo.php', $this->mensagemDeErro);
        self::assertStringNotContainsString('config.php', $this->mensagemDeErro);
    }

    public function testFixtureDoSemgrepFicaForaDoPortao(): void
    {
        $this->escrever('tools/quality/semgrep/project/fixtures/violations.php', self::SEM);
        $this->escrever('src/Com.php', self::COM);
        $this->gravarBaseline([]);

        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
    }

    public function testBaselineSemAListaEsperadaEErroDeConfiguracao(): void
    {
        $this->escrever('src/Com.php', self::COM);
        $this->escrever('tools/quality/strict-types-baseline.json', "{\"outra\": []}\n");

        $this->expectException(UnexpectedValueException::class);
        $this->rodar();
    }

    public function testArvoreSemPhpEErroDeConfiguracao(): void
    {
        self::assertSame(2, $this->rodar());
        self::assertStringContainsString('Nenhum arquivo PHP', $this->mensagemDeErro);
    }
}
