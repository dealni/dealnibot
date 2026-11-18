<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function Quality\baselineEhSomenteLeitura;
use function Quality\caminhoDoBaseline;
use function Quality\gravarSecaoDoBaseline;
use function Quality\lerBaseline;
use function Quality\numeroDoBaseline;

require_once __DIR__ . '/../../scripts/quality/baseline_tamanho.php';

final class PersistenciaBaselineTamanhoTest extends TestCase
{
    private string|false $anterior;
    private string $arquivo;

    protected function setUp(): void
    {
        $this->anterior = getenv('CHATBOTS_BASELINE');
        $arquivo = tempnam(sys_get_temp_dir(), 'tamanho_');
        self::assertIsString($arquivo);
        $this->arquivo = $arquivo;
        putenv('CHATBOTS_BASELINE=' . $arquivo);
    }

    protected function tearDown(): void
    {
        putenv($this->anterior === false ? 'CHATBOTS_BASELINE' : 'CHATBOTS_BASELINE=' . $this->anterior);
        if (is_file($this->arquivo)) {
            unlink($this->arquivo);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function documentosInvalidos(): iterable
    {
        yield 'JSON quebrado' => ['{'];
        yield 'escalar' => ['12'];
        yield 'nulo' => ['null'];
    }

    #[DataProvider('documentosInvalidos')]
    public function testDocumentoCorrompidoNaoViraDividaVazia(string $conteudo): void
    {
        file_put_contents($this->arquivo, $conteudo);
        $this->expectException(UnexpectedValueException::class);
        lerBaseline();
    }

    public function testPrimeiraGravacaoMedeSomenteSecaoDeTamanhoNaFixture(): void
    {
        unlink($this->arquivo);
        self::assertSame([], lerBaseline());
        gravarSecaoDoBaseline('tamanho', ['arquivos_medidos' => 12, 'arquivos' => [], 'funcoes' => []]);
        self::assertSame(['tamanho' => ['arquivos_medidos' => 12, 'arquivos' => [], 'funcoes' => [], 'medido_em' => date('Y-m-d')]], lerBaseline());
        self::assertSame($this->arquivo, caminhoDoBaseline());
    }

    public function testCaminhoPadraoSoApontaParaRatchetDeTamanho(): void
    {
        putenv('CHATBOTS_BASELINE');
        self::assertSame(dirname(__DIR__, 2) . '/tools/quality/tamanho-baseline.json', caminhoDoBaseline());
    }

    public function testNumerosInteirosEReaisSaoValidadosSemAceitarTexto(): void
    {
        self::assertSame(['numero' => 12.0, 'inteiro' => 12], numeroDoBaseline(['a' => 12], 'a'));
        self::assertSame(['numero' => 12.5, 'inteiro' => 12], numeroDoBaseline(['a' => 12.5], 'a'));
        $this->expectException(UnexpectedValueException::class);
        numeroDoBaseline(['a' => '12'], 'a');
    }

    public function testCiNaoAdmiteRegravacaoAutomatica(): void
    {
        $anterior = getenv('CI');
        try {
            foreach (['1' => true, 'true' => true, 'false' => false, '0' => false, '' => false] as $valor => $esperado) {
                putenv('CI=' . $valor);
                self::assertSame($esperado, baselineEhSomenteLeitura());
            }
            putenv('CI');
            self::assertFalse(baselineEhSomenteLeitura());
        } finally {
            putenv($anterior === false ? 'CI' : 'CI=' . $anterior);
        }
    }
}
