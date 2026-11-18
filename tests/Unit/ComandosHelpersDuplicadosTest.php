<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use ReflectionMethod;
use RuntimeException;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Garante que as cópias duplicadas de cortarMensagem(), escapeMarkdown() e
 * lerTextoDoRedis() em comandos/ver_audio.php e comandos/ver_imagem.php
 * continuam se COMPORTANDO como a versão de comandos/chatgpt.php (testada em
 * ComandosChatgptHelpersTest e em ComandosChatgptFluxoTextoTest).
 *
 * Nenhum dos três scripts pode ser incluído (fluxo top-level com sendMessage,
 * Redis e cURL reais). As três cópias são definidas com um SUFIXO por arquivo
 * (Support\ExtratorDeFuncoesDeScript::carregar) para coexistirem no mesmo
 * processo — sem o sufixo só a primeira encontrada existiria e uma mutação em
 * outra cópia passaria em silêncio. Com elas definidas lado a lado, os testes
 * principais EXECUTAM as três com as mesmas entradas e comparam as saídas.
 *
 * A comparação do código-fonte continua como segunda barreira: uma tabela de
 * entradas é finita, e a identidade byte a byte pega qualquer divergência que
 * a tabela não alcance.
 *
 * Há ainda um quarto par duplicado, este com nomes DIFERENTES em cada arquivo
 * (interpretarRespostaAudio e interpretarRespostaImagem): mesmo percurso
 * choices[0].message.content, escrito duas vezes. Como os nomes diferem, nem a
 * comparação textual nem os testes de cada comando enxergam uma divergência
 * entre eles — por isso os dois também são executados lado a lado aqui.
 */
final class ComandosHelpersDuplicadosTest extends TestCase
{
    /** @var array<int, string> */
    private const FUNCOES_DUPLICADAS = ['cortarMensagem', 'escapeMarkdown', 'lerTextoDoRedis'];

    private const CHATGPT = __DIR__ . '/../../comandos/chatgpt.php';
    private const VER_AUDIO = __DIR__ . '/../../comandos/ver_audio.php';
    private const VER_IMAGEM = __DIR__ . '/../../comandos/ver_imagem.php';

    /** @var array<string, string> Sufixo aplicado às cópias de cada comando. */
    private const SUFIXOS = [
        'chatgpt' => '__copia_chatgpt',
        'ver_audio' => '__copia_ver_audio',
        'ver_imagem' => '__copia_ver_imagem',
    ];

    public static function setUpBeforeClass(): void
    {
        $arquivos = [
            'chatgpt' => self::CHATGPT,
            'ver_audio' => self::VER_AUDIO,
            'ver_imagem' => self::VER_IMAGEM,
        ];

        foreach ($arquivos as $chave => $caminho) {
            Support\ExtratorDeFuncoesDeScript::carregar(
                $caminho,
                self::FUNCOES_DUPLICADAS,
                self::SUFIXOS[$chave]
            );
        }

        // Os dois leitores de resposta da OpenAI (nomes diferentes, navegação
        // duplicada) são carregados SEM sufixo para serem executados lado a
        // lado em testLeitoresDeRespostaDaOpenAiConcordamEmTodoCorpo().
        // cortarMensagem() vem junto porque os dois a chamam; as cópias são
        // idênticas, então a primeira definida serve para ambos.
        Support\ExtratorDeFuncoesDeScript::carregar(
            self::VER_AUDIO,
            ['cortarMensagem', 'interpretarRespostaAudio']
        );
        Support\ExtratorDeFuncoesDeScript::carregar(
            self::VER_IMAGEM,
            ['cortarMensagem', 'interpretarRespostaImagem']
        );

        // Os outros três trios duplicados com nomes DIFERENTES em cada comando.
        // Como os nomes não coincidem, nem a comparação textual das cópias nem a
        // bateria de cada comando enxerga uma divergência entre eles.
        Support\ExtratorDeFuncoesDeScript::carregar(
            self::CHATGPT,
            ['montarCorpoRequisicaoChatgpt', 'chamarChatCompletionsOpenAi']
        );
        Support\ExtratorDeFuncoesDeScript::carregar(
            self::VER_AUDIO,
            ['avisarUsuarioAudio', 'montarCorpoRequisicaoAudio', 'chamarChatCompletionsAudio']
        );
        Support\ExtratorDeFuncoesDeScript::carregar(
            self::VER_IMAGEM,
            ['avisarUsuarioImagem', 'montarCorpoRequisicaoImagem', 'chamarChatCompletionsImagem']
        );
    }

