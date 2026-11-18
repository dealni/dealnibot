<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes de caracterizacao do CONTRATO com o provedor externo (OpenAI) nos
 * tres comandos de IA: comandos/chatgpt.php, comandos/ver_audio.php (Whisper)
 * e comandos/ver_imagem.php (visao).
 *
 * Esses scripts NAO podem ser incluidos nos testes: o fluxo top-level valida o
 * contexto ($telegram/$redis), chama sendMessage/sendChatAction reais e dispara
 * cURL para a API da OpenAI (rede).
 *
 * O COMPORTAMENTO de cada comando e verificado executando as funcoes extraidas
 * do proprio arquivo (ComandosChatgptFluxoTextoTest, FluxoIaTextoChatgptTest e
 * equivalentes de audio/imagem). O que sobra para este teste e o que nenhuma
 * execucao alcanca: as constantes do contrato ainda escritas no TOPO dos tres
 * scripts e, principalmente, o DRIFT entre as tres copias -- se um comando
 * trocar o modelo, a cota ou a chave de contexto e os outros nao, o teste
 * acusa. Mesmo padrao de ComandosHelpersDuplicadosTest (helpers duplicados) e
 * de TelegramEndpointsTest::testListaSincronizadaComAFonte (lista de wrappers).
 *
 * O que estes testes travam (drift entre as tres copias quebra o teste):
 *   - endpoint e parametros da requisicao chat/completions (modelo, limites);
 *   - cota diaria de 100 requisicoes em chat privado (rate limiting via Redis);
 *   - memória rolante versionada por conversa/tópico e TTL de 24h;
 *   - extracao da mensagem de erro da API com guarda de tipo escalar;
 *   - contrato do Whisper (exclusivo do ver_audio) e da visao (exclusivo do
 *     ver_imagem), incluindo as fronteiras que NAO devem vazar para os demais.
 */
final class ProvedorOpenAiContratoTest extends TestCase
{
    private const CHATGPT = __DIR__ . '/../../comandos/chatgpt.php';
    private const VER_AUDIO = __DIR__ . '/../../comandos/ver_audio.php';
    private const VER_IMAGEM = __DIR__ . '/../../comandos/ver_imagem.php';

    /**
     * Os tres scripts que falam com chat/completions da OpenAI.
     *
     * @return array<string, array{string}>
     */
    public static function provedorScriptsDeChat(): array
    {
        return [
            'chatgpt.php' => [self::CHATGPT],
            'ver_audio.php' => [self::VER_AUDIO],
            'ver_imagem.php' => [self::VER_IMAGEM],
        ];
    }

    /**
     * Le a fonte do script sem executar nenhuma linha dele, junto com a dos
     * arquivos funcoes_*.php que ele carrega: as declaracoes sairam do topo do
     * comando (require_once, para o require repetido do roteador nao redeclarar
     * funcao), mas para efeito de contrato comando + funcoes sao uma coisa so.
     *
     * @param string $caminho
     * @return string
     */
    private function fonte(string $caminho): string
    {
        return Support\FonteDoComando::ler($caminho);
    }

    #[DataProvider('provedorScriptsDeChat')]
    public function testUsaEndpointChatCompletionsDaOpenAi(string $caminho): void
    {
        $this->assertStringContainsString(
            "'https://openrouter.ai/api/v1/chat/completions'",
            $this->fonte($caminho)
        );
    }

    /**
     * Parametros da requisicao a chat/completions. Presente identicamente nos
     * tres scripts: se um deles trocar o modelo ou os limites e os outros nao,
     * este teste acusa a divergencia.
     */
    #[DataProvider('provedorScriptsDeChat')]
    public function testContratoDaRequisicaoDeChatCompletions(string $caminho): void
    {
        $fonte = $this->fonte($caminho);

        $this->assertStringContainsString("'model' => 'openai/gpt-6-luna'", $fonte);
        $this->assertStringContainsString("'temperature' => 1", $fonte);
        $this->assertStringContainsString("'max_completion_tokens' => 2048", $fonte);
        $this->assertStringContainsString("'reasoning' => [", $fonte);
        $this->assertStringContainsString("'effort' => 'xhigh'", $fonte);
        $this->assertStringContainsString("'exclude' => true", $fonte);
    }

