<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

final class EsperaRespostaIaPadraoTest extends TestCase
{
    public function testEsperaPadraoRecebePrazoDaApiAntesDaUnicaRetentativa(): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'dealnibot_espera_');
        // Desabilita a função nativa no filho: o substituto registra o prazo
        // sem aguardar tempo real e deixa a closure de produção ser executada.
        file_put_contents($script, <<<'RUNNER'
<?php
function sleep (int $segundos): int { echo 'aguardou:' . $segundos . ';'; return 0; }
$raiz = getenv('CHATBOTS_ENTRY_ROOT');
require $raiz . '/vendor/autoload.php';
require $raiz . '/inclusos/Telegram.php';
require $raiz . '/inclusos/funcoes_recusa_de_escrita.php';
require $raiz . '/comandos/funcoes_ia_comuns.php';
class TelegramEsperaPadrao extends Telegram
{
    public int $tentativas = 0;
    public function sendMessage(array $content): array
    {
        return ++$this->tentativas === 1
            ? ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 1]]
            : ['ok' => true];
    }
}
$telegram = new TelegramEsperaPadrao('teste', false);
$telegram->setData(['message' => ['text' => 'pergunta', 'chat' => ['id' => 4321], 'message_id' => 10]]);
$entregue = enviarRespostaIa($telegram, new Tests\Support\RedisFalsoComLua(), 'resposta');
echo json_encode(['entregue' => $entregue, 'tentativas' => $telegram->tentativas]);
RUNNER);
        try {
            $resultado = ExecutorCli::executar($script, [], ['CHATBOTS_ENTRY_ROOT' => dirname(__DIR__, 2)], ['disable_functions' => 'sleep']);
            self::assertSame(0, $resultado['codigo'], $resultado['stderr']);
            self::assertSame('', $resultado['stderr']);
            self::assertSame('aguardou:1;{"entregue":true,"tentativas":2}', $resultado['stdout']);
        } finally {
            unlink($script);
        }
    }
}
