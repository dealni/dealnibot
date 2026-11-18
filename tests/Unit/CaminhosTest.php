<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/caminhos.php';

/**
 * Resolução do caminho do banco (inclusos/caminhos.php).
 *
 * O arquivo só declara função, então o teste faz `require_once` de verdade e a
 * cobertura é atribuída ao arquivo de produção.
 *
 * Quem consome `caminhoBancoDoBot()` é o `dsnPadrao()` de
 * `inclusos/bdTelegram.php`: errar aqui faz o FPM e o CLI abrirem arquivos
 * diferentes — ou, pior, o banco do bot irmão.
 *
 * Nenhum arquivo real é tocado: os testes só leem a variável de ambiente.
 */
final class CaminhosTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const PREFIXO = 'DEALNI';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    protected function tearDown(): void
    {
        putenv(self::PREFIXO . '_DB_PATH');
        putenv(self::PREFIXO . '_SNAPSHOTS_DIR');
        putenv(self::PREFIXO . '_SNAPSHOTS_MANTER');
        putenv(self::PREFIXO . '_TESTE_INTEIRO');
    }

    public function testDiretorioDeSnapshotsAceitaVolumeExternoESemBarraFinal(): void
    {
        putenv(self::PREFIXO . '_SNAPSHOTS_DIR=/backups/bot/');

        $this->assertSame('/backups/bot', caminhoSnapshotsDoBot());
    }

    public function testDiretorioDeSnapshotsCaiNoCheckoutSemVariavelDeAmbiente(): void
    {
        putenv(self::PREFIXO . '_SNAPSHOTS_DIR');

        $this->assertSame(dirname(__DIR__, 2) . '/snapshots', caminhoSnapshotsDoBot());
    }

    public function testQuantidadeDeSnapshotsRecusaValorInvalidoEUsaOPadrao(): void
    {
        // Zero apagaria o snapshot recém-criado; texto e negativo viram zero em
        // (int), então precisam cair no padrão em vez de passar.
        foreach (['0', '-3', 'sete', '', ' '] as $valor) {
            putenv(self::PREFIXO . '_SNAPSHOTS_MANTER=' . $valor);
            $this->assertSame(7, quantosSnapshotsManter(), "valor recusado: '{$valor}'");
        }

        putenv(self::PREFIXO . '_SNAPSHOTS_MANTER');
        $this->assertSame(7, quantosSnapshotsManter());
    }

    public function testQuantidadeDeSnapshotsAceitaValorConfigurado(): void
    {
        putenv(self::PREFIXO . '_SNAPSHOTS_MANTER=30');

        $this->assertSame(30, quantosSnapshotsManter());
    }

    /**
     * O helper usado por quantosSnapshotsManter():
     * dígitos, zeros à esquerda tolerados, >= 1 e dentro de PHP_INT_MAX.
     */
    public function testInteiroPositivoDaEnvAceitaSoDigitosPositivosQueCabemNoInteiro(): void
    {
        putenv(self::PREFIXO . '_TESTE_INTEIRO');
        $this->assertNull(inteiroPositivoDaEnv(self::PREFIXO . '_TESTE_INTEIRO'), 'sem variável é null');

        $aceitos = [
            '1' => 1,
            '007' => 7,
            ' 12 ' => 12,
            (string) PHP_INT_MAX => PHP_INT_MAX,
        ];
        foreach ($aceitos as $valor => $esperado) {
            putenv(self::PREFIXO . '_TESTE_INTEIRO=' . $valor);
            $this->assertSame($esperado, inteiroPositivoDaEnv(self::PREFIXO . '_TESTE_INTEIRO'), "valor {$valor}");
        }

        $recusados = ['', '   ', '0', '000', '-1', '+1', '1.5', '1e3', 'abc', '7 dias', (string) PHP_INT_MAX . '0'];
        foreach ($recusados as $valor) {
            putenv(self::PREFIXO . '_TESTE_INTEIRO=' . $valor);
            $this->assertNull(inteiroPositivoDaEnv(self::PREFIXO . '_TESTE_INTEIRO'), "valor `{$valor}` precisa ser recusado");
        }

        // PHP_INT_MAX + 1 tem o mesmo comprimento do limite: a comparação
        // textual é o que impede o cast de saturar em PHP_INT_MAX.
        $acimaDoLimite = substr((string) PHP_INT_MAX, 0, -1) . '8';
        putenv(self::PREFIXO . '_TESTE_INTEIRO=' . $acimaDoLimite);
        $this->assertNull(inteiroPositivoDaEnv(self::PREFIXO . '_TESTE_INTEIRO'));
    }

    public function testInteiroMaiorQueOPHPIntMaxNaoSaturaNoCast(): void
    {
        $acimaDoLimite = (string) PHP_INT_MAX . '0';

        putenv(self::PREFIXO . '_SNAPSHOTS_MANTER=' . $acimaDoLimite);
        $this->assertSame(7, quantosSnapshotsManter());

    }
}
