<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes de FLUXO do comando de imagem (comandos/ver_imagem.php) da entrada do
 * update até a resposta enviada ao Telegram, amarrando as peças reais:
 *   - parsing do update pela classe Telegram (setData, sem rede);
 *   - cota diária de inclusos/limites_requisicoes.php com o Lua EXECUTADO
 *     pelo dublê Support\RedisFalsoComLua;
 *   - montagem do payload de visão (legenda + URL da maior resolução),
 *     chamada e interpretação da resposta da OpenAI, com o transporte HTTP
 *     injetado (nenhuma rede, nenhuma espera real).
 *
 * Este comando não grava arquivo algum: a imagem vai para a OpenAI por URL.
 * Cada teste confere que nada apareceu no diretório temporário do sistema.
 *
 * O script continua NÃO podendo ser incluído: o topo termina em exit e, sem
 * transporte injetado, faria cURL de verdade. A ordem do topo é ESPELHADA em
 * simularComandoImagem() usando as FUNÇÕES DE PRODUÇÃO extraídas do próprio
 * arquivo, e a fidelidade do espelho é travada na fonte por
 * testEspelhoDoFluxoContinuaFielAFonte().
 */
final class FluxoIaImagemTest extends TestCase
{
    public function testFalhaNoDownloadNaoExpoeTokenEmWarningNativo(): void
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensagem) use (&$avisos): bool {
            if ((error_reporting() & $nivel) !== 0) {
                $avisos[] = $mensagem;
            }
            return true;
        });
        try {
            $url = 'file:///inexistente/bot123456:SEGREDO_TESTE/foto.jpg';
            self::assertFalse(baixarArquivoDoTelegram($url));
            self::assertFalse(baixarArquivoDoTelegram($url, 100));
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $avisos);
        foreach ([':', '%3A'] as $separador) {
            $url = 'https://api.telegram.org/file/bot123456' . $separador . 'SEGREDO_TESTE/foto.jpg';
            self::assertStringNotContainsString('SEGREDO_TESTE', \App\Support\ProcessadorDeRedacao::redigirTexto($url));
        }
    }

    public function testRecusaDeEscritaNaoDisparaChamadaPaga(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777), recusarAcao: true);
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('resposta'));
        $this->simularComandoImagem($telegram, $redis, $chat);
        self::assertSame(0, $chat->chamadas);
        self::assertNotContains('sendMessage', array_column($telegram->chamadas, 'api'));
    }

    private const CAMINHO_SCRIPT = __DIR__ . '/../../comandos/ver_imagem.php';

    /** Token fake: o script usa a constante TOKEN_BOT (inclusos/config.php). */
    private const TOKEN_FAKE = '123456:FAKE_TOKEN';

    /** Chave fake: o script usa a constante OPENROUTER_API_KEY (inclusos/config.php). */
    private const CHAVE_API = 'sk-teste-fake';

    /** Relógio fixo injetado no prompt para o payload ser determinístico. */
    private const DATA_HORA = '01/02/2026 03:04:05';

    private const ERRO_GENERICO = "Erro ao analisar a imagem. Tente novamente! ❌";

    private const CAMINHO_NO_TELEGRAM = 'photos/file_9.jpg';

    /** JPEG mínimo devolvido pelo download falso: só a assinatura importa. */
    private const JPEG = "\xFF\xD8\xFF\xE0bytes-da-foto";


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
            'avisarUsuarioImagem',
            'imagemDaMensagem',
            'montarMensagensImagemComHistorico',
            'montarCorpoRequisicaoImagem',
            'chamarChatCompletionsImagem',
            'interpretarRespostaImagem',
            'baixarImagemComoDataUri',
        ]);
    }

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../inclusos/limites_requisicoes.php';
    }

    // ---------------------------------------------------------------------
    // Espelho do topo de comandos/ver_imagem.php
    // ---------------------------------------------------------------------

    /**
     * Chama o comando de produção.
     *
     * Até 2026-08-24 este método era um ESPELHO escrito à mão do corpo de
     * comandos/ver_imagem.php, porque script de topo não podia ser incluído — duas cópias do
     * mesmo fluxo, e só um teste de texto conferindo que a fonte ainda continha
     * as linhas espelhadas. Com o corpo extraído para executarComandoVerImagem(), no
     * funcoes_*.php irmão, a fidelidade deixa de depender de manter as duas em
     * dia: o teste roda o código de produção, e a cobertura é atribuída a ele.
     *
     * @param callable $transporteChat fn(string $url, string $corpo, array<int, string> $cabecalhos): mixed
     */
    private function simularComandoImagem(
        Telegram $telegram,
        Support\RedisFalsoComLua $redis,
        callable $transporteChat,
        ?callable $baixadorImagem = null
    ): void {
        // Sem baixador explícito vale o download falso: o comando de produção
        // BAIXA a foto e manda um data: URI, de propósito, para não entregar à
        // OpenAI uma URL que carrega o TOKEN_BOT. O espelho antigo pulava esse
        // passo e mandava a URL — mais um jeito de os dois terem divergido.
        $baixadorImagem ??= static fn (string $url): string => self::JPEG;

        executarComandoVerImagem($telegram, $redis, $transporteChat, $baixadorImagem, self::DATA_HORA);
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
            public ?array $arquivoDoTelegram = ['file_path' => 'photos/file_9.jpg'];

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
     * Update de foto em chat privado, com os três tamanhos que o Telegram manda.
     *
     * @param int $idUsuario
     * @param string|null $legenda
     * @return array<string, mixed>
     */
    private function updateComFoto(int $idUsuario, ?string $legenda = null): array
    {
        $mensagem = [
            'message_id' => 10,
            'from' => ['id' => $idUsuario, 'is_bot' => false, 'first_name' => 'Fulano'],
            'chat' => ['id' => $idUsuario, 'type' => 'private'],
            'photo' => [
                ['file_id' => 'foto-pequena', 'width' => 90, 'height' => 90],
                ['file_id' => 'foto-media', 'width' => 320, 'height' => 320],
                ['file_id' => 'foto-grande', 'width' => 1280, 'height' => 1280],
            ],
        ];
        if ($legenda !== null) {
            $mensagem['caption'] = $legenda;
        }

        return ['update_id' => 1, 'message' => $mensagem];
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

    /**
     * O data: URI que o comando monta a partir de self::JPEG.
     *
     * Calculado, e não escrito à mão: o valor esperado tem de sair da MESMA
     * regra que o código de produção aplica (`data:<mime>;base64,<bytes>`), e
     * um literal colado aqui mediria a minha transcrição, não a regra.
     */
    private function dataUriEsperado(): string
    {
        return 'data:image/jpeg;base64,' . base64_encode(self::JPEG);
    }

    private function corpoDeResposta(string $conteudo): string
    {
        $corpo = json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $conteudo]]]]);
        $this->assertIsString($corpo);

        return $corpo;
    }

    /**
     * Confere que o comando de imagem não deixou arquivo temporário algum.
     *
     * A comparação é com o retrato tirado ANTES do fluxo, não com uma lista
     * vazia: sys_get_temp_dir() é compartilhado com o resto da máquina, e um
     * arquivo alheio com um desses prefixos faria o teste reprovar sem que o
     * comando tivesse escrito nada.
     *
     * @param array<int, string> $antes Retrato devolvido por temporariosDeImagem()
     */
    private function assertNenhumTemporarioDeImagem(array $antes): void
    {
        $this->assertSame(
            $antes,
            $this->temporariosDeImagem(),
            'O comando de imagem não pode criar arquivo temporário: o base64 vive em memória.'
        );
    }

    /**
     * Arquivos do diretório temporário do sistema com os prefixos que um
     * download de imagem produziria.
     *
     * @return array<int, string>
     */
    private function temporariosDeImagem(): array
    {
        $encontrados = glob(sys_get_temp_dir() . '/{imagem,image,photo,foto}_*', GLOB_BRACE);

        return is_array($encontrados) ? $encontrados : [];
    }

    // ---------------------------------------------------------------------
    // Fluxo feliz
    // ---------------------------------------------------------------------

    public function testFluxoDeImagemConsomeCotaEnviaAImagemEmBase64EGuardaOContexto(): void
    {
        $temporariosAntes = $this->temporariosDeImagem();
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('Vejo um gato preto 🐈'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        // 1. Cota consumida uma vez, com o TTL de 24h aplicado na criação.
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();
        $this->assertSame('1', $redis->valorDe($chaveCota));
        $this->assertSame(86400, $redis->expiracaoDe($chaveCota));

        // 2. O getFile pediu a MAIOR resolução da foto.
        $this->assertSame(
            ['getFile', 'sendChatAction', 'sendMessage'],
            $this->apisChamadas($telegram->chamadas)
        );
        $this->assertSame('foto-grande', $telegram->chamadas[0]['content']['file_id'] ?? null);

        // 3. Requisição enviada à OpenAI: endpoint, autorização e payload de visão.
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
        $this->assertStringContainsString('O usuário enviou uma IMAGEM', $desenvolvedor);
        // A imagem vai em BASE64, baixada pelo próprio bot. Antes ia a URL de
        // download do Telegram, que carrega o TOKEN_BOT completo: a OpenAI
        // recebia — em toda mensagem com foto — a credencial que dá controle
        // total do bot. Trocar este bloco de volta pela URL faz o teste falhar.
        $this->assertSame(
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'O que você vê nesta imagem?'],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $this->dataUriEsperado(),
                            'detail' => 'high',
                        ],
                    ],
                ],
            ],
            $mensagens[1] ?? null
        );

        // E o corpo inteiro, byte a byte, não pode conter o token nem o host de
        // download — o payload é o que sai da máquina.
        $this->assertIsString($chat->corpo);
        $this->assertStringNotContainsString(self::TOKEN_FAKE, $chat->corpo);
        $this->assertStringNotContainsString('FAKE_TOKEN', $chat->corpo);
        $this->assertStringNotContainsString('api.telegram.org', $chat->corpo);

        // 4. Telegram: a resposta em Markdown, respondendo à mensagem.
        $envio = $telegram->chamadas[2]['content'];
        $this->assertSame(777, $envio['chat_id'] ?? null);
        $this->assertSame('Vejo um gato preto 🐈', $envio['text'] ?? null);
        $this->assertSame('Markdown', $envio['parse_mode'] ?? null);
        $this->assertSame(
            '{"message_id":10,"allow_sending_without_reply":true}',
            $envio['reply_parameters'] ?? null
        );

        // 5. Contexto (a legenda) e resposta guardados por 24h.
        $this->assertSame('O que você vê nesta imagem?', $redis->valorDe('contexto777'));
        $this->assertSame(86400, $redis->expiracaoDe('contexto777'));
        $this->assertSame('Vejo um gato preto 🐈', $redis->valorDe('assistente777'));
        $this->assertSame(86400, $redis->expiracaoDe('assistente777'));

        // 6. Nenhum arquivo temporário: o base64 vive em memória e some com a
        //    requisição, ao contrário do áudio, que precisa de um CURLFile.
        $this->assertNenhumTemporarioDeImagem($temporariosAntes);
    }

    /**
     * O fluxo sorteava e montava um teclado inline ("Adicione Dealni no
     * Grupo" ou "Compartilhe o Bot") a cada imagem respondida, mas nunca o
     * enviava. A montagem saiu: a resposta segue sem reply_markup e o fluxo
     * não consome mais o gerador do rand(), o mesmo do sorteio de 10% do
     * roteador.php.
     */
    public function testRespostaDaImagemNaoMontaTecladoNemConsomeOSorteio(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('Vejo um gato preto 🐈'));

        mt_srand(4242);
        $esperado = rand(1, 1000000);
        mt_srand(4242);
        $this->simularComandoImagem($telegram, $redis, $chat);
        $depoisDoFluxo = rand(1, 1000000);
        mt_srand();

        $this->assertSame($esperado, $depoisDoFluxo, 'O fluxo de imagem voltou a consumir o rand().');
        $this->assertSame(
            ['getFile', 'sendChatAction', 'sendMessage'],
            $this->apisChamadas($telegram->chamadas)
        );
        $envio = $telegram->chamadas[2]['content'];
        $this->assertSame('Vejo um gato preto 🐈', $envio['text'] ?? null);
        $this->assertArrayNotHasKey('reply_markup', $envio);
    }

    /**
     * "0" é falsy em PHP e atravessa TRÊS pontos deste fluxo: a legenda da
     * foto, o contexto lido do Redis e o conteúdo devolvido pela OpenAI. Um
     * único empty() em qualquer um deles some com o que o usuário escreveu —
     * o bug que o commit 341c19d corrigiu. Aqui o "0" faz a volta completa.
     */
    public function testConteudoIgualAZeroAtravessaOFluxoInteiroDaImagem(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777, '0'));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto777', '0');
        $redis->semear('assistente777', '0');

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('0'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $mensagens = $this->mensagensDoPayload($chat);
        // O contexto "0" guardado antes não é descartado do payload...
        $this->assertCount(4, $mensagens);
        $this->assertSame(['role' => 'user', 'content' => '0'], $mensagens[1] ?? null);
        $this->assertSame(['role' => 'assistant', 'content' => '0'], $mensagens[2] ?? null);
        // ...e a legenda "0" vai como pergunta, em vez da pergunta padrão.
        $conteudo = $mensagens[3]['content'] ?? null;
        $this->assertIsArray($conteudo);
        $this->assertSame(['type' => 'text', 'text' => '0'], $conteudo[0] ?? null);

        // A resposta "0" da OpenAI é enviada ao usuário, não trocada por erro.
        $this->assertSame(['0'], $this->textosEnviados($telegram->chamadas));
        $this->assertSame('0', $redis->valorDe('contexto777'));
        $this->assertSame('0', $redis->valorDe('assistente777'));
    }

    public function testLegendaDoUsuarioViraAPerguntaEOContexto(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777, 'Que raça é esse gato?'));
        $redis = new Support\RedisFalsoComLua();

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('Parece um siamês!'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $mensagens = $this->mensagensDoPayload($chat);
        $conteudo = $mensagens[1]['content'] ?? null;
        $this->assertIsArray($conteudo);
        $this->assertSame(['type' => 'text', 'text' => 'Que raça é esse gato?'], $conteudo[0] ?? null);
        $this->assertSame('Que raça é esse gato?', $redis->valorDe('contexto777'));
    }

    public function testLegendaMuitoLongaEEnviadaRecortadaEm1024Caracteres(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777, str_repeat('a', 2000)));
        $redis = new Support\RedisFalsoComLua();

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('ok'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $mensagens = $this->mensagensDoPayload($chat);
        $conteudo = $mensagens[1]['content'] ?? null;
        $this->assertIsArray($conteudo);
        $primeiro = $conteudo[0] ?? null;
        $this->assertIsArray($primeiro);
        $texto = $primeiro['text'] ?? null;
        $this->assertIsString($texto);
        $this->assertSame(1024, mb_strlen($texto, 'UTF-8'));
        // O contexto guarda a mesma legenda já recortada.
        $this->assertSame($texto, $redis->valorDe('contexto777'));
    }

    public function testSegundaImagemEnviaOContextoAnteriorNoPayload(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777, 'e esta?'));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto777', 'pergunta anterior');
        $redis->semear('assistente777', 'resposta anterior');

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('outra foto 🙂'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $mensagens = $this->mensagensDoPayload($chat);
        $this->assertCount(4, $mensagens);
        $this->assertSame(['role' => 'user', 'content' => 'pergunta anterior'], $mensagens[1] ?? null);
        $this->assertSame(['role' => 'assistant', 'content' => 'resposta anterior'], $mensagens[2] ?? null);
        // A imagem é sempre a última mensagem do payload.
        $this->assertSame('user', $mensagens[3]['role'] ?? null);
        $this->assertIsArray($mensagens[3]['content'] ?? null);
    }

    public function testGrupoNaoConsomeCotaEUsaOIdDoChatNoContexto(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 2,
            'message' => [
                'message_id' => 11,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'caption' => 'olha isso',
                'photo' => [['file_id' => 'foto-grande', 'width' => 1280]],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoImagem(
            $telegram,
            $redis,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('que legal!'))
        );

        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
        $this->assertSame('olha isso', $redis->valorDe('contexto:memoria:v3:grupo:-100500:topico:0:usuario:777'));
        $this->assertSame('que legal!', $redis->valorDe('assistente:memoria:v3:grupo:-100500:topico:0:usuario:777'));
        $this->assertNull($redis->valorDe('contexto777'));
    }

    // ---------------------------------------------------------------------
    // Entrada, cota e Redis
    // ---------------------------------------------------------------------

    public function testMensagemSemFotoPedeAImagemSemChamarAOpenAi(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 4,
            'message' => [
                'message_id' => 13,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => 777, 'type' => 'private'],
                'text' => 'analisa aí',
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(
            ['Por favor, envie uma imagem para que eu possa analisá-la! 📸'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
        $this->assertSame(0, $chat->chamadas);
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
    }

    /**
     * Update sem autor (post de canal com foto, mensagem de serviço): sem
     * UserID não há a quem debitar a cota nem contexto a carregar, e o comando
     * devolve o controle sem fazer NADA — nem sendChatAction, nem getFile, nem
     * chamada paga.
     *
     * O ramo existe no topo do comando desde sempre e nenhum teste o
     * exercitava: sem ele o fluxo seguiria com 'contexto' concatenado a null e
     * gastaria a chamada paga por um update que ninguém pediu.
     */
    public function testUpdateSemAutorNaoDisparaNenhumaChamada(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 6,
            'message' => [
                'message_id' => 15,
                // sem 'from': UserID() devolve null
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'photo' => [['file_id' => 'foto-grande', 'width' => 1280]],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertNull($telegram->UserID(), 'O update precisa mesmo estar sem autor.');
        $this->assertSame([], $telegram->chamadas, 'Nenhuma chamada ao Telegram para update sem autor.');
        $this->assertSame(0, $chat->chamadas);
        $this->assertSame([], $redis->operacoes(), 'Nem o Redis é tocado.');
    }

    /**
     * Update com autor mas sem `message` (edição de uma foto): o comando lê
     * a imagem de `message`, e sem ele pede a imagem em vez de quebrar
     * acessando índice inexistente — nem cota nem download.
     */
    public function testUpdateSemMessagePedeAImagemSemConsumirCota(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 7,
            'edited_message' => [
                'message_id' => 16,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => 777, 'type' => 'private'],
                'photo' => [['file_id' => 'foto-grande', 'width' => 1280]],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(777, $telegram->UserID(), 'O update precisa ter autor.');
        $this->assertSame(
            ['Por favor, envie uma imagem para que eu possa analisá-la! 📸'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
        $this->assertSame(0, $chat->chamadas);
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
    }

    /**
     * Foto sem `file_id` no maior tamanho: há imagem, mas nada para pedir ao
     * getFile. O aviso é o de erro (não o pedido de imagem), e nada é cobrado.
     */
    public function testFotoSemFileIdAvisaOErroSemConsumirCota(): void
    {
        $telegram = $this->telegramEspiao([
            'update_id' => 8,
            'message' => [
                'message_id' => 17,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => 777, 'type' => 'private'],
                'photo' => [['width' => 1280, 'height' => 1280]],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(
            ['Erro ao obter informações da imagem. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
        $this->assertSame(0, $chat->chamadas);
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
    }

    /**
     * Contexto legado com bytes que não são UTF-8: o json_encode do corpo do
     * chat/completions falha, e o comando avisa o erro em vez de mandar
     * `false` como corpo — a chamada paga não acontece.
     */
    public function testContextoLegadoForaDoUtf8NaoChegaAoChatCompletions(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('contexto777', "legenda antiga com byte inv\xB1lido");
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(0, $chat->chamadas, 'Corpo que não serializa não pode virar chamada paga.');
        $this->assertContains(
            'Erro ao analisar a imagem. Tente novamente! ❌',
            $this->textosEnviados($telegram->chamadas)
        );
    }

    /**
     * O download PADRÃO da imagem (sem baixador injetado) lê pela mesma
     * função com teto de bytes de comandos/funcoes_ia_comuns.php, e o MIME
     * sai dos bytes. Fixture local por `file://`: o caminho de produção é
     * exercitado sem rede, com o arquivo de verdade carregado por require_once.
     */
    public function testDownloadPadraoDaImagemLeFixtureLocalEDeduzOMimePelosBytes(): void
    {
        $this->assertSame(CURL_IPRESOLVE_V4, opcoesDeDownloadDoTelegram()[CURLOPT_IPRESOLVE]);
        $png = "\x89PNG\r\n\x1A\nbytes-da-imagem";
        $fixture = tempnam(sys_get_temp_dir(), 'dealnibot_imagem_fixture_');
        $this->assertIsString($fixture);

        try {
            $this->assertNotFalse(file_put_contents($fixture, $png));

            $resultado = baixarImagemComoDataUri('file://' . $fixture);
            $this->assertNull($resultado['erro']);
            $this->assertSame('data:image/png;base64,' . base64_encode($png), $resultado['data_uri']);

            // Sem teto, o download lê o arquivo inteiro.
            $this->assertSame($png, baixarArquivoDoTelegram('file://' . $fixture));
            // Com teto, só os primeiros bytes.
            $this->assertSame(substr($png, 0, 8), baixarArquivoDoTelegram('file://' . $fixture, 8));
        } finally {
            @unlink($fixture);
        }
    }

    public function testCotaEstouradaAvisaOUsuarioSemChamarAOpenAi(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('limite_diario:777:' . limite_diario_dia_atual(), '100');
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(
            ['Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $chat->chamadas, 'A OpenAI não pode ser chamada com a cota estourada.');
        // A recusa acontece antes de buscar ou baixar a mídia.
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
        $this->assertNull($redis->valorDe('contexto777'));
    }

    /**
     * Em GRUPO o aviso de cota é omitido de propósito (repetir "você atingiu o
     * limite" a cada foto pelo resto do dia viraria ruído). Com o
     * sendChatAction ANTES do portão, o que o grupo via era o bot entrando em
     * "digitando…" a cada foto e nunca respondendo — pelo resto do dia. Com a
     * ordem certa, a cota estourada não produz chamada NENHUMA à Bot API.
     *
     * Este teste FALHA com a ordem anterior: lá sobrava um `sendChatAction`.
     */
    public function testCotaEstouradaEmGrupoNaoDeixaOBotDigitandoParaSempre(): void
    {
        $update = $this->updateComFoto(777);
        $mensagem = $update['message'];
        $this->assertIsArray($mensagem);
        $mensagem['chat'] = ['id' => -100999, 'type' => 'supergroup'];
        $update['message'] = $mensagem;

        $telegram = $this->telegramEspiao($update);
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('limite_diario:-100999:' . limite_diario_dia_atual(), '100');
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame([], $this->apisChamadas($telegram->chamadas));
        $this->assertSame(0, $chat->chamadas, 'A OpenAI não pode ser chamada com a cota estourada.');
    }

    public function testAdmissaoDeGrupoRecusaImagemAntesDeGetFileEDoDownload(): void
    {
        foreach ([
            ['limite_diario:-100999:' . limite_diario_dia_atual(), '100'],
            ['dealnibot:midia:v1:sujeito:-100999', '5'],
            ['dealnibot:midia:v1:global', '20'],
        ] as [$chave, $valor]) {
            $update = $this->updateComFoto(777);
            $mensagem = $update['message'];
            $this->assertIsArray($mensagem);
            $mensagem['chat'] = ['id' => -100999, 'type' => 'supergroup'];
            $update['message'] = $mensagem;
            $telegram = $this->telegramEspiao($update);
            $redis = new Support\RedisFalsoComLua();
            $redis->semear($chave, $valor);
            $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deve chamar'));
            $downloads = 0;
            $this->simularComandoImagem($telegram, $redis, $chat, static function () use (&$downloads): string {
                $downloads++;
                return self::JPEG;
            });
            $this->assertSame([], $telegram->chamadas);
            $this->assertSame(0, $downloads);
            $this->assertSame(0, $chat->chamadas);
        }
    }

    public function testMidiaInvalidaConsomeAdmissaoMasNaoCotaPaga(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $downloads = 0;
        $chat = new Support\TransporteHttpEspiao(false);
        for ($tentativa = 0; $tentativa < 6; $tentativa++) {
            $this->simularComandoImagem(
                $this->telegramEspiao($this->updateComFoto(777)),
                $redis,
                $chat,
                static function () use (&$downloads): string {
                    $downloads++;
                    return 'não é imagem';
                }
            );
        }
        $this->assertSame(5, $downloads);
        $this->assertSame(0, $chat->chamadas);
        $this->assertNull($redis->valorDe('limite_diario:777:' . limite_diario_dia_atual()));
    }

    public function testRedisIndisponivelBloqueiaAChamadaPagaEAvisaOUsuario(): void
    {
        // Redis fora do ar (phpredis sem exceções): a cota falha fechada.
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivel();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(
            ['Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $chat->chamadas, 'Sem cota confiável, nenhuma chamada paga.');
    }

    public function testCotaEsgotadaDuranteDownloadAindaBloqueiaReservaFinalDaImagem(): void
    {
        foreach (['private', 'supergroup'] as $tipo) {
            $update = $this->updateComFoto(777);
            $mensagem = $update['message'];
            $this->assertIsArray($mensagem);
            $id = $tipo === 'private' ? 777 : -100999;
            $mensagem['chat'] = ['id' => $id, 'type' => $tipo];
            $update['message'] = $mensagem;
            $telegram = $this->telegramEspiao($update);
            $redis = new Support\RedisFalsoComLua();
            $chat = new Support\TransporteHttpEspiao(false);
            $this->simularComandoImagem($telegram, $redis, $chat, static function () use ($redis, $id): string {
                $redis->semear('limite_diario:' . $id . ':' . limite_diario_dia_atual(), '100');
                return self::JPEG;
            });
            $this->assertSame(0, $chat->chamadas);
            $this->assertSame(
                $tipo === 'private' ? ['getFile', 'sendMessage'] : ['getFile'],
                $this->apisChamadas($telegram->chamadas)
            );
            $this->assertSame('100', $redis->valorDe('limite_diario:' . $id . ':' . limite_diario_dia_atual()));
        }
    }

    public function testRedisQueLancaExcecaoNaCotaInterrompeOFluxo(): void
    {
        // phpredis no modo padrão lança RedisException quando a conexão cai.
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivelComExcecao();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        try {
            $this->simularComandoImagem($telegram, $redis, $chat);
            $this->fail('A RedisException do phpredis precisa interromper o fluxo.');
        } catch (\RedisException $excecao) {
            $this->assertSame('Connection refused', $excecao->getMessage());
        }

        $this->assertSame(0, $chat->chamadas);
    }

    public function testArquivoIndisponivelNoTelegramAvisaOUsuario(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777), true, false);
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(
            ['Erro ao obter informações da imagem. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $chat->chamadas);
        $this->assertNull($redis->valorDe('contexto777'));
    }

    /**
     * Imagem grande demais: o getFile responde ok = true, mas o result vem sem
     * file_path (o Telegram só serve arquivos de até 20 MB). Sem file_path a
     * URL enviada à OpenAI terminaria em barra e a chamada paga seria gasta
     * para receber "unsupported image" — o comando precisa parar antes.
     */
    public function testImagemGrandeDemaisParaOTelegramNaoViraChamadaPaga(): void
    {
        $telegram = $this->telegramEspiao(
            $this->updateComFoto(777),
            true,
            true,
            ['file_id' => 'foto-grande', 'file_size' => 25 * 1024 * 1024]
        );
        $redis = new Support\RedisFalsoComLua();
        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(
            ['Erro ao obter informações da imagem. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $chat->chamadas);
        $this->assertNull($redis->valorDe('contexto777'));
    }

    /**
     * Foto irrecuperável não chega à OpenAI, portanto não consome a cota paga.
     */
    public function testImagemIrrecuperavelNaoGastaCotaDaOpenAi(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777), true, false);
        $redis = new Support\RedisFalsoComLua();
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();

        $this->simularComandoImagem(
            $telegram,
            $redis,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'))
        );

        $this->assertSame(
            ['Erro ao obter informações da imagem. Tente novamente! ❌'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertNull($redis->valorDe($chaveCota));
        $this->assertNull($redis->expiracaoDe($chaveCota));
    }

    /**
     * A cota diária é UMA só por usuário: a chave do Redis
     * (limite_diario:ID:AAAAMMDD) e o teto de 100 são os mesmos em
     * comandos/chatgpt.php, ver_audio.php e ver_imagem.php. Mandar foto não
     * pode ser um jeito de escapar do limite gasto com texto.
     *
     * Aqui 99 requisições de texto já foram feitas hoje; a centésima é
     * consumida por consumir_limite_diario() — a MESMA chamada que o comando de
     * texto faz — e a imagem seguinte precisa bater no limite.
     */
    public function testCotaGastaComTextoTambemBloqueiaAImagemDoMesmoUsuario(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();
        $redis->semear($chaveCota, '99');

        // A centésima requisição do dia, gasta pelo comando de TEXTO.
        $doTexto = consumir_limite_diario($redis, 777, 100);
        $this->assertFalse($doTexto['atingiu_limite'], 'A centésima de texto ainda é atendida.');

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('não deveria chegar aqui'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(
            ['Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame(0, $chat->chamadas, 'Imagem não pode burlar a cota gasta com texto.');
        $this->assertSame(['sendMessage'], $this->apisChamadas($telegram->chamadas));
    }

    /**
     * Borda exata da cota no fluxo de imagem: com 99 requisições consumidas, a
     * centésima ainda é atendida e leva o contador a 100. Um off-by-one aqui
     * (`<` no lugar de `<=`) tiraria uma requisição paga de cada usuário todo
     * dia, ou daria uma a mais de graça.
     */
    public function testCentesimaRequisicaoDoDiaAindaEAtendidaNaImagem(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();
        $chaveCota = 'limite_diario:777:' . limite_diario_dia_atual();
        $redis->semear($chaveCota, '99');

        $chat = new Support\TransporteHttpEspiao($this->corpoDeResposta('ainda dentro da cota'));

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(1, $chat->chamadas, 'A centésima requisição do dia ainda é paga.');
        $this->assertSame(['ainda dentro da cota'], $this->textosEnviados($telegram->chamadas));
        $this->assertSame('100', $redis->valorDe($chaveCota));
        // A chave já existia: o TTL não é reaplicado (o INCR só expira na criação).
        $this->assertNull($redis->expiracaoDe($chaveCota));
    }

    // ---------------------------------------------------------------------
    // Falhas da OpenAI
    // ---------------------------------------------------------------------

    public function testErroDaApiAvisaOUsuarioENaoGuardaRespostaDoAssistente(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();

        $chat = new Support\TransporteHttpEspiao(
            '{"error":{"message":"You uploaded an unsupported image","type":"invalid_request_error"}}'
        );

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertSame(
            [self::ERRO_GENERICO],
            $this->textosEnviados($telegram->chamadas)
        );
        // A legenda já havia sido gravada como contexto antes da chamada...
        $this->assertSame('O que você vê nesta imagem?', $redis->valorDe('contexto777'));
        // ...mas nenhuma resposta do assistente é registrada.
        $this->assertNull($redis->valorDe('assistente777'));
    }

    public function testAvisoDeErroRespondeAMensagemOriginalESemMarkdown(): void
    {
        // Todos os avisos do comando saem por avisarUsuarioImagem(): precisam
        // chegar no chat certo, respondendo à mensagem que gerou o erro e sem
        // formatação (o texto do erro não é Markdown).
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoImagem($telegram, $redis, new Support\TransporteHttpEspiao(false));

        $aviso = $telegram->chamadas[2] ?? null;
        $this->assertIsArray($aviso);
        $this->assertSame('sendMessage', $aviso['api']);
        $this->assertSame(
            [
                'chat_id' => 777,
                'text' => self::ERRO_GENERICO,
                'reply_parameters' => '{"message_id":10,"allow_sending_without_reply":true}',
            ],
            $aviso['content']
        );
    }

    public function testAvisoDeErroEmGrupoVaiParaOChatENaoParaOAutor(): void
    {
        // Em grupo o chat_id é diferente do id de quem mandou a foto: o aviso
        // precisa chegar no GRUPO, respondendo à mensagem original.
        $telegram = $this->telegramEspiao([
            'update_id' => 5,
            'message' => [
                'message_id' => 14,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'photo' => [['file_id' => 'foto-grande', 'width' => 1280]],
            ],
        ]);
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoImagem($telegram, $redis, new Support\TransporteHttpEspiao(false));

        $aviso = $telegram->chamadas[2] ?? null;
        $this->assertIsArray($aviso);
        $this->assertSame('sendMessage', $aviso['api']);
        $this->assertSame(
            [
                'chat_id' => -100500,
                'text' => self::ERRO_GENERICO,
                'reply_parameters' => '{"message_id":14,"allow_sending_without_reply":true}',
            ],
            $aviso['content']
        );
    }

    public function testTimeoutDaOpenAiAvisaOUsuarioSemEsperaReal(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();
        $inicio = microtime(true);

        // curl_exec devolve false em timeout/conexão recusada.
        $chat = new Support\TransporteHttpEspiao(false);

        $this->simularComandoImagem($telegram, $redis, $chat);

        $this->assertLessThan(1.0, microtime(true) - $inicio, 'Nenhuma espera real pode acontecer no teste.');
        $this->assertSame(1, $chat->chamadas);
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
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoImagem($telegram, $redis, new Support\TransporteHttpEspiao($corpo));

        $this->assertSame([self::ERRO_GENERICO], $this->textosEnviados($telegram->chamadas));
        $this->assertNull($redis->valorDe('assistente777'));
    }

    public function testFalhaDoMarkdownReenviaAMesmaRespostaSemFormatacao(): void
    {
        // A API do Telegram recusa a formatação Markdown (ok = false).
        $telegram = $this->telegramEspiao($this->updateComFoto(777), false);
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoImagem(
            $telegram,
            $redis,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('imagem com *destaque*'))
        );

        $this->assertSame(
            ['imagem com *destaque*', 'imagem com *destaque*'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame('Markdown', $telegram->chamadas[2]['content']['parse_mode'] ?? null);
        $this->assertArrayNotHasKey('parse_mode', $telegram->chamadas[3]['content']);
        $this->assertSame('imagem com *destaque*', $redis->valorDe('assistente777'));
    }

    public function testEntregaSemPermissaoNaoRepeteNemSalvaResposta(): void
    {
        // Uma recusa de permissão não é uma falha de formatação.
        $telegram = $this->telegramEspiao($this->updateComFoto(777), false, recusarEnvios: true);
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoImagem(
            $telegram,
            $redis,
            new Support\TransporteHttpEspiao($this->corpoDeResposta('imagem com *destaque*'))
        );

        $this->assertSame(
            ['imagem com *destaque*'],
            $this->textosEnviados($telegram->chamadas)
        );
        $this->assertSame('Markdown', $telegram->chamadas[2]['content']['parse_mode'] ?? null);
        $this->assertNull($redis->valorDe('assistente777'));
    }

    public function testRespostaMuitoLongaEEnviadaERegistradaJaRecortada(): void
    {
        $telegram = $this->telegramEspiao($this->updateComFoto(777));
        $redis = new Support\RedisFalsoComLua();

        $this->simularComandoImagem(
            $telegram,
            $redis,
            new Support\TransporteHttpEspiao($this->corpoDeResposta(str_repeat('a', 5000)))
        );

        $esperado = str_repeat('a', 4096);
        $this->assertSame([$esperado], $this->textosEnviados($telegram->chamadas));
        $this->assertSame($esperado, $redis->valorDe('assistente777'));
    }

    // ---------------------------------------------------------------------
    // Fidelidade do espelho
    // ---------------------------------------------------------------------
}
