<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes das funções PURAS de decisão extraídas para inclusos/roteamento.php.
 *
 * Diferente dos comandos, roteamento.php é um arquivo de DECLARAÇÕES sem
 * efeitos top-level, então já é carregado diretamente por tests/bootstrap.php
 * (como inclusos/funcoes.php). Assim, cada teste executa o código de produção
 * sem uma cópia extraída por eval().
 *
 * RoteadorTest.php continua cobrindo o roteador.php como script (guarda,
 * return e travessia); aqui cobrimos cada ramo da decisão diretamente.
 */
final class RoteadorRotasTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Extração de estrutura da atualização
    // ---------------------------------------------------------------------

    public function testExtrairMensagem(): void
    {
        $this->assertSame(
            ['message_id' => 5, 'text' => 'oi'],
            \roteamento_extrairMensagem(['message' => ['message_id' => 5, 'text' => 'oi']])
        );
        // Ausente ou de tipo errado devolve [].
        $this->assertSame([], \roteamento_extrairMensagem([]));
        $this->assertSame([], \roteamento_extrairMensagem(['message' => 'nao-array']));
    }

    public function testExtrairMensagemDoCallback(): void
    {
        $mensagem = ['message_id' => 2, 'chat' => ['id' => 11, 'type' => 'private']];
        $this->assertSame(
            $mensagem,
            \roteamento_extrairMensagemDoCallback(['callback_query' => ['message' => $mensagem]])
        );
        // Sem callback_query, ou sem message dentro dele, devolve [].
        $this->assertSame([], \roteamento_extrairMensagemDoCallback([]));
        $this->assertSame([], \roteamento_extrairMensagemDoCallback(['callback_query' => ['id' => '9']]));
        $this->assertSame([], \roteamento_extrairMensagemDoCallback(['callback_query' => 'nao-array']));
    }

    public function testExtrairChatPreferemMensagem(): void
    {
        $chatMensagem = ['id' => 1, 'type' => 'group'];
        $chatCallback = ['id' => 2, 'type' => 'private'];

        // message.chat tem prioridade sobre callback.message.chat.
        $this->assertSame(
            $chatMensagem,
            \roteamento_extrairChat(['chat' => $chatMensagem], ['chat' => $chatCallback])
        );
        // Sem message.chat, cai para callback.message.chat.
        $this->assertSame($chatCallback, \roteamento_extrairChat([], ['chat' => $chatCallback]));
        // Nenhum dos dois: [].
        $this->assertSame([], \roteamento_extrairChat([], []));
        $this->assertSame([], \roteamento_extrairChat(['chat' => 'x'], ['chat' => 'y']));
    }

    public function testTipoChat(): void
    {
        $this->assertSame('supergroup', \roteamento_tipoChat(['type' => 'supergroup']));
        $this->assertNull(\roteamento_tipoChat([]));
        // type não textual não conta.
        $this->assertNull(\roteamento_tipoChat(['type' => 123]));
    }

    public function testSomenteGruposESeusSupergruposSaoAtendidos(): void
    {
        $this->assertTrue(\roteamento_chatEhGrupo('group'));
        $this->assertTrue(\roteamento_chatEhGrupo('supergroup'));
        $this->assertFalse(\roteamento_chatEhGrupo('private'));
        $this->assertFalse(\roteamento_chatEhGrupo('channel'));
        $this->assertFalse(\roteamento_chatEhGrupo(null));
    }

    // ---------------------------------------------------------------------
    // Flags de mídia
    // ---------------------------------------------------------------------

    public function testTemFoto(): void
    {
        $this->assertTrue(\roteamento_temFoto(['photo' => [['file_id' => 'a']]]));
        $this->assertFalse(\roteamento_temFoto([]));
        // Coleção vazia não conta como foto (empty()).
        $this->assertFalse(\roteamento_temFoto(['photo' => []]));
    }

    public function testTemVoz(): void
    {
        $this->assertTrue(\roteamento_temVoz(['voice' => ['file_id' => 'a']]));
        $this->assertFalse(\roteamento_temVoz([]));
        $this->assertFalse(\roteamento_temVoz(['voice' => []]));
    }

    public function testTemAudio(): void
    {
        $this->assertTrue(\roteamento_temAudio(['audio' => ['file_id' => 'a']]));
        $this->assertFalse(\roteamento_temAudio([]));
        $this->assertFalse(\roteamento_temAudio(['audio' => []]));
    }

    // ---------------------------------------------------------------------
    // Texto para detectar a menção pelo nome
    // ---------------------------------------------------------------------

    public function testTextoParaVerificarUsaTextoQuandoNaoHaFoto(): void
    {
        $this->assertSame(
            'olá dealni',
            \roteamento_textoParaVerificar([], 'Olá DEALNI', false)
        );
        // Texto nulo vira ''.
        $this->assertSame('', \roteamento_textoParaVerificar([], null, false));
    }

    public function testTextoParaVerificarUsaCaptionQuandoHaFoto(): void
    {
        // Com foto, o texto vem do caption (não de Text()).
        $this->assertSame(
            'foto do dealni',
            \roteamento_textoParaVerificar(['caption' => 'Foto do DEALNI'], 'texto ignorado', true)
        );
        // Foto sem caption (ou caption não textual) vira ''.
        $this->assertSame('', \roteamento_textoParaVerificar([], 'texto ignorado', true));
        $this->assertSame('', \roteamento_textoParaVerificar(['caption' => 123], 'texto ignorado', true));
    }

    public function testContemNome(): void
    {
        $this->assertTrue(\roteamento_contemNome('ei dealni, tudo bem?'));
        $this->assertTrue(\roteamento_contemNome('dealni'));
        $this->assertTrue(\roteamento_contemNome('oi @meuprojetobot', 'MeuProjetoBot'));
        $this->assertTrue(\roteamento_contemNome('oi dealni', 'MeuProjetoBot'));
        $this->assertFalse(\roteamento_contemNome(''));
        $this->assertFalse(\roteamento_contemNome('bom dia, pessoal'));
        // Comparação sensível a maiúsculas: espera texto já minúsculo.
        $this->assertFalse(\roteamento_contemNome('DEALNI'));
    }

    // ---------------------------------------------------------------------
    // Reply
    // ---------------------------------------------------------------------

    public function testExtrairReply(): void
    {
        $reply = ['message_id' => 7, 'from' => ['id' => 123456]];
        $this->assertSame($reply, \roteamento_extrairReply(['reply_to_message' => $reply]));
        $this->assertNull(\roteamento_extrairReply([]));
        $this->assertNull(\roteamento_extrairReply(['reply_to_message' => 'nao-array']));
    }

    public function testReplyEhDoBot(): void
    {
        $token = '123456:FAKE_TOKEN';
        $this->assertTrue(\roteamento_replyEhDoBot(['from' => ['id' => 123456]], $token));
        $this->assertFalse(\roteamento_replyEhDoBot(['from' => ['id' => 999]], $token));
        $this->assertFalse(\roteamento_replyEhDoBot(['from' => ['username' => 'MeuProjetoBot']], $token));
        $this->assertFalse(\roteamento_replyEhDoBot(['from' => ['id' => 123456]], '123456'));
        $this->assertFalse(\roteamento_replyEhDoBot([], $token));
    }

    // ---------------------------------------------------------------------
    // Rotas por mídia
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{bool, bool, bool, string}>
     */
    public static function rotaPorMidiaProvider(): array
    {
        return [
            'foto vence tudo' => [true, true, true, 'imagem'],
            'apenas foto' => [true, false, false, 'imagem'],
            'voz' => [false, true, false, 'audio'],
            'audio' => [false, false, true, 'audio'],
            'voz e audio' => [false, true, true, 'audio'],
            'sem midia vira chatgpt' => [false, false, false, 'chatgpt'],
        ];
    }

    #[DataProvider('rotaPorMidiaProvider')]
    public function testRotaPorMidia(bool $foto, bool $voz, bool $audio, string $esperado): void
    {
        $this->assertSame($esperado, \roteamento_rotaPorMidia($foto, $voz, $audio));
    }

    public function testRotaPorMidiaFoto(): void
    {
        $this->assertSame('imagem', \roteamento_rotaPorMidiaFoto(true));
        // Sem foto, qualquer conteúdo (inclusive voz/áudio) segue para o chatgpt.
        $this->assertSame('chatgpt', \roteamento_rotaPorMidiaFoto(false));
    }

    // ---------------------------------------------------------------------
    // Portão de tipo do update fora do chat privado
    // ---------------------------------------------------------------------

    /**
     * Os quatro tipos que os comandos sabem atender. As CONSTANTES de verdade
     * entram aqui de propósito: roteamento.php escreve os valores como literal
     * para não depender de classe nenhuma, e é este teste que trava a igualdade.
     *
     * @return array<string, array{string}>
     */
    public static function tiposAtendidosForaDoPrivadoProvider(): array
    {
        return [
            'texto' => [\Telegram::MESSAGE],
            'foto' => [\Telegram::PHOTO],
            'voz' => [\Telegram::VOICE],
            'audio' => [\Telegram::AUDIO],
        ];
    }

    #[DataProvider('tiposAtendidosForaDoPrivadoProvider')]
    public function testTipoDeUpdateComComandoParaAtenderPassaNoPortao(string $tipo): void
    {
        $this->assertTrue(\roteamento_updateAtendivelForaDoPrivado($tipo));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function tiposRecusadosForaDoPrivadoProvider(): array
    {
        return [
            // Cairiam no chatgpt com texto vazio: chamada paga por um sticker.
            'video' => [\Telegram::VIDEO],
            'documento' => ['document'],
            'sticker' => ['sticker'],
            // Nunca foram atendidos fora do privado, e continuam fora.
            'callback' => [\Telegram::CALLBACK_QUERY],
            'inline' => [\Telegram::INLINE_QUERY],
            'editada' => [\Telegram::EDITED_MESSAGE],
            'post de canal' => [\Telegram::CHANNEL_POST],
            'tipo desconhecido' => [null],
        ];
    }

    #[DataProvider('tiposRecusadosForaDoPrivadoProvider')]
    public function testTipoDeUpdateSemComandoParaAtenderParaNoPortao(?string $tipo): void
    {
        $this->assertFalse(\roteamento_updateAtendivelForaDoPrivado($tipo));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textosQueContemONomeSemMencionarOBotProvider(): array
    {
        return [
            'nome dentro de outra palavra' => ['esse é o idealni para o caso'],
            'nome colado em pontuação' => ['mandei pro dealnibot ontem'],
            'nome no meio de uma url' => ['veja https://exemplo.com/dealnimarket'],
        ];
    }

    /**
     * roteamento_contemNome() é busca por SUBSTRING, sem fronteira de palavra:
     * qualquer texto de grupo que contenha "dealni" em qualquer posição dispara
     * uma chamada paga à OpenAI. Comportamento atual, travado aqui para que
     * uma mudança de critério seja deliberada.
     */
    #[DataProvider('textosQueContemONomeSemMencionarOBotProvider')]
    public function testMencaoPeloNomeEhSubstringSemFronteiraDePalavra(string $texto): void
    {
        $this->assertTrue(\roteamento_contemNome($texto));
    }

    public function testTextoParaVerificarNormalizaAcentuacaoEmMaiusculasSemQuebrarUtf8(): void
    {
        // mb_strtolower com UTF-8: o nome escrito com acentos/maiúsculas não
        // pode virar bytes quebrados (o strpos seguinte compara byte a byte).
        $this->assertSame(
            'olá, dealni! çãé',
            \roteamento_textoParaVerificar([], 'OLÁ, DEALNI! ÇÃÉ', false)
        );
        $this->assertTrue(\roteamento_contemNome(\roteamento_textoParaVerificar([], 'DEALNI', false)));
        // Texto vazio nunca dispara o gatilho.
        $this->assertFalse(\roteamento_contemNome(\roteamento_textoParaVerificar([], '', false)));
    }

    public function testTextoParaVerificarRecusaUtf8InvalidoSemRotealo(): void
    {
        // Não se deve aceitar a saída substituída de mb_strtolower() como
        // texto de roteamento: a documentação recomenda mb_check_encoding()
        // para impedir ataques de codificação inválida.
        $invalido = "\xFFDEALNI";

        $this->assertFalse(mb_check_encoding($invalido, 'UTF-8'));
        $this->assertSame('', \roteamento_textoParaVerificar([], $invalido, false));
        $this->assertFalse(\roteamento_contemNome(\roteamento_textoParaVerificar([], $invalido, false)));
        $this->assertSame(
            '',
            \roteamento_textoParaVerificar(['caption' => $invalido], 'texto ignorado', true)
        );
    }

    // ---------------------------------------------------------------------
    // Rota -> arquivo do comando
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function arquivoDaRotaProvider(): array
    {
        return [
            'imagem' => ['imagem', 'comandos/ver_imagem.php'],
            'audio' => ['audio', 'comandos/ver_audio.php'],
            'chatgpt' => ['chatgpt', 'comandos/chatgpt.php'],
        ];
    }

    /**
     * Verifica o mapeamento rota -> arquivo e, de quebra, que o comando
     * resolvido a partir da raiz do bot existe (uma renomeação de comando não
     * passaria despercebida).
     */
    #[DataProvider('arquivoDaRotaProvider')]
    public function testArquivoDaRota(string $rota, string $arquivo): void
    {
        $resolvido = \roteamento_arquivoDaRota($rota);
        $this->assertSame($arquivo, $resolvido);
        $this->assertFileExists(__DIR__ . '/../../' . $resolvido);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rotasDesconhecidasProvider(): array
    {
        return [
            'rota inventada' => ['rota_inexistente'],
            // O chat privado é tratado inline pelo roteador.php, que só
            // registra o contato e orienta a usar o bot num grupo: a rota das
            // boas-vindas do /start nunca era alcançada e saiu junto com
            // comandos/start.php.
            'boas-vindas removidas' => ['start'],
        ];
    }

    #[DataProvider('rotasDesconhecidasProvider')]
    public function testArquivoDaRotaDesconhecidaLancaExcecao(string $rota): void
    {
        $this->expectException(InvalidArgumentException::class);
        \roteamento_arquivoDaRota($rota);
    }
}
