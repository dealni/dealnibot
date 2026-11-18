<?php

declare(strict_types=1);

namespace Tests;

use CURLFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes do FLUXO DE ÁUDIO de comandos/ver_audio.php exercitando o código de
 * produção: leitura do áudio no update, download para arquivo temporário,
 * upload ao Whisper, interpretação da transcrição, montagem do payload de
 * chat/completions e interpretação de toda resposta possível.
 *
 * O script continua NÃO podendo ser incluído (o topo chama sendChatAction,
 * sendMessage, file_get_contents e cURL reais e termina em exit); as funções
 * são definidas com Support\ExtratorDeFuncoesDeScript, que extrai só as
 * declarações.
 *
 * Nenhuma rede é tocada: download e transporte HTTP entram pelos parâmetros
 * opcionais, cujos padrões continuam sendo file_get_contents e o cURL real.
 * Nenhum arquivo é criado fora de sys_get_temp_dir().
 */
final class ComandosVerAudioFluxoTest extends TestCase
{
    private const CAMINHO_SCRIPT = __DIR__ . '/../../comandos/ver_audio.php';

    /** Onde o fluxo do comando mora desde que saiu do topo do script. */
    private const CAMINHO_IRMAO = __DIR__ . '/../../comandos/funcoes_ver_audio.php';

    /** Prompt de desenvolvedor exato do comando de áudio (o sufixo é a data/hora). */
    private const PROMPT_DESENVOLVEDOR = "Você é Dealni, um gato inteligente do Telegram.\n"
        . "\n"
        . "CAPACIDADES:\n"
        . "- Processar mensagens de texto\n"
        . "- Analisar e descrever imagens\n"
        . "- Ouvir e transcrever áudios\n"
        . "\n"
        . "CONTEXTO: O usuário enviou um ÁUDIO que foi transcrito automaticamente."
        . " Você está escutando o usuário.\n"
        . "\n"
        . "INSTRUÇÕES:\n"
        . "- Responda no mesmo idioma do usuário\n"
        . "- Use emojis de forma natural e moderada\n"
        . "- Seja útil, amigável e direto\n"
        . "\n"
        . 'Data e hora em São Paulo: ';

    private const ERRO_GENERICO = "Erro ao gerar resposta. Tente novamente! ❌";

    private const ERRO_TRANSCRICAO = "Erro ao transcrever o áudio. Tente novamente! ❌";

    /** @var array<int, string> Arquivos temporários criados por um teste. */
    private array $temporarios = [];

