<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCurlIsolado;

final class ConfiguracaoCurlFalhasTest extends TestCase
{
    /** @return array<string, array{string, array<string, string>, string, int}> */
    public static function falhas(): array
    {
        $casos = [];
        foreach (['config_remover.php', 'config_webhook.php'] as $arquivo) {
            $casos[$arquivo . ' inicialização'] = [$arquivo, ['CHATBOTS_CURL_TEST_INIT_FAIL' => '1'], 'iniciar o cURL', 0];
            $casos[$arquivo . ' opções sem detalhe'] = [$arquivo, ['CHATBOTS_CURL_TEST_OPTION_FAIL' => '1'], 'configurar o cURL.', 0];
            $casos[$arquivo . ' opções com detalhe'] = [$arquivo, ['CHATBOTS_CURL_TEST_OPTION_FAIL' => '1', 'CHATBOTS_CURL_TEST_ERRNO' => '2'], 'Falha sintética de transporte', 0];
            $opcoes = [CURLOPT_POST, CURLOPT_FOLLOWLOCATION, CURLOPT_RETURNTRANSFER, CURLOPT_CONNECTTIMEOUT,
                CURLOPT_TIMEOUT, CURLOPT_SSL_VERIFYPEER, CURLOPT_SSL_VERIFYHOST, CURLOPT_NOSIGNAL];
            if ($arquivo === 'config_webhook.php') {
                $opcoes[] = CURLOPT_POSTFIELDS;
            }
            foreach ($opcoes as $opcao) {
                $casos[$arquivo . ' opção recusada ' . $opcao] = [
                    $arquivo, ['CHATBOTS_CURL_TEST_OPTION_FAIL_ID' => (string) $opcao], 'configurar o cURL.', 0,
                ];
            }
            $casos[$arquivo . ' transporte'] = [$arquivo, ['CHATBOTS_CURL_TEST_ERRNO' => '28'], 'Falha sintética de transporte', 1];
        }
        return $casos;
    }

    /** @param array<string, string> $ambiente */
    #[DataProvider('falhas')]
    public function testConfiguracaoRecusaFalhasDoTransporteSimulado(string $arquivo, array $ambiente, string $mensagem, int $pedidos): void
    {
        // O helper substitui cURL antes da entrada; o sandbox nega toda rede externa.
        $r = ExecutorCurlIsolado::executar(
            dirname(__DIR__, 2) . '/config/' . $arquivo,
            ['ok' => true],
            200,
            $ambiente + ['DEALNI_PUBLIC_BASE_URL' => 'https://bot.example.com']
        );
        self::assertSame(1, $r['codigo'], $r['stdout'] . $r['stderr']);
        self::assertCount($pedidos, $r['requisicoes']);
        self::assertStringContainsString($mensagem, $r['stderr']);
        self::assertStringNotContainsString('Fatal error', $r['stderr']);
    }
}
