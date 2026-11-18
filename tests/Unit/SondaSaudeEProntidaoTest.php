<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ContextoDeExecucao;
use App\Support\Sonda;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Saúde separada de prontidão: o que cada sonda tem PERMISSÃO de fazer.
 *
 * A decisão de status é testada aqui, sem banco e sem Redis, porque as
 * dependências chegam como closures. O que roda em produção é `ready.php`, que
 * monta as closures reais de `inclusos/funcoes_sondas.php`.
 */
final class SondaSaudeEProntidaoTest extends TestCase
{
    public function testSaudeRespondeSempreDuzentosENaoTocaEmDependencia(): void
    {
        // Uma liveness que consulta dependência derruba o processo junto com o
        // Redis: o supervisor mata um serviço que voltaria sozinho. Por isso
        // `vivo()` nem sequer ACEITA dependência — não há como regredir.
        $resposta = Sonda::vivo();

        $this->assertSame(200, $resposta['status']);
        $this->assertSame('ok', $resposta['corpo']['status']);
        $this->assertSame(ContextoDeExecucao::servico(), $resposta['corpo']['service']);
        $this->assertSame(ContextoDeExecucao::versao(), $resposta['corpo']['version']);
        $this->assertArrayNotHasKey('checks', $resposta['corpo']);
    }

    public function testProntidaoRespondeDuzentosComTodasAsDependenciasOk(): void
    {
        $resposta = Sonda::pronto([
            'postgresql' => static fn (): bool => true,
            'redis' => static fn (): bool => true,
        ]);

        $this->assertSame(200, $resposta['status']);
        $this->assertSame('ok', $resposta['corpo']['status']);
        $this->assertSame(['postgresql' => 'ok', 'redis' => 'ok'], $resposta['corpo']['checks']);
    }

    public function testUmaDependenciaFalhaEProntidaoRespondeQuinhentosETres(): void
    {
        $resposta = Sonda::pronto([
            'postgresql' => static fn (): bool => true,
            'redis' => static fn (): bool => false,
        ]);

        $this->assertSame(503, $resposta['status']);
        $this->assertSame('erro', $resposta['corpo']['status']);
        $this->assertSame(['postgresql' => 'ok', 'redis' => 'erro'], $resposta['corpo']['checks']);
    }

    public function testDependenciaQueLancaViraErroEmVezDeDerrubarASonda(): void
    {
        // Uma sonda que devolve 500 por exceção não distingue "dependência
        // caiu" de "a sonda quebrou", e as duas exigem ações diferentes de
        // quem está de plantão.
        $resposta = Sonda::pronto([
            'postgresql' => static fn (): bool => throw new RuntimeException('conexão recusada em /var/www/bot.db'),
        ]);

        $this->assertSame(503, $resposta['status']);
        $this->assertSame(['postgresql' => 'erro'], $resposta['corpo']['checks']);
    }

    public function testCorpoDasDuasSondasNaoVazaDetalheInterno(): void
    {
        // Nenhuma das duas tem autenticação, então nenhuma pode devolver
        // caminho, host, string de conexão ou mensagem de exceção. `checks` é
        // um mapa de ok/erro e nada mais.
        $vivo = json_encode(Sonda::vivo()['corpo']);
        $pronto = json_encode(Sonda::pronto([
            'postgresql' => static fn (): bool => throw new RuntimeException('SQLSTATE[HY000] /var/www/dealnibot/bot.db'),
            'redis' => static fn (): bool => throw new RuntimeException('connect 10.0.0.7:6379 recusado'),
        ])['corpo']);

        $this->assertIsString($vivo);
        $this->assertIsString($pronto);
        foreach ([$vivo, $pronto] as $corpo) {
            $this->assertStringNotContainsString('bot.db', $corpo);
            $this->assertStringNotContainsString('/var/www', $corpo);
            $this->assertStringNotContainsString('6379', $corpo);
            $this->assertStringNotContainsString('SQLSTATE', $corpo);
        }

        $decodificado = json_decode($pronto, true);
        $this->assertIsArray($decodificado);
        $this->assertSame(['postgresql' => 'erro', 'redis' => 'erro'], $decodificado['checks']);
    }

    public function testTimeoutDeDependenciaRespeitaOTetoDoContrato(): void
    {
        // O contrato pede ≤ 2 s por dependência: quem consulta /ready está
        // decidindo se manda tráfego, e sonda que pendura é pior que sonda que
        // reprova.
        $this->assertLessThanOrEqual(2.0, Sonda::TIMEOUT_SEGUNDOS);
    }
}
