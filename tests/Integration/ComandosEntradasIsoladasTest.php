<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCurlIsolado;

final class ComandosEntradasIsoladasTest extends TestCase
{
    /** @return array<string, array{string, string, int}> */
    public static function entradas(): array
    {
        $casos = [];
        foreach (['chatgpt', 'ver_audio', 'ver_imagem'] as $comando) {
            $casos[$comando . ' válido'] = [$comando, 'valido', $comando === 'chatgpt' ? 0 : 1];
            $casos[$comando . ' ID textual recusado'] = [$comando, 'id_textual', 0];
            $casos[$comando . ' ID malformado recusado'] = [$comando, 'id_malformado', 0];
            $casos[$comando . ' ausente'] = [$comando, 'ausente', 0];
            $casos[$comando . ' Telegram inválido'] = [$comando, 'telegram_invalido', 0];
            $casos[$comando . ' Redis ausente'] = [$comando, 'redis_ausente', 0];
            $casos[$comando . ' Redis inválido'] = [$comando, 'redis_invalido', 0];
        }
        $casos['texto sem tipo de chat e Redis indisponível'] = ['chatgpt', 'sem_tipo_chat', 0];
        return $casos;
    }
    #[DataProvider('entradas')]
    public function testEntradaValidaContextoAntesDeInterpretarMensagem(string $comando, string $estado, int $pedidos): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'entrada_comando_');
        file_put_contents($script, <<<'RUNNER'
<?php
$raiz = getenv('CHATBOTS_ENTRY_ROOT');
require $raiz . '/vendor/autoload.php';
require $raiz . '/inclusos/dados_bot.php';
require $raiz . '/inclusos/Telegram.php';
$telegram = new Telegram(TOKEN_BOT, false);
$telegram->setData(['message' => ['message_id' => 10, 'from' => ['id' => 4321, 'first_name' => 'Pessoa Teste'], 'chat' => ['id' => 4321, 'type' => 'private'], 'text' => '']]);
$redis = new Redis();
$estado = getenv('CHATBOTS_COMMAND_STATE');
if ($estado === 'sem_tipo_chat') { $telegram->setData(['message' => ['message_id' => 10, 'from' => ['id' => 4321], 'chat' => ['id' => 4321], 'text' => 'pergunta']]); }
if ($estado === 'id_textual' || $estado === 'id_malformado') {
    $id = $estado === 'id_textual' ? '4321' : '4321usuario';
    $telegram->setData(['message' => ['message_id' => 10, 'from' => ['id' => $id], 'chat' => ['id' => 4321, 'type' => 'private'], 'text' => 'pergunta']]);
}
if ($estado === 'ausente') { unset($telegram); }
if ($estado === 'redis_ausente') { unset($redis); }
if ($estado === 'telegram_invalido') { $telegram = new stdClass(); }
if ($estado === 'redis_invalido') { $redis = new stdClass(); }
try {
    require $raiz . '/comandos/' . getenv('CHATBOTS_COMMAND_NAME') . '.php';
    echo 'retornou';
} catch (RedisException $e) {
    echo 'quota_indisponivel';
} catch (LogicException $e) {
    echo $e->getMessage();
}
RUNNER);
        try {
            $r = ExecutorCurlIsolado::executar($script, ['ok' => true, 'result' => ['message_id' => 1]], 200, [
                'CHATBOTS_ENTRY_ROOT' => dirname(__DIR__, 2), 'CHATBOTS_COMMAND_NAME' => $comando, 'CHATBOTS_COMMAND_STATE' => $estado,
            ]);
            self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertSame('', $r['stderr']);
            self::assertCount($pedidos, $r['requisicoes']);
            if (in_array($estado, ['valido', 'sem_tipo_chat', 'id_textual', 'id_malformado'], true)) {
                self::assertSame($estado === 'sem_tipo_chat' ? 'quota_indisponivel' : 'retornou', $r['stdout']);
                if ($comando === 'ver_audio' && $estado === 'valido') {
                    self::assertStringContainsString('envie um áudio', (string) json_encode($r['requisicoes'], JSON_UNESCAPED_UNICODE));
                }
                if ($comando === 'ver_imagem' && $estado === 'valido') {
                    self::assertStringContainsString('envie uma imagem', (string) json_encode($r['requisicoes'], JSON_UNESCAPED_UNICODE));
                }
            } else {
                self::assertStringContainsString('Contexto inválido', $r['stdout']);
            }
        } finally {
            unlink($script);
        }
    }
}
