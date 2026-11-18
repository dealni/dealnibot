<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Testes do FLUXO DE TEXTO de comandos/chatgpt.php exercitando o código de
 * produção: montagem do payload da OpenAI, chamada HTTP com transporte
 * injetado, leitura do contexto no Redis e interpretação de toda resposta
 * possível (válida, malformada, erro da API e falha de transporte/timeout).
 *
 * O script continua NÃO podendo ser incluído (o topo chama sendChatAction,
 * sendMessage e cURL reais assim que é incluído); as funções são definidas com
 * Support\ExtratorDeFuncoesDeScript, que extrai só as declarações.
 *
 * Nenhuma rede é tocada: chamarChatCompletionsOpenAi() recebe o transporte
 * pelo parâmetro opcional, cujo padrão continua sendo o cURL de produção.
 */
final class ComandosChatgptFluxoTextoTest extends TestCase
{
    private const CAMINHO_SCRIPT = __DIR__ . '/../../comandos/chatgpt.php';

    /** Prompt de desenvolvedor exato do comando (o sufixo é a data/hora). */
    private const PROMPT_DESENVOLVEDOR = "Você é Dealni, um gato inteligente do Telegram.\n"
        . "\n"
        . "CAPACIDADES:\n"
        . "- Processar mensagens de texto\n"
        . "- Analisar e descrever imagens\n"
        . "- Ouvir e transcrever áudios\n"
        . "\n"
        . "INSTRUÇÕES:\n"
        . "- Responda no mesmo idioma do usuário\n"
        . "- Use emojis de forma natural e moderada\n"
        . "- Seja útil, amigável e direto\n"
        . "\n"
        . 'Data e hora em São Paulo: ';

    private const ERRO_GENERICO = "Erro ao gerar resposta. Tente novamente! ❌";

    public static function setUpBeforeClass(): void
    {
        Support\ExtratorDeFuncoesDeScript::carregar(self::CAMINHO_SCRIPT, [
            'cortarMensagem',
            'lerTextoDoRedis',
            'montarMensagensChatgptComHistorico',
            'montarCorpoRequisicaoChatgpt',
            'chamarChatCompletionsOpenAi',
            'interpretarRespostaChatgpt',
        ]);
    }

    // ---------------------------------------------------------------------
    // Payload enviado à OpenAI
    // ---------------------------------------------------------------------

