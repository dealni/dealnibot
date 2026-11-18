<?php

declare(strict_types=1);

namespace Tests;

use bdTelegram;
use PHPUnit\Framework\TestCase;

/**
 * Orçamento de desempenho — a carga mínima que reprova regressão grosseira.
 *
 * Teste de desempenho medido em relógio é a receita conhecida de portão
 * instável: a máquina do CI oscila, alguém sobe a folga, e o portão vira
 * enfeite. Por isso o orçamento aqui tem duas metades, e a determinística é a
 * que manda:
 *
 * - **plano de consulta** (determinístico): a busca por `id_telegram` tem de
 *   usar índice. Perder o UNIQUE do schema transforma cada update recebido em
 *   varredura da tabela inteira — a regressão de desempenho mais provável deste
 *   bot, e a que nenhuma medição de tempo pega enquanto a base é pequena.
 * - **vazão** (relógio, com folga grande): mil cadastros e mil buscas dentro de
 *   um teto que a máquina mais lenta cumpre com sobra. Ele não mede
 *   performance: ele pega o O(n²) que alguém acabou de introduzir.
 *
 */
final class OrcamentoDeDesempenhoTest extends TestCase
{
    /** Cadastros e buscas de cada rodada de carga. */
    private const CARGA = 1000;

    /**
     * Teto de 10 s para ~2000 operações. O tempo observado numa máquina de
     * desenvolvimento é de centenas de milissegundos; a folga é de uma ordem de
     * grandeza de propósito, porque o que se quer pegar é a regressão
     * grosseira, não a variação da máquina.
     */
    private const TETO_SEGUNDOS = 10.0;

    private string $arquivo = '';
    private ?bdTelegram $bd = null;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../inclusos/bdTelegram.php';

        $this->arquivo = (string) tempnam(sys_get_temp_dir(), 'dealnibot_carga_');
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        self::assertIsString($schema);
        $pdo = \BancoTeste::conectar($this->arquivo);
        $pdo->exec($schema);
        $pdo = null;

        $this->bd = new bdTelegram(\BancoTeste::dsn($this->arquivo));
    }

    protected function tearDown(): void
    {
        $this->bd = null;
        if ($this->arquivo !== '' && file_exists($this->arquivo)) {
            @unlink($this->arquivo);
        }
    }

    private function bd(): bdTelegram
    {
        self::assertInstanceOf(bdTelegram::class, $this->bd);

        return $this->bd;
    }

    public function testBuscaPorIdDoTelegramUsaIndice(): void
    {
        $pdo = \BancoTeste::conectar($this->arquivo);
        $pdo->exec('INSERT INTO usuarios (id_telegram) SELECT generate_series(1, 10000)');
        $pdo->exec('ANALYZE usuarios');
        $plano = implode("\n", \ConsultaBanco::nomes($pdo, 'EXPLAIN SELECT * FROM usuarios WHERE id_telegram = 1'));
        self::assertStringContainsString('Index', $plano);
        self::assertStringNotContainsString('Seq Scan', $plano);
    }

    public function testCadastroEBuscaEmMassaCabemNoOrcamento(): void
    {
        $bd = $this->bd();
        $inicio = hrtime(true);

        for ($i = 1; $i <= self::CARGA; $i++) {
            $bd->addUsuario(9_000_000 + $i);
        }
        for ($i = 1; $i <= self::CARGA; $i++) {
            self::assertNotFalse($bd->getUsuario(9_000_000 + $i));
        }

        $segundos = (hrtime(true) - $inicio) / 1_000_000_000;

        self::assertLessThan(
            self::TETO_SEGUNDOS,
            $segundos,
            sprintf(
                '%d cadastros e %d buscas levaram %.2f s (teto %.2f s). Um teto estourado por uma '
                . 'ordem de grandeza é mudança de complexidade, não máquina lenta.',
                self::CARGA,
                self::CARGA,
                $segundos,
                self::TETO_SEGUNDOS,
            ),
        );
    }
}
