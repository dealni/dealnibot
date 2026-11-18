<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes de FLUXO do script de remoção do webhook
 * (config/config_remover.php): interpretação da resposta do deleteWebhook,
 * sem tocar rede.
 *
 * O script continua NÃO podendo ser incluído (docs/CONVENCOES.md): incluí-lo
 * DERRUBA o webhook de produção. Mas a decisão inteira (o que imprimir e com
 * qual código sair) mora em config_remover_resultado(), no irmão
 * config/funcoes_config_remover.php, que Support\ExtratorDeFuncoesDeScript
 * carrega com require_once DE VERDADE — sem executar nenhuma linha do topo do
 * script. Os testes abaixo exercitam o CÓDIGO DE PRODUÇÃO, e por ser um
 * require_once (e não o eval() do extrator) a cobertura é atribuída ao arquivo.
 *
 * Só a montagem da chamada (URL a partir do token) continua no topo, fora de
 * função: é reproduzida com o dublê Support\TransporteCurlEspiao no lugar da
 * rede e travada na fonte por testTopoDoScriptMontaAChamadaEUsaAFuncao().
 */
final class ConfigRemoverFluxoTest extends TestCase
{
    private const CAMINHO_SCRIPT = __DIR__ . '/../../config/config_remover.php';

    private const TOKEN_FAKE = '123456:FAKE_TOKEN';

    private const URL_ESPERADA = 'https://api.telegram.org/bot123456:FAKE_TOKEN/deleteWebhook';

    public static function setUpBeforeClass(): void
    {
        Support\ExtratorDeFuncoesDeScript::carregar(self::CAMINHO_SCRIPT, ['config_remover_resultado']);
    }

    /**
     * Executa a chamada como o topo do script faz (URL a partir do token, sem
     * campos no POST) e devolve o resultado da função de produção.
     *
     * @return array{saida: string, codigo: int}
     */
    private function removerWebhook(
        string $tokenBot,
        Support\TransporteCurlEspiao $transporte,
        int $httpCode = 200
    ): array {
        $url = 'https://api.telegram.org/bot' . $tokenBot . '/deleteWebhook';

        $resultadoTransporte = $transporte($url);

        return config_remover_resultado(
            $resultadoTransporte['response'],
            $resultadoTransporte['erro_curl'],
            $httpCode
        );
    }

    // ---------------------------------------------------------------------
    // Fluxo feliz
    // ---------------------------------------------------------------------

    public function testSucessoRemoveOWebhookEDevolveCodigoZero(): void
    {
        $espiao = new Support\TransporteCurlEspiao('{"ok":true,"result":true}');

        $resultado = $this->removerWebhook(self::TOKEN_FAKE, $espiao);

        $this->assertSame(0, $resultado['codigo']);
        $this->assertSame('Webhook removido com sucesso.' . PHP_EOL, $resultado['saida']);
        $this->assertSame(1, $espiao->chamadas);
        $this->assertSame(self::URL_ESPERADA, $espiao->url);
    }

    // ---------------------------------------------------------------------
    // Falhas
    // ---------------------------------------------------------------------

    public function testErroDeTransporteDevolveCodigoUm(): void
    {
        $espiao = new Support\TransporteCurlEspiao(false, 'Connection timed out');

        $resultado = $this->removerWebhook(self::TOKEN_FAKE, $espiao, 0);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame(
            'Falha ao remover o webhook: erro de cURL (Connection timed out)' . PHP_EOL,
            $resultado['saida']
        );
    }

    public function testCorpoRecebidoComErroDeCurlNaoEhInterpretadoComoSucesso(): void
    {
        // curl_exec pode devolver corpo E erro (resposta truncada por timeout
        // no meio do download): o erro tem precedência, senão o operador
        // acharia que o webhook caiu quando talvez ele continue registrado.
        $espiao = new Support\TransporteCurlEspiao('{"ok":true}', 'Operation timed out');

        $resultado = $this->removerWebhook(self::TOKEN_FAKE, $espiao);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame(
            'Falha ao remover o webhook: erro de cURL (Operation timed out)' . PHP_EOL,
            $resultado['saida']
        );
    }

    public function testRespostaDeErroDoTelegramDevolveCodigoUmComADescricao(): void
    {
        $espiao = new Support\TransporteCurlEspiao('{"ok":false,"description":"token inválido"}');

        $resultado = $this->removerWebhook(self::TOKEN_FAKE, $espiao, 401);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame(
            'Falha ao remover o webhook (HTTP 401). Resposta do Telegram: token inválido' . PHP_EOL,
            $resultado['saida']
        );
    }

