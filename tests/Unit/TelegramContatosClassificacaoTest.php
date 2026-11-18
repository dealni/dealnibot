<?php

declare(strict_types=1);

namespace Tests;

use App\Support\TelegramContatos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramContatosClassificacaoTest extends TestCase
{
    /** @return iterable<string, array{int, string, ?string}> */
    public static function respostas(): iterable
    {
        yield 'bloqueio' => [403, 'Forbidden: bot was blocked by the user', 'bloqueado'];
        yield 'desativado' => [403, 'Forbidden: user is deactivated', 'desativado'];
        yield 'ausente' => [400, 'Bad Request: chat not found', 'nao_encontrado'];
        yield 'bot' => [400, 'Bad Request: USER_BOT_TO_BOT_DISABLED', 'bot'];
        yield 'bot proibido' => [403, "Forbidden: the bot can't send messages to the bot", 'bot'];
        yield 'limite' => [429, 'Too Many Requests: retry after 3', null];
        yield 'html inválido' => [400, "Bad Request: can't parse entities", null];
        yield 'chat migrado' => [400, 'Bad Request: group chat was upgraded to a supergroup chat', null];
        yield 'permissão genérica' => [403, 'Forbidden: not enough rights', null];
        yield 'falha externa' => [500, 'Internal Server Error', null];
        yield 'código errado' => [429, 'Forbidden: user is deactivated', null];
    }

    #[DataProvider('respostas')]
    public function testSomenteMotivosDefinitivosMudamContato(int $codigo, string $motivo, ?string $esperado): void
    {
        self::assertSame($esperado, TelegramContatos::estadoDaRecusa(['ok' => false, 'error_code' => $codigo, 'description' => $motivo]));
    }

    public function testSucessoERespostaIncompletaNaoDesativamContato(): void
    {
        self::assertNull(TelegramContatos::estadoDaRecusa(['ok' => true]));
        self::assertNull(TelegramContatos::estadoDaRecusa(['ok' => false]));
    }

    public function testRecusaNormalizaCaixaEEspacosSemAceitarTiposAmbiguos(): void
    {
        $recusa = ['ok' => false, 'error_code' => 403, 'description' => "  FORBIDDEN: USER IS DEACTIVATED\n"];
        self::assertSame('desativado', TelegramContatos::estadoDaRecusa($recusa));
        foreach (['ok' => 0, 'error_code' => '403', 'description' => null] as $campo => $valor) {
            self::assertNull(TelegramContatos::estadoDaRecusa(array_replace($recusa, [$campo => $valor])));
        }
    }

    public function testIdPrivadoRecusaGruposUsernamesOverflowEFormatosAmbiguos(): void
    {
        foreach ([-123, 0, '@canal', '1e3', '1.5', '9223372036854775808', '0', '0123', '+123', "123\n", '123x', 'x123', 1.5, true, null, []] as $valor) {
            self::assertNull(TelegramContatos::idPrivado($valor));
        }
        self::assertSame(123, TelegramContatos::idPrivado('123'));
        self::assertSame(1, TelegramContatos::idPrivado(1));
        self::assertSame(PHP_INT_MAX, TelegramContatos::idPrivado((string) PHP_INT_MAX));
    }
}
