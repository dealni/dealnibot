<?php

declare(strict_types=1);

namespace Tests;

use App\Support\DiagnosticoAvisoTelegram;
use PHPUnit\Framework\TestCase;

final class DiagnosticoAvisoTelegramTest extends TestCase
{
    public function testRecusaPreservaSomenteMetadadosPermitidos(): void
    {
        $corpo = json_encode(['ok' => false, 'error_code' => 429, 'description' => 'token=SEGREDO',
            'parameters' => ['retry_after' => 59], 'result' => ['chat' => ['id' => 123456789]]], JSON_THROW_ON_ERROR);
        self::assertSame(
            ['evento' => 'aviso_telegram_nao_entregue', 'http_status' => 429,
            'motivo' => 'recusa_telegram', 'telegram_error_code' => 429, 'retry_after' => 59],
            DiagnosticoAvisoTelegram::resumir($corpo, ['HTTP/1.1 200 Connection established', 'HTTP/2 429 Too Many Requests'], '')
        );
    }

    public function testFalhaDeTransporteNaoPropagaUrlNemMensagemDoPhp(): void
    {
        foreach (['SSL connection timeout' => 'timeout', 'Failed to enable crypto' => 'tls',
            'getaddrinfo failed' => 'dns', 'Connection refused' => 'transporte'] as $erro => $motivo) {
            self::assertSame(
                ['evento' => 'aviso_telegram_nao_entregue', 'http_status' => 0, 'motivo' => $motivo],
                DiagnosticoAvisoTelegram::resumir(false, [], 'file_get_contents(https://api.telegram.org/bot1:SEGREDO/sendMessage): ' . $erro)
            );
        }
    }

    public function testRespostaInvalidaNaoConfirmaEntregaNemRevelaCorpo(): void
    {
        self::assertSame(
            ['evento' => 'aviso_telegram_nao_entregue', 'http_status' => 502, 'motivo' => 'resposta_invalida'],
            DiagnosticoAvisoTelegram::resumir('senha=SEGREDO', ['HTTP/1.1 502 Bad Gateway'], '')
        );
        self::assertNull(DiagnosticoAvisoTelegram::resumir('{"ok":true}', ['HTTP/2 200'], ''));
        self::assertNotNull(DiagnosticoAvisoTelegram::resumir('{"ok":true}', ['HTTP/2 503'], ''));
        self::assertSame(
            ['evento' => 'aviso_telegram_nao_entregue', 'http_status' => 400, 'motivo' => 'recusa_telegram'],
            DiagnosticoAvisoTelegram::resumir('{"error_code":"token=SEGREDO","parameters":{"retry_after":-10}}', ['HTTP/2 400'], '')
        );
    }
}
