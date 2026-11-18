<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

use function Quality\mainTaxonomia;

require_once __DIR__ . '/../../scripts/quality/check_taxonomia_testes.php';

/**
 * O portão da taxonomia, exercitado pelas violações que ele existe para pegar.
 *
 * Portão de convenção é o que mais fácil vira carimbo: ele passa desde o dia em
 * que nasce, ninguém nunca o vê vermelho, e uma condição escrita ao contrário
 * fica verde para sempre. Por isso cada regra aqui é provada pelo caminho
 * VERMELHO, sobre uma árvore de mentira montada em disco temporário — a árvore
 * de verdade não pode ser violada só para ver o portão reclamar.
 *
 * A árvore é montada a partir das próprias constantes do bloco de dados, e não
 * de uma lista escrita aqui: assim o teste acompanha o bloco de dados quando
 * ele muda, sem uma segunda cópia das categorias para manter em dia.
 */
final class PortaoDeTaxonomiaDeTestesTest extends TestCase
{
    /**
     * Lê uma constante do bloco de dados do portão sem que o valor vire literal
     * para o analisador estático. Lida direto, a constante seria resolvida para
     * a lista DESTE bot, e toda guarda escrita para um bloco de dados diferente
     * viraria "sempre verdadeiro" — a guarda sairia do teste por causa do
     * analisador, não por causa do que ela prova.
     */
    private static function doBlocoDeDados(string $nome): mixed
    {
        return constant('Quality\\' . $nome);
    }

    private string $raiz = '';
    private string $mensagemDeErro = '';

    protected function setUp(): void
    {
        $this->raiz = (string) tempnam(sys_get_temp_dir(), 'taxonomia');
        unlink($this->raiz);
        mkdir($this->raiz . '/docs', 0777, true);
        $this->escreverManifesto();
        $this->escreverConvencoes();
        $this->escreverInventario([]);
        foreach (self::categorias() as $categoria => $dados) {
            if (is_string($dados['diretorio'])) {
                $this->escreverTeste($dados['diretorio'] . '/Exemplo' . ucfirst($categoria) . 'Test.php', '');
            }
        }
    }

    protected function tearDown(): void
    {
        $this->apagar($this->raiz);
    }

