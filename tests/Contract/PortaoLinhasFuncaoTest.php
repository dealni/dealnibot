<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function Quality\mainLinhasDeFuncao;
use function Quality\tamanhosDasFuncoes;

require_once __DIR__ . '/../../scripts/quality/check_linhas_funcao.php';

final class PortaoLinhasFuncaoTest extends TestCase
{
    private string $raiz = '';
    private string $baseline = '';
    private string $diretorio = '';
    private string|false $ciAnterior = false;
    private string|false $baselineAnterior = false;

    private static function alvos(): mixed
    {
        return constant('Quality\\ALVOS_DE_TAMANHO');
    }

    protected function setUp(): void
    {
        $this->ciAnterior = getenv('CI');
        $this->baselineAnterior = getenv('CHATBOTS_BASELINE');
        $this->raiz = (string) tempnam(sys_get_temp_dir(), 'funcao');
        unlink($this->raiz);
        /** @var list<string> $alvos */
        $alvos = self::alvos();
        $this->diretorio = $alvos[0];
        mkdir($this->raiz . '/' . $this->diretorio, 0777, true);
        $this->baseline = $this->raiz . '/baseline.json';
        putenv('CI');
        putenv('CHATBOTS_BASELINE=' . $this->baseline);
    }

    protected function tearDown(): void
    {
        putenv($this->ciAnterior === false ? 'CI' : 'CI=' . $this->ciAnterior);
        putenv($this->baselineAnterior === false ? 'CHATBOTS_BASELINE' : 'CHATBOTS_BASELINE=' . $this->baselineAnterior);
        $arquivos = glob($this->raiz . '/' . $this->diretorio . '/*');
        foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->raiz . '/' . $this->diretorio);
        if (is_file($this->baseline)) {
            unlink($this->baseline);
        }
        rmdir($this->raiz);
    }

    private function escrever(int $linhas): void
    {
        file_put_contents($this->raiz . '/' . $this->diretorio . '/alvo.php', self::funcao($linhas));
    }

    private static function funcao(int $linhas): string
    {
        return "<?php\nfunction alvo(): void\n{\n" . str_repeat("    \$valor = 1;\n", $linhas - 3) . "}\n";
    }

    private function rodar(bool $atualizar = false): int
    {
        $argumentos = ['check_linhas_funcao.php', '--raiz=' . $this->raiz];
        if ($atualizar) {
            $argumentos[] = '--atualizar-baseline';
        }
        $erro = fopen('php://memory', 'w+');
        self::assertIsResource($erro);
        ob_start();
        try {
            return mainLinhasDeFuncao($argumentos, $erro);
        } finally {
            ob_end_clean();
            fclose($erro);
        }
    }

    public function testContaCodigoMasNaoComentariosOuVazios(): void
    {
        $codigo = <<<'PHP'
<?php
function alvo(): void
{
    // comentário

    /* comentário
       multilinha */
    $a = 1; // comentário na linha com código
}
PHP;
        self::assertSame(['alvo' => [4]], tamanhosDasFuncoes($codigo));
    }

    public function testHeredocContaDadosSemTratarTextoComoComentario(): void
    {
        $codigo = <<<'PHP'
<?php
function alvo(): string
{
    return <<<'TEXTO'
// dado

/* dado */
TEXTO;
}
PHP;
        self::assertSame(['alvo' => [7]], tamanhosDasFuncoes($codigo));
    }

    public function testCallbackTipadoTemIdentidadeEstavelAoMoverLinhasEOuCorpo(): void
    {
        $primeiro = '<?php $fn = function (int $a): string { return (string) $a; };';
        $segundo = "<?php\n// mudou a posição\n\$fn = function (int \$a): string {\n return 'novo';\n};";
        $nomes = array_keys(tamanhosDasFuncoes($primeiro));
        self::assertCount(1, $nomes);
        self::assertStringStartsWith('callback:', $nomes[0]);
        self::assertSame($nomes, array_keys(tamanhosDasFuncoes($segundo)));
    }

    public function testNomesDeClassesDistinguemMetodos(): void
    {
        self::assertSame(['A/alvo' => [1], 'B/alvo' => [1]], tamanhosDasFuncoes('<?php class A {function alvo(){}} class B {function alvo(){}}'));
    }

    public function testArrowENestedSaoMedidosSeparadamente(): void
    {
        $medida = tamanhosDasFuncoes('<?php function alvo(){ $fn = fn() => 1; }');
        self::assertCount(2, $medida);
        self::assertSame([1], $medida['alvo']);
    }

    public function testCodigoInvalidoReprovaEmVezDeAprovarSemFuncoes(): void
    {
        $this->expectException(UnexpectedValueException::class);
        tamanhosDasFuncoes('<?php function quebrada( {');
    }

    public function testPrimeiraMedicaoExigeCriacaoExplicita(): void
    {
        $this->escrever(151);
        $this->expectException(UnexpectedValueException::class);
        $this->rodar();
    }

    public function testLimite150Passa151NovoReprovaSemRegravar(): void
    {
        $this->escrever(150);
        self::assertSame(0, $this->rodar(true));
        $antes = file_get_contents($this->baseline);
        $this->escrever(151);
        self::assertSame(1, $this->rodar());
        self::assertSame($antes, file_get_contents($this->baseline));
    }

    public function testAte150NaoEmiteAvisosConsultivosNemGravaObjetivo(): void
    {
        $this->escrever(150);
        ob_start();
        try {
            $status = mainLinhasDeFuncao(['check_linhas_funcao.php', '--raiz=' . $this->raiz, '--atualizar-baseline']);
        } finally {
            $saida = (string) ob_get_clean();
        }
        self::assertSame(0, $status);
        self::assertSame("Funções: 0 acima do limite de 150 linhas efetivas.\n", $saida);
        $dados = json_decode((string) file_get_contents($this->baseline), true);
        self::assertIsArray($dados);
        self::assertIsArray($dados['linhas_de_funcao']);
        self::assertArrayNotHasKey('objetivo', $dados['linhas_de_funcao']);
    }

    public function testMelhoriaApertaCapEImpedeRecuperarDivida(): void
    {
        $this->escrever(170);
        self::assertSame(0, $this->rodar(true));
        $this->escrever(160);
        self::assertSame(0, $this->rodar());
        $this->escrever(165);
        self::assertSame(1, $this->rodar());
    }

    public function testReduzirAte150RemoveCapEImpedeRecuperarDivida(): void
    {
        $this->escrever(170);
        self::assertSame(0, $this->rodar(true));
        $this->escrever(150);
        self::assertSame(0, $this->rodar());
        $dados = json_decode((string) file_get_contents($this->baseline), true);
        self::assertIsArray($dados);
        self::assertIsArray($dados['linhas_de_funcao']);
        self::assertSame([], $dados['linhas_de_funcao']['excecoes']);
        $this->escrever(151);
        self::assertSame(1, $this->rodar());
    }

    public function testCiNaoRegravaMelhoriaENaoPermiteAtualizacao(): void
    {
        $this->escrever(170);
        self::assertSame(0, $this->rodar(true));
        $antes = file_get_contents($this->baseline);
        $this->escrever(160);
        putenv('CI=1');
        self::assertSame(0, $this->rodar());
        self::assertSame($antes, file_get_contents($this->baseline));
        $this->expectException(UnexpectedValueException::class);
        $this->rodar(true);
    }

    public function testEscopoVazioReprova(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->rodar(true);
    }

    public function testClassConstantNaoAbreEscopoDeClasse(): void
    {
        $codigo = '<?php function alvo(){ $nome = Foo::class; if ($nome) { $x = 1; } $fn = fn() => 1; }';
        $identidades = array_keys(tamanhosDasFuncoes($codigo));
        self::assertCount(2, $identidades);
        self::assertStringStartsWith('alvo/callback:', $identidades[1]);
    }

    public function testMetodoAbstratoNaoAbsorveOMetodoSeguinte(): void
    {
        self::assertSame(['A/concreto' => [1]], tamanhosDasFuncoes('<?php abstract class A {abstract function ausente(): void; function concreto(): void {}}'));
    }

    public function testBaselineComLimiarIncompativelReprova(): void
    {
        $this->escrever(150);
        self::assertSame(0, $this->rodar(true));
        $dados = json_decode((string) file_get_contents($this->baseline), true);
        self::assertIsArray($dados);
        self::assertIsArray($dados['linhas_de_funcao']);
        $dados['linhas_de_funcao']['limite'] = 500;
        file_put_contents($this->baseline, (string) json_encode($dados));
        $this->expectException(UnexpectedValueException::class);
        $this->rodar();
    }

    public function testBaselineComObjetivoLegadoReprova(): void
    {
        $this->escrever(150);
        self::assertSame(0, $this->rodar(true));
        $dados = json_decode((string) file_get_contents($this->baseline), true);
        self::assertIsArray($dados);
        self::assertIsArray($dados['linhas_de_funcao']);
        $dados['linhas_de_funcao']['objetivo'] = 50;
        file_put_contents($this->baseline, (string) json_encode($dados));
        $this->expectException(UnexpectedValueException::class);
        $this->rodar();
    }

    public function testClasseAnonimaNaoRoubaNomeDaSuperclasse(): void
    {
        $codigo = '<?php class A {} $a = new class extends A {function alvo(): void {}};';
        $identidades = array_keys(tamanhosDasFuncoes($codigo));
        self::assertCount(1, $identidades);
        self::assertStringStartsWith('callback:', $identidades[0]);
        self::assertStringEndsWith('/alvo', $identidades[0]);
    }

    public function testComposerMantemOsDoisGatesNoMesmoComando(): void
    {
        $manifesto = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        self::assertIsArray($manifesto);
        self::assertIsArray($manifesto['scripts']);
        self::assertSame([
            '@php scripts/quality/check_tamanho.php',
            '@php scripts/quality/check_linhas_funcao.php',
        ], $manifesto['scripts']['check:complexity']);
    }

    public function testModificadoresMultilinhaContamNaFronteira150(): void
    {
        $codigo = "<?php class A {\npublic\nstatic\nfunction alvo(): void\n{\n" . str_repeat("    \$a = 1;\n", 147) . "}\n}";
        self::assertSame(['A/alvo' => [152]], tamanhosDasFuncoes($codigo));
        $codigo = str_replace("public\nstatic\n", '', $codigo);
        self::assertSame(['A/alvo' => [150]], tamanhosDasFuncoes($codigo));
    }

    public function testAtributosDeDeclaracaoContamMasComentariosNao(): void
    {
        $codigo = "<?php class A {\n#[\n    Exemplo('valor'),\n]\n// comentário\npublic\nfunction alvo() {}\n}";
        self::assertSame(['A/alvo' => [5]], tamanhosDasFuncoes($codigo));
    }

    public function testCallbacksIndistinguiveisNaoCompartilhamCap(): void
    {
        $callback = '$fn = function () {' . "\n" . str_repeat("    \$valor = 1;\n", 151) . '};';
        file_put_contents($this->raiz . '/' . $this->diretorio . '/alvo.php', '<?php ' . $callback . $callback);
        $this->expectException(UnexpectedValueException::class);
        $this->rodar(true);
    }
}
