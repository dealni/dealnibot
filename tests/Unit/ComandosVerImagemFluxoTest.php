<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes do FLUXO DE IMAGEM de comandos/ver_imagem.php exercitando o código de
 * produção: leitura da foto no update (maior resolução), montagem do payload de
 * visão enviado à OpenAI e interpretação de toda resposta possível.
 *
 * O script continua NÃO podendo ser incluído (o topo chama sendChatAction,
 * sendMessage e cURL reais e termina em exit); as funções são definidas com
 * Support\ExtratorDeFuncoesDeScript, que extrai só as declarações.
 *
 * Nenhuma rede é tocada: o transporte HTTP entra pelo parâmetro opcional, cujo
 * padrão continua sendo o cURL real. Este comando não grava arquivo nenhum — os
 * bytes são baixados em memória e enviados como data: URI; isso é travado na
 * fonte por testComandoDeImagemNaoCriaArquivoTemporario().
 */
final class ComandosVerImagemFluxoTest extends TestCase
{
    private const CAMINHO_SCRIPT = __DIR__ . '/../../comandos/ver_imagem.php';

    /** Onde o fluxo do comando mora desde que saiu do topo do script. */
    private const CAMINHO_IRMAO = __DIR__ . '/../../comandos/funcoes_ver_imagem.php';

    /** Prompt de desenvolvedor exato do comando de imagem (o sufixo é a data/hora). */
    private const PROMPT_DESENVOLVEDOR = "Você é Dealni, um gato inteligente do Telegram.\n"
        . "\n"
        . "CAPACIDADES:\n"
        . "- Processar mensagens de texto\n"
        . "- Analisar e descrever imagens\n"
        . "- Ouvir e transcrever áudios\n"
        . "\n"
        . "CONTEXTO: O usuário enviou uma IMAGEM. Você está vendo a imagem agora.\n"
        . "\n"
        . "INSTRUÇÕES:\n"
        . "- Responda no mesmo idioma do usuário\n"
        . "- Use emojis de forma natural e moderada\n"
        . "- Seja útil, amigável e direto\n"
        . "- Se algo não estiver visível ou claro na imagem, diga explicitamente\n"
        . "\n"
        . 'Data e hora em São Paulo: ';

    private const ERRO_GENERICO = "Erro ao analisar a imagem. Tente novamente! ❌";

    private const URL_IMAGEM = 'https://api.telegram.org/file/botTOKEN/photos/file_9.jpg';

    public static function setUpBeforeClass(): void
    {
        Support\ExtratorDeFuncoesDeScript::carregar(self::CAMINHO_SCRIPT, [
            'cortarMensagem',
            'imagemDaMensagem',
            'montarMensagensImagemComHistorico',
            'montarCorpoRequisicaoImagem',
            'chamarChatCompletionsImagem',
            'interpretarRespostaImagem',
            'baixarImagemComoDataUri',
            'limiteDeBytesDaImagem',
        ]);
    }

    // ---------------------------------------------------------------------
    // Imagem recebida no update
    // ---------------------------------------------------------------------

    public function testUsaAMaiorResolucaoDisponivelDaFoto(): void
    {
        // O Telegram manda os tamanhos em ordem crescente; o comando usa o último.
        $this->assertSame(
            ['tem_imagem' => true, 'file_id' => 'id-grande'],
            imagemDaMensagem([
                'photo' => [
                    ['file_id' => 'id-pequeno', 'width' => 90],
                    ['file_id' => 'id-medio', 'width' => 320],
                    ['file_id' => 'id-grande', 'width' => 1280],
                ],
            ])
        );
    }

