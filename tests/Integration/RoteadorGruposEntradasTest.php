<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCurlIsolado;

final class RoteadorGruposEntradasTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, int, int}> */
    public static function mensagens(): array
    {
        return [
            'resposta a terceiro' => [['reply_to_message' => ['from' => ['id' => 999, 'username' => 'MeuProjetoBot']]], 0, 0],
            'resposta de texto vazio ao bot' => [['reply_to_message' => ['from' => ['id' => 123456]]], 0, 0],
            'foto malformada em resposta pede imagem' => [['photo' => true, 'reply_to_message' => ['from' => ['id' => 123456]]], 0, 1],
            'voz malformada em resposta pede áudio' => [['voice' => true, 'reply_to_message' => ['from' => ['id' => 123456]]], 0, 1],
            'menção em legenda com foto inválida' => [['photo' => true, 'caption' => '@meuprojetobot veja'], 0, 1],
            'sorteio atende texto vazio sem chamada paga' => [[], 4, 0],
            'sorteio não seleciona mensagem' => [[], 0, 0],
            'vídeo não é encaminhado ao modelo' => [['video' => ['file_id' => 'video-da-fixture']], 0, 0],
        ];
    }

    /** @param array<string, mixed> $campos */
    #[DataProvider('mensagens')]
    public function testRoteamentoDeGrupoPreservaMidiaERegrasDeResposta(array $campos, int $semente, int $pedidos): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'grupo_entrada_');
        file_put_contents($script, <<<'RUNNER'
<?php
$raiz = getenv('CHATBOTS_ENTRY_ROOT');
require $raiz . '/vendor/autoload.php';
require $raiz . '/inclusos/dados_bot.php';
require $raiz . '/inclusos/Telegram.php';
require $raiz . '/inclusos/bdTelegram.php';
$telegram = new Telegram(TOKEN_BOT, false);
$mensagem = array_replace(['message_id' => 10, 'from' => ['id' => 4321], 'chat' => ['id' => -123, 'type' => 'supergroup'], 'text' => ''], json_decode(getenv('CHATBOTS_GROUP_FIELDS'), true));
if (isset($mensagem['video'])) { unset($mensagem['text']); }
$telegram->setData(['message' => $mensagem]);
$bd = (new ReflectionClass(bdTelegram::class))->newInstanceWithoutConstructor();
$redis = new Redis();
srand((int) getenv('CHATBOTS_GROUP_SEED'));
require $raiz . '/roteador.php';
echo 'retornou';
RUNNER);
        try {
            $r = ExecutorCurlIsolado::executar($script, ['ok' => true, 'result' => ['message_id' => 20]], 200, [
                'CHATBOTS_ENTRY_ROOT' => dirname(__DIR__, 2),
                'CHATBOTS_GROUP_FIELDS' => json_encode($campos, JSON_THROW_ON_ERROR),
                'CHATBOTS_GROUP_SEED' => (string) $semente,
                'TELEGRAM_BOT_TOKEN' => '123456:FAKE_TOKEN',
                'DEALNI_BOT_USERNAME' => 'MeuProjetoBot',
            ]);
            self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertSame('retornou', $r['stdout']);
            self::assertCount($pedidos, $r['requisicoes']);
            foreach ($r['requisicoes'] as $requisicao) {
                self::assertIsArray($requisicao);
                self::assertIsString($requisicao[CURLOPT_URL]);
                self::assertStringEndsWith('/sendMessage', (string) parse_url($requisicao[CURLOPT_URL], PHP_URL_PATH));
            }
        } finally {
            unlink($script);
        }
    }
}
