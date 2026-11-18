<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCurlIsolado;

final class TransporteOpenRouterPadraoTest extends TestCase
{
    /** @return array<string, array{int, int, bool}> */
    public static function respostas(): array
    {
        return ['sucesso' => [200, 0, false], 'HTTP 503' => [503, 0, false], 'timeout' => [0, 28, true]];
    }
    #[DataProvider('respostas')]
    public function testTransporteRealPreservaStatusEUsaTlsETempoLimitado(int $http, int $errno, bool $falhou): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'transporte_padrao_');
        file_put_contents($script, <<<'RUNNER'
<?php
require getenv('CHATBOTS_ENTRY_ROOT') . '/comandos/funcoes_http_openai.php';
echo json_encode(executarRequisicaoOpenAi('https://openrouter.ai/api/v1/chat/completions', '{}', ['Authorization: Bearer segredo-sintetico'], 'chat.completions', 0));
RUNNER);
        try {
            $r = ExecutorCurlIsolado::executar($script, ['choices' => []], $http, [
                'CHATBOTS_ENTRY_ROOT' => dirname(__DIR__, 2), 'CHATBOTS_CURL_TEST_ERRNO' => (string) $errno,
            ]);
            self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertStringNotContainsString('segredo-sintetico', $r['stderr']);
            $resposta = json_decode($r['stdout'], true);
            self::assertIsArray($resposta);
            self::assertSame($http, $resposta['http']);
            self::assertSame($falhou ? false : '{"choices":[]}', $resposta['corpo']);
            self::assertCount(1, $r['requisicoes']);
            $opcoes = $r['requisicoes'][0];
            self::assertIsArray($opcoes);
            self::assertTrue($opcoes[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, $opcoes[CURLOPT_SSL_VERIFYHOST]);
            self::assertSame(10, $opcoes[CURLOPT_CONNECTTIMEOUT]);
            self::assertSame(1, $opcoes[CURLOPT_TIMEOUT]);
        } finally {
            unlink($script);
        }
    }
    #[DataProvider('respostas')]
    public function testModalidadesUsamTransportePadraoComMesmaAutorizacao(int $http, int $errno, bool $falhou): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'chat_padrao_');
        file_put_contents($script, <<<'RUNNER'
<?php
$raiz = getenv('CHATBOTS_ENTRY_ROOT');
require $raiz . '/comandos/funcoes_chatgpt.php';
require $raiz . '/comandos/funcoes_ver_audio.php';
require $raiz . '/comandos/funcoes_ver_imagem.php';
$chamadas = ['chamarChatCompletionsOpenAi', 'chamarChatCompletionsAudio', 'chamarChatCompletionsImagem'];
echo json_encode(array_map(static fn ($funcao) => $funcao('https://openrouter.ai/api/v1/chat/completions', 'segredo-sintetico', '{}'), $chamadas));
RUNNER);
        try {
            $r = ExecutorCurlIsolado::executar($script, ['choices' => []], $http, [
                'CHATBOTS_ENTRY_ROOT' => dirname(__DIR__, 2), 'CHATBOTS_CURL_TEST_ERRNO' => (string) $errno,
            ]);
            self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertSame(array_fill(0, 3, $falhou ? false : '{"choices":[]}'), json_decode($r['stdout'], true));
            self::assertStringNotContainsString('segredo-sintetico', $r['stderr']);
            self::assertCount(3, $r['requisicoes']);
            foreach ($r['requisicoes'] as $opcoes) {
                self::assertIsArray($opcoes);
                self::assertSame(['Content-Type: application/json', 'Authorization: Bearer segredo-sintetico'], $opcoes[CURLOPT_HTTPHEADER]);
                self::assertSame('{}', $opcoes[CURLOPT_POSTFIELDS]);
                self::assertTrue($opcoes[CURLOPT_SSL_VERIFYPEER]);
                self::assertSame(2, $opcoes[CURLOPT_SSL_VERIFYHOST]);
                self::assertSame(90, $opcoes[CURLOPT_TIMEOUT]);
            }
        } finally {
            unlink($script);
        }
    }

}