    private function apagar(string $caminho): void
    {
        if (is_file($caminho)) {
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

    /** @param array<string, mixed> $ajustes */
    private function escreverManifesto(array $ajustes = []): void
    {
        $scripts = [];
        foreach (self::categorias() as $dados) {
            $scripts[$dados['comando']] = 'echo';
        }
        $this->escrever(
            'composer.json',
            (string) json_encode(['scripts' => array_replace($scripts, $ajustes)]),
        );
    }

    private function escreverConvencoes(string $extra = ''): void
    {
        $linhas = "# Convenções\n\n| Comando | Situação |\n| --- | --- |\n";
        foreach (self::naoAplicaveis() as $categoria) {
            $linhas .= '| `test:' . $categoria . "` | não se aplica |\n";
        }
        $this->escrever('docs/TESTES.md', $linhas . $extra);
    }

    /** @param list<array<string, string>> $quarentena */
    private function escreverInventario(array $quarentena, string $secao = 'quarentena'): void
    {
        $inventario = ['guardas_de_ambiente' => [], 'quarentena' => []];
        $inventario[$secao] = $quarentena;
        $this->escrever('tools/quality/testes-em-quarentena.json', (string) json_encode($inventario));
    }

    private function escreverTeste(string $relativo, string $corpo): void
    {
        $this->escrever($relativo, "<?php\n\nfinal class Exemplo\n{\n    public function testAlgo(): void\n    {\n" . $corpo . "    }\n}\n");
    }

    private function escrever(string $relativo, string $conteudo): void
    {
        $destino = $this->raiz . '/' . $relativo;
        $diretorio = dirname($destino);
        if (!is_dir($diretorio)) {
            mkdir($diretorio, 0777, true);
        }
        file_put_contents($destino, $conteudo);
    }

    /** A saída é engolida: `beStrictAboutOutputDuringTests` reprova quem escreve. */
    private function rodar(): int
    {
        $destino = fopen('php://memory', 'w+');
        self::assertIsResource($destino);
        ob_start();
        try {
            $codigo = mainTaxonomia(['check_taxonomia_testes.php', '--raiz=' . $this->raiz], $destino);
        } finally {
            ob_end_clean();
        }
        rewind($destino);
        $this->mensagemDeErro = (string) stream_get_contents($destino);
        fclose($destino);

        return $codigo;
    }

    /**
     * As constantes do bloco de dados são lidas por `constant()` de propósito:
     * lidas direto, o analisador estático resolveria os valores DESTE bot e
     * apontaria "sempre verdadeiro" nas guardas que existem para um bloco de
     * dados diferente — e a guarda sairia do teste por causa do analisador.
     *
     * @return array<string, array{comando: string, diretorio: string|null}>
     */
    private static function categorias(): array
    {
        /** @var array<string, array{comando: string, diretorio: string|null}> $categorias */
        $categorias = self::doBlocoDeDados('CATEGORIAS_DE_TESTE');

        return $categorias;
    }

    /** @return list<string> */
    private static function naoAplicaveis(): array
    {
        /** @var list<string> $lista */
        $lista = self::doBlocoDeDados('CATEGORIAS_NAO_APLICAVEIS');

        return $lista;
    }

    /** Primeiro diretório de categoria declarado; a árvore de mentira sempre tem um. */
    private function primeiroDiretorio(): string
    {
        foreach (self::categorias() as $dados) {
            if (is_string($dados['diretorio'])) {
                return $dados['diretorio'];
            }
        }

        self::fail('Nenhuma categoria com diretório: o bloco de dados está vazio.');
    }

    public function testArvoreConformeAprova(): void
    {
        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
    }

    public function testEsteRepositorioEstaConformeATaxonomia(): void
    {
        $destino = fopen('php://memory', 'w+');
        self::assertIsResource($destino);
        ob_start();
        try {
            $codigo = mainTaxonomia(['check_taxonomia_testes.php'], $destino);
        } finally {
            ob_end_clean();
        }
        rewind($destino);
        $mensagem = (string) stream_get_contents($destino);
        fclose($destino);

        self::assertSame(0, $codigo, $mensagem);
    }

    public function testArquivoDeTesteForaDasCategoriasReprova(): void
    {
        $this->escreverTeste('tests/Avulso/SoltoTest.php', '');

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('não pertence a nenhuma categoria', $this->mensagemDeErro);
    }

    public function testCategoriaSemComandoNoManifestoReprova(): void
    {
        $this->escrever('composer.json', (string) json_encode(['scripts' => []]));

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('não existe', $this->mensagemDeErro);
    }

    public function testComandoQueNaoSelecionaNadaReprova(): void
    {
        $diretorio = $this->primeiroDiretorio();
        foreach ((array) glob($this->raiz . '/' . $diretorio . '/*Test.php') as $arquivo) {
            if (is_string($arquivo)) {
                unlink($arquivo);
            }
        }

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('não seleciona nada', $this->mensagemDeErro);
    }

    public function testCategoriaNaoAplicavelComComandoReprova(): void
    {
        $naoAplicaveis = self::naoAplicaveis();
        if ($naoAplicaveis === []) {
            // Repositório sem não aplicabilidade declarada: não há o que violar,
            // e pular seria esconder que a regra não foi exercitada aqui.
            self::assertSame(0, $this->rodar(), $this->mensagemDeErro);

            return;
        }
        $this->escreverManifesto(['test:' . $naoAplicaveis[0] => 'echo']);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('não aplicável', $this->mensagemDeErro);
    }

    public function testNaoAplicabilidadeSemLinhaNaDocumentacaoReprova(): void
    {
        if (self::naoAplicaveis() === []) {
            self::assertSame(0, $this->rodar(), $this->mensagemDeErro);

            return;
        }
        $this->escrever('docs/TESTES.md', "# Convenções\n\nsem tabela\n");

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('não tem a linha que declara', $this->mensagemDeErro);
    }

    public function testFocoCommitadoReprova(): void
    {
        $this->escreverTeste(
            $this->primeiroDiretorio() . '/FocadoTest.php',
            '        $suite' . \Quality\MARCAS_DE_FOCO[2] . ");\n",
        );

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('foco commitado', $this->mensagemDeErro);
    }

    public function testEsperaPeloRelogioReprova(): void
    {
        $this->escreverTeste(
            $this->primeiroDiretorio() . '/LentoTest.php',
            '        ' . \Quality\MARCAS_DE_ESPERA[0] . "1);\n",
        );

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('retry escondido', $this->mensagemDeErro);
    }

    public function testFiltroFixoNoManifestoReprova(): void
    {
        $this->escreverManifesto(['test' => 'phpunit --filter Fluxo']);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('fixa --filter', $this->mensagemDeErro);
    }

    public function testPuloSemRegistroReprova(): void
    {
        $this->escreverTeste(
            $this->primeiroDiretorio() . '/PuladoTest.php',
            '        $this->' . \Quality\MARCAS_DE_PULO[0] . "('sem motivo');\n",
        );

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('pulado sem registro', $this->mensagemDeErro);
    }

    public function testPuloRegistradoComoGuardaDeAmbienteAprova(): void
    {
        $relativo = $this->primeiroDiretorio() . '/PuladoTest.php';
        $this->escreverTeste(
            $relativo,
            '        $this->' . \Quality\MARCAS_DE_PULO[0] . "('ext ausente');\n",
        );
        $this->escreverInventario([[
            'arquivo' => $relativo,
            'teste' => 'testAlgo',
            'motivo' => 'extensão ausente na máquina',
            'registrado_em' => '2026-08-30',
            'responsavel' => 'dealni',
        ]], 'guardas_de_ambiente');

        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
    }

    public function testQuarentenaVencidaReprova(): void
    {
        $relativo = $this->primeiroDiretorio() . '/PuladoTest.php';
        $this->escreverTeste(
            $relativo,
            '        $this->' . \Quality\MARCAS_DE_PULO[0] . "('instável');\n",
        );
        $this->escreverInventario([[
            'arquivo' => $relativo,
            'teste' => 'testAlgo',
            'motivo' => 'instável sob carga',
            'registrado_em' => '2026-01-01',
            'responsavel' => 'dealni',
            'prazo' => '2026-01-31',
        ]]);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('quarentena vencida', $this->mensagemDeErro);
    }

    public function testRegistroOrfaoReprova(): void
    {
        $this->escreverInventario([[
            'arquivo' => $this->primeiroDiretorio() . '/SumiuTest.php',
            'teste' => 'testAlgo',
            'motivo' => 'era instável',
            'registrado_em' => '2026-08-30',
            'responsavel' => 'dealni',
            'prazo' => '2099-01-01',
        ]]);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('não pula mais nada', $this->mensagemDeErro);
    }

    public function testEntradaSemMotivoOuResponsavelReprova(): void
    {
        $relativo = $this->primeiroDiretorio() . '/PuladoTest.php';
        $this->escreverTeste(
            $relativo,
            '        $this->' . \Quality\MARCAS_DE_PULO[0] . "('x');\n",
        );
        $this->escreverInventario([['arquivo' => $relativo, 'teste' => 'testAlgo']]);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('entrada sem', $this->mensagemDeErro);
    }
}
