<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function Quality\complexidadeDasFuncoes;
use function Quality\mainTamanho;

require_once __DIR__ . '/../../scripts/quality/check_tamanho.php';

/**
 * O ratchet de tamanho e complexidade, provado pelos quatro jeitos de burlá-lo.
 *
 * Um ratchet que só é exercitado pelo caminho verde é indistinguível de um
 * `exit 0`: ele passa desde o primeiro dia, e a condição invertida nunca
 * aparece. Aqui a árvore é de mentira e o baseline é temporário — o
 * `tools/quality/tamanho-baseline.json` de verdade não pode ser escrito por
 * teste, senão o portão passaria a aprovar o que ele mesmo acabou de gravar.
 */
final class PortaoDeTamanhoTest extends TestCase
{
    /**
     * Lê uma constante do bloco de dados do portão sem que o valor vire literal
     * para o analisador estático. Lida direto, a constante seria resolvida para
     * a lista gravada hoje, e toda guarda escrita para um bloco de dados
     * diferente viraria "sempre verdadeiro" — o teste perderia a guarda por
     * causa do analisador, não por decisão de quem o escreveu.
     */
    private static function doBlocoDeDados(string $nome): mixed
    {
        return constant('Quality\\' . $nome);
    }

    private string $raiz = '';
    private string $baseline = '';
    private string $mensagemDeErro = '';

    protected function setUp(): void
    {
        $this->raiz = (string) tempnam(sys_get_temp_dir(), 'tamanho');
        unlink($this->raiz);
        mkdir($this->raiz . '/' . $this->diretorioDoProduto(), 0777, true);
        $this->baseline = (string) tempnam(sys_get_temp_dir(), 'baseline');
        unlink($this->baseline);
        putenv('CHATBOTS_BASELINE=' . $this->baseline);
        putenv('CI');
    }