    public function testRespostaSemJsonValidoDevolveCodigoUmComOCorpoBruto(): void
    {
        $espiao = new Support\TransporteCurlEspiao('<html>502 Bad Gateway</html>');

        $resultado = $this->removerWebhook(self::TOKEN_FAKE, $espiao, 502);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame(
            'Falha ao remover o webhook (HTTP 502). Resposta do Telegram: <html>502 Bad Gateway</html>' . PHP_EOL,
            $resultado['saida']
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusForaDaFaixaProvider(): array
    {
        return [
            'sem status (transporte não chegou a responder)' => [0],
            'redirecionamento' => [302],
            'não autorizado' => [401],
            'erro do servidor' => [500],
        ];
    }

    /**
     * Status HTTP fora de 2xx é falha mesmo com `"ok":true` no corpo: um
     * `deleteWebhook` recusado passando por bem-sucedido é justamente o defeito
     * que a função existe para fechar.
     */
    #[DataProvider('statusForaDaFaixaProvider')]
    public function testStatusHttpForaDe2xxNuncaContaComoSucesso(int $httpCode): void
    {
        $espiao = new Support\TransporteCurlEspiao('{"ok":true,"result":true}');

        $resultado = $this->removerWebhook(self::TOKEN_FAKE, $espiao, $httpCode);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertStringContainsString("(HTTP {$httpCode})", $resultado['saida']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function okQueNaoEhVerdadeiroProvider(): array
    {
        return [
            'ok textual' => ['{"ok":"true"}'],
            'ok numérico' => ['{"ok":1}'],
            'ok nulo' => ['{"ok":null}'],
        ];
    }

    #[DataProvider('okQueNaoEhVerdadeiroProvider')]
    public function testOkQueNaoEhBooleanoVerdadeiroNaoContaComoSucesso(string $corpo): void
    {
        $espiao = new Support\TransporteCurlEspiao($corpo);

        $resultado = $this->removerWebhook(self::TOKEN_FAKE, $espiao);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertStringContainsString('Falha ao remover o webhook (HTTP 200).', $resultado['saida']);
    }

    // ---------------------------------------------------------------------
    // Precondições que os testes acima assumem sobre o topo do script
    // ---------------------------------------------------------------------

    public function testTopoDoScriptMontaAChamadaEUsaAFuncao(): void
    {
        $fonte = file_get_contents(self::CAMINHO_SCRIPT);
        $this->assertIsString($fonte, 'config/config_remover.php precisa ser legível.');

        $this->assertStringContainsString('config_telegram_requisicao($url);', $fonte);
        $transporte = file_get_contents(__DIR__ . '/../../config/funcoes_config_telegram.php');
        $this->assertIsString($transporte);
        $fonte .= $transporte;

        $trechos = [
            // A decisão inteira mora no irmão, e é o require_once que a traz.
            // Sem esta linha o script chamaria uma função indefinida em
            // produção — e, nos testes, o extrator voltaria a tokenizar +
            // eval(), que não atribui cobertura a arquivo nenhum.
            "require_once __DIR__ . '/funcoes_config_remover.php';",
            "\$url = 'https://api.telegram.org/bot' . TOKEN_BOT . '/deleteWebhook';",
            // Cada curl_setopt tem o retorno conferido; um `false` aborta antes
            // do curl_exec. TLS explícito, sem redirecionamento e sem sinais.
            '!curl_setopt($ch, CURLOPT_POST, true)',
            '!curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false)',
            '!curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5)',
            '!curl_setopt($ch, CURLOPT_TIMEOUT, 15)',
            '!curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true)',
            '!curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2)',
            '!curl_setopt($ch, CURLOPT_NOSIGNAL, true)',
            '$response = curl_exec($ch);',
            '$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);',
            "\$erro_curl = curl_errno(\$ch) !== 0 ? curl_error(\$ch) : '';",
            // O resultado da função é o que o script imprime e devolve ao shell.
            '$resultado = config_remover_resultado($response, $erro_curl, $httpCode);',
            "exit(\$resultado['codigo']);",
        ];
        foreach ($trechos as $trecho) {
            $this->assertStringContainsString($trecho, $fonte, "O script deixou de conter: {$trecho}");
        }

        // Falha vai para o STDERR e sai com 1.
        $this->assertStringContainsString("fwrite(STDERR, \$resultado['saida']);", $fonte);

        // O script não instancia mais o Telegram: o objeto nunca era usado e o
        // require do cliente só aumentava a chance de um efeito no include.
        $this->assertStringNotContainsString('new Telegram(', $fonte);
    }
}
