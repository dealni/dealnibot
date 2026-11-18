<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ExecutorCli;

require_once __DIR__ . '/../../inclusos/ConsultaBanco.php';

final class ConsultaBancoLimitesTest extends TestCase
{
    /** @return array<string, array{mixed, string}> */
    public static function linhasInvalidas(): array
    {
        return [
            'resultado escalar' => ['linha', 'Linha do banco inválida.'],
            'nome numérico' => [[0 => 'valor'], 'Valor do banco fora do contrato escalar.'],
            'valor composto' => [['dados' => ['interno']], 'Valor do banco fora do contrato escalar.'],
            'objeto' => [['dados' => new \stdClass()], 'Valor do banco fora do contrato escalar.'],
        ];
    }
    #[DataProvider('linhasInvalidas')]
    public function testFronteiraRecusaLinhaForaDoContratoEscalar(mixed $linha, string $mensagem): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($linha);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($mensagem);
        \ConsultaBanco::linha($stmt);
    }
    public function testColunaAusenteDistingueNullDeFimDaConsulta(): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls([], false);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('query')->willReturn($stmt);
        self::assertNull(\ConsultaBanco::coluna($pdo, 'SELECT valor'));
        self::assertFalse(\ConsultaBanco::coluna($pdo, 'SELECT valor'));
    }
    public function testColunaCompostaNaoEhConvertidaSilenciosamente(): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetch')->willReturn([[1]]);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('query')->willReturn($stmt);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Coluna do banco inválida.');
        \ConsultaBanco::coluna($pdo, 'SELECT valor');
    }
    public function testMapaPreservaChavesAssociativasEConverteValoresEscalares(): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['nome' => 'zero', 'valor' => 0],
            ['nome' => 'decimal', 'valor' => 1.5],
            ['nome' => 'nulo', 'valor' => null],
            ['nome' => 'booleano', 'valor' => true],
            ['nome' => 'zero', 'valor' => 'atualizado'],
            false
        );
        self::assertSame(['zero' => 'atualizado', 'decimal' => '1.5', 'nulo' => '', 'booleano' => '1'], \ConsultaBanco::mapaStatement($stmt));
    }

    /** @return array<string, array{array<string, scalar|null>}> */
    public static function mapasInvalidos(): array
    {
        return [
            'sem colunas' => [[]],
            'uma coluna' => [['nome' => 'chave']],
            'tres colunas' => [['nome' => 'chave', 'valor' => 'valor', 'extra' => 'extra']],
            'chave numerica' => [['nome' => 123, 'valor' => 'valor']],
            'chave nula' => [['nome' => null, 'valor' => 'valor']],
        ];
    }

    /** @param array<string, scalar|null> $linha */
    #[DataProvider('mapasInvalidos')]
    public function testMapaRecusaQuantidadeDeColunasEChaveForaDoContrato(array $linha): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($linha);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Mapa do banco inválido.');
        \ConsultaBanco::mapaStatement($stmt);
    }

    public function testFalhaDoSistemaAoEnumerarMigrationsNaoViraListaVazia(): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'glob_falha_');
        // glob pode devolver false em falha do sistema; o dublê preserva esse contrato.
        file_put_contents($script, <<<'RUNNER'
<?php
function glob(string $pattern, int $flags = 0): array|false { return false; }
require $argv[1] . '/inclusos/ConsultaBanco.php';
try { ConsultaBanco::arquivos('/migrations/*.sql'); } catch (RuntimeException $e) { echo $e->getMessage(); }
RUNNER);
        try {
            $r = ExecutorCli::executar($script, [dirname(__DIR__, 2)], [], ['disable_functions' => 'glob']);
            self::assertSame(0, $r['codigo'], $r['stderr']);
            self::assertSame('Não foi possível listar migrations.', $r['stdout']);
            self::assertSame('', $r['stderr']);
        } finally {
            unlink($script);
        }
    }
}