    protected function tearDown(): void
    {
        putenv('CHATBOTS_BASELINE');
        putenv('CI');
        if (is_file($this->baseline)) {
            unlink($this->baseline);
        }
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

    /** O primeiro alvo do bloco de dados; é o que a árvore de mentira preenche. */
    private function diretorioDoProduto(): string
    {
        // `constant()` em vez da constante direta: lida direto, ela seria
        // resolvida pelo analisador para o valor DESTE bot, e a guarda abaixo
        // viraria "sempre verdadeiro" — quebrando a identidade do arquivo nos
        // quatro repositórios.
        /** @var list<string> $alvos */
        $alvos = self::doBlocoDeDados('ALVOS_DE_TAMANHO');
        self::assertNotSame([], $alvos, 'O bloco de dados não declara diretório de produto.');

        return $alvos[0];
    }

    private function escrever(string $nome, string $conteudo): void
    {
        file_put_contents($this->raiz . '/' . $this->diretorioDoProduto() . '/' . $nome, $conteudo);
    }

    /** Arquivo com o número de linhas pedido e uma função de complexidade conhecida. */
    private function escreverArquivo(string $nome, int $linhas, int $ramos): void
    {
        $corpo = "<?php\n\nfunction f_" . substr($nome, 0, -4) . "(int \$n): int\n{\n";
        for ($i = 0; $i < $ramos; $i++) {
            $corpo .= "    if (\$n === {$i}) {\n        return {$i};\n    }\n";
        }
        $corpo .= "    return -1;\n}\n";
        $faltam = $linhas - substr_count($corpo, "\n");
        for ($i = 0; $i < $faltam; $i++) {
            $corpo .= "// preenchimento {$i}\n";
        }
        $this->escrever($nome, $corpo);
    }

    /** @param list<string> $argumentos */
    private function rodar(array $argumentos = []): int
    {
        $destino = fopen('php://memory', 'w+');
        self::assertIsResource($destino);
        ob_start();
        try {
            $codigo = mainTamanho(
                array_merge(['check_tamanho.php', '--raiz=' . $this->raiz], $argumentos),
                $destino,
            );
        } finally {
            ob_end_clean();
        }
        rewind($destino);
        $this->mensagemDeErro = (string) stream_get_contents($destino);
        fclose($destino);

        return $codigo;
    }

    /** @return array<string, mixed> */
    private function secaoDoBaseline(): array
    {
        $dados = json_decode((string) file_get_contents($this->baseline), true);
        self::assertIsArray($dados);
        $secao = $dados['tamanho'] ?? null;
        self::assertIsArray($secao);

        /** @var array<string, mixed> $secao */
        return $secao;
    }

    public function testComplexidadeContaCadaCaminhoDeExecucao(): void
    {
        $funcoes = complexidadeDasFuncoes(
            "<?php\nfunction alvo(\$a, \$b) {\n"
            . "    if (\$a && \$b) { return 1; }\n"
            . "    foreach ([] as \$x) { \$a = \$x ?? 0; }\n"
            . "    return \$a ? 2 : 3;\n}\n",
        );

        // 1 (base) + if + && + foreach + ?? + ternário.
        self::assertSame(['alvo' => 6], $funcoes);
    }

    public function testArrowsSeparadasDelimitamTernariosEExpressoesAninhadas(): void
    {
        $codigo = <<<'PHP'
<?php
$a = [fn(?int $x): ?int => $x ?? 0, fn($x) => $x && true];
$b = $ok ? fn($x) => $x ?: 0 : ($fora ? 1 : 2);
$c = fn($x) => $x ? (fn($y) => $y || false) : (fn($z) => $z ?? 1);
$d = fn() => [fn() => true && false, 'a' => 1];
PHP;
        self::assertSame([
            'arrow@2' => 2, 'arrow@2#2' => 2, 'arrow@3' => 2,
            'arrow@4' => 4, 'arrow@4#2' => 2, 'arrow@4#3' => 2,
            'arrow@5' => 2, 'arrow@5#2' => 2,
        ], complexidadeDasFuncoes($codigo));
    }

    public function testDecisoesInternasContamUmaVezNaFuncaoContinente(): void
    {
        self::assertSame(['alvo' => 4, 'closure@3' => 2, 'arrow@4' => 2], complexidadeDasFuncoes(<<<'PHP'
<?php
function alvo() {
    $a = function () { if (true) { return 1; } };
    $b = fn() => true && false;
    return false ? 1 : 0;
}
PHP));
    }

    public function testMatchContaCondicoesComoCaseSemContarDefaultOuOutrosArrows(): void
    {
        self::assertSame(['alvo' => 6, 'arrow@5' => 2], complexidadeDasFuncoes(<<<'PHP'
<?php
function alvo($x) {
    return match ($x) {
        1, 2 => ['a' => 1],
        3 => fn() => true && false,
        default => match ($x) { 4 => 1, default => 0 },
    };
}
PHP));
        self::assertSame(['alvo' => 5], complexidadeDasFuncoes(<<<'PHP'
<?php
function alvo($x) {
    switch ($x) { case 1: case 2: case 3: case 4: return 1; default: return 0; }
}
PHP));
    }

    public function testInterpolacaoNaoEncerraOCorpoENulabilidadeNaoEhTernario(): void
    {
        self::assertSame(['alvo' => 4, 'arrow@4' => 2], complexidadeDasFuncoes(<<<'PHP'
<?php
function alvo($x) {
    if ($x) { $mensagem = "Valor: {$x}"; }
    $a = fn(?int $n): ?int => $n ?? 0;
    if ($x) { return $mensagem; }
}
PHP));
    }

    public function testClasseAnonimaEStringsPreservamDelimitacaoSemDecisoesFalsas(): void
    {
        self::assertSame(['alvo' => 3, 'interno' => 2], complexidadeDasFuncoes(<<<'PHP'
<?php
function alvo() {
    $objeto = new class { function interno() { return true ? 1 : 0; } };
    $literal = '{ if (?) } => case &&';
    if ($objeto) { return $literal; }
}
PHP));
        self::assertSame(['arrow@2' => 2, 'arrow@2#2' => 2], complexidadeDasFuncoes(<<<'PHP'
<?php
$a = fn() => fn() => true && false;
PHP));
        self::assertSame(['alvo' => 2, 'arrow@2' => 1], complexidadeDasFuncoes(<<<'PHP'
<?php
function alvo() { return match(1) { fn()=>1 => 2, default => 0 }; }
PHP));
    }

    public function testAtributoDeMetodoNaoFechaClasseOuFuncaoContinente(): void
    {
        self::assertSame(['alvo' => 3, 'interno' => 2], complexidadeDasFuncoes(<<<'PHP'
<?php
function alvo() {
    $a = new class { #[A] function interno() { if (true) { return 1; } } };
    if ($a) { return 2; }
}
PHP));
    }

    public function testYieldComChaveNaoEncerraExpressaoDaArrow(): void
    {
        self::assertSame(['arrow@2' => 2], complexidadeDasFuncoes(<<<'PHP'
<?php
$a = fn() => yield 1 => true && false;
PHP));
    }

    public function testArrowNovaAcimaDoLimiteReprovaSemAlterarBaseline(): void
    {
        $this->escreverArquivo('pequeno.php', 10, 1);
        self::assertSame(0, $this->rodar(['--atualizar-baseline']));
        $antes = file_get_contents($this->baseline);
        $this->escrever('arrow.php', '<?php $a = fn() => ' . implode(' && ', array_fill(0, \Quality\LIMITE_COMPLEXIDADE + 1, 'true')) . ';');
        putenv('CI=1');
        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('arrow@1 tem ' . (\Quality\LIMITE_COMPLEXIDADE + 1), $this->mensagemDeErro);
        self::assertSame($antes, file_get_contents($this->baseline));
    }

    public function testMatchNoLimitePassaEAcimaDoLimiteReprova(): void
    {
        $this->escreverArquivo('pequeno.php', 10, 1);
        self::assertSame(0, $this->rodar(['--atualizar-baseline']));
        putenv('CI=1');
        $bracos = '';
        for ($i = 0; $i < \Quality\LIMITE_COMPLEXIDADE - 1; $i++) {
            $bracos .= $i . ' => 0, ';
        }
        $this->escrever('match.php', '<?php function alvo($x) { return match($x) {' . $bracos . 'default => 0}; }');
        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
        $this->escrever('match.php', '<?php function alvo($x) { return match($x) {' . $bracos . (\Quality\LIMITE_COMPLEXIDADE - 1) . ' => 0, default => 0}; }');
        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('alvo tem ' . (\Quality\LIMITE_COMPLEXIDADE + 1), $this->mensagemDeErro);
    }

    public function testFronteiras500LinhasE15CaminhosPassamEExcessosReprovam(): void
    {
        self::assertSame(500, self::doBlocoDeDados('LIMITE_LINHAS'));
        self::assertSame(15, self::doBlocoDeDados('LIMITE_COMPLEXIDADE'));
        $this->escreverArquivo('pequeno.php', 10, 1);
        self::assertSame(0, $this->rodar(['--atualizar-baseline']));
        putenv('CI=1');
        $antes = file_get_contents($this->baseline);
        $this->escreverArquivo('fronteira.php', 500, 14);
        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
        $this->escreverArquivo('fronteira.php', 501, 14);
        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('Arquivo fora do limite e sem registro', $this->mensagemDeErro);
        $this->escreverArquivo('fronteira.php', 500, 15);
        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('Função fora do limite e sem registro', $this->mensagemDeErro);
        self::assertSame($antes, file_get_contents($this->baseline));
    }

    public function testPrimeiraMedicaoExplicitaCriaOBaseline(): void
    {
        $this->escreverArquivo('grande.php', \Quality\LIMITE_LINHAS + 10, \Quality\LIMITE_COMPLEXIDADE + 3);

        self::assertSame(0, $this->rodar(['--atualizar-baseline']), $this->mensagemDeErro);
        $secao = $this->secaoDoBaseline();
        self::assertSame(1, $secao['arquivos_medidos']);
        self::assertIsArray($secao['excecoes_de_arquivo']);
        self::assertCount(1, $secao['excecoes_de_arquivo']);
        self::assertIsArray($secao['excecoes_de_funcao']);
        self::assertCount(1, $secao['excecoes_de_funcao']);
    }

    public function testBaselineAusenteReprovaSemCriacaoImplicita(): void
    {
        $this->escreverArquivo('pequeno.php', 10, 1);
        self::assertSame(2, $this->rodar());
        self::assertFileDoesNotExist($this->baseline);
    }

    public function testArquivoNovoAcimaDoLimiteReprova(): void
    {
        $this->escreverArquivo('pequeno.php', 10, 1);
        self::assertSame(0, $this->rodar(['--atualizar-baseline']));

        $this->escreverArquivo('inchado.php', \Quality\LIMITE_LINHAS + 1, 1);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('Arquivo fora do limite e sem registro', $this->mensagemDeErro);
    }

    public function testFuncaoNovaAcimaDoLimiteReprova(): void
    {
        $this->escreverArquivo('pequeno.php', 10, 1);
        self::assertSame(0, $this->rodar(['--atualizar-baseline']));

        $this->escreverArquivo('ramificado.php', 60, \Quality\LIMITE_COMPLEXIDADE + 1);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('Função fora do limite e sem registro', $this->mensagemDeErro);
    }

    public function testDividaQuePioraReprova(): void
    {
        $this->escreverArquivo('grande.php', \Quality\LIMITE_LINHAS + 10, 1);
        self::assertSame(0, $this->rodar(['--atualizar-baseline']));

        $this->escreverArquivo('grande.php', \Quality\LIMITE_LINHAS + 40, 1);

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('piorou', $this->mensagemDeErro);
    }

    public function testEscopoQueEncolheReprova(): void
    {
        $this->escreverArquivo('a.php', 10, 1);
        $this->escreverArquivo('b.php', 10, 1);
        self::assertSame(0, $this->rodar(['--atualizar-baseline']));

        unlink($this->raiz . '/' . $this->diretorioDoProduto() . '/b.php');

        self::assertSame(1, $this->rodar());
        self::assertStringContainsString('escopo medido encolheu', $this->mensagemDeErro);
    }

    public function testDividaQueCaiApertaOBaselineSozinha(): void
    {
        $this->escreverArquivo('grande.php', \Quality\LIMITE_LINHAS + 40, 1);
        self::assertSame(0, $this->rodar(['--atualizar-baseline']));

        $this->escreverArquivo('grande.php', \Quality\LIMITE_LINHAS + 5, 1);

        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
        $excecoes = $this->secaoDoBaseline()['excecoes_de_arquivo'];
        self::assertIsArray($excecoes);
        self::assertSame(\Quality\LIMITE_LINHAS + 5, $excecoes[$this->diretorioDoProduto() . '/grande.php']);
    }

    public function testNoCiOBaselineNaoEhRegravado(): void
    {
        $this->escreverArquivo('grande.php', \Quality\LIMITE_LINHAS + 40, 1);
        self::assertSame(0, $this->rodar(['--atualizar-baseline']));
        putenv('CI=1');

        $this->escreverArquivo('grande.php', \Quality\LIMITE_LINHAS + 5, 1);

        self::assertSame(0, $this->rodar(), $this->mensagemDeErro);
        $excecoes = $this->secaoDoBaseline()['excecoes_de_arquivo'];
        self::assertIsArray($excecoes);
        self::assertSame(\Quality\LIMITE_LINHAS + 40, $excecoes[$this->diretorioDoProduto() . '/grande.php']);
    }

    public function testBaselineSemAsExcecoesEhErroDeConfiguracaoENaoReprovacao(): void
    {
        $this->escreverArquivo('pequeno.php', 10, 1);
        file_put_contents(
            $this->baseline,
            (string) json_encode(['tamanho' => ['arquivos_medidos' => 1, 'medido_em' => '2026-01-01']]),
        );

        $this->expectException(UnexpectedValueException::class);
        $this->rodar();
    }
}
