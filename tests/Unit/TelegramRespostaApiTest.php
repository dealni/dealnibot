<?php

declare(strict_types=1);

namespace Tests;

use App\Support\DiagnosticoTransporteTelegram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes do transporte e da decodificação da resposta da Bot API.
 *
 * Duas camadas são exercitadas aqui:
 *   1. endpoint(): recebe o texto cru devolvido pelo transporte e o normaliza
 *      no shape TelegramResponse. Toda resposta que não seja um objeto JSON com
 *      'ok' booleano vira o erro padronizado — nunca vaza mixed para quem chama.
 *      O transporte é substituído por um dublê (sendAPIRequest é protected).
 *   2. sendAPIRequest(): monta a URL, move o chat_id escalar para a query
 *      string e converte falha de cURL em resposta de erro. Aqui o cURL roda de
 *      verdade, mas a suíte roda sob sandbox de rede: nenhuma conexão sai.
 */
final class TelegramRespostaApiTest extends TestCase
{
    /** @var list<string> Arquivos temporários criados pelo teste em execução. */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }
        $this->temporarios = [];
    }

    /**
     * Arquivo temporário com o conteúdo informado, removido no tearDown.
     */
    private function arquivoTemporario(string $conteudo): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'dealnibot_transporte_');
        $this->assertIsString($caminho, 'tempnam precisa criar o arquivo do teste.');
        $this->temporarios[] = $caminho;
        $this->assertNotFalse(file_put_contents($caminho, $conteudo));

        return $caminho;
    }

    /**
     * Erro de cURL do envelope, já narrowado para string não vazia.
     *
     * @param array<string, mixed> $envelope
     */
    private function erroDeCurl(array $envelope): string
    {
        $mensagem = $this->texto($envelope, 'curl_error');
        $this->assertNotSame('', $mensagem, 'o cURL precisa ter falhado para este teste valer.');

        return $mensagem;
    }

    /**
     * Dublê do transporte: endpoint() roda de verdade sobre o texto informado.
     *
     * @return Telegram&object{resposta: string, chamadas: array<int, array{url: string, content: array<string, mixed>, post: bool}>}
     */
    private function transporte(string $respostaCrua)
    {
        $duble = new class ('123456:FAKE_TOKEN', false) extends Telegram {
            public string $resposta = '';
            /** @var array<int, array{url: string, content: array<string, mixed>, post: bool}> */
            public array $chamadas = [];

            /**
             * @param non-empty-string $url
             * @param array<string, mixed> $content
             * @param bool $post
             * @return string
             */
            protected function sendAPIRequest(string $url, array $content, bool $post = true): string
            {
                $this->chamadas[] = ['url' => $url, 'content' => $content, 'post' => $post];

                return $this->resposta;
            }
        };
        $duble->resposta = $respostaCrua;

        return $duble;
    }

    // === endpoint(): normalização da resposta ===

    public function testEndpointMontaAUrlComTokenENomeDoMetodo(): void
    {
        $duble = $this->transporte('{"ok":true}');

        $duble->endpoint('sendMessage', ['chat_id' => 1, 'text' => 'oi']);

        $this->assertSame([[
            'url' => 'https://api.telegram.org/bot123456:FAKE_TOKEN/sendMessage',
            'content' => ['chat_id' => 1, 'text' => 'oi'],
            'post' => true,
        ]], $duble->chamadas);
    }

    public function testEndpointComPostFalseNaoEnviaConteudoAoTransporte(): void
    {
        // O conteúdo é descartado de propósito: os endpoints GET da Bot API não
        // aceitam corpo, e mandar um faria o cURL abrir um POST.
        $duble = $this->transporte('{"ok":true}');

        $duble->endpoint('getMe', ['nao' => 'deve ir'], false);

        $this->assertSame([[
            'url' => 'https://api.telegram.org/bot123456:FAKE_TOKEN/getMe',
            'content' => [],
            'post' => false,
        ]], $duble->chamadas);
    }

    public function testEndpointPreservaResultDeSucesso(): void
    {
        $duble = $this->transporte('{"ok":true,"result":[{"update_id":1}]}');

        $this->assertSame(
            ['ok' => true, 'result' => [['update_id' => 1]]],
            $duble->endpoint('getUpdates', [])
        );
    }

    public function testEndpointPreservaResultNuloExplicito(): void
    {
        // array_key_exists, não isset: "result":null é uma resposta válida e a
        // chave precisa continuar existindo para quem checa por ela.
        $duble = $this->transporte('{"ok":true,"result":null}');

        $this->assertSame(['ok' => true, 'result' => null], $duble->endpoint('getChat', []));
    }

    public function testEndpointPreservaErroCompletoDaApi(): void
    {
        $duble = $this->transporte(
            '{"ok":false,"description":"Too Many Requests","error_code":429,"parameters":{"retry_after":5}}'
        );

        $this->assertSame([
            'ok' => false,
            'description' => 'Too Many Requests',
            'error_code' => 429,
            'parameters' => ['retry_after' => 5],
        ], $duble->endpoint('sendMessage', ['chat_id' => 1]));
    }

    public function testEndpointDescartaDetalhesComTipoErrado(): void
    {
        // description precisa ser string e error_code inteiro: valores com o
        // tipo trocado são descartados em vez de vazarem para os chamadores,
        // que declaram string/int no shape TelegramResponse.
        $duble = $this->transporte('{"ok":false,"description":123,"error_code":"429","parameters":"x"}');

        $this->assertSame(['ok' => false], $duble->endpoint('sendMessage', []));
    }

    public function testEndpointFiltraChavesNaoStringDeParameters(): void
    {
        // parameters é objeto JSON na Bot API. Um payload adulterado pode
        // misturar índices numéricos e propriedades válidas: os índices saem,
        // mas os detalhes nomeados precisam sobreviver para o retry do caller.
        $duble = $this->transporte(
            '{"ok":false,"parameters":{"0":"ruido","retry_after":5,"migrate_to_chat_id":-100123}}'
        );

        $this->assertSame([
            'ok' => false,
            'parameters' => ['retry_after' => 5, 'migrate_to_chat_id' => -100123],
        ], $duble->endpoint('sendMessage', []));
    }

    /**
     * Respostas que NÃO são um objeto JSON com 'ok' booleano.
     *
     * @return array<string, array{string}>
     */
    public static function respostaInvalidaProvider(): array
    {
        return [
            'vazia'             => [''],
            'json invalido'     => ['{"ok":'],
            'null'              => ['null'],
            'string json'       => ['"texto"'],
            'numero json'       => ['123'],
            'lista sem ok'      => ['[1,2]'],
            'objeto sem ok'     => ['{"result":[]}'],
            'ok como string'    => ['{"ok":"true"}'],
            'ok como inteiro'   => ['{"ok":1}'],
            'ok nulo'           => ['{"ok":null}'],
            'html do proxy'     => ['<html>502 Bad Gateway</html>'],
        ];
    }

    #[DataProvider('respostaInvalidaProvider')]
    public function testEndpointConverteRespostaInvalidaEmErroPadronizado(string $respostaCrua): void
    {
        $duble = $this->transporte($respostaCrua);

        $this->assertSame(
            ['ok' => false, 'description' => 'Resposta inválida da API do Telegram'],
            $duble->endpoint('sendMessage', ['chat_id' => 1])
        );
    }

    // === sendAPIRequest(): transporte real, sem rede ===

    /**
     * Expõe o transporte protegido para os testes que precisam chamá-lo direto.
     *
     * @param array<string, mixed> $proxy
     */
    private function transporteReal(array $proxy = [], bool $logErrors = false): TelegramTransporteReal
    {
        return new TelegramTransporteReal('000000:TEST_TOKEN', $logErrors, $proxy);
    }

    /**
     * Decodifica o envelope de erro do transporte, já narrowado.
     *
     * @return array<string, mixed>
     */
    private function envelope(string $json): array
    {
        $decodificado = json_decode($json, true);
        $this->assertIsArray($decodificado);
        $normalizado = [];
        foreach ($decodificado as $chave => $valor) {
            $this->assertIsString($chave);
            $normalizado[$chave] = $valor;
        }

        return $normalizado;
    }

    /**
     * Lê uma chave do envelope garantindo o tipo string (o JSON é mixed).
     *
     * @param array<string, mixed> $envelope
     */
    private function texto(array $envelope, string $chave): string
    {
        $valor = $envelope[$chave] ?? null;
        $this->assertIsString($valor);

        return $valor;
    }

    /**
     * Lê uma chave do envelope garantindo o tipo int (o JSON é mixed).
     *
     * @param array<string, mixed> $envelope
     */
    private function inteiro(array $envelope, string $chave): int
    {
        $valor = $envelope[$chave] ?? null;
        $this->assertIsInt($valor);

        return $valor;
    }

    public function testBloqueioDeRedeViraRespostaDeErroPadronizada(): void
    {
        self::assertTrue(function_exists('curl_exec'));

        $telegram = new Telegram('000000:TEST_TOKEN', false, [
            'url' => '127.0.0.1',
            'port' => 1,
            'type' => 0,
            'auth' => '',
        ]);

        $resposta = $telegram->getMe();

        self::assertSame(['ok', 'description', 'error_code'], array_keys($resposta));
        self::assertFalse($resposta['ok']);
        self::assertStringStartsWith('cURL error:', $resposta['description'] ?? '');
        self::assertGreaterThan(0, $resposta['error_code'] ?? 0);
    }

    public function testChatIdVaiCodificadoParaAQueryString(): void
    {
        // Um chat_id de canal por @nome pode conter caracteres que o cURL
        // recusa numa URL. Sem rawurlencode(), o cURL rejeita a URL inteira
        // (CURLE_URL_MALFORMAT = 3) e a mensagem nunca sai — falha diferente de
        // "não consegui conectar". O controle abaixo prova que o teste
        // distingue os dois casos.
        $urlBase = 'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage';
        $duble = $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0, 'auth' => '']);

        $envelope = $this->envelope($duble->enviar($urlBase, ['chat_id' => '@meu canal', 'text' => 'oi']));

        $this->assertFalse($envelope['ok']);
        $this->assertNotSame(
            3,
            $this->inteiro($envelope, 'error_code'),
            'a URL foi rejeitada pelo cURL: chat_id entrou cru'
        );

        // Controle: a MESMA query sem codificação é recusada com o código 3.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlBase . '?chat_id=@meu canal');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1');
        curl_setopt($ch, CURLOPT_PROXYPORT, 1);
        curl_exec($ch);
        $this->assertSame(3, curl_errno($ch), 'controle: sem codificação o cURL devolve CURLE_URL_MALFORMAT');
    }

    public function testChatIdNaoEscalarNaoEConvertidoParaString(): void
    {
        // Um chat_id que chegue como array (resposta malformada de outra
        // camada) não pode virar "Array to string conversion": o guard is_scalar
        // mantém o valor no corpo do POST. Sob a suíte, esse aviso viraria erro.
        $duble = $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0, 'auth' => '']);

        $envelope = $this->envelope($duble->enviar(
            'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage',
            ['chat_id' => ['a', 'b'], 'text' => 'oi']
        ));

        $this->assertFalse($envelope['ok']);
        $this->assertStringStartsWith('cURL error:', $this->texto($envelope, 'description'));
    }

    /**
     * Configurações de proxy com valores de tipos variados: cada opção só é
     * aplicada depois de provado o tipo, então nenhuma delas pode gerar
     * TypeError nem aviso de conversão em curl_setopt().
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function proxyProvider(): array
    {
        return [
            'sem proxy'            => [[]],
            'completo com auth'    => [['url' => '127.0.0.1', 'port' => 1, 'type' => 0, 'auth' => 'usuario:senha']],
            'auth vazia'           => [['url' => '127.0.0.1', 'port' => 1, 'type' => 0, 'auth' => '']],
            'porta como string'    => [['url' => '127.0.0.1', 'port' => '1', 'type' => '0']],
            'tipos nao numericos'  => [['url' => '127.0.0.1', 'port' => 'abc', 'type' => 'meia', 'auth' => ['x']]],
            'url nao escalar'      => [['url' => ['127.0.0.1'], 'port' => 1]],
            'chaves desconhecidas' => [['proxy' => '127.0.0.1']],
        ];
    }

    /**
     * @param array<string, mixed> $proxy
     */
    #[DataProvider('proxyProvider')]
    public function testConfiguracaoDeProxyEAplicadaSemQuebrarPorTipo(array $proxy): void
    {
        $duble = $this->transporteReal($proxy);

        $envelope = $this->envelope($duble->enviar(
            'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage',
            ['chat_id' => 1, 'text' => 'oi']
        ));

        $this->assertFalse($envelope['ok']);
        $this->assertStringStartsWith('cURL error:', $this->texto($envelope, 'description'));
        $this->assertGreaterThan(0, $this->inteiro($envelope, 'curl_error_code'));
    }

    /**
     * A TÉCNICA DO `file://`: servidor falso para testar transporte de cURL.
     * =====================================================================
     *
     * Esta é a receita usada em todo teste de transporte deste repositório.
     *
     * O PROBLEMA. A URL final montada por sendAPIRequest() não é devolvida por
     * ninguém: ela entra no `curl_setopt(CURLOPT_URL, ...)` e some. Inspecioná-
     * la exigiria um servidor do outro lado para contar o que recebeu — e a
     * suíte roda com os sockets NEGADOS pelo sistema operacional
     * (`RuntimeEnvironment::hasNetworkIsolation()`, exigido por
     * `tests/bootstrap.php`). Não há como abrir servidor nem no loopback, e
     * dublar o cURL não serviria: o que está sob teste é justamente a montagem
     * que ele consome.
     *
     * POR QUE `file://` FUNCIONA. É o único protocolo que a libcurl executa de
     * ponta a ponta sem socket nenhum: `curl_exec()` roda de verdade, com as
     * mesmas opções da produção, e lê um arquivo do disco. E o detalhe que faz
     * a técnica valer é este: ao tratar `file://`, a libcurl usa SÓ o componente
     * de caminho da URL — a query (`?...`) e o fragmento (`#...`) são
     * descartados antes de o arquivo ser aberto. Ou seja, o arquivo existe do
     * ponto de vista do cURL exatamente quando o que foi acrescentado à URL
     * ficou DEPOIS do '?'.
     *
     * O QUE ISSO MATA. Comparar o corpo lido com o conteúdo gravado reprova
     * qualquer mutação que mexa na fronteira caminho/query:
     *   - chat_id concatenado no caminho, sem o '?' → o caminho passa a incluir
     *     `chat_id=42`, o arquivo não existe, e volta o envelope de erro;
     *   - '?' e valor trocados de ordem → mesma coisa;
     *   - CURLOPT_RETURNTRANSFER removido → `curl_exec()` devolve `true` e
     *     IMPRIME o corpo: o assertSame reprova, e o `beStrictAboutOutput` da
     *     suíte reprova junto.
     *
     * O QUE ISSO NÃO MATA. Documentação que promete o que a técnica não
     * entrega é pior que documentação nenhuma, então esta lista importa tanto
     * quanto a de cima. Cada item foi conferido aplicando a mutação à mão em
     * inclusos/Telegram.php e rodando a suíte inteira:
     *   - o VALOR do chat_id é invisível aqui (a query é descartada); quem
     *     trava a codificação dele é testChatIdVaiCodificadoParaAQueryString;
     *   - remover `curl_setopt(CURLOPT_HEADER, false)` não muda nada: header
     *     desligado já é o padrão da libcurl. A chamada é defensiva, e nenhum
     *     teste pode reprová-la;
     *   - CURLOPT_POST e CURLOPT_POSTFIELDS são IGNORADOS pela libcurl no
     *     protocolo FILE. Trocar o `true` por `false`, ou apagar as duas
     *     linhas, devolve o mesmo corpo e a suíte inteira segue verde: o
     *     `file://` não observa MÉTODO nem CORPO da requisição;
     *   - CURLOPT_CONNECTTIMEOUT e CURLOPT_TIMEOUT também não: ler um arquivo
     *     do disco termina antes de qualquer relógio. Só um servidor que
     *     demore a responder distinguiria os dois casos.
     *
     * Ou seja: o `file://` cobre a montagem da URL e o modo de retorno do
     * cURL, e para no que exigiria alguém do outro lado da conexão. Esse é o
     * limite honesto da técnica, e quem reusá-la em outro teste deve parar
     * nele em vez de escrever teste que só parece cobrir.
     */
    public function testChatIdEntraNaQueryStringSemAlterarOCaminhoDaUrl(): void
    {
        $corpo = '{"ok":true,"result":"lido do disco"}';
        $arquivo = $this->arquivoTemporario($corpo);
        $duble = $this->transporteReal();

        $bruto = $duble->enviar('file://' . $arquivo, ['chat_id' => 42, 'text' => 'oi']);

        $this->assertSame($corpo, $bruto);
    }

    /**
     * Controle do teste acima: com o caminho inexistente o transporte devolve
     * o envelope de erro. Sem este controle, o `assertSame` acima passaria
     * também se o cURL estivesse devolvendo o corpo por outro motivo.
     *
     * A mensagem do cURL traz o caminho, e o envelope não pode escapar as
     * barras dele: `JSON_UNESCAPED_SLASHES` é o que mantém o caminho legível
     * para quem lê o log do incidente.
     */
    public function testEnvelopeDeErroPreservaAsBarrasDoCaminho(): void
    {
        $caminho = sys_get_temp_dir() . '/dealnibot_ausente_' . uniqid() . '/resposta.json';
        $duble = $this->transporteReal();

        $bruto = $duble->enviar('file://' . $caminho, []);

        $this->assertStringContainsString($caminho, $bruto);
        $this->assertStringNotContainsString('\\/', $bruto);
        $envelope = $this->envelope($bruto);
        $this->assertFalse($envelope['ok']);
    }

    /**
     * O proxy configurado precisa CHEGAR ao cURL: com ele, a conexão falha
     * contra o endereço do proxy; sem ele, falha contra api.telegram.org. Um
     * proxy silenciosamente ignorado deixaria o bot tentando sair pela rota
     * direta — que numa VPS com saída restrita simplesmente não sai.
     */
    public function testProxyConfiguradoEUsadoNaConexao(): void
    {
        $url = 'https://api.telegram.org/bot000000:TEST_TOKEN/getMe';

        $comProxy = $this->erroDeCurl($this->envelope(
            $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0])->enviar($url, [])
        ));
        $semProxy = $this->erroDeCurl($this->envelope(
            $this->transporteReal()->enviar($url, [])
        ));

        $this->assertStringContainsString('127.0.0.1', $comProxy);
        $this->assertStringNotContainsString('127.0.0.1', $semProxy);
        $this->assertStringContainsString('api.telegram.org', $semProxy);
    }

    /**
     * A descrição do envelope repete a mensagem EXATA do cURL. O 'unknown' é
     * só para quando o cURL não dá mensagem nenhuma: se ele virasse o texto
     * padrão de toda falha, o log do incidente perderia a causa.
     */
    public function testDescricaoDoErroRepeteAMensagemExataDoCurl(): void
    {
        $envelope = $this->envelope(
            $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0])
                ->enviar('https://api.telegram.org/bot000000:TEST_TOKEN/getMe', [])
        );

        $mensagem = $this->erroDeCurl($envelope);
        $this->assertNotSame('unknown', $mensagem);
        $this->assertSame('cURL error: ' . $mensagem, $this->texto($envelope, 'description'));
    }

    public function testEnvelopeDeFalhaRepeteOCodigoNativoDoCurlSemPerderOsDetalhes(): void
    {
        // Há dois consumidores do erro: endpoint() expõe error_code no shape
        // TelegramResponse, enquanto o logger do transporte usa os campos
        // curl_error*. Se os códigos divergirem, a resposta e o log descrevem
        // incidentes diferentes e a investigação segue a pista errada.
        $envelope = $this->envelope(
            $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0])
                ->enviar('https://api.telegram.org/bot000000:TEST_TOKEN/getMe', [], false)
        );

        $codigoNativo = $this->inteiro($envelope, 'curl_error_code');
        $mensagemNativa = $this->erroDeCurl($envelope);

        $this->assertGreaterThan(0, $codigoNativo);
        $this->assertSame($codigoNativo, $this->inteiro($envelope, 'error_code'));
        $this->assertSame('cURL error: ' . $mensagemNativa, $this->texto($envelope, 'description'));
    }

    /**
     * Com LOGS_ATIVO e log_errors ligados, a falha vira uma linha no canal
     * `telegram` do app.log — mas o que entra ali é o DIAGNÓSTICO TÉCNICO da
     * chamada (método, nomes dos campos e chat_id), nunca o conteúdo.
     *
     * Antes de contextoTecnicoDaFalha(), o transporte entregava
     * `[$this->getData(), $content]` ao TelegramErrorLogger: a cada `ok:false`
     * da Bot API, a RESPOSTA COMPLETA DO MODELO mais o UPDATE INTEIRO iam para
     * logs/app-*.log, que tem 14 dias de retenção — mais que o dobro das 24 h
     * de TTL da memória no Redis. O inventário de dado pessoal de
     * docs/SEGURANCA.md conta com o que este teste trava.
     *
     * Os dois casos ficam no MESMO teste de propósito: cada
     * #[RunInSeparateProcess] inicia um processo de PHP inteiro. Agrupar os
     * cenários preserva o isolamento e reduz o custo de inicialização.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTransporteRegistraNoAppLogSoOContextoTecnicoDaFalha(): void
    {
        define('LOGS_ATIVO', true);
        $tamanhoAnterior = \Tests\Support\AppLogReader::tamanhoAtual();

        // 1) Sem update em memória: método, NOMES dos campos e chat_id. O
        //    chat_id é lido antes de migrar para a query string da URL.
        $duble = $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0], true);
        $envelope = $this->envelope($duble->enviar(
            'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage',
            ['chat_id' => 1, 'text' => 'oi']
        ));
        $this->assertFalse($envelope['ok']);

        // 2) Com update em memória, o contexto NÃO cresce: o update não entra.
        $update = ['update_id' => 9, 'message' => ['text' => 'oi']];
        $duble->setData($update);
        $duble->enviar('https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage', ['text' => 'resposta']);

        $entradas = $this->entradasDoLog($tamanhoAnterior, 2);

        $this->assertSame('telegram', $entradas[0]['channel'] ?? null);
        $this->assertSame(
            'cURL error: ' . $this->erroDeCurl($envelope),
            $this->descricaoDoLog($entradas[0])
        );
        $contextoInicial = $this->contextoDoLog($entradas[0]);
        $tempos = DiagnosticoTransporteTelegram::filtrar($contextoInicial);
        self::assertSame(['curl_dns_ms', 'curl_conexao_ms', 'curl_tls_ms', 'curl_primeiro_byte_ms', 'curl_total_ms', 'curl_http_status'], array_keys($tempos));
        self::assertSame(0, $tempos['curl_http_status']);
        foreach ($tempos as $valor) {
            self::assertGreaterThanOrEqual(0, $valor);
        }
        $this->assertSame(
            ['metodo' => 'sendMessage', 'chat_id' => '1', 'campos' => ['chat_id', 'text']],
            array_diff_key($contextoInicial, $tempos)
        );

        $segundoContexto = $this->contextoDoLog($entradas[1]);
        $this->assertSame(
            ['metodo' => 'sendMessage', 'campos' => ['text']],
            array_diff_key($segundoContexto, DiagnosticoTransporteTelegram::filtrar($segundoContexto))
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCorpoRemotoNaoInjetaMetadadosLocaisDoTransporte(): void
    {
        define('LOGS_ATIVO', true);
        $inicio = \Tests\Support\AppLogReader::tamanhoAtual();
        $arquivo = $this->arquivoTemporario('{"ok":false,"error_code":500,"curl_total_ms":123,"curl_http_status":200,"url":"TOKEN_SECRETO"}');
        $this->transporteReal([], true)->enviar('file://' . $arquivo, []);
        $entradas = $this->entradasDoLog($inicio, 1);
        self::assertSame([], DiagnosticoTransporteTelegram::filtrar($this->contextoDoLog($entradas[0])));
        self::assertStringNotContainsString('TOKEN_SECRETO', json_encode($entradas, JSON_THROW_ON_ERROR));
    }

    public function testIncludeLegadoSemComposerPreservaEnvelopeDeFalha(): void
    {
        $script = $this->arquivoTemporario(<<<'PHP'
<?php
require $argv[1];
$antes = class_exists('Composer\\Autoload\\ClassLoader', false);
$telegram = new class ('000000:TEST_TOKEN', true) extends Telegram {
    public function enviar(): string
    {
        return $this->sendAPIRequest('file:///arquivo-inexistente-dealnibot-sem-composer', []);
    }
};
echo json_encode(['composer_antes' => $antes, 'resposta' => json_decode($telegram->enviar(), true)]);
PHP);
        $resultado = \Tests\Support\ExecutorCli::executar($script, [dirname(__DIR__, 2) . '/inclusos/Telegram.php']);
        self::assertSame(0, $resultado['codigo'], $resultado['stderr']);
        $dados = json_decode($resultado['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($dados);
        self::assertFalse($dados['composer_antes']);
        $resposta = $dados['resposta'];
        self::assertIsArray($resposta);
        self::assertFalse($resposta['ok']);
        self::assertIsInt($resposta['curl_error_code']);
        self::assertGreaterThan(0, $resposta['curl_error_code']);
    }

    /**
     * Regressão do vazamento: nem o TEXTO enviado nem o UPDATE em memória podem
     * reaparecer no app.log. A asserção é sobre o TRECHO CRU acrescentado ao
     * arquivo, e não sobre o array de contexto já decodificado, porque o
     * payload voltaria por qualquer caminho novo — um campo extra no contexto,
     * um `exception` com a mensagem dentro, uma interpolação no texto do log.
     *
     * Este teste FALHA com o comportamento anterior
     * (`TelegramErrorLogger::log(..., [$this->getData(), $content])`): o
     * `sk-proj-marcador` e o `update_id` apareciam literalmente na linha JSON.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLogDaFalhaNaoCarregaPayloadCruNemUpdateNemToken(): void
    {
        define('LOGS_ATIVO', true);
        $tamanhoAnterior = \Tests\Support\AppLogReader::tamanhoAtual();

        $duble = $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0], true);
        $duble->setData([
            'update_id' => 987654321,
            'message' => ['text' => 'pergunta-do-usuario-que-nao-pode-vazar'],
        ]);
        $duble->enviar(
            'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage',
            [
                'chat_id' => -100123,
                'text' => 'resposta-do-modelo-sk-proj-marcador',
                'reply_parameters' => '{"message_id":42}',
            ]
        );

        $acrescentado = \Tests\Support\AppLogReader::conteudoDesde($tamanhoAnterior);

        $this->assertNotSame('', $acrescentado, 'a falha precisa ter virado linha de log.');
        foreach (
            [
                'pergunta-do-usuario-que-nao-pode-vazar',
                'resposta-do-modelo-sk-proj-marcador',
                'update_id',
                '987654321',
                '{"message_id":42}',
            ] as $proibido
        ) {
            $this->assertStringNotContainsString(
                $proibido,
                $acrescentado,
                "o app.log voltou a carregar payload cru: {$proibido}"
            );
        }

        // O que PODE (e precisa) estar lá: método, nomes dos campos e chat_id.
        $this->assertStringContainsString('sendMessage', $acrescentado);
        $this->assertStringContainsString('reply_parameters', $acrescentado);
        $this->assertStringContainsString('-100123', $acrescentado);
    }

    /**
     * O 2º argumento do construtor vem `true`: um bot montado sem passar
     * log_errors tem de registrar as falhas da Bot API. Com o padrão
     * invertido, a produção pararia de registrar erro e nada avisaria.
     *
     * O controle vem junto, no mesmo processo: com log_errors = false nada é
     * acrescentado, nem com LOGS_ATIVO ligado.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLogDeErroVemLigadoPorPadraoEObedeceAoConstrutor(): void
    {
        define('LOGS_ATIVO', true);
        $tamanhoAnterior = \Tests\Support\AppLogReader::tamanhoAtual();

        // Sem o 2º argumento: o padrão do construtor precisa ser "logar".
        $padrao = new TelegramTransporteReal('000000:TEST_TOKEN');
        $padrao->enviar('https://api.telegram.org/bot000000:TEST_TOKEN/getMe', []);

        $entradas = $this->entradasDoLog($tamanhoAnterior, 1);
        $this->assertSame('telegram', $entradas[0]['channel'] ?? null);

        // Desligado explicitamente: nada mais é acrescentado.
        $marca = \Tests\Support\AppLogReader::tamanhoAtual();
        $mudo = $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0]);
        $mudo->enviar('https://api.telegram.org/bot000000:TEST_TOKEN/getMe', []);

        $this->assertSame('', \Tests\Support\AppLogReader::conteudoDesde($marca));
    }

    /**
     * Linhas acrescentadas ao app.log a partir do offset informado.
     *
     * @return list<array<string, mixed>>
     */
    private function entradasDoLog(int $offsetBytes, int $esperadas): array
    {
        $linhas = \Tests\Support\AppLogReader::linhasJson(
            \Tests\Support\AppLogReader::conteudoDesde($offsetBytes)
        );
        $this->assertCount($esperadas, $linhas);

        return $linhas;
    }

    /**
     * Mensagem da linha de log, sem o prefixo do TelegramErrorLogger.
     *
     * @param array<string, mixed> $entrada
     */
    private function descricaoDoLog(array $entrada): string
    {
        $mensagem = $entrada['msg'] ?? null;
        $this->assertIsString($mensagem);
        $this->assertStringStartsWith('Telegram API Error: ', $mensagem);

        return substr($mensagem, strlen('Telegram API Error: '));
    }

    /**
     * Lista `context.context` da linha de log (o que o transporte enviou).
     *
     * @param array<string, mixed> $entrada
     * @return array<int|string, mixed>
     */
    private function contextoDoLog(array $entrada): array
    {
        $contexto = $entrada['context'] ?? null;
        $this->assertIsArray($contexto);
        $interno = $contexto['context'] ?? null;
        $this->assertIsArray($interno);

        return $interno;
    }

    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function conteudosComTimeoutProvider(): array
    {
        return [
            // Toda chamada normal da Bot API — que em produção é a totalidade
            // delas, porque o bot roda por webhook.
            'sendMessage' => [['chat_id' => 1, 'text' => 'oi'], 15],
            'sem conteúdo' => [[], 15],
            // O getUpdates é o único que PEDE ao Telegram para segurar a
            // conexão: o teto curto a cortaria antes de a resposta chegar.
            'getUpdates com timeout 30' => [['offset' => 0, 'limit' => 100, 'timeout' => 30], 40],
            'getUpdates com timeout 1' => [['timeout' => 1], 11],
            // timeout 0 é polling não-bloqueante: volta ao teto curto.
            'getUpdates com timeout 0' => [['timeout' => 0], 15],
            'timeout negativo' => [['timeout' => -5], 15],
            'timeout numérico em texto' => [['timeout' => '30'], 40],
            'timeout de outro tipo' => [['timeout' => ['30']], 15],
        ];
    }

    /**
     * O teto de duração de cada chamada à Bot API.
     *
     * Vale para o orçamento de tempo do comando inteiro: cada chamada segura um
     * filho do PHP-FPM até responder, e o pior caso do fluxo (getFile +
     * sendMessage + o reenvio sem Markdown) é três vezes este número. O valor
     * caiu de 60 s para 15 s depois de uma janela de instabilidade do Telegram
     * ter esgotado a pool e deixado o bot mudo para todo mundo.
     *
     * @param array<string, mixed> $content
     */
    #[DataProvider('conteudosComTimeoutProvider')]
    public function testTetoDaRequisicaoPorConteudoDaChamada(
        array $content,
        int $esperado
    ): void {
        $this->assertSame($esperado, $this->transporteReal()->timeoutDe($content));
    }

    /**
     * @return array<string, array{non-empty-string, array<string, mixed>, array<string, mixed>}>
     */
    public static function contextosTecnicosProvider(): array
    {
        return [
            'método e campos, com chat_id' => [
                'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage',
                ['chat_id' => -100123, 'text' => 'resposta do modelo', 'parse_mode' => 'Markdown'],
                ['metodo' => 'sendMessage', 'campos' => ['chat_id', 'text', 'parse_mode'], 'chat_id' => '-100123'],
            ],
            'sem chat_id' => [
                'https://api.telegram.org/bot000000:TEST_TOKEN/getMe',
                [],
                ['metodo' => 'getMe', 'campos' => []],
            ],
            // chat_id não escalar não entra: a Bot API só aceita int/string, e
            // um array ali seria conteúdo, não identificador de roteamento.
            'chat_id de outro tipo' => [
                'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage',
                ['chat_id' => ['id' => 1], 'text' => 'oi'],
                ['metodo' => 'sendMessage', 'campos' => ['chat_id', 'text']],
            ],
            // O basename sai do CAMINHO, nunca da URL crua: o token vive em
            // /bot<TOKEN>/<metodo> e a query iria junto.
            'com query string' => [
                'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage?chat_id=7',
                ['text' => 'oi'],
                ['metodo' => 'sendMessage', 'campos' => ['text']],
            ],
        ];
    }

    /**
     * O contexto que vai para o log tem método, NOMES dos campos e `chat_id` —
     * e nada mais. Ver testLogDaFalhaNaoCarregaPayloadCruNemUpdateNemToken.
     *
     * @param non-empty-string $url
     * @param array<string, mixed> $content
     * @param array<string, mixed> $esperado
     */
    #[DataProvider('contextosTecnicosProvider')]
    public function testContextoTecnicoDaFalhaNaoCarregaValorDeCampo(
        string $url,
        array $content,
        array $esperado
    ): void {
        $this->assertSame($esperado, $this->transporteReal()->contextoDe($url, $content));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTransporteComLogAtivoPreservaFalhaSemLogsAtivo(): void
    {
        // Falha real de transporte deve ser registrada mesmo sem LOGS_ATIVO
        // definida; a configuração controla apenas o log de rotina.
        //
        // O teste roda em processo separado de propósito: outros arquivos da
        // suíte definem LOGS_ATIVO=true no processo principal e a constante não
        // pode ser redefinida — sem o isolamento a premissa dependeria da ordem
        // de execução.
        //
        // Só o TRECHO acrescentado ao app.log é lido: o arquivo do operador
        // pode chegar a dezenas de MB; ler o arquivo inteiro gasta memória
        // sem acrescentar informação ao cenário exercitado.
        $this->assertFalse(defined('LOGS_ATIVO'));
        $tamanhoAnterior = \Tests\Support\AppLogReader::tamanhoAtual();

        $duble = $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0, 'auth' => ''], true);
        $envelope = $this->envelope($duble->enviar(
            'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage',
            ['chat_id' => 1, 'text' => 'oi']
        ));

        $this->assertFalse($envelope['ok']);
        $eventos = \Tests\Support\AppLogReader::linhasJson(\Tests\Support\AppLogReader::conteudoDesde($tamanhoAnterior));
        $this->assertCount(1, $eventos);
        $this->assertSame('error', $eventos[0]['level']);
    }

    /**
     * O token vive no CAMINHO da URL (`/bot<TOKEN>/<metodo>`): se o contexto
     * passasse a URL adiante — ou usasse basename() sobre a URL crua — o
     * segredo iria para o disco junto com o erro.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testContextoDaFalhaNuncaCarregaOTokenDaUrl(): void
    {
        define('LOGS_ATIVO', true);
        $tamanhoAnterior = \Tests\Support\AppLogReader::tamanhoAtual();

        $duble = $this->transporteReal(['url' => '127.0.0.1', 'port' => 1, 'type' => 0], true);
        $duble->enviar('https://api.telegram.org/bot000000:TEST_TOKEN/getChat', ['chat_id' => 7]);

        $linhaCrua = \Tests\Support\AppLogReader::conteudoDesde($tamanhoAnterior);
        $this->assertStringContainsString('getChat', $linhaCrua);
        $this->assertStringNotContainsString('TEST_TOKEN', $linhaCrua);
        // O host sozinho não é segredo (e volta na mensagem do próprio cURL);
        // o que não pode aparecer é o segmento /bot<TOKEN>/ do caminho.
        $this->assertStringNotContainsString('/bot000000', $linhaCrua);
    }

    public function testChamadaComumUsaOTetoCurto(): void
    {
        $transporte = $this->transporteReal();

        // O caminho de produção inteiro: webhook, nenhuma chamada pede ao
        // Telegram para segurar a conexão.
        $this->assertSame(15, $transporte->timeoutDe(['chat_id' => 1, 'text' => 'oi']));
        $this->assertSame(15, $transporte->timeoutDe([]));
    }

    public function testLongPollingSomaAMargemAoTimeoutPedido(): void
    {
        $transporte = $this->transporteReal();

        // Sem a soma, um getUpdates pedindo 50s morreria no teto de 15s e o
        // polling do bot.php não fecharia um ciclo sequer.
        $this->assertSame(60, $transporte->timeoutDe(['offset' => 0, 'limit' => 100, 'timeout' => 50]));
        $this->assertSame(11, $transporte->timeoutDe(['timeout' => 1]));
    }

    public function testTimeoutZeroOuInvalidoCaiNoTetoCurto(): void
    {
        $transporte = $this->transporteReal();

        // getUpdates() tem timeout 0 por padrão: é chamada normal, não long
        // polling, e não pode herdar margem nenhuma.
        $this->assertSame(15, $transporte->timeoutDe(['timeout' => 0]));
        $this->assertSame(15, $transporte->timeoutDe(['timeout' => -5]));
        $this->assertSame(15, $transporte->timeoutDe(['timeout' => 'abc']));
        $this->assertSame(15, $transporte->timeoutDe(['timeout' => null]));
    }

    /**
     * O `timeout` é truncado para inteiro ANTES de decidir se há long polling
     * e ANTES de somar a margem.
     *
     * Sem o corte na comparação, um `timeout` fracionário menor que 1 — que a
     * Bot API trata como "não segure a conexão" — passaria a valer como long
     * polling e o teto cairia de 15s para a margem sozinha. Sem o corte na
     * soma, o método devolveria float onde o tipo declarado é int.
     */
    public function testTimeoutFracionadoETruncadoAntesDeDecidirEDeSomar(): void
    {
        $transporte = $this->transporteReal();

        // 0.5 truncado é 0: não é long polling, vale o teto curto.
        $this->assertSame(15, $transporte->timeoutDe(['timeout' => 0.5]));
        $this->assertSame(15, $transporte->timeoutDe(['timeout' => '0.9']));

        // 1.5 truncado é 1: long polling de 1s mais a margem de 10s.
        $this->assertSame(11, $transporte->timeoutDe(['timeout' => 1.5]));
    }

    /**
     * @return array<string, array{array<string, mixed>, array{0: string, 1: array<string, mixed>}}>
     */
    public static function migracoesDeChatIdProvider(): array
    {
        $base = 'https://api.telegram.org/botX/sendMessage';

        return [
            'inteiro sai do corpo e entra na query' => [
                ['chat_id' => 4242, 'text' => 'oi'],
                [$base . '?chat_id=4242', ['text' => 'oi']],
            ],
            'negativo de supergrupo mantém o sinal' => [
                ['chat_id' => -1001234567890, 'text' => 'oi'],
                [$base . '?chat_id=-1001234567890', ['text' => 'oi']],
            ],
            // O @ e o espaço são o motivo do rawurlencode: sem ele o cURL
            // recusa a URL com CURLE_URL_MALFORMAT.
            'arroba e espaço são escapados' => [
                ['chat_id' => '@meu canal'],
                [$base . '?chat_id=%40meu%20canal', []],
            ],
            // chat_id "0" é falsy: com empty() no lugar de isset() ele ficaria
            // no corpo e a chamada iria para a conversa errada.
            'texto zero continua migrando' => [
                ['chat_id' => '0'],
                [$base . '?chat_id=0', []],
            ],
            'sem chat_id a URL não ganha query' => [
                ['text' => 'oi'],
                [$base, ['text' => 'oi']],
            ],
            // Array não vira "Array to string conversion": fica no corpo.
            'chat_id não escalar fica no corpo' => [
                ['chat_id' => ['id' => 1], 'text' => 'oi'],
                [$base, ['chat_id' => ['id' => 1], 'text' => 'oi']],
            ],
        ];
    }

    /**
     * O VALOR do chat_id precisa chegar à URL, não só a chave.
     *
     * Este caso não existia enquanto a montagem morava dentro do
     * sendAPIRequest(): o cURL descarta a query de uma URL `file://` — o único
     * protocolo que a suíte alcança sem rede —, então apagar o valor e deixar
     * `?chat_id=` passava por todos os testes e quebrava toda chamada em
     * produção. Com migrarChatIdParaAQuery() pura, é uma asserção direta.
     *
     * @param array<string, mixed> $content
     * @param array{0: string, 1: array<string, mixed>} $esperado
     */
    #[DataProvider('migracoesDeChatIdProvider')]
    public function testChatIdMigraDoCorpoParaAQueryComValorEEscape(
        array $content,
        array $esperado
    ): void {
        $this->assertSame(
            $esperado,
            $this->transporteReal()->urlComChatId('https://api.telegram.org/botX/sendMessage', $content)
        );
    }

    /**
     * URL sem caminho não inventa nome de método.
     *
     * `parse_url(..., PHP_URL_PATH)` devolve null aqui, e as DUAS condições do
     * guard precisam valer juntas: com `||` no lugar de `&&`, o basename()
     * receberia null e o contexto do log sairia com método vazio — perdendo a
     * única informação que diz qual chamada falhou.
     */
    public function testContextoDeUrlSemCaminhoNaoInventaMetodo(): void
    {
        $this->assertSame(
            ['metodo' => 'desconhecido', 'campos' => []],
            $this->transporteReal()->contextoDe('https://api.telegram.org', [])
        );
    }
}