    /**
     * GPT-5.6 é um modelo posterior a o1: instruções fixas devem usar o papel
     * `developer`, conforme o contrato atual do Chat Completions. O histórico
     * continua restrito a `user`/`assistant`; apenas a instrução do bot muda.
     */
    #[DataProvider('provedorScriptsDeChat')]
    public function testUsaPapelDeveloperParaInstrucoesNosTresFluxos(string $caminho): void
    {
        $fonte = $this->fonte($caminho);

        $this->assertStringContainsString('"role" => "developer"', $fonte);
        $this->assertStringNotContainsString('"role" => "system"', $fonte);
    }

    /**
     * Rate limiting: os três scripts reservam atomicamente a cota necessária
     * SEMPRE, e o sujeito do balde sai de limite_diario_sujeito() — o usuario no
     * privado, o proprio grupo em grupo/supergrupo. Este portao e o que separa
     * um update de uma chamada paga: nenhum dos tres pode ter caminho que passe
     * por fora dele.
     */
    #[DataProvider('provedorScriptsDeChat')]
    public function testAplicaCotaDiariaDeCemRequisicoesEmTodoChat(string $caminho): void
    {
        $fonte = $this->fonte($caminho);

        $this->assertStringContainsString('$info_limite = consumir_limite_diario(', $fonte);
        $this->assertStringContainsString('limite_diario_sujeito(', $fonte);
        $this->assertMatchesRegularExpression('/,\s*100(?:,\s*2)?\s*\);/u', $fonte);

        if (str_ends_with($caminho, '/ver_audio.php')) {
            $this->assertMatchesRegularExpression('/,\s*100,\s*2\s*\);/u', $fonte);
        }
    }

    /**
     * O "digitando…" (`sendChatAction`) vem DEPOIS do portão de cota nos três
     * comandos.
     *
     * Em grupo — que é onde este bot vive — o aviso de limite estourado é
     * omitido de propósito. Com o sendChatAction antes do portão, a cota
     * esgotada produzia a única coisa que o grupo enxergava: o bot entrando em
     * "digitando…" a cada menção, a cada foto e a cada áudio, e nunca
     * respondendo — pelo resto do dia, até a virada em America/Sao_Paulo. Uma
     * promessa de resposta que o código já sabia que não ia cumprir.
     *
     * O contrato fica aqui, junto com o do portão de cota, porque o valor está
     * em travar os TRÊS de uma vez: consertar um comando e esquecer os outros
     * dois é exatamente o drift que este arquivo existe para pegar.
     *
     * Este teste FALHA com a ordem anterior.
     */
    #[DataProvider('provedorScriptsDeChat')]
    public function testDigitandoVemDepoisDoPortaoDeCota(string $caminho): void
    {
        $fonte = $this->fonte($caminho);

        $posicaoDoPortao = strpos($fonte, '$info_limite = consumir_limite_diario(');
        $this->assertIsInt($posicaoDoPortao, 'O comando deixou de consumir a cota.');

        $posicaoDaAcao = strpos($fonte, 'iniciarRespostaIa($telegram, $redis)');
        $this->assertIsInt($posicaoDaAcao, 'O comando deixou de mandar o "digitando…".');

        $this->assertGreaterThan(
            $posicaoDoPortao,
            $posicaoDaAcao,
            'sendChatAction voltou para antes do portão de cota: em grupo isso deixa o bot '
            . '"digitando…" para sempre, porque o aviso de limite é omitido lá.'
        );

        // E depois do `return` do ramo de limite atingido, não só do consumo:
        // entre o consumo e o return o fluxo ainda pode terminar sem resposta.
        $posicaoDoRetorno = strpos($fonte, "if (\$info_limite['atingiu_limite']) {");
        $this->assertIsInt($posicaoDoRetorno);
        $this->assertGreaterThan($posicaoDoRetorno, $posicaoDaAcao);
    }