    public function testFileIdIgualAZeroContinuaSendoUmIdentificadorValido(): void
    {
        // "0" é falsy em PHP: com empty($photo_file_id) no lugar do is_string()
        // a foto viraria "erro ao obter informações" sem nem pedir o arquivo.
        $this->assertSame(
            ['tem_imagem' => true, 'file_id' => '0'],
            imagemDaMensagem(['photo' => [['file_id' => '0', 'width' => 90]]])
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function mensagensSemFotoProvider(): array
    {
        return [
            'mensagem de texto' => [['text' => 'oi']],
            'photo vazio' => [['photo' => []]],
            'photo de tipo errado' => [['photo' => 'nao e array']],
            'mensagem vazia' => [[]],
        ];
    }

    /**
     * @param array<string, mixed> $mensagem
     */
    #[DataProvider('mensagensSemFotoProvider')]
    public function testMensagemSemFotoEIdentificadaAntesDeQualquerChamada(array $mensagem): void
    {
        $this->assertSame(
            ['tem_imagem' => false, 'file_id' => null],
            imagemDaMensagem($mensagem)
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function fotosSemFileIdProvider(): array
    {
        return [
            'tamanho sem file_id' => [['photo' => [['width' => 90]]]],
            'file_id numerico' => [['photo' => [['file_id' => 12345]]]],
            'tamanho que nao e array' => [['photo' => ['apenas-um-texto']]],
        ];
    }

    /**
     * Foto presente mas sem identificador utilizável: o comando avisa o usuário
     * em vez de pedir o arquivo ao Telegram com um id inválido.
     *
     * @param array<string, mixed> $mensagem
     */
    #[DataProvider('fotosSemFileIdProvider')]
    public function testFotoSemFileIdUtilizavelNaoViraChamadaAoTelegram(array $mensagem): void
    {
        $this->assertSame(
            ['tem_imagem' => true, 'file_id' => null],
            imagemDaMensagem($mensagem)
        );
    }

    // ---------------------------------------------------------------------
    // Download da imagem: o TOKEN_BOT não pode sair daqui
    // ---------------------------------------------------------------------

    /** JPEG mínimo: só a assinatura importa para o reconhecimento. */
    private const JPEG = "\xFF\xD8\xFF\xE0conteudo-da-foto";

    /** GIF mínimo válido com dois descritores de imagem (dois quadros). */
    private const GIF_ANIMADO =
        "GIF89a\x01\x00\x01\x00\x00\x00\x00"
        . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x4C\x01\x00"
        . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x4C\x01\x00"
        . "\x3B";

    // O parser de GIF e o reconhecimento de MIME pelos bytes vivem em
    // App\Support\FormatoDeImagem e são provados em FormatoDeImagemTest; aqui
    // fica o que o COMANDO faz com o resultado.

    /**
     * A URL de download do Telegram é
     * `https://api.telegram.org/file/bot<TOKEN_BOT>/<file_path>`. Enviá-la como
     * `image_url.url` fazia o TOKEN_BOT COMPLETO viajar para a OpenAI em toda
     * mensagem com foto, e ficar no que quer que ela retenha — quem tem esse
     * valor lê todas as mensagens do bot, escreve como ele e troca o webhook.
     *
     * Este teste FALHA com o comportamento anterior: lá o payload levava a URL
     * (com o token dentro) em vez do data: URI.
     */
    public function testTokenDoBotNaoAcompanhaAImagemEnviadaAOpenAi(): void
    {
        $urlComToken = 'https://api.telegram.org/file/bot123456:SEGREDO_DO_BOT/photos/file_9.jpg';

        $baixada = baixarImagemComoDataUri($urlComToken, static fn (): string => self::JPEG);

        $this->assertNull($baixada['erro']);
        // O assertNull acima já narrowa o shape de retorno para o ramo de
        // sucesso: aqui data_uri é string, e uma asserção de não-nulo seria
        // sempre verdadeira.
        $dataUri = $baixada['data_uri'];
        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUri);

        $payload = montarCorpoRequisicaoImagem(
            montarMensagensImagemComHistorico('o que é isso?', $baixada['data_uri'], [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']], '01/02/2026 03:04:05')
        );
        $this->assertIsString($payload);

        // Nada do token, nem do host de download, pode aparecer no corpo.
        $this->assertStringNotContainsString('SEGREDO_DO_BOT', $payload);
        $this->assertStringNotContainsString('123456:', $payload);
        $this->assertStringNotContainsString('api.telegram.org', $payload);

        // O que a OpenAI recebe no lugar: os bytes da foto, em base64. A
        // comparação é sobre o JSON DECODIFICADO — json_encode escapa a barra
        // do MIME (`image\/jpeg`), e uma asserção sobre o texto cru mediria o
        // escape em vez do conteúdo.
        $decodificado = json_decode($payload, true);
        $this->assertIsArray($decodificado);
        $mensagens = $decodificado['messages'] ?? null;
        $this->assertIsArray($mensagens);
        $mensagemDaImagem = $mensagens[1] ?? null;
        $this->assertIsArray($mensagemDaImagem);
        $conteudo = $mensagemDaImagem['content'] ?? null;
        $this->assertIsArray($conteudo);
        $bloco = $conteudo[1] ?? null;
        $this->assertIsArray($bloco);
        $imagem = $bloco['image_url'] ?? null;
        $this->assertIsArray($imagem);
        $this->assertSame($dataUri, $imagem['url'] ?? null);
    }

    public function testGifAnimadoERecusadoAntesDeVirarChamadaPaga(): void
    {
        $baixada = baixarImagemComoDataUri(
            'https://api.telegram.org/file/botTOKEN/photos/file_9.gif',
            static fn (): string => self::GIF_ANIMADO
        );

        $this->assertNull($baixada['data_uri']);
        $this->assertSame(
            "Não reconheci o formato dessa imagem. Tente JPEG, PNG, GIF ou WebP! 🖼️",
            $baixada['erro']
        );
    }

    public function testFormatoDesconhecidoAvisaOUsuarioEmVezDeVirarChamadaPaga(): void
    {
        $baixada = baixarImagemComoDataUri(
            'https://api.telegram.org/file/botTOKEN/photos/file_9.jpg',
            static fn (): string => '<html>502 Bad Gateway</html>'
        );

        $this->assertNull($baixada['data_uri']);
        $this->assertSame(
            "Não reconheci o formato dessa imagem. Tente JPEG, PNG, GIF ou WebP! 🖼️",
            $baixada['erro']
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function downloadsQueFalharamProvider(): array
    {
        return [
            'transporte devolveu false' => [false],
            'corpo vazio' => [''],
            'corpo nao textual' => [null],
        ];
    }

    /**
     * Comparação com '' e não empty(): o mesmo cuidado de
     * baixarAudioParaArquivoTemporario(). Corpo vazio é download falho.
     *
     * @param mixed $devolvido
     */
    #[DataProvider('downloadsQueFalharamProvider')]
    public function testDownloadFalhoAvisaOUsuarioENaoMontaDataUri($devolvido): void
    {
        $baixada = baixarImagemComoDataUri(
            'https://api.telegram.org/file/botTOKEN/photos/file_9.jpg',
            static fn (): mixed => $devolvido
        );

        $this->assertNull($baixada['data_uri']);
        $this->assertSame("Erro ao baixar a imagem. Tente novamente! ❌", $baixada['erro']);
    }

    /**
     * O teto existe porque o base64 custa 4/3 do binário e a visão da OpenAI
     * recusa requisição acima de 20 MB: sem ele, uma imagem grande viraria uma
     * chamada PAGA garantidamente recusada. O download lê UM byte além do teto
     * justamente para poder distinguir "no limite" de "passou".
     */
    public function testImagemAcimaDoTetoNaoViraDataUri(): void
    {
        $limite = limiteDeBytesDaImagem();
        $this->assertSame(10 * 1024 * 1024, $limite);

        $acima = "\xFF\xD8\xFF" . str_repeat('a', $limite - 2);
        $this->assertSame($limite + 1, strlen($acima));

        $baixada = baixarImagemComoDataUri(
            'https://api.telegram.org/file/botTOKEN/photos/file_9.jpg',
            static fn (): string => $acima
        );

        $this->assertNull($baixada['data_uri']);
        $this->assertSame(
            "Essa imagem é grande demais para eu analisar. Envie uma menor! 🖼️",
            $baixada['erro']
        );
    }

    public function testImagemExatamenteNoTetoAindaEAceita(): void
    {
        $limite = limiteDeBytesDaImagem();
        $noLimite = "\xFF\xD8\xFF" . str_repeat('a', $limite - 3);
        $this->assertSame($limite, strlen($noLimite));

        $baixada = baixarImagemComoDataUri(
            'https://api.telegram.org/file/botTOKEN/photos/file_9.jpg',
            static fn (): string => $noLimite
        );

        $this->assertNull($baixada['erro']);
        $this->assertSame('data:image/jpeg;base64,' . base64_encode($noLimite), $baixada['data_uri']);
    }

    // ---------------------------------------------------------------------
    // Payload de visão enviado à OpenAI
    // ---------------------------------------------------------------------

    public function testPayloadDaImagemLevaLegendaEUrlEmAltaResolucao(): void
    {
        $mensagens = montarMensagensImagemComHistorico('O que você vê nesta imagem?', self::URL_IMAGEM, [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']], '01/02/2026 03:04:05');

        $this->assertSame(
            [
                ['role' => 'developer', 'content' => self::PROMPT_DESENVOLVEDOR . '01/02/2026 03:04:05'],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'O que você vê nesta imagem?'],
                        [
                            'type' => 'image_url',
                            'image_url' => ['url' => self::URL_IMAGEM, 'detail' => 'high'],
                        ],
                    ],
                ],
            ],
            $mensagens
        );
    }

    public function testPayloadDaImagemMantemAOrdemDeveloperUsuarioAssistenteAtual(): void
    {
        $mensagens = montarMensagensImagemComHistorico('e esta?', self::URL_IMAGEM, [['role' => 'user', 'content' => 'pergunta anterior'], ['role' => 'assistant', 'content' => 'resposta anterior']], '01/02/2026 03:04:05');

        $this->assertCount(4, $mensagens);
        $this->assertSame('developer', $mensagens[0]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'pergunta anterior'], $mensagens[1]);
        $this->assertSame(['role' => 'assistant', 'content' => 'resposta anterior'], $mensagens[2]);
        $this->assertSame('user', $mensagens[3]['role']);
        // A imagem vai sempre na ÚLTIMA mensagem, junto da legenda atual.
        $this->assertSame(
            [
                ['type' => 'text', 'text' => 'e esta?'],
                ['type' => 'image_url', 'image_url' => ['url' => self::URL_IMAGEM, 'detail' => 'high']],
            ],
            $mensagens[3]['content']
        );
    }

    public function testPayloadDaImagemIgnoraContextoVazio(): void
    {
        $mensagens = montarMensagensImagemComHistorico('atual', self::URL_IMAGEM, [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => 'só a resposta anterior']], '01/02/2026 03:04:05');

        $this->assertCount(3, $mensagens);
        $this->assertSame(['role' => 'assistant', 'content' => 'só a resposta anterior'], $mensagens[1]);
    }

    /**
     * Contexto e resposta anterior iguais a "0" são falsy em PHP: com
     * !empty() no lugar da comparação com '' as duas mensagens sumiam do
     * payload e o modelo analisava a imagem sem o que já foi dito. É o mesmo
     * bug que o commit 341c19d corrigiu no conteúdo da resposta — este ramo
     * tinha ficado sem teste, e uma volta para empty() passaria em silêncio.
     */
    public function testContextoIgualAZeroContinuaNoPayloadDaImagem(): void
    {
        $mensagens = montarMensagensImagemComHistorico('e esta?', self::URL_IMAGEM, [['role' => 'user', 'content' => '0'], ['role' => 'assistant', 'content' => '0']], '01/02/2026 03:04:05');

        $this->assertCount(4, $mensagens);
        $this->assertSame(['role' => 'user', 'content' => '0'], $mensagens[1]);
        $this->assertSame(['role' => 'assistant', 'content' => '0'], $mensagens[2]);
    }

    public function testRelogioPadraoDaImagemUsaAHoraAtualNoFormatoDoComando(): void
    {
        $mensagens = montarMensagensImagemComHistorico('oi', self::URL_IMAGEM, [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']]);
        $desenvolvedor = $mensagens[0]['content'] ?? '';

        $this->assertIsString($desenvolvedor);
        $this->assertStringStartsWith(self::PROMPT_DESENVOLVEDOR, $desenvolvedor);
        $this->assertMatchesRegularExpression(
            '/Data e hora em São Paulo: \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/',
            $desenvolvedor
        );
    }

    public function testLegendaMuitoLongaERecortadaNoUltimoEspacoAntesDe1024(): void
    {
        // Limite de caption do Telegram, aplicado antes de a legenda entrar no
        // payload. Uma legenda real tem espaços: o corte precisa cair no último
        // deles, sem partir a palavra e sem passar de 1024.
        $legenda = str_repeat('palavra ', 200); // 1600 caracteres

        $recortada = cortarMensagem($legenda, 1024);

        // "palavra " tem 8 caracteres, então o corte duro em 1024 cai exatamente
        // no espaço que fecha a 128ª repetição; o corte no último espaço tira
        // esse espaço final e a legenda sai com 1023 caracteres, sem palavra
        // partida ao meio.
        $this->assertSame(rtrim(str_repeat('palavra ', 128)), $recortada);
        $this->assertSame(1023, mb_strlen($recortada, 'UTF-8'));
        $this->assertStringEndsWith('palavra', $recortada);
    }

    /**
     * Contraprova do teste acima: com o limite caindo DENTRO de uma palavra, o
     * corte volta para o espaço anterior em vez de entregar meia palavra. Com o
     * corte duro (sem o mb_strrpos) a legenda terminaria em "pala".
     */
    public function testLegendaERecortadaSemPartirPalavraAoMeio(): void
    {
        $recortada = cortarMensagem(str_repeat('palavra ', 200), 1020);

        $this->assertSame(rtrim(str_repeat('palavra ', 127)), $recortada);
        $this->assertSame(1015, mb_strlen($recortada, 'UTF-8'));
        $this->assertStringEndsWith('palavra', $recortada);
    }

    public function testCorpoDaRequisicaoDaImagemLevaModeloLimitesEMensagens(): void
    {
        $mensagens = montarMensagensImagemComHistorico('oi', self::URL_IMAGEM, [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']], '01/02/2026 03:04:05');
        $corpo = montarCorpoRequisicaoImagem($mensagens);
        $this->assertIsString($corpo, 'O corpo da requisição precisa ser gerado.');

        $dados = json_decode($corpo, true);
        $this->assertIsArray($dados);
        $this->assertSame('openai/gpt-6-luna', $dados['model'] ?? null);
        $this->assertSame(1, $dados['temperature'] ?? null);
        $this->assertSame(['allow_fallbacks' => true, 'data_collection' => 'deny', 'ignore' => ['openai']], $dados['provider'] ?? null);
        $this->assertSame(2048, $dados['max_completion_tokens'] ?? null);
        $this->assertSame(['effort' => 'xhigh', 'exclude' => true], $dados['reasoning'] ?? null);
        $this->assertSame($mensagens, $dados['messages'] ?? null);
    }

    public function testCorpoDaRequisicaoDaImagemDevolveFalseQuandoOJsonFalha(): void
    {
        // Legenda com byte inválido em UTF-8: json_encode falha e o comando
        // avisa o usuário em vez de enviar corpo vazio à OpenAI.
        $this->assertFalse(
            montarCorpoRequisicaoImagem(montarMensagensImagemComHistorico("\xB1\x31", self::URL_IMAGEM, [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']]))
        );
    }

    public function testChamadaDaImagemEnviaUrlCorpoECabecalhosComAChaveDaApi(): void
    {
        $transporte = new Support\TransporteHttpEspiao('{"choices":[]}');

        $resposta = chamarChatCompletionsImagem(
            'https://openrouter.ai/api/v1/chat/completions',
            'sk-teste',
            '{"model":"openai/gpt-6-luna"}',
            $transporte
        );

        $this->assertSame(1, $transporte->chamadas);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $transporte->url);
        $this->assertSame('{"model":"openai/gpt-6-luna"}', $transporte->corpo);
        $this->assertSame(
            ['Content-Type: application/json', 'Authorization: Bearer sk-teste'],
            $transporte->cabecalhos
        );
        $this->assertSame('{"choices":[]}', $resposta);
    }

    public function testTimeoutDaImagemViraFalseSemEsperaReal(): void
    {
        $inicio = microtime(true);

        $resposta = chamarChatCompletionsImagem(
            'https://openrouter.ai/api/v1/chat/completions',
            'sk-teste',
            '{}',
            new Support\TransporteHttpEspiao(false)
        );

        $this->assertFalse($resposta);
        $this->assertLessThan(1.0, microtime(true) - $inicio, 'O teste não pode esperar de verdade.');
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaImagem($resposta)
        );
    }

    // ---------------------------------------------------------------------
    // Interpretação da resposta
    // ---------------------------------------------------------------------

    public function testRespostaValidaDaImagemDevolveADescricao(): void
    {
        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => 'Vejo um gato preto 🐈‍⬛'],
            interpretarRespostaImagem('{"choices":[{"message":{"content":"Vejo um gato preto 🐈‍⬛"}}]}')
        );
    }

    public function testRespostaValidaDaImagemUsaApenasAPrimeiraEscolha(): void
    {
        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => 'primeira'],
            interpretarRespostaImagem(
                '{"choices":[{"message":{"content":"primeira"}},{"message":{"content":"segunda"}}]}'
            )
        );
    }

    public function testRespostaValidaDaImagemMuitoLongaERecortada(): void
    {
        $corpo = json_encode(['choices' => [['message' => ['content' => str_repeat('a', 5000)]]]]);
        $this->assertIsString($corpo);

        $interpretada = interpretarRespostaImagem($corpo);

        $this->assertSame('resposta', $interpretada['tipo']);
        $this->assertSame(str_repeat('a', 4096), $interpretada['texto']);
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
     * Resposta inválida, malformada ou sem conteúdo cai sempre na mensagem de
     * erro do comando de imagem — nunca em texto vazio para o Telegram.
     *
     * @param mixed $corpo
     */
    #[DataProvider('respostasSemConteudoProvider')]
    public function testRespostaInvalidaDaImagemViraMensagemGenericaDeErro($corpo): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaImagem($corpo)
        );
    }

    public function testContentZeroNaImagemEUmaRespostaValida(): void
    {
        // "0" é falsy em PHP: com empty() a resposta legítima do modelo virava
        // "erro ao analisar a imagem".
        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => '0'],
            interpretarRespostaImagem('{"choices":[{"message":{"content":"0"}}]}')
        );
    }

    public function testErroDaApiNaImagemEnviaAMensagemDevolvidaPelaOpenAi(): void
    {
        $this->assertSame(
            ['tipo' => 'erro_api', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaImagem('{"error":{"message":"You uploaded an unsupported image"}}')
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

    #[DataProvider('errosSemMensagemUtilProvider')]
    public function testErroDaApiNaImagemSemMensagemUtilEnviaSomenteOPrefixo(string $corpo): void
    {
        $this->assertSame(
            ['tipo' => 'erro_api', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaImagem($corpo)
        );
    }

    public function testErroDaApiNaImagemTemPrioridadeSobreOConteudo(): void
    {
        $this->assertSame(
            ['tipo' => 'erro_api', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaImagem(
                '{"error":{"message":"quota"},"choices":[{"message":{"content":"ignorado"}}]}'
            )
        );
    }

    public function testErroNuloNaRespostaDaImagemNaoBloqueiaOConteudo(): void
    {
        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => 'tudo certo'],
            interpretarRespostaImagem('{"error":null,"choices":[{"message":{"content":"tudo certo"}}]}')
        );
    }

    // ---------------------------------------------------------------------
    // Arquivos temporários
    // ---------------------------------------------------------------------

    /**
     * A guarda de contexto é a primeira coisa do script, e o fluxo inteiro só
     * roda depois dela.
     *
     * Desde que o corpo saiu para executarComandoVerImagem(), o invariante ficou mais
     * simples de afirmar do que quando tudo morava no mesmo arquivo: a guarda
     * está no script, a chamada vem depois dela, e todo efeito está do outro
     * lado — dentro da função, que a guarda decide se chega a ser chamada.
     */
    public function testComandoDeImagemExigeContextoValidoAntesDeQualquerEfeito(): void
    {
        $script = (string) file_get_contents(self::CAMINHO_SCRIPT);
        $this->assertNotSame('', $script, 'O script do comando precisa ser legível.');

        $guarda = "if (!isset(\$telegram, \$redis) || !\$telegram instanceof Telegram || !\$redis instanceof Redis) {\n"
            . "    throw new LogicException('Contexto inválido para o comando de imagem.');\n"
            . '}';
        $this->assertStringContainsString($guarda, $script);

        $posicaoGuarda = strpos($script, $guarda);
        $posicaoChamada = strpos($script, 'executarComandoVerImagem($telegram, $redis);');
        $this->assertIsInt($posicaoGuarda);
        $this->assertIsInt($posicaoChamada, 'O script precisa chamar executarComandoVerImagem().');
        $this->assertGreaterThan(
            $posicaoGuarda,
            $posicaoChamada,
            'A guarda de contexto precisa vir antes da chamada do fluxo.'
        );

        // E os efeitos continuam todos do lado de lá da guarda.
        $fluxo = $this->fonteDoComando();
        foreach (['$telegram->UserID()', 'iniciarRespostaIa', 'consumir_limite_diario', 'caminhoDoArquivoDeMidia('] as $efeito) {
            $this->assertStringContainsString($efeito, $fluxo, "O comando deixou de conter: {$efeito}");
        }
    }

    public function testComandoDeImagemNaoCriaArquivoTemporario(): void
    {
        // A imagem passou a ser BAIXADA (a URL do Telegram carrega o TOKEN_BOT
        // e não podia ir para a OpenAI), mas continua vivendo só em memória: o
        // base64 do data: URI some com a requisição. O arquivo de funções entra
        // na varredura junto com o comando justamente porque o download mora
        // lá — sem isso a garantia teria migrado para fora do que se verifica.
        $fonte = $this->fonteDoComando() . "\n" . $this->fonteDasFuncoes();

        $this->assertStringNotContainsString('sys_get_temp_dir', $fonte);
        $this->assertStringNotContainsString('file_put_contents', $fonte);
        $this->assertStringNotContainsString('tempnam', $fonte);
        $this->assertStringNotContainsString('unlink', $fonte);
        $this->assertStringNotContainsString('fopen', $fonte);
        $this->assertStringNotContainsString('CURLFile', $fonte);
    }

    /**
     * Lista fechada do que o comando de imagem pode chamar. A verificação por
     * nomes proibidos acima só pega as primitivas que alguém lembrou de listar:
     * baixar a imagem com fopen()/fwrite() (ou copy(), tmpfile(), rename()...)
     * criaria um temporário que nenhum caminho de erro remove e passaria por
     * ela em silêncio. Aqui qualquer chamada NOVA reprova, então a única forma
     * de o comando voltar a gravar em disco é alguém alterar este teste junto.
     */
    public function testComandoDeImagemSoChamaFuncoesSemEfeitoEmDisco(): void
    {
        $permitidas = [
            // Funções do próprio comando
            'admitirMidiaDoComando',
            'caminhoDoArquivoDeMidia',
            'mensagemRecebidaDeMidia',
            'tipoDeChatDaMidia',
            'responderMidiaImagem',
            'avisarUsuarioImagem',
            // Baixa a imagem em MEMÓRIA e devolve data: base64 — nada em disco.
            // Está aqui porque mandar a URL do Telegram entregava o TOKEN_BOT
            // completo à OpenAI; ver baixarImagemComoDataUri().
            'baixarImagemComoDataUri',
            'chamarChatCompletionsImagem',
            'chaveMemoriaConversa',
            'chavesLegadasDaConversa',
            'cortarMensagem',
            'idTopicoDaMensagem',
            'imagemDaMensagem',
            'interpretarRespostaImagem',
            'lerHistoricoMemoria',
            'montarCorpoRequisicaoImagem',
            'montarMensagensImagemComHistorico',
            'montarMensagensImagemComHistorico',
            'salvarTurnoMemoria',
            'enviarRespostaIa',
            // Só prepara metadados em memória; persistência pertence à entrega.
            'memorizar',
            'iniciarRespostaIa',
            // Cota diária (inclusos/limites_requisicoes.php)
            'admitir_midia',
            'consumir_limite_diario',
            'limite_diario_sujeito',
            'salvarTextoComTtl',
            'ttlMemoriaConversa',
            // Chamada HTTP à OpenAI: a imagem vai por URL, nada é gravado
            'curl_exec',
            'curl_init',
            'curl_setopt',
            // Utilitários puros
            'count',
            'date',
            'date_default_timezone_set',
            'end',
            'is_array',
            'is_scalar',
            'is_string',
            'json_decode',
            'json_encode',
            'lerTextoDoRedis',
            'mb_strlen',
            'mb_strrpos',
            'mb_substr',
            'rand',
            'str_replace',
            'str_starts_with',
            'new LogicException',
        ];

        $inesperadas = array_values(
            array_diff(
                array_merge(Support\EstruturaDeScript::funcoesChamadas(self::CAMINHO_IRMAO, 'executarComandoVerImagem'), Support\EstruturaDeScript::funcoesChamadas(self::CAMINHO_IRMAO, 'responderMidiaImagem')),
                $permitidas
            )
        );

        $this->assertSame(
            [],
            $inesperadas,
            'comandos/ver_imagem.php passou a chamar algo novo; se grava em disco, o temporário vaza nos erros.'
        );
    }

    /**
     * Fluxo do comando, desindentado.
     *
     * O corpo saiu do topo do script para executarComandoVerImagem(), no
     * funcoes_ver_imagem.php irmão, em 2026-08-24: script de topo executa no
     * include e nenhum teste podia incluí-lo, então tudo que morasse lá era
     * código de produção sem cobertura possível. A única diferença de FORMA é
     * um nível de indentação a mais — as agulhas abaixo descrevem a estrutura
     * do fluxo, não a profundidade em que ele está aninhado.
     */
    private function fonteDoComando(): string
    {
        $corpo = Support\EstruturaDeScript::corpoDaFuncao(self::CAMINHO_IRMAO, 'executarComandoVerImagem');

        return implode("\n", array_map(
            static fn (string $linha): string => str_starts_with($linha, '    ')
                ? substr($linha, 4)
                : $linha,
            explode("\n", $corpo)
        ));
    }

    private function fonteDasFuncoes(): string
    {
        $fontes = [];
        foreach (['funcoes_ver_imagem.php', 'funcoes_arquivo_imagem.php', 'funcoes_admissao_midia.php', 'funcoes_respostas_ia.php'] as $arquivo) {
            $fonte = file_get_contents(__DIR__ . '/../../comandos/' . $arquivo);
            $this->assertIsString($fonte, $arquivo . ' precisa ser legível.');
            $fontes[] = $fonte;
        }
        return implode("\n", $fontes);
    }
}
