<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/Telegram.php';
require_once __DIR__ . '/../../comandos/funcoes_ia_comuns.php';

final class EntregaRespostaIaTest extends TestCase
{
    public function testRecusaDeEscritaNaoConfundeFalhaTransitoriaComPermissao(): void
    {
        foreach (
            [
                ['ok' => false, 'error_code' => 403],
                ['ok' => false, 'error_code' => 400, 'description' => 'CHAT_WRITE_FORBIDDEN'],
                ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: not enough rights to send text messages to the chat'],
                ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'],
                ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: CHAT_RESTRICTED'],
                ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: have no rights to send a message'],
            ] as $resposta
        ) {
            self::assertTrue(\TelegramErrorLogger::ehRecusaDefinitivaDeEscrita($resposta), (string) json_encode($resposta));
        }
        foreach (
            [
                ['ok' => true],
                ['ok' => true, 'error_code' => 403],
                ['error_code' => 403],
                ['ok' => false],
                ['ok' => false, 'error_code' => 429],
                ['ok' => false, 'error_code' => 500],
                ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"],
                ['ok' => false, 'error_code' => '403'],
                ['ok' => false, 'error_code' => 400, 'description' => ['CHAT_WRITE_FORBIDDEN']],
            ] as $resposta
        ) {
            self::assertFalse(\TelegramErrorLogger::ehRecusaDefinitivaDeEscrita($resposta), (string) json_encode($resposta));
        }
    }

    public function testRecusaDefinitivaFicaLembradaPorSeisHorasEFalhaTransitoriaNao(): void
    {
        $redis = new Support\RedisFalsoComLua();
        self::assertFalse(\chatSemPermissaoDeEscrita($redis, -100123));
        self::assertFalse(\lembrarRecusaDeEscrita($redis, -100123, ['ok' => false, 'error_code' => 429]));
        self::assertFalse(\chatSemPermissaoDeEscrita($redis, -100123));

        self::assertTrue(\lembrarRecusaDeEscrita($redis, -100123, ['ok' => false, 'error_code' => 400, 'description' => 'CHAT_WRITE_FORBIDDEN']));
        self::assertTrue(\chatSemPermissaoDeEscrita($redis, -100123));
        self::assertFalse(\chatSemPermissaoDeEscrita($redis, -100999), 'A memória é por chat.');
        self::assertFalse(\chatSemPermissaoDeEscrita($redis, null));
        self::assertSame('telegram:sem_escrita:-100123', \chaveRecusaDeEscrita(-100123));
        self::assertSame(21600, $redis->expiracaoDe('telegram:sem_escrita:-100123'));

        // Quando o TTL vence, o bot tenta de novo: o grupo pode ter devolvido a permissão.
        $redis->vencerChave('telegram:sem_escrita:-100123');
        self::assertFalse(\chatSemPermissaoDeEscrita($redis, -100123));

        // Sem chat não há o que lembrar, mas a recusa continua reconhecida.
        $antes = $redis->operacoes();
        self::assertTrue(\lembrarRecusaDeEscrita($redis, null, ['ok' => false, 'error_code' => 403]));
        self::assertSame($antes, $redis->operacoes(), 'Nada foi gravado sem chat.');
    }

    public function testRedisForaDoArNaoCalaOBotNemDerrubaOFluxo(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivelComExcecao();
        self::assertFalse(\chatSemPermissaoDeEscrita($redis, -100123));
        self::assertTrue(\lembrarRecusaDeEscrita($redis, -100123, ['ok' => false, 'error_code' => 403]));
    }

