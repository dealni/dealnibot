<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes de FLUXO do script de configuração do webhook
 * (config/config_webhook.php): interpretação da resposta do setWebhook, sem
 * tocar rede.
 *
 * O script continua NÃO podendo ser incluído (docs/CONVENCOES.md): incluí-lo
 * REGISTRA o webhook de verdade, apontando a produção para outro lugar. Mas a
 * decisão inteira (o que imprimir e com qual código sair) mora em
 * config_webhook_resultado(), no irmão config/funcoes_config_webhook.php, que
 * Support\ExtratorDeFuncoesDeScript carrega com require_once DE VERDADE — sem
 * executar nenhuma linha do topo do script. Os testes abaixo exercitam o CÓDIGO
 * DE PRODUÇÃO, e por ser um require_once (e não o eval() do extrator) a
 * cobertura é atribuída ao arquivo.
 *
 * Só a montagem da chamada (URL + campos do POST) continua no topo, fora de
 * função: ela é reproduzida com o dublê Support\TransporteCurlEspiao no lugar
 * da rede e travada na fonte por testTopoDoScriptMontaAChamadaEUsaAFuncao().
 */
final class ConfigWebhookFluxoTest extends TestCase
{
    private const CAMINHO_SCRIPT = __DIR__ . '/../../config/config_webhook.php';

    private const CAMINHO_DADOS_BOT = __DIR__ . '/../../inclusos/dados_bot.php';

    private const TOKEN_FAKE = '123456:FAKE_TOKEN';

    private const URL_ESPERADA = 'https://api.telegram.org/bot123456:FAKE_TOKEN/setWebhook';

    /** URL sintética da instalação usada nos testes. */
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const BASE_PUBLICA = 'https://bot.example.com';

    /** Variável de ambiente que dados_bot.php lê para PUBLIC_BASE_URL. */
    private const VARIAVEL_DA_BASE_PUBLICA = 'DEALNI_PUBLIC_BASE_URL';

    /**
     * Campos de produto além de `url` e `secret_token` (o bloco de dados de
     * config/config_webhook.php) e os trechos do script que os montam.
     *
     * @var array<string, string>
     */
    private const CAMPOS_DE_PRODUTO = [];

    /** @var list<string> */
    private const TRECHOS_DE_PRODUTO = [];
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    private const WEBHOOK_URL = self::BASE_PUBLICA . '/bot_web.php';

    private const SEGREDO = 'segredo-do-webhook-para-testes-123';

    public static function setUpBeforeClass(): void
    {
        Support\ExtratorDeFuncoesDeScript::carregar(self::CAMINHO_SCRIPT, ['config_webhook_resultado']);
    }

    /**
     * Executa a chamada como o topo do script faz (URL a partir do token, campo
     * `url` montado a partir de PUBLIC_BASE_URL e `secret_token` sempre
     * presente) e devolve o resultado da função de produção.
     *
     * @return array{saida: string, codigo: int}
     */
    private function configurarWebhook(
        string $tokenBot,
        Support\TransporteCurlEspiao $transporte,
        int $httpCode = 200,
        string $segredoConfigurado = self::SEGREDO
    ): array {
        $url = 'https://api.telegram.org/bot' . $tokenBot . '/setWebhook';
        $webhook_url = rtrim(trim(self::BASE_PUBLICA), '/') . '/bot_web.php';

        // Mesma montagem do topo do script (travada em
        // testTopoDoScriptMontaAChamadaEUsaAFuncao): o secret_token é sempre
        // enviado, lido de constant('TELEGRAM_WEBHOOK_SECRET').
        $campos = [
            'url' => $webhook_url,
            'secret_token' => $segredoConfigurado,
        ] + self::CAMPOS_DE_PRODUTO;

        $resultadoTransporte = $transporte($url, $campos);

        return config_webhook_resultado(
            $resultadoTransporte['response'],
            $resultadoTransporte['erro_curl'],
            $httpCode,
            $webhook_url
        );
    }

    // ---------------------------------------------------------------------
    // Fluxo feliz
    // ---------------------------------------------------------------------

