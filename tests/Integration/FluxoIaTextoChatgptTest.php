<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes de FLUXO do comando de texto (comandos/chatgpt.php) da entrada do
 * update até a resposta enviada ao Telegram, amarrando as peças reais:
 *   - parsing do update pela classe Telegram (setData, sem rede);
 *   - cota diária de inclusos/limites_requisicoes.php com o Lua EXECUTADO
 *     pelo dublê Support\RedisFalsoComLua;
 *   - contexto/assistente no Redis com os TTLs de 24h;
 *   - payload, chamada e interpretação da resposta da OpenAI, com o transporte
 *     HTTP injetado (nenhuma rede, nenhuma espera real).
 *
 * O script continua NÃO podendo ser incluído: o topo chama sendChatAction e
 * sendMessage reais e, sem transporte injetado, faria cURL de verdade. A ordem
 * do topo é ESPELHADA em simularComandoTexto() usando as FUNÇÕES DE PRODUÇÃO
 * extraídas do próprio arquivo, e a fidelidade do espelho é travada na fonte
 * por testEspelhoDoFluxoContinuaFielAFonte() — que precisa listar TODA linha
 * de decisão espelhada: foi a ausência da guarda do texto recebido nessa lista
 * que deixou o espelho com `empty()` depois de o comando já ter sido corrigido.
 */