    /**
     * A memória nova é versionada por conversa/tópico e o fallback legado mantém
     * a migração de `contexto`/`assistente` sem perder uma conversa ativa.
     */
    #[DataProvider('provedorScriptsDeChat')]
    public function testContextoConversacionalNoRedisComTtlDe24h(string $caminho): void
    {
        $fonte = $this->fonte($caminho);

        $this->assertStringContainsString("'contexto' . \$id_telegram", $fonte);
        $this->assertStringContainsString("'assistente' . \$id_telegram", $fonte);
        $this->assertStringContainsString('salvarTextoComTtl($redis, $chave_contexto,', $fonte);
        $this->assertStringContainsString('salvarTextoComTtl($redis, $chave_assistente,', $fonte);
        $this->assertStringNotContainsString('$redis->expire(', $fonte);
        $this->assertStringContainsString('chaveMemoriaConversa(', $fonte);
        $this->assertStringContainsString('idTopicoDaMensagem(', $fonte);
        $this->assertStringContainsString('salvarTurnoMemoria($redis, $chave_memoria, $historico', $fonte);
    }

    /**
     * Contrato de erro da API: a mensagem so e concatenada quando error.message
     * for escalar (a guarda de tipo evita TypeError se a OpenAI mudar o formato).
     */
    #[DataProvider('provedorScriptsDeChat')]
    public function testErroDaApiExtraiMessageComGuardaDeTipoEscalar(string $caminho): void
    {
        $this->assertStringContainsString(
            "is_scalar(\$mensagem) ? (string) \$mensagem : ''",
            $this->fonte($caminho)
        );
    }

    /**
     * Contrato do Whisper (transcricao) e EXCLUSIVO do ver_audio: endpoint de
     * transcriptions, modelo de transcricao, idioma pt e formato json, alem da
     * guarda de tipo na mensagem de erro do Whisper.
     */
    public function testContratoDeTranscricaoWhisperExclusivoDoVerAudio(): void
    {
        $audio = $this->fonte(self::VER_AUDIO);

        $this->assertStringContainsString(
            "'https://openrouter.ai/api/v1/audio/transcriptions'",
            $audio
        );
        $this->assertStringContainsString("'model' => 'openai/whisper-large-v3-turbo'", $audio);
        $this->assertStringContainsString("'language' => 'pt'", $audio);
        $this->assertStringContainsString("'response_format' => 'json'", $audio);
        $this->assertStringContainsString(
            "isset(\$erro_whisper['message']) && is_scalar(\$erro_whisper['message'])",
            $audio
        );

        // A transcricao nao pode vazar para os comandos de texto/imagem.
        $this->assertStringNotContainsString('audio/transcriptions', $this->fonte(self::CHATGPT));
        $this->assertStringNotContainsString('audio/transcriptions', $this->fonte(self::VER_IMAGEM));
    }

    /**
     * Contrato de visao e EXCLUSIVO do ver_imagem: bloco image_url em alta
     * resolucao e legenda limitada a 1024 chars (limite de caption do Telegram).
     */
    public function testContratoDeVisaoExclusivoDoVerImagem(): void
    {
        $imagem = $this->fonte(self::VER_IMAGEM);

        $this->assertStringContainsString('"type" => "image_url"', $imagem);
        $this->assertStringContainsString('"detail" => "high"', $imagem);
        $this->assertStringContainsString('cortarMensagem($caption_original, 1024)', $imagem);

        // A visao nao pode vazar para os comandos de texto/audio.
        $this->assertStringNotContainsString('image_url', $this->fonte(self::CHATGPT));
        $this->assertStringNotContainsString('image_url', $this->fonte(self::VER_AUDIO));
    }
}