    public static function setUpBeforeClass(): void
    {
        Support\ExtratorDeFuncoesDeScript::carregar(self::CAMINHO_SCRIPT, [
            'cortarMensagem',
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

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        $this->temporarios = [];
        Support\DiscoQueFalhaNaEscrita::remover();
    }

    public function testAudioNoTetoLocalEPermitidoEOExcessoNaoCriaTemporario(): void
    {
        $resultado = baixarAudioParaArquivoTemporario(
            'https://api.telegram.org/file/teste',
            static fn (): string => str_repeat('a', limiteDeBytesDoAudio())
        );
        $this->assertNotNull($resultado['caminho']);
        $this->temporarios[] = $resultado['caminho'];
        $this->assertSame(limiteDeBytesDoAudio(), filesize($resultado['caminho']));

        $criacoes = 0;
        $excesso = baixarAudioParaArquivoTemporario(
            'https://api.telegram.org/file/teste',
            static fn (): string => str_repeat('a', limiteDeBytesDoAudio() + 1),
            null,
            static function () use (&$criacoes): false {
                $criacoes++;
                return false;
            }
        );
        $this->assertNull($excesso['caminho']);
        $this->assertSame('Esse áudio é grande demais. Envie até 20 MiB. 🎤', $excesso['erro']);
        $this->assertSame(0, $criacoes);
    }

    public function testDownloadPadraoDoAudioLeFixtureLocalComTeto(): void
    {
        $entrada = tempnam(sys_get_temp_dir(), 'dealnibot_audio_fixture_');
        $this->assertIsString($entrada);
        $this->temporarios[] = $entrada;
        file_put_contents($entrada, 'audio-local');
        $resultado = baixarAudioParaArquivoTemporario('file://' . $entrada);
        $this->assertNotNull($resultado['caminho']);
        $this->temporarios[] = $resultado['caminho'];
        $this->assertSame('audio-local', file_get_contents($resultado['caminho']));

        file_put_contents($entrada, str_repeat('a', limiteDeBytesDoAudio() + 1));
        $resultado = baixarAudioParaArquivoTemporario('file://' . $entrada);
        $this->assertNull($resultado['caminho']);
        $this->assertSame('Esse áudio é grande demais. Envie até 20 MiB. 🎤', $resultado['erro']);
    }

    // ---------------------------------------------------------------------
    // Áudio recebido no update
    // ---------------------------------------------------------------------

    public function testVoiceTemPrioridadeSobreOArquivoDeAudio(): void
    {
        $this->assertSame(
            ['tem_audio' => true, 'file_id' => 'id-do-voice'],
            audioDaMensagem([
                'voice' => ['file_id' => 'id-do-voice', 'duration' => 3],
                'audio' => ['file_id' => 'id-do-arquivo'],
            ])
        );
    }

    public function testArquivoDeAudioEUsadoQuandoNaoHaVoice(): void
    {
        $this->assertSame(
            ['tem_audio' => true, 'file_id' => 'id-do-arquivo'],
            audioDaMensagem(['audio' => ['file_id' => 'id-do-arquivo', 'duration' => 9]])
        );
    }

    public function testFileIdIgualAZeroContinuaSendoUmIdentificadorValido(): void
    {
        // "0" é falsy em PHP: com empty($audio_file_id) no lugar do is_string()
        // o áudio viraria "erro ao obter informações" sem nem pedir o arquivo.
        $this->assertSame(
            ['tem_audio' => true, 'file_id' => '0'],
            audioDaMensagem(['voice' => ['file_id' => '0', 'duration' => 1]])
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function mensagensSemAudioProvider(): array
    {
        return [
            'mensagem de texto' => [['text' => 'oi']],
            'voice vazio' => [['voice' => []]],
            'audio vazio' => [['audio' => []]],
            'voice de tipo errado' => [['voice' => 'nao e array']],
            'mensagem vazia' => [[]],
        ];
    }

    /**
     * @param array<string, mixed> $mensagem
     */
    #[DataProvider('mensagensSemAudioProvider')]
    public function testMensagemSemAudioEIdentificadaAntesDeQualquerChamada(array $mensagem): void
    {
        $this->assertSame(
            ['tem_audio' => false, 'file_id' => null],
            audioDaMensagem($mensagem)
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function audiosSemFileIdProvider(): array
    {
        return [
            'voice sem file_id' => [['voice' => ['duration' => 3]]],
            'audio sem file_id' => [['audio' => ['duration' => 3]]],
            'file_id numerico' => [['voice' => ['file_id' => 12345]]],
            'file_id nulo' => [['audio' => ['file_id' => null, 'duration' => 1]]],
        ];
    }

    /**
     * Áudio presente mas sem identificador utilizável: o comando avisa o
     * usuário em vez de pedir o arquivo ao Telegram com um id inválido.
     *
     * @param array<string, mixed> $mensagem
     */
    #[DataProvider('audiosSemFileIdProvider')]
    public function testAudioSemFileIdUtilizavelNaoViraDownload(array $mensagem): void
    {
        $this->assertSame(
            ['tem_audio' => true, 'file_id' => null],
            audioDaMensagem($mensagem)
        );
    }

    public function testLimiteDeSegundosDoAudioEhTresMinutos(): void
    {
        $this->assertSame(180, limiteDeSegundosDoAudio());
    }

    public function testDuracaoDoAudioExtraiSegundosDeVoiceOuAudio(): void
    {
        $this->assertSame(45, duracaoDoAudio(['voice' => ['duration' => 45]]));
        $this->assertSame(120, duracaoDoAudio(['audio' => ['duration' => 120]]));
        $this->assertSame(15, duracaoDoAudio(['voice' => ['duration' => 15], 'audio' => ['duration' => 99]]));
        $this->assertNull(duracaoDoAudio(['voice' => ['duration' => 'invalido']]));
        $this->assertNull(duracaoDoAudio([]));
    }

    // ---------------------------------------------------------------------
    // Download e arquivo temporário
    // ---------------------------------------------------------------------

    public function testDownloadGravaOAudioEmArquivoTemporarioNoDiretorioDoSistema(): void
    {
        $resultado = baixarAudioParaArquivoTemporario(
            'https://api.telegram.org/file/botTOKEN/voice/file_1.oga',
            static fn (): string => 'bytes-do-audio'
        );

        $caminho = $resultado['caminho'];
        $this->assertIsString($caminho, 'O download bem-sucedido precisa devolver o caminho.');
        $this->temporarios[] = $caminho;

        $this->assertNull($resultado['erro']);
        // O arquivo nasce de forma exclusiva no diretório temporário, com nome
        // imprevisível e sem depender de extensão para o MIME do upload.
        $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($caminho)));
        $this->assertStringStartsWith('dealnibot_audio_', basename($caminho));
        $this->assertFileExists($caminho);
        $this->assertSame('bytes-do-audio', file_get_contents($caminho));
        $permissoes = fileperms($caminho);
        $this->assertIsInt($permissoes);
        $this->assertSame('600', decoct($permissoes & 0777));
    }

    public function testDownloadRecebeAUrlDoArquivoNoTelegram(): void
    {
        $recebida = null;
        $resultado = baixarAudioParaArquivoTemporario(
            'https://api.telegram.org/file/botTOKEN/voice/file_1.oga',
            static function (string $url) use (&$recebida): string {
                $recebida = $url;

                return 'bytes';
            }
        );

        $caminho = $resultado['caminho'];
        $this->assertIsString($caminho);
        $this->temporarios[] = $caminho;

        $this->assertSame('https://api.telegram.org/file/botTOKEN/voice/file_1.oga', $recebida);
    }

    public function testCadaDownloadUsaUmArquivoTemporarioDiferente(): void
    {
        $primeiro = baixarAudioParaArquivoTemporario('https://t.me/a.oga', static fn (): string => 'a');
        $segundo = baixarAudioParaArquivoTemporario('https://t.me/a.oga', static fn (): string => 'b');

        $this->assertIsString($primeiro['caminho']);
        $this->assertIsString($segundo['caminho']);
        $this->temporarios[] = $primeiro['caminho'];
        $this->temporarios[] = $segundo['caminho'];

        $this->assertNotSame($primeiro['caminho'], $segundo['caminho']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function downloadsQueFalhamProvider(): array
    {
        return [
            'file_get_contents devolveu false' => [false],
            'sem retorno' => [null],
            'retorno de tipo inesperado' => [['bytes']],
        ];
    }

    /**
     * Falha no download: nenhum arquivo temporário chega a ser criado e o
     * usuário recebe o aviso do comando.
     *
     * @param mixed $retorno
     */
    #[DataProvider('downloadsQueFalhamProvider')]
    public function testFalhaNoDownloadNaoDeixaArquivoTemporario($retorno): void
    {
        $antes = $this->arquivosTemporariosDeAudio();

        $resultado = baixarAudioParaArquivoTemporario(
            'https://api.telegram.org/file/botTOKEN/voice/file_1.oga',
            static function () use ($retorno) {
                return $retorno;
            }
        );

        $this->assertSame(
            ['caminho' => null, 'erro' => "Erro ao baixar o áudio. Tente novamente! ❌"],
            $resultado
        );
        $this->assertSame($antes, $this->arquivosTemporariosDeAudio());
    }

    /**
     * Corpo vazio é download falho: sem esta guarda o comando gravava um
     * arquivo de 0 byte e o enviava assim mesmo à chamada PAGA do Whisper, que
     * responde erro — o usuário recebia "erro ao transcrever" (culpando a
     * transcrição) e a cota da OpenAI era gasta à toa.
     */
    public function testDownloadComCorpoVazioNaoViraArquivoDeZeroBytes(): void
    {
        $antes = $this->arquivosTemporariosDeAudio();

        $resultado = baixarAudioParaArquivoTemporario(
            'https://api.telegram.org/file/botTOKEN/voice/file_1.oga',
            static fn (): string => ''
        );

        $this->assertSame(
            ['caminho' => null, 'erro' => "Erro ao baixar o áudio. Tente novamente! ❌"],
            $resultado
        );
        $this->assertSame($antes, $this->arquivosTemporariosDeAudio());
    }

    public function testDownloadDeUmUnicoByteZeroContinuaSendoGravado(): void
    {
        // A guarda acima compara com '' e não usa empty(): um corpo de um byte
        // "0" é conteúdo binário legítimo e não pode virar falha de download.
        $resultado = baixarAudioParaArquivoTemporario(
            'https://api.telegram.org/file/botTOKEN/voice/file_1.oga',
            static fn (): string => '0'
        );

        $caminho = $resultado['caminho'];
        $this->assertIsString($caminho, 'Um corpo de um byte "0" precisa ser gravado.');
        $this->temporarios[] = $caminho;

        $this->assertNull($resultado['erro']);
        $this->assertSame('0', file_get_contents($caminho));
    }

    public function testFalhaNaCriacaoNaoDeixaArquivoTemporario(): void
    {
        $diretorio = Support\DiscoQueFalhaNaEscrita::registrar();

        $resultado = $this->semAvisosDoPhp(static function () use ($diretorio) {
            return baixarAudioParaArquivoTemporario(
                'https://api.telegram.org/file/botTOKEN/voice/file_1.oga',
                static fn (): string => 'bytes-do-audio',
                $diretorio,
                static fn (): false => false
            );
        });

        $this->assertSame(
            [
                'caminho' => null,
                'erro' => "Erro ao preparar o áudio para processamento. Tente novamente! ❌",
            ],
            $resultado
        );
        // O ponto do teste: o arquivo criado pela tentativa de escrita NÃO pode
        // ficar para trás no disco.
        $this->assertSame([], Support\DiscoQueFalhaNaEscrita::arquivosRestantes());
    }

    public function testFalhaAoProtegerTemporarioRemoveOCaminho(): void
    {
        $diretorio = sys_get_temp_dir();
        $inexistente = $diretorio . '/dealnibot_inexistente_' . uniqid();

        $resultado = $this->semAvisosDoPhp(static function () use ($diretorio, $inexistente) {
            return baixarAudioParaArquivoTemporario(
                'https://api.telegram.org/file/botTOKEN/voice/file_1.oga',
                static fn (): string => 'bytes',
                $diretorio,
                static fn (): string => $inexistente
            );
        });

        $this->assertSame(
            ['caminho' => null, 'erro' => "Erro ao proteger o áudio para processamento. Tente novamente! ❌"],
            $resultado
        );
        $this->assertFileDoesNotExist($inexistente);
    }

    public function testFalhaAoGravarTemporarioRemoveSemVazarArquivo(): void
    {
        $diretorio = (string) tempnam(sys_get_temp_dir(), 'dealnibot_dir_');
        unlink($diretorio);
        $this->assertTrue(mkdir($diretorio, 0700));

        try {
            $resultado = $this->semAvisosDoPhp(static function () use ($diretorio) {
                return baixarAudioParaArquivoTemporario(
                    'https://api.telegram.org/file/botTOKEN/voice/file_1.oga',
                    static fn (): string => 'bytes',
                    $diretorio,
                    static fn (): string => $diretorio
                );
            });

            $this->assertSame(
                ['caminho' => null, 'erro' => "Erro ao salvar o áudio para processamento. Tente novamente! ❌"],
                $resultado
            );
        } finally {
            chmod($diretorio, 0700);
            rmdir($diretorio);
        }
    }

    public function testRemocaoDoTemporarioApagaOArquivoDoDisco(): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'dealnibot_audio_');
        $this->assertIsString($caminho);
        $this->temporarios[] = $caminho;

        removerAudioTemporario($caminho);

        clearstatcache(true, $caminho);
        $this->assertFileDoesNotExist($caminho);
    }

    public function testRemocaoDeArquivoInexistenteNaoQuebraOFluxo(): void
    {
        // Chamada dupla (fluxo normal + finally) não pode gerar warning.
        $caminho = sys_get_temp_dir() . '/audio_que_nunca_existiu_' . uniqid() . '.ogg';

        removerAudioTemporario($caminho);

        $this->assertFileDoesNotExist($caminho);
    }

    // ---------------------------------------------------------------------
    // Chamada ao Whisper
    // ---------------------------------------------------------------------

    public function testUploadDoWhisperLevaArquivoModeloIdiomaEFormato(): void
    {
        $transporte = new Support\TransporteWhisperEspiao('{"text":"oi"}');

        $devolvido = chamarTranscricaoWhisper(
            'https://openrouter.ai/api/v1/audio/transcriptions',
            'sk-teste',
            '/tmp/audio_exemplo.ogg',
            $transporte
        );

        $this->assertSame(1, $transporte->chamadas, 'O Whisper precisa ser chamado uma vez.');
        $this->assertSame('https://openrouter.ai/api/v1/audio/transcriptions', $transporte->url);
        $this->assertSame('/tmp/audio_exemplo.ogg', $transporte->arquivoEnviado());
        $this->assertSame(
            [
                'model' => 'openai/whisper-large-v3-turbo',
                'provider' => '{"allow_fallbacks":true,"data_collection":"deny","ignore":["openai"]}',
                'language' => 'pt',
                'response_format' => 'json',
                'prompt' => 'Transcrição de áudio em português do Brasil. Mantenha a pontuação e capitalize corretamente.',
            ],
            $transporte->camposSemArquivo()
        );
        $this->assertSame(['Authorization: Bearer sk-teste'], $transporte->cabecalhos);
        $this->assertSame(['corpo' => '{"text":"oi"}', 'http' => 200], $devolvido);
    }

    public function testUploadDoWhisperEnviaOArquivoComoOggComNomeFixo(): void
    {
        $transporte = new Support\TransporteWhisperEspiao('{"text":"oi"}');

        chamarTranscricaoWhisper(
            'https://openrouter.ai/api/v1/audio/transcriptions',
            'sk-teste',
            '/tmp/audio_exemplo.ogg',
            $transporte
        );

        $arquivo = $transporte->campos['file'] ?? null;
        $this->assertInstanceOf(CURLFile::class, $arquivo);
        $this->assertSame('audio/ogg', $arquivo->getMimeType());
        $this->assertSame('audio.ogg', $arquivo->getPostFilename());
    }

    /**
     * Arquivos enviados como `audio` pelo Telegram não são necessariamente
     * OGG. O nome e o MIME do multipart precisam acompanhar a extensão
     * devolvida pelo endpoint de arquivo para a API identificar os bytes.
     */
    public function testMetadadosDeUploadPreservamFormatosSuportadosETratamOgaComoOgg(): void
    {
        $this->assertSame(
            ['nome' => 'audio.mp3', 'mime' => 'audio/mpeg'],
            metadadosDeUploadAudio('audio/file_123.mp3')
        );
        $this->assertSame(
            ['nome' => 'audio.m4a', 'mime' => 'audio/mp4'],
            metadadosDeUploadAudio('audio/file_123.m4a')
        );
        $this->assertSame(
            ['nome' => 'audio.ogg', 'mime' => 'audio/ogg'],
            metadadosDeUploadAudio('voice/file_123.oga')
        );
        $this->assertSame(
            ['nome' => 'audio.ogg', 'mime' => 'audio/ogg'],
            metadadosDeUploadAudio('voice/file_123.sem-extensao')
        );
    }

    public function testUploadDoWhisperAceitaMetadadosDoFormatoDoArquivo(): void
    {
        $transporte = new Support\TransporteWhisperEspiao('{"text":"oi"}');

        chamarTranscricaoWhisper(
            'https://openrouter.ai/api/v1/audio/transcriptions',
            'sk-teste',
            '/tmp/audio_exemplo_sem_extensao',
            $transporte,
            'audio.mp3',
            'audio/mpeg'
        );

        $arquivo = $transporte->campos['file'] ?? null;
        $this->assertInstanceOf(CURLFile::class, $arquivo);
        $this->assertSame('audio/mpeg', $arquivo->getMimeType());
        $this->assertSame('audio.mp3', $arquivo->getPostFilename());
    }

    public function testCodigoHttpDaTranscricaoEPropagado(): void
    {
        $transporte = new Support\TransporteWhisperEspiao('{"error":{"message":"quota"}}', 429);

        $this->assertSame(
            ['corpo' => '{"error":{"message":"quota"}}', 'http' => 429],
            chamarTranscricaoWhisper('https://openrouter.ai/api/v1/audio/transcriptions', 'sk', '/tmp/a.ogg', $transporte)
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function retornosInvalidosDoWhisperProvider(): array
    {
        return [
            'timeout do cURL (false)' => [false],
            'sem retorno' => [null],
            'retorno que nao e o par corpo/http' => ['{"text":"oi"}'],
            'retorno vazio' => [[]],
        ];
    }

    /**
     * Transporte que não devolve o par corpo/http vira falha fechada (corpo
     * false, HTTP 0), sem exceção e sem espera real.
     *
     * @param mixed $retorno
     */
    #[DataProvider('retornosInvalidosDoWhisperProvider')]
    public function testTransporteDoWhisperSemCorpoViraFalhaFechada($retorno): void
    {
        $inicio = microtime(true);

        $devolvido = chamarTranscricaoWhisper(
            'https://openrouter.ai/api/v1/audio/transcriptions',
            'sk-teste',
            '/tmp/audio_exemplo.ogg',
            static function () use ($retorno) {
                return $retorno;
            }
        );

        $this->assertSame(['corpo' => false, 'http' => 0], $devolvido);
        $this->assertLessThan(1.0, microtime(true) - $inicio, 'O teste não pode esperar de verdade.');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function codigosHttpNaoInteirosProvider(): array
    {
        return [
            'codigo como texto' => ['200'],
            'codigo como float' => [200.0],
            'codigo nulo' => [null],
            'codigo ausente' => ['ausente'],
        ];
    }

    /**
     * O par devolvido pelo transporte só é aceito com o código HTTP INTEIRO:
     * curl_getinfo(CURLINFO_HTTP_CODE) devolve int, e aceitar "200" faria a
     * comparação `!== 200` do interpretador dar falso positivo com qualquer
     * outro tipo. Sem código confiável, o fluxo cai no aviso de erro em vez de
     * tratar um corpo de origem duvidosa como transcrição válida.
     *
     * @param mixed $codigo
     */
    #[DataProvider('codigosHttpNaoInteirosProvider')]
    public function testCodigoHttpNaoInteiroViraZeroENaoViraTranscricao($codigo): void
    {
        $devolvido = chamarTranscricaoWhisper(
            'https://openrouter.ai/api/v1/audio/transcriptions',
            'sk-teste',
            '/tmp/audio_exemplo.ogg',
            static function () use ($codigo): array {
                $par = ['corpo' => '{"text":"oi"}'];
                if ($codigo !== 'ausente') {
                    $par['http'] = $codigo;
                }

                return $par;
            }
        );

        $this->assertSame(['corpo' => '{"text":"oi"}', 'http' => 0], $devolvido);
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_TRANSCRICAO],
            interpretarTranscricaoWhisper($devolvido['corpo'], $devolvido['http'])
        );
    }

    // ---------------------------------------------------------------------
    // Interpretação da transcrição
    // ---------------------------------------------------------------------

    public function testTranscricaoValidaDevolveOTextoDoAudio(): void
    {
        $this->assertSame(
            ['tipo' => 'transcricao', 'texto' => 'Oi, tudo bem com você?'],
            interpretarTranscricaoWhisper('{"text":"Oi, tudo bem com você?"}', 200)
        );
    }

    public function testTranscricaoMuitoLongaERecortadaAntesDeIrParaOChatgpt(): void
    {
        $corpo = json_encode(['text' => str_repeat('a', 5000)]);
        $this->assertIsString($corpo);

        $interpretada = interpretarTranscricaoWhisper($corpo, 200);

        $this->assertSame(
            ['tipo' => 'transcricao', 'texto' => str_repeat('a', 4096)],
            $interpretada
        );
    }

    public function testTranscricaoIgualAZeroNaoViraFalhaDaTranscricao(): void
    {
        // Áudio em que o usuário só diz "zero": com !empty($whisper_data['text'])
        // no lugar do isset() a transcrição legítima viraria erro e o
        // chat/completions nem seria chamado.
        $this->assertSame(
            ['tipo' => 'transcricao', 'texto' => '0'],
            interpretarTranscricaoWhisper('{"text":"0"}', 200)
        );
    }

    /**
     * Áudio sem fala (silêncio, ruído, microfone mudo): o Whisper responde 200
     * com texto VAZIO. Uma transcrição vazia não é conteúdo — mandá-la adiante
     * gasta a chamada PAGA do chat/completions com uma mensagem de usuário sem
     * nada dentro, faz o modelo responder ao contexto antigo como se o áudio
     * tivesse dito aquilo e ainda grava '' como contexto, apagando a conversa
     * anterior.
     *
     * O comando de TEXTO já recusa entrada vazia
     * (`if ($texto_recebido === null || $texto_recebido === '')` em
     * comandos/chatgpt.php); o de áudio precisa recusar do mesmo jeito.
     *
     * A comparação é com '', nunca empty(): uma transcrição "0" (o usuário
     * falou "zero") é conteúdo legítimo e continua passando.
     */
    public function testTranscricaoVaziaNaoViraMensagemVaziaParaOChatgpt(): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_TRANSCRICAO],
            interpretarTranscricaoWhisper('{"text":""}', 200)
        );
    }

    public function testParserRecusaTranscricaoSomenteComEspacos(): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_TRANSCRICAO],
            interpretarTranscricaoWhisper('{"text":" \t\n"}', 200)
        );
    }

    public function testTimeoutDoWhisperViraAvisoDeTranscricaoDesconhecida(): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_TRANSCRICAO],
            interpretarTranscricaoWhisper(false, 0)
        );
    }

    public function testErroHttpDoWhisperEnviaAMensagemDaOpenAi(): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_TRANSCRICAO],
            interpretarTranscricaoWhisper('{"error":{"message":"Audio file is too short"}}', 400)
        );
    }

    /**
     * Recusas REAIS da API de transcrição, com o código HTTP e o texto que a
     * OpenAI devolve em cada uma. O usuário precisa receber a explicação da
     * OpenAI — arquivo grande demais, formato não suportado, cota da conta
     * estourada, chave errada, erro do servidor —, nunca o aviso genérico e
     * nunca silêncio.
     *
     * @return array<string, array{int, string}>
     */
    public static function recusasDaApiDeTranscricaoProvider(): array
    {
        return [
            // Áudio acima do limite de 25 MB da própria API de transcrição
            // (o Telegram entrega até 20 MB, mas o .ogg pode ser reenviado
            // como documento por outro bot/canal).
            'arquivo maior que o limite da api' => [
                413,
                'Maximum content size limit (26214400) exceeded (26214401 bytes read)',
            ],
            'formato nao suportado' => [
                400,
                'Invalid file format. Supported formats: [flac, m4a, mp3, mp4, mpeg, mpga, oga, ogg, wav, webm]',
            ],
            'arquivo corrompido' => [400, 'The audio file could not be decoded or its format is not supported.'],
            'cota da conta estourada' => [
                429,
                'Rate limit reached for gpt-transcribe in organization org-teste on requests per min (RPM)',
            ],
            'chave de api invalida' => [401, 'Incorrect API key provided: sk-tes***ste.'],
            'erro do servidor' => [500, 'The server had an error while processing your request.'],
        ];
    }

    #[DataProvider('recusasDaApiDeTranscricaoProvider')]
    public function testRecusaDaApiDeTranscricaoChegaAoUsuarioComOMotivo(int $http, string $mensagem): void
    {
        $corpo = json_encode(['error' => ['message' => $mensagem, 'type' => 'invalid_request_error']]);
        $this->assertIsString($corpo);

        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_TRANSCRICAO],
            interpretarTranscricaoWhisper($corpo, $http)
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function transcricoesInvalidasProvider(): array
    {
        return [
            'html de gateway' => ['<html>502 Bad Gateway</html>', 502],
            'json truncado' => ['{"text":', 200],
            'json que nao e objeto' => ['"apenas um texto"', 200],
            'json nulo' => ['null', 200],
            'corpo vazio' => ['', 200],
            'sem a chave text' => ['{"duration":3}', 200],
            'text nulo' => ['{"text":null}', 200],
            'erro sem message' => ['{"error":{"type":"server_error"}}', 500],
            'erro com message de tipo errado' => ['{"error":{"message":{"pt":"falhou"}}}', 500],
            'erro que nao e objeto' => ['{"error":"falhou"}', 500],
            'http 500 com texto presente' => ['{"text":"ignorado"}', 500],
            'http 200 com erro' => ['{"error":{"type":"server_error"}}', 200],
        ];
    }

    /**
     * Resposta inválida, malformada ou com HTTP diferente de 200 cai no aviso
     * padrão da transcrição — nunca em texto vazio enviado ao chat/completions.
     */
    #[DataProvider('transcricoesInvalidasProvider')]
    public function testTranscricaoInvalidaViraAvisoPadrao(string $corpo, int $http): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_TRANSCRICAO],
            interpretarTranscricaoWhisper($corpo, $http)
        );
    }

    public function testHttp500ComMensagemDeErroTemPrioridadeSobreOTexto(): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_TRANSCRICAO],
            interpretarTranscricaoWhisper(
                '{"text":"ignorado","error":{"message":"Server had an error"}}',
                500
            )
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textosDeTipoInesperadoProvider(): array
    {
        return [
            'text como objeto' => ['{"text":{"pt":"oi"}}'],
            'text como lista' => ['{"text":["oi"]}'],
            'text numerico' => ['{"text":42}'],
            'text booleano' => ['{"text":true}'],
        ];
    }

    /**
     * text de outro tipo é falha de transcrição, não transcrição.
     *
     * Antes o valor não textual passava por cortarMensagem(), que devolve o
     * aviso fixo "Erro ao gerar mensagem." — e era ESSE aviso que seguia adiante
     * como se fosse a fala do usuário: chamada PAGA do chat/completions gasta
     * com lixo, resposta do modelo sobre uma frase que ninguém disse e o texto
     * do aviso gravado no Redis como contexto da conversa.
     */
    #[DataProvider('textosDeTipoInesperadoProvider')]
    public function testTextoDeTipoInesperadoNaoViraFalaDoUsuario(string $corpo): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_TRANSCRICAO],
            interpretarTranscricaoWhisper($corpo, 200)
        );
    }

    // ---------------------------------------------------------------------
    // Payload enviado ao chat/completions
    // ---------------------------------------------------------------------

    public function testPayloadDoAudioAvisaOModeloQueAMensagemVeioDeUmAudio(): void
    {
        $this->assertSame(
            [
                ['role' => 'developer', 'content' => self::PROMPT_DESENVOLVEDOR . '01/02/2026 03:04:05'],
                ['role' => 'user', 'content' => 'transcrição do áudio'],
            ],
            montarMensagensAudioComHistorico('transcrição do áudio', [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']], '01/02/2026 03:04:05')
        );
    }

    public function testPayloadDoAudioMantemAOrdemDeveloperUsuarioAssistenteAtual(): void
    {
        $this->assertSame(
            [
                ['role' => 'developer', 'content' => self::PROMPT_DESENVOLVEDOR . '01/02/2026 03:04:05'],
                ['role' => 'user', 'content' => 'pergunta anterior'],
                ['role' => 'assistant', 'content' => 'resposta anterior'],
                ['role' => 'user', 'content' => 'transcrição atual'],
            ],
            montarMensagensAudioComHistorico('transcrição atual', [['role' => 'user', 'content' => 'pergunta anterior'], ['role' => 'assistant', 'content' => 'resposta anterior']], '01/02/2026 03:04:05')
        );
    }

    public function testPayloadDoAudioIgnoraContextoVazio(): void
    {
        $this->assertSame(
            [
                ['role' => 'developer', 'content' => self::PROMPT_DESENVOLVEDOR . '01/02/2026 03:04:05'],
                ['role' => 'assistant', 'content' => 'só a resposta anterior'],
                ['role' => 'user', 'content' => 'atual'],
            ],
            montarMensagensAudioComHistorico('atual', [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => 'só a resposta anterior']], '01/02/2026 03:04:05')
        );
    }

    /**
     * Contexto e resposta anterior iguais a "0" são falsy em PHP: com
     * !empty() no lugar da comparação com '' as duas mensagens sumiam do
     * payload e o modelo respondia sem saber o que já foi dito. É o mesmo bug
     * que o commit 341c19d corrigiu no conteúdo da resposta — este ramo tinha
     * ficado sem teste, e uma volta para empty() passaria em silêncio.
     */
    public function testContextoIgualAZeroContinuaNoPayloadDoAudio(): void
    {
        $this->assertSame(
            [
                ['role' => 'developer', 'content' => self::PROMPT_DESENVOLVEDOR . '01/02/2026 03:04:05'],
                ['role' => 'user', 'content' => '0'],
                ['role' => 'assistant', 'content' => '0'],
                ['role' => 'user', 'content' => 'e agora?'],
            ],
            montarMensagensAudioComHistorico('e agora?', [['role' => 'user', 'content' => '0'], ['role' => 'assistant', 'content' => '0']], '01/02/2026 03:04:05')
        );
    }

    public function testRelogioPadraoDoAudioUsaAHoraAtualNoFormatoDoComando(): void
    {
        $mensagens = montarMensagensAudioComHistorico('oi', [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']]);
        $desenvolvedor = $mensagens[0]['content'] ?? '';

        $this->assertStringStartsWith(self::PROMPT_DESENVOLVEDOR, $desenvolvedor);
        $this->assertMatchesRegularExpression(
            '/Data e hora em São Paulo: \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/',
            $desenvolvedor
        );
    }

    public function testCorpoDaRequisicaoDoAudioLevaModeloLimitesEMensagens(): void
    {
        $mensagens = montarMensagensAudioComHistorico('oi', [['role' => 'user', 'content' => ''], ['role' => 'assistant', 'content' => '']], '01/02/2026 03:04:05');
        $corpo = montarCorpoRequisicaoAudio($mensagens);
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

    public function testCorpoDaRequisicaoDoAudioDevolveFalseQuandoOJsonFalha(): void
    {
        // Transcrição com byte inválido em UTF-8: json_encode falha e o comando
        // avisa o usuário em vez de enviar corpo vazio à OpenAI.
        $this->assertFalse(
            montarCorpoRequisicaoAudio([['role' => 'user', 'content' => "\xB1\x31"]])
        );
    }

    public function testChamadaDoAudioEnviaUrlCorpoECabecalhosComAChaveDaApi(): void
    {
        $transporte = new Support\TransporteHttpEspiao('{"choices":[]}');

        $resposta = chamarChatCompletionsAudio(
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

    public function testTimeoutDoChatgptNoAudioViraFalseSemEsperaReal(): void
    {
        $inicio = microtime(true);

        $resposta = chamarChatCompletionsAudio(
            'https://openrouter.ai/api/v1/chat/completions',
            'sk-teste',
            '{}',
            new Support\TransporteHttpEspiao(false)
        );

        $this->assertFalse($resposta);
        $this->assertLessThan(1.0, microtime(true) - $inicio, 'O teste não pode esperar de verdade.');
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaAudio($resposta)
        );
    }

    // ---------------------------------------------------------------------
    // Interpretação da resposta do chat/completions
    // ---------------------------------------------------------------------

    public function testRespostaValidaDoAudioDevolveOConteudoDoAssistente(): void
    {
        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => 'Ouvi! 🐾'],
            interpretarRespostaAudio('{"choices":[{"message":{"content":"Ouvi! 🐾"}}]}')
        );
    }

    public function testRespostaValidaDoAudioUsaApenasAPrimeiraEscolha(): void
    {
        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => 'primeira'],
            interpretarRespostaAudio(
                '{"choices":[{"message":{"content":"primeira"}},{"message":{"content":"segunda"}}]}'
            )
        );
    }

    public function testRespostaValidaDoAudioMuitoLongaERecortada(): void
    {
        $corpo = json_encode(['choices' => [['message' => ['content' => str_repeat('a', 5000)]]]]);
        $this->assertIsString($corpo);

        $interpretada = interpretarRespostaAudio($corpo);

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
     * @param mixed $corpo
     */
    #[DataProvider('respostasSemConteudoProvider')]
    public function testRespostaInvalidaDoAudioViraMensagemGenericaDeErro($corpo): void
    {
        $this->assertSame(
            ['tipo' => 'falha', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaAudio($corpo)
        );
    }

    public function testContentZeroNoAudioEUmaRespostaValida(): void
    {
        // "0" é falsy em PHP: com empty() a resposta legítima do modelo virava
        // "erro ao gerar resposta".
        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => '0'],
            interpretarRespostaAudio('{"choices":[{"message":{"content":"0"}}]}')
        );
    }

    public function testErroDaApiNoAudioEnviaAMensagemDevolvidaPelaOpenAi(): void
    {
        $this->assertSame(
            ['tipo' => 'erro_api', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaAudio('{"error":{"message":"Rate limit reached for gpt-5.6-luna"}}')
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
    public function testErroDaApiNoAudioSemMensagemUtilEnviaSomenteOPrefixo(string $corpo): void
    {
        $this->assertSame(
            ['tipo' => 'erro_api', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaAudio($corpo)
        );
    }

    public function testErroDaApiNoAudioTemPrioridadeSobreOConteudo(): void
    {
        $this->assertSame(
            ['tipo' => 'erro_api', 'texto' => self::ERRO_GENERICO],
            interpretarRespostaAudio(
                '{"error":{"message":"quota"},"choices":[{"message":{"content":"ignorado"}}]}'
            )
        );
    }

    public function testErroNuloNaRespostaDoAudioNaoBloqueiaOConteudo(): void
    {
        $this->assertSame(
            ['tipo' => 'resposta', 'texto' => 'tudo certo'],
            interpretarRespostaAudio('{"error":null,"choices":[{"message":{"content":"tudo certo"}}]}')
        );
    }

    // ---------------------------------------------------------------------
    // O que o comando tem permissão de fazer no disco
    // ---------------------------------------------------------------------

    /**
     * Lista fechada do que o comando de áudio pode chamar. Ele é o ÚNICO dos
     * três comandos de IA que grava em disco, e a limpeza só cobre o caminho
     * montado por baixarAudioParaArquivoTemporario() e apagado por
     * removerAudioTemporario(): qualquer outra primitiva que crie arquivo
     * (copy, tmpfile, rename, fopen/fwrite, uma conversão por shell_exec) deixa
     * um resíduo que nenhum caminho de erro remove — e o servidor de polling
     * acumula um por áudio recebido.
     *
     * Aqui qualquer chamada NOVA reprova, então a única forma de o comando
     * passar a mexer no disco de outro jeito é alguém alterar este teste junto.
     */
    public function testComandoDeAudioSoChamaFuncoesConhecidas(): void
    {
        $permitidas = [
            // Funções do próprio comando
            'admitirMidiaDoComando',
            'caminhoDoArquivoDeMidia',
            'mensagemRecebidaDeMidia',
            'tipoDeChatDaMidia',
            'responderMidiaAudio',
            'validarAudioDoComando',
            'idDoContextoDeMidia',
            'audioDaMensagem',
            'avisarUsuarioAudio',
            'baixarAudioParaArquivoTemporario',
            'chamarChatCompletionsAudio',
            'chamarTranscricaoWhisper',
            'cortarMensagem',
            'duracaoDoAudio',
            'interpretarRespostaAudio',
            'interpretarTranscricaoWhisper',
            'chaveMemoriaConversa',
            'chavesLegadasDaConversa',
            'lerTextoDoRedis',
            'lerHistoricoMemoria',
            'limiteDeSegundosDoAudio',
            'metadadosDeUploadAudio',
            'montarMensagensAudioComHistorico',
            'montarCorpoRequisicaoAudio',
            'idTopicoDaMensagem',
            'removerAudioTemporario',
            'salvarTextoComTtl',
            'salvarTurnoMemoria',
            'enviarRespostaIa',
            // Só prepara metadados em memória; persistência pertence à entrega.
            'memorizar',
            'iniciarRespostaIa',
            'ttlMemoriaConversa',
            // Cota diária (inclusos/limites_requisicoes.php)
            'admitir_midia',
            'consumir_limite_diario',
            'limite_diario_sujeito',
            'sujeitoDaCotaDeAudio',
            // Download do áudio e upload ao Whisper/chat completions
            'curl_exec',
            'curl_getinfo',
            'curl_init',
            'curl_setopt',
            'file_get_contents',
            'new CURLFile',
            // Arquivo temporário: criação, verificação e remoção — e nada mais
            'file_put_contents',
            'chmod',
            'is_file',
            'sys_get_temp_dir',
            'tempnam',
            'unlink',
            // Utilitários puros
            'date',
            'date_default_timezone_set',
            'is_array',
            'is_int',
            'is_scalar',
            'is_string',
            'json_decode',
            'json_encode',
            'mb_strlen',
            'mb_strrpos',
            'mb_substr',
            'str_replace',
            'str_starts_with',
            'new LogicException',
        ];

        $inesperadas = array_values(
            array_diff(
                array_merge(Support\EstruturaDeScript::funcoesChamadas(self::CAMINHO_IRMAO, 'executarComandoVerAudio'), Support\EstruturaDeScript::funcoesChamadas(self::CAMINHO_IRMAO, 'responderMidiaAudio'), Support\EstruturaDeScript::funcoesChamadas(self::CAMINHO_IRMAO, 'sujeitoDaCotaDeAudio')),
                $permitidas
            )
        );

        $this->assertSame(
            [],
            $inesperadas,
            'comandos/ver_audio.php passou a chamar algo novo; se cria arquivo, o temporário vaza nos erros.'
        );
    }

    // ---------------------------------------------------------------------
    // Contexto exigido pelo comando
    // ---------------------------------------------------------------------

    /**
     * A guarda de contexto é a primeira coisa do script, e o fluxo inteiro só
     * roda depois dela.
     *
     * Desde que o corpo saiu para executarComandoVerAudio(), o invariante ficou mais
     * simples de afirmar do que quando tudo morava no mesmo arquivo: a guarda
     * está no script, a chamada vem depois dela, e todo efeito está do outro
     * lado — dentro da função, que a guarda decide se chega a ser chamada.
     */
    public function testComandoDeAudioExigeContextoValidoAntesDeQualquerEfeito(): void
    {
        $script = (string) file_get_contents(self::CAMINHO_SCRIPT);
        $this->assertNotSame('', $script, 'O script do comando precisa ser legível.');

        $guarda = "if (!isset(\$telegram, \$redis) || !\$telegram instanceof Telegram || !\$redis instanceof Redis) {\n"
            . "    throw new LogicException('Contexto inválido para o comando de áudio.');\n"
            . '}';
        $this->assertStringContainsString($guarda, $script);

        $posicaoGuarda = strpos($script, $guarda);
        $posicaoChamada = strpos($script, 'executarComandoVerAudio($telegram, $redis);');
        $this->assertIsInt($posicaoGuarda);
        $this->assertIsInt($posicaoChamada, 'O script precisa chamar executarComandoVerAudio().');
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

    // ---------------------------------------------------------------------
    // Utilitários
    // ---------------------------------------------------------------------

    /**
     * Fluxo do comando, desindentado.
     *
     * O corpo saiu do topo do script para executarComandoVerAudio(), no
     * funcoes_ver_audio.php irmão, em 2026-08-24: script de topo executa no
     * include e nenhum teste podia incluí-lo, então tudo que morasse lá era
     * código de produção sem cobertura possível. A única diferença de FORMA é
     * um nível de indentação a mais — as agulhas abaixo descrevem a estrutura
     * do fluxo, não a profundidade em que ele está aninhado.
     */
    private function fonteDoComando(): string
    {
        $corpo = Support\EstruturaDeScript::corpoDaFuncao(self::CAMINHO_IRMAO, 'executarComandoVerAudio');

        return implode("\n", array_map(
            static fn (string $linha): string => str_starts_with($linha, '    ')
                ? substr($linha, 4)
                : $linha,
            explode("\n", $corpo)
        ));
    }

    /**
     * Arquivos audio_*.ogg presentes no diretório temporário do sistema.
     *
     * @return array<int, string>
     */
    private function arquivosTemporariosDeAudio(): array
    {
        $encontrados = glob(sys_get_temp_dir() . '/audio_*.ogg');

        return is_array($encontrados) ? $encontrados : [];
    }

    /**
     * Executa o trecho silenciando os avisos que o PHP emite quando a escrita
     * falha de propósito (o teste está simulando disco cheio).
     *
     * @param callable $trecho
     * @return mixed
     */
    private function semAvisosDoPhp(callable $trecho)
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $trecho();
        } finally {
            restore_error_handler();
        }
    }
}
