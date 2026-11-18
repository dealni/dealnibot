<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/funcoes_webhook_inbox.php';
require_once __DIR__ . '/../../processos/funcoes_processar_webhook_inbox.php';

final class WebhookInboxFalhaReservaTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function operacoes(): array
    {
        return ['recuperação de leases' => [1], 'reserva do próximo' => [2]];
    }

    private function bancoFalhoNaConsulta(int $posicao): \bdTelegram
    {
        $bd = $this->createMock(\bdTelegram::class);
        $consulta = $this->createStub(\PDOStatement::class);
        $chamadas = 0;
        $bd->expects(self::exactly($posicao))->method('queryExec')->willReturnCallback(
            static function () use (&$chamadas, $posicao, $consulta): \PDOStatement|false {
                return ++$chamadas === $posicao ? false : $consulta;
            }
        );
        return $bd;
    }

    #[DataProvider('operacoes')]
    public function testErroSqlNaoEhFilaVazia(int $posicao): void
    {
        $bd = $this->bancoFalhoNaConsulta($posicao);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs($posicao === 1
            ? 'Falha ao recuperar leases expiradas da inbox.'
            : 'Falha ao reservar o próximo update da inbox.');
        pegarProximoUpdateWebhook($bd, 100);
    }

    #[DataProvider('operacoes')]
    public function testDrenoContaUmaFalhaEParaSemRotear(int $posicao): void
    {
        $telegram = $this->createMock(\Telegram::class);
        $telegram->expects(self::never())->method('setData');
        self::assertSame(['processados' => 0, 'falhas' => 1], drenarInboxWebhook(
            $this->bancoFalhoNaConsulta($posicao),
            $telegram,
            $this->createStub(\Redis::class),
            agora: static fn (): int => 100
        ));
    }
}
