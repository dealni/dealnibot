<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/funcoes_curl_telegram.php';

/**
 * Montagem das opções de cURL da Bot API (inclusos/funcoes_curl_telegram.php),
 * testada como dado puro: o que a classe Telegram aplica ao handle é
 * exatamente o array que estas funções devolvem.
 *
 * TelegramEndpointsTest continua provando o caminho pela classe (proxy
 * recebido no construtor, timeouts vindos das constantes); aqui ficam as
 * regras de conversão e de domínio, uma a uma.
 */
final class FuncoesCurlTelegramTest extends TestCase
{
    private const URL = 'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage';

    /**
     * @return array<string, array{mixed, int|null}>
     */
    public static function valoresDeConfiguracaoProvider(): array
    {
        return [
            'inteiro' => [8080, 8080],
            'zero' => [0, 0],
            'negativo é descartado' => [-1, null],
            'texto só de dígitos é convertido' => ['8080', 8080],
            'texto com sinal é descartado' => ['+80', null],
            'notação científica é descartada' => ['1e3', null],
            'texto com espaço é descartado' => ['80 ', null],
            'texto não numérico é descartado' => ['oitomil', null],
            'texto vazio é descartado' => ['', null],
            'float é descartado' => [8080.5, null],
            'booleano é descartado' => [true, null],
            'nulo é descartado' => [null, null],
            'array é descartado' => [[8080], null],
        ];
    }

    /**
     * A configuração chega de fora com o tipo que o parser deu; só o que é
     * inequivocamente um inteiro não negativo vira opção do cURL.
     */
    #[DataProvider('valoresDeConfiguracaoProvider')]
    public function testInteiroNaoNegativoDeConfiguracao(mixed $valor, ?int $esperado): void
    {
        $this->assertSame($esperado, inteiroNaoNegativoDeConfiguracao($valor));
    }

    public function testListaDeTiposDeProxyEhADoCurl(): void
    {
        $tipos = tiposDeProxyAceitosPeloCurl();

        foreach ([CURLPROXY_HTTP, CURLPROXY_SOCKS5, CURLPROXY_SOCKS5_HOSTNAME] as $conhecido) {
            $this->assertContains($conhecido, $tipos);
        }
        // Um inteiro que o cURL não conhece faz curl_setopt_array() parar no
        // meio do array — as travas TLS ficariam sem aplicar.
        $this->assertNotContains(999, $tipos);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<int, mixed>}>
     */
    public static function proxiesProvider(): array
    {
        return [
            'vazio não aplica nada' => [[], []],
            'tipo conhecido em texto é convertido' => [
                ['type' => (string) CURLPROXY_SOCKS5],
                [CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5],
            ],
            'tipo desconhecido some em vez de virar zero' => [
                ['type' => 999, 'url' => 'proxy.local'],
                [CURLOPT_PROXY => 'proxy.local'],
            ],
            'tipo negativo some' => [['type' => -1], []],
            'porta no limite superior é aceita' => [['port' => 65535], [CURLOPT_PROXYPORT => 65535]],
            'porta acima do limite some' => [['port' => 65536], []],
            'porta zero é aceita como inteiro' => [['port' => 0], [CURLOPT_PROXYPORT => 0]],
            'auth vazia não vira dois-pontos' => [['auth' => '', 'url' => 'proxy.local'], [CURLOPT_PROXY => 'proxy.local']],
            'auth escalar vira texto' => [['auth' => 12345], [CURLOPT_PROXYUSERPWD => '12345']],
            'auth não escalar some' => [['auth' => ['u', 'p']], []],
            'url não escalar some' => [['url' => ['proxy.local']], []],
            'chave desconhecida é ignorada' => [['host' => 'proxy.local'], []],
        ];
    }

    /**
     * @param array<string, mixed> $proxy
     * @param array<int, mixed> $esperado
     */
    #[DataProvider('proxiesProvider')]
    public function testOpcoesDeProxySoEntramComTipoEDominioCertos(array $proxy, array $esperado): void
    {
        $this->assertSame($esperado, opcoesCurlDeProxy($proxy));
    }

    public function testPostLevaCorpoEGetNaoLeva(): void
    {
        $comPost = opcoesCurlDaBotApi(self::URL, ['text' => 'oi'], true, [], 10, 15);
        $this->assertTrue($comPost[CURLOPT_POST] ?? null);
        $this->assertSame(['text' => 'oi'], $comPost[CURLOPT_POSTFIELDS] ?? null);

        $semPost = opcoesCurlDaBotApi(self::URL, ['text' => 'oi'], false, [], 10, 15);
        $this->assertArrayNotHasKey(CURLOPT_POST, $semPost);
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $semPost);
    }

    /**
     * Por estas conexões passa o TOKEN_BOT: as travas de TLS valem com e sem
     * proxy, e VERIFYHOST é 2 (1 é "existe um nome", que não verifica nada).
     */
    public function testTravasDeTlsETimeoutsValemSempre(): void
    {
        foreach ([[], ['url' => 'proxy.local', 'port' => 3128]] as $proxy) {
            $opcoes = opcoesCurlDaBotApi(self::URL, [], true, $proxy, 7, 42);

            $this->assertSame(self::URL, $opcoes[CURLOPT_URL] ?? null);
            $this->assertFalse($opcoes[CURLOPT_HEADER] ?? null);
            $this->assertTrue($opcoes[CURLOPT_RETURNTRANSFER] ?? null);
            $this->assertTrue($opcoes[CURLOPT_SSL_VERIFYPEER] ?? null);
            $this->assertSame(2, $opcoes[CURLOPT_SSL_VERIFYHOST] ?? null);
            $this->assertSame(7, $opcoes[CURLOPT_CONNECTTIMEOUT] ?? null);
            $this->assertSame(42, $opcoes[CURLOPT_TIMEOUT] ?? null);
        }
    }

    /**
     * A ordem das chaves não importa para o cURL, mas importa para quem lê:
     * transporte, corpo, proxy e, por último, as travas de segurança — assim
     * a trava é sempre a última palavra sobre a conexão.
     */
    public function testProxyEntraAntesDasTravasDeSeguranca(): void
    {
        $opcoes = opcoesCurlDaBotApi(self::URL, ['a' => 1], true, ['url' => 'proxy.local', 'port' => 3128], 10, 15);
        $chaves = array_keys($opcoes);

        $this->assertSame(
            [
                CURLOPT_URL, CURLOPT_HEADER, CURLOPT_RETURNTRANSFER,
                CURLOPT_POST, CURLOPT_POSTFIELDS,
                CURLOPT_PROXY, CURLOPT_PROXYPORT,
                CURLOPT_SSL_VERIFYPEER, CURLOPT_SSL_VERIFYHOST, CURLOPT_IPRESOLVE, CURLOPT_CONNECTTIMEOUT, CURLOPT_TIMEOUT,
            ],
            $chaves
        );
    }
}
