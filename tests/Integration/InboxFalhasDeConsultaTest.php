<?php

declare(strict_types=1);

namespace Tests;

use bdTelegram;
use PDOStatement;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/bdTelegram.php';
require_once __DIR__ . '/../../inclusos/funcoes_webhook_inbox.php';

final class InboxFalhasDeConsultaTest extends TestCase
{
    public function testFalhaNaReservaPorIdNaoDevolveUpdate(): void
    {
        $bd = $this->createMock(bdTelegram::class);
        $bd->expects(self::once())->method('queryExec')->willReturn(false);
        self::assertNull(pegarUpdateWebhookPorId($bd, 12, 100));
    }

    public function testFalhaNaRecuperacaoDaFilaInterrompeReserva(): void
    {
        $bd = $this->createMock(bdTelegram::class);
        $bd->expects(self::once())->method('queryExec')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('Falha ao recuperar leases expiradas da inbox.');
        pegarProximoUpdateWebhook($bd, 100);
    }

    public function testLimpezaIndisponivelNaoInventaContagem(): void
    {
        $bd = $this->createMock(bdTelegram::class);
        $bd->expects(self::once())->method('queryExec')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        limparInboxWebhookRetida($bd, 100);
    }

    public function testRegistroAusenteUsaAtrasoMinimoSemConfirmarUpdate(): void
    {
        $consulta = $this->createMock(PDOStatement::class);
        $consulta->expects(self::once())->method('fetchColumn')->willReturn(false);
        $bd = $this->createMock(bdTelegram::class);
        $bd->expects(self::exactly(2))->method('queryExec')->willReturnCallback(
            static function (string $sql, array $params) use ($consulta): PDOStatement|false {
                if (str_starts_with($sql, 'SELECT')) {
                    return $consulta;
                }
                self::assertSame(102, $params[':disponivel']);
                self::assertSame(12, $params[':id']);
                self::assertSame(7, $params[':tentativa']);
                return false;
            }
        );
        self::assertFalse(marcarUpdateWebhookFalho($bd, 12, 'falha', 100, 7));
    }

    public function testErroFatalSemPersistenciaNaoTentaConfirmarProcessamento(): void
    {
        $bd = $this->createMock(bdTelegram::class);
        $bd->expects(self::once())->method('queryExec')->with(self::stringStartsWith('SELECT'))
            ->willReturn(false);
        finalizarUpdateWebhookNoDesligamento($bd, 12, 100, ['type' => E_ERROR], 1);
    }
}
