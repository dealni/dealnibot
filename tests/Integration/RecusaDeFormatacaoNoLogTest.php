<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppLogReader;

require_once __DIR__ . '/../../inclusos/Telegram.php';
require_once __DIR__ . '/../../comandos/funcoes_ia_comuns.php';

/**
 * O 400 "can't parse entities" da resposta de IA só é erro no app.log quando o
 * reenvio sem parse_mode também falha.
 *
 * Antes, o transporte gravava a recusa do Markdown como erro no mesmo instante,
 * e o reenvio em texto puro, que entregava a resposta, não desfazia a linha: a
 * contagem de erros do canal `telegram` crescia a cada resposta com Markdown
 * inválido do modelo. Com a correção, a recusa fica retida e vira aviso quando o
 * reenvio entrega.
 *
 * Os três casos ficam no MESMO processo porque LOGS_ATIVO é constante e cada
 * #[RunInSeparateProcess] custa um PHP inteiro.
 */
final class RecusaDeFormatacaoNoLogTest extends TestCase
{
    private const RECUSA = [
        'ok' => false,
        'error_code' => 400,
        'description' => "Bad Request: can't parse entities: Can't find end of the entity starting at byte offset 12",
    ];

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRecusaDeFormatacaoViraAvisoSoQuandoOReenvioEntrega(): void
    {
        define('LOGS_ATIVO', true);

        // 1) Recusa do Markdown e reenvio entregue: uma linha, de aviso.
        $marca = AppLogReader::tamanhoAtual();
        $this->assertTrue(\enviarRespostaIa(new TelegramRespostasRoteirizadas([self::RECUSA, ['ok' => true]]), new Support\RedisFalsoComLua(), 'a *b*'));
        $linhas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($marca));
        $this->assertCount(1, $linhas);
        $this->assertSame('warn', $linhas[0]['level'] ?? null);
        $this->assertSame('telegram', $linhas[0]['channel'] ?? null);
        $this->assertSame('Telegram API Warning: ' . self::RECUSA['description'], $linhas[0]['msg'] ?? null);

        // 2) Recusa do Markdown e reenvio recusado: as duas falhas são erro.
        $marca = AppLogReader::tamanhoAtual();
        $instavel = ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'];
        $this->assertFalse(\enviarRespostaIa(new TelegramRespostasRoteirizadas([self::RECUSA, $instavel]), new Support\RedisFalsoComLua(), 'a *b*'));
        $linhas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($marca));
        $this->assertSame(['error', 'error'], array_column($linhas, 'level'));
        $mensagens = array_column($linhas, 'msg');
        sort($mensagens);
        $this->assertSame(
            ['Telegram API Error: ' . $instavel['description'], 'Telegram API Error: ' . self::RECUSA['description']],
            $mensagens
        );

        // 3) Outra falha na primeira tentativa não é retida nem rebaixada.
        $marca = AppLogReader::tamanhoAtual();
        $this->assertFalse(\enviarRespostaIa(new TelegramRespostasRoteirizadas([$instavel]), new Support\RedisFalsoComLua(), 'a *b*'));
        $linhas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($marca));
        $this->assertCount(1, $linhas);
        $this->assertSame('error', $linhas[0]['level'] ?? null);

        // 4) Recusa definitiva de escrita é decisão do grupo, não defeito:
        //    aviso marcado, e a memória no Redis cala o chat por seis horas.
        foreach (
            [
                ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked from the supergroup chat'],
                ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: not enough rights to send text messages to the chat'],
            ] as $recusa
        ) {
            $marca = AppLogReader::tamanhoAtual();
            $redis = new Support\RedisFalsoComLua();
            $this->assertFalse(\enviarRespostaIa(new TelegramRespostasRoteirizadas([$recusa]), $redis, 'a *b*'));
            $linhas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($marca));
            $this->assertCount(1, $linhas);
            $this->assertSame('warn', $linhas[0]['level'] ?? null);
            $this->assertSame('Telegram API Warning: ' . $recusa['description'], $linhas[0]['msg'] ?? null);
            $contexto = $linhas[0]['context'] ?? null;
            $this->assertIsArray($contexto);
            $this->assertTrue($contexto['recusa_de_escrita'] ?? null);
            $this->assertSame(RECUSA_DE_ESCRITA_TTL_SEGUNDOS, $redis->expiracaoDe(\chaveRecusaDeEscrita(123)));
        }
    }
}

/**
 * Dublê que entrega respostas roteirizadas e, como o transporte real
 * (`Telegram::sendAPIRequest` com log_errors ligado), passa cada uma ao
 * TelegramErrorLogger antes de devolvê-la.
 */
final class TelegramRespostasRoteirizadas extends \Telegram
{
    /** @param list<array{ok: bool, error_code?: int, description?: string}> $respostas */
    public function __construct(private array $respostas)
    {
        parent::__construct('000000:TEST_TOKEN');
    }

    public function ChatID(): int
    {
        return 123;
    }

    public function MessageID(): int
    {
        return 7;
    }

    public function sendMessage(array $content): array
    {
        $resposta = array_shift($this->respostas);
        TestCase::assertNotNull($resposta, 'sendMessage chamado mais vezes que o roteiro.');
        \TelegramErrorLogger::log($resposta, ['metodo' => 'sendMessage', 'campos' => array_keys($content)]);

        return $resposta;
    }
}