    /** @return array<string, array{array{ok: bool, error_code?: int, description?: string}, int, bool}> */
    public static function respostas(): array
    {
        return [
            'entrega formatada' => [['ok' => true], 1, true],
            'formatação recusada' => [['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"], 2, true],
            'sem permissão' => [['ok' => false, 'error_code' => 403, 'description' => 'Forbidden'], 1, false],
            'chat sem escrita' => [['ok' => false, 'error_code' => 400, 'description' => 'CHAT_WRITE_FORBIDDEN'], 1, false],
            'limite do Telegram' => [['ok' => false, 'error_code' => 429], 1, false],
            'resultado incerto' => [['ok' => false], 1, false],
        ];
    }

    /** @param array{ok: bool, error_code?: int, description?: string} $primeira */
    #[DataProvider('respostas')]
    public function testSoReenviaQuandoTelegramRecusaAFormatacao(array $primeira, int $vezes, bool $entregue): void
    {
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(123);
        $telegram->method('MessageID')->willReturn(7);
        $texto = 'Texto *formatado* e _teste1_';
        $contador = 0;
        $telegram->expects(self::exactly($vezes))->method('sendMessage')->willReturnCallback(
            static function (array $conteudo) use (&$contador, $primeira, $texto): array {
                self::assertSame($texto, $conteudo['text']);
                self::assertSame(123, $conteudo['chat_id']);
                if ($contador++ === 0) {
                    self::assertSame('Markdown', $conteudo['parse_mode']);
                    return $primeira;
                }
                self::assertArrayNotHasKey('parse_mode', $conteudo);
                return ['ok' => true];
            }
        );
        $redis = new Support\RedisFalsoComLua();
        self::assertSame($entregue, \enviarRespostaIa($telegram, $redis, $texto));
        $recusa = \TelegramErrorLogger::ehRecusaDefinitivaDeEscrita($primeira);
        self::assertSame($recusa, \chatSemPermissaoDeEscrita($redis, 123), 'Só a recusa definitiva fica lembrada.');
    }

    public function testRespostaCortadaComCodigoSegueDiretoComoTextoPuro(): void
    {
        $texto = \cortarMensagem(str_repeat('a', 4080) . ' `codigo ' . str_repeat('b', 40));
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(123);
        $telegram->method('MessageID')->willReturn(7);
        $telegram->expects(self::once())->method('sendMessage')->willReturnCallback(
            static function (array $conteudo) use ($texto): array {
                self::assertSame($texto, $conteudo['text']);
                self::assertArrayNotHasKey('parse_mode', $conteudo);

                return ['ok' => true];
            }
        );
        self::assertTrue(\enviarRespostaIa($telegram, new Support\RedisFalsoComLua(), $texto));
    }

    /** @return array<string, array{list<array<string, mixed>>, list<int>, bool}> */
    public static function recusasComRetentativa(): array
    {
        $limite = ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 10]];
        $formato = ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"];

        return [
            'limite e entrega' => [[$limite, ['ok' => true]], [10], true],
            'limite reiterado' => [[$limite, $limite], [10], false],
            'formatação antes do limite' => [[$formato, $limite, ['ok' => true]], [10], true],
            'formatação depois do limite' => [[$limite, $formato, ['ok' => true]], [10], true],
            'atraso observado em produção' => [[['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 32]], ['ok' => true]], [32], true],
            'atraso máximo' => [[['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 60]], ['ok' => true]], [60], true],
            'atraso ausente' => [[['ok' => false, 'error_code' => 429]], [], false],
            'atraso textual' => [[['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => '10']]], [], false],
            'atraso fracionário' => [[['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 1.5]]], [], false],
            'parâmetros malformados' => [[['ok' => false, 'error_code' => 429, 'parameters' => '10']], [], false],
            'atraso nulo' => [[['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 0]]], [], false],
            'atraso negativo' => [[['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => -1]]], [], false],
            'atraso excessivo' => [[['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 61]]], [], false],
            'erro do servidor' => [[['ok' => false, 'error_code' => 503, 'parameters' => ['retry_after' => 10]]], [], false],
            'timeout ambíguo' => [[['ok' => false, 'error_code' => 0, 'description' => 'Operation timed out']], [], false],
        ];
    }

    /**
     * @param list<array<string, mixed>> $respostas
     * @param list<int> $esperas
     */
    #[DataProvider('recusasComRetentativa')]
    public function testLimiteExplicitoRepeteUmaVezComEsperaLimitada(array $respostas, array $esperas, bool $entregue): void
    {
        $logAntes = Support\AppLogReader::tamanhoAtual();
        $telegram = $this->createMock(\Telegram::class);
        $telegram->method('ChatID')->willReturn(123);
        $telegram->method('MessageID')->willReturn(7);
        $indice = 0;
        $semFormato = false;
        $telegram->expects(self::exactly(count($respostas)))->method('sendMessage')->willReturnCallback(
            static function (array $conteudo) use ($respostas, &$indice, &$semFormato): array {
                self::assertSame('Texto *formatado*', $conteudo['text']);
                self::assertIsString($conteudo['reply_parameters']);
                self::assertSame(['message_id' => 7, 'allow_sending_without_reply' => true], json_decode($conteudo['reply_parameters'], true));
                self::assertSame(!$semFormato, isset($conteudo['parse_mode']));
                $resposta = $respostas[$indice++];
                $semFormato = $semFormato || ($resposta['error_code'] ?? null) === 400;

                return $resposta;
            }
        );
        $esperasRecebidas = [];
        $esperar = static function (int $segundos) use (&$esperasRecebidas): void {
            $esperasRecebidas[] = $segundos;
        };
        self::assertSame($entregue, \enviarRespostaIa($telegram, new Support\RedisFalsoComLua(), 'Texto *formatado*', $esperar));
        self::assertSame($esperas, $esperasRecebidas);
        $registros = Support\AppLogReader::linhasJson(Support\AppLogReader::conteudoDesde($logAntes));
        self::assertCount($entregue ? 1 : 0, $registros);
        if ($entregue) {
            self::assertSame('Resposta de IA entregue após limite do Telegram', $registros[0]['msg']);
            self::assertSame('info', $registros[0]['level']);
        }
    }
}