    public function testPayloadSemContextoLevaSomenteDeveloperEMensagemAtual(): void
    {
        $this->assertSame(
            [
                ['role' => 'developer', 'content' => self::PROMPT_DESENVOLVEDOR . '01/02/2026 03:04:05'],
                ['role' => 'user', 'content' => 'oi, gato'],
            ],
            montarMensagensChatgptComHistorico('oi, gato', [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']], '01/02/2026 03:04:05')
        );
    }

    public function testPayloadComContextoMantemAOrdemDeveloperUsuarioAssistenteAtual(): void
    {
        $this->assertSame(
            [
                ['role' => 'developer', 'content' => self::PROMPT_DESENVOLVEDOR . '01/02/2026 03:04:05'],
                ['role' => 'user', 'content' => 'pergunta anterior'],
                ['role' => 'assistant', 'content' => 'resposta anterior'],
                ['role' => 'user', 'content' => 'pergunta atual'],
            ],
            montarMensagensChatgptComHistorico('pergunta atual', [['role' => 'user', 'content' => 'pergunta anterior'], ['role' => 'assistant', 'content' => 'resposta anterior']], '01/02/2026 03:04:05')
        );
    }

    public function testPayloadIgnoraContextoVazioEMantemSomenteOAssistente(): void
    {
        $this->assertSame(
            [
                ['role' => 'developer', 'content' => self::PROMPT_DESENVOLVEDOR . '01/02/2026 03:04:05'],
                ['role' => 'assistant', 'content' => 'só a resposta anterior'],
                ['role' => 'user', 'content' => 'atual'],
            ],
            montarMensagensChatgptComHistorico('atual', [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => 'só a resposta anterior']], '01/02/2026 03:04:05')
        );
    }

    public function testPayloadMantemContextoZero(): void
    {
        // "0" é conteúdo legítimo. Com empty() ele era falsy e o contexto sumia
        // do payload, fazendo o modelo responder sem saber o que veio antes.
        $mensagens = montarMensagensChatgptComHistorico('atual', [['role' => 'user', 'content' => '0'], ['role' => 'assistant', 'content' => '0']], '01/02/2026 03:04:05');

        $this->assertSame(
            [
                ['role' => 'developer', 'content' => self::PROMPT_DESENVOLVEDOR . '01/02/2026 03:04:05'],
                ['role' => 'user', 'content' => '0'],
                ['role' => 'assistant', 'content' => '0'],
                ['role' => 'user', 'content' => 'atual'],
            ],
            $mensagens
        );
    }

    public function testRelogioPadraoUsaAHoraAtualNoFormatoDoComando(): void
    {
        // Sem relógio injetado (caminho de produção) o prompt recebe a data
        // atual no formato d/m/Y H:i:s.
        $mensagens = montarMensagensChatgptComHistorico('oi', [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']]);
        $desenvolvedor = $mensagens[0]['content'] ?? '';

        $this->assertStringStartsWith(self::PROMPT_DESENVOLVEDOR, $desenvolvedor);
        $this->assertMatchesRegularExpression(
            '/Data e hora em São Paulo: \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/',
            $desenvolvedor
        );
    }

    public function testCorpoDaRequisicaoLevaModeloLimitesEMensagens(): void
    {
        $mensagens = montarMensagensChatgptComHistorico('oi', [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']], '01/02/2026 03:04:05');
        $corpo = montarCorpoRequisicaoChatgpt($mensagens);
        $this->assertIsString($corpo, 'O corpo da requisição precisa ser gerado.');

        $this->assertSame(
            [
                'model' => 'openai/gpt-6-luna',
                'provider' => ['allow_fallbacks' => true, 'data_collection' => 'deny', 'ignore' => ['openai']],
                'messages' => $mensagens,
                'temperature' => 1,
                'max_completion_tokens' => 2048,
                'reasoning' => [
                    'effort' => 'xhigh',
                    'exclude' => true,
                ],
            ],
            $this->decodificarJson($corpo)
        );
    }

    public function testCorpoDaRequisicaoDevolveFalseQuandoOJsonNaoPodeSerGerado(): void
    {
        // Texto com byte inválido em UTF-8: json_encode falha e o comando
        // avisa o usuário em vez de enviar corpo vazio à OpenAI.
        $this->assertFalse(
            montarCorpoRequisicaoChatgpt([['role' => 'user', 'content' => "\xB1\x31"]])
        );
    }

    // ---------------------------------------------------------------------
    // Chamada HTTP (transporte injetado)
    // ---------------------------------------------------------------------

    public function testChamadaEnviaUrlCorpoECabecalhosComAChaveDaApi(): void
    {
        $recebido = [];
        $chamadas = 0;

        $resposta = chamarChatCompletionsOpenAi(
            'https://openrouter.ai/api/v1/chat/completions',
            'sk-teste',
            '{"model":"openai/gpt-6-luna"}',
            function (string $url, string $corpo, array $cabecalhos) use (&$recebido, &$chamadas): string {
                $chamadas++;
                $recebido = ['url' => $url, 'corpo' => $corpo, 'cabecalhos' => $cabecalhos];

                return '{"choices":[]}';
            }
        );

        $this->assertSame(1, $chamadas, 'A API precisa ser chamada exatamente uma vez.');
        $this->assertSame(
            [
                'url' => 'https://openrouter.ai/api/v1/chat/completions',
                'corpo' => '{"model":"openai/gpt-6-luna"}',
                'cabecalhos' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer sk-teste',
                ],
            ],
            $recebido
        );
        $this->assertSame('{"choices":[]}', $resposta);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function retornosDeFalhaDoTransporteProvider(): array
    {
        return [
            'timeout do cURL (false)' => [false],
            'sem retorno' => [null],
            'retorno de tipo inesperado' => [['corpo']],
            'retorno numerico' => [500],
        ];
    }

    /**
     * Qualquer retorno que não seja string (o false do curl_exec em timeout,
     * DNS ou conexão recusada) vira false, sem exceção e sem espera real.
     *
     * @param mixed $retorno
     */
    #[DataProvider('retornosDeFalhaDoTransporteProvider')]
    public function testChamadaDevolveFalseQuandoOTransporteNaoDevolveCorpo($retorno): void
    {
        $inicio = microtime(true);

        $resposta = chamarChatCompletionsOpenAi(
            'https://openrouter.ai/api/v1/chat/completions',
            'sk-teste',
            '{}',
            static function () use ($retorno) {
                return $retorno;
            }
        );

        $this->assertFalse($resposta);
        $this->assertLessThan(1.0, microtime(true) - $inicio, 'O teste não pode esperar de verdade.');
    }

    public function testTimeoutDoTransporteViraMensagemDeErroParaOUsuario(): void
    {
        // Cadeia completa da falha: curl_exec devolve false -> chamada devolve
        // false -> interpretação manda a mensagem genérica de erro.
        $resposta = chamarChatCompletionsOpenAi(
            'https://openrouter.ai/api/v1/chat/completions',
            'sk-teste',
            '{}',
            static function () {
                return false;
            }
        );

        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaChatgpt($resposta)
        );
    }

    public function testTransporteComumTemTimeoutStatusHttpEErroDoCurl(): void
    {
        $fonte = file_get_contents(__DIR__ . '/../../comandos/funcoes_http_openai.php');
        $this->assertIsString($fonte, 'o transporte HTTP comum precisa ser legível.');

        foreach ([
            'CURLOPT_CONNECTTIMEOUT',
            'CURLOPT_TIMEOUT',
            'CURLOPT_SSL_VERIFYPEER',
            'CURLOPT_SSL_VERIFYHOST',
            'curl_setopt_array',
            'curl_getinfo',
            'CURLINFO_HTTP_CODE',
            'curl_errno',
            'curl_error',
            'registrarFalhaHttpOpenAi',
        ] as $protecao) {
            $this->assertStringContainsString($protecao, $fonte);
        }

        $this->assertStringNotContainsString('Authorization: Bearer', $fonte);
    }

    // ---------------------------------------------------------------------
    // Interpretação da resposta
    // ---------------------------------------------------------------------

    public function testRespostaValidaDevolveOConteudoDoAssistente(): void
    {
        $corpo = json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Miau! 🐾']],
            ],
        ]);
        $this->assertIsString($corpo);

        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => 'Miau! 🐾'],
            interpretarRespostaChatgpt($corpo)
        );
    }

    public function testRespostaValidaMuitoLongaERecortadaAntesDoEnvio(): void
    {
        $conteudo = str_repeat('a', 5000);
        $corpo = json_encode(['choices' => [['message' => ['content' => $conteudo]]]]);
        $this->assertIsString($corpo);

        $interpretada = interpretarRespostaChatgpt($corpo);

        $this->assertSame('resposta', $interpretada['tipo']);
        $this->assertSame(str_repeat('a', 4096), $interpretada['texto']);
        $this->assertSame(4096, mb_strlen($interpretada['texto'], 'UTF-8'));
    }

    public function testRespostaValidaUsaApenasAPrimeiraEscolha(): void
    {
        $corpo = json_encode([
            'choices' => [
                ['message' => ['content' => 'primeira']],
                ['message' => ['content' => 'segunda']],
            ],
        ]);
        $this->assertIsString($corpo);

        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => 'primeira'],
            interpretarRespostaChatgpt($corpo)
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function respostasSemConteudoProvider(): array
    {
        return [
            'falha de transporte' => [false],
            'corpo vazio' => [''],
            'corpo que nao e json' => ['<html>502 Bad Gateway</html>'],
            'json truncado' => ['{"choices":[{"message":'],
            'json valido que nao e objeto' => ['"apenas um texto"'],
            'json nulo' => ['null'],
            'objeto sem choices' => ['{"id":"chatcmpl-1"}'],
            'choices vazio' => ['{"choices":[]}'],
            'choices de tipo errado' => ['{"choices":"nada"}'],
            'escolha sem message' => ['{"choices":[{"index":0}]}'],
            'message sem content' => ['{"choices":[{"message":{"role":"assistant"}}]}'],
            'content nulo' => ['{"choices":[{"message":{"content":null}}]}'],
            'content vazio' => ['{"choices":[{"message":{"content":""}}]}'],
            // Entradas não textuais: fixam o parâmetro `mixed`. Com
            // `string|false` nativo, 500 viraria "500" e nulo/array virariam
            // TypeError — o usuário receberia uma exceção no lugar do aviso.
            'corpo nulo' => [null],
            'corpo numerico' => [500],
            'corpo em array' => [['choices' => []]],
        ];
    }

    /**
     * Resposta inválida, malformada ou sem conteúdo cai sempre na mesma
     * mensagem genérica de erro — nunca em texto vazio para o Telegram.
     *
     * @param mixed $corpo
     */
    #[DataProvider('respostasSemConteudoProvider')]
    public function testRespostaInvalidaViraMensagemGenericaDeErro($corpo): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaChatgpt($corpo)
        );
    }

    public function testContentDeTipoInesperadoViraFalha(): void
    {
        // content como objeto não é resposta nenhuma: cai na falha genérica, e
        // não em um 'resposta' com texto de erro dentro.
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaChatgpt('{"choices":[{"message":{"content":{"texto":"oi"}}}]}')
        );
    }

    public function testContentZeroEUmaRespostaValida(): void
    {
        // "0" é falsy em PHP: com empty() a resposta legítima do modelo virava
        // "erro ao gerar resposta".
        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => '0'],
            interpretarRespostaChatgpt('{"choices":[{"message":{"content":"0"}}]}')
        );
    }

    public function testErroDaApiEnviaAMensagemDevolvidaPelaOpenAi(): void
    {
        $corpo = json_encode([
            'error' => [
                'message' => 'Rate limit reached for gpt-5.6-luna',
                'type' => 'insufficient_quota',
                'code' => 'rate_limit_exceeded',
            ],
        ]);
        $this->assertIsString($corpo);

        $this->assertSame(
            ['tipo' => 'erro_api', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaChatgpt($corpo)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function errosSemMensagemUtilProvider(): array
    {
        return [
            'error sem message' => ['{"error":{"type":"server_error"}}'],
            'error com message de tipo errado' => ['{"error":{"message":{"pt":"falhou"}}}'],
            'error que nao e objeto' => ['{"error":"falhou"}'],
            'error booleano' => ['{"error":true}'],
        ];
    }

    /**
     * Erro em formato inesperado ainda avisa o usuário, só sem detalhe.
     */
    #[DataProvider('errosSemMensagemUtilProvider')]
    public function testErroDaApiSemMensagemUtilEnviaSomenteOPrefixo(string $corpo): void
    {
        $this->assertSame(
            ['tipo' => 'erro_api', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaChatgpt($corpo)
        );
    }

    public function testErroDaApiTemPrioridadeSobreOConteudo(): void
    {
        // Corpo com error E choices: o erro é reportado e o conteúdo ignorado.
        $corpo = '{"error":{"message":"quota"},"choices":[{"message":{"content":"ignorado"}}]}';

        $this->assertSame(
            ['tipo' => 'erro_api', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaChatgpt($corpo)
        );
    }

    public function testErroNuloNaRespostaNaoBloqueiaOConteudo(): void
    {
        // A OpenAI manda "error": null em algumas respostas de sucesso.
        $corpo = '{"error":null,"choices":[{"message":{"content":"tudo certo"}}]}';

        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => 'tudo certo'],
            interpretarRespostaChatgpt($corpo)
        );
    }

    // ---------------------------------------------------------------------
    // Leitura do contexto no Redis
    // ---------------------------------------------------------------------

    public function testContextoAusenteNoRedisViraTextoVazio(): void
    {
        $redis = new Support\RedisFalsoComLua();

        $this->assertSame('', lerTextoDoRedis($redis, 'contexto123'));
        $this->assertSame(['EXISTS contexto123'], $redis->operacoes());
    }

    public function testContextoPresenteNoRedisEDevolvidoIntegralmente(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto123', 'mensagem anterior do usuário');

        $this->assertSame('mensagem anterior do usuário', lerTextoDoRedis($redis, 'contexto123'));
        $this->assertSame(['EXISTS contexto123', 'GET contexto123'], $redis->operacoes());
    }

    public function testRedisIndisponivelNaLeituraDoContextoNaoQuebraOFluxo(): void
    {
        // phpredis sem exceções devolve false em todo comando: o comando segue
        // sem contexto em vez de mandar false para dentro do payload.
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivel();

        $this->assertSame('', lerTextoDoRedis($redis, 'contexto123'));
    }

    public function testExcecaoDoRedisNaLeituraDoContextoNaoQuebraOFluxo(): void
    {
        // Com Redis::OPT_THROW_EXCEPTIONS (ou um erro WRONGTYPE), phpredis
        // lança em vez de devolver false. Memória é opcional após o ACK e deve
        // degradar para contexto vazio nesse caso.
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivelComExcecao();

        $this->assertSame('', lerTextoDoRedis($redis, 'contexto123'));
    }

    public function testValorDeTipoInesperadoNoRedisViraTextoVazio(): void
    {
        // Se a chave for reescrita com outro tipo (lista, hash), o get devolve
        // algo que não é string; o comando trata como ausência de contexto.
        $redis = $this->createStub(Redis::class);
        $redis->method('exists')->willReturn(1);
        $redis->method('get')->willReturn(['pedaço 1', 'pedaço 2']);

        $this->assertSame('', lerTextoDoRedis($redis, 'contexto123'));
    }

    // ---------------------------------------------------------------------
    // Utilitários
    // ---------------------------------------------------------------------

    /**
     * @param string $json
     * @return array<string, mixed>
     */
    private function decodificarJson(string $json): array
    {
        $dados = json_decode($json, true);
        $this->assertIsArray($dados, 'O corpo enviado à OpenAI precisa ser JSON válido.');

        $normalizado = [];
        foreach ($dados as $chave => $valor) {
            $normalizado[(string) $chave] = $valor;
        }

        return $normalizado;
    }
}
