<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes de FLUXO do comando de áudio (comandos/ver_audio.php) da entrada do
 * update até a resposta enviada ao Telegram, amarrando as peças reais:
 *   - parsing do update pela classe Telegram (setData, sem rede);
 *   - cota diária de inclusos/limites_requisicoes.php com o Lua EXECUTADO
 *     pelo dublê Support\RedisFalsoComLua;
 *   - download do áudio para arquivo temporário e sua REMOÇÃO em todos os
 *     caminhos, inclusive nos de erro;
 *   - transcrição no Whisper e, depois, payload/chamada/resposta do
 *     chat/completions, com os transportes HTTP injetados (nenhuma rede,
 *     nenhuma espera real).
 *
 * O script continua NÃO podendo ser incluído: o topo termina em exit e, sem
 * transporte injetado, faria download e cURL de verdade. A ordem do topo é
 * ESPELHADA em simularComandoAudio() usando as FUNÇÕES DE PRODUÇÃO extraídas do
 * próprio arquivo, e a fidelidade do espelho é travada na fonte por
 * testEspelhoDoFluxoContinuaFielAFonte().
 */
final class FluxoIaAudioTest extends TestCase
{
    public function testRecusaDeEscritaNaoDisparaChamadaPaga(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777), recusarAcao: true);
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('resposta'));
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('Oi'));
        $this->simularComandoAudio($telegram, $redis, static fn (string $url): string => 'audio-falso', $whisper, $chat);
        self::assertSame(0, $whisper->chamadas);
        self::assertSame([], $this->arquivosTemporarios());
        self::assertSame(0, $chat->chamadas);
        self::assertNotContains('sendMessage', array_column($telegram->chamadas, 'api'));
    }

    private const CAMINHO_SCRIPT = __DIR__ . '/../../comandos/ver_audio.php';

    /** Token fake: o script usa a constante TOKEN_BOT (inclusos/config.php). */
    private const TOKEN_FAKE = '123456:FAKE_TOKEN';

    /** Chave fake: o script usa a constante OPENROUTER_API_KEY (inclusos/config.php). */
    private const CHAVE_API = 'sk-teste-fake';

    /** Relógio fixo injetado no prompt para o payload ser determinístico. */
    private const DATA_HORA = '01/02/2026 03:04:05';

    private const ERRO_GENERICO = "Erro ao gerar resposta. Tente novamente! ❌";

    private const ERRO_TRANSCRICAO = "Erro ao transcrever o áudio. Tente novamente! ❌";

    private const CAMINHO_NO_TELEGRAM = 'voice/file_1.oga';

    /** @var string Diretório temporário exclusivo do teste, dentro de sys_get_temp_dir(). */
    private string $diretorioTemporario = '';

    public static function setUpBeforeClass(): void
    {
        // O comando lê chave e token das constantes, como em produção — o
        // espelho antigo passava literais no lugar delas. Os valores aqui são
        // os mesmos que os dublês usam, senão a URL de download montada pelo
        // comando não bateria com a que o baixador injetado espera.
        if (!defined('OPENROUTER_API_KEY')) {
            define('OPENROUTER_API_KEY', self::CHAVE_API);
        }
        if (!defined('TOKEN_BOT')) {
            define('TOKEN_BOT', self::TOKEN_FAKE);
        }

        Support\ExtratorDeFuncoesDeScript::carregar(self::CAMINHO_SCRIPT, [
            'cortarMensagem',
            'lerTextoDoRedis',
            'avisarUsuarioAudio',
            'audioDaMensagem',
            'duracaoDoAudio',
            'limiteDeSegundosDoAudio',
            'removerAudioTemporario',
            'baixarAudioParaArquivoTemporario',
            'chamarTranscricaoWhisper',
            'interpretarTranscricaoWhisper',
            'montarMensagensAudioComHistorico',
            'montarCorpoRequisicaoAudio',
            'chamarChatCompletionsAudio',
            'interpretarRespostaAudio',
        ]);
    }

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../inclusos/limites_requisicoes.php';

        $diretorio = sys_get_temp_dir() . '/dealnibot_audio_' . uniqid();
        $this->assertTrue(mkdir($diretorio), 'O diretório temporário do teste precisa ser criado.');
        $this->diretorioTemporario = $diretorio;
    }

    protected function tearDown(): void
    {
        if ($this->diretorioTemporario === '' || !is_dir($this->diretorioTemporario)) {
            return;
        }
        foreach ($this->arquivosTemporarios() as $arquivo) {
            @unlink($this->diretorioTemporario . '/' . $arquivo);
        }
        @rmdir($this->diretorioTemporario);
    }

    // ---------------------------------------------------------------------
    // Espelho do topo de comandos/ver_audio.php
    // ---------------------------------------------------------------------

    /**
     * Chama o comando de produção.
     *
     * Até 2026-08-24 este método era um ESPELHO escrito à mão do corpo de
     * comandos/ver_audio.php, porque script de topo não podia ser incluído — duas cópias do
     * mesmo fluxo, e só um teste de texto conferindo que a fonte ainda continha
     * as linhas espelhadas. Com o corpo extraído para executarComandoVerAudio(), no
     * funcoes_*.php irmão, a fidelidade deixa de depender de manter as duas em
     * dia: o teste roda o código de produção, e a cobertura é atribuída a ele.
     *
     * @param callable $baixador fn(string $url): mixed
     * @param callable $transporteWhisper fn(string $url, array<string, mixed> $campos, array<int, string> $cabecalhos): mixed
     * @param callable $transporteChat fn(string $url, string $corpo, array<int, string> $cabecalhos): mixed
     */
    private function simularComandoAudio(
        Telegram $telegram,
        Support\RedisFalsoComLua $redis,
        callable $baixador,
        callable $transporteWhisper,
        callable $transporteChat
    ): void {
        executarComandoVerAudio(
            $telegram,
            $redis,
            $transporteWhisper,
            $transporteChat,
            $baixador,
            self::DATA_HORA,
            $this->diretorioTemporario
        );
    }

    // ---------------------------------------------------------------------
    // Dublês e utilitários
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $update
     * @param bool $respostaOk Resultado devolvido pela API do Telegram nos envios
     * @param bool $arquivoDisponivel false faz o getFile responder sem file_path
     * @param array<string, mixed>|null $resultadoDoGetFile Substitui o result do
     *        getFile; nulo usa o arquivo com file_path do caminho feliz
     * @return Telegram&object{chamadas: array<int, array{api: string, content: array<string, mixed>}>, respostaOk: bool, recusarEnvios: bool}
     */
    private function telegramEspiao(
        array $update,
        bool $respostaOk = true,
        bool $arquivoDisponivel = true,
        ?array $resultadoDoGetFile = null,
        bool $recusarEnvios = false,
        bool $recusarAcao = false
    ) {
        $duble = new class (self::TOKEN_FAKE, false) extends Telegram {
            /** @var array<int, array{api: string, content: array<string, mixed>}> */
            public array $chamadas = [];

            /** @var bool Resultado que a API do Telegram devolve em cada envio. */
            public bool $respostaOk = true;
            public bool $recusarEnvios = false;
            public bool $recusarAcao = false;

            /** @var array<string, mixed>|null Resultado do getFile; null simula arquivo indisponível. */
            public ?array $arquivoDoTelegram = ['file_path' => 'voice/file_1.oga'];

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

                if ($api === 'getFile') {
                    return $this->arquivoDoTelegram === null
                        ? ['ok' => false, 'description' => 'file not found']
                        : ['ok' => true, 'result' => $this->arquivoDoTelegram];
                }

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
        $duble->arquivoDoTelegram = $arquivoDisponivel
            ? ($resultadoDoGetFile ?? ['file_path' => self::CAMINHO_NO_TELEGRAM])
            : null;
        $duble->setData($update);

        return $duble;
    }

    /**
     * Update de mensagem de voz em chat privado.
     *
     * @return array<string, mixed>
     */
    private function updateDeVoz(int $idUsuario): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => $idUsuario, 'is_bot' => false, 'first_name' => 'Fulano'],
                'chat' => ['id' => $idUsuario, 'type' => 'private'],
                'voice' => ['file_id' => 'voice-abc', 'duration' => 4, 'mime_type' => 'audio/ogg'],
            ],
        ];
    }

    /** Download que devolve bytes de áudio e registra a URL pedida. */
    private function baixadorFalso(string $conteudo = 'bytes-do-audio'): callable
    {
        return static function (string $url) use ($conteudo): string {
            return $conteudo;
        };
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
     * Arquivos deixados no diretório temporário do teste.
     *
     * @return array<int, string>
     */
    private function arquivosTemporarios(): array
    {
        $encontrados = scandir($this->diretorioTemporario);
        if (!is_array($encontrados)) {
            return [];
        }

        return array_values(array_diff($encontrados, ['.', '..']));
    }

    /**
     * Mensagens do payload efetivamente enviado ao chat/completions.
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

    private function corpoDeTranscricao(string $texto): string
    {
        $corpo = json_encode(['text' => $texto]);
        $this->assertIsString($corpo);

        return $corpo;
    }

    // ---------------------------------------------------------------------
    // Fluxo feliz
    // ---------------------------------------------------------------------

    public function testFluxoDeAudioTranscreveResponderEApagaOTemporario(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();

        $urlBaixada = null;
        $baixador = static function (string $url) use (&$urlBaixada): string {
            $urlBaixada = $url;

            return 'bytes-do-audio';
        };
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('Oi, gato!'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('Miau! 🐾'));

        $this->simularComandoAudio($telegram, $redis, $baixador, $whisper, $chat);

        // 1. Áudio reserva duas unidades: transcrição + chat/completions.
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();
        $this->assertSame('2', $redis->valorDe($chaveCota));
        $this->assertSame(86400, $redis->expiracaoDe($chaveCota));

        // 2. Áudio baixado da URL montada com o file_path devolvido pelo getFile.
        $this->assertSame(
            'https://api.telegram.org/file/bot' . self::TOKEN_FAKE . '/' . self::CAMINHO_NO_TELEGRAM,
            $urlBaixada
        );

        // 3. Upload ao Whisper com o arquivo temporário gravado no disco.
        $this->assertSame(1, $whisper->chamadas);
        $this->assertSame('https://openrouter.ai/api/v1/audio/transcriptions', $whisper->url);
        $this->assertSame(['Authorization: Bearer ' . self::CHAVE_API], $whisper->cabecalhos);
        $enviado = $whisper->arquivoEnviado();
        $this->assertIsString($enviado);
        $this->assertSame(realpath($this->diretorioTemporario), realpath(dirname($enviado)));
        $this->assertStringStartsWith('dealnibot_audio_', basename($enviado));

        // 4. O temporário NÃO fica no disco depois do fluxo.
        $this->assertSame([], $this->arquivosTemporarios());

        // 5. Requisição ao chat/completions com a transcrição como mensagem atual.
        $this->assertSame(1, $chat->chamadas);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $chat->url);
        $this->assertSame(
            ['Content-Type: application/json', 'Authorization: Bearer ' . self::CHAVE_API],
            $chat->cabecalhos
        );
        $mensagens = $this->mensagensDoPayload($chat);
        $this->assertCount(2, $mensagens);
        $this->assertSame('developer', $mensagens[0]['role'] ?? null);
        $desenvolvedor = $mensagens[0]['content'] ?? null;
        $this->assertIsString($desenvolvedor);
        $this->assertStringContainsString('O usuário enviou um ÁUDIO', $desenvolvedor);
        $this->assertSame(['role' => 'user', 'content' => 'Oi, gato!'], $mensagens[1] ?? null);

        // 6. Telegram: "typing", getFile e a resposta em Markdown.
        $this->assertSame(
            ['getFile', 'sendChatAction', 'sendMessage'],
            $this->apisChamadas($telegram->chamadas)
        );
        $envio = $telegram->chamadas[2]['content'];
        $this->assertSame(777, $envio['chat_id'] ?? null);
        $this->assertSame('Miau! 🐾', $envio['text'] ?? null);
        $this->assertSame('Markdown', $envio['parse_mode'] ?? null);
        $this->assertSame(
            '{"message_id":10,"allow_sending_without_reply":true}',
            $envio['reply_parameters'] ?? null
        );

        // 7. Contexto (a transcrição) e resposta guardados por 24h.
        $this->assertSame('Oi, gato!', $redis->valorDe('contexto777'));
        $this->assertSame(86400, $redis->expiracaoDe('contexto777'));
        $this->assertSame('Miau! 🐾', $redis->valorDe('assistente777'));
        $this->assertSame(86400, $redis->expiracaoDe('assistente777'));
    }

    public function testSegundoAudioEnviaOContextoAnteriorNoPayload(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto777', 'pergunta anterior');
        $redis->semear('assistente777', 'resposta anterior');

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('continuando 🙂'));

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('e depois?')),
            $chat
        );

        $mensagens = $this->mensagensDoPayload($chat);
        $this->assertCount(4, $mensagens);
        $this->assertSame(['role' => 'user', 'content' => 'pergunta anterior'], $mensagens[1] ?? null);
        $this->assertSame(['role' => 'assistant', 'content' => 'resposta anterior'], $mensagens[2] ?? null);
        $this->assertSame(['role' => 'user', 'content' => 'e depois?'], $mensagens[3] ?? null);

        $this->assertSame('e depois?', $redis->valorDe('contexto777'));
        $this->assertSame('continuando 🙂', $redis->valorDe('assistente777'));
    }

    /**
     * "0" é falsy em PHP e atravessa TRÊS pontos deste fluxo: a transcrição
     * devolvida pelo Whisper, o contexto lido do Redis e o conteúdo devolvido
     * pela OpenAI. Um único empty() em qualquer um deles some com a mensagem do
     * usuário — o bug que o commit 341c19d corrigiu. Aqui o "0" faz a volta
     * completa: entra como transcrição, é guardado, volta como contexto e sai
     * como resposta enviada ao Telegram.
     */
    public function testConteudoIgualAZeroAtravessaOFluxoInteiroDoAudio(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto777', '0');
        $redis->semear('assistente777', '0');

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('0'));

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('0')),
            $chat
        );

        // O contexto "0" guardado antes não é descartado do payload...
        $mensagens = $this->mensagensDoPayload($chat);
        $this->assertCount(4, $mensagens);
        $this->assertSame(['role' => 'user', 'content' => '0'], $mensagens[1] ?? null);
        $this->assertSame(['role' => 'assistant', 'content' => '0'], $mensagens[2] ?? null);
        // ...e a transcrição "0" chega como mensagem atual.
        $this->assertSame(['role' => 'user', 'content' => '0'], $mensagens[3] ?? null);

        // A resposta "0" da OpenAI é enviada ao usuário, não trocada por erro.
        $this->assertSame(['0'], $this->textosEnviados($telegram->chamadas));
        $this->assertSame('0', $redis->valorDe('contexto777'));
        $this->assertSame('0', $redis->valorDe('assistente777'));
    }

    public function testArquivoDeAudioEnviadoComoDocumentoTambemEAtendido(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 3,
            'message' => [
                'message_id' => 12,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => 777, 'type' => 'private'],
                'audio' => ['file_id' => 'audio-xyz', 'duration' => 30, 'mime_type' => 'audio/mpeg', 'file_name' => 'musica.mp3'],
            ],
        ], true, true, ['file_path' => 'audio/file_3.mp3']);
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('música'));

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            $whisper,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('que legal!'))
        );

        $this->assertSame('audio-xyz', $telegram->chamadas[0]['content']['file_id'] ?? null);
        $arquivo = $whisper->campos['file'] ?? null;
        $this->assertInstanceOf(\CURLFile::class, $arquivo);
        $this->assertSame('audio/mpeg', $arquivo->getMimeType());
        $this->assertSame('audio.mp3', $arquivo->getPostFilename());
        $this->assertSame(['que legal!'], $this->textosEnviados($telegram->chamadas));
        $this->assertSame([], $this->arquivosTemporarios());
    }

    public function testGrupoNaoConsomeCotaEUsaOIdDoChatNoContexto(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 2,
            'message' => [
                'message_id' => 11,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'voice' => ['file_id' => 'voice-abc', 'duration' => 4],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('oi pessoal')),
            new Support\TransporteHttpEspiao($this->corpoDeResposta('olá!'))
        );

        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        $this->assertSame('oi pessoal', $redis->valorDe('contexto:memoria:v3:grupo:-100500:topico:0:usuario:777'));
        $this->assertSame('olá!', $redis->valorDe('assistente:memoria:v3:grupo:-100500:topico:0:usuario:777'));
        $this->assertNull($redis->valorDe('contexto777'));
    }

    // ---------------------------------------------------------------------
    // Entrada, cota e Redis
    // ---------------------------------------------------------------------

    public function testMensagemSemAudioPedeOAudioSemChamarNinguem(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 4,
            'message' => [
                'message_id' => 13,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => 777, 'type' => 'private'],
                'text' => 'transcreve aí',
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio($telegram, $redis, $this->baixadorFalso(), $whisper, $chat);

        $this->assertSame(
            ['Por favor, envie um áudio para que eu possa transcrevê-lo! 🎤'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
        $this->assertSame(0, $whisper->chamadas);
        $this->assertSame(0, $chat->chamadas);
        // Nem cota nem arquivo temporário: o fluxo parou na primeira decisão.
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        $this->assertSame([], $this->arquivosTemporarios());
    }

    public function testAudioComMaisDeTresMinutosEhRecusadoAntesDeQualquerChamada(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 5,
            'message' => [
                'message_id' => 14,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => 777, 'type' => 'private'],
                'voice' => ['file_id' => 'voice-longo', 'duration' => 181],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio($telegram, $redis, $this->baixadorFalso(), $whisper, $chat);

        $this->assertSame(
            ['Esse áudio é longo demais. Envie áudios de até 3 minutos. 🎤'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
        $this->assertSame(0, $whisper->chamadas);
        $this->assertSame(0, $chat->chamadas);
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        $this->assertSame([], $this->arquivosTemporarios());
    }

    /**
     * Update sem autor (post de canal encaminhado com áudio, mensagem de
     * serviço): sem UserID não há a quem debitar a cota nem contexto a
     * carregar, e o comando devolve o controle sem fazer NADA — nem
     * sendChatAction, nem getFile, nem chamada paga.
     *
     * O ramo existe no topo do comando desde sempre e nenhum teste o
     * exercitava: sem ele o fluxo seguiria com 'contexto' concatenado a null e
     * gastaria as duas chamadas pagas por um update que ninguém pediu.
     */
    public function testUpdateSemAutorNaoDisparaNenhumaChamada(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 6,
            'message' => [
                'message_id' => 15,
                // sem 'from': UserID() devolve null
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'voice' => ['file_id' => 'voice-abc', 'duration' => 4],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $baixado = false;
        $this->simularComandoAudio(
            $telegram,
            $redis,
            static function () use (&$baixado): string {
                $baixado = true;

                return 'bytes';
            },
            $whisper,
            $chat
        );

        $this->assertNull($telegram->UserID(), 'O update precisa mesmo estar sem autor.');
        $this->assertSame([], $telegram->chamadas, 'Nenhuma chamada ao Telegram para update sem autor.');
        $this->assertFalse($baixado);
        $this->assertSame(0, $whisper->chamadas);
        $this->assertSame(0, $chat->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
        $this->assertSame([], $redis->operacoes(), 'Nem o Redis é tocado.');
    }

    /**
     * Update com autor mas sem `message` (edição de uma mensagem de voz): o
     * comando lê o áudio de `message`, e sem ele pede o áudio em vez de
     * quebrar acessando índice inexistente — nem cota nem download.
     */
    public function testUpdateSemMessagePedeOAudioSemBaixarNemConsumirCota(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 7,
            'edited_message' => [
                'message_id' => 16,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => 777, 'type' => 'private'],
                'voice' => ['file_id' => 'voice-abc', 'duration' => 4],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio($telegram, $redis, $this->baixadorFalso(), $whisper, $chat);

        $this->assertSame(777, $telegram->UserID(), 'O update precisa ter autor.');
        $this->assertSame(
            ['Por favor, envie um áudio para que eu possa transcrevê-lo! 🎤'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
        $this->assertSame(0, $whisper->chamadas);
        $this->assertSame(0, $chat->chamadas);
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        $this->assertSame([], $this->arquivosTemporarios());
    }

    /**
     * Voz sem `file_id`: há áudio, mas nada para pedir ao getFile. O aviso é
     * o de erro (não o pedido de áudio), e nada é baixado nem cobrado.
     */
    public function testVozSemFileIdAvisaOErroSemBaixarNemConsumirCota(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 8,
            'message' => [
                'message_id' => 17,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => 777, 'type' => 'private'],
                'voice' => ['duration' => 4, 'mime_type' => 'audio/ogg'],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio($telegram, $redis, $this->baixadorFalso(), $whisper, $chat);

        $this->assertSame(
            ['Erro ao obter informações do áudio. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
        $this->assertSame(0, $whisper->chamadas);
        $this->assertSame(0, $chat->chamadas);
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        $this->assertSame([], $this->arquivosTemporarios());
    }

    /**
     * Contexto legado com bytes que não são UTF-8: o json_encode do corpo do
     * chat/completions falha, e o comando avisa o erro genérico em vez de
     * mandar `false` como corpo — a transcrição já foi paga, a segunda
     * chamada não é feita.
     */
    public function testContextoLegadoForaDoUtf8NaoChegaAoChatCompletions(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto777', "pergunta antiga com byte inv\xB1lido");
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('e agora?'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio($telegram, $redis, $this->baixadorFalso(), $whisper, $chat);

        $this->assertSame(1, $whisper->chamadas, 'A transcrição acontece antes de o contexto ser montado.');
        $this->assertSame(0, $chat->chamadas, 'Corpo que não serializa não pode virar chamada paga.');
        $this->assertContains(self::ERRO_GENERICO, $this->textosEnviados($telegram->chamadas));
        $this->assertSame([], $this->arquivosTemporarios());
    }

    public function testCotaEstouradaAvisaOUsuarioSemBaixarNemChamarAOpenAi(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('limite_diario:777:' . limite_diario_dia_atual(), '100');
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $downloads = 0;
        $this->simularComandoAudio($telegram, $redis, static function () use (&$downloads): string {
            $downloads++;
            return 'audio';
        }, $whisper, $chat);

        $this->assertSame(0, $downloads);
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));

        $this->assertSame(
            ['Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $whisper->chamadas, 'A OpenAI não pode ser chamada com a cota estourada.');
        $this->assertSame(0, $chat->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
        $this->assertNull($redis->valorDe('contexto777'));
    }

    /**
     * Borda exata da cota diária: com 98 chamadas pagas consumidas, o áudio
     * ainda reserva as duas restantes e leva o contador a 100.
     */
    public function testAudioComDuasVagasRestantesAindaEAtendido(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();
        $redis->semear($chaveCota, '98');
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('ainda dentro da cota'));

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('última do dia')),
            $chat
        );

        $this->assertSame(1, $chat->chamadas, 'As duas chamadas do áudio cabem exatamente na cota.');
        $this->assertSame(['ainda dentro da cota'], $this->textosEnviados($telegram->chamadas));
        $this->assertSame('100', $redis->valorDe($chaveCota));
        // A chave já existia: o TTL não é reaplicado (o INCR só expira na criação).
        $this->assertNull($redis->expiracaoDe($chaveCota));
    }

    public function testAdmissaoDeGrupoRecusaVozEAudioAntesDeQualquerArquivo(): void
    {
        foreach (['voice', 'audio'] as $tipo) {
            foreach ([
                ['limite_diario:-100999:' . limite_diario_dia_atual(), '99'],
                ['dealnibot:midia:v1:sujeito:-100999', '5'],
                ['dealnibot:midia:v1:global', '20'],
            ] as [$chave, $valor]) {
                $update = $this->updateDeVoz(777);
                $mensagem = $update['message'];
                $this->assertIsArray($mensagem);
                $mensagem['chat'] = ['id' => -100999, 'type' => 'supergroup'];
                unset($mensagem['voice']);
                $mensagem[$tipo] = ['file_id' => 'midia'];
                $update['message'] = $mensagem;
                $telegram = $this->telegramEspiao($update);
                $redis = new Support\RedisFalsoComLua();
                $redis->semear($chave, $valor);
                $whisper = new Support\TransporteWhisperEspiao(false);
                $chat = new Support\TransporteHttpEspiao(false);
                $downloads = 0;
                $this->simularComandoAudio($telegram, $redis, static function () use (&$downloads): string {
                    $downloads++;
                    return 'audio';
                }, $whisper, $chat);
                $this->assertSame([], $telegram->chamadas);
                $this->assertSame(0, $downloads);
                $this->assertSame(0, $whisper->chamadas);
                $this->assertSame(0, $chat->chamadas);
                $this->assertSame([], $this->arquivosTemporarios());
            }
        }
    }

    public function testRedisIndisponivelBloqueiaAChamadaPagaEAvisaOUsuario(): void
    {
        // Redis fora do ar (phpredis sem exceções): a cota falha fechada.
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivel();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio($telegram, $redis, $this->baixadorFalso(), $whisper, $chat);

        $this->assertSame(
            ['Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $whisper->chamadas, 'Sem cota confiável, nenhuma chamada paga.');
        $this->assertSame(0, $chat->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
    }

    public function testReservaFinalRecusaCotaConcorrenteOuExcecaoELimpaAudio(): void
    {
        foreach (['private', 'supergroup'] as $tipo) {
            foreach ([false, true] as $excecao) {
                $update = $this->updateDeVoz(777);
                $mensagem = $update['message'];
                $this->assertIsArray($mensagem);
                $id = $tipo === 'private' ? 777 : -100999;
                $mensagem['chat'] = ['id' => $id, 'type' => $tipo];
                $update['message'] = $mensagem;
                $telegram = $this->telegramEspiao($update);
                $redis = new Support\RedisFalsoComLua();
                $whisper = new Support\TransporteWhisperEspiao(false);
                $chat = new Support\TransporteHttpEspiao(false);
                $downloads = 0;
                try {
                    $this->simularComandoAudio($telegram, $redis, static function () use ($redis, $id, $excecao, &$downloads): string {
                        $downloads++;
                        if ($excecao) {
                            $redis->ficarIndisponivelComExcecao();
                        } else {
                            $redis->semear('limite_diario:' . $id . ':' . limite_diario_dia_atual(), '100');
                        }
                        return 'audio';
                    }, $whisper, $chat);
                    $this->assertFalse($excecao, 'Exceção na reserva final deve propagar.');
                } catch (\RedisException $erro) {
                    $this->assertTrue($excecao);
                    $this->assertSame('Connection refused', $erro->getMessage());
                }
                $this->assertSame(1, $downloads);
                $this->assertSame(0, $whisper->chamadas);
                $this->assertSame(0, $chat->chamadas);
                $this->assertSame([], $this->arquivosTemporarios());
                if ($tipo === 'private' && !$excecao) {
                    $this->assertSame(
                        ['Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂'],
                        $this->textosEnviados($telegram->chamadas)
                    );
                } else {
                    $this->assertSame([], $this->textosEnviados($telegram->chamadas));
                }
            }
        }
    }

    public function testRajadaNoPrivadoAvisaSemBaixarAudio(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('dealnibot:midia:v1:sujeito:777', '5');
        $this->simularComandoAudio(
            $telegram,
            $redis,
            static function (): never {
                throw new \LogicException('Não deve baixar após recusar a rajada.');
            },
            new Support\TransporteWhisperEspiao(false),
            new Support\TransporteHttpEspiao(false)
        );
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
        $this->assertSame(
            ['Muitas mídias em pouco tempo. Tente novamente em um minuto. 🙂'],
            $this->textosEnviados($telegram->chamadas)
        );
    }

    public function testRedisQueLancaExcecaoNaCotaNaoDeixaTemporarioParaTras(): void
    {
        // phpredis no modo padrão lança RedisException quando a conexão cai.
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivelComExcecao();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));

        try {
            $this->simularComandoAudio(
                $telegram,
                $redis,
                $this->baixadorFalso(),
                $whisper,
                new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
            );
            $this->fail('A RedisException do phpredis precisa interromper o fluxo.');
        } catch (\RedisException $excecao) {
            $this->assertSame('Connection refused', $excecao->getMessage());
        }

        $this->assertSame(0, $whisper->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
    }

    public function testArquivoIndisponivelNoTelegramAvisaOUsuario(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777), true, false);
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            $whisper,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
        );

        $this->assertSame(
            ['Erro ao obter informações do áudio. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $whisper->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
    }

    /**
     * Áudio grande demais: o getFile responde ok = true, mas o result vem sem
     * file_path (o Telegram só serve arquivos de até 20 MB). Sem file_path não
     * há URL para baixar, então o comando precisa parar aqui — e não montar
     * uma URL truncada nem gastar a chamada paga do Whisper.
     */
    public function testAudioGrandeDemaisParaOTelegramNaoViraDownloadNemChamadaPaga(): void
    {
        $telegram = $this->telegramEspiao(
            $this->updateDeVoz(777),
            true,
            true,
            ['file_id' => 'voice-abc', 'file_size' => 25 * 1024 * 1024]
        );
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));

        $baixado = false;
        $this->simularComandoAudio(
            $telegram,
            $redis,
            static function () use (&$baixado): string {
                $baixado = true;

                return 'bytes';
            },
            $whisper,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
        );

        $this->assertFalse($baixado, 'Sem file_path não há o que baixar.');
        $this->assertSame(
            ['Erro ao obter informações do áudio. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $whisper->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
    }

    // ---------------------------------------------------------------------
    // Falhas do download e da transcrição
    // ---------------------------------------------------------------------

    /**
     * Download que responde 200 com corpo VAZIO (proxy/CDN devolvendo nada):
     * antes o comando gravava um .ogg de 0 byte e o mandava assim mesmo ao
     * Whisper — chamada paga com resultado garantido de erro, e o usuário
     * recebia "erro ao transcrever" em vez de "erro ao baixar".
     */
    public function testDownloadComCorpoVazioNaoGastaAChamadaPagaDoWhisper(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));

        $this->simularComandoAudio(
            $telegram,
            $redis,
            static fn (): string => '',
            $whisper,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
        );

        $this->assertSame(0, $whisper->chamadas, 'Áudio vazio não pode virar upload pago.');
        $this->assertSame(
            ['Erro ao baixar o áudio. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame([], $this->arquivosTemporarios());
        $this->assertNull($redis->valorDe('contexto777'));
    }

    public function testFalhaNoDownloadAvisaOUsuarioSemDeixarTemporario(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));

        $this->simularComandoAudio(
            $telegram,
            $redis,
            static fn () => false,
            $whisper,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
        );

        $this->assertSame(
            ['Erro ao baixar o áudio. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $whisper->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
        $this->assertNull($redis->valorDe('contexto777'));
    }

    public function testAvisoDeErroRespondeAMensagemOriginalESemMarkdown(): void
    {
        // Todos os avisos do comando saem por avisarUsuarioAudio(): precisam
        // chegar no chat certo, respondendo à mensagem que gerou o erro e sem
        // formatação (o texto do erro não é Markdown).
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoAudio(
            $telegram,
            $redis,
            static fn () => false,
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui')),
            new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
        );

        $aviso = $telegram->chamadas[1] ?? null;
        $this->assertIsArray($aviso);
        $this->assertSame('sendMessage', $aviso['api']);
        $this->assertSame(
            [
                'chat_id' => 777,
                'text' => "Erro ao baixar o áudio. Tente novamente! ❌",
                'reply_parameters' => '{"message_id":10,"allow_sending_without_reply":true}',
            ],
            $aviso['content']
        );
    }

    public function testAvisoDeErroEmGrupoVaiParaOChatENaoParaOAutor(): void
    {
        // Em grupo o chat_id é diferente do id de quem mandou o áudio: o aviso
        // precisa chegar no GRUPO, respondendo à mensagem original.
        $telegram = $this->telegramEspiao([
            'update_id' => 5,
            'message' => [
                'message_id' => 14,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'voice' => ['file_id' => 'voice-abc', 'duration' => 4],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoAudio(
            $telegram,
            $redis,
            static fn () => false,
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui')),
            new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
        );

        $aviso = $telegram->chamadas[1] ?? null;
        $this->assertIsArray($aviso);
        $this->assertSame('sendMessage', $aviso['api']);
        $this->assertSame(
            [
                'chat_id' => -100500,
                'text' => "Erro ao baixar o áudio. Tente novamente! ❌",
                'reply_parameters' => '{"message_id":14,"allow_sending_without_reply":true}',
            ],
            $aviso['content']
        );
    }

    public function testTimeoutDoWhisperApagaOTemporarioEAvisaSemEsperaReal(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $inicio = microtime(true);

        // curl_exec devolve false em timeout/conexão recusada.
        $whisper = new Support\TransporteWhisperEspiao(false, 0);
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio($telegram, $redis, $this->baixadorFalso(), $whisper, $chat);

        $this->assertLessThan(1.0, microtime(true) - $inicio, 'Nenhuma espera real pode acontecer no teste.');
        $this->assertSame(1, $whisper->chamadas);
        $this->assertSame(0, $chat->chamadas, 'Sem transcrição, o chat/completions não é chamado.');
        $this->assertSame([self::ERRO_TRANSCRICAO], $this->textosEnviados($telegram->chamadas));
        // O ponto do teste: o áudio baixado não fica no disco quando a
        // transcrição falha.
        $this->assertSame([], $this->arquivosTemporarios());
        $this->assertNull($redis->valorDe('contexto777'));
    }

    public function testErroHttpDoWhisperApagaOTemporarioEEnviaAMensagemDaOpenAi(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();

        $whisper = new Support\TransporteWhisperEspiao(
            '{"error":{"message":"Audio file might be corrupted","type":"invalid_request_error"}}',
            400
        );
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio($telegram, $redis, $this->baixadorFalso(), $whisper, $chat);

        $this->assertSame(
            [self::ERRO_TRANSCRICAO],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $chat->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
    }

    /**
     * Áudio sem fala: o Whisper responde 200 com texto vazio. A segunda chamada
     * PAGA (chat/completions) não pode acontecer com uma mensagem de usuário
     * vazia, e o contexto anterior não pode ser sobrescrito por ''.
     */
    public function testAudioSemFalaNaoGastaAChamadaPagaDoChatgpt(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto777', 'pergunta anterior');
        $redis->semear('assistente777', 'resposta anterior');

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('')),
            $chat
        );

        $this->assertSame([self::ERRO_TRANSCRICAO], $this->textosEnviados($telegram->chamadas));
        $this->assertSame(0, $chat->chamadas, 'Transcrição vazia não pode virar chamada paga.');
        // A conversa anterior continua intacta: nada foi sobrescrito por ''.
        $this->assertSame('pergunta anterior', $redis->valorDe('contexto777'));
        $this->assertSame('resposta anterior', $redis->valorDe('assistente777'));
        $this->assertSame([], $this->arquivosTemporarios());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function transcricoesQuebradasProvider(): array
    {
        return [
            'corpo html de gateway' => ['<html><body>502 Bad Gateway</body></html>', 502],
            'json truncado' => ['{"text":', 200],
            'sem a chave text' => ['{"duration":3}', 200],
            'http 500 sem detalhe' => ['{}', 500],
            // 429 com corpo vazio: a OpenAI derruba a requisição sem JSON.
            'http 429 sem corpo' => ['', 429],
            // Áudio sem fala: 200 com texto vazio (ver teste dedicado acima).
            'http 200 com texto vazio' => ['{"text":""}', 200],
            // text de outro tipo: não pode virar a "fala" do usuário na chamada paga.
            'http 200 com text de outro tipo' => ['{"text":{"pt":"oi"}}', 200],
        ];
    }

    #[DataProvider('transcricoesQuebradasProvider')]
    public function testTranscricaoMalformadaAvisaOUsuarioSemChamarOChatgpt(string $corpo, int $http): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($corpo, $http),
            $chat
        );

        $this->assertSame([self::ERRO_TRANSCRICAO], $this->textosEnviados($telegram->chamadas));
        $this->assertSame(0, $chat->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
    }

    /**
     * Exceção NO MEIO do upload ao Whisper (o cURL do phpredis/PHP pode lançar
     * por conta do wrapper, e qualquer erro do PHP virado exceção sobe daqui).
     * O `finally` é o único ponto que roda nesse caminho: sem ele o .ogg baixado
     * ficaria no disco do servidor para sempre, um por áudio recebido.
     *
     * O teste anterior (timeout) só prova o retorno false; este prova o caminho
     * excepcional, que é o que o `finally` existe para cobrir.
     */
    public function testExcecaoNoUploadDoWhisperNaoDeixaOAudioNoDisco(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        // O transporte anota o arquivo que RECEBEU antes de estourar: sem isso o
        // teste passaria mesmo que o download nunca tivesse gravado nada.
        $arquivoRecebido = null;
        $existiaDurante = null;

        try {
            $this->simularComandoAudio(
                $telegram,
                $redis,
                $this->baixadorFalso(),
                static function (string $url, array $campos) use (&$arquivoRecebido, &$existiaDurante) {
                    $enviado = $campos['file'] ?? null;
                    $arquivoRecebido = $enviado instanceof \CURLFile ? $enviado->getFilename() : null;
                    $existiaDurante = is_string($arquivoRecebido) && is_file($arquivoRecebido);

                    throw new \RuntimeException('conexão interrompida no meio do upload');
                },
                $chat
            );
            $this->fail('A exceção do transporte precisa atravessar o fluxo do comando.');
        } catch (\RuntimeException $excecao) {
            $this->assertSame('conexão interrompida no meio do upload', $excecao->getMessage());
        }

        $this->assertIsString($arquivoRecebido, 'O áudio precisa ter sido gravado antes do upload.');
        $this->assertTrue($existiaDurante, 'O arquivo temporário existia quando o upload estourou.');

        // O ponto do teste: mesmo assim, nada fica para trás no disco.
        $this->assertFileDoesNotExist($arquivoRecebido);
        $this->assertSame([], $this->arquivosTemporarios());
        $this->assertSame(0, $chat->chamadas, 'Sem transcrição, o chat/completions não é chamado.');
        $this->assertNull($redis->valorDe('contexto777'));
    }

    /**
     * Exceção durante o DOWNLOAD (antes de qualquer gravação): o comando não
     * chega a criar o temporário, então também não pode deixar rastro.
     */
    public function testExcecaoNoDownloadNaoCriaArquivoTemporario(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));

        try {
            $this->simularComandoAudio(
                $telegram,
                $redis,
                static function () {
                    throw new \RuntimeException('DNS do Telegram fora do ar');
                },
                $whisper,
                new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
            );
            $this->fail('A exceção do download precisa atravessar o fluxo do comando.');
        } catch (\RuntimeException $excecao) {
            $this->assertSame('DNS do Telegram fora do ar', $excecao->getMessage());
        }

        $this->assertSame([], $this->arquivosTemporarios());
        $this->assertSame(0, $whisper->chamadas);
    }

    /**
     * A cota é consumida ANTES de o arquivo ser pedido ao Telegram, então um
     * Áudio irrecuperável não chega à OpenAI, portanto não pode consumir a cota
     * que mede chamadas pagas.
     */
    public function testAudioIrrecuperavelNaoGastaCotaDaOpenAi(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777), true, false);
        $redis = new Support\RedisFalsoComLua();
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui')),
            new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
        );

        $this->assertSame(
            ['Erro ao obter informações do áudio. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertNull($redis->valorDe($chaveCota));
        $this->assertNull($redis->expiracaoDe($chaveCota));
    }

    /**
     * A cota diária é UMA só por usuário: a chave do Redis
     * (limite_diario:ID:AAAAMMDD) e o teto de 100 são os mesmos em
     * comandos/chatgpt.php, ver_audio.php e ver_imagem.php. Mandar áudio não
     * pode ser um jeito de escapar do limite gasto com texto.
     *
     * Aqui 99 requisições de texto já foram feitas hoje; a centésima é
     * consumida por consumir_limite_diario() — a MESMA chamada que o comando de
     * texto faz — e o áudio seguinte precisa bater no limite.
     */
    public function testCotaGastaComTextoTambemBloqueiaOAudioDoMesmoUsuario(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();
        $redis->semear($chaveCota, '99');

        // A centésima requisição do dia, gasta pelo comando de TEXTO.
        $doTexto = consumir_limite_diario($redis, 777, 100);
        $this->assertFalse($doTexto['atingiu_limite'], 'A centésima de texto ainda é atendida.');

        $whisper = new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('não deveria chegar aqui'));
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoAudio($telegram, $redis, $this->baixadorFalso(), $whisper, $chat);

        $this->assertSame(
            ['Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $whisper->chamadas, 'Áudio não pode burlar a cota gasta com texto.');
        $this->assertSame(0, $chat->chamadas);
        $this->assertSame([], $this->arquivosTemporarios());
    }

    // ---------------------------------------------------------------------
    // Falhas do chat/completions
    // ---------------------------------------------------------------------

    public function testErroDaApiAvisaOUsuarioENaoGuardaRespostaDoAssistente(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();

        $chat = new Support\TransporteHttpEspiao('{"error":{"message":"Rate limit reached","type":"rate_limit_error"}}');

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('Oi!')),
            $chat
        );

        $this->assertSame([self::ERRO_GENERICO], $this->textosEnviados($telegram->chamadas));
        // Rate limit NÃO vira nova tentativa: uma requisição, uma chamada.
        $this->assertSame(1, $chat->chamadas);
        // A transcrição já havia sido gravada como contexto antes da chamada...
        $this->assertSame('Oi!', $redis->valorDe('contexto777'));
        // ...mas nenhuma resposta do assistente é registrada.
        $this->assertNull($redis->valorDe('assistente777'));
        $this->assertSame([], $this->arquivosTemporarios());
    }

    public function testTimeoutDoChatgptAvisaOUsuarioSemEsperaReal(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();
        $inicio = microtime(true);

        $chat = new Support\TransporteHttpEspiao(false);

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('Oi!')),
            $chat
        );

        $this->assertLessThan(1.0, microtime(true) - $inicio, 'Nenhuma espera real pode acontecer no teste.');
        $this->assertSame(1, $chat->chamadas);
        $this->assertSame([self::ERRO_GENERICO], $this->textosEnviados($telegram->chamadas));
        $this->assertNull($redis->valorDe('assistente777'));
        $this->assertSame([], $this->arquivosTemporarios());
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
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('Oi!')),
            new Support\TransporteHttpEspiao($corpo)
        );

        $this->assertSame([self::ERRO_GENERICO], $this->textosEnviados($telegram->chamadas));
        $this->assertNull($redis->valorDe('assistente777'));
    }

    public function testFalhaDoMarkdownReenviaAMesmaRespostaSemFormatacao(): void
    {
        // A API do Telegram recusa a formatação Markdown (ok = false).
        $telegram = $this->telegramEspiao($this->updateDeVoz(777), false);
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('Oi!')),
            new Support\TransporteHttpEspiao($this->corpoDeResposta('texto com *destaque*'))
        );

        $this->assertSame(
            ['texto com *destaque*', 'texto com *destaque*'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame('Markdown', $telegram->chamadas[2]['content']['parse_mode'] ?? null);
        $this->assertArrayNotHasKey('parse_mode', $telegram->chamadas[3]['content']);
        $this->assertSame('texto com *destaque*', $redis->valorDe('assistente777'));
    }

    public function testEntregaSemPermissaoNaoRepeteNemSalvaResposta(): void
    {
        // Uma recusa de permissão não é uma falha de formatação.
        $telegram = $this->telegramEspiao($this->updateDeVoz(777), false, recusarEnvios: true);
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('Oi!')),
            new Support\TransporteHttpEspiao($this->corpoDeResposta('texto com *destaque*'))
        );

        $this->assertSame(
            ['texto com *destaque*'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame('Markdown', $telegram->chamadas[2]['content']['parse_mode'] ?? null);
        $this->assertNull($redis->valorDe('assistente777'));
    }

    public function testRespostaMuitoLongaEEnviadaERegistradaJaRecortada(): void
    {
        $telegram = $this->telegramEspiao($this->updateDeVoz(777));
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoAudio(
            $telegram,
            $redis,
            $this->baixadorFalso(),
            new Support\TransporteWhisperEspiao($this->corpoDeTranscricao('escreva muito')),
            new Support\TransporteHttpEspiao($this->corpoDeResposta(str_repeat('a', 5000)))
        );

        $esperado = str_repeat('a', 4096);
        $this->assertSame([$esperado], $this->textosEnviados($telegram->chamadas));
        $this->assertSame($esperado, $redis->valorDe('assistente777'));
    }

    // ---------------------------------------------------------------------
    // Fidelidade do espelho
    // ---------------------------------------------------------------------

    /**
     * A remoção do temporário precisa ficar DENTRO do finally que envolve a
     * chamada ao Whisper — é o único ponto que roda em todos os caminhos.
     *
     * Movê-la para depois do bloco (só no caminho feliz) deixaria o áudio no
     * disco sempre que a transcrição falhasse ou lançasse exceção, e nenhum
     * teste de comportamento veria isso: o espelho acima REPRODUZ a ordem do
     * topo em vez de executá-la, então ele continuaria limpando o arquivo por
     * conta própria. Por isso a posição da limpeza é travada aqui, no bloco
     * lido do próprio arquivo.
     */
    public function testRemocaoDoTemporarioFicaDentroDoFinallyDaTranscricao(): void
    {
        // O bloco mora no irmão desde que o corpo do comando saiu do script:
        // executarComandoVerAudio() é onde o try/finally vive agora.
        $bloco = Support\EstruturaDeScript::blocoTryFinallyQueContem(
            __DIR__ . '/../../comandos/funcoes_ver_audio.php',
            'chamarTranscricaoWhisper'
        );

        // O que pode falhar (chamada paga, exceção, timeout) fica no try.
        $this->assertStringContainsString(
            "chamarTranscricaoWhisper(\n"
            . "            \$whisper_url,\n"
            . "            \$api_key,\n"
            . "            \$temp_audio_path,\n"
            . "            \$transporteWhisper,\n"
            . "            \$metadados_upload['nome'],\n"
            . "            \$metadados_upload['mime']\n"
            . "        );",
            $bloco['try'],
            'A chamada ao Whisper precisa ficar dentro do try protegido pelo finally.'
        );

        // A limpeza fica no finally, que roda inclusive quando a transcrição falha.
        $this->assertStringContainsString(
            'removerAudioTemporario($temp_audio_path);',
            $bloco['finally'],
            'O finally precisa remover o áudio temporário em todos os caminhos.'
        );

        // E não pode haver limpeza DEPOIS do finally: ali ela só rodaria quando
        // a transcrição desse certo, vazando o arquivo em todo caminho de erro.
        $this->assertStringNotContainsString(
            'removerAudioTemporario(',
            $bloco['depois'],
            'A limpeza do temporário não pode depender do caminho feliz.'
        );
    }
}