    /**
     * Entradas de cortarMensagem() que cobrem cada ramo: não-string, dentro do
     * limite, corte no último espaço, corte duro, espaço na posição 0 e
     * multibyte.
     *
     * @return array<string, array{mixed, int}>
     */
    public static function entradasDeCortarMensagemProvider(): array
    {
        return [
            'texto curto' => ['oi, tudo bem?', 4096],
            'string vazia' => ['', 4096],
            // "0" é falsy: uma cópia que trocasse is_string() por !empty()
            // devolveria o aviso fixo para um conteúdo legítimo.
            'texto zero' => ['0', 4096],
            'nulo' => [null, 4096],
            'inteiro' => [123, 4096],
            'array' => [['texto'], 4096],
            'booleano' => [true, 4096],
            'exatamente no limite' => ['abcde', 5],
            // Exatamente no limite E com espaço: separa "<= limite" de
            // "< limite" (com "<" a mensagem inteira seria cortada no espaço).
            'exatamente no limite com espaco' => ['ab cd', 5],
            'sem espaco corta duro' => ['abcdefghij', 5],
            'corta no ultimo espaco' => ['abcde fghij klm', 10],
            // Vários espaços dentro do trecho cortado: separa "último espaço"
            // de "primeiro espaço" (mb_strrpos x mb_strpos).
            'varios espacos no trecho' => ['uma frase com varias palavras aqui', 20],
            'varios espacos multibyte' => ['ação é boa demais para todos', 15],
            'espaco na posicao zero' => [' abcdef', 3],
            'multibyte' => ['ááááá', 3],
            'emoji' => [' 😼😼😼😼', 3],
            'legenda da imagem' => [str_repeat('c', 2000), 1024],
        ];
    }

    /**
     * As três cópias precisam devolver exatamente o mesmo resultado para a
     * mesma entrada. É este teste que uma mutação dentro de uma única cópia
     * (por exemplo cortar no primeiro espaço só no ver_imagem) tem de quebrar.
     *
     * @param mixed $mensagem
     * @param int $limite
     */
    #[DataProvider('entradasDeCortarMensagemProvider')]
    public function testCortarMensagemSeComportaIgualNasTresCopias($mensagem, int $limite): void
    {
        $referencia = $this->copia('cortarMensagem', 'chatgpt')($mensagem, $limite);
        $this->assertIsString($referencia);

        $this->assertSame(
            $referencia,
            $this->copia('cortarMensagem', 'ver_audio')($mensagem, $limite),
            'cortarMensagem() de comandos/ver_audio.php divergiu de comandos/chatgpt.php.'
        );
        $this->assertSame(
            $referencia,
            $this->copia('cortarMensagem', 'ver_imagem')($mensagem, $limite),
            'cortarMensagem() de comandos/ver_imagem.php divergiu de comandos/chatgpt.php.'
        );
    }