    public function testSucessoConfiguraOWebhookEDevolveCodigoZero(): void
    {
        $espiao = new Support\TransporteCurlEspiao('{"ok":true,"result":true}');

        $resultado = $this->configurarWebhook(self::TOKEN_FAKE, $espiao);

        $this->assertSame(0, $resultado['codigo']);
        $this->assertSame(
            'Webhook configurado com sucesso: ' . self::WEBHOOK_URL . PHP_EOL,
            $resultado['saida']
        );
        $this->assertSame(1, $espiao->chamadas);
        $this->assertSame(self::URL_ESPERADA, $espiao->url);
        $this->assertSame(
            ['url' => self::WEBHOOK_URL, 'secret_token' => self::SEGREDO] + self::CAMPOS_DE_PRODUTO,
            $espiao->postFields
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusDeSucessoProvider(): array
    {
        return [
            '200 OK' => [200],
            '299 (limite superior da faixa)' => [299],
        ];
    }

    #[DataProvider('statusDeSucessoProvider')]
    public function testQualquerStatus2xxComOkVerdadeiroContaComoSucesso(int $httpCode): void
    {
        $espiao = new Support\TransporteCurlEspiao('{"ok":true,"result":true}');

        $resultado = $this->configurarWebhook(self::TOKEN_FAKE, $espiao, $httpCode);

        $this->assertSame(0, $resultado['codigo']);
    }

    // ---------------------------------------------------------------------
    // secret_token: o que liga o gate de origem do bot_web.php
    // ---------------------------------------------------------------------

    public function testSegredoConfiguradoEhRegistradoNoTelegramComoSecretToken(): void
    {
        // Sem este campo o Telegram nunca devolve o header
        // X-Telegram-Bot-Api-Secret-Token e webhookTelegramAutorizado()
        // (inclusos/funcoes_webhook.php) rejeitaria 100% dos updates legítimos.
        $espiao = new Support\TransporteCurlEspiao('{"ok":true,"result":true}');

        $resultado = $this->configurarWebhook(self::TOKEN_FAKE, $espiao, 200, 'outro-segredo');

        $this->assertSame(0, $resultado['codigo']);
        $this->assertSame(
            ['url' => self::WEBHOOK_URL, 'secret_token' => 'outro-segredo'] + self::CAMPOS_DE_PRODUTO,
            $espiao->postFields
        );
    }

    // ---------------------------------------------------------------------
    // Falhas
    // ---------------------------------------------------------------------

    public function testErroDeTransporteDevolveCodigoUm(): void
    {
        $espiao = new Support\TransporteCurlEspiao(false, 'Could not resolve host');

        $resultado = $this->configurarWebhook(self::TOKEN_FAKE, $espiao, 0);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame(
            'Falha ao configurar o webhook: erro de cURL (Could not resolve host)' . PHP_EOL,
            $resultado['saida']
        );
    }

    public function testCorpoRecebidoComErroDeCurlNaoEhInterpretadoComoSucesso(): void
    {
        // curl_exec pode devolver corpo E erro (resposta truncada por timeout
        // no meio do download): o erro tem precedência, senão o script
        // anunciaria um webhook que talvez não tenha sido registrado.
        $espiao = new Support\TransporteCurlEspiao('{"ok":true}', 'Operation timed out');

        $resultado = $this->configurarWebhook(self::TOKEN_FAKE, $espiao);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame(
            'Falha ao configurar o webhook: erro de cURL (Operation timed out)' . PHP_EOL,
            $resultado['saida']
        );
    }

    public function testRespostaDeErroDoTelegramDevolveCodigoUmComADescricao(): void
    {
        $espiao = new Support\TransporteCurlEspiao('{"ok":false,"description":"webhook inválido"}');

        $resultado = $this->configurarWebhook(self::TOKEN_FAKE, $espiao, 400);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame(
            'Falha ao configurar o webhook (HTTP 400). Resposta do Telegram: webhook inválido' . PHP_EOL,
            $resultado['saida']
        );
    }

    public function testRespostaSemJsonValidoDevolveCodigoUmComOCorpoBruto(): void
    {
        $espiao = new Support\TransporteCurlEspiao('<html>502 Bad Gateway</html>');

        $resultado = $this->configurarWebhook(self::TOKEN_FAKE, $espiao, 502);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame(
            'Falha ao configurar o webhook (HTTP 502). Resposta do Telegram: <html>502 Bad Gateway</html>' . PHP_EOL,
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
     * Status HTTP fora de 2xx é falha mesmo com `"ok":true` no corpo: um proxy
     * ou uma página de erro podem devolver um JSON qualquer, e anunciar sucesso
     * aí deixaria a produção sem webhook sem ninguém perceber.
     */
    #[DataProvider('statusForaDaFaixaProvider')]
    public function testStatusHttpForaDe2xxNuncaContaComoSucesso(int $httpCode): void
    {
        $espiao = new Support\TransporteCurlEspiao('{"ok":true,"result":true}');

        $resultado = $this->configurarWebhook(self::TOKEN_FAKE, $espiao, $httpCode);

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

    /**
     * A comparação é `=== true`: só o booleano verdadeiro do Telegram conta.
     * Qualquer outro valor é resposta desconhecida e vira falha — nunca um
     * "configurado com sucesso" que não aconteceu.
     */
    #[DataProvider('okQueNaoEhVerdadeiroProvider')]
    public function testOkQueNaoEhBooleanoVerdadeiroNaoContaComoSucesso(string $corpo): void
    {
        $espiao = new Support\TransporteCurlEspiao($corpo);

        $resultado = $this->configurarWebhook(self::TOKEN_FAKE, $espiao);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertStringContainsString('Falha ao configurar o webhook (HTTP 200).', $resultado['saida']);
    }

    // ---------------------------------------------------------------------
    // Precondições que os testes acima assumem sobre o topo do script
    // ---------------------------------------------------------------------

    public function testTopoDoScriptMontaAChamadaEUsaAFuncao(): void
    {
        $fonte = file_get_contents(self::CAMINHO_SCRIPT);
        $this->assertIsString($fonte, 'config/config_webhook.php precisa ser legível.');

        $this->assertStringContainsString('config_telegram_requisicao($url, $campos);', $fonte);
        $transporte = file_get_contents(__DIR__ . '/../../config/funcoes_config_telegram.php');
        $this->assertIsString($transporte);
        $fonte .= $transporte;

        $trechos = [
            // A decisão inteira mora no irmão, e é o require_once que a traz.
            // Sem esta linha o script chamaria uma função indefinida em
            // produção — e, nos testes, o extrator voltaria a tokenizar +
            // eval(), que não atribui cobertura a arquivo nenhum.
            "require_once __DIR__ . '/funcoes_config_webhook.php';",
            "\$url = 'https://api.telegram.org/bot' . TOKEN_BOT . '/setWebhook';",
            "\$publicBaseUrl = defined('PUBLIC_BASE_URL') ? constant('PUBLIC_BASE_URL') : null;",
            "\$webhook_url = rtrim(trim(\$publicBaseUrl), '/') . '/bot_web.php';",
            // Cada curl_setopt tem o retorno conferido; um `false` aborta antes
            // do curl_exec. TLS explícito, sem redirecionamento e sem sinais.
            '!curl_setopt($ch, CURLOPT_POST, true)',
            "'url' => \$webhook_url,",
            "'secret_token' => constant('TELEGRAM_WEBHOOK_SECRET'),",
            ...self::TRECHOS_DE_PRODUTO,
            '!curl_setopt($ch, CURLOPT_POSTFIELDS, $campos)',
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
            '$resultado = config_webhook_resultado($response, $erro_curl, $httpCode, $webhook_url);',
            "exit(\$resultado['codigo']);",
        ];
        foreach ($trechos as $trecho) {
            $this->assertStringContainsString($trecho, $fonte, "O script deixou de conter: {$trecho}");
        }

        // Falha vai para o STDERR e sai com 1: `exit("texto")` sairia com
        // status 0 e o cron/shell veria sucesso onde o webhook não foi
        // registrado.
        $this->assertStringContainsString("fwrite(STDERR, \$resultado['saida']);", $fonte);
    }

    /**
     * A URL registrada vem do ambiente da instalação.
     */
    public function testUrlPublicaVemDaConfiguracaoDaInstalacao(): void
    {
        $fonte = file_get_contents(self::CAMINHO_SCRIPT);
        $this->assertIsString($fonte, 'config/config_webhook.php precisa ser legível.');
        $this->assertStringNotContainsString('$webhook_url = "https://', $fonte);

        $dadosBot = file_get_contents(self::CAMINHO_DADOS_BOT);
        $this->assertIsString($dadosBot, 'inclusos/dados_bot.php precisa ser legível.');
        $this->assertStringContainsString("getenv('" . self::VARIAVEL_DA_BASE_PUBLICA . "')", $dadosBot);
        $this->assertStringContainsString("define('PUBLIC_BASE_URL', rtrim(\$publicBaseUrl, '/'));", $dadosBot);
    }
}