final class FluxoIaTextoChatgptTest extends TestCase
{
    public function testRecusaDeEscritaNaoDisparaChamadaPaga(): void
    {
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'), recusarAcao: true);
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('resposta'));
        $this->simularComandoTexto($telegram, $redis, $chat);
        self::assertSame(0, $chat->chamadas);
        self::assertNotContains('sendMessage', array_column($telegram->chamadas, 'api'));
        // A recusa fica lembrada: o roteador cala o chat pelas próximas seis horas.
        self::assertSame(RECUSA_DE_ESCRITA_TTL_SEGUNDOS, $redis->expiracaoDe(\chaveRecusaDeEscrita(777)));
    }

    private const CAMINHO_SCRIPT = __DIR__ . '/../../comandos/chatgpt.php';

    /** Chave fake: o script usa a constante OPENROUTER_API_KEY (inclusos/config.php). */
    private const CHAVE_API = 'sk-teste-fake';

    /** Relógio fixo injetado no prompt para o payload ser determinístico. */
    private const DATA_HORA = '01/02/2026 03:04:05';

    private const ERRO_GENERICO = "Erro ao gerar resposta. Tente novamente! ❌";

    public static function setUpBeforeClass(): void
    {
        // carregar() dá require_once de verdade nos funcoes_*.php irmãos do
        // script — é de lá que vem executarComandoChatgpt(), com a cobertura
        // atribuída ao arquivo de produção.
        Support\ExtratorDeFuncoesDeScript::carregar(self::CAMINHO_SCRIPT, [
            'cortarMensagem',
            'lerTextoDoRedis',
            'montarMensagensChatgptComHistorico',
            'montarCorpoRequisicaoChatgpt',
            'chamarChatCompletionsOpenAi',
            'interpretarRespostaChatgpt',
        ]);
        require_once __DIR__ . '/../../inclusos/limites_requisicoes.php';

        // O comando lê a chave da constante, como em produção. O
        // config_loader dá 'test-only' quando a variável não existe; aqui o
        // valor é explícito para o payload do teste ser determinístico.
        if (!defined('OPENROUTER_API_KEY')) {
            define('OPENROUTER_API_KEY', self::CHAVE_API);
        }
    }

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../inclusos/limites_requisicoes.php';
    }

    // ---------------------------------------------------------------------
    // Espelho do topo de comandos/chatgpt.php
    // ---------------------------------------------------------------------

    /**
     * Chama o comando de produção.
     *
     * Até 2026-08-24 este método era um ESPELHO escrito à mão do corpo de
     * comandos/chatgpt.php, porque script de topo não podia ser incluído. O
     * espelho tinha derivado: montava as mensagens com montarMensagensChatgpt()
     * e nunca tocava a memória rolante, que a produção lê e grava desde a
     * migração de contexto. Vinte e cinco testes exercitavam um fluxo que não
     * existia mais, e testEspelhoDoFluxoContinuaFielAFonte() não pegava porque
     * conferia se as linhas existiam no script, não se o espelho as reproduzia.
     *
     * Com o corpo extraído para executarComandoChatgpt(), no funcoes_chatgpt.php
     * irmão, a fidelidade deixa de depender de alguém manter duas cópias em dia.
     *
     * @param callable $transporte fn(string $url, string $corpo, array<int, string> $cabecalhos): mixed
     */
    private function simularComandoTexto(
        Telegram $telegram,
        Support\RedisFalsoComLua $redis,
        callable $transporte
    ): void {
        executarComandoChatgpt($telegram, $redis, $transporte, self::DATA_HORA);
    }

    // ---------------------------------------------------------------------
    // Dublês e utilitários
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $update
     * @param bool $respostaOk Resultado devolvido pela API do Telegram
     * @return Telegram&object{chamadas: array<int, array{api: string, content: array<string, mixed>}>, respostaOk: bool, recusarEnvios: bool}
     */
    private function telegramEspiao(array $update, bool $respostaOk = true, bool $recusarEnvios = false, bool $recusarAcao = false)
    {
        $duble = new class ('123456:FAKE_TOKEN', false) extends Telegram {
            /** @var array<int, array{api: string, content: array<string, mixed>}> */
            public array $chamadas = [];

            /** @var bool Resultado que a API do Telegram devolve em cada envio. */
            public bool $respostaOk = true;
            public bool $recusarEnvios = false;
            public bool $recusarAcao = false;

            /**
             * @param string $api
             * @param array<string, mixed> $content
             * @param bool $post
             * @return array{
             *     ok: bool,
             *     result?: mixed,
             *     description?: string,
             *     error_code?: int,
             *     parameters?: array<string, mixed>
             * }
             */
            public function endpoint(string $api, array $content, bool $post = true): array
            {
                $this->chamadas[] = ['api' => $api, 'content' => $content];

                if ($api === 'sendChatAction' && $this->recusarAcao) {
                    return ['ok' => false, 'error_code' => 400, 'description' => 'CHAT_WRITE_FORBIDDEN'];
                }
                if ($api === 'sendMessage' && $this->recusarEnvios) {
                    return ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden'];
                }
                if ($api === 'sendMessage' && !$this->respostaOk && isset($content['parse_mode'])) {
                    return ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"];
                }
                return ['ok' => true, 'result' => ['message_id' => 4242]];
            }
        };
        $duble->respostaOk = $respostaOk;
        $duble->recusarEnvios = $recusarEnvios;
        $duble->recusarAcao = $recusarAcao;
        $duble->setData($update);

        return $duble;
    }

    /**
     * Update de mensagem de texto em chat privado.
     *
     * @return array<string, mixed>
     */
    private function updatePrivado(int $idUsuario, string $texto): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => $idUsuario, 'is_bot' => false, 'first_name' => 'Fulano'],
                'chat' => ['id' => $idUsuario, 'type' => 'private'],
                'text' => $texto,
            ],
        ];
    }

    /**
     * @param array<int, array{api: string, content: array<string, mixed>}> $chamadas
     * @return array<int, string>
     */
    private function apisChamadas(array $chamadas): array
    {
        return array_map(
            static fn (array $chamada): string => $chamada['api'],
            $chamadas
        );
    }

    /**
     * @param array<int, array{api: string, content: array<string, mixed>}> $chamadas
     * @return array<int, string>
     */
    private function textosEnviados(array $chamadas): array
    {
        $textos = [];
        foreach ($chamadas as $chamada) {
            if ($chamada['api'] !== 'sendMessage') {
                continue;
            }
            $texto = $chamada['content']['text'] ?? null;
            $textos[] = is_string($texto) ? $texto : '';
        }

        return $textos;
    }

    /**
     * Mensagens do payload efetivamente enviado à OpenAI.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mensagensDoPayload(Support\TransporteHttpEspiao $transporte): array
    {
        $corpo = $transporte->corpo;
        $this->assertIsString($corpo, 'O transporte precisa ter recebido o corpo da requisição.');

        $payload = json_decode($corpo, true);
        $this->assertIsArray($payload, 'O corpo enviado à OpenAI precisa ser JSON válido.');
        $this->assertSame('openai/gpt-6-luna', $payload['model'] ?? null);
        $this->assertSame(['effort' => 'xhigh', 'exclude' => true], $payload['reasoning'] ?? null);

        $mensagens = $payload['messages'] ?? null;
        $this->assertIsArray($mensagens);

        $normalizadas = [];
        foreach ($mensagens as $mensagem) {
            $this->assertIsArray($mensagem);
            $item = [];
            foreach ($mensagem as $chave => $valor) {
                $item[(string) $chave] = $valor;
            }
            $normalizadas[] = $item;
        }

        return $normalizadas;
    }

    private function corpoDeResposta(string $conteudo): string
    {
        $corpo = json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $conteudo]]]]);
        $this->assertIsString($corpo);

        return $corpo;
    }

    // ---------------------------------------------------------------------
    // Fluxo feliz
    // ---------------------------------------------------------------------

    public function testFluxoDeTextoConsomeCotaEnviaPayloadEGuardaOContexto(): void
    {
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'));
        $redis = new Support\RedisFalsoComLua();

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('Miau! 🐾'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        // 1. Cota consumida uma vez, com o TTL de 24h aplicado na criação.
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();
        $this->assertSame('1', $redis->valorDe($chaveCota));
        $this->assertSame(86400, $redis->expiracaoDe($chaveCota));

        // 2. Requisição enviada à OpenAI: endpoint, autorização e mensagens.
        $this->assertSame(1, $transporte->chamadas);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $transporte->url);
        $this->assertSame(
            ['Content-Type: application/json', 'Authorization: Bearer ' . self::CHAVE_API],
            $transporte->cabecalhos
        );
        $mensagens = $this->mensagensDoPayload($transporte);
        $this->assertCount(2, $mensagens);
        $this->assertSame('developer', $mensagens[0]['role'] ?? null);
        $this->assertSame(['role' => 'user', 'content' => 'Olá!'], $mensagens[1] ?? null);

        // 3. Telegram: "typing" e a resposta em Markdown, respondendo à mensagem.
        $this->assertSame(['sendChatAction', 'sendMessage'], $this->apisChamadas($telegram->chamadas));
        $envio = $telegram->chamadas[1]['content'];
        $this->assertSame(777, $envio['chat_id'] ?? null);
        $this->assertSame('Miau! 🐾', $envio['text'] ?? null);
        $this->assertSame('Markdown', $envio['parse_mode'] ?? null);
        $this->assertSame(
            '{"message_id":10,"allow_sending_without_reply":true}',
            $envio['reply_parameters'] ?? null
        );

        // 4. Contexto e resposta guardados por 24h para a próxima mensagem.
        $this->assertSame('Olá!', $redis->valorDe('contexto777'));
        $this->assertSame(86400, $redis->expiracaoDe('contexto777'));
        $this->assertSame('Miau! 🐾', $redis->valorDe('assistente777'));
        $this->assertSame(86400, $redis->expiracaoDe('assistente777'));
    }

    public function testTextoEmForumUsaOTopicoDaMensagemNaChaveDeMemoria(): void
    {
        $update = $this->updatePrivado(777, 'assunto do tópico');
        $mensagem = $update['message'];
        $this->assertIsArray($mensagem);
        $mensagem['chat'] = ['id' => -100500, 'type' => 'supergroup'];
        $mensagem['message_thread_id'] = 42;
        $update['message'] = $mensagem;

        $telegram = $this->telegramEspiao($update);
        $redis = new Support\RedisFalsoComLua();
        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('resposta isolada'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertNotNull($redis->valorDe('memoria:v3:grupo:-100500:topico:42:usuario:777'));
        $this->assertNull($redis->valorDe('memoria:v2:grupo:-100500:topico:0'));
    }

    /**
     * "0" é a única mensagem não vazia que o PHP considera falsy — e é
     * conteúdo legítimo do usuário. Com o `empty($texto_recebido)` do comando
     * antigo o bot simplesmente não respondia: sem erro, sem log e sem cobrar
     * a cota. Hoje a guarda é `=== null || === ''` e o "0" percorre o fluxo
     * inteiro.
     */
    public function testMensagemZeroDoUsuarioEhRespondidaNormalmente(): void
    {
        $texto = '0';
        $telegram = $this->telegramEspiao($this->updatePrivado(777, $texto));
        $redis = new Support\RedisFalsoComLua();

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('zero é um número 🙂'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        // A OpenAI foi chamada com o texto exato do usuário...
        $this->assertSame(1, $transporte->chamadas);
        $mensagens = $this->mensagensDoPayload($transporte);
        $ultima = end($mensagens);
        // Sem o assertNotFalse, um payload sem mensagem nenhuma chegaria
        // ao assertSame como `false` e o diff não diria o que faltou.
        $this->assertNotFalse($ultima, 'O payload precisa ter ao menos uma mensagem.');
        $this->assertSame(['role' => 'user', 'content' => $texto], $ultima);
        // ...a cota foi consumida...
        $this->assertSame('1', $redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        // ...e o usuário recebeu resposta.
        $this->assertSame(['zero é um número 🙂'], $this->textosEnviados($telegram->chamadas));
        $this->assertSame($texto, $redis->valorDe('contexto777'));
    }

    public function testMensagemVaziaOuAusenteEncerraSemChamarAOpenAi(): void
    {
        // O outro lado da guarda: string vazia (e a ausência do campo text,
        // como num update de sticker) continuam encerrando o comando antes de
        // qualquer efeito.
        $semCampoTexto = [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Fulano'],
                'chat' => ['id' => 777, 'type' => 'private'],
            ],
        ];

        foreach ([$this->updatePrivado(777, ''), $semCampoTexto] as $update) {
            $telegram = $this->telegramEspiao($update);
            $redis = new Support\RedisFalsoComLua();
            $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

            $this->simularComandoTexto($telegram, $redis, $transporte);

            $this->assertSame(0, $transporte->chamadas);
            $this->assertSame([], $telegram->chamadas, 'Nem o "typing" pode ser enviado sem texto.');
            $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        }
    }

    /**
     * Update sem autor (post de canal, mensagem de serviço): sem UserID não
     * há a quem debitar a cota nem contexto a carregar, e o comando devolve
     * o controle sem fazer NADA — nem "typing", nem chamada paga.
     */
    public function testUpdateSemAutorNaoDisparaNenhumaChamada(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 6,
            'message' => [
                'message_id' => 15,
                // sem 'from': UserID() devolve null
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'text' => 'dealni, responde',
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertNull($telegram->UserID(), 'O update precisa mesmo estar sem autor.');
        $this->assertSame([], $telegram->chamadas, 'Nenhuma chamada ao Telegram para update sem autor.');
        $this->assertSame(0, $transporte->chamadas);
        $this->assertSame([], $redis->operacoes(), 'Nem o Redis é tocado.');
    }

    /**
     * Update com autor mas sem `message` (edição de mensagem sem texto): o
     * comando lê a mensagem de `message` e, sem texto, encerra antes de
     * qualquer efeito, em vez de quebrar acessando índice inexistente.
     */
    public function testUpdateSemMessageEncerraSemChamarAOpenAi(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 7,
            'edited_message' => [
                'message_id' => 16,
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Fulano'],
                'chat' => ['id' => 777, 'type' => 'private'],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame(777, $telegram->UserID(), 'O update precisa ter autor.');
        $this->assertSame(0, $transporte->chamadas);
        $this->assertSame([], $telegram->chamadas, 'Nem o "typing" pode ser enviado sem texto.');
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
    }

    public function testRespostaZeroDaOpenAiChegaAoUsuarioEAoContexto(): void
    {
        // Mesmo problema do outro lado do fluxo: `empty()` na leitura de
        // choices[0].message.content trocava a resposta "0" pelo erro genérico.
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'quantos gatos você tem?'));
        $redis = new Support\RedisFalsoComLua();

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('0'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame(['0'], $this->textosEnviados($telegram->chamadas));
        $this->assertSame('0', $redis->valorDe('assistente777'));
        $this->assertSame(86400, $redis->expiracaoDe('assistente777'));
    }

    public function testSegundaMensagemEnviaOContextoAnteriorNoPayload(): void
    {
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'e depois?'));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto777', 'pergunta anterior');
        $redis->semear('assistente777', 'resposta anterior');

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('continuando 🙂'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $mensagens = $this->mensagensDoPayload($transporte);
        $this->assertCount(4, $mensagens);
        $this->assertSame('developer', $mensagens[0]['role'] ?? null);
        $this->assertSame(['role' => 'user', 'content' => 'pergunta anterior'], $mensagens[1] ?? null);
        $this->assertSame(['role' => 'assistant', 'content' => 'resposta anterior'], $mensagens[2] ?? null);
        $this->assertSame(['role' => 'user', 'content' => 'e depois?'], $mensagens[3] ?? null);

        // O contexto é substituído pela rodada atual, sempre com TTL de 24h.
        $this->assertSame('e depois?', $redis->valorDe('contexto777'));
        $this->assertSame('continuando 🙂', $redis->valorDe('assistente777'));
        $this->assertSame(86400, $redis->expiracaoDe('assistente777'));
    }

    public function testRespostaMuitoLongaEEnviadaERegistradaJaRecortada(): void
    {
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'escreva muito'));
        $redis = new Support\RedisFalsoComLua();

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta(str_repeat('a', 5000)));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $esperado = str_repeat('a', 4096);
        $this->assertSame([$esperado], $this->textosEnviados($telegram->chamadas));
        $this->assertSame($esperado, $redis->valorDe('assistente777'));
    }

    // ---------------------------------------------------------------------
    // Cota e Redis
    // ---------------------------------------------------------------------

    public function testCotaEstouradaAvisaOUsuarioSemChamarAOpenAi(): void
    {
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('limite_diario:777:' . limite_diario_dia_atual(), '100');

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame(0, $transporte->chamadas, 'A OpenAI não pode ser chamada com a cota estourada.');
        $this->assertSame(
            ['Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂'],
            $this->textosEnviados($telegram->chamadas)
        );
        // Nada de contexto novo: o fluxo parou antes de tocar as chaves da conversa.
        $this->assertNull($redis->valorDe('contexto777'));
        $this->assertNull($redis->valorDe('assistente777'));
    }

    public function testRedisIndisponivelBloqueiaAChamadaPagaEAvisaOUsuario(): void
    {
        // Redis fora do ar (phpredis sem exceções): a cota falha fechada.
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'));
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivel();

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame(0, $transporte->chamadas, 'Sem cota confiável, nenhuma chamada paga.');
        $this->assertSame(
            ['Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂'],
            $this->textosEnviados($telegram->chamadas)
        );
    }

    public function testCotaDoGrupoEstouradaBloqueiaAChamadaPagaEmSilencio(): void
    {
        // Este é o teto que faltava: sem ele, um grupo grande gerava chamada
        // paga sem limite nenhum. Estourado o balde do CHAT, nenhuma chamada
        // sai — e nenhuma mensagem também: repetir "você atingiu o limite" a
        // cada menção pelo resto do dia transformaria o bot em ruído no grupo.
        $telegram = $this->telegramEspiao([
            'update_id' => 3,
            'message' => [
                'message_id' => 12,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'text' => 'dealni, tudo bem?',
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('limite_diario:-100500:' . limite_diario_dia_atual(), '100');

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame(0, $transporte->chamadas, 'A OpenAI não pode ser chamada com a cota do grupo estourada.');
        $this->assertSame([], $this->textosEnviados($telegram->chamadas));
        // E o fluxo parou antes de tocar as chaves da conversa do grupo.
        $this->assertNull($redis->valorDe('contexto-100500'));
        $this->assertNull($redis->valorDe('assistente-100500'));
    }

    /**
     * Em grupo quem paga a cota é o GRUPO, não quem mandou a mensagem: o bot
     * responde ali por menção, por resposta a ele ou pelo sorteio de 10%, e
     * nenhuma das três é escolha de quem postou. O balde do usuário fica
     * intocado, e o contexto continua sendo o do chat.
     */
    public function testGrupoConsomeACotaDoChatENaoADoUsuario(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 2,
            'message' => [
                'message_id' => 11,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'text' => 'dealni, tudo bem?',
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('tudo ótimo!'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        // A cota saiu do balde do GRUPO (id negativo, nunca colide com o de um
        // usuário) e a do autor da mensagem ficou intacta...
        $this->assertSame('1', $redis->valorDe('limite_diario:-100500:' . limite_diario_dia_atual()));
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        // ...e o contexto combina chat, tópico e participante, sem compartilhar entre membros.
        $this->assertSame('dealni, tudo bem?', $redis->valorDe('contexto:memoria:v3:grupo:-100500:topico:0:usuario:777'));
        $this->assertSame('tudo ótimo!', $redis->valorDe('assistente:memoria:v3:grupo:-100500:topico:0:usuario:777'));
        $this->assertNull($redis->valorDe('contexto777'));
        $this->assertSame(1, $transporte->chamadas);
    }

    // ---------------------------------------------------------------------
    // Falhas da OpenAI
    // ---------------------------------------------------------------------

    public function testErroDaApiAvisaOUsuarioENaoGuardaRespostaDoAssistente(): void
    {
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'));
        $redis = new Support\RedisFalsoComLua();

        $transporte = new Support\TransporteHttpEspiao('{"error":{"message":"Rate limit reached","type":"rate_limit_error"}}');

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame([self::ERRO_GENERICO], $this->textosEnviados($telegram->chamadas));
        // O contexto do usuário já havia sido gravado antes da chamada...
        $this->assertSame('Olá!', $redis->valorDe('contexto777'));
        // ...mas nenhuma resposta do assistente é registrada.
        $this->assertNull($redis->valorDe('assistente777'));
    }

    public function testTimeoutDaOpenAiAvisaOUsuarioSemEsperaReal(): void
    {
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'));
        $redis = new Support\RedisFalsoComLua();
        $inicio = microtime(true);

        // curl_exec devolve false em timeout/conexão recusada.
        $transporte = new Support\TransporteHttpEspiao(false);

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertLessThan(1.0, microtime(true) - $inicio, 'Nenhuma espera real pode acontecer no teste.');
        $this->assertSame(1, $transporte->chamadas);
        $this->assertSame([self::ERRO_GENERICO], $this->textosEnviados($telegram->chamadas));
        $this->assertNull($redis->valorDe('assistente777'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function respostasQuebradasProvider(): array
    {
        return [
            'corpo html de gateway' => ['<html><body>502 Bad Gateway</body></html>'],
            'json truncado' => ['{"choices":[{"message":'],
            'sem choices' => ['{"id":"chatcmpl-1","object":"chat.completion"}'],
            'conteudo vazio' => ['{"choices":[{"message":{"content":""}}]}'],
        ];
    }

    #[DataProvider('respostasQuebradasProvider')]
    public function testRespostaMalformadaAvisaOUsuarioENaoGuardaContextoDoAssistente(string $corpo): void
    {
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'));
        $redis = new Support\RedisFalsoComLua();

        $transporte = new Support\TransporteHttpEspiao($corpo);

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame([self::ERRO_GENERICO], $this->textosEnviados($telegram->chamadas));
        $this->assertNull($redis->valorDe('assistente777'));
    }

    public function testFalhaDoMarkdownReenviaAMesmaRespostaSemFormatacao(): void
    {
        // A API do Telegram recusa a formatação Markdown (ok = false).
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'), false);
        $redis = new Support\RedisFalsoComLua();

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('texto com *destaque*'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        // Dois envios com o MESMO texto: o segundo sem parse_mode.
        $this->assertSame(
            ['texto com *destaque*', 'texto com *destaque*'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame('Markdown', $telegram->chamadas[1]['content']['parse_mode'] ?? null);
        $this->assertArrayNotHasKey('parse_mode', $telegram->chamadas[2]['content']);
        // Mesmo com a falha de formatação, a resposta entra no contexto.
        $this->assertSame('texto com *destaque*', $redis->valorDe('assistente777'));
    }

    public function testEntregaSemPermissaoNaoRepeteNemSalvaResposta(): void
    {
        // Uma recusa de permissão não é uma falha de formatação.
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'), false, recusarEnvios: true);
        $redis = new Support\RedisFalsoComLua();

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('texto com *destaque*'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        // Uma tentativa apenas, mantendo Markdown.
        $this->assertSame(
            ['texto com *destaque*'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame('Markdown', $telegram->chamadas[1]['content']['parse_mode'] ?? null);
        // Uma resposta não entregue não entra no contexto.
        $this->assertNull($redis->valorDe('assistente777'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function falhasQueNaoRendemRespostaProvider(): array
    {
        return [
            'timeout do transporte' => [''],
            'erro devolvido pela API' => ['{"error":{"message":"Rate limit reached"}}'],
            'corpo sem conteúdo' => ['{"choices":[]}'],
        ];
    }

    /**
     * A vaga da cota é consumida ANTES da chamada e nunca é devolvida: uma
     * indisponibilidade da OpenAI queima requisições do usuário sem que ele
     * receba resposta nenhuma. Comportamento atual — o reembolso exigiria
     * decidir o que fazer quando a resposta chega depois do erro.
     */
    #[DataProvider('falhasQueNaoRendemRespostaProvider')]
    public function testCotaNaoEhDevolvidaQuandoAOpenAiFalha(string $corpo): void
    {
        $redis = new Support\RedisFalsoComLua();
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();

        // Primeira mensagem: sucesso, consome 1.
        $this->simularComandoTexto(
            $this->telegramEspiao($this->updatePrivado(777, 'Olá!')),
            $redis,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('Miau!'))
        );
        $this->assertSame('1', $redis->valorDe($chaveCota));

        // Segunda mensagem: a OpenAI falha, mas a cota já foi consumida.
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'e agora?'));
        $transporte = new Support\TransporteHttpEspiao($corpo === '' ? false : $corpo);

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame('2', $redis->valorDe($chaveCota), 'A vaga consumida não é devolvida.');
        $this->assertCount(1, $this->textosEnviados($telegram->chamadas));
        $this->assertNotSame('', $this->textosEnviados($telegram->chamadas)[0], 'O usuário precisa ser avisado.');
        // A resposta anterior do assistente continua sendo a da rodada que deu certo.
        $this->assertSame('Miau!', $redis->valorDe('assistente777'));
        // Mas o contexto do usuário JÁ foi sobrescrito pela pergunta sem resposta.
        $this->assertSame('e agora?', $redis->valorDe('contexto777'));
    }

    public function testCorpoImpossivelDeCodificarAvisaOUsuarioSemChamarAOpenAi(): void
    {
        // Contexto gravado no Redis com bytes que não são UTF-8 válido (chave
        // reescrita por outro processo, valor corrompido): json_encode devolve
        // false e o comando precisa avisar em vez de mandar corpo vazio à API.
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto777', "\xB1\x31");

        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame(0, $transporte->chamadas, 'Nenhuma chamada paga com corpo inválido.');
        $this->assertSame([self::ERRO_GENERICO], $this->textosEnviados($telegram->chamadas));
        $this->assertNull($redis->valorDe('assistente777'));
        // A cota já tinha sido consumida antes de a montagem falhar...
        $this->assertSame('1', $redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        // ...e o contexto corrompido já tinha sido sobrescrito pela mensagem
        // atual, então a rodada seguinte volta a funcionar.
        $this->assertSame('Olá!', $redis->valorDe('contexto777'));
    }

    public function testMensagemAcimaDoLimiteDoTelegramVaiCortadaParaAOpenAiEParaOContexto(): void
    {
        // O Telegram entrega no máximo 4096 caracteres por mensagem, mas o
        // comando corta de novo antes de montar o payload: o corte é o mesmo
        // que vai para a OpenAI e para o contexto guardado no Redis.
        $texto = str_repeat('a', 4000) . ' ' . str_repeat('b', 1000);
        $esperado = str_repeat('a', 4000);

        $telegram = $this->telegramEspiao($this->updatePrivado(777, $texto));
        $redis = new Support\RedisFalsoComLua();
        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('entendi'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $mensagens = $this->mensagensDoPayload($transporte);
        $ultima = end($mensagens);
        // Sem o assertNotFalse, um payload sem mensagem nenhuma chegaria
        // ao assertSame como `false` e o diff não diria o que faltou.
        $this->assertNotFalse($ultima, 'O payload precisa ter ao menos uma mensagem.');
        $this->assertSame(['role' => 'user', 'content' => $esperado], $ultima);
        $this->assertSame($esperado, $redis->valorDe('contexto777'));
        // A próxima rodada reenvia esse contexto — que já cabe no limite.
        $this->assertSame(4000, mb_strlen($esperado, 'UTF-8'));
    }

    /**
     * A guarda de conteúdo era `$resposta_chatgpt === ''`, e uma resposta só com
     * espaços/quebras de linha passava por ela como válida: o comando mandava ao
     * Telegram um texto em branco (recusado com "message text is empty" nas duas
     * tentativas, com e sem Markdown — o usuário ficava sem resposta) e ainda
     * gravava o branco como contexto do assistente para a rodada seguinte. Agora
     * a guarda é `trim(...) === ''`, nas três cópias (chatgpt, ver_audio e
     * ver_imagem), e o caso cai no mesmo aviso de falha das outras.
     */
    public function testRespostaSoComEspacosViraFalhaEnaoContamina0Contexto(): void
    {
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'), false);
        $redis = new Support\RedisFalsoComLua();
        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta("  \n\t "));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        // O usuário recebe o aviso de falha, e não uma mensagem em branco.
        $this->assertSame(
            ['Erro ao gerar resposta. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        // E o branco não vira contexto da rodada seguinte.
        $this->assertNull($redis->valorDe('assistente777'));
    }

    public function testRespostaZeroContinuaSendoRespostaValida(): void
    {
        // O trim() não pode engolir o "0": é resposta legítima do modelo e
        // empty()/falsy o descartaria como se fosse vazio.
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Quanto é 1-1?'), false);
        $redis = new Support\RedisFalsoComLua();
        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('0'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        // Dois envios porque o dublê responde ok=false e o fluxo reenvia sem
        // Markdown — o que importa aqui é que o texto é o "0", e não o aviso de
        // falha.
        $this->assertSame(['0', '0'], $this->textosEnviados($telegram->chamadas));
        $this->assertSame('0', $redis->valorDe('assistente777'));
    }

    public function testMensagemDoUsuarioSoComEspacosPercorreOFluxoInteiro(): void
    {
        // O outro lado: " " não é '' nem null, então a guarda do texto recebido
        // deixa passar e a requisição paga acontece.
        $telegram = $this->telegramEspiao($this->updatePrivado(777, '   '));
        $redis = new Support\RedisFalsoComLua();
        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('oi?'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame(1, $transporte->chamadas);
        $this->assertSame('1', $redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        $mensagens = $this->mensagensDoPayload($transporte);
        $ultima = end($mensagens);
        // Sem o assertNotFalse, um payload sem mensagem nenhuma chegaria
        // ao assertSame como `false` e o diff não diria o que faltou.
        $this->assertNotFalse($ultima, 'O payload precisa ter ao menos uma mensagem.');
        $this->assertSame(['role' => 'user', 'content' => '   '], $ultima);
    }

    public function testRespostaComMarkdownDoUsuarioNaoEhEscapadaPeloComando(): void
    {
        // escapeMarkdown() existe em comandos/chatgpt.php mas o fluxo nunca a
        // chama: o texto do modelo permanece integral. Um marcador aberto
        // deve seguir como texto puro já na primeira tentativa.
        $telegram = $this->telegramEspiao($this->updatePrivado(777, 'Olá!'));
        $redis = new Support\RedisFalsoComLua();
        $transporte = new Support\TransporteHttpEspiao($this->corpoDeResposta('use o arquivo teste_1.php'));

        $this->simularComandoTexto($telegram, $redis, $transporte);

        $this->assertSame(
            ['use o arquivo teste_1.php'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertArrayNotHasKey('parse_mode', $telegram->chamadas[1]['content']);

        // E o comando de fato não usa o helper de escape no fluxo: a declaração
        // mora em comandos/funcoes_ia_comuns.php e o comando não a chama uma
        // vez sequer — código morto documentado, não removido.
        $comando = file_get_contents(self::CAMINHO_SCRIPT);
        $this->assertIsString($comando);
        $this->assertSame(
            0,
            substr_count($comando, 'escapeMarkdown'),
            'comandos/chatgpt.php passou a chamar escapeMarkdown() no fluxo.'
        );
        $this->assertSame(
            1,
            substr_count(Support\FonteDoComando::ler(self::CAMINHO_SCRIPT), 'escapeMarkdown'),
            'escapeMarkdown() continua declarada uma única vez e nunca chamada.'
        );
    }

    // ---------------------------------------------------------------------
    // Fidelidade do espelho
    // ---------------------------------------------------------------------
}