    /**
     * O limite PADRÃO (4096, usado pelas respostas da OpenAI) precisa ser o
     * mesmo nas três cópias: uma mensagem de 4097 caracteres sem espaço tem de
     * sair com 4096 em todas.
     */
    public function testLimitePadraoDe4096EIgualNasTresCopias(): void
    {
        $mensagem = str_repeat('a', 4097);
        $esperado = str_repeat('a', 4096);

        foreach (array_keys(self::SUFIXOS) as $arquivo) {
            $this->assertSame(
                $esperado,
                $this->copia('cortarMensagem', $arquivo)($mensagem),
                "Limite padrão divergente em comandos/{$arquivo}.php."
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entradasDeEscapeMarkdownProvider(): array
    {
        return [
            'underscore' => ['a_b'],
            'asterisco' => ['a*b'],
            'crase' => ['a`b'],
            'todos os especiais' => ['_*`'],
            'texto ja escapado' => ['a\\_b'],
            'sem caractere especial' => ['texto normal, [link](url) #titulo!'],
            'string vazia' => [''],
        ];
    }

    #[DataProvider('entradasDeEscapeMarkdownProvider')]
    public function testEscapeMarkdownSeComportaIgualNasTresCopias(string $entrada): void
    {
        $referencia = $this->copia('escapeMarkdown', 'chatgpt')($entrada);

        $this->assertSame(
            $referencia,
            $this->copia('escapeMarkdown', 'ver_audio')($entrada),
            'escapeMarkdown() de comandos/ver_audio.php divergiu de comandos/chatgpt.php.'
        );
        $this->assertSame(
            $referencia,
            $this->copia('escapeMarkdown', 'ver_imagem')($entrada),
            'escapeMarkdown() de comandos/ver_imagem.php divergiu de comandos/chatgpt.php.'
        );
    }

    /**
     * lerTextoDoRedis() precisa ler as MESMAS chaves, na mesma ordem, e tratar
     * ausência e presença do mesmo jeito nas três cópias. Cada cópia recebe seu
     * próprio dublê para que a lista de operações seja comparável.
     */
    public function testLerTextoDoRedisSeComportaIgualNasTresCopias(): void
    {
        $cenarios = [
            'chave ausente' => [null, ''],
            'chave presente' => ['mensagem anterior do usuário', 'mensagem anterior do usuário'],
            // Contexto guardado que é exatamente "0" (o usuário respondeu "0",
            // ou o modelo devolveu "0"): falsy em PHP, mas conteúdo legítimo.
            // Uma cópia que devolvesse '' aqui apagaria o histórico da conversa.
            'chave com o texto zero' => ['0', '0'],
        ];

        foreach ($cenarios as $rotulo => [$semente, $esperado]) {
            /** @var array<string, string> $resultados */
            $resultados = [];
            /** @var array<string, array<int, string>> $operacoes */
            $operacoes = [];

            foreach (array_keys(self::SUFIXOS) as $arquivo) {
                $redis = new Support\RedisFalsoComLua();
                if ($semente !== null) {
                    $redis->semear('contexto123', $semente);
                }

                $resultados[$arquivo] = $this->copia('lerTextoDoRedis', $arquivo)($redis, 'contexto123');
                $operacoes[$arquivo] = $redis->operacoes();
            }

            $this->assertSame($esperado, $resultados['chatgpt'], "Cenário '{$rotulo}' mudou no chatgpt.");
            $this->assertSame($resultados['chatgpt'], $resultados['ver_audio'], "Cenário '{$rotulo}': ver_audio divergiu.");
            $this->assertSame($resultados['chatgpt'], $resultados['ver_imagem'], "Cenário '{$rotulo}': ver_imagem divergiu.");
            $this->assertSame($operacoes['chatgpt'], $operacoes['ver_audio'], "Cenário '{$rotulo}': operações do ver_audio divergiram.");
            $this->assertSame($operacoes['chatgpt'], $operacoes['ver_imagem'], "Cenário '{$rotulo}': operações do ver_imagem divergiram.");
        }
    }

    /**
     * Redis fora do ar e valor de tipo inesperado precisam virar string vazia
     * nas três cópias — nunca false dentro do payload enviado à OpenAI.
     */
    public function testFalhaDoRedisViraTextoVazioNasTresCopias(): void
    {
        foreach (array_keys(self::SUFIXOS) as $arquivo) {
            $funcao = $this->copia('lerTextoDoRedis', $arquivo);

            $indisponivel = new Support\RedisFalsoComLua();
            $indisponivel->ficarIndisponivel();
            $this->assertSame(
                '',
                $funcao($indisponivel, 'contexto123'),
                "Redis fora do ar mudou em comandos/{$arquivo}.php."
            );

            $tipoErrado = new class () extends Redis {
                /**
                 * @param mixed $key
                 * @param mixed ...$other_keys
                 * @return int
                 */
                public function exists(mixed $key, mixed ...$other_keys): int
                {
                    return 1;
                }

                /**
                 * @param string $key
                 * @return mixed
                 */
                public function get(string $key): mixed
                {
                    return ['pedaço 1', 'pedaço 2'];
                }
            };
            $this->assertSame(
                '',
                $funcao($tipoErrado, 'contexto123'),
                "Valor não textual mudou em comandos/{$arquivo}.php."
            );
        }
    }

    /**
     * Corpos que a OpenAI pode devolver ao chat/completions, do caminho feliz
     * ao hostil.
     *
     * @return array<string, array{mixed}>
     */
    public static function corposDaOpenAiProvider(): array
    {
        return [
            'conteudo normal' => ['{"choices":[{"message":{"content":"Olá! 🐾"}}]}'],
            'conteudo zero' => ['{"choices":[{"message":{"content":"0"}}]}'],
            'conteudo vazio' => ['{"choices":[{"message":{"content":""}}]}'],
            'conteudo nulo' => ['{"choices":[{"message":{"content":null}}]}'],
            'conteudo nao textual' => ['{"choices":[{"message":{"content":{"pt":"oi"}}}]}'],
            'segunda escolha ignorada' => ['{"choices":[{"message":{"content":"a"}},{"message":{"content":"b"}}]}'],
            'choices vazio' => ['{"choices":[]}'],
            'choices nao e lista' => ['{"choices":"nada"}'],
            'escolha sem message' => ['{"choices":[{"index":0}]}'],
            'message sem content' => ['{"choices":[{"message":{"role":"assistant"}}]}'],
            'erro com mensagem' => ['{"error":{"message":"Rate limit reached"}}'],
            'erro sem mensagem' => ['{"error":{"type":"server_error"}}'],
            'erro que nao e objeto' => ['{"error":"falhou"}'],
            'erro nulo com conteudo' => ['{"error":null,"choices":[{"message":{"content":"ok"}}]}'],
            'erro tem prioridade' => ['{"error":{"message":"quota"},"choices":[{"message":{"content":"x"}}]}'],
            'html de gateway' => ['<html>502 Bad Gateway</html>'],
            'json truncado' => ['{"choices":[{"message":'],
            'json nulo' => ['null'],
            'corpo vazio' => [''],
            'timeout do curl' => [false],
            'corpo nao textual' => [['choices' => []]],
        ];
    }

    /**
     * interpretarRespostaAudio() e interpretarRespostaImagem() são o MESMO
     * percurso choices[0].message.content escrito duas vezes, com estilos
     * diferentes (o do áudio encadeia isset/is_array, o da imagem encadeia
     * `??`). Só a mensagem de falha muda entre os dois.
     *
     * Sem este teste, mexer em um dos leitores e não no outro passa em
     * silêncio: cada arquivo tem a sua própria bateria de casos, e nada compara
     * os dois. Aqui os dois recebem o MESMO corpo e precisam classificá-lo
     * igual — e, quando há texto vindo da API, entregá-lo igual.
     *
     * @param mixed $corpo
     */
    #[DataProvider('corposDaOpenAiProvider')]
    public function testLeitoresDeRespostaDaOpenAiConcordamEmTodoCorpo($corpo): void
    {
        $audio = interpretarRespostaAudio($corpo);
        $imagem = interpretarRespostaImagem($corpo);

        $this->assertSame(
            $audio['tipo'],
            $imagem['tipo'],
            'Os leitores de áudio e imagem classificaram o mesmo corpo de formas diferentes.'
        );

        if ($audio['tipo'] === 'falha' || $audio['tipo'] === 'erro_api') {
            // Único ponto em que divergem: cada comando tem o seu aviso fixo.
            //
            // 'erro_api' entrou aqui junto com 'falha' quando a mensagem crua da
            // OpenAI parou de ser repassada ao usuário: ela fala da CONTA
            // (billing, cota, organização) e virava texto público no grupo, e
            // agora vai só para o canal `erros_openai`. Do ponto de vista de
            // quem lê, erro da API e falha de transporte são o MESMO aviso.
            $this->assertSame("Erro ao gerar resposta. Tente novamente! ❌", $audio['texto']);
            $this->assertSame("Erro ao analisar a imagem. Tente novamente! ❌", $imagem['texto']);

            return;
        }

        // Conteúdo do assistente: os dois têm de entregar exatamente o mesmo.
        $this->assertSame($audio['texto'], $imagem['texto']);
    }

    /**
     * Mensagens que os TRÊS comandos podem mandar para o chat/completions.
     *
     * O bloco de visão fica de fora de propósito: só montarCorpoRequisicaoImagem
     * o declara na assinatura, e ele já é exercitado em
     * ComandosVerImagemFluxoTest. Aqui interessa o formato que os três
     * compartilham.
     *
     * @return array<string, array{array<int, array{role: string, content: string}>}>
     */
    public static function mensagensDoChatCompletionsProvider(): array
    {
        return [
            'so o developer e a pergunta' => [[
                ['role' => 'developer', 'content' => 'prompt de desenvolvedor'],
                ['role' => 'user', 'content' => 'oi'],
            ]],
            'com contexto e resposta anterior' => [[
                ['role' => 'developer', 'content' => 'prompt de desenvolvedor'],
                ['role' => 'user', 'content' => 'pergunta anterior'],
                ['role' => 'assistant', 'content' => 'resposta anterior'],
                ['role' => 'user', 'content' => 'e agora?'],
            ]],
            'conteudo zero' => [[
                ['role' => 'developer', 'content' => 'prompt de desenvolvedor'],
                ['role' => 'user', 'content' => '0'],
            ]],
            'conteudo vazio' => [[
                ['role' => 'developer', 'content' => 'prompt de desenvolvedor'],
                ['role' => 'user', 'content' => ''],
            ]],
            'texto multibyte com emoji' => [[
                ['role' => 'user', 'content' => 'ação é boa demais 😼 "aspas" e \\barra'],
            ]],
            'lista vazia' => [[]],
            // Byte inválido em UTF-8: os três precisam devolver false, e não um
            // JSON truncado enviado à OpenAI.
            'conteudo que o json recusa' => [[
                ['role' => 'user', 'content' => "\xB1\x31"],
            ]],
        ];
    }

    /**
     * montarCorpoRequisicaoChatgpt(), montarCorpoRequisicaoAudio() e
     * montarCorpoRequisicaoImagem() são o MESMO json_encode escrito três vezes:
     * mesmo modelo, mesma temperatura, mesmo teto de tokens.
     *
     * ProvedorOpenAiContratoTest já compara o TEXTO dos três arquivos, mas texto
     * igual não é resultado igual: trocar a ordem das chaves, acrescentar um
     * parâmetro em um só ou mudar as flags do json_encode passaria por lá e
     * chegaria à OpenAI como uma requisição diferente por comando. Aqui os três
     * recebem as MESMAS mensagens e o corpo gerado tem de ser byte a byte igual.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    #[DataProvider('mensagensDoChatCompletionsProvider')]
    public function testCorpoDaRequisicaoEIdenticoNosTresComandos(array $messages): void
    {
        $referencia = montarCorpoRequisicaoChatgpt($messages);

        $this->assertSame(
            $referencia,
            montarCorpoRequisicaoAudio($messages),
            'montarCorpoRequisicaoAudio() divergiu de montarCorpoRequisicaoChatgpt().'
        );
        $this->assertSame(
            $referencia,
            montarCorpoRequisicaoImagem($messages),
            'montarCorpoRequisicaoImagem() divergiu de montarCorpoRequisicaoChatgpt().'
        );
    }

    /**
     * O corpo comum precisa continuar levando modelo e limites — sem esta
     * âncora, os três poderiam divergir juntos (por exemplo se alguém trocasse
     * o modelo nos três de uma vez) e o teste acima continuaria verde.
     */
    public function testCorpoDaRequisicaoLevaModeloETetosCombinados(): void
    {
        $corpo = montarCorpoRequisicaoChatgpt([['role' => 'user', 'content' => 'oi']]);
        $this->assertIsString($corpo);

        $dados = json_decode($corpo, true);
        $this->assertIsArray($dados);
        $this->assertSame('openai/gpt-6-luna', $dados['model'] ?? null);
        $this->assertSame(1, $dados['temperature'] ?? null);
        $this->assertSame(['allow_fallbacks' => true, 'data_collection' => 'deny', 'ignore' => ['openai']], $dados['provider'] ?? null);
        $this->assertSame(2048, $dados['max_completion_tokens'] ?? null);
        $this->assertSame(['effort' => 'xhigh', 'exclude' => true], $dados['reasoning'] ?? null);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function respostasDoTransporteProvider(): array
    {
        return [
            'corpo normal' => ['{"choices":[{"message":{"content":"oi"}}]}'],
            'corpo vazio' => [''],
            'timeout do curl' => [false],
            'retorno nao textual' => [['choices' => []]],
            'retorno nulo' => [null],
        ];
    }

    /**
     * chamarChatCompletionsOpenAi(), ...Audio() e ...Imagem() são a mesma
     * chamada HTTP escrita três vezes. Os três recebem o MESMO transporte e
     * precisam montar a mesma URL, o mesmo corpo, os mesmos cabeçalhos (com a
     * chave da API) e converter o retorno do transporte do mesmo jeito.
     *
     * Um cabeçalho a mais em um só, ou um `is_string()` que virasse `(string)`
     * em um deles, mudaria a requisição paga de um comando e nada acusaria.
     *
     * @param mixed $resposta
     */
    #[DataProvider('respostasDoTransporteProvider')]
    public function testChamadaAoChatCompletionsEIdenticaNosTresComandos($resposta): void
    {
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $corpo = '{"model":"openai/gpt-6-luna"}';

        $observados = [];
        $retornos = [];
        $chamadas = [
            'chatgpt' => 'chamarChatCompletionsOpenAi',
            'ver_audio' => 'chamarChatCompletionsAudio',
            'ver_imagem' => 'chamarChatCompletionsImagem',
        ];

        foreach ($chamadas as $arquivo => $funcao) {
            $espiao = new Support\TransporteHttpEspiao($resposta);
            $retornos[$arquivo] = $funcao($url, 'sk-teste', $corpo, $espiao);
            $observados[$arquivo] = [
                'chamadas' => $espiao->chamadas,
                'url' => $espiao->url,
                'corpo' => $espiao->corpo,
                'cabecalhos' => $espiao->cabecalhos,
            ];
        }

        $this->assertSame(
            [
                'chamadas' => 1,
                'url' => $url,
                'corpo' => $corpo,
                'cabecalhos' => ['Content-Type: application/json', 'Authorization: Bearer sk-teste'],
            ],
            $observados['chatgpt']
        );
        $this->assertSame($observados['chatgpt'], $observados['ver_audio'], 'A chamada do ver_audio divergiu.');
        $this->assertSame($observados['chatgpt'], $observados['ver_imagem'], 'A chamada do ver_imagem divergiu.');

        $this->assertSame($retornos['chatgpt'], $retornos['ver_audio'], 'O retorno do ver_audio divergiu.');
        $this->assertSame($retornos['chatgpt'], $retornos['ver_imagem'], 'O retorno do ver_imagem divergiu.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function avisosAoUsuarioProvider(): array
    {
        return [
            'aviso comum' => ['Erro ao obter informações do arquivo. Tente novamente! ❌'],
            'aviso com markdown solto' => ['texto com _asterisco* solto e `crase`'],
            'aviso vazio' => [''],
            'aviso zero' => ['0'],
        ];
    }

    /**
     * avisarUsuarioAudio() e avisarUsuarioImagem() são o mesmo sendMessage
     * escrito duas vezes: mesmo chat, resposta à mensagem original,
     * allow_sending_without_reply e — o ponto que mais importa — SEM
     * parse_mode, porque o texto do aviso não é Markdown (um "_" no meio de uma
     * mensagem de erro da OpenAI faria o Telegram recusar o envio e o usuário
     * ficaria sem aviso nenhum).
     */
    #[DataProvider('avisosAoUsuarioProvider')]
    public function testAvisoAoUsuarioEIdenticoNosDoisComandosDeMidia(string $texto): void
    {
        $doAudio = $this->telegramEspiao();
        $daImagem = $this->telegramEspiao();

        avisarUsuarioAudio($doAudio, $texto);
        avisarUsuarioImagem($daImagem, $texto);

        $this->assertSame(
            [[
                'api' => 'sendMessage',
                'content' => [
                    'chat_id' => -100500,
                    'text' => $texto,
                    'reply_parameters' => '{"message_id":42,"allow_sending_without_reply":true}',
                ],
            ]],
            $doAudio->chamadas
        );
        $this->assertSame(
            $doAudio->chamadas,
            $daImagem->chamadas,
            'avisarUsuarioImagem() divergiu de avisarUsuarioAudio().'
        );
    }

    /**
     * Telegram que registra o envio em vez de chamar a API. O update é de
     * GRUPO de propósito: chat_id e id do autor são diferentes, então um aviso
     * que fosse para o autor em vez do chat apareceria na comparação.
     *
     * @return Telegram&object{chamadas: array<int, array{api: string, content: array<string, mixed>}>}
     */
    private function telegramEspiao()
    {
        $duble = new class ('123456:FAKE_TOKEN', false) extends Telegram {
            /** @var array<int, array{api: string, content: array<string, mixed>}> */
            public array $chamadas = [];

            /**
             * A forma do retorno é repetida à mão porque o alias
             * TelegramResponse é declarado na classe Telegram e não há como
             * importá-lo para dentro de uma classe anônima; um
             * `array<string, mixed>` aqui seria mais largo que o retorno do
             * método sobrescrito.
             *
             * @param string $api
             * @param array<string, mixed> $content
             * @param bool $post
             * @return array{ok: bool, result?: mixed, description?: string, error_code?: int, parameters?: array<string, mixed>}
             */
            public function endpoint(string $api, array $content, bool $post = true): array
            {
                $this->chamadas[] = ['api' => $api, 'content' => $content];

                return ['ok' => true, 'result' => ['message_id' => 4242]];
            }
        };
        $duble->setData([
            'update_id' => 1,
            'message' => [
                'message_id' => 42,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'text' => 'oi',
            ],
        ]);

        return $duble;
    }

    /**
     * Segunda barreira: identidade byte a byte das declarações. A tabela de
     * entradas acima é finita; esta comparação pega qualquer divergência que
     * ela não alcance (inclusive em ramos ainda sem caso de teste).
     */
    public function testCopiasDeVerAudioEVerImagemSaoIdenticasAoChatgpt(): void
    {
        $referencia = $this->extrairDeclaracoes(self::CHATGPT);

        // A extração precisa ter encontrado as três funções na referência.
        $this->assertStringContainsString('function cortarMensagem', $referencia);
        $this->assertStringContainsString('function escapeMarkdown', $referencia);
        $this->assertStringContainsString('function lerTextoDoRedis', $referencia);

        $this->assertSame(
            $referencia,
            $this->extrairDeclaracoes(self::VER_AUDIO),
            'Helpers de comandos/ver_audio.php divergiram de comandos/chatgpt.php; sincronize as cópias.'
        );
        $this->assertSame(
            $referencia,
            $this->extrairDeclaracoes(self::VER_IMAGEM),
            'Helpers de comandos/ver_imagem.php divergiram de comandos/chatgpt.php; sincronize as cópias.'
        );
    }

    /**
     * Devolve a cópia de $nome definida a partir do comando $arquivo. Falha
     * imediatamente se o extrator não a tiver definido — sem isso um erro de
     * extração viraria "teste verde" silencioso.
     *
     * @param string $nome Nome da função no código de produção
     * @param string $arquivo Chave de SUFIXOS ('chatgpt', 'ver_audio', 'ver_imagem')
     * @return callable
     */
    private function copia(string $nome, string $arquivo): callable
    {
        if (!isset(self::SUFIXOS[$arquivo])) {
            throw new RuntimeException("Comando desconhecido: {$arquivo}.");
        }

        $funcao = $nome . self::SUFIXOS[$arquivo];
        if (!function_exists($funcao)) {
            throw new RuntimeException("A cópia {$funcao} não foi definida pelo extrator.");
        }

        return $funcao;
    }

    /**
     * Invoca o extrator privado do Support\ExtratorDeFuncoesDeScript para obter o
     * código-fonte das funções sem defini-las (já estão definidas, com sufixo,
     * pelo setUpBeforeClass).
     *
     * @param string $caminhoArquivo
     * @return string
     */
    private function extrairDeclaracoes(string $caminhoArquivo): string
    {
        $metodo = new ReflectionMethod(Support\ExtratorDeFuncoesDeScript::class, 'extrairDeclaracoes');
        $declaracoes = $metodo->invoke(null, $caminhoArquivo, self::FUNCOES_DUPLICADAS);
        if (!is_string($declaracoes)) {
            throw new RuntimeException('extrairDeclaracoes deveria devolver string.');
        }

        return $declaracoes;
    }
}