/**
 * Expõe sendAPIRequest() (protected) para os testes de transporte.
 *
 * stream/reflexão não servem aqui: o que se quer exercitar é o método real,
 * com o cURL de verdade, sob o sandbox de rede da suíte. Uma classe NOMEADA
 * (em vez de anônima) é necessária para que o PHPStan enxergue enviar().
 */
final class TelegramTransporteReal extends Telegram
{
    /**
     * @param non-empty-string $url
     * @param array<string, mixed> $content
     * @param bool $post
     * @return string
     */
    public function enviar(string $url, array $content, bool $post = true): string
    {
        return $this->sendAPIRequest($url, $content, $post);
    }

    /**
     * @param array<string, mixed> $content
     */
    public function timeoutDe(array $content): int
    {
        return $this->timeoutDaRequisicaoEmSegundos($content);
    }

    /**
     * @param non-empty-string $url
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function contextoDe(string $url, array $content): array
    {
        return $this->contextoTecnicoDaFalha($url, $content);
    }

    /**
     * @param non-empty-string $url
     * @param array<string, mixed> $content
     * @return array{0: non-empty-string, 1: array<string, mixed>}
     */
    public function urlComChatId(string $url, array $content): array
    {
        return $this->migrarChatIdParaAQuery($url, $content);
    }
}
